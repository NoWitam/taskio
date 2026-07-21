// A COMMITTED snapshot of the backend operation-id vocabulary. This fixture TRACKS the
// backend enum `App\Modules\Workflows\Enums\WorkflowOperation` (app/modules/Workflows/
// Enums/WorkflowOperation.php) — the authoritative set of pipeline ops the condition
// engine implements. It exists so `operationLabelDrift.spec.ts` can fail CI the moment
// the backend gains an op id that `standardOperations.ts` has no FE label for (which would
// make `resolveOperationCatalog` degrade that op to a raw-id label in the UI).
//
// KEEP IN SYNC with WorkflowOperation: when a new backend op case lands, add its id here
// AND give it a label in `standardOperations.ts` (+ i18n keys). Ordered by the enum's own
// grouping; ids may only be ADDED, never renamed (they are the stable wire contract).
export const BACKEND_WORKFLOW_OPERATION_IDS: string[] = [
  // TEXT (input: text)
  'text_uppercase', 'text_lowercase', 'text_trim', 'text_substring', 'text_replace',
  'text_append', 'text_prepend', 'text_length', 'text_to_number', 'text_equals',
  'text_not_equals', 'text_contains', 'text_starts_with', 'text_ends_with', 'text_is_empty',
  'text_is_not_empty', 'match_to_choice',
  // NUMBER (input: number)
  'num_add', 'num_subtract', 'num_multiply', 'num_divide', 'num_abs', 'num_round',
  'num_floor', 'num_ceil', 'num_to_text', 'num_eq', 'num_neq', 'num_gt', 'num_gte',
  'num_lt', 'num_lte', 'num_between',
  // BOOLEAN (input: boolean)
  'bool_not', 'bool_to_number', 'bool_to_text',
  // DATE (input: date)
  'date_add_days', 'date_subtract_days', 'date_add_months', 'date_add_years',
  'date_start_of_month', 'date_end_of_month', 'date_day', 'date_month', 'date_year',
  'date_weekday', 'date_to_text', 'date_before', 'date_after', 'date_on', 'date_between',
  'date_is_weekend', 'date_is_past', 'date_is_future',
  // ENUM (input: enum)
  'enum_is', 'enum_is_not', 'enum_in', 'enum_to_text', 'enum_to_number', 'enum_to_date',
  'enum_to_choice',
  // MULTI (input: multi)
  'multi_includes', 'multi_excludes', 'multi_includes_any', 'multi_includes_all',
  'multi_count', 'multi_is_empty', 'multi_to_text',
  // FILE (input: file)
  'file_is_empty', 'file_is_not_empty', 'file_count', 'file_name',
];
