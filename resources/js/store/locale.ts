import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import enLocale from '../locales/en.json';
import plLocale from '../locales/pl.json';
import { useAuth } from '@/composables/useAuth';

export type Locale = 'en' | 'pl';

const locales: Record<Locale, Record<string, any>> = {
  en: enLocale,
  pl: plLocale,
};

export const useLocaleStore = defineStore('locale', () => {
  const getInitialLocale = (): Locale => {
    // Check localStorage first
    const saved = localStorage.getItem('locale');
    if (saved === 'en' || saved === 'pl') {
      return saved as Locale;
    }
    
    // Check browser language
    const browserLang = navigator.language.split('-')[0];
    if (browserLang === 'pl') {
      return 'pl';
    }
    
    return 'en';
  };

  const currentLocale = ref<Locale>(getInitialLocale());

  const messages = computed(() => locales[currentLocale.value]);

  const setLocale = async (locale: Locale) => {
    if (locale in locales) {
      currentLocale.value = locale;
      localStorage.setItem('locale', locale);
      document.documentElement.lang = locale;

      // Send to backend if user is authenticated
      const { isAuthenticated } = useAuth();
      if (isAuthenticated.value) {
        try {
          await fetch('/api/user/locale', {
            method: 'PUT',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': (window as any).__INITIAL_STATE__?.csrfToken || '',
            },
            body: JSON.stringify({ locale }),
          });
        } catch (error) {
          console.error('Failed to save locale preference:', error);
        }
      }
    }
  };

  const t = (key: string, defaultValue?: string, params?: Record<string, string | number>): string => {
    let value: any = messages.value;
    const keys = key.split('.');

    for (const k of keys) {
      if (value && typeof value === 'object' && k in value) {
        value = value[k];
      } else {
        return defaultValue || key;
      }
    }

    if (typeof value !== 'string') {
      return defaultValue || key;
    }

    // Replace parameters in the string
    if (params) {
      let result = value;
      for (const [paramKey, paramValue] of Object.entries(params)) {
        result = result.replace(`{${paramKey}}`, String(paramValue));
      }
      return result;
    }

    return value;
  };

  return {
    currentLocale,
    messages,
    setLocale,
    t,
  };
});
