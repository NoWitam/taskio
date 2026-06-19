<script setup lang="ts">
// LocaleSwitcher — a compact 2-segment PL/EN toggle for the "next" frontend.
//
// Mirrors the StyleguideView theme-preference segmented control visually. Uses
// the singleton `useI18n()` so flipping the language re-renders every translated
// string instantly (the active locale is a shared reactive ref).
//
// A11y: a labelled `role="group"`; each segment is a real button with
// `aria-pressed` reflecting the active locale. The group + buttons carry
// translated aria-labels so the control itself respects the i18n discipline.
import { useI18n, type NextLocale } from '../app/i18n';

const { t, currentLocale, setLocale, availableLocales } = useI18n();

const LABEL: Record<NextLocale, string> = { pl: 'PL', en: 'EN' };

function nameFor(locale: NextLocale): string {
  return locale === 'pl' ? t('language.polish', 'Polski') : t('language.english', 'English');
}
</script>

<template>
  <div
    class="flex items-center rounded-next-md border border-next-border bg-next-bg p-next-0_5"
    role="group"
    :aria-label="t('language.label', 'Language')"
  >
    <button
      v-for="loc in availableLocales"
      :key="loc"
      type="button"
      class="rounded-next-sm px-next-2 py-next-1 text-next-xs font-next-medium uppercase transition-colors duration-[var(--duration-next-fast)]"
      :class="
        currentLocale === loc
          ? 'bg-next-primary text-next-primary-foreground'
          : 'text-next-muted-foreground hover:text-next-fg'
      "
      :aria-pressed="currentLocale === loc"
      :aria-label="nameFor(loc)"
      @click="setLocale(loc)"
    >
      {{ LABEL[loc] }}
    </button>
  </div>
</template>
