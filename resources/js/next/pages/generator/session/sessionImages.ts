// sessionImages — shared helpers for the "Zapisz na Dysk" (Save-to-Disk) flow of a generation session
// (R2 sub-stage 2c). Only PRODUCED images are savable (the backend re-reads the produced PNG bytes);
// text parts have no image, so they never appear here. Kept framework-free (pure functions + a type) so
// the result cards, the dock and the chat header all agree on WHAT can be saved and its default name.
import type { ContentTypePart } from '../types';
import type { SessionResults } from '../sessionTypes';

/**
 * A produced image the user can save to Disk — a top-level `image_plan` part, one `scene_plan` scene, or
 * one `storyboard` shot.
 */
export interface SavableImage {
  /** The storage key the serve / save endpoints address (`part.key`, a `scene_plan.<i>` or `storyboard.<i>`). */
  partKey: string;
  /** A human label for the menu entry / default file name. */
  label: string;
}

/** The payload a Save affordance emits up to the dialog owner. */
export interface SaveImageRequest {
  partKey: string;
  /** The pre-filled (editable) default file name. */
  name: string;
}

/** Ensure a base name ends with `.png` — the produced images are always PNG. */
export function pngFileName(base: string): string {
  const trimmed = base.trim() || 'image';
  return /\.png$/i.test(trimmed) ? trimmed : `${trimmed}.png`;
}

/**
 * Collect every produced, savable image across the parts (top-level image parts + scene images), in the
 * content type's declared order. `labelFor` localizes a part's label (kept out so this stays pure).
 */
export function collectSavableImages(
  parts: ContentTypePart[],
  results: SessionResults | null,
  labelFor: (part: ContentTypePart) => string,
): SavableImage[] {
  if (!results) return [];

  const out: SavableImage[] = [];
  for (const part of parts) {
    const result = results[part.key];
    if (!result || result.status !== 'ok') continue;

    if (result.kind === 'image_plan' && result.image) {
      out.push({ partKey: part.key, label: labelFor(part) });
      continue;
    }

    if (result.kind === 'scene_plan' && Array.isArray(result.scenes)) {
      result.scenes.forEach((scene, index) => {
        if (scene.image_status === 'ok' && scene.part_key) {
          out.push({ partKey: scene.part_key, label: `${labelFor(part)} · ${index + 1}` });
        }
      });
    }

    if (result.kind === 'storyboard' && Array.isArray(result.shots)) {
      (result.shots as Array<{ image_status?: string; part_key?: string; index?: number }>).forEach(
        (shot, index) => {
          if (shot.image_status === 'ok' && shot.part_key) {
            out.push({ partKey: shot.part_key, label: `${labelFor(part)} · ${(shot.index ?? index) + 1}` });
          }
        },
      );
    }
  }
  return out;
}
