// @vitest-environment happy-dom
// WorkflowStepCard.generateContent.spec — the `generate_content` step editor (R2 sub-stage 5).
//
// Behaviour-focused, and deliberately concentrated on the THREE things that can silently
// cost the author a failed run:
//   1. the slot ROWS are rendered from the chosen template's DECLARED descriptors, with the
//      required marker on by DEFAULT (a descriptor has no `required` key — required is
//      `nullable !== true`),
//   2. a COMPOSITE slot (base `object`, or `array:true` with base `file`) is refused BEFORE
//      save — its input is disabled, and a REQUIRED one condemns the whole template,
//   3. template DRIFT is WARNED about and never silently resets the author's mapping.
// Plus the save gate the drawer consults (`type-error`) and the exact config writes.
//
// The template is fetched through the templates store, so a real Pinia is installed and the
// store action is stubbed — no HTTP. FolderPickerPanel (which fetches Disk folders on mount)
// and MarkdownEditor are stubbed for the same reason.
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { h, nextTick, reactive } from 'vue';
import WorkflowStepCard from '../WorkflowStepCard.vue';
import ValueOrVariableField from '../ValueOrVariableField.vue';
import { buildStepConfig, emptyStepConfig, type StepDraft } from '../workflowEditorModel';
import { useTemplatesStore } from '../../../app/stores/templates';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { CatalogVariableDescriptor, WorkflowCatalog } from '../types';
import type { Template, TemplateSlot } from '../../generator/types';

const CATALOG: WorkflowCatalog = {
  variables: [
    { source: 'trigger', path: 'fields.topic', name: 'Topic', type: 'text' },
    { source: 'trigger', path: 'fields.file', name: 'Attachment', type: 'file' },
  ],
  fields: [],
  operations: [{ id: 'text_uppercase', input: 'text', output: 'text', args: [] }],
};

/** A slot descriptor with the shape the generator authors ({base, nullable, array, …}). */
function descriptor(overrides: Partial<CatalogVariableDescriptor> = {}): CatalogVariableDescriptor {
  return { base: 'text', nullable: false, array: false, ...overrides };
}

function slot(name: string, overrides: Partial<CatalogVariableDescriptor> = {}, description?: string): TemplateSlot {
  return { name, description, descriptor: descriptor(overrides) };
}

