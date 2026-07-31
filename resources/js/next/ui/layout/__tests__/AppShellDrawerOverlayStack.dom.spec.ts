// @vitest-environment happy-dom
// AppShellDrawerOverlayStack.dom.spec — the mobile navigation drawer is an overlay, so it belongs in
// the shared overlay stack.
//
// The drawer is `role="dialog" aria-modal="true"` with a scrim, a focus trap and a body-scroll lock,
// but its Escape handler was a plain `@keydown` on its own subtree. Two consequences:
//
//   1. Escape raised anywhere OUTSIDE that subtree never reached it — including from a
//      body-TELEPORTED popover opened by a control inside the drawer, which is where the caret
//      actually is while such a control is open.
//   2. It had no place in the dismissal ORDER. Since `Select` joined the stack (and it is the most
//      common overlay in the app), the stack's capture-phase listener `stopPropagation()`s Escape
//      for the topmost overlay, so a drawer that is not in the stack cannot be reasoned about
//      against one that is.
//
// Registering fixes both AND keeps the ordering honest: an open options list wins the first Escape,
// the drawer the second.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, nextTick, ref } from 'vue';
import AppShell from '../AppShell.vue';
import Select from '../../forms/Select.vue';
import Button from '../../primitives/Button.vue';
import { overlayStackSize } from '../../../app/composables/useOverlayStack';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

const OPTIONS = [
  { value: 'a', label: 'Alpha' },
  { value: 'b', label: 'Beta' },
];

/** The shell with a page-level Select in main content and a menu button wired to `openDrawer`. */
const Host = defineComponent({
  components: { AppShell, Select, Button },
  setup() {
    return { OPTIONS };
  },
  template: `
    <AppShell>
      <template #sidebar><nav aria-label="nav"><a href="#x">Item</a></nav></template>
      <template #navbar="{ openDrawer }">
        <Button data-test="menu" @click="openDrawer">Menu</Button>
      </template>
      <Select :options="OPTIONS" aria-label="picker" />
    </AppShell>
  `,
});

async function settle(times = 3): Promise<void> {
  for (let i = 0; i < times; i += 1) await nextTick();
}

function pressEscape(target: EventTarget): void {
  target.dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }),
  );
}

const drawer = (): HTMLElement | null =>
  document.body.querySelector('[role="dialog"][aria-modal="true"]');
const trigger = (): HTMLElement => document.body.querySelector('[role="combobox"]') as HTMLElement;

async function openDrawer(wrapper: ReturnType<typeof mount>): Promise<void> {
  (document.body.querySelector('[data-test="menu"]') as HTMLElement).click();
  await settle();
  expect(drawer()).not.toBeNull();
  void wrapper;
}

describe('AppShell mobile drawer — Escape goes through the overlay stack', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => {
    document.body.innerHTML = '';
    document.body.style.overflow = '';
    restoreBrowserMocks();
  });

  it('closes on an Escape raised OUTSIDE its own subtree', async () => {
    const baseline = overlayStackSize();
    const wrapper = mount(Host, { attachTo: document.body });
    await openDrawer(wrapper);

    // An open drawer is an overlay in its own right.
    expect(overlayStackSize()).toBe(baseline + 1);

    // The caret can legitimately sit outside the drawer's DOM (a body-teleported popover).
    pressEscape(document.body);
    await settle();

    expect(drawer()).toBeNull();
    expect(overlayStackSize()).toBe(baseline);

    wrapper.unmount();
  });

  it('yields the first Escape to an open Select list and closes on the second', async () => {
    const baseline = overlayStackSize();
    const wrapper = mount(Host, { attachTo: document.body });
    await openDrawer(wrapper);

    trigger().click();
    await settle();
    expect(trigger().getAttribute('aria-expanded')).toBe('true');

    // 1st Escape → the options list only.
    pressEscape(trigger());
    await settle();
    expect(trigger().getAttribute('aria-expanded')).toBe('false');
    expect(drawer()).not.toBeNull();

    // 2nd Escape → now the drawer.
    pressEscape(document.body);
    await settle();
    expect(drawer()).toBeNull();
    expect(overlayStackSize()).toBe(baseline);

    wrapper.unmount();
  });

  it('releases its stack entry when the shell unmounts with the drawer open', async () => {
    const baseline = overlayStackSize();
    const wrapper = mount(Host, { attachTo: document.body });
    await openDrawer(wrapper);
    expect(overlayStackSize()).toBe(baseline + 1);

    wrapper.unmount();
    await settle();
    expect(overlayStackSize()).toBe(baseline);
  });
});
