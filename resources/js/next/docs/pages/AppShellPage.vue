<script setup lang="ts">
import { ref } from 'vue';
import AppShell from '../../ui/layout/AppShell.vue';
import Sidebar from '../../ui/layout/Sidebar.vue';
import SidebarSection from '../../ui/layout/SidebarSection.vue';
import SidebarItem from '../../ui/layout/SidebarItem.vue';
import Navbar from '../../ui/layout/Navbar.vue';
import Container from '../../ui/layout/Container.vue';
import Grid from '../../ui/layout/Grid.vue';
import Stack from '../../ui/layout/Stack.vue';
import Card from '../../ui/layout/Card.vue';
import Button from '../../ui/primitives/Button.vue';
import Icon from '../../ui/primitives/Icon.vue';
import Avatar from '../../ui/primitives/Avatar.vue';
import StoryPage from '../StoryPage.vue';
import StorySection from '../StorySection.vue';
import ApiTable, { type ApiRow } from '../ApiTable.vue';

// Self-contained fake nav (NOT wired to real routes). `active` is forced and
// items use plain hrefs so the demo never navigates away from the gallery.
const activeKey = ref('dashboard');
const nav = [
  { key: 'dashboard', label: 'Dashboard', icon: 'layout-dashboard' as const },
  { key: 'forms', label: 'Forms', icon: 'file-text' as const, badge: 12 },
  { key: 'inbox', label: 'Inbox', icon: 'inbox' as const, badge: 3 },
  { key: 'members', label: 'Members', icon: 'users' as const },
];

const shellPropRows: ApiRow[] = [
  { name: 'sidebarWidthClass', type: 'string', default: "'w-64'", description: 'Sidebar column / drawer width utility on desktop + drawer.' },
];
const shellSlotRows: ApiRow[] = [
  { name: 'sidebar', type: 'content', description: 'The nav. Rendered as a fixed column ≥ next-md, or an off-canvas drawer below.' },
  { name: 'navbar', type: 'content, scope { openDrawer, drawerOpen }', description: 'Top bar. Use the scope to wire the mobile menu button to the drawer.' },
  { name: 'default', type: 'content', description: 'Main page content (scrolls under the sticky navbar).' },
];

const sidebarSlotRows: ApiRow[] = [
  { name: 'brand', type: 'content', description: 'Top brand / logo / workspace switcher.' },
  { name: 'default', type: 'content', description: 'Scrollable nav body (SidebarItem / SidebarSection rows).' },
  { name: 'footer', type: 'content', description: 'Pinned footer (user menu, theme toggle).' },
];

const sidebarItemPropRows: ApiRow[] = [
  { name: 'label', type: 'string', default: '—', description: 'Visible row label (also the truncated accessible text).' },
  { name: 'icon', type: 'IconName', default: '—', description: 'Leading icon.' },
  { name: 'to', type: 'string | RouteLocation', default: '—', description: 'router-link target (renders <router-link>).' },
  { name: 'href', type: 'string', default: '—', description: 'Plain anchor href (used when `to` is absent).' },
  { name: 'active', type: 'boolean', default: 'false', description: 'Force active styling + aria-current="page".' },
  { name: 'badge', type: 'string | number', default: '—', description: 'Trailing count/badge.' },
  { name: 'disabled', type: 'boolean', default: 'false', description: 'Dim + inert.' },
];

const navbarSlotRows: ApiRow[] = [
  { name: 'leading', type: 'content', description: 'Mobile menu button + page title / breadcrumbs.' },
  { name: 'trailing', type: 'content', description: 'Actions, theme toggle, avatar / menu.' },
];
</script>

