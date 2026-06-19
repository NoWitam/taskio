<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'TaskIO') }} — next</title>

        {{-- Resolve the initial theme BEFORE the app mounts to avoid a flash of
             the wrong color scheme (FOUC). Mirrors the logic in
             resources/js/next/app/lib/theme.ts: 'light' | 'dark' | 'system',
             persisted under the 'next-theme' localStorage key, defaulting to
             the OS preference. The class is applied to the .next-root element. --}}
        <script>
            (function () {
                try {
                    var stored = localStorage.getItem('next-theme');
                    var prefersDark = window.matchMedia
                        && window.matchMedia('(prefers-color-scheme: dark)').matches;
                    var isDark = stored === 'dark' || ((stored === 'system' || !stored) && prefersDark);
                    if (isDark) {
                        document.documentElement.classList.add('next-pre-dark');
                    }
                } catch (e) { /* localStorage unavailable — fall back to light */ }
            })();
        </script>
        <style>
            /* Bridge the pre-mount flag onto the root element so tokens apply
               immediately. main.ts re-asserts the real class on mount. */
            html.next-pre-dark #next-app { color-scheme: dark; }
        </style>

        @vite(['resources/js/next/main.ts'])
    </head>
    <body class="next-body">
        <div id="next-app" class="next-root"></div>

        <script>
            // Promote the pre-mount dark flag onto the actual root element.
            (function () {
                if (document.documentElement.classList.contains('next-pre-dark')) {
                    var root = document.getElementById('next-app');
                    if (root) root.classList.add('dark');
                }
            })();
        </script>
    </body>
</html>
