// filterTabIcon — the icon set offered by the SaveViewModal picker, plus the
// reverse map (next `IconName` → backend `IconEnum` value) used at save time.
//
// R1 resolution (option c from the UX spec): the picker shows next glyphs (the
// same registry Labels use), but the backend validates `icon` with
// `Rule::enum(IconEnum)`, so we may only OFFER next icons that have a valid
// IconEnum counterpart, and we map the chosen next name → its IconEnum value on
// save. Read-back uses the existing `resolveLabelIcon()` (IconEnum → next),
// keeping ONE shared read path with Labels.
//
// Every entry below is verified to:
//   • be a real next `IconName` (renders in the picker grid), AND
//   • map to a real `App\Enums\IconEnum` value (so a save can't 422), AND
//   • round-trip back through `resolveLabelIcon()` to the SAME next glyph.
import type { IconName } from '../primitives/icons';

/**
 * next IconName → IconEnum value (the string the backend stores). Only icons
 * present here are offered by the picker. Keys are next glyphs; values are
 * IconEnum cases. Where a next name is itself a valid IconEnum value the mapping
 * is identity; otherwise it points at the closest IconEnum case that
 * `resolveLabelIcon` maps BACK to this same next glyph.
 */
export const NEXT_TO_ICON_ENUM: Partial<Record<IconName, string>> = {
  // identity (name is a valid IconEnum value)
  'alert-triangle': 'alert-triangle',
  'arrow-left': 'arrow-left',
  'at-sign': 'at-sign',
  bell: 'bell',
  calendar: 'calendar',
  check: 'check',
  'check-circle': 'check-circle',
  'chevron-down': 'chevron-down',
  'chevron-left': 'chevron-left',
  'chevron-right': 'chevron-right',
  'chevron-up': 'chevron-up',
  clock: 'clock',
  download: 'download',
  'external-link': 'external-link',
  eye: 'eye',
  'eye-off': 'eye-off',
  'file-text': 'file-text',
  flag: 'flag',
  folder: 'folder',
  hash: 'hash',
  heading: 'heading',
  inbox: 'inbox',
  link: 'link',
  list: 'list',
  loader: 'loader',
  lock: 'lock',
  mail: 'mail',
  menu: 'menu',
  minus: 'minus',
  moon: 'moon',
  pencil: 'pencil',
  plus: 'plus',
  redo: 'redo',
  search: 'search',
  settings: 'settings',
  sparkles: 'sparkles',
  sun: 'sun',
  table: 'table',
  tag: 'tag',
  type: 'type',
  trash: 'trash',
  undo: 'undo',
  upload: 'upload',
  user: 'user',
  users: 'users',
  x: 'x',
  'x-circle': 'x-circle',
  // aliased (next name differs from IconEnum value; resolveLabelIcon maps back)
  info: 'info-circle', // info-circle → info
  'help-circle': 'circle-help', // circle-help → help-circle
  'list-checks': 'list-check', // list-check → list-checks
  'log-out': 'logout', // logout → log-out
  'more-horizontal': 'dots-horizontal', // dots-horizontal → more-horizontal
  'git-branch': 'branch', // branch → git-branch
};

/** The ordered list of pickable next icons (the picker grid renders these). */
export const PICKABLE_ICONS: IconName[] = Object.keys(NEXT_TO_ICON_ENUM) as IconName[];

/** Map a chosen next glyph to the IconEnum value to persist (null when unset). */
export function toIconEnumValue(name: IconName | null): string | null {
  if (!name) return null;
  return NEXT_TO_ICON_ENUM[name] ?? null;
}
