// templateMeta — the content-type + part-KIND → icon / label helpers (list rows, editor picker,
// preview headers). Content types come from `GET /generator/content-types`, so a label is localized by
// its STABLE id (`generator.templates.type.<id>`) with the SERVER label as the fallback — never the raw
// server string as the primary. A new/unknown id or kind falls back to a neutral glyph so the FE never
// crashes on an extended registry.
import type { IconName } from '../../ui/primitives/icons';
import type { ContentTypeId, PartKind } from './types';

type TranslateFn = (key: string, defaultValue?: string, params?: Record<string, string | number>) => string;

/** The per-content-type glyph (fallback: the generic file glyph). */
const TYPE_ICONS: Record<string, IconName> = {
  post: 'file-text',
  post_with_image: 'image',
  video_script: 'film',
};

/** The icon for a content type (fallback: the generic file glyph). */
export function contentTypeIcon(id: ContentTypeId | string): IconName {
  return TYPE_ICONS[id] ?? 'file-text';
}

/**
 * The localized label for a content type: prefer the stable id key (`generator.templates.type.<id>`),
 * then the server-provided label, then the id itself — so a new server type still shows its own label.
 */
export function contentTypeLabel(id: ContentTypeId | string, t: TranslateFn, serverLabel?: string): string {
  return t(`generator.templates.type.${id}`, serverLabel || String(id));
}

/** The per-part-kind glyph (drives the data-driven section headers). */
const KIND_ICONS: Record<PartKind, IconName> = {
  text_body: 'file-text',
  image_plan: 'image',
  script: 'film',
  scene_plan: 'list-ordered',
  shot_list: 'list-ordered',
  storyboard: 'layout-dashboard',
};

/** The icon for a part kind (fallback: the generic file glyph). */
export function partKindIcon(kind: PartKind | string): IconName {
  return KIND_ICONS[kind as PartKind] ?? 'file-text';
}
