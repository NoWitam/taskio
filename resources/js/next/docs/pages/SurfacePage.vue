<script setup lang="ts">
import Surface from '../../ui/layout/Surface.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const bgs = ['bg', 'card', 'muted', 'popover', 'accent'] as const;
const radii = ['none', 'sm', 'md', 'lg', 'xl', '2xl'] as const;
const elevations = ['none', 'xs', 'sm', 'md', 'lg', 'xl'] as const;

const propRows: ApiRow[] = [
  { name: 'bg', type: "'bg' | 'card' | 'muted' | 'popover' | 'accent' | 'transparent'", default: "'card'", description: 'Background role (paired foreground applied where one exists).' },
  { name: 'border', type: 'boolean', default: 'false', description: '1px hairline border using the border token.' },
  { name: 'radius', type: "'none' | 'sm' | 'md' | 'lg' | 'xl' | '2xl' | 'full'", default: "'lg'", description: 'Corner radius token.' },
  { name: 'elevation', type: "'none' | 'xs' | 'sm' | 'md' | 'lg' | 'xl'", default: "'none'", description: 'Shadow token. Dark mode leans on border + bg lightness.' },
  { name: 'as', type: 'string', default: "'div'", description: 'Rendered element.' },
];

const slotRows: ApiRow[] = [
  { name: 'default', type: 'content', description: 'Surface contents (no padding is applied — compose Stack/Grid inside).' },
];
</script>

<template>
  <StoryPage
    title="Surface"
    description="The low-level themed background that Card and other panels build on. Four orthogonal axes — background role, border, radius, elevation — all token-driven, so dark mode is automatic. Carries no padding of its own."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Surface is presentational; it adds no roles or focus behavior. Wrap interactive content in real controls.</li>
        <li>Contrast is guaranteed by pairing each <code>bg</code> with its semantic foreground token (handled internally).</li>
      </ul>
    </template>

    <StorySection title="Background roles" description="Each role pairs with its foreground token; toggle the gallery theme to see dark mode swap automatically.">
      <StoryGrid align="start">
        <StoryCell v-for="b in bgs" :key="b" :label="`bg=${b}`">
          <Surface :bg="b" border radius="md" class="flex h-20 w-32 items-center justify-center p-next-3">
            <span class="text-next-sm font-next-medium">{{ b }}</span>
          </Surface>
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Radius" description="Corner radius tokens, from flush to fully rounded.">
      <StoryGrid align="start">
        <StoryCell v-for="r in radii" :key="r" :label="`radius=${r}`">
          <Surface bg="muted" :radius="r" border class="h-16 w-16" />
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Elevation" description="Shadow tokens. In dark mode, depth comes mostly from border + surface lightness, so shadows stay subtle.">
      <StoryGrid align="start">
        <StoryCell v-for="e in elevations" :key="e" :label="`elevation=${e}`">
          <Surface bg="card" :elevation="e" radius="lg" class="h-16 w-24" />
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="Composed" description="Border + radius + elevation together — the recipe Card uses for each variant.">
      <StoryGrid align="start">
        <StoryCell label="card · sm">
          <Surface bg="card" border elevation="sm" radius="lg" class="h-20 w-40 p-next-3">
            <p class="text-next-sm font-next-medium">Resting card</p>
            <p class="text-next-xs text-next-muted-foreground">border + sm shadow</p>
          </Surface>
        </StoryCell>
        <StoryCell label="card · md (elevated)">
          <Surface bg="card" elevation="md" radius="lg" class="h-20 w-40 p-next-3">
            <p class="text-next-sm font-next-medium">Elevated</p>
            <p class="text-next-xs text-next-muted-foreground">no border + md shadow</p>
          </Surface>
        </StoryCell>
        <StoryCell label="popover · lg">
          <Surface bg="popover" border elevation="lg" radius="lg" class="h-20 w-40 p-next-3">
            <p class="text-next-sm font-next-medium">Popover</p>
            <p class="text-next-xs text-next-muted-foreground">floating panel</p>
          </Surface>
        </StoryCell>
      </StoryGrid>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Slots" type-header="Content" :rows="slotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
