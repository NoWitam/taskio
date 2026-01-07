<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'TaskIO') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div id="app"></div>

        <script>
            // Wstrzyknięcie danych inicjalizacyjnych z backendu
            window.__INITIAL_STATE__ = {
                user: @json(auth()->user()),
                permissions: @json(auth()->check() ? auth()->user()->getPermissions() : []),
                modules: @json(config('modules.available', ['dashboard', 'tasks'])),
                csrfToken: @json(csrf_token()),
            };
        </script>
    </body>
</html>
