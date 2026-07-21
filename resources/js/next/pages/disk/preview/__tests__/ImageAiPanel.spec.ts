// @vitest-environment happy-dom
// ImageAiPanel.spec — the AI actions panel (F2-3). Behaviour-focused with a FAKE editor (reactive
// refs + spies) so no real canvas is needed: a masked op ARMS the mask brush and its Apply stays
// DISABLED until a mask is painted, then applies with the exported mask; and a Cancel control shows
// while an edit is in flight. The engine's own applyAi/exportMask are covered in useImageEditor.spec.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { nextTick, ref } from 'vue';
import ImageAiPanel from '../ImageAiPanel.vue';
import type { useImageEditor } from '../useImageEditor';
import { setLocale } from '../../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../../__tests__/helpers/dom';

/** A minimal reactive stand-in for the editor the panel drives. */
function makeEditor() {
  return {
    aiBusy: ref(false),
    hasMaskStrokes: ref(false),
    brushSize: ref(48),
    maskMode: ref(false),
    setMaskMode: vi.fn(),
    clearMask: vi.fn(),
    cancelAi: vi.fn(),
    exportMask: vi.fn(async () => new Blob(['m'], { type: 'image/png' })),
    applyAi: vi.fn(async () => {}),
  };
}
type FakeEditor = ReturnType<typeof makeEditor>;

function mountPanel(editor: FakeEditor) {
  return mount(ImageAiPanel, {
    props: { editor: editor as unknown as ReturnType<typeof useImageEditor> },
  });
}

/** The single primary (Apply) button in the masked section, if rendered. */
function maskedApply(wrapper: ReturnType<typeof mountPanel>) {
  return wrapper.findAll('button').find((b) => b.classes().includes('bg-next-primary'));
}

describe('ImageAiPanel', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => restoreBrowserMocks());

  it('arms the mask brush for a masked op and gates Apply until a mask is painted', async () => {
    const editor = makeEditor();
    const wrapper = mountPanel(editor);

    // No masked op armed yet → no primary Apply.
    expect(maskedApply(wrapper)).toBeUndefined();

    // The masked chips are the ones carrying aria-pressed; arm the first (Remove object).
    const chips = wrapper.findAll('[aria-pressed]');
    expect(chips.length).toBe(2);
    await chips[0].trigger('click');
    expect(editor.setMaskMode).toHaveBeenCalledWith(true);

    // Apply now renders but is DISABLED (nothing painted) + the hint is shown.
    const apply = maskedApply(wrapper);
    expect(apply).toBeTruthy();
    expect(apply!.attributes('disabled')).toBeDefined();
    expect(wrapper.text()).toContain('Paint over the area to edit.');

    // Paint a mask → Apply enables → applying sends the exported mask with the op's prompt.
    editor.hasMaskStrokes.value = true;
    await nextTick();
    expect(maskedApply(wrapper)!.attributes('disabled')).toBeUndefined();

    await maskedApply(wrapper)!.trigger('click');
    await flushPromises();
    expect(editor.exportMask).toHaveBeenCalled();
    // The remove-object op sends its fixed removal instruction + the exported mask (assert loosely so
    // prompt-wording tweaks don't break the test).
    expect(editor.applyAi).toHaveBeenCalledWith(expect.stringContaining('remove any object'), expect.any(Blob));
  });

  it('re-clicking an armed masked chip disarms it (mask mode off)', async () => {
    const editor = makeEditor();
    const wrapper = mountPanel(editor);
    const chip = wrapper.findAll('[aria-pressed]')[0];

    await chip.trigger('click');
    expect(editor.setMaskMode).toHaveBeenLastCalledWith(true);
    await chip.trigger('click');
    expect(editor.setMaskMode).toHaveBeenLastCalledWith(false);
  });

  it('exits area-edit mode back to the whole image via the explicit exit button', async () => {
    const editor = makeEditor();
    const wrapper = mountPanel(editor);
    await wrapper.findAll('[aria-pressed]')[0].trigger('click'); // arm Remove object
    expect(editor.setMaskMode).toHaveBeenLastCalledWith(true);

    const exit = wrapper.findAll('button').find((b) => b.text().trim() === 'Edit whole image');
    expect(exit).toBeTruthy();
    await exit!.trigger('click');
    expect(editor.setMaskMode).toHaveBeenLastCalledWith(false); // back to whole-image editing
  });

  it('shows a Cancel control while an edit is in flight and calls cancelAi', async () => {
    const editor = makeEditor();
    const wrapper = mountPanel(editor);

    editor.aiBusy.value = true;
    await nextTick();
    const cancel = wrapper.findAll('button').find((b) => b.text().trim() === 'Cancel');
    expect(cancel).toBeTruthy();

    await cancel!.trigger('click');
    expect(editor.cancelAi).toHaveBeenCalled();
  });

  it('applies a full-image preset immediately with no mask', async () => {
    const editor = makeEditor();
    const wrapper = mountPanel(editor);

    // The full-image chips have no aria-pressed; the first is Remove background.
    const removeBg = wrapper.findAll('button').find((b) => b.text().trim() === 'Remove background');
    expect(removeBg).toBeTruthy();
    await removeBg!.trigger('click');
    await flushPromises();

    expect(editor.applyAi).toHaveBeenCalledTimes(1);
    // One argument only → whole-image edit (no mask).
    expect(editor.applyAi.mock.calls[0].length).toBe(1);
    expect(editor.exportMask).not.toHaveBeenCalled();
  });
});
