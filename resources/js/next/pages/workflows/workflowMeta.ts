// Static presentation maps for the Workflows module (next) — REVISION 2 (B7a).
//
// Central icon/label lookups for the domain enums so every surface (card, detail,
// runs) renders trigger types, step types, run states and origins consistently —
// always icon + text, never color-only (§7.3-7.6). Labels are resolved through the
// passed translator so they stay locale-reactive; icons are fixed `IconName`s
// verified against `ui/primitives/icons.ts`.
//
// 5.1 rebuild: TWO trigger types (form_submitted, schedule) + TWO step types
// (create_task, create_form_report). B7e retired the last legacy consumers
// (TargetPickerModal + WorkflowDetailView), so these maps are narrowed to the strict
// typed unions; the authoritative ordered arrays `TRIGGER_TYPES`/`STEP_TYPES` carry the
// same values. R2 sub-stage 5 adds the THIRD step type, `generate_content`.
import type { IconName } from '../../ui/primitives/icons';
import type {
  WorkflowRunOrigin,
  WorkflowRunState,
  WorkflowStepType,
  WorkflowTone,
  WorkflowTriggerType,
} from './types';

type Translate = (key: string, defaultValue?: string, params?: Record<string, string | number>) => string;

/** A Badge variant derived from a backend tone string. */
type BadgeVariant = 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info';

// --- Trigger types (§7.3 — two only) ---------------------------------------

/** The TWO trigger types in a stable order (used by the trigger SegmentedControl). */
export const TRIGGER_TYPES: WorkflowTriggerType[] = ['form_submitted', 'schedule'];

/** Trigger-type → icon (§7.3): form_submitted → file-text, schedule → calendar. */
const TRIGGER_ICONS: Record<WorkflowTriggerType, IconName> = {
  form_submitted: 'file-text',
  schedule: 'calendar',
};

export function triggerIcon(type: WorkflowTriggerType): IconName {
  return TRIGGER_ICONS[type] ?? 'workflow';
}
export function triggerLabel(type: WorkflowTriggerType, t: Translate): string {
  return t(`workflows.trigger.${type}.label`);
}
export function triggerShort(type: WorkflowTriggerType, t: Translate): string {
  return t(`workflows.trigger.${type}.short`);
}

// --- Step types (§7.4 — two only) ------------------------------------------

/** The step types in a stable order (used by the add-step type picker). */
export const STEP_TYPES: WorkflowStepType[] = [
  'create_task',
  'create_form_report',
  'generate_content',
  'create_event',
];

/**
 * Step-type → icon (§7.4): create_task → plus, create_form_report → file-text,
 * generate_content → sparkles (the Generator module's own glyph, so the step reads
 * as "this runs the content generator"), create_event → calendar (the Calendar module's
 * own glyph, for the same reason).
 *
 * `calendar` is ALSO the `schedule` TRIGGER's glyph, and that collision is deliberate
 * rather than overlooked: the two never appear in the same vocabulary (a trigger badge
 * and a step badge are different rows of the editor, each labelled in words), and the
 * alternative — giving the Calendar module two different silhouettes depending on which
 * list it is in — would be the more confusing choice.
 */
const STEP_ICONS: Record<WorkflowStepType, IconName> = {
  create_task: 'plus',
  create_form_report: 'file-text',
  generate_content: 'sparkles',
  create_event: 'calendar',
};

export function stepIcon(type: WorkflowStepType): IconName {
  return STEP_ICONS[type] ?? 'list-checks';
}
export function stepLabel(type: WorkflowStepType, t: Translate): string {
  return t(`workflows.step.${type}.label`);
}

// --- Run states (§5.5 — exhaustive 6-state map) ----------------------------

const RUN_STATE_ICONS: Record<WorkflowRunState, IconName> = {
  pending: 'clock',
  running: 'loader',
  waiting: 'clock',
  completed: 'check-circle',
  failed: 'x-circle',
  cancelled: 'x',
};

export function runStateIcon(state: WorkflowRunState): IconName {
  return RUN_STATE_ICONS[state] ?? 'clock';
}
export function runStateLabel(state: WorkflowRunState, t: Translate): string {
  return t(`workflows.runs.state.${state}`);
}

// --- Origins (§7.6 — event retuned to file-text) ---------------------------

const ORIGIN_ICONS: Record<WorkflowRunOrigin, IconName> = {
  // The only event origin is now a form submission (event triggers gone), so the
  // origin glyph mirrors the form_submitted trigger (§7.6). schedule/manual unchanged.
  event: 'file-text',
  schedule: 'calendar',
  manual: 'user',
};

export function originIcon(origin: WorkflowRunOrigin): IconName {
  return ORIGIN_ICONS[origin] ?? 'file-text';
}
export function originLabel(origin: WorkflowRunOrigin, t: Translate): string {
  return t(`workflows.runs.origin.${origin}`);
}

// --- Tone → Badge variant --------------------------------------------------

/**
 * Map a backend tone string (state_tone / status_tone) onto a Badge variant.
 * `danger` for a hard verdict, everything else its natural token; unknown → neutral.
 */
export function toneToVariant(tone: WorkflowTone | string): BadgeVariant {
  switch (tone) {
    case 'success':
      return 'success';
    case 'danger':
      return 'danger';
    case 'warning':
      return 'warning';
    case 'info':
      return 'info';
    case 'neutral':
    default:
      return 'neutral';
  }
}
