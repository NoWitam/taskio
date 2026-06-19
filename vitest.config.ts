import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  plugins: [vue()],
  test: {
    environment: 'node',
    // Per-file `// @vitest-environment happy-dom` annotations switch DOM-dependent
    // specs (the "next" frontend) to happy-dom; the default stays node.
    include: [
      'resources/js/components/editors/MarkdownEditor/__tests__/**/*.spec.ts',
      'resources/js/next/**/*.spec.ts',
    ],
  },
});
