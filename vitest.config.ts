import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  plugins: [vue()],
  test: {
    environment: 'node',
    include: ['resources/js/components/editors/MarkdownEditor/__tests__/**/*.spec.ts'],
  },
});
