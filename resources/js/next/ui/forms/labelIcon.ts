// labelIcon — map a backend Label `icon` string (App\Enums\IconEnum value) onto
// the "next" frontend's local Icon set (`ui/primitives/icons.ts`).
//
// The backend enum is much larger than (and partly disjoint from) the next icon
// registry, so we map the values that have a clear counterpart and fall back to a
// generic label icon for everything else. This keeps LabelSelect resilient: a
// label whose icon we don't (yet) ship still renders a sensible glyph rather than
// crashing on an unknown name.
import { ICONS, type IconName } from '../primitives/icons';

/** Generic fallback when a label has no icon, or an unmapped one. */
export const LABEL_FALLBACK_ICON: IconName = 'tag';

// Explicit aliases: backend value → next IconName, used when the raw value is not
// itself a valid next icon name.
const ALIASES: Record<string, IconName> = {
  label: 'tag',
  flag: 'flag',
  bookmark: 'bookmark',
  'info-circle': 'info',
  'circle-help': 'help-circle',
  'question-mark': 'help-circle',
  'list-check': 'list-checks',
  logout: 'log-out',
  'dots-horizontal': 'more-horizontal',
  pencil: 'file-text',
  message: 'mail',
  branch: 'git-branch',
  workflow: 'git-branch',
};

const VALID = new Set<string>(Object.keys(ICONS));

/**
 * Resolve a label's `icon` string (possibly null) to a renderable next IconName.
 * Order: explicit alias → exact next-icon name → generic fallback.
 */
export function resolveLabelIcon(icon: string | null | undefined): IconName {
  if (!icon) return LABEL_FALLBACK_ICON;
  if (ALIASES[icon]) return ALIASES[icon];
  if (VALID.has(icon)) return icon as IconName;
  return LABEL_FALLBACK_ICON;
}
