// @vitest-environment happy-dom
// A DEAD LINK IS A STATE, NOT AN INLINE ERROR.
//
// There is nothing on this form the user can change to fix an invalid, expired or spent link,
// so leaving the two password fields on screen under a red message invites them to keep
// pressing a button that cannot work. The screen replaces itself with the one act that helps:
// ask for a new link. This spec pins that the swap really happens, that it covers all three
// causes with the single sentence the backend's silence requires, and that the form is gone
// rather than merely annotated.
//
// The route/router, the api client and the auth store are mocked; i18n is the real singleton.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import ResetPasswordPage from '../ResetPasswordPage.vue';
import { setLocale } from '../../../app/i18n';
import { en } from '../../../app/i18n/en';

const routeMock = reactive({ query: {} as Record<string, string | string[]> });
const replace = vi.fn();
const post = vi.fn();
const persistToken = vi.fn();
const applyContext = vi.fn();

vi.mock('vue-router', () => ({
  useRoute: () => routeMock,
  useRouter: () => ({ replace, push: vi.fn() }),
}));

vi.mock('../../../app/lib/api', () => ({
  api: {
    post: (...args: unknown[]) => post(...args),
  },
}));

vi.mock('../../../app/stores/auth', () => ({
  useAuthStore: () => ({ persistToken, applyContext }),
}));

const LINK = { token: 'a-plaintext-token', email: 'user@example.com' };

/** Mount with a live link in the query and fill in a valid new password. */
async function mountWithLink() {
  routeMock.query = { ...LINK };
  const wrapper = mount(ResetPasswordPage);

  await wrapper.find('input[name="password"]').setValue('a-brand-new-password');
  await wrapper.find('input[name="password_confirmation"]').setValue('a-brand-new-password');

  return wrapper;
}

beforeEach(() => {
  setLocale('en');
  routeMock.query = {};
  replace.mockReset();
  post.mockReset();
  persistToken.mockReset();
  applyContext.mockReset();
});

describe('ResetPasswordPage — the link is refused', () => {
  it('replaces the form with the dead-link state and its single primary action', async () => {
    const wrapper = await mountWithLink();

    post.mockRejectedValueOnce({
      response: { status: 422, data: { errors: { token: ['This password reset token is invalid.'] } } },
    });

    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(wrapper.html()).toContain(en.auth.reset.deadLink.title);
    expect(wrapper.html()).toContain(en.auth.reset.errors.invalidLink);

    // The form is GONE, not annotated: nothing here can be retyped into a working link.
    expect(wrapper.find('form').exists()).toBe(false);
    expect(wrapper.find('input[name="password"]').exists()).toBe(false);

    // One way forward, plus the way out. The first is the primary action.
    const links = wrapper.findAll('a');
    expect(links.map((link) => link.attributes('href'))).toEqual([
      '/next/forgot-password',
      '/next/login',
    ]);
    expect(links[0].text()).toBe(en.auth.reset.requestNewLink);
  });

  it('says the same thing whether the server blamed the token or the address', async () => {
    // The backend deliberately cannot tell "expired" from "never existed" out loud, so the
    // screen must not invent the difference either.
    const wrapper = await mountWithLink();

    post.mockRejectedValueOnce({
      response: { status: 422, data: { errors: { email: ['We cannot find that user.'] } } },
    });

    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(wrapper.html()).toContain(en.auth.reset.errors.invalidLink);
    expect(wrapper.find('form').exists()).toBe(false);
    // Never the server's own sentence — it would leak exactly what the collapse hides.
    expect(wrapper.html()).not.toContain('We cannot find that user.');
  });

  it('keeps a password complaint in the form, where it can be fixed', async () => {
    const wrapper = await mountWithLink();

    post.mockRejectedValueOnce({
      response: { status: 422, data: { errors: { password: ['too weak'] } } },
    });

    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(wrapper.find('form').exists()).toBe(true);
    expect(wrapper.html()).toContain(en.auth.reset.errors.rejected);
    expect(wrapper.html()).not.toContain(en.auth.reset.deadLink.title);
  });
});

describe('ResetPasswordPage — the other two states', () => {
  it('asks for a new link when the URL never carried one, without calling the endpoint', () => {
    routeMock.query = {};
    const wrapper = mount(ResetPasswordPage);

    expect(wrapper.html()).toContain(en.auth.reset.missingLink.title);
    expect(wrapper.find('form').exists()).toBe(false);
    expect(post).not.toHaveBeenCalled();
  });

  it('shows which account the link belongs to, read-only, from the link itself', async () => {
    const wrapper = await mountWithLink();

    const email = wrapper.find('input[name="email"]');
    expect((email.element as HTMLInputElement).value).toBe(LINK.email);
    expect(email.attributes('readonly')).toBeDefined();
  });

  it('enters the app on success with the session the server just issued', async () => {
    const wrapper = await mountWithLink();

    post.mockResolvedValueOnce({
      message: 'Your password has been reset.',
      token: 'a-fresh-sanctum-token',
      user: { id: 'u1' },
      permissions: [],
      workspaces: [],
      current_workspace: null,
    });

    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/auth/reset-password', {
      token: LINK.token,
      email: LINK.email,
      password: 'a-brand-new-password',
      password_confirmation: 'a-brand-new-password',
    });
    expect(persistToken).toHaveBeenCalledWith('a-fresh-sanctum-token');
    expect(applyContext).toHaveBeenCalled();
    expect(replace).toHaveBeenCalledWith('/dashboard');
  });

  it('does not post a password the server would only reject', async () => {
    routeMock.query = { ...LINK };
    const wrapper = mount(ResetPasswordPage);

    await wrapper.find('input[name="password"]').setValue('short');
    await wrapper.find('input[name="password_confirmation"]').setValue('short');
    await wrapper.find('form').trigger('submit');
    await flushPromises();

    expect(post).not.toHaveBeenCalled();
    expect(wrapper.html()).toContain(en.auth.reset.errors.tooShort);
  });
});
