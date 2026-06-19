<script setup lang="ts">
// Interactive styleguide gallery for the "next" design system.
//
// Layout: a fixed left nav (sections from the registry), a top bar with a
// light/dark theme toggle, and a content area that renders the selected story.
// Empty sections show a "coming soon" hint so the intended structure is visible
// before components land.
import { computed, ref, shallowRef, watch, onMounted, type Component } from 'vue';
import Icon from '../ui/primitives/Icon.vue';
import LocaleSwitcher from '../ui/LocaleSwitcher.vue';
import { useTheme, type ThemePreference } from '../app/lib/theme';
import { useI18n } from '../app/i18n';
import {
  groupedStories,
  storyId,
  stories,
  type StyleguideStory,
} from './registry';

const { preference, isDark, setTheme } = useTheme();
const { t } = useI18n();

const sections = groupedStories();
const navOpen = ref(false);

// Selected story (default: first available).
const activeId = ref<string>(stories.length ? storyId(stories[0]) : '');
const activeComponent = shallowRef<Component | null>(null);

const activeStory = computed<StyleguideStory | undefined>(() =>
  stories.find((s) => storyId(s) === activeId.value),
);

async function loadStory(story: StyleguideStory): Promise<void> {
  const resolved = await story.component();
  // Support both `() => import(...)` (module with default) and direct components.
  activeComponent.value =
    (resolved as { default?: Component }).default ?? (resolved as Component);
}

function selectStory(story: StyleguideStory): void {
  activeId.value = storyId(story);
  navOpen.value = false;
}

watch(
  activeStory,
  (story) => {
    if (story) void loadStory(story);
    else activeComponent.value = null;
  },
  { immediate: false },
);

onMounted(() => {
  if (activeStory.value) void loadStory(activeStory.value);
});

const themeOptions: { value: ThemePreference; label: string }[] = [
  { value: 'light', label: 'Light' },
  { value: 'system', label: 'System' },
  { value: 'dark', label: 'Dark' },
];

function cycleTheme(): void {
  setTheme(isDark.value ? 'light' : 'dark');
}
</script>

<template>
  <div class="flex min-h-screen flex-col bg-next-bg text-next-fg">
    <!-- Top bar -->
    <header
      class="sticky top-next-0 flex items-center gap-next-3 border-b border-next-border bg-next-card px-next-4 py-next-3 shadow-next-xs"
      :style="{ zIndex: 'var(--z-next-sticky)' }"
    >
      <button
        type="button"
        class="rounded-next-md p-next-2 text-next-fg hover:bg-next-accent hover:text-next-accent-foreground next-lg:hidden"
        aria-label="Toggle navigation"
        @click="navOpen = !navOpen"
      >
        <Icon name="menu" class="text-next-xl" />
      </button>

      <div class="flex items-center gap-next-2">
        <span class="text-next-primary"><Icon name="palette" class="text-next-2xl" /></span>
        <div class="leading-next-tight">
          <p class="text-next-base font-next-semibold">Taskio next</p>
          <p class="text-next-xs text-next-muted-foreground">Design system styleguide</p>
        </div>
      </div>

      <div class="ml-auto flex items-center gap-next-3">
        <!-- Language switcher (re-renders translated strings live) -->
        <LocaleSwitcher />

        <!-- Explicit preference selector (light / system / dark) -->
        <div
          class="hidden items-center rounded-next-md border border-next-border bg-next-bg p-next-0_5 next-sm:flex"
          role="group"
          aria-label="Theme preference"
        >
          <button
            v-for="opt in themeOptions"
            :key="opt.value"
            type="button"
            class="rounded-next-sm px-next-2 py-next-1 text-next-xs font-next-medium transition-colors duration-[var(--duration-next-fast)]"
            :class="
              preference === opt.value
                ? 'bg-next-primary text-next-primary-foreground'
                : 'text-next-muted-foreground hover:text-next-fg'
            "
            :aria-pressed="preference === opt.value"
            @click="setTheme(opt.value)"
          >
            {{ opt.label }}
          </button>
        </div>

        <!-- Quick toggle (always visible) -->
        <button
          type="button"
          class="rounded-next-md border border-next-border bg-next-bg p-next-2 text-next-fg transition-colors duration-[var(--duration-next-fast)] hover:bg-next-accent hover:text-next-accent-foreground"
          :aria-label="isDark ? 'Switch to light theme' : 'Switch to dark theme'"
          @click="cycleTheme"
        >
          <Icon :name="isDark ? 'sun' : 'moon'" class="text-next-lg" />
        </button>
      </div>
    </header>

    <div class="flex flex-1 next-lg:gap-next-0">
      <!-- Left nav -->
      <aside
        class="border-r border-next-border bg-next-card"
        :class="[
          navOpen ? 'block' : 'hidden',
          'next-lg:block next-lg:w-64 next-lg:shrink-0',
        ]"
      >
        <nav class="flex flex-col gap-next-5 p-next-4" aria-label="Styleguide sections">
          <div v-for="group in sections" :key="group.section">
            <p
              class="mb-next-2 px-next-2 text-next-2xs font-next-semibold uppercase tracking-next-wide text-next-muted-foreground"
            >
              {{ group.section }}
            </p>

            <ul v-if="group.stories.length" class="flex flex-col gap-next-px">
              <li v-for="story in group.stories" :key="storyId(story)">
                <button
                  type="button"
                  class="flex w-full items-center gap-next-2 rounded-next-md px-next-2 py-next-1_5 text-left text-next-sm transition-colors duration-[var(--duration-next-fast)]"
                  :class="
                    activeId === storyId(story)
                      ? 'bg-next-primary-subtle text-next-primary-subtle-foreground font-next-medium'
                      : 'text-next-fg hover:bg-next-accent hover:text-next-accent-foreground'
                  "
                  :aria-current="activeId === storyId(story) ? 'page' : undefined"
                  @click="selectStory(story)"
                >
                  <Icon
                    v-if="activeId === storyId(story)"
                    name="chevron-right"
                    class="text-next-sm"
                  />
                  <span :class="{ 'pl-next-5': activeId !== storyId(story) }">{{ story.name }}</span>
                </button>
              </li>
            </ul>

            <p
              v-else
              class="px-next-2 text-next-xs italic text-next-muted-foreground"
            >
              Coming soon
            </p>
          </div>
        </nav>
      </aside>

      <!-- Content -->
      <main class="min-w-0 flex-1 px-next-4 py-next-6 next-lg:px-next-8">
        <component :is="activeComponent" v-if="activeComponent" />
        <p v-else class="text-next-sm text-next-muted-foreground">
          Select a page from the navigation.
        </p>
      </main>
    </div>
  </div>
</template>
