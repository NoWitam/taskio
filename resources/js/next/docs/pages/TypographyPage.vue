<script setup lang="ts">
import Text from '../../ui/primitives/Text.vue';
import Heading from '../../ui/primitives/Heading.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const textVariants = ['body', 'ui', 'caption', 'lead', 'mono'] as const;
const tones = ['default', 'muted', 'primary', 'danger', 'success'] as const;
const levels = [1, 2, 3, 4, 5, 6] as const;

const longLine =
  'This is a single line of text that is intentionally quite long so the truncation behavior is visible in the gallery.';
const paragraph =
  'Taskio lets teams build and ship forms fast. This paragraph is clamped to two lines to demonstrate the clamp behavior; the rest of the sentence is hidden behind an ellipsis once it overflows the allotted lines.';

const textProps: ApiRow[] = [
  { name: 'variant', type: "'body' | 'ui' | 'caption' | 'lead' | 'mono'", default: "'body'", description: 'Size + family + default weight.' },
  { name: 'tone', type: "'default' | 'muted' | 'primary' | 'danger' | 'success' | 'inverted'", default: "'default'", description: 'Color role.' },
  { name: 'as', type: 'string', default: "'p'", description: 'Rendered element (use `span` for inline).' },
  { name: 'truncate', type: 'boolean', default: 'false', description: 'Single-line ellipsis.' },
  { name: 'clamp', type: 'number', default: '—', description: 'Clamp to N lines (overrides truncate).' },
  { name: 'noWrap', type: 'boolean', default: 'false', description: 'Prevent wrapping (no ellipsis).' },
];

const headingProps: ApiRow[] = [
  { name: 'level', type: '1 | 2 | 3 | 4 | 5 | 6', default: '2', description: 'Semantic heading element rendered.' },
  { name: 'size', type: "'h1'…'h6'", default: 'matches level', description: 'Visual size, decoupled from the semantic level.' },
  { name: 'tone', type: "'default' | 'muted' | 'primary' | 'danger' | 'success'", default: "'default'", description: 'Color role.' },
  { name: 'balance', type: 'boolean', default: 'false', description: 'text-wrap: balance for tidy multi-line headings.' },
  { name: 'truncate', type: 'boolean', default: 'false', description: 'Single-line ellipsis.' },
];
</script>

<template>
  <StoryPage
    title="Text & Heading"
    description="Typography primitives. Text covers body/ui/caption/lead/mono with a tone scale and overflow controls. Heading decouples semantic level from visual size."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Heading <strong>level</strong> (the rendered <code>&lt;h1&gt;…&lt;h6&gt;</code>) is independent from visual <strong>size</strong> — never skip levels for styling; choose the level for document structure and the size for appearance.</li>
        <li>Text colors come from semantic tokens, so contrast holds in both themes; the <code>inverted</code> tone is for use on solid/primary surfaces.</li>
        <li>Truncation/clamp hide overflow visually only — the full text remains in the DOM for assistive tech.</li>
      </ul>
    </template>

    <StorySection title="Heading — visual sizes" description="h1 → h6 at their default size.">
      <div class="flex flex-col gap-next-2">
        <Heading v-for="l in levels" :key="l" :level="l">Heading level {{ l }}</Heading>
      </div>
    </StorySection>

    <StorySection title="Heading — level vs size decoupled" description="A semantic h2 styled at h4 size, and an h4 styled at h1 size.">
      <div class="flex flex-col gap-next-3">
        <Heading :level="2" size="h4">&lt;h2&gt; rendered at h4 size</Heading>
        <Heading :level="4" size="h1">&lt;h4&gt; rendered at h1 size</Heading>
      </div>
    </StorySection>

    <StorySection title="Heading — tones">
      <StoryGrid align="center">
        <StoryCell v-for="t in tones" :key="t" :label="t">
          <Heading :level="3" size="h4" :tone="t">Aa</Heading>
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Heading — balanced" description="Balance evens out line lengths for a tidier multi-line title.">
      <div class="max-w-xs">
        <Heading :level="3" size="h3" balance>
          A noticeably long heading that wraps onto multiple balanced lines
        </Heading>
      </div>
    </StorySection>

    <StorySection title="Text — variants">
      <div class="flex flex-col gap-next-3">
        <div v-for="v in textVariants" :key="v" class="flex items-baseline gap-next-4">
          <span class="w-16 shrink-0 font-next-mono text-next-2xs text-next-muted-foreground">{{ v }}</span>
          <Text :variant="v">The quick brown fox jumps over the lazy dog</Text>
        </div>
      </div>
    </StorySection>

    <StorySection title="Text — tones">
      <div class="flex flex-col gap-next-2">
        <Text v-for="t in tones" :key="t" :tone="t">Tone: {{ t }} — body copy at this color.</Text>
        <div class="rounded-next-md bg-next-primary p-next-3">
          <Text tone="inverted">inverted — body copy on a solid primary surface.</Text>
        </div>
      </div>
    </StorySection>

    <StorySection title="Text — overflow" description="Truncate (single line), clamp (N lines), and no-wrap.">
      <div class="flex flex-col gap-next-4">
        <div class="max-w-md">
          <Text variant="ui" class="mb-next-1 block text-next-muted-foreground">truncate</Text>
          <Text truncate>{{ longLine }}</Text>
        </div>
        <div class="max-w-md">
          <Text variant="ui" class="mb-next-1 block text-next-muted-foreground">clamp = 2</Text>
          <Text :clamp="2">{{ paragraph }}</Text>
        </div>
        <div class="max-w-md overflow-x-auto rounded-next-md border border-next-border p-next-2">
          <Text variant="ui" class="mb-next-1 block text-next-muted-foreground">no-wrap (scrolls)</Text>
          <Text no-wrap>{{ longLine }}</Text>
        </div>
      </div>
    </StorySection>

    <StorySection title="Realistic usage" description="A card header: heading + caption + lead.">
      <div class="flex flex-col gap-next-2 rounded-next-lg border border-next-border bg-next-bg p-next-4">
        <Text variant="caption">Survey · updated 2h ago</Text>
        <Heading :level="3" size="h3">Customer onboarding feedback</Heading>
        <Text variant="lead" tone="muted">
          Collect structured feedback during onboarding and route it to the right team automatically.
        </Text>
        <Text variant="mono" tone="muted">id: frm_8c2a91</Text>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Text props" :rows="textProps" show-default />
        <ApiTable title="Heading props" :rows="headingProps" show-default />
      </div>
    </StorySection>
  </StoryPage>
</template>
