<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Laravel') }}</title>
    @vite(['resources/scss/style.scss', 'resources/js/app.js'])
</head>
<body class="bg-slate-50 antialiased">
    <div id="app"></div>
</body>
</html>
