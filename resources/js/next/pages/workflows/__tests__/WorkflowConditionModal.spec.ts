// @vitest-environment happy-dom
// WorkflowConditionModal.spec — the per-condition editor (Modal + shared pipeline).
//
// Asserts the boolean-result GATE (a boolean-terminating pipeline enables Save; a
// non-boolean one blocks it), the enum source → enum_is → sourceOption options wiring,
// the field-change RESET (confirm → the pipeline clears), and the SHARED variable TREE
// picker as the source control (expandable section groups, nullable/array markers, and
// an unchanged `{source, sourceType, pipeline}` payload). The Modal + Selects teleport to
// <body>, so we query/drive document.body directly.
import { describe, it, expect, beforeEach, afterEach, beforeAll } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale } from '../../../app/i18n';
import WorkflowConditionModal from '../WorkflowConditionModal.vue';
import { standardOperationsCatalog } from '../../../ui/editor/extensions/standardOperations';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { CatalogField, CatalogVariable, WorkflowVariableType } from '../types';
import type { DraftCondition } from '../workflowConditions';
import type { VariablePipelineStep } from '../../../ui/editor/extensions/types';

const CATALOG = standardOperationsCatalog();

const FIELDS: CatalogField[] = [
  { path: 'fields.status', field_id: 'status', label: 'Status', type: 'enum', enumOptions: ['open', 'done', 'blocked'], operators: ['is', 'is_not', 'in'] },
  { path: 'fields.level', field_id: 'level', label: 'Level', type: 'enum', enumOptions: ['low', 'high'], operators: ['is', 'is_not', 'in'] },
  // A file field: its condition is built through the SAME pipeline (file → boolean via
  // file_is_empty / file_is_not_empty), never a bespoke operator UI.
  { path: 'fields.attachment', field_id: 'attachment', label: 'Attachment', type: 'file', operators: ['filled', 'empty'] },
];

/** A form with a SECTION: its leaves ride flat as `fields.<section>.<leaf>` condition fields. */
const SECTION_FIELDS: CatalogField[] = [
  { path: 'fields.contact.email', field_id: 'contact.email', label: 'Email', type: 'text', operators: ['equals'] },
  { path: 'fields.contact.phone', field_id: 'contact.phone', label: 'Phone', type: 'text', operators: ['equals'] },
];

/**
 * The matching catalog VARIABLES — the descriptor source (`fields.x` ↔ `trigger.fields.x`): the
 * section container plus one OPTIONAL (nullable) and one required leaf.
 */
const SECTION_VARIABLES: CatalogVariable[] = [
  {
    source: 'trigger',
    path: 'trigger.fields.contact',
    name: 'Contact',
    type: 'text',
    descriptor: {
      base: 'object',
      nullable: false,
      array: false,
      fields: [{ key: 'email', label: 'Email', descriptor: { base: 'text', nullable: true, array: false } }],
    },
  },
  {
    source: 'trigger',
    path: 'trigger.fields.contact.email',
    name: 'Email',
    type: 'text',
    descriptor: { base: 'text', nullable: true, array: false },
    nullable: true,
  },
  {
    source: 'trigger',
    path: 'trigger.fields.contact.phone',
    name: 'Phone',
    type: 'text',
    descriptor: { base: 'text', nullable: false, array: false },
  },
];

function step(op: string, args: Record<string, unknown> = {}, outputType: WorkflowVariableType = 'boolean'): VariablePipelineStep {
  return { stepId: `s-${op}`, operationId: op, args: args as VariablePipelineStep['args'], outputType };
}

function seeded(pipeline: VariablePipelineStep[], source = 'fields.status'): DraftCondition {
  return { uid: 'c1', kind: 'condition', source, sourceType: 'enum', pipeline };
}

function flush() {
  return new Promise((r) => setTimeout(r, 0));
}

function mountModal(
  condition: DraftCondition | null,
  fields: CatalogField[] = FIELDS,
  variables: CatalogVariable[] = [],
  argVariables: CatalogVariable[] = [],
) {
  return mount(WorkflowConditionModal, {
    attachTo: document.body,
    props: { open: true, condition, fields, variables, catalog: CATALOG, argVariables },
  });
}

