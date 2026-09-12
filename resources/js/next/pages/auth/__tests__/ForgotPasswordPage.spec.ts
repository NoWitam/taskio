// @vitest-environment happy-dom
// THE CONFIRMATION PANEL IS THE SAME PANEL FOR EVERY ADDRESS.
//
// The backend answers this form with one sentence whether or not the account exists, because
// anything else turns it into a membership test against the user table. That property is
// worth exactly as much as the SCREEN honours it: a page that echoed the server's sentence,
// or named the address back, or showed a "resend" affordance only when something was really
// sent, would leak on the client what the API refused to leak on the wire.
//
// So the test drives the page twice with DIFFERENT addresses and DIFFERENT answers — the
// second one deliberately the leaky sentence a regressed backend might produce — and compares
// the rendered DOM byte for byte. It passes only while the panel is the page's own.
//
// i18n is the real singleton pinned to `en`; only the api client is mocked.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import ForgotPasswordPage from '../ForgotPasswordPage.vue';
import { setLocale } from '../../../app/i18n';
import { en } from '../../../app/i18n/en';

const post = vi.fn();

vi.mock('../../../app/lib/api', () => ({
  api: {
    post: (...args: unknown[]) => post(...args),
  },
}));

/** Type an address, submit, and hand back the whole rendered page. */
async function submitAddress(email: string): Promise<string> {
  const wrapper = mount(ForgotPasswordPage);

  await wrapper.find('input[name="email"]').setValue(email);
  await wrapper.find('form').trigger('submit');
  await flushPromises();

  return wrapper.html();
}

beforeEach(() => {
  setLocale('en');
  post.mockReset();
});

describe('ForgotPasswordPage', () => {
  it('renders one confirmation, identical for a known and an unknown address', async () => {
    // Run 1: a real account. Run 2: an address that belongs to nobody — and an answer that
    // says so, which is exactly what must not reach the screen.
    post.mockResolvedValueOnce({ message: 'We have emailed your password reset link.' });
    const known = await submitAddress('known@example.com');

    post.mockResolvedValueOnce({ message: "We can't find a user with that email address." });
    const unknown = await submitAddress('nobody@example.com');

    expect(unknown).toBe(known);

    // …and what it says is the page's own conditional sentence, not either server reply.
    expect(known).toContain(en.auth.forgot.sent.body);
    expect(known).not.toContain('We have emailed your password reset link.');
    expect(known).not.toContain("We can't find a user");

    // The address is never named back. (It is also the one string that differed between the
    // two runs, so an echo would have failed the comparison above — asserted directly too,
    // because that comparison could be weakened without anybody noticing this.)
    expect(known).not.toContain('known@example.com');
    expect(unknown).not.toContain('nobody@example.com');
  });

  it('offers exactly one way on from the confirmation: back to sign in', async () => {
    post.mockResolvedValueOnce({ message: 'anything' });
    const wrapper = mount(ForgotPasswordPage);

    await wrapper.find('input[name="email"]').setValue('known@example.com');
    await wrapper.find('form').trigger('submit');
    await flushPromises();

    // No form left to resubmit — a "send again" button would be a live oracle: it only makes
    // sense to press when something was really sent, and the 60-second broker cooldown means
    // the second press does nothing anyway.
    expect(wrapper.find('form').exists()).toBe(false);
    expect(wrapper.find('input[name="email"]').exists()).toBe(false);

    const links = wrapper.findAll('a');
    expect(links).toHaveLength(1);
    expect(links[0].attributes('href')).toBe('/next/login');
  });

  it('a refused request keeps the form and says so, instead of claiming a link was sent', async () => {
    // The confirmation must be reachable ONLY from a 2xx. Flipping to it on any settled
    // request would tell somebody a link is coming when the request never landed.
    post.mockRejectedValueOnce({ response: { status: 422, data: { errors: { email: ['bad'] } } } });
    const wrapper = mount(ForgotPasswordPage);

    await wrapper.find('input[name="email"]').setValue('not-an-address');
    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(wrapper.find('form').exists()).toBe(true);
    expect(wrapper.html()).not.toContain(en.auth.forgot.sent.title);
    expect(wrapper.html()).toContain(en.auth.forgot.errors.invalidEmail);
  });

  it('does not call the endpoint for an empty address', async () => {
    const wrapper = mount(ForgotPasswordPage);

    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(post).not.toHaveBeenCalled();
    expect(wrapper.html()).toContain(en.auth.forgot.errors.invalidEmail);
  });
});
