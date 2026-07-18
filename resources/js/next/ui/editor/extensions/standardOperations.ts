// standardOperations.ts — the CANONICAL variable-operations catalog for the "next"
// editor. Hosts import `standardOperationsCatalog()` instead of hand-rolling ad-hoc
// op lists, so every pipeline surface (the styleguide demo, workflow conditions,
// future consumers) speaks ONE vocabulary. The ids are the STABLE wire contract the
// backend condition engine implements 1:1 — never rename an id, only add.
//
// Design rules:
//   • ids are `<type>_<verb>` snake_case; grouped by their (single) input type.
//   • every type can TERMINATE in boolean (so any variable can become a condition),
//     CONVERT toward other types where meaningful (to_text / to_number / to_date),
//     and TRANSFORM within its own type.
//   • enum/multi comparison values use the SOURCE-driven arg kinds (`sourceOption`
//     / `sourceOptions`) — choices come from the picked variable's options.
//   • labels + arg labels resolve through the i18n singleton AT CALL TIME, so hosts
//     should read the catalog inside a computed for locale reactivity.
//
// RUNTIME SEMANTICS (documented here, ENFORCED by the backend engine — the editor
// only type-flows):
//   • text_to_number: a non-numeric value fails the evaluation (fail-closed) —
//     it never coerces to 0.
//   • bool_to_text / bool_to_number: yield the `when_true` arg for true and the
//     `when_false` arg for false.
//   • enum_to_text / enum_to_number / enum_to_date: a PER-OPTION `sourceMap`
//     (option value → target value); an option missing from the mapping (or an
//     unparseable mapped number/date) fails the evaluation.
//   • num_divide by zero fails the evaluation.
//   • text_substring: `start` is 1-BASED; `length` 0 means "to the end".
//   • date ops operate on ISO `YYYY-MM-DD` wall-clock dates; date_weekday is
//     0=Sunday..6=Saturday (the module-wide Carbon convention).
//   • date_is_past / date_is_future / date_is_weekend compare against the RUN's
//     "now" in the evaluation timezone.
//   • multi_to_text joins the SELECTED options' VALUES with ", " (the runtime
//     payload carries values only — labels are a UI concept).
import { translate } from '../../../app/i18n';
import type { VariableOperationDefinition } from './types';

/** Label helper: `editor.ops.<id>` with an EN fallback. */
function L(id: string, fallback: string): string {
  return translate(`editor.ops.${id}`, fallback);
}

/** Shared arg-label helper: `editor.opsArgs.<key>` with an EN fallback. */
function A(key: string, fallback: string): string {
  return translate(`editor.opsArgs.${key}`, fallback);
}

/** Description helper: `editor.opsDesc.<id>` with an EN fallback (shown in the add-op menu). */
function D(id: string, fallback: string): string {
  return translate(`editor.opsDesc.${id}`, fallback);
}

/**
 * Build the full standard catalog (66 ops). Call inside a computed — labels follow
 * the active locale.
 */
