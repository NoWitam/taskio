import './bootstrap';
import { createApp } from 'vue';
import App from './App.vue';
import router from './router';
import { pinia } from './store';
import { useLocaleStore } from './store/locale';
import { useUserStore } from './store/user';

const app = createApp(App);

app.use(pinia);
app.use(router);

// Initialize locale
const localeStore = useLocaleStore(pinia);
localeStore.setLocale(localeStore.currentLocale);

// Re-hydrate the auth session from a stored token, if present.
const userStore = useUserStore(pinia);
userStore.init();

app.mount('#app');
