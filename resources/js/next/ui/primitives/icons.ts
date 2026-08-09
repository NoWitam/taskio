// Local icon registry for the "next" frontend.
//
// No icon-library dependency: each icon is hand-authored 24x24 stroke geometry
// (Lucide-style), stored as the raw inner SVG markup. `Icon.vue` looks up a name
// here and inlines the paths, drawing with `currentColor` so icons inherit text
// color and adapt to dark-mode tokens. Sizing is font-size driven (`1em`).
//
// Kept in a plain .ts module (not inside `<script setup>`) so the runtime
// `ICON_NAMES` export is allowed and consumers (e.g. the gallery) can enumerate
// the set.

export type IconName =
  | 'sun'
  | 'moon'
  | 'menu'
  | 'check'
  | 'check-circle'
  | 'x'
  | 'x-circle'
  | 'plus'
  | 'minus'
  | 'search'
  | 'chevron-right'
  | 'chevron-left'
  | 'chevron-up'
  | 'chevron-down'
  | 'arrow-right'
  | 'arrow-left'
  | 'arrow-up'
  | 'arrow-down'
  | 'external-link'
  | 'alert-triangle'
  | 'alert-circle'
  | 'info'
  | 'user'
  | 'users'
  | 'settings'
  | 'bell'
  | 'mail'
  | 'trash'
  | 'download'
  | 'upload'
  | 'star'
  | 'heart'
  | 'eye'
  | 'eye-off'
  | 'loader'
  | 'palette'
  | 'layout-dashboard'
  | 'file-text'
  | 'folder'
  | 'inbox'
  | 'calendar'
  | 'clock'
  | 'log-out'
  | 'help-circle'
  | 'more-horizontal'
  | 'more-vertical'
  | 'rotate-ccw'
  | 'pencil'
  // --- Editor / rich-text toolbar (Tier 7) ---
  | 'bold'
  | 'italic'
  | 'underline'
  | 'strikethrough'
  | 'code'
  | 'code-block'
  | 'list'
  | 'list-ordered'
  | 'list-checks'
  | 'quote'
  | 'heading'
  | 'heading-1'
  | 'heading-2'
  | 'heading-3'
  | 'pilcrow'
  | 'link'
  | 'link-2'
  | 'unlink'
  | 'table'
  | 'undo'
  | 'redo'
  | 'remove-formatting'
  // --- Editor PART 2 (app nodes) ---
  | 'braces'
  | 'git-branch'
  | 'workflow'
  | 'sparkles'
  | 'at-sign'
  | 'type'
  | 'hash'
  | 'lock'
  | 'lock-open'
  // --- Label / tagging (used by LabelSelect icon mapping + fallbacks) ---
  | 'tag'
  | 'flag'
  | 'circle'
  | 'bookmark'
  // --- Disk / file types (the browser tiles + drawer) ---
  | 'image'
  | 'film'
  | 'music'
  | 'archive'
  | 'copy'
  | 'crop'
  // --- AI cost / budget (R2 sub-stage 4 usage meter) ---
  | 'wallet'
  // --- Knowledge (R3): the module + a knowledge base + the link graph ---
  | 'book-open'
  | 'network'
  // Entry-type glyphs. They were added to the REGISTRY below without being added here, which
  // `Record<IconName, string>` makes a type error — latent only because no typecheck runs in CI.
  | 'map-pin'
  | 'package';