function template(slots: TemplateSlot[], overrides: Partial<Template> = {}): Template {
  return {
    id: 'tpl-1',
    name: 'Launch post',
    description: null,
    content_type: 'post_with_image',
    slots,
    content: {},
    is_owner: true,
    can_be_edited: true,
    can_be_deleted: true,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

/** A MarkdownEditor stub (Tiptap internals are irrelevant here). */
const MarkdownEditorStub = {
  name: 'MarkdownEditor',
  props: ['modelValue'],
  setup: () => () => h('textarea', { class: 'md-stub' }),
};

/** FolderPickerPanel fetches a Disk level on mount — stub it to a plain marker. */
const FolderPickerStub = {
  name: 'FolderPickerPanel',
  props: ['modelValue', 'excludeId'],
  emits: ['update:modelValue'],
  setup: (_p: unknown, { emit }: { emit: (e: string, v: unknown) => void }) => () =>
    h('button', { class: 'folder-stub', onClick: () => emit('update:modelValue', 'fld-9') }, 'folder'),
};

function gcStep(config: Record<string, unknown> = {}): StepDraft {
  return reactive({
    uid: 'g1',
    type: 'generate_content',
    key: 'content',
    config: { ...emptyStepConfig('generate_content'), ...config },
  });
}

/** Mount the card with a stubbed `fetchTemplate`; returns the wrapper + the spy. */
function mountCard(step: StepDraft, resolved: Template | null, overrides: Record<string, unknown> = {}) {
  const store = useTemplatesStore();
  const fetchTemplate = vi
    .spyOn(store, 'fetchTemplate')
    .mockImplementation(async () => (resolved ? resolved : Promise.reject(new Error('gone'))));

  const wrapper = mount(WorkflowStepCard, {
    attachTo: document.body,
    global: { stubs: { MarkdownEditor: MarkdownEditorStub, FolderPickerPanel: FolderPickerStub } },
    props: {
      step,
      index: 0,
      total: 1,
      catalog: CATALOG,
      triggerType: null,
      steps: [step],
      position: 0,
      errors: {},
      duplicateKey: false,
      expanded: true,
      ...overrides,
    },
  });
  return { wrapper, fetchTemplate };
}

/**
 * The card's LATEST `type-error` emit — the boolean the list editor forwards to the
 * drawer's Save gate ("this card is not saveable yet").
 */
function lastTypeError(wrapper: { emitted: (name: string) => unknown[][] | undefined }): unknown {
  const events = wrapper.emitted('type-error');
  return events && events.length ? events[events.length - 1] : undefined;
}

/** Let the mocked fetch settle + the resulting render flush. */
async function settle(): Promise<void> {
  await nextTick();
  await Promise.resolve();
  await nextTick();
  await nextTick();
}

describe('WorkflowStepCard — generate_content: slot rows from the template descriptors', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    installBrowserMocks();
  });
  afterEach(() => {
    restoreBrowserMocks();
    vi.restoreAllMocks();
  });

  it('fetches the chosen template and renders ONE row per declared slot', async () => {
    const step = gcStep({ template_id: 'tpl-1' });
    const { wrapper, fetchTemplate } = mountCard(
      step,
      template([slot('topic', {}, 'What the post is about'), slot('tone', { base: 'enum', nullable: true })]),
    );
    await settle();

    expect(fetchTemplate).toHaveBeenCalledWith('tpl-1');
    // One labelled row per slot, with the authored description echoed.
    expect(wrapper.text()).toContain('topic');
    expect(wrapper.text()).toContain('What the post is about');
    expect(wrapper.text()).toContain('tone');
    wrapper.unmount();
  });

  it('marks EVERY slot required unless its descriptor is nullable', async () => {
    const step = gcStep({ template_id: 'tpl-1' });
    const { wrapper } = mountCard(step, template([slot('topic'), slot('tone', { nullable: true })]));
    await settle();

    // `required` is the DEFAULT (no `required` key exists), so both markers are explicit.
    expect(wrapper.text()).toContain('Required');
    expect(wrapper.text()).toContain('Optional');
    wrapper.unmount();
  });

  it('writes a picked variable into config.slots.<name> and UNSETS the key when cleared', async () => {
    const step = gcStep({ template_id: 'tpl-1' });
    const { wrapper } = mountCard(step, template([slot('topic')]));
    await settle();

    const field = wrapper.findAllComponents(ValueOrVariableField)[0];
    await field.get('button[aria-label="Variable"]').trigger('click');
    await nextTick();
    await field.get('[role="combobox"]').trigger('click');
    await nextTick();
    await Promise.resolve();
    await nextTick();

    const options = document.body.querySelectorAll<HTMLElement>('[role="treeitem"]');
    expect(options.length).toBeGreaterThan(0);
    options[0].click();
    await nextTick();

    expect((step.config.slots as Record<string, unknown>).topic).toEqual({
      kind: 'variable',
      ref: { source: 'trigger', path: 'fields.topic', type: 'text' },
    });

    // Clearing the field back to an empty literal leaves nothing usable on the wire.
    field.vm.$emit('update:modelValue', null);
    await nextTick();
    expect((step.config.slots as Record<string, unknown>).topic).toBeUndefined();
    wrapper.unmount();
  });

  it('picking a DIFFERENT template clears the (now meaningless) slot mapping', async () => {
    const step = gcStep({
      template_id: 'tpl-1',
      slots: { topic: { kind: 'literal', value: 'old' } },
    });
    const { wrapper } = mountCard(step, template([slot('topic')]));
    await settle();

    wrapper.findComponent({ name: 'TemplateSelect' }).vm.$emit('update:modelValue', 'tpl-2');
    await nextTick();

    expect(step.config.template_id).toBe('tpl-2');
    expect(step.config.slots).toEqual({});
    wrapper.unmount();
  });

  it('HYDRATION never touches the saved mapping (only an explicit re-pick does)', async () => {
    const saved = { topic: { kind: 'literal', value: 'kept' } };
    const step = gcStep({ template_id: 'tpl-1', slots: saved });
    const { wrapper } = mountCard(step, template([slot('topic')]));
    await settle();

    expect(step.config.slots).toEqual(saved);
    wrapper.unmount();
  });
});

