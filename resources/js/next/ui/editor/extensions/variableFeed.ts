// variableFeed — the ONE bridge between the EDITOR's identity-only variable vocabulary
// (`VariableDefinition`: `{id,name,type,options?,nullable?,array?}`) and the SHARED variable model
// (`ui/variables`: `VariableSourceVar` → `buildVariableTree` → `VariableNode`).
//
// WHY IT EXISTS (B4). Every variable surface now browses the shared TREE — including the markdown
// editor's `{`-insert popup and its chip panel. But the editor feature can be fed TWO ways:
//   • the LIVE `source()` getter — the host's real `VariableSourceVar[]`, WITH descriptors (the
//     workflow step card passes exactly the pool its value-or-variable pickers use), or
//   • the FROZEN `variables` array — the flat, identity-only `VariableDefinition[]` an older /
//     simpler host (the docs page, any plain embed) passes.
// A surface must not care which arrived: this module promotes the flat definitions BACK into the
// shared vocabulary so BOTH end up as one `VariableNode[]`, with the same container rules, the same
// `?`/`[]` markers and the same never-selectable objects.
//
// PURE: no Vue, no i18n, no DOM. Lives beside the editor types (not in `ui/variables`) because the
// `VariableDefinition` vocabulary is the EDITOR's, and the shared model must keep knowing nothing
// about it.
import { buildVariableTree } from '../../variables/variableTree';
import type {
  VariableBase,
  VariableNode,
  VariableSource,
  VariableSourceVar,
  VariableType,
} from '../../variables/types';
import type { VariableDefinition } from './types';

/**
 * The ROOT source a path implies. Used ONLY for a definition-shaped feed, which carries no source
 * of its own: the editor's directive is identity-only (`id` === `path`) and never stores a source,
 * so nothing on this surface can drift from it. The shared model deliberately does NOT re-derive a
 * source from the path (a slot may rewrite paths); here there is no slot and no path transform.
 */
function sourceOfPath(path: string): VariableSource {
  if (path.startsWith('steps.')) return 'steps';
  if (path.startsWith('globals.')) return 'globals';
  // TEMPLATE SLOTS (R2 Generator): a `slots.<name>` definition-shaped feed implies the `slots` root.
  if (path.startsWith('slots.')) return 'slots';
  // The two SCOPED synthetic roots of an element pipeline (array-transform wave 2). They only
  // ever exist inside an element-pipeline browser feed; recognising them here keeps a scope ref's
  // implied source correct if a definition-shaped feed ever carries one.
  if (path === 'element' || path === 'index') return 'scope';
  return 'trigger';
}

/** The structural base implied by a flat editor type (a `multi` is an `enum` array). */
function baseOfType(type: VariableType): VariableBase {
  return type === 'multi' ? 'enum' : type;
}

/**
 * Promote ONE flat editor definition back into the shared model's vocabulary — the exact inverse of
 * `sourceVarToDefinition` (see ./variable.ts). The synthesized descriptor carries the definition's
 * own marker flags, structural base + choices, so a definition-only host still gets the `?`/`[]`
 * markers, a typed "default when empty" control, enum options and NEVER-selectable containers.
 *
 * The one thing a flat definition cannot carry is a container's `descriptor.fields`, so a
 * SELF-CONTAINED object (an object global, whose children are not flat entries) has no children to
 * expand and the tree prunes it as a dead row. A section is unaffected — its leaves ARE flat
 * entries and nest by path. A host that wants the full structure feeds `source()`.
 */
export function definitionToSourceVar(definition: VariableDefinition): VariableSourceVar {
  const type = (definition.type ?? 'text') as VariableType;
  return {
    source: sourceOfPath(definition.id),
    path: definition.id,
    name: definition.name || definition.id,
    type,
    descriptor: {
      // The explicit structural base wins: an `object` container degrades to the `text` wire type,
      // and losing that here would make it a SELECTABLE (insertable) leaf in the tree.
      base: definition.base ?? baseOfType(type),
      nullable: definition.nullable === true,
      array: definition.array === true,
      ...(definition.options?.length
        ? { options: definition.options.map((option) => ({ key: option.value, label: option.label })) }
        : {}),
    },
  };
}

/**
 * The shared-model feed for a variable-capable editor surface: the host's LIVE list when it carries
 * anything, else the frozen definitions promoted back. Mirrors the extension storage's own
 * "live wins over frozen" rule EXACTLY, so the `{` popup, the chip and the panel can never browse a
 * different set than the one the storage reports.
 */
export function variableSourceFeed(
  source: VariableSourceVar[] | null | undefined,
  definitions: VariableDefinition[] | null | undefined,
): VariableSourceVar[] {
  if (source?.length) return source;
  return (definitions ?? []).map(definitionToSourceVar);
}

/**
 * That feed as the browsable TREE (no slot policy — the editor accepts every SELECTABLE variable it
 * is offered). Containers ride through as EXPAND-ONLY branches: `buildVariableTree` guarantees a
 * non-array object is never selectable, which is what makes it safe for the markdown feed to carry
 * sections / object globals / the "Globals" group at all (B4).
 */
export function variableFeedTree(
  source: VariableSourceVar[] | null | undefined,
  definitions: VariableDefinition[] | null | undefined,
): VariableNode[] {
  return buildVariableTree(variableSourceFeed(source, definitions), {});
}