/** name -> array of inner SVG elements (paths/circles) as raw markup. */
export const ICONS: Record<IconName, string> = {
  sun:
    '<circle cx="12" cy="12" r="4" /><path d="M12 2v2" /><path d="M12 20v2" />' +
    '<path d="m4.93 4.93 1.41 1.41" /><path d="m17.66 17.66 1.41 1.41" />' +
    '<path d="M2 12h2" /><path d="M20 12h2" />' +
    '<path d="m6.34 17.66-1.41 1.41" /><path d="m19.07 4.93-1.41 1.41" />',
  moon: '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z" />',
  menu: '<path d="M4 6h16" /><path d="M4 12h16" /><path d="M4 18h16" />',
  check: '<path d="M20 6 9 17l-5-5" />',
  'check-circle':
    '<path d="M21.801 10A10 10 0 1 1 17 3.335" /><path d="m9 11 3 3L22 4" />',
  x: '<path d="M18 6 6 18" /><path d="m6 6 12 12" />',
  'x-circle':
    '<circle cx="12" cy="12" r="10" /><path d="m15 9-6 6" /><path d="m9 9 6 6" />',
  plus: '<path d="M5 12h14" /><path d="M12 5v14" />',
  minus: '<path d="M5 12h14" />',
  search: '<circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" />',
  'chevron-right': '<path d="m9 18 6-6-6-6" />',
  'chevron-left': '<path d="m15 18-6-6 6-6" />',
  'chevron-up': '<path d="m18 15-6-6-6 6" />',
  'chevron-down': '<path d="m6 9 6 6 6-6" />',
  'arrow-right': '<path d="M5 12h14" /><path d="m12 5 7 7-7 7" />',
  'arrow-left': '<path d="M19 12H5" /><path d="m12 19-7-7 7-7" />',
  'arrow-up': '<path d="M12 19V5" /><path d="m5 12 7-7 7 7" />',
  'arrow-down': '<path d="M12 5v14" /><path d="m19 12-7 7-7-7" />',
  'external-link':
    '<path d="M15 3h6v6" /><path d="M10 14 21 3" />' +
    '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />',
  'alert-triangle':
    '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z" />' +
    '<path d="M12 9v4" /><path d="M12 17h.01" />',
  'alert-circle':
    '<circle cx="12" cy="12" r="10" /><path d="M12 8v4" /><path d="M12 16h.01" />',
  info:
    '<circle cx="12" cy="12" r="10" /><path d="M12 16v-4" /><path d="M12 8h.01" />',
  user:
    '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" />',
  users:
    '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" />' +
    '<path d="M22 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" />',
  settings:
    '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2Z" />' +
    '<circle cx="12" cy="12" r="3" />',
  bell:
    '<path d="M10.268 21a2 2 0 0 0 3.464 0" />' +
    '<path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8a6 6 0 0 0-12 0c0 4.499-1.411 5.956-2.738 7.326" />',
  mail:
    '<rect width="20" height="16" x="2" y="4" rx="2" />' +
    '<path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />',
  trash:
    '<path d="M3 6h18" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />' +
    '<path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /><path d="M10 11v6" /><path d="M14 11v6" />',
  download:
    '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />' +
    '<path d="M7 10l5 5 5-5" /><path d="M12 15V3" />',
  upload:
    '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />' +
    '<path d="M17 8l-5-5-5 5" /><path d="M12 3v12" />',
  star:
    '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" />',
  heart:
    '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.5 4.04 3 5.5l7 7Z" />',
  eye:
    '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0" />' +
    '<circle cx="12" cy="12" r="3" />',
  'eye-off':
    '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49" />' +
    '<path d="M14.084 14.158a3 3 0 0 1-4.242-4.242" />' +
    '<path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143" />' +
    '<path d="m2 2 20 20" />',
  loader:
    '<path d="M12 2v4" /><path d="m16.2 7.8 2.9-2.9" /><path d="M18 12h4" />' +
    '<path d="m16.2 16.2 2.9 2.9" /><path d="M12 18v4" /><path d="m4.9 19.1 2.9-2.9" />' +
    '<path d="M2 12h4" /><path d="m4.9 4.9 2.9 2.9" />',
  palette:
    '<path d="M12 2a10 10 0 0 0 0 20 2 2 0 0 0 2-2 2 2 0 0 0-.5-1.3 2 2 0 0 1 1.5-3.2H17a5 5 0 0 0 5-5 10 10 0 0 0-10-8.5Z" />' +
    '<circle cx="7.5" cy="10.5" r="1" /><circle cx="12" cy="7.5" r="1" /><circle cx="16.5" cy="10.5" r="1" />',
  'layout-dashboard':
    '<rect width="7" height="9" x="3" y="3" rx="1" />' +
    '<rect width="7" height="5" x="14" y="3" rx="1" />' +
    '<rect width="7" height="9" x="14" y="12" rx="1" />' +
    '<rect width="7" height="5" x="3" y="16" rx="1" />',
  'file-text':
    '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" />' +
    '<path d="M14 2v4a2 2 0 0 0 2 2h4" />' +
    '<path d="M10 9H8" /><path d="M16 13H8" /><path d="M16 17H8" />',
  folder:
    '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z" />',
  inbox:
    '<path d="M22 12h-6l-2 3h-4l-2-3H2" />' +
    '<path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />',
  calendar:
    '<path d="M8 2v4" /><path d="M16 2v4" />' +
    '<rect width="18" height="18" x="3" y="4" rx="2" /><path d="M3 10h18" />',
  clock:
    '<circle cx="12" cy="12" r="10" /><path d="M12 6v6l4 2" />',
  'log-out':
    '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />' +
    '<path d="m16 17 5-5-5-5" /><path d="M21 12H9" />',
  'help-circle':
    '<circle cx="12" cy="12" r="10" />' +
    '<path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" /><path d="M12 17h.01" />',
  'more-horizontal':
    '<circle cx="12" cy="12" r="1" /><circle cx="19" cy="12" r="1" /><circle cx="5" cy="12" r="1" />',
  'more-vertical':
    '<circle cx="12" cy="12" r="1" /><circle cx="12" cy="5" r="1" /><circle cx="12" cy="19" r="1" />',
  'rotate-ccw':
    '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" /><path d="M3 3v5h5" />',
  pencil:
    '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497Z" />' +
    '<path d="m15 5 4 4" />',

  // --- Editor / rich-text toolbar (Tier 7) ---
  bold:
    '<path d="M6 12h9a4 4 0 0 1 0 8H7a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h7a4 4 0 0 1 0 8" />',
  italic:
    '<line x1="19" x2="10" y1="4" y2="4" /><line x1="14" x2="5" y1="20" y2="20" />' +
    '<line x1="15" x2="9" y1="4" y2="20" />',
  underline:
    '<path d="M6 4v6a6 6 0 0 0 12 0V4" /><line x1="4" x2="20" y1="20" y2="20" />',
  strikethrough:
    '<path d="M16 4H9a3 3 0 0 0-2.83 4" /><path d="M14 12a4 4 0 0 1 0 8H6" />' +
    '<line x1="4" x2="20" y1="12" y2="12" />',
  code: '<path d="m16 18 6-6-6-6" /><path d="m8 6-6 6 6 6" />',
  'code-block':
    '<rect width="18" height="18" x="3" y="3" rx="2" />' +
    '<path d="m9 9-2 3 2 3" /><path d="m15 9 2 3-2 3" />',
  list:
    '<path d="M8 6h13" /><path d="M8 12h13" /><path d="M8 18h13" />' +
    '<path d="M3 6h.01" /><path d="M3 12h.01" /><path d="M3 18h.01" />',
  'list-ordered':
    '<path d="M10 6h11" /><path d="M10 12h11" /><path d="M10 18h11" />' +
    '<path d="M4 6h1v4" /><path d="M4 10h2" />' +
    '<path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1" />',
  'list-checks':
    '<path d="m3 5 2 2 4-4" /><path d="M13 6h8" />' +
    '<path d="m3 13 2 2 4-4" /><path d="M13 14h8" />' +
    '<path d="M13 20h8" /><path d="M3 20h.01" />',
  quote:
    '<path d="M16 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2V8.5a5.5 5.5 0 0 0-4-5.3" />' +
    '<path d="M6 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2V8.5a5.5 5.5 0 0 0-4-5.3" />',
  heading:
    '<path d="M6 12h12" /><path d="M6 20V4" /><path d="M18 20V4" />',
  'heading-1':
    '<path d="M4 12h8" /><path d="M4 18V6" /><path d="M12 18V6" />' +
    '<path d="m17 12 3-2v8" />',
  'heading-2':
    '<path d="M4 12h8" /><path d="M4 18V6" /><path d="M12 18V6" />' +
    '<path d="M21 18h-4c0-4 4-3 4-6 0-1.5-2-2.5-4-1" />',
  'heading-3':
    '<path d="M4 12h8" /><path d="M4 18V6" /><path d="M12 18V6" />' +
    '<path d="M17.5 10.5c1.7-1 3.5 0 3.5 1.5a2 2 0 0 1-2 2" />' +
    '<path d="M17 17.5c2 1.5 4 .3 4-1.5a2 2 0 0 0-2-2" />',
  pilcrow:
    '<path d="M13 4v16" /><path d="M17 4v16" />' +
    '<path d="M19 4H9.5a4.5 4.5 0 0 0 0 9H13" />',
  link:
    '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />' +
    '<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />',
  'link-2':
    '<path d="M9 17H7A5 5 0 0 1 7 7h2" /><path d="M15 7h2a5 5 0 1 1 0 10h-2" />' +
    '<line x1="8" x2="16" y1="12" y2="12" />',
  unlink:
    '<path d="m18.84 12.25 1.72-1.71a5 5 0 0 0-7.07-7.07l-1.72 1.71" />' +
    '<path d="m5.17 11.75-1.71 1.71a5 5 0 0 0 7.07 7.07l1.71-1.71" />' +
    '<line x1="8" x2="8" y1="2" y2="5" /><line x1="2" x2="5" y1="8" y2="8" />' +
    '<line x1="16" x2="16" y1="19" y2="22" /><line x1="19" x2="22" y1="16" y2="16" />',
  table:
    '<rect width="18" height="18" x="3" y="3" rx="2" />' +
    '<path d="M3 9h18" /><path d="M3 15h18" />' +
    '<path d="M9 3v18" /><path d="M15 3v18" />',
  undo:
    '<path d="M3 7v6h6" /><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13" />',
  redo:
    '<path d="M21 7v6h-6" /><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3L21 13" />',
  'remove-formatting':
    '<path d="M4 7V4h16v3" /><path d="M5 20h6" /><path d="M13 4 8 20" />' +
    '<path d="m15 15 5 5" /><path d="m20 15-5 5" />',

  // --- Editor PART 2 (app nodes) ---
  braces:
    '<path d="M8 3H7a2 2 0 0 0-2 2v5a2 2 0 0 1-2 2 2 2 0 0 1 2 2v5a2 2 0 0 0 2 2h1" />' +
    '<path d="M16 21h1a2 2 0 0 0 2-2v-5a2 2 0 0 1 2-2 2 2 0 0 1-2-2V5a2 2 0 0 0-2-2h-1" />',
  'git-branch':
    '<line x1="6" x2="6" y1="3" y2="15" /><circle cx="18" cy="6" r="3" />' +
    '<circle cx="6" cy="18" r="3" /><path d="M18 9a9 9 0 0 1-9 9" />',
  workflow:
    '<rect width="8" height="8" x="3" y="3" rx="2" />' +
    '<path d="M7 11v4a2 2 0 0 0 2 2h4" />' +
    '<rect width="8" height="8" x="13" y="13" rx="2" />',
  sparkles:
    '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .962 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.962 0z" />' +
    '<path d="M20 3v4" /><path d="M22 5h-4" /><path d="M4 17v2" /><path d="M5 18H3" />',
  'at-sign':
    '<circle cx="12" cy="12" r="4" />' +
    '<path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94" />',
  type:
    '<path d="M4 7V4h16v3" /><path d="M9 20h6" /><path d="M12 4v16" />',
  hash:
    '<line x1="4" x2="20" y1="9" y2="9" /><line x1="4" x2="20" y1="15" y2="15" />' +
    '<line x1="10" x2="8" y1="3" y2="21" /><line x1="16" x2="14" y1="3" y2="21" />',
  lock:
    '<rect width="18" height="11" x="3" y="11" rx="2" ry="2" />' +
    '<path d="M7 11V7a5 5 0 0 1 10 0v4" />',
  'lock-open':
    '<rect width="18" height="11" x="3" y="11" rx="2" ry="2" />' +
    '<path d="M7 11V7a5 5 0 0 1 9.9-1" />',

  // --- Label / tagging (used by LabelSelect icon mapping + fallbacks) ---
  tag:
    '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z" />' +
    '<circle cx="7.5" cy="7.5" r=".5" fill="currentColor" />',
  flag:
    '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z" />' +
    '<line x1="4" x2="4" y1="22" y2="15" />',
  circle: '<circle cx="12" cy="12" r="10" />',
  bookmark: '<path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z" />',
  // Entry-type glyphs. Both silhouettes are deliberately unlike the other type icons
  // (user / users / calendar / bookmark / braces): a teardrop and a box, readable at badge size.
  'map-pin':
    '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" />' +
    '<circle cx="12" cy="10" r="3" />',
  package:
    '<path d="m7.5 4.27 9 5.15" /><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z" />' +
    '<path d="m3.3 7 8.7 5 8.7-5" /><path d="M12 22V12" />',
  image:
    '<rect width="18" height="18" x="3" y="3" rx="2" ry="2" /><circle cx="9" cy="9" r="2" />' +
    '<path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />',
  film:
    '<rect width="18" height="18" x="3" y="3" rx="2" /><path d="M7 3v18" /><path d="M3 7.5h4" />' +
    '<path d="M3 12h18" /><path d="M3 16.5h4" /><path d="M17 3v18" /><path d="M17 7.5h4" /><path d="M17 16.5h4" />',
  music: '<path d="M9 18V5l12-2v13" /><circle cx="6" cy="18" r="3" /><circle cx="18" cy="16" r="3" />',
  archive:
    '<rect width="20" height="5" x="2" y="3" rx="1" /><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8" />' +
    '<path d="M10 12h4" />',
  copy:
    '<rect width="14" height="14" x="8" y="8" rx="2" ry="2" />' +
    '<path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />',
  crop:
    '<path d="M6 2v14a2 2 0 0 0 2 2h14" />' +
    '<path d="M18 22V8a2 2 0 0 0-2-2H2" />',
  wallet:
    '<path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v3" />' +
    '<path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-3" />' +
    '<path d="M16 12a2 2 0 0 0 0 4h5a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1Z" />',
  // An OPEN book: two facing pages over a spine. Deliberately unlike `file-text`
  // (one page) and `folder` — the module aside relies on four distinct silhouettes.
  'book-open':
    '<path d="M12 7v14" />' +
    '<path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3Z" />',
  // Three nodes joined by two lines — the link GRAPH section. Deliberately unlike `workflow`
  // (squares = steps) and `git-branch` (a fork): this one is a neighbourhood, not a sequence.
  network:
    '<rect x="9" y="2" width="6" height="6" rx="1" /><rect x="2" y="16" width="6" height="6" rx="1" />' +
    '<rect x="16" y="16" width="6" height="6" rx="1" />' +
    '<path d="M12 8v3" /><path d="M5 16v-2a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v2" />',
};

/** All available icon names — handy for the gallery icon grid. */
export const ICON_NAMES = Object.keys(ICONS) as IconName[];
