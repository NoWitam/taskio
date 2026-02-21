import { useLocaleStore } from '@/store/locale';

export const useI18n = () => {
  const localeStore = useLocaleStore();

  return {
    t: localeStore.t,
    currentLocale: localeStore.currentLocale,
    setLocale: localeStore.setLocale,
    locale: localeStore.currentLocale,
  };
};
