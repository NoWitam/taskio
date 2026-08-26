// HTTP client for the isolated "next" frontend.
//
// A fresh, self-contained axios singleton — it shares NO code with the legacy
// `resources/js/lib/api.ts`. Auth is Bearer-token based: the login token is
// persisted in `localStorage['taskio_token']` (the SAME key the verified backend
// contract uses) and sent as `Authorization: Bearer <token>` on every request.
// The active workspace id (`localStorage['taskio_workspace']`) rides along as the
// `X-Workspace-Id` header. CSRF is still read from the `<meta name="csrf-token">`
// tag and `withCredentials` stays on (Sanctum). An unauthenticated 401 redirects
// to the `/next/login` route (Vue Router owns /next).
import axios, {
  type AxiosInstance,
  type AxiosRequestConfig,
  type AxiosResponse,
  type AxiosError,
} from 'axios';
import { activeLocale } from '../i18n';
import { authToken, TOKEN_KEY } from './token';

/** Path the 401 interceptor redirects to. */
export const LOGIN_PATH = '/next/login';

/**
 * localStorage keys shared with the verified backend auth contract.
 *
 * `TOKEN_KEY` is declared in `./token` (so "is anyone logged in?" can be asked without importing this
 * client) and re-exported here, because that is where every existing caller imports it from.
 */
export { TOKEN_KEY };
export const WORKSPACE_KEY = 'taskio_workspace';

/**
 * Header stating the language THIS CLIENT IS RENDERING, read by `App\Http\Middleware\SetUserLocale`.
 *
 * The frontend resolves its own locale (localStorage → browser → default) and used to tell the server
 * only on a deliberate switch of the language toggle — so a Polish interface received English
 * validation messages, labels and badges in the same window, from first login. This states the fact on
 * every request instead. It is NOT a preference: a stored `users.locale` still outranks it server-side,
 * and nothing here writes to that column.
 */
export const CLIENT_LOCALE_HEADER = 'X-Client-Locale';

function csrfToken(): string | null {
  if (typeof document === 'undefined') return null;
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? null;
}

function workspaceId(): string | null {
  try {
    return localStorage.getItem(WORKSPACE_KEY);
  } catch {
    return null;
  }
}

class NextApiClient {
  private readonly client: AxiosInstance;

  constructor() {
    this.client = axios.create({
      baseURL: '/api',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      withCredentials: true,
    });

    this.setupInterceptors();
  }

  private setupInterceptors(): void {
    this.client.interceptors.request.use((config) => {
      const token = csrfToken();
      if (token) {
        config.headers.set('X-CSRF-TOKEN', token);
      }

      // Bearer auth: attach the persisted login token when present.
      const bearer = authToken();
      if (bearer) {
        config.headers.set('Authorization', `Bearer ${bearer}`);
      }

      // Scope the request to the active workspace when one is selected.
      const workspace = workspaceId();
      if (workspace) {
        config.headers.set('X-Workspace-Id', workspace);
      }

      // State the language this client is rendering, so server prose can match the screen it
      // lands on. Read at REQUEST time (not at module load) so it follows a live language switch.
      config.headers.set(CLIENT_LOCALE_HEADER, activeLocale());

      // Let the browser set the multipart boundary for FormData payloads.
      if (typeof FormData !== 'undefined' && config.data instanceof FormData) {
        config.headers.delete('Content-Type');
      }

      return config;
    });

    this.client.interceptors.response.use(
      (response) => response,
      (error: AxiosError) => {
        if (error.response?.status === 401 && typeof window !== 'undefined') {
          // Avoid a redirect loop if we are already on the login route.
          if (!window.location.pathname.startsWith(LOGIN_PATH)) {
            window.location.assign(LOGIN_PATH);
          }
        }
        return Promise.reject(error);
      },
    );
  }

  async get<T = unknown>(url: string, config?: AxiosRequestConfig): Promise<T> {
    const response: AxiosResponse<T> = await this.client.get(url, config);
    return response.data;
  }

  async post<T = unknown>(url: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> {
    const response: AxiosResponse<T> = await this.client.post(url, data, config);
    return response.data;
  }

  async put<T = unknown>(url: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> {
    const response: AxiosResponse<T> = await this.client.put(url, data, config);
    return response.data;
  }

  async patch<T = unknown>(url: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> {
    const response: AxiosResponse<T> = await this.client.patch(url, data, config);
    return response.data;
  }

  async delete<T = unknown>(url: string, config?: AxiosRequestConfig): Promise<T> {
    const response: AxiosResponse<T> = await this.client.delete(url, config);
    return response.data;
  }
}

export const api = new NextApiClient();
