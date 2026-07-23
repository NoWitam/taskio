<script setup lang="ts">
// VariableTypeIcon — a variable's type glyph plus optional MODIFIER markers (§refinement 3):
//   • array    → a compact "[]" list marker (the variable is a collection — a multi / repeater),
//   • nullable → a compact "?" marker (the variable may resolve empty at run time).
// The markers sit as tiny superscripts adjacent to the icon; each is announced (title + sr-only text)
// and reads from the shared type tokens so it is legible in light + dark alike. Shared by the editor
// VariableChip, the value-or-variable chip token, and the picker tree rows so a variable's type reads
// with the SAME glyph + markers everywhere.
import Icon, { type IconName } from '../../primitives/Icon.vue';
import { useI18n } from '../../../app/i18n';

defineProps<{
  /** The already-resolved type icon (callers map type/base → icon). */
  icon: IconName;
  /** The variable may resolve empty → a "?" marker. */
  nullable?: boolean;
  /** The variable is a collection → a "[]" list marker. */
  array?: boolean;
  /**
   * The human TYPE name, announced sr-only (the glyph itself is decorative/aria-hidden). Pass it
   * wherever the type is not already visible as text, so a screen reader still hears "Number" —
   * the chip relied on the old Icon's label before this component existed.
   */
  typeLabel?: string;
}>();

const { t } = useI18n();
</script>

<template>
  <span class="next-vti">
    <Icon :name="icon" class="next-vti__icon" aria-hidden="true" />
    <span v-if="typeLabel" class="sr-only">{{ typeLabel }}</span>
    <span v-if="array || nullable" class="next-vti__markers">
      <span
        v-if="array"
        class="next-vti__marker"
        data-marker="list"
        :title="t('workflows.variable.marker.list')"
      >
        <span aria-hidden="true">[]</span>
        <span class="sr-only">{{ t('workflows.variable.marker.list') }}</span>
      </span>
      <span
        v-if="nullable"
        class="next-vti__marker"
        data-marker="optional"
        :title="t('workflows.variable.marker.optional')"
      >
        <span aria-hidden="true">?</span>
        <span class="sr-only">{{ t('workflows.variable.marker.optional') }}</span>
      </span>
    </span>
  </span>
</template>

<style scoped>
.next-vti {
  display: inline-flex;
  align-items: center;
  gap: 0.0625rem;
}
.next-vti__icon {
  flex-shrink: 0;
}
/* The markers cluster as tiny superscripts hugging the icon. */
.next-vti__markers {
  display: inline-flex;
  align-items: flex-start;
  align-self: flex-start;
  gap: 0.0625rem;
  margin-top: -0.125rem;
}
.next-vti__marker {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 0.75em;
  height: 0.75em;
  padding-inline: 0.15em;
  border-radius: var(--radius-next-xs);
  background-color: color-mix(in srgb, currentColor 14%, transparent);
  font-size: 0.62em;
  font-weight: var(--font-weight-next-bold);
  line-height: 1;
}
</style>
