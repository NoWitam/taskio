<script setup lang="ts">
// Gallery: Drawer (Sheet) — a side panel that mirrors Modal's overlay mechanics.
//
// Demonstrates opening from each side, sizes, a detail-panel layout, the
// stacking-aware overlay (a Drawer over a Modal), and the API + a11y. All visible
// text routes through t().
import { ref } from 'vue';
import Drawer from '../../ui/overlay/Drawer.vue';
import Modal from '../../ui/overlay/Modal.vue';
import Button from '../../ui/primitives/Button.vue';
import { useI18n } from '../../app/i18n';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import StoryGrid from '../StoryGrid.vue';
import StoryCell from '../StoryCell.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const { t } = useI18n();

type Side = 'left' | 'right' | 'top' | 'bottom';
type Size = 'sm' | 'md' | 'lg' | 'xl' | 'full';

const openSide = ref<Side | null>(null);
const openSize = ref<Size | null>(null);
const detailOpen = ref(false);
const stackModal = ref(false);
const stackDrawer = ref(false);

const sides: Side[] = ['left', 'right', 'top', 'bottom'];
const sizes: Size[] = ['sm', 'md', 'lg', 'xl', 'full'];

const propRows: ApiRow[] = [
  { name: 'side', type: "'left' | 'right' | 'top' | 'bottom'", default: "'right'", description: 'Edge the panel slides in from.' },
  { name: 'size', type: "'sm' | 'md' | 'lg' | 'xl' | 'full'", default: "'md'", description: 'Cross-axis extent (width for left/right, height for top/bottom).' },
  { name: 'closeOnEsc', type: 'boolean', default: 'true', description: 'Escape closes the topmost overlay only.' },
  { name: 'closeOnScrim', type: 'boolean', default: 'true', description: 'Clicking the scrim closes the topmost overlay only.' },
  { name: 'showClose', type: 'boolean', default: 'true', description: 'Show the built-in ✕ in the header.' },
  { name: 'ariaLabel', type: 'string', default: '—', description: 'Accessible label when no #title slot is given.' },
  { name: 'v-model:open', type: 'boolean', default: 'false', description: 'Open state.' },
];
const eventRows: ApiRow[] = [
  { name: 'open', type: '—', description: 'Emitted when the drawer opens.' },
  { name: 'close', type: '—', description: 'Emitted when the drawer closes.' },
];
const slotRows: ApiRow[] = [
  { name: 'title', type: '—', description: 'Header title (labels the dialog).' },
  { name: 'description', type: '—', description: 'Sub-text under the title (describes the dialog).' },
  { name: 'default', type: '—', description: 'Panel body.' },
  { name: 'footer', type: '{ close }', description: 'Footer actions; receives a close() helper.' },
];
</script>

<template>
  <StoryPage
    title="Drawer (Sheet)"
    description="A side panel that slides in from any edge. It mirrors Modal's overlay mechanics: a translucent scrim, the shared overlay stack (Esc / scrim dismiss the topmost only), focus trap, body-scroll lock, and stacking-aware z-index — so a Drawer can open above a Modal. Use it for detail panels and contextual editing."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li><code>role="dialog"</code> + <code>aria-modal="true"</code>, labelled by the #title and described by #description when present.</li>
        <li>Focus is trapped while open (reused <code>useFocusTrap</code>) and returns to the trigger on close.</li>
        <li><kbd>Esc</kbd> and scrim-click close the <strong>topmost</strong> overlay only (shared <code>useOverlayStack</code>); body scroll is locked while any overlay is open.</li>
        <li>The slide transform is applied to the panel itself — never to the teleported wrapper (it holds a <code>position: fixed</code> child); the scrim animates opacity only.</li>
      </ul>
    </template>

    <StorySection title="Sides" description="Open from left, right, top, or bottom.">
      <StoryGrid>
        <StoryCell v-for="s in sides" :key="s" :label="s">
          <Button variant="outline" size="sm" @click="openSide = s">{{ t('common.open', 'Open') }} {{ s }}</Button>
        </StoryCell>
      </StoryGrid>
      <Drawer
        v-for="s in sides"
        :key="`d-${s}`"
        :open="openSide === s"
        :side="s"
        @close="openSide = null"
      >
        <template #title>{{ s }} drawer</template>
        <template #description>This panel slid in from the {{ s }} edge.</template>
        <p class="text-next-sm text-next-muted-foreground">
          {{ t('common.loading', 'Loading…') }} — replace with real content. The
          drawer body scrolls when tall.
        </p>
        <template #footer="{ close }">
          <Button variant="ghost" size="sm" @click="close">{{ t('common.cancel', 'Cancel') }}</Button>
          <Button size="sm" @click="close">{{ t('common.done', 'Done') }}</Button>
        </template>
      </Drawer>
    </StorySection>

    <StorySection title="Sizes" description="sm / md / lg / xl / full (right side shown).">
      <StoryGrid>
        <StoryCell v-for="sz in sizes" :key="sz" :label="sz">
          <Button variant="outline" size="sm" @click="openSize = sz">{{ sz }}</Button>
        </StoryCell>
      </StoryGrid>
      <Drawer
        v-for="sz in sizes"
        :key="`sz-${sz}`"
        :open="openSize === sz"
        side="right"
        :size="sz"
        @close="openSize = null"
      >
        <template #title>Size {{ sz }}</template>
        <p class="text-next-sm text-next-muted-foreground">A {{ sz }} right-side drawer.</p>
      </Drawer>
    </StorySection>

    <StorySection title="Detail panel" description="A realistic detail/edit panel with a footer.">
      <Button @click="detailOpen = true">{{ t('common.open', 'Open') }} detail panel</Button>
      <Drawer v-model:open="detailOpen" side="right" size="md">
        <template #title>Task details</template>
        <template #description>Inspect and edit without leaving the list.</template>
        <div class="flex flex-col gap-next-3 text-next-sm">
          <p class="text-next-muted-foreground">
            Detail panels keep context: the dimmed list stays visible behind the
            translucent scrim.
          </p>
        </div>
        <template #footer="{ close }">
          <Button variant="ghost" size="sm" @click="close">{{ t('common.cancel', 'Cancel') }}</Button>
          <Button size="sm" @click="close">{{ t('common.save', 'Save') }}</Button>
        </template>
      </Drawer>
    </StorySection>

    <StorySection title="Stacking over a Modal" description="A Drawer opened from inside a Modal stacks above it; Esc closes the Drawer first.">
      <Button variant="outline" @click="stackModal = true">{{ t('common.open', 'Open') }} modal</Button>
      <Modal v-model:open="stackModal">
        <template #title>A modal</template>
        <p class="mb-next-3 text-next-sm text-next-muted-foreground">
          Open a drawer above this modal. Pressing Escape closes the drawer first,
          then the modal.
        </p>
        <Button size="sm" @click="stackDrawer = true">{{ t('common.open', 'Open') }} drawer</Button>
        <Drawer v-model:open="stackDrawer" side="right" size="sm">
          <template #title>Stacked drawer</template>
          <p class="text-next-sm text-next-muted-foreground">I render above the modal.</p>
        </Drawer>
      </Modal>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
        <ApiTable title="Slots" type-header="Slot props" :rows="slotRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
