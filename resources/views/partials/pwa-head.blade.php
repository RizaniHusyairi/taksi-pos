{{--
  PWA <head> partial. Pasang di dalam <head> halaman.
  Pemakaian:
    @include('partials.pwa-head', [
      'appName'    => 'CSO Panel',
      'themeColor' => '#4f46e5',
      'manifest'   => asset('manifest-cso.webmanifest'),
    ])
--}}
@php
  $pwaAppName    = $appName    ?? 'Taksi POS';
  $pwaThemeColor = $themeColor ?? '#4f46e5';
  $pwaManifest   = $manifest   ?? asset('manifest-cso.webmanifest');
@endphp

<meta name="theme-color" content="{{ $pwaThemeColor }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="{{ $pwaAppName }}">
<meta name="application-name" content="{{ $pwaAppName }}">

<link rel="manifest" href="{{ $pwaManifest }}">
<link rel="apple-touch-icon" href="{{ asset('pos-assets/img/pwa/icon-180.png') }}">
<link rel="apple-touch-icon" sizes="192x192" href="{{ asset('pos-assets/img/pwa/icon-192.png') }}">

<script>
  // Registrasi service worker (scope root) untuk kemampuan install & cache aset statis.
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('{{ asset('sw.js') }}')
        .catch(function (err) { console.warn('SW registration failed:', err); });
    });
  }
</script>