// --- Teleported DOM helpers -------------------------------------------------
function bodyButtons(): HTMLButtonElement[] {
  return Array.from(document.body.querySelectorAll('button'));
}
function saveButton(): HTMLButtonElement | undefined {
  return bodyButtons().find((b) => (b.textContent ?? '').includes('Save condition'));
}
function comboboxes(): HTMLElement[] {
  return Array.from(document.body.querySelectorAll('[role="combobox"]'));
}
function optionTexts(): string[] {
  return Array.from(document.body.querySelectorAll('[role="option"]')).map((o) => (o.textContent ?? '').trim());
}
/** Open the source TREE picker (the modal's first combobox). */
async function openSourcePicker(): Promise<void> {
  comboboxes()[0].click();
  await nextTick();
  await Promise.resolve();
  await nextTick();
}
/**
 * The VISIBLE tree rows of the teleported picker panel. Deliberately `treeitem`-only (B3):
 * this file also opens plain Selects, whose rows are `option`s (`optionTexts` above).
 */
function treeItems(): HTMLElement[] {
  return Array.from(document.body.querySelectorAll<HTMLElement>('[role="treeitem"]'));
}
/** A row's NAME (the marker glyphs carry sr-only text, so read the label span). */
function treeItemLabel(el: HTMLElement): string {
  return el.querySelector('span.truncate')?.textContent?.trim() ?? '';
}
function treeLabels(): string[] {
  return treeItems().map(treeItemLabel);
}
function treeItemByText(text: string): HTMLElement | undefined {
  return treeItems().find((el) => treeItemLabel(el) === text);
}
/** Open the picker and click the row labelled `text`. */
async function pickSource(text: string): Promise<void> {
  await openSourcePicker();
  treeItemByText(text)?.click();
  await nextTick();
}
/** The add-operation menu button whose LABEL span matches exactly. */
function addOpButton(label: string): HTMLButtonElement | undefined {
  return bodyButtons().find((b) => b.querySelector('span.truncate')?.textContent?.trim() === label);
}
/**
 * Whether the shared reference editor's terminal-status strip reads SATISFIED. Since B7 the modal's
 * status is the shared "Returns: <type>" strip (data-type-satisfied), not a bespoke "ready" line.
 */
function terminalSatisfied(): boolean {
  return document.body.querySelector('[data-type-satisfied]')?.getAttribute('data-type-satisfied') === 'true';
}

