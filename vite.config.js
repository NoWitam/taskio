import { defineConfig } from 'vite';
import { fileURLToPath, URL } from 'node:url';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    plugins: [
        tailwindcss(),
        laravel({
            input: [
                // The "next" SPA is the whole frontend now (served at /next via
                // next.blade.php; the legacy /app bundle was decommissioned).
                'resources/js/next/main.ts',
            ],
            refresh: true,
        }),
        vue(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
