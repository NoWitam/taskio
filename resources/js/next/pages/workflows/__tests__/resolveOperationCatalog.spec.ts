// Unit tests for resolveOperationCatalog's custom-FUNCTION handling (Phase 3c): a `fn:<uuid>`
// operation descriptor is UNKNOWN to the built-in `standardOperationsCatalog()`, so instead of the
// id-as-label fallback it must carry the wire `label` / `description` (a function's own name/desc)
// and render each declared ARG by its value type (a non-scalar arg type collapses to a text control,
// mirroring the backend call-site control). A KNOWN built-in id still resolves to its FE label.
import { describe, expect, it } from 'vitest';
import { resolveOperationCatalog } from '../workflowConditions';
import { standardOperationsCatalog } from '../../../ui/editor/extensions/standardOperations';
import type { WorkflowCatalog } from '../types';

function catalogWith(operations: WorkflowCatalog['operations']): WorkflowCatalog {
  return { variables: [], fields: [], operations };
}

describe('resolveOperationCatalog — custom function ops', () => {
  it('carries the wire label + description for an unknown fn: id (not the id)', () => {
    const resolved = resolveOperationCatalog(
      catalogWith([
        {
          id: 'fn:abc-123',
          input: 'text',
          output: 'number',
          label: 'Word count',
          description: 'Counts the words',
          args: [],
        },
      ]),
    );

    const op = resolved.find((o) => o.id === 'fn:abc-123');
    expect(op).toBeTruthy();
    expect(op!.label).toBe('Word count'); // NOT 'fn:abc-123'
    expect(op!.description).toBe('Counts the words');
    expect(op!.inputTypes).toEqual(['text']);
    expect(op!.outputType).toBe('number');
  });

  it('renders each declared arg by its value type, collapsing a non-scalar to a text control', () => {
    const resolved = resolveOperationCatalog(
      catalogWith([
        {
          id: 'fn:with-args',
          input: 'number',
          output: 'text',
          label: 'Format',
          description: null,
          args: [
            { id: 'factor', type: 'number' as never },
            { id: 'flag', type: 'boolean' as never },
            { id: 'choice', type: 'enum' as never },
          ],
        },
      ]),
    );

    const op = resolved.find((o) => o.id === 'fn:with-args')!;
    expect(op.args).toEqual([
      { id: 'factor', label: 'factor', type: 'number', mapType: undefined },
      { id: 'flag', label: 'flag', type: 'boolean', mapType: undefined },
      // A non-scalar (enum) arg collapses to a text control (mirrors the backend argControl).
      { id: 'choice', label: 'choice', type: 'text', mapType: undefined },
    ]);
    // A null wire description is dropped (not surfaced as an empty string).
    expect(op.description).toBeUndefined();
  });

  it('a KNOWN built-in id still resolves to its FE label (functions are additive)', () => {
    const known = standardOperationsCatalog()[0];
    const resolved = resolveOperationCatalog(
      catalogWith([{ id: known.id, input: known.inputTypes[0], output: known.outputType, args: [] }]),
    );
    const op = resolved.find((o) => o.id === known.id)!;
    // The FE-labeled definition wins over the id-as-label fallback.
    expect(op.label).toBe(known.label);
    expect(op.label).not.toBe(known.id);
  });
});
