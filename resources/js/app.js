import './bootstrap';
import { createApp } from 'vue';
import App from './App.vue';
import router from './router';
import { pinia } from './store';
import { useLocaleStore } from './store/locale';

const app = createApp(App);

app.use(pinia);
app.use(router);

// Initialize locale
const localeStore = useLocaleStore(pinia);
localeStore.setLocale(localeStore.currentLocale);

app.mount('#app');
