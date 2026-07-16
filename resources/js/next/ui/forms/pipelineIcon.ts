// pipelineIcon — map a backend ApprovalPipeline `icon` string (App\Enums\IconEnum
// value) onto the "next" frontend's local Icon set (`ui/primitives/icons.ts`).
//
// Same idea as `formIcon.ts`, but an approval pipeline's generic fallback is
// `git-branch` (the workflow glyph the Approvals module uses) rather than
// `file-text`. A pipeline whose icon we don't (yet) ship still renders a sensible
// glyph instead of crashing on an unknown name.
import { ICONS, type IconName } from '../primitives/icons';
import { resolveLabelIcon } from './labelIcon';

/** Generic fallback when a pipeline has no icon, or an unmapped one. */
export const PIPELINE_FALLBACK_ICON: IconName = 'git-branch';

const VALID = new Set<string>(Object.keys(ICONS));

/**
 * Resolve a pipeline's `icon` string (possibly null) to a renderable next
 * IconName. Order: exact next-icon name → the shared label-icon alias table
 * (covers `branch`/`workflow` → `git-branch`) → `git-branch`.
 */
export function resolvePipelineIcon(icon: string | null | undefined): IconName {
  if (!icon) return PIPELINE_FALLBACK_ICON;
  if (VALID.has(icon)) return icon as IconName;
  // Reuse the label alias map for shared backend enum values; if it falls back to
  // its own generic `tag`, prefer the pipeline-specific `git-branch` instead.
  const mapped = resolveLabelIcon(icon);
  return mapped === 'tag' ? PIPELINE_FALLBACK_ICON : mapped;
}
