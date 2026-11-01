@php($theme = auth()->user()?->ui_theme ?? 'system')
<!DOCTYPE html>
<html lang="en" @class(['dark' => $theme === 'dark']) data-theme="{{ $theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Match my device: decided before the first paint, so a dark page never flashes white (wave 7). --}}
    <script>
        if (document.documentElement.dataset.theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.classList.add('dark');
        }
    </script>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <title inertia>CNCMS</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/tsx/app.tsx'])
    @inertiaHead
</head>
<body class="antialiased">
    @inertia
</body>
</html>
