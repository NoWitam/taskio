// @vitest-environment happy-dom
// WorkflowConditionModal.spec — the per-condition editor (Modal + shared pipeline).
//
// Asserts the boolean-result GATE (a boolean-terminating pipeline enables Save; a
// non-boolean one blocks it), the enum source → enum_is → sourceOption options wiring,
// and the field-change RESET (confirm → the pipeline clears). The Modal + Selects
// teleport to <body>, so we query/drive document.body directly.
import { describe, it, expect, beforeEach, afterEach, beforeAll } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { setLocale } from '../../../app/i18n';
import WorkflowConditionModal from '../WorkflowConditionModal.vue';
import { standardOperationsCatalog } from '../../../ui/editor/extensions/standardOperations';
import { installBrowserMocks, restoreBrowserMocks } from '../../../__tests__/helpers/dom';
import type { CatalogField, WorkflowVariableType } from '../types';
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

function step(op: string, args: Record<string, unknown> = {}, outputType: WorkflowVariableType = 'boolean'): VariablePipelineStep {
  return { stepId: `s-${op}`, operationId: op, args: args as VariablePipelineStep['args'], outputType };
}

function seeded(pipeline: VariablePipelineStep[], source = 'fields.status'): DraftCondition {
  return { uid: 'c1', kind: 'condition', source, sourceType: 'enum', pipeline };
}

function flush() {
  return new Promise((r) => setTimeout(r, 0));
}

function mountModal(condition: DraftCondition | null) {
  return mount(WorkflowConditionModal, {
    attachTo: document.body,
    props: { open: true, condition, fields: FIELDS, catalog: CATALOG },
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
async function clickOptionText(text: string): Promise<void> {
  const option = Array.from(document.body.querySelectorAll('[role="option"]')).find((o) => (o.textContent ?? '').trim() === text);
  (option as HTMLElement | undefined)?.click();
  await nextTick();
}
/** The add-operation menu button whose LABEL span matches exactly. */
function addOpButton(label: string): HTMLButtonElement | undefined {
  return bodyButtons().find((b) => b.querySelector('span.truncate')?.textContent?.trim() === label);
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

  it('a boolean-terminating pipeline (enum_is) enables Save', () => {
    const wrapper = mountModal(seeded([step('enum_is', { value: 'open' })]));
    expect(saveButton()?.disabled).toBe(false);
    expect(document.body.textContent).toContain('The condition is ready.');
    wrapper.unmount();
  });

  it('a non-boolean pipeline (enum_to_text) blocks Save', () => {
    const wrapper = mountModal(seeded([step('enum_to_text', { mapping: {} }, 'text')]));
    expect(saveButton()?.disabled).toBe(true);
    expect(document.body.textContent).toContain('Keep going until the check returns a yes/no result.');
    wrapper.unmount();
  });

  it('picking an enum field, adding "Is", exposes the field enum options as sourceOption choices', async () => {
    const wrapper = mountModal(null);

    // Pick the Status field (the modal's field Select is the first combobox).
    await comboboxes()[0].click();
    await nextTick();
    await clickOptionText('Status');

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
    expect(document.body.textContent).toContain('The condition is ready.');
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

    // Change the field → a non-empty pipeline triggers the confirm dialog.
    await comboboxes()[0].click();
    await nextTick();
    await clickOptionText('Level');
    expect(document.body.textContent).toContain('Change the field?');

    // Confirm → the pipeline clears (empty-state copy returns) and the source switches.
    bodyButtons().find((b) => (b.textContent ?? '').includes('Change field'))!.click();
    await nextTick();
    await nextTick();
    expect(document.body.textContent).toContain('No operations. Add the first transformation');
    wrapper.unmount();
  });
});
