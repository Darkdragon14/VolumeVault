<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title inertia>{{ config('app.name', 'VolumeVault') }}</title>
        <link rel="icon" type="image/png" sizes="64x64" href="/favicon.png">
        <script>
            (() => {
                const userTheme = @json(auth()->check() ? auth()->user()->theme : null);
                const storedTheme = (() => {
                    try {
                        return window.localStorage.getItem('volumevault-theme');
                    } catch {
                        return null;
                    }
                })();
                const theme = ['light', 'dark'].includes(userTheme)
                    ? userTheme
                    : (['light', 'dark'].includes(storedTheme) ? storedTheme : 'dark');

                document.documentElement.classList.toggle('dark', theme === 'dark');
                document.documentElement.dataset.theme = theme;
                document.documentElement.style.colorScheme = theme;
                try {
                    window.localStorage.setItem('volumevault-theme', theme);
                } catch {}
            })();
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="bg-slate-50 text-slate-950 antialiased dark:bg-slate-950 dark:text-slate-100">
        @inertia
    </body>
</html>
