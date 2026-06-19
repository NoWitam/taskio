<script setup lang="ts">
import Icon from '../../ui/primitives/Icon.vue';
import { ICON_NAMES } from '../../ui/primitives/icons';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const propRows: ApiRow[] = [
  { name: 'name', type: 'IconName', default: '— (required)', description: 'Which icon to render (see grid below).' },
  { name: 'label', type: 'string', default: '—', description: 'Accessible label. When set, role="img"; when omitted, the icon is decorative (aria-hidden).' },
  { name: 'strokeWidth', type: 'number', default: '2', description: 'Stroke width in the 24px viewBox.' },
];
</script>

<template>
  <StoryPage
    title="Icon"
    description="Inline SVG icons from a local name→path map (no icon-library dependency). 24px stroke geometry drawn with currentColor, sized via font-size (1em)."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Icons are decorative by default (<code>aria-hidden</code>). Pass <code>label</code> only when the icon conveys meaning on its own — then it gets <code>role="img"</code> + <code>aria-label</code>.</li>
        <li>Color follows <code>currentColor</code>, so an icon adapts to the surrounding text token in light and dark automatically.</li>
        <li>Size follows font-size — use a text utility (e.g. <code>text-next-2xl</code>) on the icon or its parent.</li>
      </ul>
    </template>

    <StorySection title="All icons" description="The full local set available to Tier 1 components and the gallery.">
      <div class="grid grid-cols-3 gap-next-3 next-sm:grid-cols-4 next-md:grid-cols-6">
        <div
          v-for="name in ICON_NAMES"
          :key="name"
          class="flex flex-col items-center gap-next-2 rounded-next-md border border-next-border bg-next-bg p-next-3"
        >
          <Icon :name="name" class="text-next-2xl" />
          <span class="text-center font-next-mono text-next-2xs text-next-muted-foreground">{{ name }}</span>
        </div>
      </div>
    </StorySection>

    <StorySection title="Sizing" description="Driven by the text utility on the icon.">
      <div class="flex items-end gap-next-4">
        <Icon name="star" class="text-next-sm" />
        <Icon name="star" class="text-next-lg" />
        <Icon name="star" class="text-next-2xl" />
        <Icon name="star" class="text-next-4xl" />
      </div>
    </StorySection>

    <StorySection title="Color (currentColor)" description="Icons inherit the surrounding text color token.">
      <div class="flex items-center gap-next-4">
        <span class="text-next-primary"><Icon name="heart" class="text-next-2xl" /></span>
        <span class="text-next-success"><Icon name="check-circle" class="text-next-2xl" /></span>
        <span class="text-next-warning"><Icon name="alert-triangle" class="text-next-2xl" /></span>
        <span class="text-next-danger"><Icon name="x-circle" class="text-next-2xl" /></span>
        <span class="text-next-muted-foreground"><Icon name="info" class="text-next-2xl" /></span>
      </div>
    </StorySection>

    <StorySection title="Meaningful vs decorative">
      <div class="flex items-center gap-next-6">
        <span class="inline-flex items-center gap-next-2 text-next-sm">
          <Icon name="check" /> Saved (icon decorative, text carries meaning)
        </span>
        <Icon name="bell" label="Notifications" class="text-next-2xl text-next-fg" />
      </div>
    </StorySection>

    <StorySection title="API">
      <ApiTable title="Props" :rows="propRows" show-default />
    </StorySection>
  </StoryPage>
</template>
