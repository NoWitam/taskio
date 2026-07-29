// templateCatalog — the generator catalog → shared-editor adapter.
//
// The `POST /generator/catalog` response is the SAME `{variables, operations, types}` shape the
// workflow catalog returns, so this REUSES the workflow catalog→editor transforms verbatim (no
// parallel implementation): it adapts a `GeneratorCatalog` to the `WorkflowCatalog` shape those
// pure functions accept (empty `fields`, no steps / trigger) and delegates.
//
//   • templateEditorVariables(catalog) → the MarkdownEditor's identity `VariableDefinition[]`
//     (`slots.<name>` + `globals.*`, with the TRUE type + options + `?`/`[]` markers),
//   • templateSourceVariables(catalog) → the shared-model `CatalogVariable[]` LIVE feed the
//     editor's `{`-browser + chip pipeline + arg-variable pool run on,
//   • templateOperationsCatalog(catalog) → the resolved operations (built-ins × FE labels +
//     `fn:<uuid>` custom functions).
//
// A slot carries `source:'slots'` (never `steps`), so it rides through `expandVariables` as a
// plain selectable leaf; a `globals.*` entry still groups under the shared "Globals" node.
import { allValueVariables, toEditorVariablesTyped } from '../workflows/workflowVariables';
import { resolveOperationCatalog } from '../workflows/workflowConditions';
import type { CatalogVariable, WorkflowCatalog } from '../workflows/types';
import type {
  VariableDefinition,
  VariableOperationDefinition,
} from '../../ui/editor/extensions/types';
import type { GeneratorCatalog } from './types';

/**
 * Adapt a `GeneratorCatalog` to the `WorkflowCatalog` shape the shared transforms accept — an
 * empty `fields` (no condition sources here) and no step outputs. A null / absent catalog stays
 * null so every transform degrades to "just what the draft slots imply" gracefully.
 */
function asWorkflowCatalog(catalog: GeneratorCatalog | null | undefined): WorkflowCatalog | null {
  if (!catalog) return null;
  return {
    variables: catalog.variables ?? [],
    fields: [],
    operations: catalog.operations,
    types: catalog.types,
  };
}

/** The MarkdownEditor's identity-only `VariableDefinition[]` for the template prompt body. */
export function templateEditorVariables(
  catalog: GeneratorCatalog | null | undefined,
): VariableDefinition[] {
  return toEditorVariablesTyped(asWorkflowCatalog(catalog), [], 0);
}

/** The LIVE shared-model feed (`CatalogVariable[]`) the editor's browser + pipelines run on. */
export function templateSourceVariables(
  catalog: GeneratorCatalog | null | undefined,
): CatalogVariable[] {
  return allValueVariables(asWorkflowCatalog(catalog), [], 0);
}

/** The resolved operations catalog (built-ins × FE labels + `fn:<uuid>` functions). */
export function templateOperationsCatalog(
  catalog: GeneratorCatalog | null | undefined,
): VariableOperationDefinition[] {
  return resolveOperationCatalog(asWorkflowCatalog(catalog));
}
