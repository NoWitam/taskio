// @vitest-environment happy-dom
// ariaInvalidDelegation.spec.ts — the PROPERTY that a field rejected by the server
// is announced ON THE CONTROL, not only in the message underneath it.
//
// This pins the contract of `ariaInvalid` across the whole `next` control family:
//
//     const invalid = computed(() => props.ariaInvalid ?? field?.invalid.value ?? false);
//
//   * an EXPLICIT prop wins (either way — `true` forces the error skin standalone,
//     `false` opts a control out of its FormField's verdict),
//   * ABSENCE defers to the surrounding FormField.
//
// Absence only means "absent" while the prop opts out of Vue's boolean casting via
// `ariaInvalid: undefined` in `withDefaults`. Without it, Vue turns an absent
// `boolean` prop into `false`, `false ?? x` is `false`, and the `field` branch is
// dead: a FormField carrying an error hands its control NOTHING — no `aria-invalid`
// for assistive technology, no error line on the FieldShell, and `focusFirstError()`
// helpers that look for `[aria-invalid="true"]` find nothing to focus. That was a
// live, app-wide defect.
//
// The SWEEP below matters more than any single control: every control in the family,
// plus the wrappers that forward the prop, must behave IDENTICALLY. A divergence
// between them is what nobody would notice.
//
// AND THE SWEEP GUARDS ITS OWN COVERAGE BY ENUMERATING `ui/forms/`, not by counting
// itself. A hand-written count ("18 controls + 7 wrappers") only proves that whoever
// last edited the list also edited the number — a new control shipped without a line
// here passes in silence, which is the exact failure this file exists to prevent one
// level down. So the directory is the roll call: every `.vue` in `ui/forms/` is either
// in the sweep or on EXEMPT with a reason, and EXEMPT is itself checked against the
// component's source so nothing that actually takes `ariaInvalid` can be waved through.
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h, nextTick, type Component } from 'vue';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';

import FormField from '../FormField.vue';
import TextInput from '../TextInput.vue';
import Textarea from '../Textarea.vue';
import NumberInput from '../NumberInput.vue';
import Select from '../Select.vue';
import Checkbox from '../Checkbox.vue';
import RadioGroup from '../RadioGroup.vue';
import Radio from '../Radio.vue';
import Slider from '../Slider.vue';
import Switch from '../Switch.vue';
import ColorInput from '../ColorInput.vue';
import IconInput from '../IconInput.vue';
import PillGroupInput from '../PillGroupInput.vue';
import DatePicker from '../DatePicker.vue';
import DateTimePicker from '../DateTimePicker.vue';
import DateRangePicker from '../DateRangePicker.vue';
import DateRangeFilter from '../DateRangeFilter.vue';
import MonthPicker from '../MonthPicker.vue';
import TimePicker from '../TimePicker.vue';
import MarkdownEditor from '../../editor/MarkdownEditor.vue';
import FormSelect from '../FormSelect.vue';
import PipelineSelect from '../PipelineSelect.vue';
import LabelSelect from '../LabelSelect.vue';
import UserSelect from '../UserSelect.vue';
import TemplateSelect from '../TemplateSelect.vue';
import BotSelect from '../BotSelect.vue';
import KnowledgeBaseSelect from '../KnowledgeBaseSelect.vue';

/** Async loader stub for the data-backed wrappers — no HTTP, no api singleton. */
const fetchOptions = async () => ({ options: [], nextCursor: null });

type Case = { name: string; component: Component; props?: Record<string, unknown>; slot?: () => unknown };

/**
 * The controls that resolve their own invalid state from the FormField.
 */
const CONTROLS: Case[] = [
  { name: 'TextInput', component: TextInput },
  { name: 'Textarea', component: Textarea },
  { name: 'NumberInput', component: NumberInput },
  { name: 'Select', component: Select, props: { options: [{ value: 'a', label: 'A' }] } },
  { name: 'Checkbox', component: Checkbox, props: { label: 'Accept' } },
  {
    name: 'RadioGroup',
    component: RadioGroup,
    slot: () => h(Radio, { value: 'a', label: 'A' }),
  },
  { name: 'Slider', component: Slider, props: { ariaLabel: 'Amount' } },
  { name: 'Switch', component: Switch, props: { label: 'Notify me' } },
  { name: 'ColorInput', component: ColorInput },
  { name: 'IconInput', component: IconInput },
  { name: 'PillGroupInput', component: PillGroupInput },
  { name: 'DatePicker', component: DatePicker },
  { name: 'DateTimePicker', component: DateTimePicker },
  { name: 'DateRangePicker', component: DateRangePicker },
  { name: 'DateRangeFilter', component: DateRangeFilter },
  { name: 'MonthPicker', component: MonthPicker },
  { name: 'TimePicker', component: TimePicker },
  { name: 'MarkdownEditor', component: MarkdownEditor },
];