<template>
  <StoryPage
    title="App Shell"
    description="The single app layout — a fixed sidebar + sticky navbar + scrollable main area — composed from AppShell, Sidebar, SidebarItem, and Navbar. Below next-md the sidebar collapses to a focus-trapped off-canvas drawer."
  >
    <template #a11y>
      <ul class="ml-next-4 list-disc space-y-next-1">
        <li>Sidebar is a <code>&lt;nav&gt;</code> landmark; the navbar is a <code>&lt;header&gt;</code>; main content is a <code>&lt;main&gt;</code> landmark.</li>
        <li>The mobile drawer is a <code>role="dialog"</code> <code>aria-modal="true"</code>: focus is trapped inside, <kbd>Esc</kbd> and the scrim close it, and focus returns to the trigger on close. Body scroll is locked while open.</li>
        <li>SidebarItem marks the current route with <code>aria-current="page"</code> and pairs the active tint with a left accent bar (shape, not color alone).</li>
        <li>The menu button exposes <code>aria-expanded</code> reflecting the drawer state.</li>
      </ul>
    </template>

    <StorySection
      title="Miniature shell"
      description="A bounded, self-contained shell (fake nav, no real routing). Shrink the gallery / your viewport below next-md to see the sidebar collapse into a drawer — use the menu button to open it."
    >
      <!-- Bounded frame so the fixed/sticky shell renders inside the doc page.
           The shell's responsiveness keys off the real viewport breakpoints. -->
      <div class="h-[34rem] overflow-hidden rounded-next-lg border border-next-border">
        <AppShell class="h-full !min-h-0">
          <template #sidebar>
            <Sidebar aria-label="Demo">
              <template #brand>
                <span class="text-next-primary"><Icon name="palette" class="text-next-2xl" /></span>
                <span class="text-next-base font-next-semibold">Acme</span>
              </template>

              <SidebarSection label="Workspace">
                <SidebarItem
                  v-for="item in nav"
                  :key="item.key"
                  :label="item.label"
                  :icon="item.icon"
                  href="#"
                  :badge="item.badge"
                  :active="activeKey === item.key"
                  @click="activeKey = item.key"
                />
              </SidebarSection>

              <SidebarSection label="Settings" collapsible :default-open="false">
                <SidebarItem label="General" icon="settings" href="#" />
                <SidebarItem label="Billing" icon="calendar" href="#" />
                <SidebarItem label="Archived" icon="folder" href="#" disabled />
              </SidebarSection>

              <template #footer>
                <SidebarItem label="Help & docs" icon="help-circle" href="#" />
                <SidebarItem label="Sign out" icon="log-out" href="#" />
              </template>
            </Sidebar>
          </template>

          <template #navbar="{ openDrawer, drawerOpen }">
            <Navbar>
              <template #leading>
                <!-- Mobile menu button: shown below next-md, wired to the shell drawer. -->
                <button
                  type="button"
                  class="rounded-next-md p-next-2 text-next-fg hover:bg-next-accent hover:text-next-accent-foreground next-md:hidden"
                  aria-label="Open navigation"
                  :aria-expanded="drawerOpen"
                  @click="openDrawer"
                >
                  <Icon name="menu" class="text-next-xl" />
                </button>
                <h2 class="text-next-lg font-next-semibold">{{ nav.find((n) => n.key === activeKey)?.label }}</h2>
              </template>
              <template #trailing>
                <Button size="sm" variant="ghost" leading-icon="search" class="hidden next-sm:inline-flex">Search</Button>
                <Button size="icon" variant="ghost" aria-label="Notifications" leading-icon="bell" />
                <Avatar name="Avery Rivera" size="sm" />
              </template>
            </Navbar>
          </template>

          <!-- Main content -->
          <Container size="lg" as="section" class="py-next-6">
            <Stack gap="6">
              <Stack direction="horizontal" align="center" justify="between">
                <h3 class="text-next-2xl font-next-semibold">{{ nav.find((n) => n.key === activeKey)?.label }}</h3>
                <Button size="sm" leading-icon="plus">New</Button>
              </Stack>

              <Grid :cols="{ base: 1, sm: 2, lg: 4 }" gap="4">
                <Card v-for="stat in ['Forms', 'Submissions', 'Members', 'Active']" :key="stat">
                  <template #header><span class="text-next-sm text-next-muted-foreground">{{ stat }}</span></template>
                  <p class="text-next-2xl font-next-semibold">{{ Math.floor(Math.random() * 900 + 100) }}</p>
                </Card>
              </Grid>

              <Grid :cols="{ base: 1, lg: 2 }" gap="4">
                <Card variant="interactive" href="#" action-label="Open recent activity">
                  <template #header><h4 class="text-next-base font-next-semibold">Recent activity</h4></template>
                  <p class="text-next-sm text-next-muted-foreground">A whole-card link inside the shell.</p>
                  <template #footer>
                    <Icon name="calendar" class="text-next-sm" /><span>Updated just now</span>
                  </template>
                </Card>
                <Card>
                  <template #header><h4 class="text-next-base font-next-semibold">Notes</h4></template>
                  <p class="text-next-sm text-next-muted-foreground">Scroll this main area independently of the sticky navbar.</p>
                </Card>
              </Grid>
            </Stack>
          </Container>
        </AppShell>
      </div>
    </StorySection>

    <StorySection title="Responsive & drawer notes" description="How the shell adapts across breakpoints.">
      <ul class="ml-next-4 list-disc space-y-next-2 text-next-sm text-next-muted-foreground">
        <li><strong>≥ next-md (768px):</strong> the sidebar is a permanent fixed column (<code>sticky top-0 h-screen</code>); the menu button is hidden.</li>
        <li><strong>&lt; next-md:</strong> the column is hidden and the same <code>#sidebar</code> content renders inside an off-canvas drawer that slides in from the left with a scrim. It is a focus-trapped modal dialog and locks body scroll.</li>
        <li>The navbar is sticky at the <code>--z-next-sticky</code> layer; the scrim/drawer sit at <code>--z-next-overlay</code> / <code>--z-next-modal</code> so the drawer always rides above the navbar.</li>
        <li><strong>Dark mode:</strong> all surfaces (sidebar, navbar, cards) read from <code>bg-next-card</code> + <code>border-next-border</code>, so flipping the theme swaps every token — no per-component inversion.</li>
      </ul>
    </StorySection>

    <StorySection title="API">
      <div class="flex flex-col gap-next-8">
        <div class="flex flex-col gap-next-6">
          <h4 class="text-next-lg font-next-semibold">AppShell</h4>
          <ApiTable title="Props" :rows="shellPropRows" show-default />
          <ApiTable title="Slots" type-header="Content" :rows="shellSlotRows" />
        </div>
        <div class="flex flex-col gap-next-6">
          <h4 class="text-next-lg font-next-semibold">Sidebar &amp; SidebarItem</h4>
          <ApiTable title="Sidebar slots" type-header="Content" :rows="sidebarSlotRows" />
          <ApiTable title="SidebarItem props" :rows="sidebarItemPropRows" show-default />
        </div>
        <div class="flex flex-col gap-next-6">
          <h4 class="text-next-lg font-next-semibold">Navbar</h4>
          <ApiTable title="Slots" type-header="Content" :rows="navbarSlotRows" />
        </div>
      </div>
    </StorySection>
  </StoryPage>
</template>
