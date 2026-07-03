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
                'resources/css/app.css',
                'resources/js/app.js',
                // The isolated "next" SPA (served at /next via next.blade.php).
                // Without this entry the built manifest lacks it and /next 500s
                // whenever the Vite dev server isn't running.
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