/**
 * The wrappers do NOT resolve the state themselves — they FORWARD `ariaInvalid` to
 * Select. They are in this sweep because forwarding is exactly where the defect
 * hides: a wrapper whose own prop is boolean-cast hands Select a hard `false` and
 * the delegation dies one level above the control the user sees.
 */
const WRAPPERS: Case[] = [
  { name: 'FormSelect', component: FormSelect, props: { fetchOptions } },
  { name: 'PipelineSelect', component: PipelineSelect, props: { fetchOptions } },
  { name: 'LabelSelect', component: LabelSelect, props: { fetchOptions } },
  { name: 'UserSelect', component: UserSelect, props: { fetchOptions } },
  { name: 'TemplateSelect', component: TemplateSelect, props: { fetchOptions } },
  { name: 'BotSelect', component: BotSelect, props: { fetchOptions } },
  { name: 'KnowledgeBaseSelect', component: KnowledgeBaseSelect, props: { fetchOptions } },
];

const ALL = [...CONTROLS, ...WRAPPERS];

// ── The roll call ────────────────────────────────────────────────────────────────────

/**
 * Members of the sweep that do NOT live in `ui/forms/`, so the directory cannot vouch
 * for them. Exactly one today: the editor's textbox is the ProseMirror surface.
 */
const OUTSIDE_UI_FORMS = ['MarkdownEditor'];

/**
 * The `ui/forms/*.vue` files that are NOT controls, each with the reason it is not.
 *
 * A name may only be here if the component takes NO `ariaInvalid` — asserted below
 * against its own source, so "exempt" can never become a place to park a control
 * somebody did not want to wire up.
 */
const EXEMPT: Record<string, string> = {
  'FormField.vue': 'the PROVIDER of the verdict — it is what the controls delegate TO',
  'FieldShell.vue': 'presentation only: it paints the state a control hands it',
  'FieldPopover.vue': 'positioning/overlay plumbing, holds no value and no verdict',
  'ChipOverflow.vue': 'a display helper for a list of chips, not an input',
  'FileDropzone.vue': 'files are validated by the surrounding surface, not by a field verdict',
  'Radio.vue': 'one option INSIDE RadioGroup — the group carries the verdict for all of them',
  'SegmentedControl.vue': 'a mode switch: every option is valid, so there is no invalid state',
};

/** Every component file in `ui/forms/`, and its raw source, straight off disk. */
const FORM_FILES = import.meta.glob('../*.vue', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>;

/** `../TextInput.vue` → `TextInput.vue`. */
function baseName(path: string): string {
  return path.slice(path.lastIndexOf('/') + 1);
}

/** Mount a case inside a FormField, optionally with extra props on the control. */
function inField(c: Case, error: string | undefined, extra: Record<string, unknown> = {}) {
  const host = defineComponent({
    render: () =>
      h(FormField, { label: 'Field', error }, () => [
        h(c.component, { ...(c.props ?? {}), ...extra }, c.slot ? { default: c.slot } : undefined),
      ]),
  });
  return mount(host, { attachTo: document.body });
}

/**
 * Let the mount finish. Most controls render their `aria-invalid` synchronously;
 * MarkdownEditor's textbox is the ProseMirror surface, which Tiptap creates on
 * mount, so the sweep waits before reading the DOM.
 */
async function settle(): Promise<void> {
  await flushPromises();
  await nextTick();
}

describe('aria-invalid delegation — representative control (TextInput)', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('a field the server rejected is announced ON the control, not only under it', () => {
    const wrapper = inField({ name: 'TextInput', component: TextInput }, 'Give the event a title, please.');

    // The message is there…
    expect(wrapper.text()).toContain('Give the event a title, please.');
    // …AND the control itself carries the state, which is what assistive technology
    // reads and what `focusFirstError()` helpers query for.
    const input = wrapper.find('input');
    expect(input.attributes('aria-invalid')).toBe('true');
    expect(input.attributes('aria-describedby')).toBeTruthy();

    wrapper.unmount();
  });

  it('renders the error state visually on the field shell, not just red text', () => {
    const wrapper = inField({ name: 'TextInput', component: TextInput }, 'Boom');

    const shell = wrapper.find('.next-field-shell');
    expect(shell.classes()).toContain('state-error');
    expect(shell.classes()).toContain('has-ring');

    wrapper.unmount();
  });

  it('stays clean while the FormField has no verdict', () => {
    const wrapper = inField({ name: 'TextInput', component: TextInput }, undefined);

    expect(wrapper.find('input').attributes('aria-invalid')).toBeUndefined();
    expect(wrapper.find('.next-field-shell').classes()).not.toContain('state-error');

    wrapper.unmount();
  });

  it('an explicit prop still wins over the FormField — both ways', () => {
    // Explicit `true`, standalone, with no FormField at all.
    const standalone = mount(TextInput, { props: { ariaInvalid: true } });
    expect(standalone.find('input').attributes('aria-invalid')).toBe('true');
    expect(standalone.find('.next-field-shell').classes()).toContain('state-error');
    standalone.unmount();

    // Explicit `false` opts this control out of its FormField's verdict (e.g. one
    // control of several under a single label, where only the other is at fault).
    const optedOut = inField({ name: 'TextInput', component: TextInput }, 'Boom', {
      ariaInvalid: false,
    });
    expect(optedOut.find('input').attributes('aria-invalid')).toBeUndefined();
    expect(optedOut.find('.next-field-shell').classes()).not.toContain('state-error');
    // The FormField still says what is wrong — only the control skin is opted out.
    expect(optedOut.text()).toContain('Boom');
    optedOut.unmount();
  });

  it('the bare attribute shorthand still reads as true', () => {
    // `<TextInput aria-invalid />` — opting out of the boolean CAST must not opt out
    // of the boolean SHORTHAND.
    const wrapper = mount(TextInput, { attrs: { 'aria-invalid': '' } });
    expect(wrapper.find('input').attributes('aria-invalid')).toBe('true');
    wrapper.unmount();
  });
});

