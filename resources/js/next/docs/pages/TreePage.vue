<script setup lang="ts">
// Gallery: Tree — expand/collapse, single + multiple selection, lazy children
// with a per-node spinner, guides, sizes, and disabled nodes. All text via t().
import { ref } from 'vue';
import Tree from '../../ui/data/Tree.vue';
import type { TreeNode } from '../../ui/data/tree';
import { useI18n } from '../../app/i18n';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

const { t } = useI18n();

const fileTree: TreeNode[] = [
  {
    id: 'src',
    label: 'src',
    icon: 'folder',
    children: [
      {
        id: 'components',
        label: 'components',
        icon: 'folder',
        badge: 3,
        children: [
          { id: 'button', label: 'Button.vue', icon: 'file-text' },
          { id: 'card', label: 'Card.vue', icon: 'file-text' },
          { id: 'tree', label: 'Tree.vue', icon: 'file-text' },
        ],
      },
      { id: 'main', label: 'main.ts', icon: 'file-text' },
      { id: 'app', label: 'App.vue', icon: 'file-text', disabled: true },
    ],
  },
  {
    id: 'public',
    label: 'public',
    icon: 'folder',
    children: [{ id: 'index', label: 'index.html', icon: 'file-text' }],
  },
  { id: 'readme', label: 'README.md', icon: 'file-text' },
];

// Uncontrolled-ish (we still bind to read state in the gallery).
const expanded1 = ref<string[]>(['src']);

// Single selection.
const expanded2 = ref<string[]>(['src', 'components']);
const selectedSingle = ref<string[]>(['button']);

// Multiple selection.
const expanded3 = ref<string[]>(['src', 'components']);
const selectedMulti = ref<string[]>(['button', 'tree']);

// Lazy loading.
const lazyTree: TreeNode[] = [
  { id: 'org', label: 'Organization', icon: 'users', loadable: true },
  { id: 'archive', label: 'Archive', icon: 'inbox', loadable: true },
];
const lazyExpanded = ref<string[]>([]);
let lazyCounter = 0;
function loadChildren(node: TreeNode): Promise<TreeNode[]> {
  return new Promise((resolve) => {
    setTimeout(() => {
      lazyCounter += 1;
      resolve([
        { id: `${node.id}-a-${lazyCounter}`, label: `Team ${lazyCounter}`, icon: 'users', loadable: true },
        { id: `${node.id}-b-${lazyCounter}`, label: 'Member A', icon: 'user' },
        { id: `${node.id}-c-${lazyCounter}`, label: 'Member B', icon: 'user' },
      ]);
    }, 800);
  });
}

const propRows: ApiRow[] = [
  { name: 'nodes', type: 'TreeNode[]', default: '—', description: '{ id, label, icon?, children?, disabled?, badge?, loadable? }.' },
  { name: 'selectable', type: "'single' | 'multiple' | null", default: 'null', description: 'Enable selection (and aria-multiselectable when multiple).' },
  { name: 'v-model:expanded', type: 'string[]', default: '[]', description: 'Expanded node ids (uncontrolled if unbound).' },
  { name: 'v-model:selected', type: 'string[]', default: '[]', description: 'Selected node ids.' },
  { name: 'loadChildren', type: '(node) => Promise<TreeNode[]>', default: '—', description: 'Async loader for `loadable` nodes (spinner while in flight).' },
  { name: 'size', type: "'sm' | 'md'", default: "'md'", description: 'Row scale.' },
  { name: 'guides', type: 'boolean', default: 'true', description: 'Draw indent / connector guide lines.' },
  { name: 'ariaLabel', type: 'string', default: '—', description: 'Accessible label for the tree.' },
];
const eventRows: ApiRow[] = [
  { name: 'select', type: 'TreeNode', description: 'A node was (de)selected.' },
  { name: 'expand / collapse', type: 'TreeNode', description: 'A node was expanded / collapsed.' },
];
</script>

<template>
  <StoryPage
    title="Tree"
    :description="t('story.tree.desc', 'A hierarchical tree (WAI-ARIA tree pattern): expand/collapse, optional single/multiple selection, lazy children, and connector guides. Full keyboard navigation.')"
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>{{ t('story.tree.a11y1', 'role="tree" / "treeitem" / "group"; aria-level, aria-expanded, aria-selected; one roving tabindex.') }}</li>
        <li>{{ t('story.tree.a11y2', '↑/↓ move visible nodes; →/← expand/collapse or move to child/parent; Home/End jump; Enter/Space select; type-ahead.') }}</li>
        <li>{{ t('story.tree.a11y3', 'Selection adds a subtle surface + token text; the chevron rotates — never color alone.') }}</li>
      </ul>
    </template>

    <StorySection :title="t('story.tree.basic', 'Navigation only')" :description="t('story.tree.basicDesc', 'No selection; Enter/Space toggles expansion. One node is disabled.')">
      <Tree v-model:expanded="expanded1" :nodes="fileTree" :aria-label="t('story.tree.files', 'File tree')" />
    </StorySection>

    <StorySection :title="t('story.tree.single', 'Single selection')">
      <Tree
        v-model:expanded="expanded2"
        v-model:selected="selectedSingle"
        :nodes="fileTree"
        selectable="single"
        :aria-label="t('story.tree.files', 'File tree')"
      />
      <p class="mt-next-3 font-next-mono text-next-xs text-next-muted-foreground">selected: {{ selectedSingle.join(', ') || '—' }}</p>
    </StorySection>

    <StorySection :title="t('story.tree.multi', 'Multiple selection')">
      <Tree
        v-model:expanded="expanded3"
        v-model:selected="selectedMulti"
        :nodes="fileTree"
        selectable="multiple"
        :aria-label="t('story.tree.files', 'File tree')"
      />
      <p class="mt-next-3 font-next-mono text-next-xs text-next-muted-foreground">selected: {{ selectedMulti.join(', ') || '—' }}</p>
    </StorySection>

    <StorySection :title="t('story.tree.lazy', 'Lazy children')" :description="t('story.tree.lazyDesc', 'Loadable nodes fetch children on first expand; a per-node spinner shows while loading.')">
      <Tree
        v-model:expanded="lazyExpanded"
        :nodes="lazyTree"
        :load-children="loadChildren"
        selectable="single"
        :aria-label="t('story.tree.orgs', 'Organizations')"
      />
    </StorySection>

    <StorySection :title="t('story.tree.small', 'Small, no guides')">
      <Tree :nodes="fileTree" size="sm" :guides="false" :aria-label="t('story.tree.files', 'File tree')" />
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-6">
        <ApiTable title="Props" :rows="propRows" show-default />
        <ApiTable title="Events" type-header="Payload" :rows="eventRows" />
      </div>
    </StorySection>
  </StoryPage>
</template>
