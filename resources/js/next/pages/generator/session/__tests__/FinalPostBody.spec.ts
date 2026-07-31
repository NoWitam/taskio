// @vitest-environment happy-dom
// FinalPostBody.spec — the assembled "Gotowy post" artifact.
//
// Two things this file guards, both about STORYBOARD frames:
//   • a frame still being rendered by its own queue job gets a placeholder, not an empty gap — the
//     artifact is the surface a user reloads onto mid-run;
//   • a beat drawn from the session's frozen character says so as a TEXT SUFFIX on the heading, not as a
//     badge: this is a reading surface, and a row of chips would compete with the content it presents.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setLocale } from '../../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../../__tests__/helpers/dom';

vi.mock('../../../../app/lib/api', () => ({
  api: { get: vi.fn(async () => new Blob([], { type: 'image/png' })) },
}));

import FinalPostBody from '../FinalPostBody.vue';
import type { ContentTypePart } from '../../types';

const storyboardPart: ContentTypePart = {
  key: 'storyboard',
  kind: 'storyboard',
  label: 'Storyboard',
  required: true,
  config: {},
};

function results(shots: Array<Record<string, unknown>>) {
  return { storyboard: { kind: 'storyboard', status: 'ok', shots } };
}

function mountBody(props: Record<string, unknown>) {
  return mount(FinalPostBody, {
    props: { parts: [storyboardPart], status: 'ready', sessionId: 's1', ...props },
    attachTo: document.body,
  });
}

describe('FinalPostBody', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
    URL.createObjectURL = vi.fn(() => 'blob:mock');
    URL.revokeObjectURL = vi.fn();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('holds the place of a frame still being rendered instead of leaving a gap', async () => {
    const wrapper = mountBody({
      results: results([
        { index: 0, visual: 'wide shot', voiceover: 'a', seconds: 3, image_status: 'pending' },
        { index: 1, visual: 'close up', voiceover: 'b', seconds: 2, image_status: 'rendering' },
      ]),
    });
    await flushPromises();

    expect(wrapper.findAll('[data-test="final-frame-pending"]')).toHaveLength(2);
    expect(wrapper.text()).toContain('Frame in progress…');
    expect(wrapper.find('img').exists()).toBe(false);
    wrapper.unmount();
  });

  it('suffixes the heading of a beat drawn from the character — only when the session has one', async () => {
    const shots = [
      { index: 0, visual: 'the creator', voiceover: 'a', seconds: 3, image_status: 'ok', part_key: 'storyboard.0', features_character: true },
      { index: 1, visual: 'the product', voiceover: 'b', seconds: 2, image_status: 'ok', part_key: 'storyboard.1', features_character: false },
    ];

    const plain = mountBody({ results: results(shots) });
    await flushPromises();
    expect(plain.text()).not.toContain('with character');
    plain.unmount();

    const wrapper = mountBody({ results: results(shots), hasCharacterImage: true });
    await flushPromises();
    expect(wrapper.text()).toContain('Shot 1 · with character');
    expect(wrapper.text()).not.toContain('Shot 2 · with character');
    wrapper.unmount();
  });
});
