// operationLabelDrift.spec — the FE↔BE OP-LABEL drift alarm.
//
// `resolveOperationCatalog` (pages/workflows/workflowConditions.ts) merges the backend's
// label-less operation DESCRIPTORS with the FE labels from `standardOperationsCatalog()`
// by id; an op the FE has NO label for degrades to a raw-id label in the UI. This spec
// guards that gap: every id in the committed backend fixture
// (`backendWorkflowOperationIds.ts`, which tracks `WorkflowOperation`) MUST have a real,
// i18n-resolved FE label. Add a backend op without an FE label → this fails CI here first.
import { describe, it, expect, beforeAll } from 'vitest';
import { setLocale } from '../../../../app/i18n';
import { standardOperationsCatalog } from '../standardOperations';
import { BACKEND_WORKFLOW_OPERATION_IDS } from './backendWorkflowOperationIds';

describe('operation label drift — backend WorkflowOperation ⊆ FE standardOperations labels', () => {
  beforeAll(() => setLocale('en'));

  it('every backend op id has a real, i18n-resolved FE label (no raw-id fallback)', () => {
    const byId = new Map(standardOperationsCatalog().map((op) => [op.id, op]));

    for (const id of BACKEND_WORKFLOW_OPERATION_IDS) {
      const op = byId.get(id);
      // The core guard: a backend op the FE cannot label at all.
      expect(op, `no FE entry for backend op '${id}' — add it to standardOperations.ts (+ i18n)`).toBeTruthy();
      // A label that fell back to the raw id, or a still-unresolved i18n key, is drift too.
      expect(op!.label, `'${id}' label degraded to the raw id`).not.toBe(id);
      expect(op!.label.startsWith('editor.'), `'${id}' label did not resolve through i18n`).toBe(false);
      expect(op!.label.length, `'${id}' has an empty label`).toBeGreaterThan(0);
    }
  });

  it('the FE catalog carries no op the backend fixture is missing (both directions pinned)', () => {
    // Complements the subset check above: if the FE adds an op id the backend fixture does
    // not track, the fixture is stale — update it (and the backend enum) together.
    const backend = new Set(BACKEND_WORKFLOW_OPERATION_IDS);
    const feOnly = standardOperationsCatalog()
      .map((op) => op.id)
      .filter((id) => !backend.has(id));
    expect(feOnly, `FE ops absent from the backend fixture: ${feOnly.join(', ')}`).toEqual([]);
  });
});
