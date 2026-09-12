// oauthReturn — reading the callback's answer out of the URL. Pure, so it can be tested
// without a router.
//
// THE PARAMETER IS `connection`, NOT `connect`. `PlatformOAuthCallbackController` declares
// `RESULT_KEY = 'connection'` with the values `connected` / `failed`. A screen watching the
// wrong key would show nothing at all after a successful connect — silently, and only after
// somebody had gone all the way through a consent screen.
//
// THREE PROPERTIES OF THIS PAYLOAD THAT A READER MUST NOT ASSUME AWAY:
//   1. `platform` MAY BE ABSENT. The controller filters a null platform, which is exactly
//      what happens on `unknown_platform` — so every sentence and every action has to work
//      without it.
//   2. `reason` IS NOT VALIDATED against a closed list. Any raw error the platform reported
//      (`server_error`, …) passes through verbatim, truncated to 64 characters. Callers must
//      have a fallback; this function does not invent one.
//   3. The parameters must be STRIPPED from the URL the moment they are read, or a later
//      Back re-shows a banner about something that happened a quarter of an hour ago. That
//      is the caller's job (it owns the router); this function only reads.
import type { OAuthReturn, PublishingPlatform } from './types';

const PLATFORMS: readonly string[] = ['youtube', 'instagram', 'facebook', 'dry_run'];

/** A query value that may arrive as an array (`?a=1&a=2`) — only the first entry counts. */
function first(value: unknown): string | null {
  const raw = Array.isArray(value) ? value[0] : value;
  return typeof raw === 'string' && raw !== '' ? raw : null;
}

/**
 * The callback's outcome, or null when this navigation is not a return from one.
 *
 * A `connection` value that is neither `connected` nor `failed` is treated as no return at
 * all: something else put it there, and inventing a third outcome from it would be a banner
 * about an event that did not happen.
 */
export function readOAuthReturn(query: Record<string, unknown>): OAuthReturn | null {
  const result = first(query.connection);
  if (result !== 'connected' && result !== 'failed') return null;

  const platform = first(query.platform);

  return {
    result,
    platform: platform && PLATFORMS.includes(platform) ? (platform as PublishingPlatform) : null,
    // Kept verbatim, including a code this build has never heard of — see property 2.
    reason: result === 'failed' ? first(query.reason) : null,
  };
}
