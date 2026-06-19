<script setup lang="ts">
import Container from '../../ui/layout/Container.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const sizes = ['sm', 'md', 'lg', 'xl', 'full'] as const;

const propRows: ApiRow[] = [
  { name: 'size', type: "'sm' | 'md' | 'lg' | 'xl' | 'full'", default: "'lg'", description: 'Max content width. `full` removes the cap (gutters still apply).' },
  { name: 'as', type: 'string', default: "'div'", description: 'Rendered element — use `main` / `section` for landmark semantics.' },
  { name: 'flush', type: 'boolean', default: 'false', description: 'Remove the responsive horizontal gutters (content runs to the edges).' },
];

const slotRows: ApiRow[] = [
  { name: 'default', type: 'content', description: 'The page content the container centers + gutters.' },
];
</script>

<template>
  <StoryPage
    title="Container"
    description="A max-width content wrapper that centers children and applies responsive horizontal gutters (16px mobile → 24px sm → 32px lg). Structural only — no color of its own."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Container is presentational; set <code>as="main"</code> / <code>as="section"</code> when it should be a landmark.</li>
        <li>It never traps focus or changes tab order; it only constrains width + padding.</li>
      </ul>
    </template>

    <StorySection title="Sizes" description="Each size caps the measure at a different width. The tinted box shows the resolved content box; the page edges show the gutters.">
      <div class="flex flex-col gap-next-4">
        <div v-for="s in sizes" :key="s" class="rounded-next-md bg-next-muted py-next-2">
          <Container :size="s">
            <div class="rounded-next-md bg-next-primary-subtle px-next-3 py-next-2 text-center text-next-sm font-next-medium text-next-primary-subtle-foreground">
              size="{{ s }}"
            </div>
          </Container>
        </div>
      </div>
    </StorySection>

    <StorySection title="Responsive gutters" description="Resize the window: the horizontal padding steps up at the sm and lg breakpoints. `flush` removes them entirely.">
      <div class="flex flex-col gap-next-4">
        <div class="rounded-next-md bg-next-muted py-next-2">
          <Container size="md">
            <div class="rounded-next-md border border-next-border bg-next-card px-next-3 py-next-2 text-next-sm">
              Gutters: <code>px-next-4</code> → <code>next-sm:px-next-6</code> → <code>next-lg:px-next-8</code>
            </div>
          </Container>
        </div>
        <div class="rounded-next-md bg-next-muted py-next-2">
          <Container size="md" flush>
            <div class="rounded-next-md border border-next-border bg-next-card px-next-3 py-next-2 text-next-sm">
              <code>flush</code> — no gutters
            </div>
          </Container>
        </div>
      </div>
    </StorySection>

    <StorySection title="Realistic usage" description="A page region: a centered main column with consistent gutters at every breakpoint.">
      <div class="rounded-next-md bg-next-muted py-next-6">
        <Container as="section" size="lg">
          <div class="flex flex-col gap-next-2">
            <h4 class="text-next-xl font-next-semibold">Forms</h4>
            <p class="text-next-sm text-next-muted-foreground">All your published forms, centered within a readable measure.</p>
          </div>
        </Container>
      </div>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Slots" type-header="Content" :rows="slotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
