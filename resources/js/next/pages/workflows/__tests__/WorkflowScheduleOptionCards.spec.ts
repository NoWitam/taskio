// @vitest-environment happy-dom
// WorkflowScheduleOptionCards.spec — the REV5 radio-group of self-configuring selection
// cards (§4.5.5/§4.5.13). Pins the a11y + focus MODEL the axis panels depend on:
//   • a role=radiogroup of role=radio headers; only the SELECTED card renders a body;
//   • the body is a role=group SIBLING of the header — NEVER inside the radio button
//     (a radio must not wrap interactive controls) — so Tab from the header enters it;
//   • roving tabindex (only the selected header is a tab stop);
//   • arrows (↑/↓ AND ←/→) MOVE + SELECT, wrapping and SKIPPING disabled options;
//   • Home/End jump; disabled headers keep aria-disabled and can't be selected.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick, h } from 'vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import WorkflowScheduleOptionCards from '../WorkflowScheduleOptionCards.vue';

const OPTIONS = [
  { value: 'a', title: 'Alpha' },
  { value: 'b', title: 'Bravo' },
  { value: 'c', title: 'Charlie', disabled: true },
  { value: 'd', title: 'Delta' },
];

function mountCards(modelValue = 'a', options = OPTIONS) {
  const wrapper: VueWrapper = mount(WorkflowScheduleOptionCards, {
    props: {
      modelValue,
      options,
      ariaLabel: 'Axis',
      'onUpdate:modelValue': (v: string) => wrapper.setProps({ modelValue: v }),
    },
    slots: {
      'body-a': () => h('input', { class: 'body-a-input' }),
      'body-b': () => h('input', { class: 'body-b-input' }),
      'body-d': () => h('input', { class: 'body-d-input' }),
    },
  });
  return wrapper;
}

const radios = (w: VueWrapper) => w.findAll('[role="radio"]');
const radioBy = (w: VueWrapper, title: string) => radios(w).find((r) => r.text().includes(title));
const group = (w: VueWrapper) => w.find('[role="radiogroup"]');
const model = (w: VueWrapper) => (w.props() as { modelValue: string }).modelValue;

async function arrow(w: VueWrapper, key: string): Promise<void> {
  await group(w).trigger('keydown', { key });
  await nextTick();
}

describe('WorkflowScheduleOptionCards', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('renders a radiogroup of radio headers; only the selected card shows a body', () => {
    const w = mountCards('a');
    expect(group(w).attributes('aria-label')).toBe('Axis');
    expect(radios(w).length).toBe(4);
    expect(radioBy(w, 'Alpha')!.attributes('aria-checked')).toBe('true');
    expect(w.find('.body-a-input').exists()).toBe(true);
    expect(w.find('.body-b-input').exists()).toBe(false);
    expect(w.find('.body-d-input').exists()).toBe(false);
    w.unmount();
  });

  it('the body is a role=group SIBLING of the header, never INSIDE the radio button', () => {
    const w = mountCards('a');
    const radio = radioBy(w, 'Alpha')!;
    // The radio button must NOT wrap interactive controls.
    expect(radio.find('input').exists()).toBe(false);
    // The body is a role=group labelled by the option title (reached by Tab from the header).
    const body = w.findAll('[role="group"]').find((g) => g.attributes('aria-label') === 'Alpha');
    expect(body).toBeTruthy();
    expect(body!.find('.body-a-input').exists()).toBe(true);
    w.unmount();
  });

  it('roving tabindex: only the selected header is a tab stop; disabled is -1', () => {
    const w = mountCards('a');
    expect(radioBy(w, 'Alpha')!.attributes('tabindex')).toBe('0');
    expect(radioBy(w, 'Bravo')!.attributes('tabindex')).toBe('-1');
    expect(radioBy(w, 'Charlie')!.attributes('tabindex')).toBe('-1');
    w.unmount();
  });

  it('ArrowDown / ArrowRight MOVE + SELECT the next enabled option (skipping disabled)', async () => {
    const w = mountCards('a');
    await arrow(w, 'ArrowDown');
    expect(model(w)).toBe('b');
    await arrow(w, 'ArrowRight');
    // Skips disabled 'c' → 'd'.
    expect(model(w)).toBe('d');
    w.unmount();
  });

  it('ArrowUp / ArrowLeft move back, WRAPPING and skipping disabled', async () => {
    const w = mountCards('a');
    // From 'a' back wraps to the LAST enabled ('d', skipping disabled 'c').
    await arrow(w, 'ArrowUp');
    expect(model(w)).toBe('d');
    // From 'd' forward wraps to 'a'.
    await arrow(w, 'ArrowDown');
    expect(model(w)).toBe('a');
    w.unmount();
  });

  it('Home / End jump to the first / last enabled option', async () => {
    const w = mountCards('b');
    await arrow(w, 'End');
    expect(model(w)).toBe('d');
    await arrow(w, 'Home');
    expect(model(w)).toBe('a');
    w.unmount();
  });

  it('disabled headers keep aria-disabled and cannot be selected by click', async () => {
    const w = mountCards('a');
    const charlie = radioBy(w, 'Charlie')!;
    expect(charlie.attributes('aria-disabled')).toBe('true');
    await charlie.trigger('click');
    await nextTick();
    expect(model(w)).toBe('a'); // unchanged
    w.unmount();
  });

  it('selecting another card moves the body to it', async () => {
    const w = mountCards('a');
    await radioBy(w, 'Bravo')!.trigger('click');
    await nextTick();
    expect(model(w)).toBe('b');
    expect(w.find('.body-a-input').exists()).toBe(false);
    expect(w.find('.body-b-input').exists()).toBe(true);
    w.unmount();
  });
});
