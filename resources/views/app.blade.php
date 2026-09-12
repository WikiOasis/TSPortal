@php
use Illuminate\Support\Js;
@endphp

<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#101418" media="(prefers-color-scheme: dark)">

    <title>{{ config('app.name', 'TSPortal') }}</title>

    <script>
        window.TSPortal = {{ Js::from([
            'loginUrl' => route('oidc.redirect'),
            'logoutUrl' => route('logout'),
            'centralUrl' => config('mediawiki.central_url'),
            'appName' => config('app.name', 'TSPortal'),
            'version' => config('app.version'),
        ]) }};
    </script>

    @vite('resources/js/app.js')
</head>
<body>
<div id="app">
    <noscript>
        <p style="font-family: sans-serif; padding: 2em; max-width: 40em;">
            TSPortal needs JavaScript. It is an internal tool for Trust &amp; Safety;
            if you cannot enable it, mail
            <a href="mailto:trustandsafety@wikioasis.org">trustandsafety@wikioasis.org</a>.
        </p>
    </noscript>
</div>
</body>
</html>
