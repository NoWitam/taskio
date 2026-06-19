// Entry point for the isolated "next" frontend (ADR-0002).
// This bundle is fully independent of the legacy `resources/js/` app:
// it must NOT import anything from outside `resources/js/next/`.
import '../../css/next.css';

import { createApp } from 'vue';
import App from './App.vue';
import { router } from './app/router';
import { pinia } from './app/stores';
import { initTheme } from './app/lib/theme';
import { initI18n } from './app/i18n';
import { useAuthStore } from './app/stores/auth';

// Resolve + apply the persisted theme to `.next-root` before first paint of the
// mounted app (the Blade shell already applied a best-effort class to avoid FOUC).
initTheme();

// Resolve the persisted/browser locale and set `<html lang>` before first paint.
initI18n();

const app = createApp(App);

app.use(pinia);
app.use(router);

// Re-hydrate a stored session before the first guarded navigation resolves so a
// returning user lands authenticated (the router guard also awaits this, but
// kicking it off here warms the request in parallel with router setup). It is
// idempotent — guarded by the store's `ready` flag.
void useAuthStore(pinia).init();

app.mount('#next-app');