describe('WorkflowStepCard — generate_content: COMPOSITE slots', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    installBrowserMocks();
  });
  afterEach(() => {
    restoreBrowserMocks();
    vi.restoreAllMocks();
  });

  it('renders an OBJECT slot with a DISABLED input + an explanation (never hidden)', async () => {
    const step = gcStep({ template_id: 'tpl-1' });
    const { wrapper } = mountCard(step, template([slot('brief', { base: 'object', nullable: true })]));
    await settle();

    // The slot is still listed…
    expect(wrapper.text()).toContain('brief');
    // …with an inert control carrying the refusal as its placeholder…
    const disabled = wrapper.findAll('input[disabled]');
    expect(disabled.length).toBeGreaterThan(0);
    expect(disabled.some((i) => i.attributes('placeholder') === 'Can’t be supplied by a workflow')).toBe(true);
    // …and a plain-language reason.
    expect(wrapper.text()).toContain('structured value');
    wrapper.unmount();
  });

  it('treats an ARRAY-OF-FILE slot as composite too (a SCALAR file slot is fine)', async () => {
    const step = gcStep({ template_id: 'tpl-1' });
    const { wrapper } = mountCard(
      step,
      template([slot('gallery', { base: 'file', array: true, nullable: true }), slot('cover', { base: 'file' })]),
    );
    await settle();

    // The scalar `cover` slot keeps a live value-or-variable control; only `gallery` is inert.
    expect(wrapper.findAllComponents(ValueOrVariableField).length).toBe(1);
    expect(wrapper.findAll('input[disabled]').length).toBeGreaterThan(0);
    wrapper.unmount();
  });

  it('a REQUIRED composite warns that the TEMPLATE cannot be driven by a workflow, and blocks save', async () => {
    const step = gcStep({ template_id: 'tpl-1' });
    const { wrapper } = mountCard(step, template([slot('brief', { base: 'object' })]));
    await settle();

    expect(wrapper.text()).toContain('This template can’t be run from a workflow');
    expect(wrapper.text()).toContain('brief');
    // The card reports itself unsaveable to the list editor → drawer Save gate.
    expect(lastTypeError(wrapper)).toEqual([true]);
    // …and the row is flagged.
    expect(wrapper.text()).toContain('Has errors');
    wrapper.unmount();
  });

  it('a MAPPED nullable composite is flagged with a one-click fix (the server rejects it)', async () => {
    const step = gcStep({
      template_id: 'tpl-1',
      slots: { brief: { kind: 'literal', value: 'stale' } },
    });
    const { wrapper } = mountCard(step, template([slot('brief', { base: 'object', nullable: true })]));
    await settle();

    expect(wrapper.text()).toContain('currently mapped');
    expect(lastTypeError(wrapper)).toEqual([true]);

    const clear = wrapper.findAll('button').find((b) => b.text().includes('Clear this input'));
    expect(clear).toBeTruthy();
    await clear!.trigger('click');
    await nextTick();
    expect(step.config.slots).toEqual({});
    wrapper.unmount();
  });
});

