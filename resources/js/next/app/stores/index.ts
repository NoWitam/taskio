// Pinia root for the isolated "next" frontend.
//
// A dedicated instance — independent of the legacy app's Pinia. No domain
// stores exist yet; they will be added here as setup stores (`defineStore`
// with `ref`/`computed`) when the app pages land.
import { createPinia } from 'pinia';

export const pinia = createPinia();
