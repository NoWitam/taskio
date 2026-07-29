// templateContent — the PURE, testable core of a template's DATA-DRIVEN content map.
//
// A template's `content` is a map keyed by the selected content type's PART keys; each value's shape is
// decided by the part's KIND (D8), never by the content-type id. This module owns the per-kind defaults,
// the seed/reconcile when a content type is chosen (or an edit is loaded), and the write-content builder
// (REQUIRED parts always present; OPTIONAL parts only when the author opted in). The editor iterates the
// definition's parts and renders a section per kind — it never hard-codes a post/image layout.
import { emptyImagePlan, isImagePlanComplete } from './imagePlan';
import type {
  BodyContent,
  ContentTypeDefinition,
  ContentTypePart,
  ImagePlanContent,
  PartKind,
  ScenePlanContent,
  ShotListContent,
  StoryboardContent,
  TemplateContent,
} from './types';

/** A fresh text_body / script body. */
export function emptyBody(): BodyContent {
  return { markdown: '' };
}

/** A fresh scene_plan (an empty ordered scene list — lean v1). */
export function emptyScenePlan(): ScenePlanContent {
  return { scenes: [] };
}

/** A fresh shot_list — an empty creative brief (the STRUCTURE is baked server-side). */
export function emptyShotList(): ShotListContent {
  return { brief: emptyBody() };
}

/**
 * A fresh storyboard — an empty style prompt + an empty filter chain (both optional to fill). `max_shots` is
 * deliberately ABSENT rather than null-filled: an unauthored cap means "use the platform ceiling", and the
 * write validator rejects anything that is not a whole number in range, so seeding a null/0 would either
 * ride the wire as noise or 422 on save.
 */
export function emptyStoryboard(): StoryboardContent {
  return { style: emptyBody(), filters: [] };
}

/** The default content value for a part kind (used when a REQUIRED part first appears). */
export function defaultPartContent(kind: PartKind): unknown {
  switch (kind) {
    case 'text_body':
    case 'script':
      return emptyBody();
    case 'image_plan':
      return emptyImagePlan();
    case 'scene_plan':
      return emptyScenePlan();
    case 'shot_list':
      return emptyShotList();
    case 'storyboard':
      return emptyStoryboard();
  }
}

/** Read a body part's markdown, tolerating an absent / malformed value. */
export function bodyMarkdown(content: unknown): string {
  if (content && typeof content === 'object' && !Array.isArray(content)) {
    const markdown = (content as { markdown?: unknown }).markdown;
    if (typeof markdown === 'string') return markdown;
  }
  return '';
}

/** Wrap a markdown string as a body content object. */
export function makeBody(markdown: string): BodyContent {
  return { markdown };
}

/**
 * Whether a part is ACTIVE in the given content map — i.e. its key is present with a non-null value.
 * A REQUIRED part is always active (seeded); an OPTIONAL part is active only when the author added it.
 */
export function isPartActive(content: TemplateContent, part: ContentTypePart): boolean {
  return part.key in content && content[part.key] != null;
}

/**
 * Seed the editor content map for a definition, keeping any EXISTING part value whose key still belongs
 * to the type (an edit-load) and defaulting each REQUIRED part that is missing. OPTIONAL parts stay
 * absent unless they were already authored. Unknown keys (not a declared part) are dropped.
 */
export function seedContent(
  definition: ContentTypeDefinition,
  existing: TemplateContent = {},
): TemplateContent {
  const next: TemplateContent = {};
  for (const part of definition.parts) {
    const has = part.key in existing && existing[part.key] != null;
    if (has) {
      next[part.key] = existing[part.key];
    } else if (part.required) {
      next[part.key] = defaultPartContent(part.kind);
    }
    // an absent optional part stays absent (the author opts in)
  }
  return next;
}

/**
 * Activate an optional part: seed its default content if not already present. Returns a NEW map.
 */
export function activatePart(content: TemplateContent, part: ContentTypePart): TemplateContent {
  if (isPartActive(content, part)) return { ...content };
  return { ...content, [part.key]: defaultPartContent(part.kind) };
}

/** Deactivate an optional part: drop its key. Returns a NEW map. */
export function deactivatePart(content: TemplateContent, part: ContentTypePart): TemplateContent {
  const next = { ...content };
  delete next[part.key];
  return next;
}

/**
 * Build the WRITE content map from the editor's live content: a REQUIRED part is always emitted; an
 * OPTIONAL part only when it is active. Mirrors the backend contract (unknown keys are rejected, a
 * missing required part is a 422) so the payload is exactly what the type declares.
 */
export function buildWriteContent(
  definition: ContentTypeDefinition,
  content: TemplateContent,
): TemplateContent {
  const out: TemplateContent = {};
  for (const part of definition.parts) {
    const active = isPartActive(content, part);
    if (part.required) {
      out[part.key] = active ? content[part.key] : defaultPartContent(part.kind);
    } else if (active) {
      out[part.key] = content[part.key];
    }
  }
  return out;
}

/**
 * Whether the content map is complete enough to SAVE (mirrors the coarse backend gate; the server stays
 * authoritative). REQUIRED body parts need no non-empty text (an empty post is allowed); an ACTIVE image
 * plan needs a complete base; an active scene's active image likewise. `fileSlotNames` are the declared
 * file-typed slot names a `from_slot` base may reference.
 */
export function isContentComplete(
  definition: ContentTypeDefinition,
  content: TemplateContent,
  fileSlotNames: string[],
): boolean {
  for (const part of definition.parts) {
    if (!isPartActive(content, part)) {
      if (part.required) return false; // a required part must be present
      continue;
    }
    if (part.kind === 'image_plan') {
      if (!isImagePlanComplete(content[part.key] as ImagePlanContent | null, fileSlotNames)) return false;
    }
    if (part.kind === 'scene_plan') {
      if (!scenePlanComplete(content[part.key] as ScenePlanContent | null, fileSlotNames)) return false;
    }
  }
  return true;
}

/** A scene plan is complete when every scene's OPTIONAL image (if present) has a complete base. */
function scenePlanComplete(plan: ScenePlanContent | null, fileSlotNames: string[]): boolean {
  if (plan === null) return true;
  const scenes = Array.isArray(plan.scenes) ? plan.scenes : [];
  return scenes.every(
    (scene) => scene.image_plan == null || isImagePlanComplete(scene.image_plan, fileSlotNames),
  );
}

/** The names of the declared file-typed slots — the only slots a `from_slot` image base may name. */
export function fileSlotNames(slots: Array<{ name: string; descriptor: { base?: string } }>): string[] {
  return slots.filter((slot) => slot.descriptor?.base === 'file').map((slot) => slot.name);
}
