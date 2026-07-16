// formIcon — map a backend Form `icon` string (App\Enums\IconEnum value) onto the
// "next" frontend's local Icon set (`ui/primitives/icons.ts`).
//
// Same idea as `labelIcon.ts`, but a Form's generic fallback is `file-text` (the
// document glyph the Forms module uses everywhere) rather than `tag`. A form whose
// icon we don't (yet) ship still renders a sensible glyph instead of crashing on
// an unknown name.
import { ICONS, type IconName } from '../primitives/icons';
import { resolveLabelIcon } from './labelIcon';

/** Generic fallback when a form has no icon, or an unmapped one. */
export const FORM_FALLBACK_ICON: IconName = 'file-text';

const VALID = new Set<string>(Object.keys(ICONS));

/**
 * Resolve a form's `icon` string (possibly null) to a renderable next IconName.
 * Order: exact next-icon name → the shared label-icon alias table → `file-text`.
 */
export function resolveFormIcon(icon: string | null | undefined): IconName {
  if (!icon) return FORM_FALLBACK_ICON;
  if (VALID.has(icon)) return icon as IconName;
  // Reuse the label alias map for shared backend enum values; if it falls back to
  // its own generic `tag`, prefer the form-specific `file-text` fallback instead.
  const mapped = resolveLabelIcon(icon);
  return mapped === 'tag' ? FORM_FALLBACK_ICON : mapped;
}