describe('WorkflowConditionModal', () => {
  beforeAll(() => setLocale('en'));
  beforeEach(() => installBrowserMocks());
  afterEach(() => restoreBrowserMocks());

  it('no field selected → Save is disabled and the pipeline editor is hidden', () => {
    const wrapper = mountModal(null);
    expect(saveButton()?.disabled).toBe(true);
    // The pipeline editor renders its "Add operation" trigger only once a source exists.
    expect(bodyButtons().some((b) => (b.textContent ?? '').includes('Add operation'))).toBe(false);
    wrapper.unmount();
  });

  it('the source control is the shared variable BROWSER (one inline tree), not a flat Select', async () => {
    const wrapper = mountModal(null);

    await openSourcePicker();

    // The browser body IS the ARIA tree and the single focusable element carrying virtual
    // focus (B3) — not the Select's flat option listbox.
    const browser = document.body.querySelector('[data-variable-browser]');
    expect(browser).toBeTruthy();
    expect(browser?.getAttribute('role')).toBe('tree');
    expect(browser?.getAttribute('aria-activedescendant')).toBeTruthy();
    expect(document.body.querySelectorAll('[role="listbox"]').length).toBe(0);
    expect(treeLabels()).toEqual(['Status', 'Level', 'Attachment']);

    wrapper.unmount();
  });

  it('a boolean-terminating pipeline (enum_is) enables Save', () => {
    const wrapper = mountModal(seeded([step('enum_is', { value: 'open' })]));
    expect(saveButton()?.disabled).toBe(false);
    expect(terminalSatisfied()).toBe(true);
    wrapper.unmount();
  });

  it('a non-boolean pipeline (enum_to_text) blocks Save', () => {
    const wrapper = mountModal(seeded([step('enum_to_text', { mapping: {} }, 'text')]));
    expect(saveButton()?.disabled).toBe(true);
    expect(terminalSatisfied()).toBe(false);
    wrapper.unmount();
  });

  it('picking an enum field, adding "Is", exposes the field enum options as sourceOption choices', async () => {
    const wrapper = mountModal(null);

    // Pick the Status field from the tree.
    await pickSource('Status');

    // Add the enum "Is" operation from the pipeline editor's menu (opens in edit mode).
    const addBtn = bodyButtons().find((b) => (b.textContent ?? '').includes('Add operation'));
    expect(addBtn).toBeTruthy();
    addBtn!.click();
    await flush();
    await flush();
    addOpButton('Is')!.click();
    await nextTick();

    // The sourceOption arg Select (the LAST combobox) lists the field's enum options.
    const cbs = comboboxes();
    await cbs[cbs.length - 1].click();
    await nextTick();
    expect(optionTexts()).toEqual(['open', 'done', 'blocked']);

    // enum_is → boolean, so the condition is now ready to save.
    expect(saveButton()?.disabled).toBe(false);
    wrapper.unmount();
  });

  it('a file field with file_is_not_empty resolves to boolean → Save enabled', () => {
    const condition: DraftCondition = {
      uid: 'c1', kind: 'condition', source: 'fields.attachment', sourceType: 'file',
      pipeline: [step('file_is_not_empty')],
    };
    const wrapper = mountModal(condition);
    expect(saveButton()?.disabled).toBe(false);
    expect(terminalSatisfied()).toBe(true);
    wrapper.unmount();
  });

  it('a bare file field (identity, no ops) is not boolean → Save blocked', () => {
    const condition: DraftCondition = {
      uid: 'c1', kind: 'condition', source: 'fields.attachment', sourceType: 'file', pipeline: [],
    };
    const wrapper = mountModal(condition);
    expect(saveButton()?.disabled).toBe(true);
    wrapper.unmount();
  });

  it('changing the field confirms, then RESETS the pipeline', async () => {
    const wrapper = mountModal(seeded([step('enum_is', { value: 'open' })]));
    // A step is present → not the empty state yet.
    expect(document.body.textContent).not.toContain('No operations. Add the first transformation');

    // Change the field → a non-empty pipeline triggers the confirm dialog (shared editor copy).
    await pickSource('Level');
    expect(document.body.textContent).toContain('Change the variable?');

    // Confirm → the pipeline clears (empty-state copy returns) and the source switches. The
    // ConfirmDialog's "Change variable" button is NOT the picker trigger (which reuses the same copy
    // as its change-source placeholder, but carries role="combobox"), so exclude that one.
    bodyButtons()
      .find((b) => (b.textContent ?? '').trim() === 'Change variable' && b.getAttribute('role') !== 'combobox')!
      .click();
    await nextTick();
    await nextTick();
    expect(document.body.textContent).toContain('No operations. Add the first transformation');
    expect(document.body.querySelector('[data-variable-source]')?.textContent).toContain('Level');
    wrapper.unmount();
  });

  // --- Tree structure: sections group their leaves, markers ride on the rows ---
  it('a section groups its leaves: expanding it and picking a child emits the composed path', async () => {
    const wrapper = mountModal(null, SECTION_FIELDS, SECTION_VARIABLES);

    await openSourcePicker();

    // Collapsed: only the section GROUP row is visible (its leaves nest under it).
    expect(treeLabels()).toEqual(['Contact']);

    // Expand → the two leaves appear; picking one selects the composed condition path.
    treeItemByText('Contact')!.click();
    await nextTick();
    expect(treeLabels()).toEqual(['Contact', 'Email', 'Phone']);

    treeItemByText('Email')!.click();
    await nextTick();

    // The chosen source is echoed with its label; saving emits the composed `fields.` path.
    expect(document.body.querySelector('[data-variable-source]')?.textContent).toContain('Email');

    // text → text_is_not_empty is a boolean terminal, so Save becomes available.
    const addBtn = bodyButtons().find((b) => (b.textContent ?? '').includes('Add operation'));
    addBtn!.click();
    await flush();
    await flush();
    addOpButton('Is not empty')!.click();
    await nextTick();

    saveButton()!.click();
    await nextTick();

    expect(wrapper.emitted('save')?.[0]?.[0]).toEqual({
      source: 'fields.contact.email',
      sourceType: 'text',
      pipeline: [expect.objectContaining({ operationId: 'text_is_not_empty' })],
    });

    wrapper.unmount();
  });

  it('a NULLABLE field row carries the optional "?" marker (and a required one does not)', async () => {
    const wrapper = mountModal(null, SECTION_FIELDS, SECTION_VARIABLES);

    await openSourcePicker();
    treeItemByText('Contact')!.click();
    await nextTick();

    const email = treeItemByText('Email')!;
    const phone = treeItemByText('Phone')!;
    expect(email.querySelector('[data-marker="optional"]')).toBeTruthy();
    expect(phone.querySelector('[data-marker="optional"]')).toBeNull();

    // The echo of the chosen nullable source carries the marker too.
    email.click();
    await nextTick();
    expect(
      document.body.querySelector('[data-variable-source] [data-marker="optional"]'),
    ).toBeTruthy();

    wrapper.unmount();
  });

  // --- The SF3.2 identifier strip does NOT apply here (B2.1) ------------------
  it('offers a user-named `*_id` field as a source (the strip is for SYSTEM identity paths)', async () => {
    // The strip hides `trigger.task.id` / a step's `task_id` from the INSERTION surfaces. On
    // this surface the feed is the form's own condition fields, so all it could ever hit is a
    // field a user named `order_id` — conditionable, and offered by the Select this replaced.
    const idFields: CatalogField[] = [
      { path: 'fields.order_id', field_id: 'order_id', label: 'Order number', type: 'text', operators: ['equals'] },
      { path: 'fields.title', field_id: 'title', label: 'Title', type: 'text', operators: ['equals'] },
    ];
    const wrapper = mountModal(null, idFields);

    await openSourcePicker();
    expect(treeLabels()).toEqual(['Order number', 'Title']);

    // It is a normal selectable source: picking it drives the payload unchanged.
    treeItemByText('Order number')!.click();
    await nextTick();
    expect(document.body.querySelector('[data-variable-source]')?.textContent).toContain('Order number');

    const addBtn = bodyButtons().find((b) => (b.textContent ?? '').includes('Add operation'));
    addBtn!.click();
    await flush();
    await flush();
    addOpButton('Is not empty')!.click();
    await nextTick();

    saveButton()!.click();
    await nextTick();
    expect(wrapper.emitted('save')?.[0]?.[0]).toMatchObject({
      source: 'fields.order_id',
      sourceType: 'text',
    });

    wrapper.unmount();
  });

  it('an EDITED condition keeps its payload byte-identical when only re-saved', async () => {
    const wrapper = mountModal(seeded([step('enum_is', { value: 'open' })]));

    saveButton()!.click();
    await nextTick();

    expect(wrapper.emitted('save')?.[0]?.[0]).toEqual({
      source: 'fields.status',
      sourceType: 'enum',
      pipeline: [expect.objectContaining({ operationId: 'enum_is', args: { value: 'open' } })],
    });
    // A condition that never set a default OMITS the key (byte-identical round-trip).
    expect(wrapper.emitted('save')?.[0]?.[0]).not.toHaveProperty('default');

    wrapper.unmount();
  });

  // --- B7: the typed "default when empty" (shared VariableDefaultField) --------
  it('shows the default-when-empty input ONLY for a NULLABLE source, typed to it', async () => {
    const wrapper = mountModal(null, SECTION_FIELDS, SECTION_VARIABLES);

    await openSourcePicker();
    treeItemByText('Contact')!.click();
    await nextTick();

    // Email is NULLABLE (text) → the shared default block renders (a TypedLiteralInput, not a
    // boolean tri-state Select).
    treeItemByText('Email')!.click();
    await nextTick();
    expect(document.body.querySelector('[data-vov-default]')).toBeTruthy();

    // Switching to the REQUIRED Phone (empty pipeline ⇒ no confirm) drops the default block.
    await openSourcePicker();
    treeItemByText('Phone')!.click();
    await nextTick();
    expect(document.body.querySelector('[data-vov-default]')).toBeNull();

    wrapper.unmount();
  });

  it('round-trips a seeded default and emits it on save (nullable source)', async () => {
    const seededDefault: DraftCondition = {
      uid: 'c1', kind: 'condition', source: 'fields.contact.email', sourceType: 'text',
      pipeline: [step('text_is_not_empty', {})], default: 'N/A',
    };
    const wrapper = mountModal(seededDefault, SECTION_FIELDS, SECTION_VARIABLES);

    // The seeded nullable source renders the default block already filled.
    expect(document.body.querySelector('[data-vov-default]')).toBeTruthy();

    saveButton()!.click();
    await nextTick();
    expect(wrapper.emitted('save')?.[0]?.[0]).toEqual({
      source: 'fields.contact.email',
      sourceType: 'text',
      pipeline: [expect.objectContaining({ operationId: 'text_is_not_empty' })],
      default: 'N/A',
    });

    wrapper.unmount();
  });

  // --- B7: op-argument variables (pool = trigger + globals, NEVER steps.*) -----
  it('an op argument offers the value/variable toggle when an arg pool is supplied', async () => {
    // The gate-time pool: a trigger field + a global (NO steps.* — nothing has run at gate time).
    const argPool: CatalogVariable[] = [
      { source: 'trigger', path: 'trigger.fields.name', name: 'Name', type: 'text', descriptor: { base: 'text', nullable: false, array: false } },
      { source: 'globals', path: 'globals.brand', name: 'Brand', type: 'text', descriptor: { base: 'text', nullable: false, array: false } },
    ];
    // A text source + text_equals: its `value` arg is a value-typed control that can be a variable.
    const seededText: DraftCondition = {
      uid: 'c1', kind: 'condition', source: 'fields.contact.email', sourceType: 'text',
      pipeline: [step('text_equals', { value: '' })],
    };
    const wrapper = mountModal(seededText, SECTION_FIELDS, SECTION_VARIABLES, argPool);

    // Open the (only) pipeline step to reveal its args; the value arg row carries the
    // value/variable mode toggle (its "braces" Variable button) — the same affordance the step field
    // has. Absent the pool it would be a plain literal input.
    document.body.querySelector<HTMLElement>('[aria-label^="Edit step"]')?.click();
    await nextTick();
    const toggles = Array.from(document.body.querySelectorAll('[aria-pressed]'));
    expect(toggles.some((b) => (b.getAttribute('aria-label') ?? '').toLowerCase().includes('variable'))).toBe(true);

    wrapper.unmount();
  });

  // --- B7: workspace globals as condition sources, grouped under "Globals" -----
  it('groups a global under "Globals", selectable at a scalar leaf, and emits source globals.<key>', async () => {
    const globalFields: CatalogField[] = [
      { path: 'fields.title', field_id: 'title', source: 'trigger', label: 'Title', type: 'text', operators: ['equals'] },
      { path: 'globals.brand', source: 'globals', label: 'Brand', type: 'text', operators: ['equals'] },
    ];
    const globalVariables: CatalogVariable[] = [
      { source: 'trigger', path: 'trigger.fields.title', name: 'Title', type: 'text', descriptor: { base: 'text', nullable: false, array: false } },
      { source: 'globals', path: 'globals.brand', name: 'Brand', type: 'text', descriptor: { base: 'text', nullable: false, array: false } },
    ];
    const wrapper = mountModal(null, globalFields, globalVariables);

    await openSourcePicker();
    // Roots: the form field + the "Globals" GROUP node (the global nests under it, not flat).
    expect(treeLabels()).toEqual(['Title', 'Globals']);

    // Globals is expand-only; expanding reveals the selectable scalar leaf.
    treeItemByText('Globals')!.click();
    await nextTick();
    expect(treeLabels()).toEqual(['Title', 'Globals', 'Brand']);

    treeItemByText('Brand')!.click();
    await nextTick();
    expect(document.body.querySelector('[data-variable-source]')?.textContent).toContain('Brand');

    // text → text_is_not_empty is a boolean terminal; saving emits the FULL globals source path.
    const addBtn = bodyButtons().find((b) => (b.textContent ?? '').includes('Add operation'));
    addBtn!.click();
    await flush();
    await flush();
    addOpButton('Is not empty')!.click();
    await nextTick();

    saveButton()!.click();
    await nextTick();
    expect(wrapper.emitted('save')?.[0]?.[0]).toMatchObject({
      source: 'globals.brand',
      sourceType: 'text',
    });

    wrapper.unmount();
  });

  // --- F1: a repeater condition source threads its descriptor -----------------
  const REPEATER_DESCRIPTOR = {
    base: 'object' as const,
    nullable: false,
    array: true,
    fields: [{ key: 'price', label: 'Price', descriptor: { base: 'number' as const, nullable: false, array: false } }],
  };
  const REPEATER_FIELDS: CatalogField[] = [
    { path: 'fields.items', field_id: 'items', label: 'Items', type: 'multi', operators: ['filled', 'empty'] },
  ];
  const REPEATER_VARIABLES: CatalogVariable[] = [
    { source: 'trigger', path: 'trigger.fields.items', name: 'Items', type: 'multi', descriptor: REPEATER_DESCRIPTOR },
  ];

  it('F1: a repeater source threads its descriptor — array_filter offers the inline object subfield builder', async () => {
    const condition: DraftCondition = { uid: 'c1', kind: 'condition', source: 'fields.items', sourceType: 'multi', pipeline: [] };
    const wrapper = mountModal(condition, REPEATER_FIELDS, REPEATER_VARIABLES);

    const addBtn = bodyButtons().find((b) => (b.textContent ?? '').includes('Add operation'));
    addBtn!.click();
    await flush();
    await flush();
    // The array ops are offered on the repeater; "Keep items" (array_filter) hosts the OBJECT element.
    expect(addOpButton('Keep items')).toBeTruthy();
    addOpButton('Keep items')!.click();
    await nextTick();

    // Because the OBJECT element descriptor is threaded (F1), the element pipeline is the inline subfield
    // builder (F5) — the "Item field" Select — not a bare enum pipeline.
    expect(document.body.textContent).toContain('Item field');
    wrapper.unmount();
  });

  // --- F4: a non-terminal array_at needs a valid typed default ----------------
  const MULTI_FIELDS: CatalogField[] = [
    { path: 'fields.tags', field_id: 'tags', label: 'Tags', type: 'multi', enumOptions: ['open', 'done'], operators: ['includes', 'excludes'] },
  ];

  it('F4: a non-terminal array_at WITHOUT a default blocks Save; a valid typed default enables it', () => {
    const atStep = (over: Record<string, unknown> = {}): VariablePipelineStep => ({
      stepId: 's-at', operationId: 'array_at', args: { index: 1, ...over } as VariablePipelineStep['args'], outputType: 'text',
    });
    const enumIs = step('enum_is', { value: 'open' });

    // array_at → enum_is is NON-terminal (the following op cannot consume a null element).
    const missing: DraftCondition = {
      uid: 'c1', kind: 'condition', source: 'fields.tags', sourceType: 'multi', pipeline: [atStep(), enumIs],
    };
    const blocked = mountModal(missing, MULTI_FIELDS);
    expect(saveButton()?.disabled).toBe(true);
    blocked.unmount();
    document.body.innerHTML = '';

    // A valid typed default (an element option) satisfies the gate → Save enabled.
    const withDefault: DraftCondition = {
      uid: 'c1', kind: 'condition', source: 'fields.tags', sourceType: 'multi',
      pipeline: [atStep({ default: { type: 'enum', value: 'open' } }), enumIs],
    };
    const ok = mountModal(withDefault, MULTI_FIELDS);
    expect(saveButton()?.disabled).toBe(false);
    ok.unmount();
  });
});
