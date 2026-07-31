// @vitest-environment happy-dom
// BotVisualCandidates.spec — the likeness STRIP.
//
// What is pinned here is what the UX rests on:
//   • an approved tile carries THREE non-colour signals (ring class, badge text, aria-label) and its
//     DELETE is disabled WITH the reason (the server refuses it — hiding the control would just puzzle);
//   • clicking an unapproved tile APPROVES; clicking the approved one ENLARGES (it is already chosen);
//   • at capacity the tile a further generation would evict says so (title + an sr-only line) — this is
//     the warning about a PERMANENT deletion, so it must not be colour-only either;
//   • an in-flight generation reserves its place with a skeleton tile of the same geometry;
//   • emptying the strip hands focus back to the panel rather than dropping it on <body>.
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { setLocale } from '../../../app/i18n';
import { en } from '../../../app/i18n/en';
import BotVisualCandidates from '../BotVisualCandidates.vue';

// The tile images fetch bytes; the strip's own behaviour is what is under test.
const stubs = {
  BotVisualImage: { name: 'BotVisualImage', props: ['fileId', 'alt', 'fit', 'lazy'], template: '<img :alt="alt" />' },
  Modal: { name: 'Modal', props: ['open'], template: '<div class="modal"><slot name="title" /><slot /></div>' },
};

function mountStrip(props: Record<string, unknown> = {}) {
  return mount(BotVisualCandidates, {
    props: {
      candidates: ['f1', 'f2'],
      canonicalFileId: null,
      botName: 'Ada',
      ...props,
    },
    global: { stubs },
  });
}

/** The tile buttons (the full-tile approve/enlarge control), in order. */
function tiles(wrapper: ReturnType<typeof mountStrip>) {
  return wrapper.findAll('li > button');
}

describe('BotVisualCandidates', () => {
  beforeEach(() => {
    setLocale('en');
    vi.clearAllMocks();
  });

  it('renders an in-panel empty prompt (not an EmptyState) when there is nothing yet', () => {
    const wrapper = mountStrip({ candidates: [] });
    expect(wrapper.text()).toContain(en.bots.editor.visual.candidates.empty);
    expect(wrapper.text()).toContain(en.bots.editor.visual.candidates.emptyHint);
    expect(wrapper.find('ul').exists()).toBe(false);
    wrapper.unmount();
  });

  it('approves on a tile click, and labels each tile with its position', async () => {
    const wrapper = mountStrip();
    expect(tiles(wrapper)[0].attributes('aria-label')).toBe('Likeness 1 of 2 — set as the likeness');

    await tiles(wrapper)[1].trigger('click');
    expect(wrapper.emitted('approve')?.[0]).toEqual(['f2']);
    wrapper.unmount();
  });

  it('marks the approved tile with a ring, a badge AND its own aria-label — never colour alone', () => {
    const wrapper = mountStrip({ canonicalFileId: 'f2' });
    const approved = wrapper.findAll('li')[1];

    expect(approved.classes().join(' ')).toContain('ring-next-success');
    expect(approved.text()).toContain(en.bots.editor.visual.candidates.canonicalBadge);
    expect(tiles(wrapper)[1].attributes('aria-label')).toBe('Likeness 2 of 2 — approved');
    wrapper.unmount();
  });

  it('enlarges (does NOT re-approve) when the approved tile is clicked', async () => {
    const wrapper = mountStrip({ canonicalFileId: 'f1' });
    await tiles(wrapper)[0].trigger('click');

    expect(wrapper.emitted('approve')).toBeUndefined();
    expect(wrapper.find('.modal').exists()).toBe(true);
    wrapper.unmount();
  });

  it('refuses to delete the approved likeness, and says why on the disabled control', () => {
    const wrapper = mountStrip({ canonicalFileId: 'f1' });
    const remove = wrapper.findAll('li')[0].findAll('button')[2];

    expect(remove.attributes('disabled')).toBeDefined();
    expect(remove.attributes('title')).toBe(en.bots.editor.visual.candidates.removeCanonical);
    wrapper.unmount();
  });

  it('emits remove for an unapproved tile', async () => {
    const wrapper = mountStrip();
    await wrapper.findAll('li')[0].findAll('button')[2].trigger('click');
    expect(wrapper.emitted('remove')?.[0]).toEqual(['f1']);
    wrapper.unmount();
  });

  it('warns on the tile a further generation would evict — only once the strip is FULL', () => {
    const full = ['a', 'b', 'c', 'd', 'e', 'f'];
    const notFull = mountStrip({ candidates: ['a', 'b'] });
    expect(notFull.text()).not.toContain(en.bots.editor.visual.candidates.oldestHint);
    notFull.unmount();

    const wrapper = mountStrip({ candidates: full });
    // The OLDEST (first) tile is the one at risk — both as a title and as text for assistive tech.
    expect(tiles(wrapper)[0].attributes('title')).toBe(en.bots.editor.visual.candidates.oldestHint);
    expect(wrapper.findAll('li')[0].find('.sr-only').text()).toBe(
      en.bots.editor.visual.candidates.oldestHint,
    );
    // Every other tile falls back to what a click does.
    expect(tiles(wrapper)[1].attributes('title')).toBe(en.bots.editor.visual.candidates.approve);
    wrapper.unmount();
  });

  it('skips the APPROVED tile when naming the one that would be evicted', () => {
    const wrapper = mountStrip({ candidates: ['a', 'b', 'c', 'd', 'e', 'f'], canonicalFileId: 'a' });
    expect(tiles(wrapper)[0].attributes('title')).toBe(en.bots.editor.visual.candidates.preview);
    expect(tiles(wrapper)[1].attributes('title')).toBe(en.bots.editor.visual.candidates.oldestHint);
    wrapper.unmount();
  });

  it('reserves the in-flight generation its place with a skeleton tile', () => {
    const wrapper = mountStrip({ pending: true });
    expect(wrapper.find('[data-test="candidate-pending"]').exists()).toBe(true);
    // …and shows the strip (not the empty prompt) even with no candidates yet.
    const fresh = mountStrip({ candidates: [], pending: true });
    expect(fresh.text()).not.toContain(en.bots.editor.visual.candidates.empty);
    expect(fresh.find('[data-test="candidate-pending"]').exists()).toBe(true);
    wrapper.unmount();
    fresh.unmount();
  });

  it('hands focus back to the panel when a delete empties the strip', async () => {
    const wrapper = mountStrip({ candidates: ['only'] });
    await wrapper.findAll('li')[0].findAll('button')[2].trigger('click');
    await wrapper.setProps({ candidates: [] });

    expect(wrapper.emitted('focus-generate')).toHaveLength(1);
    wrapper.unmount();
  });

  it('is read-only when curation is unavailable (an unsaved bot)', () => {
    const wrapper = mountStrip({ disabled: true });
    expect(tiles(wrapper)[0].attributes('disabled')).toBeDefined();
    expect(wrapper.findAll('li')[0].findAll('button')[2].attributes('disabled')).toBeDefined();
    wrapper.unmount();
  });
});
