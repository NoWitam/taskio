<script setup lang="ts">
import { ref } from 'vue';
import Badge from '../../ui/primitives/Badge.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const variants = ['neutral', 'primary', 'success', 'warning', 'danger', 'info', 'modified'] as const;

const tags = ref(['Design', 'Frontend', 'Vue', 'Accessibility']);
function removeTag(tag: string) {
  tags.value = tags.value.filter((t) => t !== tag);
}

const propRows: ApiRow[] = [
  { name: 'variant', type: "'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info' | 'modified'", default: "'neutral'", description: 'Color family. `modified` = the project-wide "changed since a snapshot" semantic (see Foundations → Design Tokens).' },
  { name: 'tone', type: "'solid' | 'subtle'", default: "'subtle'", description: 'Filled vs tinted surface.' },
  { name: 'size', type: "'sm' | 'md'", default: "'md'", description: 'Chip height + text scale.' },
  { name: 'icon', type: 'IconName', default: '—', description: 'Leading icon.' },
  { name: 'dot', type: 'boolean', default: 'false', description: 'Leading status dot (shape signal in addition to color).' },
  { name: 'removable', type: 'boolean', default: 'false', description: 'Renders a focusable ✕ that emits `remove`.' },
  { name: 'removeLabel', type: 'string', default: "'Remove'", description: 'aria-label for the remove control.' },
  { name: 'truncate', type: 'boolean', default: 'false', description: 'Caps width and ellipses the label.' },
];

const eventRows: ApiRow[] = [
  { name: 'remove', type: '()', description: 'Emitted when the removable ✕ is activated (click / Enter / Space).' },
];

const slotRows: ApiRow[] = [
  { name: 'default', type: 'label', description: 'Badge text.' },
];
</script>

<template>
  <StoryPage
    title="Badge"
    description="A compact status/label chip. Seven color families × solid/subtle tones × two sizes, with icon, dot, count, removable, and truncated states."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>State is never color-only — pair a status badge with text, a leading icon, or a dot (shape signal).</li>
        <li>Removable badges expose a focusable <code>&lt;button&gt;</code> ✕ with an <code>aria-label</code>; <kbd>Enter</kbd> / <kbd>Space</kbd> remove it.</li>
        <li>Purely decorative badges duplicated in nearby text may be hidden from assistive tech by the consumer.</li>
      </ul>
    </template>

    <StorySection title="Subtle tone" description="Default tone — tinted surface with subtle foreground.">
      <StoryGrid>
        <StoryCell v-for="v in variants" :key="v" :label="v">
          <Badge :variant="v" tone="subtle">{{ v }}</Badge>
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Solid tone" description="Filled surface with high-contrast foreground.">
      <StoryGrid>
        <StoryCell v-for="v in variants" :key="v" :label="v">
          <Badge :variant="v" tone="solid">{{ v }}</Badge>
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Sizes">
      <StoryGrid align="center">
        <StoryCell label="sm"><Badge size="sm" variant="primary">Small</Badge></StoryCell>
        <StoryCell label="md"><Badge size="md" variant="primary">Medium</Badge></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="With icon" description="Leading icon reinforces meaning beyond color.">
      <StoryGrid>
        <StoryCell label="success"><Badge variant="success" icon="check-circle">Published</Badge></StoryCell>
        <StoryCell label="warning"><Badge variant="warning" icon="alert-triangle">Draft</Badge></StoryCell>
        <StoryCell label="danger"><Badge variant="danger" icon="x-circle" tone="solid">Failed</Badge></StoryCell>
        <StoryCell label="info"><Badge variant="info" icon="info">Beta</Badge></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="With dot" description="A status dot adds a shape signal alongside the color.">
      <StoryGrid>
        <StoryCell label="online"><Badge variant="success" dot>Online</Badge></StoryCell>
        <StoryCell label="away"><Badge variant="warning" dot>Away</Badge></StoryCell>
        <StoryCell label="offline"><Badge variant="neutral" dot>Offline</Badge></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Modified (diff / changed)" description="The project-wide &quot;this value drifted from a captured snapshot&quot; semantic — never a substitute for `warning`. First shipped in the Workflows run detail's submission diff (Workflows → Run detail → a form-submission trigger).">
      <StoryGrid>
        <StoryCell label="subtle"><Badge variant="modified" tone="subtle" icon="pencil">Changed</Badge></StoryCell>
        <StoryCell label="solid"><Badge variant="modified" tone="solid" icon="pencil">Changed</Badge></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Count" description="Numeric badges for unread counts / totals.">
      <StoryGrid align="center">
        <StoryCell label="primary"><Badge variant="primary" tone="solid" size="sm">3</Badge></StoryCell>
        <StoryCell label="danger"><Badge variant="danger" tone="solid" size="sm">12</Badge></StoryCell>
        <StoryCell label="neutral"><Badge variant="neutral" size="sm">99+</Badge></StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Removable" description="Keyboard-removable filter chips. Tab to a chip’s ✕ and press Enter; chips disappear from the list.">
      <div class="flex flex-wrap gap-next-2">
        <Badge
          v-for="tag in tags"
          :key="tag"
          variant="primary"
          removable
          :remove-label="`Remove ${tag}`"
          @remove="removeTag(tag)"
        >
          {{ tag }}
        </Badge>
        <span v-if="!tags.length" class="text-next-sm text-next-muted-foreground">
          All tags removed — refresh the page to reset.
        </span>
      </div>
    </StorySection>

    <StorySection title="Truncated" description="Long labels are capped with an ellipsis.">
      <div class="flex max-w-xs flex-wrap gap-next-2">
        <Badge variant="info" truncate>A very long label that should be clipped</Badge>
        <Badge variant="neutral" truncate icon="user">organization-owner-permissions</Badge>
      </div>
    </StorySection>

    <StorySection title="Realistic usage" description="Form status row, the way it appears in a list.">
      <div class="flex items-center justify-between rounded-next-lg border border-next-border bg-next-bg p-next-3">
        <div class="flex items-center gap-next-2">
          <span class="text-next-sm font-next-medium">Customer onboarding survey</span>
          <Badge variant="success" dot size="sm">Live</Badge>
          <Badge variant="info" tone="subtle" size="sm" icon="eye">Indexed</Badge>
        </div>
        <Badge variant="neutral" size="sm">142 responses</Badge>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
        <ApiTable title="Slots" type-header="Content" :rows="slotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
