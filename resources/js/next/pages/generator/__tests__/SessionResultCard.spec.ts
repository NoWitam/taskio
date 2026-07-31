// @vitest-environment happy-dom
// SessionResultCard.spec — the per-part result turn. Asserts the per-KIND body (text → MarkdownViewer,
// failed text → error alert, image → the PRODUCED image via the serve endpoint, failed image → alert,
// scenes → narration + per-scene image / error) AND the 2c capability gating: Copy + Save-to-Disk (for a
// produced image) are LIVE while every generative / undo affordance renders INERT (disabled).
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

vi.mock('../../../app/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), danger: vi.fn() }),
}));

// The produced image is blob-fetched through the api singleton (SessionPartImage) — mock it to a PNG blob.
vi.mock('../../../app/lib/api', () => ({
  api: { get: vi.fn(async () => new Blob([], { type: 'image/png' })) },
}));

// The "bot appearance" repair action deep-links into the bots editor.
const routerPush = vi.hoisted(() => vi.fn());
vi.mock('vue-router', () => ({ useRouter: () => ({ push: routerPush }) }));

import { api } from '../../../app/lib/api';
import SessionResultCard from '../session/SessionResultCard.vue';
import type { ContentTypePart } from '../types';

const apiMock = api as unknown as { get: ReturnType<typeof vi.fn> };

function part(overrides: Partial<ContentTypePart> = {}): ContentTypePart {
  return { key: 'body', kind: 'text_body', label: 'Post body', required: true, config: {}, ...overrides };
}

function mountCard(props: Record<string, unknown>) {
  return mount(SessionResultCard, {
    props: { sessionId: 's1', sessionName: 'Spring promo', ...props },
    attachTo: document.body,
  });
}