describe('WorkflowStepCard — generate_content: template DRIFT', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    installBrowserMocks();
  });
  afterEach(() => {
    restoreBrowserMocks();
    vi.restoreAllMocks();
  });

  it('WARNS (never resets) when a mapped slot is no longer declared', async () => {
    const step = gcStep({
      template_id: 'tpl-1',
      slots: { topic: { kind: 'literal', value: 'kept' }, removed_slot: { kind: 'literal', value: 'stale' } },
    });
    const { wrapper } = mountCard(step, template([slot('topic')]));
    await settle();

    expect(wrapper.text()).toContain('This template changed after the step was saved');
    expect(wrapper.text()).toContain('removed_slot');
    // The author's mapping is UNTOUCHED until they act.
    expect(Object.keys(step.config.slots as Record<string, unknown>)).toEqual(['topic', 'removed_slot']);
    // Drift is a server 422, so it blocks Save.
    expect(lastTypeError(wrapper)).toEqual([true]);
    wrapper.unmount();
  });

  it('clearing the removed inputs is an EXPLICIT author action', async () => {
    const step = gcStep({
      template_id: 'tpl-1',
      slots: { topic: { kind: 'literal', value: 'kept' }, removed_slot: { kind: 'literal', value: 'stale' } },
    });
    const { wrapper } = mountCard(step, template([slot('topic')]));
    await settle();

    const drop = wrapper.findAll('button').find((b) => b.text().includes('Clear the removed inputs'));
    expect(drop).toBeTruthy();
    await drop!.trigger('click');
    await nextTick();

    expect(step.config.slots).toEqual({ topic: { kind: 'literal', value: 'kept' } });
    wrapper.unmount();
  });

  it('a PARTIALLY-MAPPED new step names the UNFILLED inputs — it is NOT drift', async () => {
    // The ordinary authoring path: pick a recipe with TWO required inputs, fill the first.
    // Nothing about the template changed, so the drift alert must stay silent and the
    // ACCURATE "still needs a value" message must not be suppressed by it.
    const step = gcStep({ template_id: 'tpl-1' });
    const { wrapper } = mountCard(step, template([slot('topic'), slot('audience')]));
    await settle();

    wrapper
      .findAllComponents(ValueOrVariableField)[0]
      .vm.$emit('update:modelValue', { kind: 'literal', value: 'Launch' });
    await nextTick();

    expect(step.config.slots).toEqual({ topic: { kind: 'literal', value: 'Launch' } });
    expect(wrapper.text()).not.toContain('This template changed after the step was saved');
    expect(wrapper.text()).toContain('These required inputs still need a value: audience.');
    wrapper.unmount();
  });

  it('names a NEWLY-REQUIRED slot the template gained after authoring', async () => {
    const step = gcStep({
      template_id: 'tpl-1',
      slots: { topic: { kind: 'literal', value: 'kept' } },
    });
    const { wrapper } = mountCard(step, template([slot('topic'), slot('audience')]));
    await settle();

    expect(wrapper.text()).toContain('New required inputs');
    expect(wrapper.text()).toContain('audience');
    wrapper.unmount();
  });

  it('a BRAND-NEW step reports no drift — just the unfilled-required warning', async () => {
    const step = gcStep({ template_id: 'tpl-1' });
    const { wrapper } = mountCard(step, template([slot('topic')]));
    await settle();

    expect(wrapper.text()).not.toContain('This template changed after the step was saved');
    expect(wrapper.text()).toContain('These required inputs still need a value');
    // Unfinished blocks Save…
    expect(lastTypeError(wrapper)).toEqual([true]);
    // …but does NOT paint a fresh card red.
    expect(wrapper.text()).not.toContain('Has errors');
    wrapper.unmount();
  });
});