export function standardOperationsCatalog(): VariableOperationDefinition[] {
  return [
    // ── TEXT ────────────────────────────────────────────────────────────────
    { id: 'text_uppercase', label: L('text_uppercase', 'Uppercase'), inputTypes: ['text'], outputType: 'text' },
    { id: 'text_lowercase', label: L('text_lowercase', 'Lowercase'), inputTypes: ['text'], outputType: 'text' },
    { id: 'text_trim', label: L('text_trim', 'Trim whitespace'), inputTypes: ['text'], outputType: 'text' },
    {
      id: 'text_substring',
      label: L('text_substring', 'Cut a fragment'),
      inputTypes: ['text'],
      outputType: 'text',
      args: [
        { id: 'start', label: A('start', 'From character'), type: 'number', defaultValue: 1 },
        { id: 'length', label: A('length', 'Length (0 = to the end)'), type: 'number', defaultValue: 0 },
      ],
    },
    {
      id: 'text_replace',
      label: L('text_replace', 'Replace fragment'),
      inputTypes: ['text'],
      outputType: 'text',
      args: [
        { id: 'search', label: A('search', 'Find'), type: 'text' },
        { id: 'replace', label: A('replace', 'Replace with'), type: 'text' },
      ],
    },
    {
      id: 'text_append',
      label: L('text_append', 'Append'),
      inputTypes: ['text'],
      outputType: 'text',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'text' }],
    },
    {
      id: 'text_prepend',
      label: L('text_prepend', 'Prepend'),
      inputTypes: ['text'],
      outputType: 'text',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'text' }],
    },
    {
      id: 'text_length',
      label: L('text_length', 'Length'),
      description: D('text_length', 'Counts the characters (e.g. "Jan" → 3).'),
      inputTypes: ['text'],
      outputType: 'number',
    },
    {
      id: 'text_to_number',
      label: L('text_to_number', 'To number'),
      description: D('text_to_number', 'Reads a number written as text (e.g. "42" → 42); non-numeric text fails.'),
      inputTypes: ['text'],
      outputType: 'number',
    },
    {
      // CHOICE-producing (target-driven): only offered when a destination option set
      // (`targetOptions`) exists — a value-or-variable field over a "choice" field.
      id: 'match_to_choice',
      label: L('match_to_choice', 'Match to a choice'),
      description: D('match_to_choice', 'Maps the text to a destination choice by rules; a fallback covers the rest.'),
      inputTypes: ['text'],
      outputType: 'enum',
      args: [
        { id: 'rules', label: A('rules', 'Rules'), type: 'choiceRules' },
        { id: 'fallback', label: A('fallback', 'Fallback choice'), type: 'choiceFallback' },
      ],
    },
    {
      id: 'text_equals',
      label: L('text_equals', 'Equals'),
      inputTypes: ['text'],
      outputType: 'boolean',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'text' }],
    },
    {
      id: 'text_not_equals',
      label: L('text_not_equals', 'Not equals'),
      inputTypes: ['text'],
      outputType: 'boolean',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'text' }],
    },
    {
      id: 'text_contains',
      label: L('text_contains', 'Contains'),
      inputTypes: ['text'],
      outputType: 'boolean',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'text' }],
    },
    {
      id: 'text_starts_with',
      label: L('text_starts_with', 'Starts with'),
      inputTypes: ['text'],
      outputType: 'boolean',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'text' }],
    },
    {
      id: 'text_ends_with',
      label: L('text_ends_with', 'Ends with'),
      inputTypes: ['text'],
      outputType: 'boolean',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'text' }],
    },
    { id: 'text_is_empty', label: L('text_is_empty', 'Is empty'), inputTypes: ['text'], outputType: 'boolean' },
    { id: 'text_is_not_empty', label: L('text_is_not_empty', 'Is not empty'), inputTypes: ['text'], outputType: 'boolean' },

    // ── NUMBER ──────────────────────────────────────────────────────────────
    {
      id: 'num_add',
      label: L('num_add', 'Add'),
      inputTypes: ['number'],
      outputType: 'number',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'number', defaultValue: 1 }],
    },
    {
      id: 'num_subtract',
      label: L('num_subtract', 'Subtract'),
      inputTypes: ['number'],
      outputType: 'number',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'number', defaultValue: 1 }],
    },
    {
      id: 'num_multiply',
      label: L('num_multiply', 'Multiply by'),
      inputTypes: ['number'],
      outputType: 'number',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'number', defaultValue: 2 }],
    },
    {
      id: 'num_divide',
      label: L('num_divide', 'Divide by'),
      inputTypes: ['number'],
      outputType: 'number',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'number', defaultValue: 2 }],
    },
    { id: 'num_abs', label: L('num_abs', 'Absolute value'), inputTypes: ['number'], outputType: 'number' },
    {
      id: 'num_round',
      label: L('num_round', 'Round'),
      inputTypes: ['number'],
      outputType: 'number',
      args: [{ id: 'precision', label: A('precision', 'Decimal places'), type: 'number', defaultValue: 0 }],
    },
    { id: 'num_floor', label: L('num_floor', 'Round down'), inputTypes: ['number'], outputType: 'number' },
    { id: 'num_ceil', label: L('num_ceil', 'Round up'), inputTypes: ['number'], outputType: 'number' },
    { id: 'num_to_text', label: L('num_to_text', 'To text'), inputTypes: ['number'], outputType: 'text' },
    {
      id: 'num_eq',
      label: L('num_eq', 'Equal to'),
      inputTypes: ['number'],
      outputType: 'boolean',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'number', defaultValue: 0 }],
    },
    {
      id: 'num_neq',
      label: L('num_neq', 'Not equal to'),
      inputTypes: ['number'],
      outputType: 'boolean',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'number', defaultValue: 0 }],
    },
    {
      id: 'num_gt',
      label: L('num_gt', 'Greater than'),
      inputTypes: ['number'],
      outputType: 'boolean',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'number', defaultValue: 0 }],
    },
    {
      id: 'num_gte',
      label: L('num_gte', 'Greater or equal'),
      inputTypes: ['number'],
      outputType: 'boolean',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'number', defaultValue: 0 }],
    },
    {
      id: 'num_lt',
      label: L('num_lt', 'Less than'),
      inputTypes: ['number'],
      outputType: 'boolean',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'number', defaultValue: 0 }],
    },
    {
      id: 'num_lte',
      label: L('num_lte', 'Less or equal'),
      inputTypes: ['number'],
      outputType: 'boolean',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'number', defaultValue: 0 }],
    },
    {
      id: 'num_between',
      label: L('num_between', 'Between'),
      inputTypes: ['number'],
      outputType: 'boolean',
      args: [
        { id: 'from', label: A('from', 'From'), type: 'number', defaultValue: 0 },
        { id: 'to', label: A('to', 'To'), type: 'number', defaultValue: 0 },
      ],
    },

    // ── BOOLEAN (condition) ─────────────────────────────────────────────────
    { id: 'bool_not', label: L('bool_not', 'Negate (NOT)'), inputTypes: ['boolean'], outputType: 'boolean' },
    {
      id: 'bool_to_number',
      label: L('bool_to_number', 'To number'),
      description: D('bool_to_number', 'Yields the "when true" number for true and the "when false" one for false.'),
      inputTypes: ['boolean'],
      outputType: 'number',
      args: [
        { id: 'when_true', label: A('whenTrue', 'When true'), type: 'number', defaultValue: 1 },
        { id: 'when_false', label: A('whenFalse', 'When false'), type: 'number', defaultValue: 0 },
      ],
    },
    {
      id: 'bool_to_text',
      label: L('bool_to_text', 'To text'),
      description: D('bool_to_text', 'Yields the "when true" text for true and the "when false" one for false.'),
      inputTypes: ['boolean'],
      outputType: 'text',
      args: [
        { id: 'when_true', label: A('whenTrue', 'When true'), type: 'text' },
        { id: 'when_false', label: A('whenFalse', 'When false'), type: 'text' },
      ],
    },

    // ── DATE ────────────────────────────────────────────────────────────────
    {
      id: 'date_add_days',
      label: L('date_add_days', 'Add days'),
      inputTypes: ['date'],
      outputType: 'date',
      args: [{ id: 'value', label: A('days', 'Days'), type: 'number', defaultValue: 1 }],
    },
    {
      id: 'date_subtract_days',
      label: L('date_subtract_days', 'Subtract days'),
      inputTypes: ['date'],
      outputType: 'date',
      args: [{ id: 'value', label: A('days', 'Days'), type: 'number', defaultValue: 1 }],
    },
    {
      id: 'date_add_months',
      label: L('date_add_months', 'Add months'),
      inputTypes: ['date'],
      outputType: 'date',
      args: [{ id: 'value', label: A('months', 'Months'), type: 'number', defaultValue: 1 }],
    },
    {
      id: 'date_add_years',
      label: L('date_add_years', 'Add years'),
      inputTypes: ['date'],
      outputType: 'date',
      args: [{ id: 'value', label: A('years', 'Years'), type: 'number', defaultValue: 1 }],
    },
    { id: 'date_start_of_month', label: L('date_start_of_month', 'First day of month'), inputTypes: ['date'], outputType: 'date' },
    { id: 'date_end_of_month', label: L('date_end_of_month', 'Last day of month'), inputTypes: ['date'], outputType: 'date' },
    { id: 'date_day', label: L('date_day', 'Day of month'), inputTypes: ['date'], outputType: 'number' },
    { id: 'date_month', label: L('date_month', 'Month number'), inputTypes: ['date'], outputType: 'number' },
    { id: 'date_year', label: L('date_year', 'Year'), inputTypes: ['date'], outputType: 'number' },
    { id: 'date_weekday', label: L('date_weekday', 'Weekday (0=Sunday)'), inputTypes: ['date'], outputType: 'number' },
    { id: 'date_to_text', label: L('date_to_text', 'To text (YYYY-MM-DD)'), inputTypes: ['date'], outputType: 'text' },
    {
      id: 'date_before',
      label: L('date_before', 'Before'),
      inputTypes: ['date'],
      outputType: 'boolean',
      args: [{ id: 'value', label: A('date', 'Date'), type: 'date' }],
    },
    {
      id: 'date_after',
      label: L('date_after', 'After'),
      inputTypes: ['date'],
      outputType: 'boolean',
      args: [{ id: 'value', label: A('date', 'Date'), type: 'date' }],
    },
    {
      id: 'date_on',
      label: L('date_on', 'On the same day'),
      inputTypes: ['date'],
      outputType: 'boolean',
      args: [{ id: 'value', label: A('date', 'Date'), type: 'date' }],
    },
    {
      id: 'date_between',
      label: L('date_between', 'Between'),
      inputTypes: ['date'],
      outputType: 'boolean',
      args: [
        { id: 'from', label: A('from', 'From'), type: 'date' },
        { id: 'to', label: A('to', 'To'), type: 'date' },
      ],
    },
    { id: 'date_is_weekend', label: L('date_is_weekend', 'Falls on a weekend'), inputTypes: ['date'], outputType: 'boolean' },
    { id: 'date_is_past', label: L('date_is_past', 'Is in the past'), inputTypes: ['date'], outputType: 'boolean' },
    { id: 'date_is_future', label: L('date_is_future', 'Is in the future'), inputTypes: ['date'], outputType: 'boolean' },

    // ── ENUM (single choice) ────────────────────────────────────────────────
    {
      id: 'enum_is',
      label: L('enum_is', 'Is'),
      inputTypes: ['enum'],
      outputType: 'boolean',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'sourceOption' }],
    },
    {
      id: 'enum_is_not',
      label: L('enum_is_not', 'Is not'),
      inputTypes: ['enum'],
      outputType: 'boolean',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'sourceOption' }],
    },
    {
      id: 'enum_in',
      label: L('enum_in', 'Is one of'),
      inputTypes: ['enum'],
      outputType: 'boolean',
      args: [{ id: 'values', label: A('values', 'Values'), type: 'sourceOptions' }],
    },
    {
      id: 'enum_to_text',
      label: L('enum_to_text', 'To text'),
      description: D('enum_to_text', 'Maps EACH option to the text you enter for it.'),
      inputTypes: ['enum'],
      outputType: 'text',
      args: [{ id: 'mapping', label: A('mapping', 'Value per option'), type: 'sourceMap', mapType: 'text' }],
    },
    {
      id: 'enum_to_number',
      label: L('enum_to_number', 'To number'),
      description: D('enum_to_number', 'Maps EACH option to the number you enter for it; an unmapped option fails.'),
      inputTypes: ['enum'],
      outputType: 'number',
      args: [{ id: 'mapping', label: A('mapping', 'Value per option'), type: 'sourceMap', mapType: 'number' }],
    },
    {
      id: 'enum_to_date',
      label: L('enum_to_date', 'To date'),
      description: D('enum_to_date', 'Maps EACH option to the date you pick for it; an unmapped option fails.'),
      inputTypes: ['enum'],
      outputType: 'date',
      args: [{ id: 'mapping', label: A('mapping', 'Value per option'), type: 'sourceMap', mapType: 'date' }],
    },
    {
      // CHOICE-producing (target-driven): only offered when a destination option set
      // (`targetOptions`) exists. Each SOURCE option maps to a DESTINATION choice value.
      id: 'enum_to_choice',
      label: L('enum_to_choice', 'To a choice'),
      description: D('enum_to_choice', 'Maps EACH option to a destination choice; an unmapped option fails.'),
      inputTypes: ['enum'],
      outputType: 'enum',
      args: [{ id: 'mapping', label: A('choiceMapping', 'Choice per option'), type: 'sourceMap', mapType: 'enum' }],
    },

    // ── MULTI (multiple choice) ─────────────────────────────────────────────
    {
      id: 'multi_includes',
      label: L('multi_includes', 'Includes'),
      inputTypes: ['multi'],
      outputType: 'boolean',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'sourceOption' }],
    },
    {
      id: 'multi_excludes',
      label: L('multi_excludes', 'Does not include'),
      inputTypes: ['multi'],
      outputType: 'boolean',
      args: [{ id: 'value', label: A('value', 'Value'), type: 'sourceOption' }],
    },
    {
      id: 'multi_includes_any',
      label: L('multi_includes_any', 'Includes any of'),
      inputTypes: ['multi'],
      outputType: 'boolean',
      args: [{ id: 'values', label: A('values', 'Values'), type: 'sourceOptions' }],
    },
    {
      id: 'multi_includes_all',
      label: L('multi_includes_all', 'Includes all of'),
      inputTypes: ['multi'],
      outputType: 'boolean',
      args: [{ id: 'values', label: A('values', 'Values'), type: 'sourceOptions' }],
    },
    { id: 'multi_count', label: L('multi_count', 'Count'), inputTypes: ['multi'], outputType: 'number' },
    { id: 'multi_is_empty', label: L('multi_is_empty', 'Is empty'), inputTypes: ['multi'], outputType: 'boolean' },
    { id: 'multi_to_text', label: L('multi_to_text', 'To text (joined)'), inputTypes: ['multi'], outputType: 'text' },

    // ── file ────────────────────────────────────────────────────────────────
    // A file variable carries a snapshot list. Two boolean terminals make a file field usable
    // in a condition at all; the two converters hand it to the text/number vocabulary, so
    // "is it a PDF" is file_name -> text_ends_with '.pdf' and "more than one" is
    // file_count -> num_gt 1 — no file-specific comparison ops to keep in sync.
    { id: 'file_is_empty', label: L('file_is_empty', 'Is empty'), inputTypes: ['file'], outputType: 'boolean' },
    { id: 'file_is_not_empty', label: L('file_is_not_empty', 'Is not empty'), inputTypes: ['file'], outputType: 'boolean' },
    { id: 'file_count', label: L('file_count', 'Count'), inputTypes: ['file'], outputType: 'number' },
    { id: 'file_name', label: L('file_name', 'File name'), inputTypes: ['file'], outputType: 'text' },
  ];
}
