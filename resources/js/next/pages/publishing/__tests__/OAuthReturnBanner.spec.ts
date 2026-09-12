// @vitest-environment happy-dom
// OAuthReturnBanner.spec — the worst possible moment to show somebody a machine identifier.
//
// A person has just come back from Google or Meta with nothing, and this banner is the only
// new thing on the page. Three of its properties fail silently, so each is pinned here:
//
//   • EVERY REASON RENDERS A SENTENCE. `oauthReasonKey` is a lookup into a catalog, and a key
//     that was renamed on one side renders the RAW KEY on screen — `publishing.oauth.
//     failures.token_response_unusable` where a sentence should be. `oauthReason*.spec` pins
//     the mapping; nothing pinned that the catalog actually answers for all fourteen, which
//     is a different question and the one that reaches a reader.
//   • THE CALLBACK VALIDATES NOTHING. Any raw platform error (`server_error`, …) passes
//     through verbatim, truncated to 64 chars, so the fallback is load-bearing rather than
//     defensive decoration — and the raw code goes on its own quiet line, never as the
//     sentence itself.
//   • TONE IS NOT SEVERITY THEATRE. `access_denied` and every stale-link refusal are amber:
//     the user DECLINED, or clicked an old link, and nothing broke. Red there is an
//     accusation. Danger is for the platform refusing after consent was given.
//   • TWO REASONS HAVE NO "CONNECT AGAIN", each for its own cause: `workspace_unavailable`'s
//     remedy is access to the workspace, and `unknown_platform` has nothing to repeat.
//     Offering the button anyway sends somebody round a loop that cannot end.
//   • FOCUS MOVES HERE. Otherwise the caret is wherever a full-page navigation left it and
//     the one sentence that matters is never announced.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import OAuthReturnBanner from '../OAuthReturnBanner.vue';
import { OAUTH_REASONS } from '../publishingMeta';
import { setLocale } from '../../../app/i18n';
import type { PublishingPlatform } from '../types';

/** The amber half of the map: a refusal nobody is to blame for. */
const WARNING_REASONS = [
  'access_denied',
  'missing_code',
  'oauth_state_malformed',
  'oauth_state_bad_signature',
  'oauth_state_expired',
  'oauth_state_already_used',
  'oauth_state_platform_mismatch',
  'oauth_browser_mismatch',
];

function mountBanner(reason: string | null, platform: PublishingPlatform | null = 'youtube') {
  return mount(OAuthReturnBanner, {
    props: { reason, platform },
    attachTo: document.body,
  });
}

type Wrapper = ReturnType<typeof mountBanner>;

/** The Alert's own surface classes — the tone, as a reader sees it. */
function surface(wrapper: Wrapper): string {
  return wrapper.find('.next-alert').attributes('class') ?? '';
}

function retryButton(wrapper: Wrapper) {
  return wrapper.findAll('button').find((b) => b.text().includes('Connect again'));
}

beforeEach(() => {
  setLocale('en');
});

afterEach(() => {
  document.body.innerHTML = '';
});

describe('every reason this build can be handed renders a SENTENCE', () => {
  it('answers for all fourteen named reasons, and never with a raw key', () => {
    for (const reason of OAUTH_REASONS) {
      const wrapper = mountBanner(reason);
      const text = wrapper.text();

      // A sentence, not a token: several words and at least one space.
      expect(text, reason).not.toContain('publishing.oauth.failures');
      const body = text.replace('The account could not be connected.', '').trim();
      expect(body.length, reason).toBeGreaterThan(20);
      expect(body, reason).toContain(' ');
      wrapper.unmount();
    }
  });

  it('falls back for a code the platform passed through verbatim', () => {
    const wrapper = mountBanner('server_error');
    expect(wrapper.text()).toContain('The platform refused the connection');
    // The raw code is shown, but QUIETLY — on its own line, never as the sentence.
    expect(wrapper.text()).toContain('server_error');
    expect(wrapper.text()).not.toContain('publishing.oauth.failures');
  });

  it('still says something when there is no reason at all', () => {
    // The callback can land here with the key absent; a blank alert would be worse than none.
    const wrapper = mountBanner(null);
    expect(wrapper.text()).toContain('The platform refused the connection');
    // And with no code to show, nothing pretends to be one.
    expect(wrapper.find('.font-next-mono').exists()).toBe(false);
  });
});

describe('the tone', () => {
  it('is AMBER where nobody did anything wrong', () => {
    for (const reason of WARNING_REASONS) {
      const wrapper = mountBanner(reason);
      expect(surface(wrapper), reason).toContain('bg-next-warning-subtle');
      expect(surface(wrapper), reason).not.toContain('bg-next-danger-subtle');
      wrapper.unmount();
    }
  });

  it('is RED where the platform refused AFTER consent was given', () => {
    const refusals = OAUTH_REASONS.filter((r) => !WARNING_REASONS.includes(r));
    expect(refusals.length).toBeGreaterThan(0);
    for (const reason of refusals) {
      const wrapper = mountBanner(reason);
      expect(surface(wrapper), reason).toContain('bg-next-danger-subtle');
      wrapper.unmount();
    }
  });
});

describe('"Connect again"', () => {
  it('is ABSENT for the two reasons repeating cannot help', () => {
    for (const reason of ['workspace_unavailable', 'unknown_platform'] as const) {
      const wrapper = mountBanner(reason);
      expect(retryButton(wrapper), reason).toBeUndefined();
      wrapper.unmount();
    }
  });

  it('is offered for everything else, and carries the platform in the event', async () => {
    const wrapper = mountBanner('oauth_state_expired');
    const button = retryButton(wrapper)!;
    expect(button).toBeDefined();

    await button.trigger('click');
    expect(wrapper.emitted('retry')?.[0]).toEqual(['youtube']);
  });

  it('is absent without a platform — there is no destination to start from', () => {
    expect(retryButton(mountBanner('oauth_state_expired', null))).toBeUndefined();
  });
});

describe('arriving on this banner', () => {
  it('takes the focus, so the sentence is where the caret is', () => {
    const wrapper = mountBanner('token_exchange_failed');
    expect(wrapper.element.getAttribute('tabindex')).toBe('-1');
    expect(document.activeElement).toBe(wrapper.element);
  });

  it('is dismissible, and says so rather than disappearing on a timer', async () => {
    const wrapper = mountBanner('access_denied');
    const dismiss = wrapper.findAll('button').find((b) => b.attributes('aria-label') === 'Dismiss');
    expect(dismiss).toBeDefined();

    await dismiss!.trigger('click');
    expect(wrapper.emitted('dismiss')).toHaveLength(1);
  });
});