describe('WorkflowStepCard — generate_content: scale note, outputs, folder, save gate', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    installBrowserMocks();
  });
  afterEach(() => {
    restoreBrowserMocks();
    vi.restoreAllMocks();
  });

  it('shows the per-content-type SCALE note (video_script fans out to many images)', async () => {
    const step = gcStep({ template_id: 'tpl-1' });
    const { wrapper } = mountCard(step, template([], { content_type: 'video_script' }));
    await settle();

    expect(wrapper.text()).toContain('up to 8 images in a single run');
    wrapper.unmount();
  });

  it('labels the `status` output honestly (always "ready" — not a branch condition)', async () => {
    const step = gcStep({ template_id: 'tpl-1' });
    const { wrapper } = mountCard(step, template([]));
    await settle();

    expect(wrapper.text()).toContain('Available to later steps');
    expect(wrapper.text()).toContain('steps.content.image_file_ids');
    expect(wrapper.text()).toContain('always “ready” today');
    wrapper.unmount();
  });

  it('the Disk folder picker writes folder_id (null = the Disk root)', async () => {
    const step = gcStep({ template_id: 'tpl-1' });
    const { wrapper } = mountCard(step, template([]));
    await settle();

    expect(wrapper.text()).toContain('Destination: the Disk root.');
    await wrapper.get('.folder-stub').trigger('click');
    await nextTick();
    expect(step.config.folder_id).toBe('fld-9');
    wrapper.unmount();
  });

  it('a fully-mapped template with no composites does NOT block save', async () => {
    const step = gcStep({
      template_id: 'tpl-1',
      slots: { topic: { kind: 'literal', value: 'Launch' } },
    });
    const { wrapper } = mountCard(step, template([slot('topic'), slot('tone', { nullable: true })]));
    await settle();

    expect(lastTypeError(wrapper)).toEqual([false]);
    expect(wrapper.text()).not.toContain('Has errors');
    wrapper.unmount();
  });

  it('a FAILED template read surfaces an error + retry and does NOT block save (the server decides)', async () => {
    const step = gcStep({ template_id: 'tpl-1' });
    const { wrapper } = mountCard(step, null);
    await settle();

    expect(wrapper.text()).toContain('Couldn’t load this template’s inputs');
    expect(lastTypeError(wrapper)).toEqual([false]);
    wrapper.unmount();
  });

  it('the COLLAPSED summary names the template + its input count', async () => {
    const step = gcStep({ template_id: 'tpl-1' });
    const { wrapper } = mountCard(step, template([slot('topic'), slot('tone')]), { expanded: false });
    await settle();

    expect(wrapper.text()).toContain('Launch post · 2 inputs');
    wrapper.unmount();
  });

  it('BLOCKS save while the template is still LOADING (Save must wait, not 422)', async () => {
    // A fast Save used to slip through the whole per-slot gate: `template` is null until the
    // fetch resolves, so every check was skipped and the server answered with a 422.
    const store = useTemplatesStore();
    let release: (tpl: Template) => void = () => {};
    vi.spyOn(store, 'fetchTemplate').mockImplementation(
      () => new Promise<Template>((resolve) => { release = resolve; }),
    );

    const step = gcStep({ template_id: 'tpl-1', slots: { topic: { kind: 'literal', value: 'Launch' } } });
    const wrapper = mount(WorkflowStepCard, {
      attachTo: document.body,
      global: { stubs: { MarkdownEditor: MarkdownEditorStub, FolderPickerPanel: FolderPickerStub } },
      props: {
        step,
        index: 0,
        total: 1,
        catalog: CATALOG,
        triggerType: null,
        steps: [step],
        position: 0,
        errors: {},
        duplicateKey: false,
        expanded: true,
      },
    });
    await nextTick();

    // Still in flight → not saveable yet.
    expect(lastTypeError(wrapper)).toEqual([true]);

    release(template([slot('topic')]));
    await settle();

    // Resolved and fully mapped → Save opens.
    expect(lastTypeError(wrapper)).toEqual([false]);
    wrapper.unmount();
  });
});