describe('aria-invalid delegation — every control behaves the same', () => {
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  /**
   * THE ROLL CALL. Read the directory and make every file account for itself.
   *
   * The failure message names the file, because the only useful thing to say to whoever
   * just added a control is which one it is and which of the two lists it belongs on.
   */
  it('covers every control in `ui/forms` — the directory is the list, not a counter', () => {
    const onDisk = Object.keys(FORM_FILES).map(baseName).sort();
    const swept = ALL.map((c) => `${c.name}.vue`).filter((f) => !OUTSIDE_UI_FORMS.includes(f.replace('.vue', '')));

    const unaccounted = onDisk.filter((f) => !swept.includes(f) && !(f in EXEMPT));
    expect(
      unaccounted,
      `these ui/forms components are in neither the sweep nor EXEMPT — add each to CONTROLS/WRAPPERS ` +
        `if it takes an \`ariaInvalid\`, or to EXEMPT with the reason it does not: ${unaccounted.join(', ')}`,
    ).toEqual([]);

    // The other direction: a name in the sweep (or on EXEMPT) that no longer exists is a
    // stale entry, and a stale entry is how a list starts drifting from the thing it lists.
    const missing = [...swept, ...Object.keys(EXEMPT)].filter((f) => !onDisk.includes(f));
    expect(missing, `these names are listed here but no longer exist in ui/forms: ${missing.join(', ')}`).toEqual([]);

    // Nothing may be on both lists.
    expect(swept.filter((f) => f in EXEMPT)).toEqual([]);
  });

  /**
   * EXEMPT is not a promise, it is a claim about the source — so it is checked against it.
   *
   * Without this, the roll call above is satisfiable by writing a control's name on EXEMPT,
   * which is a shorter edit than wiring it into the sweep and would look just as green.
   */
  it('lets nothing that takes `ariaInvalid` sit on the exempt list', () => {
    const smuggled = Object.keys(EXEMPT).filter((file) => {
      const source = FORM_FILES[`../${file}`] ?? '';
      return source.includes('ariaInvalid');
    });

    expect(
      smuggled,
      `these are exempt but their source mentions \`ariaInvalid\` — they are controls and belong ` +
        `in the sweep: ${smuggled.join(', ')}`,
    ).toEqual([]);
  });

  for (const c of ALL) {
    it(`${c.name} takes the error state from its FormField`, async () => {
      const wrapper = inField(c, 'Boom');
      await settle();
      expect(
        wrapper.element.querySelector('[aria-invalid="true"]'),
        `${c.name} renders no aria-invalid="true" inside a FormField carrying an error`,
      ).not.toBeNull();
      wrapper.unmount();
    });

    it(`${c.name} stays clean when the FormField has no verdict`, async () => {
      const wrapper = inField(c, undefined);
      await settle();
      expect(
        wrapper.element.querySelector('[aria-invalid="true"]'),
        `${c.name} claims to be invalid with no error on the field`,
      ).toBeNull();
      wrapper.unmount();
    });

    it(`${c.name} lets an explicit prop override the FormField`, async () => {
      const forced = mount(
        defineComponent({
          render: () =>
            h(FormField, { label: 'Field' }, () => [
              h(c.component, { ...(c.props ?? {}), ariaInvalid: true }, c.slot ? { default: c.slot } : undefined),
            ]),
        }),
        { attachTo: document.body },
      );
      await settle();
      expect(
        forced.element.querySelector('[aria-invalid="true"]'),
        `${c.name} ignores an explicit ariaInvalid=true`,
      ).not.toBeNull();
      forced.unmount();

      const suppressed = inField(c, 'Boom', { ariaInvalid: false });
      await settle();
      expect(
        suppressed.element.querySelector('[aria-invalid="true"]'),
        `${c.name} ignores an explicit ariaInvalid=false`,
      ).toBeNull();
      suppressed.unmount();
    });
  }
});
