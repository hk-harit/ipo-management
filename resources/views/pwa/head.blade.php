{{-- Makes the site installable as an app on a phone. $manifest is the manifest file for this panel. --}}
<link rel="manifest" href="{{ asset($manifest) }}">
<meta name="theme-color" content="#059669">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ $title ?? 'IPO Manage' }}">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('icons/icon-192.png') }}">
