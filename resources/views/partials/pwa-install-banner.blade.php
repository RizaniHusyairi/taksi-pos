{{--
  Banner "Install App" kustom (menangkap event beforeinstallprompt).
  Pemakaian:
    @include('partials.pwa-install-banner', [
      'appName'      => 'CSO Panel',
      'appKey'       => 'cso',      // kunci unik utk localStorage (cso/admin)
      'bottomOffset' => '6rem',     // jarak dari bawah (hindari bottom-nav). default 1.5rem
    ])
--}}
@php
  $piName   = $appName      ?? 'Taksi POS';
  $piKey    = $appKey       ?? 'app';
  $piBottom = $bottomOffset ?? '1.5rem';
@endphp

<style>
  #pwaInstallBanner {
    transform: translateY(140%);
    opacity: 0;
    transition: transform .45s cubic-bezier(.22,1,.36,1), opacity .35s ease;
  }
  #pwaInstallBanner.pwa-in { transform: translateY(0); opacity: 1; }
  #pwaInstallBanner .pwa-icon-pulse::after {
    content: ""; position: absolute; inset: 0; border-radius: 0.9rem;
    box-shadow: 0 0 0 0 rgba(255,255,255,.6); animation: pwaPulse 2.2s ease-out infinite;
  }
  @keyframes pwaPulse {
    0% { box-shadow: 0 0 0 0 rgba(255,255,255,.5); }
    70% { box-shadow: 0 0 0 12px rgba(255,255,255,0); }
    100% { box-shadow: 0 0 0 0 rgba(255,255,255,0); }
  }
</style>

<div id="pwaInstallBanner"
     class="hidden fixed inset-x-0 z-20 px-4 flex justify-center pointer-events-none"
     style="bottom: {{ $piBottom }};">
  <div class="pointer-events-auto w-full max-w-md rounded-2xl shadow-2xl p-3.5 flex items-center gap-3 text-white"
       style="background: linear-gradient(135deg, #2563eb 0%, #4f46e5 50%, #7c3aed 100%); box-shadow: 0 20px 45px -12px rgba(79,70,229,.7);">
    <div class="pwa-icon-pulse relative flex-shrink-0">
      <img src="{{ asset('pos-assets/img/pwa/icon-192.png') }}" alt="App" class="w-12 h-12 rounded-xl bg-white object-contain p-1 shadow-md">
    </div>
    <div class="flex-1 min-w-0">
      <div class="font-extrabold text-sm leading-tight">Install {{ $piName }}</div>
      <div id="pwaInstallText" class="text-[11px] text-white/85 leading-snug mt-0.5">
        Pasang ke layar utama untuk akses lebih cepat &amp; full-screen.
      </div>
    </div>
    <button id="pwaInstallBtn" type="button"
            class="flex-shrink-0 bg-white text-indigo-700 text-xs font-bold px-4 py-2.5 rounded-xl shadow-md hover:scale-105 active:scale-95 transition-transform">
      Install
    </button>
    <button id="pwaInstallClose" type="button" aria-label="Tutup"
            class="flex-shrink-0 w-7 h-7 flex items-center justify-center rounded-full bg-white/15 hover:bg-white/30 text-white transition-colors">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
    </button>
  </div>
</div>

<script>
  (function () {
    var KEY = 'pwa-install-' + @json($piKey);
    var SNOOZE_MS = 7 * 24 * 60 * 60 * 1000; // tampilkan lagi 7 hari setelah ditutup

    var banner   = document.getElementById('pwaInstallBanner');
    var btnInst  = document.getElementById('pwaInstallBtn');
    var btnClose = document.getElementById('pwaInstallClose');
    var txt      = document.getElementById('pwaInstallText');
    if (!banner) return;

    var deferredPrompt = null;

    // Sudah terpasang (standalone)? Jangan tampilkan.
    var isStandalone = window.matchMedia('(display-mode: standalone)').matches
                    || window.navigator.standalone === true;
    if (isStandalone) return;

    function isDismissed() {
      try {
        var raw = localStorage.getItem(KEY);
        if (!raw) return false;
        var d = JSON.parse(raw);
        if (d.done) return true;
        if (d.snoozed && (Date.now() - d.snoozed) < SNOOZE_MS) return true;
        return false;
      } catch (e) { return false; }
    }
    function setDone()    { try { localStorage.setItem(KEY, JSON.stringify({ done: true })); } catch (e) {} }
    function setSnoozed() { try { localStorage.setItem(KEY, JSON.stringify({ snoozed: Date.now() })); } catch (e) {} }

    function show() {
      if (isDismissed()) return;
      banner.classList.remove('hidden');
      requestAnimationFrame(function () { banner.classList.add('pwa-in'); });
    }
    function hide() {
      banner.classList.remove('pwa-in');
      setTimeout(function () { banner.classList.add('hidden'); }, 450);
    }

    // --- Android / Chromium: prompt asli ---
    window.addEventListener('beforeinstallprompt', function (e) {
      e.preventDefault();
      deferredPrompt = e;
      show();
    });

    btnInst && btnInst.addEventListener('click', async function () {
      if (!deferredPrompt) { hide(); return; }
      deferredPrompt.prompt();
      try {
        var choice = await deferredPrompt.userChoice;
        if (choice && choice.outcome === 'accepted') setDone(); else setSnoozed();
      } catch (e) { setSnoozed(); }
      deferredPrompt = null;
      hide();
    });

    btnClose && btnClose.addEventListener('click', function () { setSnoozed(); hide(); });

    window.addEventListener('appinstalled', function () { setDone(); hide(); });

    // --- iOS Safari: tidak ada beforeinstallprompt -> tampilkan instruksi ---
    var ua = navigator.userAgent || '';
    var isIOS = /iphone|ipad|ipod/i.test(ua)
             || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var isSafari = /^((?!chrome|crios|fxios|android).)*safari/i.test(ua);
    if (isIOS && isSafari && !isDismissed()) {
      if (txt) txt.innerHTML = "Ketuk tombol <b>Bagikan</b> di Safari, lalu pilih <b>“Tambahkan ke Layar Utama”</b>.";
      if (btnInst) btnInst.classList.add('hidden'); // tidak bisa prompt otomatis di iOS
      setTimeout(show, 1800);
    }
  })();
</script>
