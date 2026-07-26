// echo — a lazily-created Laravel Echo (Reverb) singleton for realtime pushes, insulated behind a
// tiny API so callers never touch laravel-echo's types. Returns null when Reverb is not configured
// (`VITE_REVERB_APP_KEY` unset) — callers then fall back to HTTP polling, so the app works with or
// without a running Reverb server. Auth for private channels uses the SAME Bearer token as the API
// (`/broadcasting/auth` runs on the `auth:sanctum` guard), so the persisted login token is forwarded
// on the auth request — without it Sanctum can't identify the user and the auth endpoint 500s.
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { TOKEN_KEY } from './api';

/** The minimal channel surface the app needs (chainable listen / error / stopListening). */
export interface RealtimeChannel {
  listen(event: string, callback: (payload: unknown) => void): RealtimeChannel;
  error(callback: (error: unknown) => void): RealtimeChannel;
  stopListening(event: string): RealtimeChannel;
}

let instance: Echo<'reverb'> | null | undefined;

function env(key: string): string | undefined {
  return (import.meta.env as Record<string, string | undefined>)[key];
}

/** The persisted Bearer login token (same one the API client sends), or null. */
function readToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}

/** The Echo/Reverb singleton, or null when Reverb isn't configured. */
function echo(): Echo<'reverb'> | null {
  if (instance !== undefined) return instance;

  const key = env('VITE_REVERB_APP_KEY');
  if (!key) {
    instance = null; // not configured → callers poll instead
    return instance;
  }

  (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;
  const scheme = env('VITE_REVERB_SCHEME') ?? 'https';
  const port = Number(env('VITE_REVERB_PORT') ?? (scheme === 'https' ? 443 : 80));
  const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

  // /broadcasting/auth runs on `auth:sanctum` (same guard as the API), so it needs the Bearer token
  // the API uses — the CSRF token alone can't identify the user. `Accept: application/json` makes an
  // auth FAILURE return a clean 401 instead of a redirect to the (nonexistent) `login` route, which
  // would surface as a 500. Missing/blank values are simply omitted.
  const authHeaders: Record<string, string> = { Accept: 'application/json' };
  if (csrf) authHeaders['X-CSRF-TOKEN'] = csrf;
  const bearer = readToken();
  if (bearer) authHeaders.Authorization = `Bearer ${bearer}`;

  instance = new Echo({
    broadcaster: 'reverb',
    key,
    wsHost: env('VITE_REVERB_HOST') ?? window.location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    auth: { headers: authHeaders },
  });
  return instance;
}

/** Subscribe to a PRIVATE channel; null when Reverb isn't configured. */
export function subscribePrivate(name: string): RealtimeChannel | null {
  const e = echo();
  return e ? (e.private(name) as unknown as RealtimeChannel) : null;
}

/**
 * Register a callback fired if the realtime CONNECTION drops or can't be established (server down /
 * network gone), so a caller can fall back to polling promptly instead of waiting on a dead socket.
 * Returns an unbind function; a no-op when Reverb isn't configured.
 */
export function whenConnectionFails(callback: () => void): () => void {
  const e = echo();
  const connection = (e as unknown as { connector?: { pusher?: { connection?: { bind?: (ev: string, cb: () => void) => void; unbind?: (ev: string, cb: () => void) => void } } } } | null)
    ?.connector?.pusher?.connection;
  if (!connection?.bind) return () => {};
  connection.bind('unavailable', callback);
  connection.bind('failed', callback);
  return () => {
    connection.unbind?.('unavailable', callback);
    connection.unbind?.('failed', callback);
  };
}