describe('WorkflowStepCard — generate_content: ARRAYED scalar slots', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    installBrowserMocks();
  });
  afterEach(() => {
    restoreBrowserMocks();
    vi.restoreAllMocks();
  });

  it('accepts a plain TEXT variable for an `array:true, base:text` slot (the runtime wraps it)', async () => {
    // No operation produces `multi` from a scalar, so demanding a terminal `multi` here was a
    // dead end: the picker offered the variable and the gate then refused it with no way out.
    // The server accepts it and VariableResolver::coerce wraps a scalar into a one-element list.
    const step = gcStep({
      template_id: 'tpl-1',
      slots: { tags: { kind: 'variable', ref: { source: 'trigger', path: 'fields.topic', type: 'text' } } },
    });
    const { wrapper } = mountCard(step, template([slot('tags', { base: 'text', array: true })]));
    await settle();

    expect(lastTypeError(wrapper)).toEqual([false]);
    expect(wrapper.text()).not.toContain('Has errors');
    // …and the wire shape is untouched by the relaxation.
    expect(buildStepConfig(step).slots).toEqual({
      tags: { kind: 'variable', ref: { source: 'trigger', path: 'fields.topic', type: 'text' } },
    });
    wrapper.unmount();
  });

  // The SERVER half of this is pinned by
  // WorkflowGenerateContentStepTest::test_an_arrayed_slot_accepts_a_pipeline_that_ends_in_its_element_type
  // (StoreWorkflowRequest::slotPipelineTerminals). Both sides accept the pair {multi, element}, so a
  // tightening on either side now breaks a test instead of stranding the author at a 422.
  it('offers BOTH terminals for an arrayed slot, so a non-empty element pipeline still saves', async () => {
    const step = gcStep({
      template_id: 'tpl-1',
      slots: {
        tags: {
          kind: 'variable',
          ref: { source: 'trigger', path: 'fields.topic', type: 'text' },
          pipeline: [{ op: 'text_uppercase', args: {} }],
        },
      },
    });
    const { wrapper } = mountCard(step, template([slot('tags', { base: 'text', array: true })]));
    await settle();

    // The slot's own `multi` AND its plain element type — exactly what the server admits.
    expect(wrapper.findComponent(ValueOrVariableField).props('resultTypes')).toEqual(['multi', 'text']);

    // A text-producing pipeline is therefore a valid mapping: Save stays open…
    expect(lastTypeError(wrapper)).toEqual([false]);
    // …and the pipeline reaches the wire untouched.
    expect(buildStepConfig(step).slots).toEqual({
      tags: {
        kind: 'variable',
        ref: { source: 'trigger', path: 'fields.topic', type: 'text' },
        pipeline: [{ op: 'text_uppercase', args: {} }],
      },
    });
    wrapper.unmount();
  });

  it('carries FALSY-but-real slot values (false / 0 / []) from the ROW to the wire', async () => {
    const step = gcStep({ template_id: 'tpl-1' });
    const { wrapper } = mountCard(
      step,
      template([
        slot('flag', { base: 'boolean' }),
        slot('count', { base: 'number' }),
        slot('tags', { base: 'text', array: true }),
      ]),
    );
    await settle();

    const fields = wrapper.findAllComponents(ValueOrVariableField);
    fields[0].vm.$emit('update:modelValue', { kind: 'literal', value: false });
    fields[1].vm.$emit('update:modelValue', { kind: 'literal', value: 0 });
    fields[2].vm.$emit('update:modelValue', { kind: 'literal', value: [] });
    await nextTick();

    expect(buildStepConfig(step).slots).toEqual({
      flag: { kind: 'literal', value: false },
      count: { kind: 'literal', value: 0 },
      tags: { kind: 'literal', value: [] },
    });
    wrapper.unmount();
  });

  it('labels an arrayed TEXT slot from its OWN base, not the engine `multi` type', async () => {
    const step = gcStep({ template_id: 'tpl-1' });
    const { wrapper } = mountCard(step, template([slot('tags', { base: 'text', array: true, nullable: true })]));
    await settle();

    expect(wrapper.text()).toContain('Text (list)');
    expect(wrapper.text()).not.toContain('Multi-choice (list)');
    wrapper.unmount();
  });
});

describe('WorkflowStepCard — generate_content: FE/server composite parity', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    installBrowserMocks();
  });
  afterEach(() => {
    restoreBrowserMocks();
    vi.restoreAllMocks();
  });

  // The documented `StoreWorkflowRequest::isUnsuppliableSlot` rules, as a table: base `object`
  // (ANY shape) or `array:true` + base `file` are refused; every scalar (incl. a scalar `file`)
  // and every arrayed non-file scalar is supplied normally. A backend change now drifts LOUDLY.
  const PARITY: Array<{ label: string; descriptor: Partial<CatalogVariableDescriptor>; refused: boolean }> = [
    { label: 'object (scalar)', descriptor: { base: 'object' }, refused: true },
    { label: 'object (array)', descriptor: { base: 'object', array: true }, refused: true },
    { label: 'file (array)', descriptor: { base: 'file', array: true }, refused: true },
    { label: 'file (scalar)', descriptor: { base: 'file' }, refused: false },
    { label: 'text (scalar)', descriptor: { base: 'text' }, refused: false },
    { label: 'text (array)', descriptor: { base: 'text', array: true }, refused: false },
    { label: 'enum (array)', descriptor: { base: 'enum', array: true }, refused: false },
    { label: 'number (array)', descriptor: { base: 'number', array: true }, refused: false },
  ];

  it.each(PARITY)('$label → refused: $refused', async ({ descriptor: overrides, refused }) => {
    const step = gcStep({ template_id: 'tpl-1' });
    const { wrapper } = mountCard(step, template([slot('s', { ...overrides, nullable: true })]));
    await settle();

    // A refused slot gets an INERT row (no live control); a supported one gets exactly one.
    expect(wrapper.findAllComponents(ValueOrVariableField).length).toBe(refused ? 0 : 1);
    const inert = wrapper
      .findAll('input[disabled]')
      .some((i) => i.attributes('placeholder') === 'Can’t be supplied by a workflow');
    expect(inert).toBe(refused);
    wrapper.unmount();
  });
});
