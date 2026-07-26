// @vitest-environment happy-dom
// ConstantEditorDrawer.spec — the create/edit form (formerly the globals editor). Asserts the
// form EMITS the correct `{ name, descriptor, value }` write body for a text, a number, an
// enum (with options) and an array<text> constant; that the VALUE control ADAPTS to the
// chosen base; and that an invalid value is BLOCKED client-side (no `submit` emitted).
// The Drawer teleports to <body>, so inputs/buttons are queried there.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import ConstantEditorDrawer from '../ConstantEditorDrawer.vue';
import { setLocale } from '../../../app/i18n';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

let wrapper: ReturnType<typeof mount> | null = null;

function mountDrawer() {
  wrapper = mount(ConstantEditorDrawer, { attachTo: document.body, props: { open: true } });
  return wrapper;
}

function inputsByPlaceholder(placeholder: string): HTMLInputElement[] {
  return Array.from(document.body.querySelectorAll('input')).filter(
    (i) => i.getAttribute('placeholder') === placeholder,
  );
}
function firstInput(placeholder: string): HTMLInputElement | undefined {
  return inputsByPlaceholder(placeholder)[0];
}
function setInput(el: HTMLInputElement | undefined, value: string): void {
  if (!el) throw new Error('input not found');
  el.value = value;
  el.dispatchEvent(new Event('input', { bubbles: true }));
}
function baseRadio(label: string): HTMLElement {
  const radio = Array.from(document.body.querySelectorAll<HTMLElement>('[role="radio"]')).find((r) =>
    r.textContent?.includes(label),
  );
  if (!radio) throw new Error(`base radio "${label}" not found`);
  return radio;
}
function bodyButton(text: string): HTMLButtonElement | undefined {
  return Array.from(document.body.querySelectorAll('button')).find((b) => b.textContent?.trim() === text) as
    | HTMLButtonElement
    | undefined;
}
async function flush(): Promise<void> {
  await nextTick();
  await Promise.resolve();
  await nextTick();
}

/** The last emitted `submit` payload, or null. */
function lastSubmit(w: ReturnType<typeof mountDrawer>): Record<string, unknown> | null {
  const emitted = w.emitted('submit');
  return emitted ? (emitted[emitted.length - 1][0] as Record<string, unknown>) : null;
}

describe('ConstantEditorDrawer', () => {
  beforeEach(() => {
    setLocale('en');
    installBrowserMocks();
  });
  afterEach(() => {
    // The Drawer teleports to <body>; unmount + clear so no drawer leaks into the next test.
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    restoreBrowserMocks();
  });

  it('emits a TEXT constant body (no key when the slug is untouched)', async () => {
    const wrapper = mountDrawer();
    await flush();

    setInput(firstInput('e.g. Brand name'), 'Brand');
    setInput(firstInput('A text value'), 'Taskio');
    await flush();

    bodyButton('Create constant')!.click();
    await nextTick();

    expect(lastSubmit(wrapper)).toEqual({
      name: 'Brand',
      descriptor: { base: 'text', nullable: false, array: false },
      value: 'Taskio',
    });
  });

  it('emits a NUMBER constant body once the base is switched', async () => {
    const wrapper = mountDrawer();
    await flush();

    setInput(firstInput('e.g. Brand name'), 'Budget');
    baseRadio('Number').click();
    await flush();

    // The value control ADAPTED: a numeric input replaced the text input.
    expect(firstInput('A number')).toBeTruthy();
    expect(firstInput('A text value')).toBeUndefined();

    setInput(firstInput('A number'), '5000');
    await flush();

    bodyButton('Create constant')!.click();
    await nextTick();

    expect(lastSubmit(wrapper)).toEqual({
      name: 'Budget',
      descriptor: { base: 'number', nullable: false, array: false },
      value: 5000,
    });
  });

  it('emits an ENUM constant body with options (value auto-set to a valid member)', async () => {
    const wrapper = mountDrawer();
    await flush();

    setInput(firstInput('e.g. Brand name'), 'Status');
    baseRadio('Choice').click();
    await flush();

    setInput(firstInput('Value'), 'open');
    setInput(firstInput('Label'), 'Open ticket');
    await flush();

    bodyButton('Create constant')!.click();
    await nextTick();

    expect(lastSubmit(wrapper)).toEqual({
      name: 'Status',
      descriptor: {
        base: 'enum',
        nullable: false,
        array: false,
        options: [{ key: 'open', label: 'Open ticket' }],
      },
      value: 'open',
    });
  });

  it('emits an ARRAY<text> constant body from the repeatable list', async () => {
    const wrapper = mountDrawer();
    await flush();

    setInput(firstInput('e.g. Brand name'), 'Tags');
    // The FIRST role=switch in the type section is the `array` toggle.
    (document.body.querySelectorAll('[role="switch"]')[0] as HTMLButtonElement).click();
    await flush();

    bodyButton('Add item')!.click();
    await flush();
    setInput(inputsByPlaceholder('A text value')[0], 'ai');
    bodyButton('Add item')!.click();
    await flush();
    setInput(inputsByPlaceholder('A text value')[1], 'auto');
    await flush();

    bodyButton('Create constant')!.click();
    await nextTick();

    expect(lastSubmit(wrapper)).toEqual({
      name: 'Tags',
      descriptor: { base: 'text', nullable: false, array: true },
      value: ['ai', 'auto'],
    });
  });

  it('adapts the value control to the base (date → a date picker)', async () => {
    const wrapper = mountDrawer();
    await flush();

    baseRadio('Date').click();
    await flush();

    expect(firstInput('Pick a date')).toBeTruthy();
    expect(firstInput('A text value')).toBeUndefined();
  });

  it('BLOCKS submit client-side when a required (non-nullable) value is empty', async () => {
    const wrapper = mountDrawer();
    await flush();

    setInput(firstInput('e.g. Brand name'), 'Budget');
    baseRadio('Number').click();
    await flush();

    // Leave the numeric value empty (null) and try to save.
    bodyButton('Create constant')!.click();
    await nextTick();

    // No submit was emitted, and the value error is surfaced.
    expect(wrapper.emitted('submit')).toBeFalsy();
    expect(document.body.textContent).toContain('A value is required for this type.');
  });
});
