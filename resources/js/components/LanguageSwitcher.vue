<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from '@/composables/useI18n';
import DropdownMenu from '@/components/ui/DropdownMenu.vue';

const { t, currentLocale, setLocale } = useI18n();

const languages = computed(() => [
  { code: 'en', label: t('language.english'), flag: '🇬🇧' },
  { code: 'pl', label: t('language.polish'), flag: '🇵🇱' },
]);

const currentLanguage = computed(() => {
  const locale = typeof currentLocale === 'object' && 'value' in currentLocale 
    ? currentLocale.value 
    : currentLocale;
  return languages.value.find(lang => lang.code === locale) || languages.value[0];
});

const isCurrentLocale = (code: string) => {
  const currentCode = typeof currentLocale === 'object' && 'value' in currentLocale 
    ? currentLocale.value 
    : currentLocale;
  return currentCode === code;
};

const handleLanguageChange = (code: string) => {
  setLocale(code as 'en' | 'pl');
};
</script>

<template>
  <DropdownMenu align="end">
    <template #activator="{ toggle }">
      <button
        @click.stop="toggle"
        class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors cursor-pointer hover:bg-secondary/60 text-foreground/80 hover:text-foreground"
        :title="t('language.select')"
      >
        <span class="text-base">{{ currentLanguage?.flag }}</span>
        <span class="uppercase font-semibold text-xs">{{ currentLanguage?.code }}</span>
      </button>
    </template>

    <template #default="{ closeMenu }">
      <button
        v-for="language in languages"
        :key="language.code"
        type="button"
        class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm transition cursor-pointer hover:bg-secondary/60 focus:bg-secondary/60"
        :class="{ 'bg-primary/10': isCurrentLocale(language.code) }"
        @click="handleLanguageChange(language.code); closeMenu()"
      >
        <span class="text-lg">{{ language.flag }}</span>
        <span>{{ language.label }}</span>
        <span v-if="isCurrentLocale(language.code)" class="ml-auto text-xs text-primary">✓</span>
      </button>
    </template>
  </DropdownMenu>
</template>
