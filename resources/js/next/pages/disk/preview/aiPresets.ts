// AI edit presets for the image editor (P11 / F2-3): each is a fixed ENGLISH prompt template (image
// models follow English instructions most reliably) with a LOCALIZED chip label. Presets split into
// two families:
//   • FULL_IMAGE_PRESETS — whole-image edits, applied on click with NO mask.
//   • MASKED_PRESETS      — edits confined to a brushed region; selecting one arms the mask brush and
//                           the Apply is gated on painted strokes. `usesPrompt` ops (replace) take the
//                           free-text prompt as their instruction instead of a fixed template.
// The free-form prompt field sits alongside — presets are just prompt shortcuts, same endpoint.
import type { IconName } from '../../../ui/primitives/icons';

export interface AiPreset {
  key: string;
  /** i18n key under disk.preview.ai.presets.* */
  labelKey: string;
  /** The English instruction sent to the model (empty for `usesPrompt` ops — they take the free text). */
  prompt: string;
  /** Chip icon (defaults to `sparkles` in the panel). */
  icon?: IconName;
  /** Requires a brushed mask region (an area painted on the canvas). */
  needsMask?: boolean;
  /** Uses the free-text prompt as the instruction (the masked "replace" op). */
  usesPrompt?: boolean;
}

export const FULL_IMAGE_PRESETS: AiPreset[] = [
  {
    key: 'remove-background',
    labelKey: 'disk.preview.ai.presets.removeBackground',
    icon: 'image',
    prompt:
      'Remove the background completely and replace it with a clean, plain white background. Keep the main subject unchanged.',
  },
  {
    key: 'enhance',
    labelKey: 'disk.preview.ai.presets.enhance',
    icon: 'sparkles',
    prompt:
      'Enhance the overall quality of this image: improve lighting, color balance and contrast. Keep the content and composition unchanged.',
  },
  {
    key: 'sharpen',
    labelKey: 'disk.preview.ai.presets.sharpen',
    icon: 'sparkles',
    prompt: 'Sharpen this image and reduce blur and noise. Keep the content and composition unchanged.',
  },
  {
    key: 'line-art',
    labelKey: 'disk.preview.ai.presets.lineArt',
    icon: 'pencil',
    prompt:
      'Convert this image into clean black-and-white line art: bold, smooth outlines on a plain white background, no shading or color. Keep the composition.',
  },
  {
    key: 'watercolor',
    labelKey: 'disk.preview.ai.presets.watercolor',
    icon: 'palette',
    prompt:
      'Repaint this image as a soft watercolor illustration with gentle washes and visible paper texture. Keep the composition and main subject recognizable.',
  },
  {
    key: 'product-on-white',
    labelKey: 'disk.preview.ai.presets.productOnWhite',
    icon: 'image',
    prompt:
      'Place the main subject on a clean, seamless pure white studio background with soft, even product lighting and a subtle natural shadow. Keep the subject unchanged.',
  },
];

export const MASKED_PRESETS: AiPreset[] = [
  {
    key: 'remove-object',
    labelKey: 'disk.preview.ai.presets.removeObject',
    icon: 'remove-formatting',
    needsMask: true,
    prompt:
      'Completely and seamlessly remove any object inside the edited region and reconstruct the ' +
      'background so it perfectly continues the surrounding scene — matching its exact colors, ' +
      'lighting, textures and patterns, as if nothing was ever there. Do not darken, blur, or ' +
      'leave any patch, outline or artifact.',
  },
  {
    key: 'replace',
    labelKey: 'disk.preview.ai.presets.replace',
    icon: 'sparkles',
    needsMask: true,
    usesPrompt: true,
    prompt: '',
  },
];
