// conditionTreeContext — the provide/inject bridge for the recursive condition TREE.
//
// WorkflowConditionsEditor owns the tree (its v-model) + the Modal; each nested
// WorkflowConditionGroup renders a slice and calls back UP through this context (by
// uid) so the recursion never threads props/emits through every level.
import type { InjectionKey } from 'vue';
import type { VariableOperationDefinition } from '../../ui/editor/extensions/types';
import type { CatalogField, CatalogVariable, ConditionLogic } from './types';
import type { ConditionSummary, DraftCondition } from './workflowConditions';

export interface ConditionTreeContext {
  /** The catalog condition fields (chip labels + the Modal source picker). */
  fields: () => CatalogField[];
  /**
   * The condition fields as PICKER variables (`conditionSourceVariables`) — the same feed the
   * Modal's tree picker runs on, so a chip can render its source with the SAME type glyph and
   * nullable / array markers the picker shows.
   */
  sources: () => CatalogVariable[];
  /** The merged operations catalog (chip op labels). */
  operations: () => VariableOperationDefinition[];
  /** The server 422 map (conditions.*), for the section error read-out. */
  errors: () => Record<string, string>;
  /** Open the Modal to append a condition to a group. */
  addCondition: (groupUid: string) => void;
  /** Append an empty sub-group to a group. */
  addGroup: (groupUid: string) => void;
  /** Open the Modal seeded with an existing condition. */
  editCondition: (uid: string) => void;
  /** Remove any node by uid (never the root). */
  removeNode: (uid: string) => void;
  /** Switch a group's logic (AND ⇄ OR). */
  setLogic: (groupUid: string, logic: ConditionLogic) => void;
  /** The human sentence parts for a condition chip. */
  summarize: (condition: DraftCondition) => ConditionSummary;
  /** The tree limits (child count / nesting depth). */
  limits: { maxDepth: number; maxGroupChildren: number };
}

export const CONDITION_TREE_KEY = Symbol('workflow-condition-tree') as InjectionKey<ConditionTreeContext>;
