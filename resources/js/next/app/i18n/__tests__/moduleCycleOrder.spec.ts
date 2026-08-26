// THE i18n ↔ api IMPORT CYCLE, EVALUATED FROM THE OTHER SIDE.
//
// `lib/api.ts` imports `activeLocale` from this module so every request can state the language the
// client is rendering; this module imports `api` to PUT a deliberate language choice. That is a
// circular ES module import. It is inert only because neither module touches the other's bindings
// while it is being evaluated, and because `activeLocale` is a hoisted function declaration — an
// invariant that lives in a comment and would otherwise be enforced by nothing.
//
// `lib/__tests__/apiLocaleHeader.spec.ts` happens to load `lib/api` first, which is also the order the
// production bundle uses. THIS spec deliberately loads `app/i18n` first, with both modules real, so the
// other evaluation order is exercised too: a future module-scope call across the cycle (a `const x =
// api.get(...)` at the top level, say) fails here as a TDZ error rather than in one bundler's output.
import { describe, expect, it } from 'vitest';
// Import order is load-bearing in this file — i18n BEFORE api. Do not reorder.
import { setLocale, activeLocale } from '../index';
import type { AxiosRequestConfig, AxiosResponse } from 'axios';
import { api, CLIENT_LOCALE_HEADER } from '../../lib/api';

describe('i18n ↔ api module cycle', () => {
  it('evaluates cleanly when i18n is loaded first', () => {
    expect(typeof activeLocale()).toBe('string');
    expect(api).toBeDefined();
  });

  it('still declares the rendered locale on a request in this order', async () => {
    setLocale('pl');

    let seen: Record<string, unknown> = {};
    const config: AxiosRequestConfig = {
      adapter: (used): Promise<AxiosResponse> => {
        seen = { ...(used.headers as unknown as Record<string, unknown>) };

        return Promise.resolve({
          data: {},
          status: 200,
          statusText: 'OK',
          headers: {},
          config: used as never,
        });
      },
    };

    await api.get('/probe', config);

    expect(seen[CLIENT_LOCALE_HEADER]).toBe('pl');
  });
});
