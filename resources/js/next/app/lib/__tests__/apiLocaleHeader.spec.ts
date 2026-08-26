// THE CLIENT STATES THE LANGUAGE IT IS RENDERING, ON EVERY REQUEST.
//
// The frontend resolves its own locale (localStorage → browser → default) and used to tell the server
// only when somebody deliberately flipped the language toggle. A user with a Polish browser therefore
// got a Polish interface and an English server — validation messages, calendar source labels, badges —
// in the same window, from first login, with no way to correct it from the UI.
//
// `App\Http\Middleware\SetUserLocale` now reads `X-Client-Locale` for anyone who has not chosen a
// locale, so the header being present and CURRENT is the whole fix on this side. These tests capture
// the real axios request config through a per-request adapter: no mock of our own client, so the
// interceptor wiring is what is under test.
import { describe, expect, it } from 'vitest';
import type { AxiosRequestConfig, AxiosResponse } from 'axios';
import { api, CLIENT_LOCALE_HEADER } from '../api';
import { setLocale } from '../../i18n';

/** Issue a real request through the client and return the headers the adapter was handed. */
async function headersOf(send: (config: AxiosRequestConfig) => Promise<unknown>): Promise<Record<string, unknown>> {
  let seen: Record<string, unknown> = {};

  await send({
    adapter: (config): Promise<AxiosResponse> => {
      seen = { ...(config.headers as unknown as Record<string, unknown>) };

      return Promise.resolve({
        data: {},
        status: 200,
        statusText: 'OK',
        headers: {},
        config: config as never,
      });
    },
  });

  return seen;
}

describe('api client — the locale it is rendering', () => {
  it('declares the active locale on a GET', async () => {
    setLocale('pl');

    const headers = await headersOf((config) => api.get('/probe', config));

    expect(headers[CLIENT_LOCALE_HEADER]).toBe('pl');
  });

  it('follows a live language switch rather than the locale at module load', async () => {
    setLocale('pl');
    expect((await headersOf((config) => api.get('/probe', config)))[CLIENT_LOCALE_HEADER]).toBe('pl');

    setLocale('en');
    expect((await headersOf((config) => api.get('/probe', config)))[CLIENT_LOCALE_HEADER]).toBe('en');

    setLocale('pl');
    expect((await headersOf((config) => api.get('/probe', config)))[CLIENT_LOCALE_HEADER]).toBe('pl');
  });

  it('declares it on writes too — validation messages are the surface users meet most', async () => {
    setLocale('pl');

    const posted = await headersOf((config) => api.post('/probe', { any: 'body' }, config));
    const put = await headersOf((config) => api.put('/probe', { any: 'body' }, config));
    const patched = await headersOf((config) => api.patch('/probe', { any: 'body' }, config));
    const deleted = await headersOf((config) => api.delete('/probe', config));

    for (const headers of [posted, put, patched, deleted]) {
      expect(headers[CLIENT_LOCALE_HEADER]).toBe('pl');
    }
  });

  it('sends one of the supported locales and nothing else', async () => {
    // The server matches this value EXACTLY against `config('app.supported_locales')` — no trimming,
    // no case folding, no `pl-PL` → `pl`. A client that invented a variant would be silently ignored.
    for (const locale of ['pl', 'en'] as const) {
      setLocale(locale);
      expect(await headersOf((config) => api.get('/probe', config))).toMatchObject({
        [CLIENT_LOCALE_HEADER]: locale,
      });
    }
  });
});
