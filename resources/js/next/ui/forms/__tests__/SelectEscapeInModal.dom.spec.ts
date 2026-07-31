// @vitest-environment happy-dom
// SelectEscapeInModal.dom.spec — REGRESSION: Escape inside a Select that lives in a Modal.
//
// The overlay stack's Escape listener is installed on `document` in the CAPTURE phase, so it runs
// BEFORE any component-local bubble handler. Select used to rely on its own `stopPropagation()` in
// `handleNavKey`, which therefore came far too late: pressing Escape with the options list open
// closed the MODAL and discarded whatever the user had typed into it. The fix registers the open
// popover with the stack, making it the topmost overlay.
//
// This is deliberately pinned at the Select level (not only in the ai-text author panel) because it
// affects every Select rendered inside a modal or drawer across the app.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, nextTick, ref } from 'vue';
import Modal from '../../overlay/Modal.vue';
import Select from '../Select.vue';
import { overlayStackSize } from '../../../app/composables/useOverlayStack';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const OPTIONS = [
  { value: 'a', label: 'Alpha' },
  { value: 'b', label: 'Beta' },
];

/** A Modal that opens AFTER mount — Modal registers with the stack from a non-immediate watch. */
const Host = defineComponent({
  components: { Modal, Select },
  setup() {
    const open = ref(false);
    return { open, OPTIONS };
  },
  template: `
    <Modal v-model:open="open" aria-label="host">
      <Select :options="OPTIONS" aria-label="picker" />
    </Modal>
  `,
});

function pressEscape(target: EventTarget): void {
  target.dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }),
  );
}

describe('Select inside a Modal — Escape is scoped to the topmost overlay', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => {
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('the first Escape closes the OPTIONS LIST, the second closes the modal', async () => {
    const wrapper = mount(Host, { attachTo: document.body });
    (wrapper.vm as unknown as { open: boolean }).open = true;
    await nextTick();
    await nextTick();

    const trigger = document.body.querySelector('[role="combobox"]') as HTMLElement;
    trigger.click();
    await nextTick();
    expect(trigger.getAttribute('aria-expanded')).toBe('true');

    // 1st Escape → the list closes; the modal survives.
    pressEscape(trigger);
    await nextTick();
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    expect(document.body.querySelector('.next-modal')).not.toBeNull();

    // 2nd Escape → now the modal closes.
    pressEscape(document.body.querySelector('.next-modal') as HTMLElement);
    await nextTick();
    await nextTick();
    expect(document.body.querySelector('.next-modal')).toBeNull();

    wrapper.unmount();
  });

  // Registering makes Select the most common overlay in the app, so a LEAKED entry would silently
  // starve every other overlay of Escape (the stack's capture listener answers for the topmost
  // entry and stops the event there). Unmounting with the list still open must release it.
  it('releases its stack entry when unmounted with the list still open', async () => {
    const baseline = overlayStackSize();
    const wrapper = mount(Host, { attachTo: document.body });
    (wrapper.vm as unknown as { open: boolean }).open = true;
    await nextTick();
    await nextTick();

    const trigger = document.body.querySelector('[role="combobox"]') as HTMLElement;
    trigger.click();
    await nextTick();
    expect(overlayStackSize()).toBe(baseline + 2); // the modal + the open list

    wrapper.unmount();
    await nextTick();
    expect(overlayStackSize()).toBe(baseline);
  });
});