describe('SessionResultCard', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
    vi.clearAllMocks();
    apiMock.get.mockResolvedValue(new Blob([], { type: 'image/png' }));
    // happy-dom lacks object-URL helpers; stub them so the blob→objectURL path resolves.
    URL.createObjectURL = vi.fn(() => 'blob:mock');
    URL.revokeObjectURL = vi.fn();
  });
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('text_body (ok): renders the produced markdown', () => {
    const wrapper = mountCard({
      part: part(),
      result: { kind: 'text_body', status: 'ok', text: '# Hello world' },
    });
    expect(wrapper.find('.next-md-prose').exists()).toBe(true);
    expect(wrapper.text()).toContain('Hello world');
    wrapper.unmount();
  });

  it('text_body (failed): renders the localized error, not the body', () => {
    const wrapper = mountCard({
      part: part(),
      result: { kind: 'text_body', status: 'failed', error: 'boom on this part' },
    });
    expect(wrapper.text()).toContain('boom on this part');
    expect(wrapper.find('.next-md-prose').exists()).toBe(false);
    wrapper.unmount();
  });

  it('image_plan (ok): fetches the produced image from the serve endpoint and renders it', async () => {
    const wrapper = mountCard({
      part: part({ key: 'image', kind: 'image_plan', label: 'Image' }),
      result: { kind: 'image_plan', status: 'ok', image: { mime: 'image/png', width: 100, height: 80 } },
    });
    await flushPromises();

    expect(apiMock.get).toHaveBeenCalledWith(
      '/generator/sessions/s1/parts/image/image',
      { responseType: 'blob' },
    );
    const img = wrapper.find('img');
    expect(img.exists()).toBe(true);
    expect(img.attributes('src')).toBe('blob:mock');
    wrapper.unmount();
  });

  it('image_plan (failed): renders the localized error alert, never an image', async () => {
    const wrapper = mountCard({
      part: part({ key: 'image', kind: 'image_plan', label: 'Image' }),
      result: { kind: 'image_plan', status: 'failed', error: 'image boom' },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('image boom');
    expect(wrapper.find('img').exists()).toBe(false);
    expect(apiMock.get).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  it('scene_plan (ok): renders each narration + the per-scene produced image / error', async () => {
    const wrapper = mountCard({
      part: part({ key: 'scenes', kind: 'scene_plan', label: 'Scenes' }),
      result: {
        kind: 'scene_plan',
        status: 'ok',
        scenes: [
          { narration: 'Scene one narration', image_status: 'ok', part_key: 'scene_plan.0', image: { mime: 'image/png', width: 10, height: 10 } },
          { narration: 'Scene two narration', image_status: 'failed', image_error: 'scene boom' },
        ],
      },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('Scene one narration');
    expect(wrapper.text()).toContain('Scene two narration');
    expect(wrapper.text()).toContain('scene boom');
    // The ok scene loads its image via the scene's part_key.
    expect(apiMock.get).toHaveBeenCalledWith(
      '/generator/sessions/s1/parts/scene_plan.0/image',
      { responseType: 'blob' },
    );
    expect(wrapper.findAll('img')).toHaveLength(1);
    wrapper.unmount();
  });

  it('gating: Copy + Regenerate are live and the dead "Edit" control is gone (text part)', () => {
    const wrapper = mountCard({
      part: part(),
      result: { kind: 'text_body', status: 'ok', text: 'hi', version: 1 },
    });
    const copy = wrapper.find('button[aria-label="Copy"]');
    expect(copy.exists()).toBe(true);
    expect(copy.attributes('disabled')).toBeUndefined();
    expect(wrapper.find('button[aria-label="Regenerate"]').attributes('disabled')).toBeUndefined();
    expect(wrapper.find('button[aria-label="Edit"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('2d: renders the "Version N" badge once a part is past its first version', () => {
    const wrapper = mountCard({
      part: part(),
      result: { kind: 'text_body', status: 'ok', text: 'hi', version: 3 },
    });
    expect(wrapper.text()).toContain('Version 3');
    wrapper.unmount();
  });

  it('2d: no version badge on a first-version part with no history', () => {
    const wrapper = mountCard({
      part: part(),
      result: { kind: 'text_body', status: 'ok', text: 'hi', version: 1 },
    });
    expect(wrapper.text()).not.toContain('Version');
    wrapper.unmount();
  });

  it('2d: Regenerate emits regenerate(partKey); shows a spinner + disables while busy', async () => {
    const wrapper = mountCard({
      part: part(),
      result: { kind: 'text_body', status: 'ok', text: 'hi', version: 1 },
    });
    const regen = wrapper.find('button[aria-label="Regenerate"]');
    await regen.trigger('click');
    expect(wrapper.emitted('regenerate')?.[0]).toEqual(['body']);

    await wrapper.setProps({ busy: true });
    expect(wrapper.find('button[aria-label="Regenerate"]').attributes('aria-busy')).toBe('true');
    wrapper.unmount();
  });

  it('2d: every generative affordance disables while the session is generating', () => {
    const wrapper = mountCard({
      part: part(),
      result: { kind: 'text_body', status: 'ok', text: 'hi', version: 2 },
      partHistory: { can_undo: true, undo_depth: 1 },
      generating: true,
    });
    expect(wrapper.find('button[aria-label="Regenerate"]').attributes('disabled')).toBeDefined();
    expect(wrapper.find('button[aria-label="Refine"]').attributes('disabled')).toBeDefined();
    expect(wrapper.find('button[aria-label="Undo"]').attributes('disabled')).toBeDefined();
    wrapper.unmount();
  });

  it('2d: Undo is gated on part_history.can_undo and emits undo(partKey)', async () => {
    const wrapper = mountCard({
      part: part(),
      result: { kind: 'text_body', status: 'ok', text: 'hi', version: 2 },
      partHistory: { can_undo: true, undo_depth: 1 },
    });
    const undo = wrapper.find('button[aria-label="Undo"]');
    expect(undo.attributes('disabled')).toBeUndefined();
    await undo.trigger('click');
    expect(wrapper.emitted('undo')?.[0]).toEqual(['body']);
    wrapper.unmount();
  });

  it('2d: Undo stays disabled with no undoable history', () => {
    const wrapper = mountCard({
      part: part(),
      result: { kind: 'text_body', status: 'ok', text: 'hi', version: 1 },
    });
    expect(wrapper.find('button[aria-label="Undo"]').attributes('disabled')).toBeDefined();
    wrapper.unmount();
  });

  it('2d: a refinable part reveals an inline instruction input and emits refine({partKey, instruction})', async () => {
    const wrapper = mountCard({
      part: part(),
      result: { kind: 'text_body', status: 'ok', text: 'hi', version: 1 },
    });
    await wrapper.find('button[aria-label="Refine"]').trigger('click');
    const input = wrapper.find('textarea');
    expect(input.exists()).toBe(true);

    await input.setValue('make it shorter');
    const submit = wrapper.findAll('button').find((b) => b.text() === 'Refine');
    await submit?.trigger('click');

    expect(wrapper.emitted('refine')?.[0]).toEqual([{ partKey: 'body', instruction: 'make it shorter' }]);
    wrapper.unmount();
  });

  it('2d: a scene_plan part is NOT refinable but can still be regenerated', () => {
    const wrapper = mountCard({
      part: part({ key: 'scenes', kind: 'scene_plan', label: 'Scenes' }),
      result: { kind: 'scene_plan', status: 'ok', scenes: [] },
    });
    expect(wrapper.find('button[aria-label="Refine"]').exists()).toBe(false);
    expect(wrapper.find('button[aria-label="Regenerate"]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('2c: Save-to-Disk is LIVE on a produced image and emits a save request', async () => {
    const wrapper = mountCard({
      part: part({ key: 'image', kind: 'image_plan', label: 'Image' }),
      result: { kind: 'image_plan', status: 'ok', image: { mime: 'image/png', width: 100, height: 80 } },
    });
    await flushPromises();

    const save = wrapper.findAll('button').find((b) => b.text() === 'Save to Disk');
    expect(save).toBeTruthy();
    expect(save?.attributes('disabled')).toBeUndefined();

    await save?.trigger('click');
    const emitted = wrapper.emitted('save');
    expect(emitted).toBeTruthy();
    expect(emitted?.[0][0]).toMatchObject({ partKey: 'image' });
    expect((emitted?.[0][0] as { name: string }).name).toMatch(/\.png$/);
    wrapper.unmount();
  });

  it('2c: Save-to-Disk stays disabled while an image part is still failed (nothing to save)', async () => {
    const wrapper = mountCard({
      part: part({ key: 'image', kind: 'image_plan', label: 'Image' }),
      result: { kind: 'image_plan', status: 'failed', error: 'image boom' },
    });
    await flushPromises();

    const save = wrapper.findAll('button').find((b) => b.text() === 'Save to Disk');
    expect(save).toBeTruthy();
    expect(save?.attributes('disabled')).toBeDefined();
    wrapper.unmount();
  });

  // --- shot_list (video_script Phase B) -------------------------------------
  const shotListPart = () => part({ key: 'shot_list', kind: 'shot_list', label: 'Shot list' });

  it('shot_list (ok): renders hook, ordered shots (visual/voiceover/seconds) and cta; is refinable + copyable', () => {
    const wrapper = mountCard({
      part: shotListPart(),
      result: {
        kind: 'shot_list',
        status: 'ok',
        hook: 'Stop scrolling',
        shots: [
          { visual: 'Wide desk shot', voiceover: 'Meet the tool', seconds: 3 },
          { visual: 'Close up', voiceover: 'It just works', seconds: 2 },
        ],
        cta: 'Follow for more',
        text: 'flattened script',
        parse_ok: true,
        version: 1,
      },
    });
    const body = wrapper.text();
    expect(body).toContain('Stop scrolling'); // hook
    expect(body).toContain('Shot 1');
    expect(body).toContain('Wide desk shot'); // visual
    expect(body).toContain('Meet the tool'); // voiceover
    expect(body).toContain('3s'); // seconds chip
    expect(body).toContain('Follow for more'); // cta
    // shot_list IS refinable + copyable; it is NOT an image (no Save-to-Disk).
    expect(wrapper.find('button[aria-label="Refine"]').exists()).toBe(true);
    expect(wrapper.find('button[aria-label="Copy"]').attributes('disabled')).toBeUndefined();
    expect(wrapper.findAll('button').some((b) => b.text() === 'Save to Disk')).toBe(false);
    wrapper.unmount();
  });

  it('shot_list (parse_ok:false): shows the raw text + a "couldn\'t structure" info note, no shots list', () => {
    const wrapper = mountCard({
      part: shotListPart(),
      result: {
        kind: 'shot_list',
        status: 'ok',
        hook: '',
        shots: [],
        cta: '',
        text: 'raw unparseable reply',
        parse_ok: false,
        version: 1,
      },
    });
    expect(wrapper.text()).toContain('raw unparseable reply');
    expect(wrapper.text()).toContain("We couldn't structure this reply");
    expect(wrapper.text()).not.toContain('Shot 1');
    wrapper.unmount();
  });

  // --- storyboard (video_script Phase B, per-shot surface) ------------------
  const storyboardPart = () => part({ key: 'storyboard', kind: 'storyboard', label: 'Storyboard' });
  const twoShots = () => ({
    kind: 'storyboard' as const,
    status: 'ok' as const,
    shots: [
      { index: 0, visual: 'wide shot', voiceover: 'hello', seconds: 3, image_status: 'ok', part_key: 'storyboard.0', image: { mime: 'image/png', width: 10, height: 10, version: 1 } },
      { index: 1, visual: 'close up', voiceover: 'world', seconds: 2, image_status: 'failed', image_error: 'shot boom' },
    ],
  });

  it('storyboard (ok): renders per-shot cards — image via the serve endpoint + a per-shot error alert', async () => {
    const wrapper = mountCard({ part: storyboardPart(), result: twoShots() });
    await flushPromises();

    expect(wrapper.text()).toContain('Shot 1');
    expect(wrapper.text()).toContain('wide shot');
    expect(wrapper.text()).toContain('shot boom'); // failed shot's error
    expect(apiMock.get).toHaveBeenCalledWith(
      '/generator/sessions/s1/parts/storyboard.0/image?v=1',
      { responseType: 'blob' },
    );
    // NOT top-level refinable — a bare storyboard's refine lives per shot only, and only on a
    // PRODUCED (`ok`) shot. `twoShots()` has one ok shot (0) and one failed shot (1), so exactly
    // one per-shot Refine renders; the header has no extra top-level Refine.
    expect(wrapper.findAll('button[aria-label="Refine"]').length).toBe(1);
    wrapper.unmount();
  });

  it('storyboard: a per-shot regenerate/undo/save emit with the shot\'s storyboard.<i> partKey', async () => {
    const wrapper = mountCard({
      part: storyboardPart(),
      result: twoShots(),
      partHistoryMap: { 'storyboard.0': { can_undo: true, undo_depth: 1 } },
    });
    await flushPromises();

    // Shot 0's regenerate (first per-shot regen icon).
    await wrapper.findAll('button[aria-label="Regenerate"]')[0].trigger('click');
    expect(wrapper.emitted('regenerate')?.[0]).toEqual(['storyboard.0']);

    // Shot 0's undo (gated on part_history['storyboard.0']).
    const undo0 = wrapper.findAll('button[aria-label="Undo"]')[0];
    expect(undo0.attributes('disabled')).toBeUndefined();
    await undo0.trigger('click');
    expect(wrapper.emitted('undo')?.[0]).toEqual(['storyboard.0']);

    // Shot 0's save-to-disk (only the ok shot exposes it).
    const save = wrapper.findAll('button').find((b) => b.text() === 'Save to Disk');
    await save?.trigger('click');
    expect((wrapper.emitted('save')?.[0][0] as { partKey: string }).partKey).toBe('storyboard.0');
    wrapper.unmount();
  });

  it('storyboard: a per-shot refine reveals an inline composer and emits refine({partKey:"storyboard.0"})', async () => {
    const wrapper = mountCard({ part: storyboardPart(), result: twoShots() });
    await flushPromises();

    await wrapper.findAll('button[aria-label="Refine"]')[0].trigger('click');
    const input = wrapper.find('textarea');
    expect(input.exists()).toBe(true);
    await input.setValue('brighter lighting');
    const submit = wrapper.findAll('button').find((b) => b.text() === 'Refine');
    await submit?.trigger('click');
    expect(wrapper.emitted('refine')?.[0]).toEqual([{ partKey: 'storyboard.0', instruction: 'brighter lighting' }]);
    wrapper.unmount();
  });

  it('storyboard: per-shot Refine is offered ONLY on a produced (ok) shot, not a failed one; regenerate stays on both', async () => {
    // `twoShots()`: shot 0 image_status 'ok', shot 1 image_status 'failed'.
    const wrapper = mountCard({ part: storyboardPart(), result: twoShots() });
    await flushPromises();

    // Refine (AI image-edit) has nothing to revise on a failed shot → exactly one Refine, on shot 0.
    const refines = wrapper.findAll('button[aria-label="Refine"]');
    expect(refines.length).toBe(1);
    // Clicking it targets the ok shot's key (storyboard.0), never the failed shot.
    await refines[0].trigger('click');
    await wrapper.find('textarea').setValue('crisper focus');
    await wrapper.findAll('button').find((b) => b.text() === 'Refine')?.trigger('click');
    expect(wrapper.emitted('refine')?.[0]).toEqual([{ partKey: 'storyboard.0', instruction: 'crisper focus' }]);

    // Regenerate legitimately RETRIES a failed shot, so it stays on both shots.
    expect(wrapper.findAll('button[aria-label="Regenerate"]').length).toBe(2);
    wrapper.unmount();
  });

  it('storyboard: only the ACTING shot spins (per-shot busy isolation via busyPartKey)', async () => {
    const wrapper = mountCard({
      part: storyboardPart(),
      result: twoShots(),
      generating: true,
      busyPartKey: 'storyboard.0',
    });
    await flushPromises();
    const regens = wrapper.findAll('button[aria-label="Regenerate"]');
    expect(regens[0].attributes('aria-busy')).toBe('true'); // shot 0 acting
    expect(regens[1].attributes('aria-busy')).not.toBe('true'); // shot 1 idle
    wrapper.unmount();
  });

  it('storyboard: a top-level "Regenerate all shots" regenerates the whole part', async () => {
    const wrapper = mountCard({ part: storyboardPart(), result: twoShots() });
    await flushPromises();
    const regenAll = wrapper.findAll('button').find((b) => b.text() === 'Regenerate all shots');
    expect(regenAll).toBeTruthy();
    await regenAll?.trigger('click');
    expect(wrapper.emitted('regenerate')?.[0]).toEqual(['storyboard']);
    wrapper.unmount();
  });

  it('storyboard (empty shots): shows the "generate a shot list first" empty state', () => {
    const wrapper = mountCard({
      part: storyboardPart(),
      result: { kind: 'storyboard', status: 'ok', shots: [] },
    });
    expect(wrapper.text()).toContain('No shots yet');
    wrapper.unmount();
  });

  // --- Collapse toggle (owner note #2) --------------------------------------
  // The card carries the same show/hide affordance as the other turn cards, but starts EXPANDED and hides
  // its body with `v-show`. The v-show rule is load-bearing: SessionPartImage owns an IntersectionObserver
  // and a blob object-URL per image, so a `v-if` regression would re-fetch every image on each collapse and
  // dangle `aria-controls`. These tests pin that.
  describe('collapse', () => {
    /** The header's show/hide control (the action row lives in the footer, outside header actions). */
    const toggleOf = (wrapper: ReturnType<typeof mountCard>) =>
      wrapper.get('.next-card__header-actions button');

    it('starts EXPANDED — the result is the artifact the user came for', () => {
      const wrapper = mountCard({
        part: part(),
        result: { kind: 'text_body', status: 'ok', text: 'Hello world' },
      });
      const toggle = toggleOf(wrapper);
      expect(toggle.attributes('aria-expanded')).toBe('true');
      expect(toggle.text()).toBe('Hide');
      const body = wrapper.get(`#${toggle.attributes('aria-controls')}`);
      expect((body.element as HTMLElement).style.display).not.toBe('none');
      wrapper.unmount();
    });

    it('collapses and re-expands, tracking aria-expanded against a RESOLVING aria-controls', async () => {
      const wrapper = mountCard({
        part: part(),
        result: { kind: 'text_body', status: 'ok', text: 'Hello world' },
      });
      const selector = `#${toggleOf(wrapper).attributes('aria-controls')}`;

      await toggleOf(wrapper).trigger('click');
      expect(toggleOf(wrapper).attributes('aria-expanded')).toBe('false');
      expect(toggleOf(wrapper).text()).toBe('Show');
      expect((wrapper.get(selector).element as HTMLElement).style.display).toBe('none');

      await toggleOf(wrapper).trigger('click');
      expect(toggleOf(wrapper).attributes('aria-expanded')).toBe('true');
      expect((wrapper.get(selector).element as HTMLElement).style.display).not.toBe('none');
      wrapper.unmount();
    });

    it('collapsing HIDES but never UNMOUNTS the body — the image is not re-fetched (v-if guard)', async () => {
      const wrapper = mountCard({
        part: part({ key: 'image', kind: 'image_plan', label: 'Image' }),
        result: { kind: 'image_plan', status: 'ok', image: { mime: 'image/png', width: 100, height: 80 } },
      });
      await flushPromises();
      expect(apiMock.get).toHaveBeenCalledTimes(1);

      const selector = `#${toggleOf(wrapper).attributes('aria-controls')}`;
      await toggleOf(wrapper).trigger('click');
      await flushPromises();

      // The body element (and the <img> inside it) survives the collapse…
      expect(wrapper.find(selector).exists()).toBe(true);
      expect(wrapper.find('img').exists()).toBe(true);

      await toggleOf(wrapper).trigger('click');
      await flushPromises();
      // …so re-expanding costs nothing: still exactly one blob fetch.
      expect(apiMock.get).toHaveBeenCalledTimes(1);
      wrapper.unmount();
    });

    it('keeps the whole-part action row reachable while collapsed (it lives in the footer)', async () => {
      const wrapper = mountCard({
        part: part(),
        result: { kind: 'text_body', status: 'ok', text: 'hi', version: 2 },
        partHistory: { can_undo: true, undo_depth: 1 },
      });
      await toggleOf(wrapper).trigger('click');

      const footer = wrapper.get('.next-card__footer');
      expect((footer.element as HTMLElement).style.display).not.toBe('none');
      expect(footer.find('button[aria-label="Regenerate"]').exists()).toBe(true);
      expect(footer.find('button[aria-label="Undo"]').attributes('disabled')).toBeUndefined();
      wrapper.unmount();
    });

    it('shows a one-line teaser when collapsed — text: the first line; storyboard: the shot count', async () => {
      const textCard = mountCard({
        part: part(),
        result: { kind: 'text_body', status: 'ok', text: '# Spring promo\n\nBody copy here.' },
      });
      await toggleOf(textCard).trigger('click');
      // Asserted on the HEADER: the body stays mounted, so a whole-wrapper match would prove nothing.
      expect(textCard.get('.next-card__header').text()).toContain('Spring promo');
      textCard.unmount();

      const storyboard = mountCard({ part: storyboardPart(), result: twoShots() });
      await flushPromises();
      await toggleOf(storyboard).trigger('click');
      expect(storyboard.get('.next-card__header').text()).toContain('2 shots');
      storyboard.unmount();
    });

    it('teases the ERROR of a failed part (the reason to open it)', async () => {
      const wrapper = mountCard({
        part: part(),
        result: { kind: 'text_body', status: 'failed', error: 'boom on this part' },
      });
      await toggleOf(wrapper).trigger('click');
      expect(wrapper.get('.next-card__header').text()).toContain('boom on this part');
      wrapper.unmount();
    });
  });

  // --- Stale hint (Phase A cross-part coherence) ----------------------------
  it('stale: a downstream part flagged stale shows a non-color-only "may be out of date" badge', () => {
    const wrapper = mountCard({
      part: storyboardPart(),
      result: { kind: 'storyboard', status: 'ok', shots: [], stale: true },
    });
    expect(wrapper.text()).toContain('May be out of date');
    wrapper.unmount();
  });

  // --- In-flight frames (the distributed-frames stage) ----------------------
  // A storyboard's frames render in SEPARATE queue jobs, so a session fetched mid-run legitimately
  // carries `pending` / `rendering` shots. Before this arm they rendered as an empty hole: the beat's
  // text with nothing where the picture goes, and no way to tell "still coming" from "nothing here".
  it('renders a placeholder of the same geometry for a frame that is still being rendered', async () => {
    const sb = {
      kind: 'storyboard' as const,
      status: 'ok' as const,
      shots: [
        { index: 0, visual: 'wide shot', voiceover: 'a', seconds: 3, image_status: 'pending' },
        { index: 1, visual: 'close up', voiceover: 'b', seconds: 2, image_status: 'rendering' },
      ],
    };
    const wrapper = mountCard({ part: storyboardPart(), result: sb });
    await flushPromises();

    expect(wrapper.findAll('[data-test="shot-frame-pending"]')).toHaveLength(2);
    expect(wrapper.text()).toContain('Frame in progress…');
    // The beats themselves are still readable while their images are on the way.
    expect(wrapper.text()).toContain('wide shot');
    // No image request is made for a frame that has none yet.
    expect(apiMock.get).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  // --- Character signals (R2 sub-stage 3) ----------------------------------
  it('marks the shots drawn from the session CHARACTER — and only when the session has one', async () => {
    const sb = {
      kind: 'storyboard' as const,
      status: 'ok' as const,
      shots: [
        { index: 0, visual: 'the creator to camera', voiceover: 'a', seconds: 3, image_status: 'ok', part_key: 'storyboard.0', features_character: true },
        { index: 1, visual: 'the product alone', voiceover: 'b', seconds: 2, image_status: 'ok', part_key: 'storyboard.1', features_character: false },
      ],
    };

    // Without a character the flag means nothing — no marker at all.
    const plain = mountCard({ part: storyboardPart(), result: sb });
    await flushPromises();
    expect(plain.text()).not.toContain('With character');
    plain.unmount();

    const wrapper = mountCard({ part: storyboardPart(), result: sb, hasCharacterImage: true });
    await flushPromises();
    // Exactly one shot is marked — the one the model flagged.
    expect(wrapper.findAll('.next-badge').filter((b) => b.text() === 'With character')).toHaveLength(1);
    wrapper.unmount();
  });

  it('promotes a MODERATION refusal over the generic prose, and offers the appearance as the fix', async () => {
    const sb = {
      kind: 'storyboard' as const,
      status: 'ok' as const,
      shots: [
        {
          index: 0,
          visual: 'the creator to camera',
          voiceover: 'a',
          seconds: 3,
          image_status: 'failed',
          image_error: 'The image could not be produced.',
          // The per-SHOT key — namespaced `image_*` like `image_error`. The whole-part fixture below
          // uses `error_code`; asserting BOTH shapes is what catches a FE/BE key drift between them.
          image_error_code: 'image_safety',
          features_character: true,
        },
      ],
    };
    const wrapper = mountCard({
      part: storyboardPart(),
      result: sb,
      hasCharacterImage: true,
      botAuthorId: 'bot-9',
    });
    await flushPromises();

    expect(wrapper.text()).toContain('content policy');
    expect(wrapper.text()).not.toContain('The image could not be produced.');

    const action = wrapper.findAll('button').find((b) => b.text().includes('Bot appearance'))!;
    await action.trigger('click');
    // Deep-links into the bot editor's Wygląd module — where the description + wardrobe live.
    expect(routerPush).toHaveBeenCalledWith({
      name: 'next.bots',
      query: { bot: 'bot-9', botModule: 'visual' },
    });
    wrapper.unmount();
  });

  it('offers the appearance action on CONTEXT, not on the error text — and never without a character', async () => {
    const failedNonCharacter = {
      kind: 'storyboard' as const,
      status: 'ok' as const,
      shots: [
        { index: 0, visual: 'a chart', voiceover: 'a', seconds: 3, image_status: 'failed', image_error: 'boom', features_character: false },
      ],
    };
    const wrapper = mountCard({
      part: storyboardPart(),
      result: failedNonCharacter,
      hasCharacterImage: true,
      botAuthorId: 'bot-9',
    });
    await flushPromises();
    expect(wrapper.text()).not.toContain('Bot appearance');
    wrapper.unmount();

    // A character shot, but nothing to link to (an undelegated session) → no dead action.
    const noAuthor = mountCard({
      part: storyboardPart(),
      result: {
        ...failedNonCharacter,
        shots: [{ ...failedNonCharacter.shots[0], features_character: true }],
      },
      hasCharacterImage: true,
    });
    await flushPromises();
    expect(noAuthor.text()).not.toContain('Bot appearance');
    noAuthor.unmount();
  });

  it('promotes a moderation refusal on a SCENE image too (the third arm)', async () => {
    const wrapper = mountCard({
      part: part({ key: 'scenes', kind: 'scene_plan', label: 'Scenes' }),
      result: {
        kind: 'scene_plan',
        status: 'ok',
        scenes: [
          {
            narration: 'A scene.',
            image_status: 'failed',
            image_error: 'The image could not be produced.',
            image_error_code: 'image_safety',
          },
        ],
      },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('content policy');
    expect(wrapper.text()).not.toContain('The image could not be produced.');
    wrapper.unmount();
  });

  it('promotes a moderation refusal on a whole image PART too', () => {
    const wrapper = mountCard({
      part: part({ key: 'image', kind: 'image_plan', label: 'Image' }),
      result: {
        kind: 'image_plan',
        status: 'failed',
        error: 'The image could not be produced.',
        error_code: 'image_safety',
      },
    });
    expect(wrapper.text()).toContain('content policy');
    wrapper.unmount();
  });
});
