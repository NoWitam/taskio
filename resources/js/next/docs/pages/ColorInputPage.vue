<script setup lang="ts">
import { ref } from 'vue';
import ColorInput from '../../ui/forms/ColorInput.vue';
import FormField from '../../ui/forms/FormField.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const sizes = ['sm', 'md', 'lg'] as const;

const brand = ref<string | null>('#EC4899');
const empty = ref<string | null>(null);
const custom = ref<string | null>('#10B981');
const formColor = ref<string | null>('#3B82F6');

const brandSwatches = [
  '#EC4899', '#D946EF', '#A855F7', '#8B5CF6', '#6366F1', '#3B82F6', '#06B6D4', '#10B981', '#22C55E',
];

const propRows: ApiRow[] = [
  { name: 'v-model', type: 'string | null', default: 'null', description: 'Uppercase #rrggbb hex, or null when cleared.' },
  { name: 'size', type: "'sm' | 'md' | 'lg'", default: "'md'", description: 'Trigger height + text scale.' },
  { name: 'swatches', type: 'string[]', default: 'built-in palette', description: 'Override the preset swatch list (#rgb / #rrggbb each).' },
  { name: 'clearable', type: 'boolean', default: 'true', description: 'Show a clear (✕) affordance + Clear button.' },
  { name: 'placeholder', type: 'string', default: "'Select a color…'", description: 'Trigger text when empty.' },
  { name: 'disabled / readonly', type: 'boolean', default: 'false', description: 'Disabled or read-only state.' },
  { name: 'success / dirty', type: 'boolean', default: 'false', description: 'Force the success / dirty line standalone (FormField sets these).' },
  { name: 'ariaInvalid / id / describedById / ariaLabel', type: 'string | boolean', default: '—', description: 'Standalone wiring; provided inside a FormField.' },
];

const eventRows: ApiRow[] = [
  { name: 'update:modelValue', type: 'string | null', description: 'Emitted as the user drags the SV square / hue, types hex, picks a preset, or clears.' },
];
</script>

<template>
  <StoryPage
    title="ColorInput"
    description="Pick a color. A FieldShell trigger shows a swatch + the hex value; the popover holds a bespoke (no-dependency) saturation/value square, a hue slider, a hex text input, and a palette of preset swatches. Model is an uppercase #rrggbb hex or null."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>The trigger is a <code>button</code> with <code>aria-haspopup="dialog"</code> / <code>aria-expanded</code> / <code>aria-controls</code>; the popover is a <code>role="dialog"</code> (FieldPopover) closing on <kbd>Esc</kbd> + outside click, returning focus to the trigger.</li>
        <li>The SV handle is a focusable control — <kbd>↑</kbd>/<kbd>↓</kbd> change brightness, <kbd>←</kbd>/<kbd>→</kbd> change saturation (hold <kbd>Shift</kbd> for larger steps).</li>
        <li>The hue handle is a <code>role="slider"</code> with <code>aria-valuenow/min/max</code>; arrows step hue, <kbd>Home</kbd>/<kbd>End</kbd> jump.</li>
        <li>Preset swatches are <code>aria-pressed</code> buttons labelled by hex; the selected one shows a check + ring (color is never the only signal).</li>
        <li>The hex input validates <code>#rgb</code>/<code>#rrggbb</code> and flags <code>aria-invalid</code> on a bad value.</li>
      </ul>
    </template>

    <StorySection title="Sizes">
      <div class="grid max-w-sm gap-next-4">
        <StoryCell v-for="s in sizes" :key="s" :label="s">
          <div class="w-56"><ColorInput :size="s" v-model="brand" /></div>
        </StoryCell>
      </div>
    </StorySection>

    <StorySection title="States" description="filled · empty (hatched swatch) · readonly · disabled. Open one to use the SV square + hue + presets + hex.">
      <div class="grid max-w-sm gap-next-4">
        <StoryCell label="filled"><div class="w-56"><ColorInput v-model="brand" /></div></StoryCell>
        <StoryCell label="empty"><div class="w-56"><ColorInput v-model="empty" /></div></StoryCell>
        <StoryCell label="readonly"><div class="w-56"><ColorInput :model-value="'#F59E0B'" readonly /></div></StoryCell>
        <StoryCell label="disabled"><div class="w-56"><ColorInput :model-value="'#F59E0B'" disabled /></div></StoryCell>
      </div>
    </StorySection>

    <StorySection title="Custom presets" description="Override the palette with a brand-specific set via :swatches.">
      <div class="w-56"><ColorInput v-model="custom" :swatches="brandSwatches" /></div>
    </StorySection>

    <StorySection title="Validation (error / success)">
      <div class="grid max-w-sm gap-next-4">
        <FormField label="Accent" :error="brand == null ? 'Pick an accent color.' : undefined">
          <div class="w-56"><ColorInput v-model="brand" /></div>
        </FormField>
        <FormField label="Accent" success="Looks good.">
          <div class="w-56"><ColorInput :model-value="'#22C55E'" success /></div>
        </FormField>
      </div>
    </StorySection>

    <StorySection title="Realistic usage (FormField)">
      <FormField
        label="Label color"
        required
        description="Shown on the project label across the app."
        :error="formColor == null ? 'Choose a color.' : undefined"
      >
        <div class="w-56"><ColorInput v-model="formColor" /></div>
      </FormField>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
        <p class="text-next-sm text-next-muted-foreground">
          <strong>Alpha deferred:</strong> the model is an opaque <code>#rrggbb</code>. An alpha channel
          (and an <code>rgba()</code> emit shape) is out of scope for this parity pass.
          <strong>Color-token exception:</strong> the swatch, SV square, hue track, and preset buttons
          render the user's chosen arbitrary color inline — that is data being edited, not theming.
        </p>
      </div>
    </StorySection>
  </StoryPage>
</template>
