<!DOCTYPE html>
<html lang="id" class="">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <meta name="csrf-token" content="{{ csrf_token() }}">

  <title>CSO - Taksi POS</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    // Konfigurasi Tailwind CSS dengan dark mode berbasis class
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            primary: {
              50: '#eff6ff', 100: '#dbeafe', 200: '#bfdbfe', 300: '#93c5fd', 400: '#60a5fa',
              500: '#3b82f6', 600: '#2563eb', 700: '#1d4ed8', 800: '#1e40af', 900: '#1e3a8a'
            },
            pending: '#f59e0b', success: '#10b981', danger: '#ef4444'
          },
          fontFamily: {
            sans: ['Plus Jakarta Sans', 'Inter', 'ui-sans-serif', 'system-ui', 'sans-serif']
          }
        }
      }
    }
    </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <link rel="icon" href="{{ asset('pos-assets/img/logo_taksi.png') }}" type="image/png">

  {{-- PWA: installable + cache aset statis (data tetap network-first) --}}
  @include('partials.pwa-head', [
    'appName'    => 'CSO Panel',
    'themeColor' => '#4f46e5',
    'manifest'   => asset('manifest-cso.webmanifest'),
  ])

  <link rel="stylesheet" href="{{ asset('pos-assets/css/style.css') }}">
  <style>
    :root {
      --grad-primary: linear-gradient(135deg, #2563eb 0%, #4f46e5 50%, #7c3aed 100%);
      --grad-primary-soft: linear-gradient(135deg, #3b82f6 0%, #6366f1 100%);
    }
    * { -webkit-tap-highlight-color: transparent; }
    body { font-family: 'Plus Jakarta Sans', sans-serif; }

    /* ===== Animated Aurora Background ===== */
    .aurora-bg { position: fixed; inset: 0; z-index: 0; overflow: hidden; pointer-events: none; }
    .aurora-blob {
      position: absolute; border-radius: 9999px; filter: blur(70px); opacity: .45;
      animation: floatBlob 18s ease-in-out infinite;
    }
    .aurora-blob.b1 { width: 22rem; height: 22rem; top: -6rem; left: -5rem; background: radial-gradient(circle, #60a5fa, transparent 70%); }
    .aurora-blob.b2 { width: 18rem; height: 18rem; top: 20%; right: -6rem; background: radial-gradient(circle, #a78bfa, transparent 70%); animation-delay: -4s; }
    .aurora-blob.b3 { width: 20rem; height: 20rem; bottom: -6rem; left: 30%; background: radial-gradient(circle, #34d399, transparent 70%); animation-delay: -9s; opacity: .3; }
    .dark .aurora-blob { opacity: .22; }
    @keyframes floatBlob {
      0%, 100% { transform: translate(0,0) scale(1); }
      33% { transform: translate(2rem, -1.5rem) scale(1.1); }
      66% { transform: translate(-1.5rem, 1rem) scale(.95); }
    }

    /* ===== View / Section entrance ===== */
    .view-section { animation: fadeUp .45s cubic-bezier(.22,1,.36,1) both; }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
    .stagger > * { animation: fadeUp .5s cubic-bezier(.22,1,.36,1) both; }
    .stagger > *:nth-child(1) { animation-delay: .04s; }
    .stagger > *:nth-child(2) { animation-delay: .10s; }
    .stagger > *:nth-child(3) { animation-delay: .16s; }
    .stagger > *:nth-child(4) { animation-delay: .22s; }
    .stagger > *:nth-child(5) { animation-delay: .28s; }

    /* ===== Glass card ===== */
    .glass {
      background: rgba(255,255,255,.72);
      backdrop-filter: blur(14px) saturate(160%);
      -webkit-backdrop-filter: blur(14px) saturate(160%);
      border: 1px solid rgba(255,255,255,.6);
    }
    .dark .glass {
      background: rgba(30,41,59,.6);
      border-color: rgba(148,163,184,.12);
    }
    .card-hover { transition: transform .3s cubic-bezier(.22,1,.36,1), box-shadow .3s ease; }
    .card-hover:hover { transform: translateY(-3px); box-shadow: 0 18px 40px -12px rgba(37,99,235,.28); }

    /* ===== Gradient text / borders ===== */
    .text-grad { background: var(--grad-primary); -webkit-background-clip: text; background-clip: text; color: transparent; }

    /* ===== Buttons ===== */
    .btn-grad {
      background: var(--grad-primary); background-size: 200% 200%;
      position: relative; overflow: hidden; transition: transform .15s ease, box-shadow .3s ease;
      box-shadow: 0 10px 26px -8px rgba(79,70,229,.6);
      animation: gradShift 6s ease infinite;
    }
    .btn-grad:hover:not(:disabled) { box-shadow: 0 14px 32px -8px rgba(79,70,229,.75); }
    .btn-grad:active:not(:disabled) { transform: scale(.97); }
    .btn-grad:disabled { filter: grayscale(.5); opacity: .55; box-shadow: none; animation: none; }
    .btn-grad.is-done {
      background: linear-gradient(135deg, #10b981, #059669); animation: none;
      box-shadow: 0 10px 26px -8px rgba(16,185,129,.6); filter: none; opacity: 1;
    }
    .btn-grad.is-done:disabled { filter: none; opacity: 1; }
    @keyframes gradShift { 0%,100% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } }
    .btn-grad::before {
      content: ""; position: absolute; top: 0; left: -120%; width: 60%; height: 100%;
      background: linear-gradient(100deg, transparent, rgba(255,255,255,.4), transparent);
      transform: skewX(-20deg);
    }
    .btn-grad:not(:disabled):hover::before { animation: shimmer 1s ease; }
    @keyframes shimmer { 0% { left: -120%; } 100% { left: 160%; } }

    /* ===== Step indicator ===== */
    .step-dot { transition: all .35s cubic-bezier(.22,1,.36,1); }
    .step-line { transition: all .5s ease; }

    /* ===== Bottom nav ===== */
    .bottom-nav {
      background: rgba(255,255,255,.8);
      backdrop-filter: blur(18px) saturate(180%);
      -webkit-backdrop-filter: blur(18px) saturate(180%);
      box-shadow: 0 -8px 30px -10px rgba(0,0,0,.12);
    }
    .dark .bottom-nav { background: rgba(15,23,42,.82); border-top-color: #1e293b; }
    .nav-item .nav-pill {
      transition: all .35s cubic-bezier(.34,1.56,.64,1);
      transform: translateY(4px) scale(.9); opacity: 0;
    }
    .nav-item.active .nav-pill { transform: translateY(0) scale(1); opacity: 1; }
    .nav-item .nav-icon-wrap { transition: all .35s cubic-bezier(.34,1.56,.64,1); }
    .nav-item.active .nav-icon-wrap {
      background: var(--grad-primary);
      box-shadow: 0 8px 18px -6px rgba(79,70,229,.7);
      transform: translateY(-2px);
    }
    .nav-item.active .nav-icon-wrap svg { color: #fff; }
    .nav-item.active .nav-text { color: #4f46e5; font-weight: 700; }
    .dark .nav-item.active .nav-text { color: #a5b4fc; }
    .nav-item:active .nav-icon-wrap { transform: scale(.88); }

    /* ===== Driver / selected card ===== */
    .driver-card.selected {
      box-shadow: 0 0 0 3px #6366f1, 0 12px 28px -10px rgba(99,102,241,.5);
      border-color: #6366f1;
    }

    /* ===== Zone cards ===== */
    .zone-card .zone-check { transition: all .25s cubic-bezier(.34,1.56,.64,1); opacity: 0; transform: scale(.4); }
    .zone-card .zone-icon { transition: background .25s ease, color .25s ease; }
    .zone-card.selected {
      border-color: transparent;
      box-shadow: 0 0 0 2px #6366f1, 0 14px 30px -12px rgba(99,102,241,.55);
      background: linear-gradient(135deg, rgba(99,102,241,.14), rgba(59,130,246,.06));
    }
    .dark .zone-card.selected { background: linear-gradient(135deg, rgba(99,102,241,.28), rgba(59,130,246,.12)); }
    .zone-card.selected .zone-check { opacity: 1; transform: scale(1); }
    .zone-card.selected .zone-icon { background: var(--grad-primary); color: #fff; }

    /* ===== Status pulse dot ===== */
    .pulse-dot { position: relative; }
    .pulse-dot::after {
      content: ""; position: absolute; inset: 0; border-radius: 9999px;
      background: inherit; animation: pulseRing 1.8s ease-out infinite;
    }
    @keyframes pulseRing { 0% { transform: scale(1); opacity: .7; } 100% { transform: scale(2.6); opacity: 0; } }

    /* ===== Price pop ===== */
    .price-pop { animation: pricePop .45s cubic-bezier(.34,1.56,.64,1); }
    @keyframes pricePop { 0% { transform: scale(.7); opacity: .3; } 60% { transform: scale(1.12); } 100% { transform: scale(1); opacity: 1; } }

    /* ===== Spin / refresh ===== */
    .spin-once { animation: spin360 .6s ease; }
    @keyframes spin360 { to { transform: rotate(360deg); } }

    /* ===== Modal entrance ===== */
    .modal-pop > .modal-card { animation: modalPop .35s cubic-bezier(.34,1.56,.64,1) both; }
    @keyframes modalPop { from { opacity: 0; transform: translateY(24px) scale(.96); } to { opacity: 1; transform: none; } }

    /* ===== Floating avatar ring ===== */
    .avatar-ring { animation: floatY 4s ease-in-out infinite; }
    @keyframes floatY { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
    .ring-spin {
      background: conic-gradient(from 0deg, #2563eb, #7c3aed, #06b6d4, #2563eb);
      animation: spin360 5s linear infinite;
    }

    /* ===== Skeleton shimmer ===== */
    .skeleton {
      background: linear-gradient(90deg, rgba(148,163,184,.18) 25%, rgba(148,163,184,.35) 37%, rgba(148,163,184,.18) 63%);
      background-size: 400% 100%; animation: skel 1.4s ease infinite; border-radius: .75rem;
    }
    @keyframes skel { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }

    /* ===== Custom select chevron ===== */
    select.fancy-select {
      appearance: none; -webkit-appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%236366f1' stroke-width='2.5'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right .9rem center; background-size: 1.1rem;
    }

    /* nicer scrollbar in modals */
    .nice-scroll::-webkit-scrollbar { width: 6px; }
    .nice-scroll::-webkit-scrollbar-thumb { background: rgba(99,102,241,.4); border-radius: 9999px; }
  </style>
</head>
<body class="bg-slate-100 dark:bg-slate-950 min-h-screen flex flex-col font-sans text-slate-800 dark:text-slate-100 transition-colors duration-300">

  <!-- Animated Aurora Background -->
  <div class="aurora-bg">
    <div class="aurora-blob b1"></div>
    <div class="aurora-blob b2"></div>
    <div class="aurora-blob b3"></div>
  </div>

  <!-- Top Header -->
  <header class="glass border-b border-white/40 dark:border-slate-700/50 px-4 py-3 flex items-center justify-between sticky top-0 z-20">
    <div class="flex items-center gap-3">
      <div class="relative">
        <div class="absolute -inset-1 rounded-2xl ring-spin opacity-80 blur-[1px]"></div>
        <img src="{{ asset('pos-assets/img/logo_taksi.png') }}" alt="Logo" class="relative w-10 h-10 object-contain bg-white rounded-xl p-1.5 shadow-md" />
      </div>
      <div>
        <h1 class="font-extrabold text-grad text-lg leading-tight">CSO Panel</h1>
        <p id="pageTitle" class="text-xs font-medium text-slate-500 dark:text-slate-400 -mt-0.5">Pemesanan Baru</p>
      </div>
    </div>
    <div class="flex items-center gap-1.5">
      <button id="themeToggleBtn" class="p-2.5 rounded-xl glass border-white/40 dark:border-slate-700 hover:scale-105 active:scale-95 transition-transform shadow-sm">
          <svg id="theme-icon-dark" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-indigo-500 dark:text-indigo-300 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" /></svg>
          <svg id="theme-icon-light" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-amber-500 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
      </button>
      <a href="{{ route('logout') }}"
        onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
        class="p-2.5 rounded-xl glass border-white/40 dark:border-slate-700 text-rose-500 hover:bg-rose-500 hover:text-white hover:scale-105 active:scale-95 transition-all shadow-sm">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
          </svg>
      </a>

      <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
          @csrf
      </form>
    </div>
  </header>

  <!-- Main Content Area -->
  <main class="relative z-10 flex-grow p-4 pb-28 space-y-5">

    <!-- View: Pemesanan Baru -->
    <section id="view-new" class="view-section space-y-5">

      <!-- Step Progress -->
      <div class="glass rounded-2xl shadow-sm px-4 py-3 flex items-center justify-between">
        <div class="flex flex-col items-center gap-1.5 flex-1">
          <div id="stepDot1" class="step-dot w-9 h-9 rounded-full btn-grad flex items-center justify-center text-white text-sm font-bold shadow-md">1</div>
          <span id="stepLabel1" class="text-[10px] font-bold text-indigo-600 dark:text-indigo-300">Tujuan</span>
        </div>
        <div id="stepLine1" class="step-line h-1 flex-1 bg-slate-200 dark:bg-slate-700 rounded-full -mt-4"></div>
        <div class="flex flex-col items-center gap-1.5 flex-1">
          <div id="stepDot2" class="step-dot w-9 h-9 rounded-full bg-slate-200 dark:bg-slate-700 flex items-center justify-center text-slate-500 dark:text-slate-400 text-sm font-bold">2</div>
          <span id="stepLabel2" class="text-[10px] font-bold text-slate-400">Bayar</span>
        </div>
        <div id="stepLine2" class="step-line h-1 flex-1 bg-slate-200 dark:bg-slate-700 rounded-full -mt-4"></div>
        <div class="flex flex-col items-center gap-1.5 flex-1">
          <div id="stepDot3" class="step-dot w-9 h-9 rounded-full bg-slate-200 dark:bg-slate-700 flex items-center justify-center text-slate-500 dark:text-slate-400 text-sm font-bold">3</div>
          <span id="stepLabel3" class="text-[10px] font-bold text-slate-400">Supir</span>
        </div>
      </div>

      <div class="stagger space-y-5">
        <!-- Route Selection Card -->
        <div class="glass rounded-2xl shadow-md p-5">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <div class="w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-300 flex items-center justify-center">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><circle cx="12" cy="11" r="2.5"/></svg>
              </div>
              <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">Pilih Tujuan</h2>
            </div>
            <span id="zoneCount" class="text-[10px] font-bold text-slate-400"></span>
          </div>

          <!-- Search Zona -->
          <div class="relative mb-3">
            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
              <svg class="w-4.5 h-4.5 text-slate-400" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            </div>
            <input type="text" id="zoneSearch" autocomplete="off" placeholder="Cari zona tujuan..."
              class="w-full pl-10 pr-9 py-2.5 rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white/70 dark:bg-slate-700/60 text-sm font-semibold text-slate-800 dark:text-slate-100 placeholder:font-normal placeholder:text-slate-400 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/20 transition-all">
            <button id="zoneSearchClear" type="button" class="hidden absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-rose-500 transition-colors">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>

          <!-- Hidden field menyimpan zona terpilih (dipakai logika JS yang sudah ada) -->
          <input type="hidden" id="toZone">

          <!-- Grid Kartu Zona -->
          <div id="zoneGrid" class="grid grid-cols-2 gap-2.5 max-h-72 overflow-y-auto nice-scroll pr-0.5 -mr-0.5"></div>

          <!-- Empty state pencarian -->
          <div id="zoneNoResult" class="hidden text-center py-6">
            <svg class="w-10 h-10 mx-auto text-slate-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <p class="text-xs font-semibold text-slate-400">Zona tidak ditemukan</p>
          </div>
        </div>

        <!-- Tariff Card -->
        <div class="card-hover rounded-2xl shadow-lg p-5 btn-grad text-white relative overflow-hidden">
          <div class="absolute -right-6 -top-6 w-28 h-28 rounded-full bg-white/10"></div>
          <div class="absolute -right-2 bottom-2 opacity-15">
            <svg width="80" height="80" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13l2-5a2 2 0 012-1.5h10A2 2 0 0119 8l2 5M5 16h.01M19 16h.01M4 13h16a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 11-4 0H9a2 2 0 11-4 0H4a1 1 0 01-1-1v-3a1 1 0 011-1z"/></svg>
          </div>
          <div class="relative flex items-center justify-between">
            <div>
              <div class="flex items-center gap-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-300 pulse-dot"></span>
                <span class="text-xs font-medium text-white/80 uppercase tracking-wider">Tarif Perjalanan</span>
              </div>
              <span id="priceBox" class="block text-3xl font-extrabold mt-1 tracking-tight">-</span>
            </div>
          </div>
        </div>

        <!-- Driver Selection Card -->
        <div>
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
              <div class="w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-300 flex items-center justify-center">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z"/></svg>
              </div>
              <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">Antrian Supir</h2>
            </div>
            <span class="text-[10px] font-medium text-slate-400">Urutan masuk</span>
          </div>

          <div id="driversList" class="flex flex-col gap-3"></div>
        </div>
      </div>

      <!-- Confirmation Button -->
      <div class="sticky bottom-24">
        <button id="btnConfirmBooking" disabled class="btn-grad w-full text-white font-bold py-4 rounded-2xl flex items-center justify-center gap-2 disabled:cursor-not-allowed">
          <span>Konfirmasi &amp; Proses Pembayaran</span>
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
        </button>
      </div>
    </section>

    <!-- View: Riwayat -->
    <section id="view-history" class="view-section hidden space-y-4">

        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <div class="w-8 h-8 rounded-lg bg-violet-100 dark:bg-violet-500/20 text-violet-600 dark:text-violet-300 flex items-center justify-center">
                <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              </div>
              <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200">Riwayat Transaksi</h2>
            </div>
            <button id="refreshHistory" class="p-2.5 rounded-xl glass border-white/40 dark:border-slate-700 hover:scale-105 active:scale-95 transition-transform shadow-sm">
                <svg id="refreshHistoryIcon" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-violet-600 dark:text-violet-300" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 110 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" />
                </svg>
            </button>
        </div>

        <div class="glass card-hover p-4 rounded-2xl shadow-sm">
            <div class="flex items-end gap-2">
                <div class="flex-1">
                    <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 mb-1 ml-0.5 uppercase tracking-wide">Dari Tanggal</label>
                    <input type="date" id="filterStartDate" class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white/70 dark:bg-slate-700/60 text-xs font-semibold py-2.5 px-3 dark:text-white focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20 transition-all">
                </div>
                <div class="flex-1">
                    <label class="block text-[10px] font-bold text-slate-500 dark:text-slate-400 mb-1 ml-0.5 uppercase tracking-wide">Sampai Tanggal</label>
                    <input type="date" id="filterEndDate" class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white/70 dark:bg-slate-700/60 text-xs font-semibold py-2.5 px-3 dark:text-white focus:border-violet-500 focus:ring-2 focus:ring-violet-500/20 transition-all">
                </div>
                <button id="btnFilterHistory" class="btn-grad text-white p-3 rounded-xl shadow-md">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                </button>
            </div>
        </div>

        <div id="csoTxList" class="space-y-3"></div>
    </section>

    <!-- View: Profil -->
    <section id="view-profile" class="view-section hidden space-y-5">
        <div class="glass card-hover rounded-2xl shadow-md p-6 text-center relative overflow-hidden">
            <div class="absolute inset-x-0 -top-10 h-32 btn-grad opacity-90"></div>
            <div class="relative inline-block avatar-ring mt-2">
              <div class="absolute -inset-1.5 rounded-full ring-spin opacity-90 blur-[2px]"></div>
              <div class="relative w-24 h-24 bg-white dark:bg-slate-800 text-indigo-600 dark:text-indigo-300 rounded-full flex items-center justify-center text-4xl font-extrabold shadow-lg">
                  <span id="profileInitial">U</span>
              </div>
            </div>
            <h2 id="profileNameDisplay" class="text-xl font-extrabold text-slate-800 dark:text-slate-100 mt-4">Nama User</h2>
            <span class="inline-flex items-center gap-1.5 mt-1 px-3 py-1 rounded-full bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-300 text-xs font-bold">
              <svg width="12" height="12" fill="currentColor" viewBox="0 0 20 20"><path d="M10 2a4 4 0 100 8 4 4 0 000-8zM4 16a6 6 0 1112 0H4z"/></svg>
              Customer Service Officer
            </span>
        </div>

        <div class="glass card-hover rounded-2xl shadow-md p-5">
            <h3 class="font-bold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
              <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
              Edit Biodata
            </h3>
            <form id="formEditProfile" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1.5 ml-0.5">Nama Lengkap</label>
                    <input type="text" id="editName" class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white/70 dark:bg-slate-700/60 text-slate-900 dark:text-slate-100 text-sm font-semibold py-2.5 px-3.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition-all" required>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1.5 ml-0.5">Username</label>
                    <input type="text" id="editUsername" class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white/70 dark:bg-slate-700/60 text-slate-900 dark:text-slate-100 text-sm font-semibold py-2.5 px-3.5 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition-all" required>
                </div>
                <div class="text-right">
                    <button type="submit" class="btn-grad text-white text-sm font-bold py-2.5 px-5 rounded-xl">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>

        <div class="glass card-hover rounded-2xl shadow-md p-5">
            <h3 class="font-bold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
              <svg class="w-4 h-4 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
              Ganti Password
            </h3>
            <form id="formChangePassword" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1.5 ml-0.5">Password Saat Ini</label>
                    <input type="password" id="currentPass" class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white/70 dark:bg-slate-700/60 text-slate-900 dark:text-slate-100 text-sm font-semibold py-2.5 px-3.5 focus:border-rose-400 focus:ring-2 focus:ring-rose-400/20 transition-all" required>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1.5 ml-0.5">Password Baru</label>
                    <input type="password" id="newPass" class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white/70 dark:bg-slate-700/60 text-slate-900 dark:text-slate-100 text-sm font-semibold py-2.5 px-3.5 focus:border-rose-400 focus:ring-2 focus:ring-rose-400/20 transition-all" required minlength="6">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1.5 ml-0.5">Konfirmasi Password Baru</label>
                    <input type="password" id="confirmPass" class="w-full rounded-xl border-2 border-slate-200 dark:border-slate-600 bg-white/70 dark:bg-slate-700/60 text-slate-900 dark:text-slate-100 text-sm font-semibold py-2.5 px-3.5 focus:border-rose-400 focus:ring-2 focus:ring-rose-400/20 transition-all" required minlength="6">
                </div>
                <div class="text-right">
                    <button type="submit" class="bg-slate-800 hover:bg-slate-900 dark:bg-slate-700 dark:hover:bg-slate-600 text-white text-sm font-bold py-2.5 px-5 rounded-xl shadow-md transition-all active:scale-95">
                        Update Password
                    </button>
                </div>
            </form>
        </div>
    </section>

  </main>

  <!-- Bottom Navigation -->
  <nav class="bottom-nav w-full fixed bottom-0 left-0 z-20 h-20 flex justify-around items-center border-t border-white/40 dark:border-slate-700">
    <a href="#new" class="nav-item flex flex-col items-center justify-center gap-1 w-full h-full active">
      <div class="nav-icon-wrap w-11 h-11 rounded-2xl flex items-center justify-center bg-slate-100 dark:bg-slate-800">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5 text-slate-500 dark:text-slate-400" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <span class="nav-text text-[11px] font-semibold text-slate-500 dark:text-slate-400">Pesan</span>
      <span class="nav-pill w-1 h-1 rounded-full btn-grad"></span>
    </a>
    <a href="#history" class="nav-item flex flex-col items-center justify-center gap-1 w-full h-full">
      <div class="nav-icon-wrap w-11 h-11 rounded-2xl flex items-center justify-center bg-slate-100 dark:bg-slate-800">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5 text-slate-500 dark:text-slate-400" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </div>
      <span class="nav-text text-[11px] font-semibold text-slate-500 dark:text-slate-400">Riwayat</span>
      <span class="nav-pill w-1 h-1 rounded-full btn-grad"></span>
    </a>
    <a href="#profile" class="nav-item flex flex-col items-center justify-center gap-1 w-full h-full">
      <div class="nav-icon-wrap w-11 h-11 rounded-2xl flex items-center justify-center bg-slate-100 dark:bg-slate-800">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5.5 w-5.5 text-slate-500 dark:text-slate-400" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
        </svg>
      </div>
      <span class="nav-text text-[11px] font-semibold text-slate-500 dark:text-slate-400">Profil</span>
      <span class="nav-pill w-1 h-1 rounded-full btn-grad"></span>
    </a>
  </nav>

  <!-- Payment Modal -->
  <div id="paymentModal" class="modal-pop fixed inset-0 bg-slate-900/60 hidden items-center justify-center p-4 z-30 backdrop-blur-md transition-opacity">
    <div class="modal-card glass dark:bg-slate-800/95 rounded-3xl shadow-2xl max-w-md w-full p-6 relative overflow-hidden flex flex-col max-h-[90vh]">

      <div class="flex items-center justify-between mb-4 flex-shrink-0">
        <div>
            <h3 class="font-extrabold text-xl text-grad">Konfirmasi Order</h3>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Pastikan data perjalanan sudah benar</p>
        </div>
        <button id="closePayModal" class="w-9 h-9 flex items-center justify-center bg-slate-100 dark:bg-slate-700 rounded-full text-slate-500 hover:bg-rose-500 hover:text-white transition-colors">✕</button>
      </div>

      <div class="overflow-y-auto flex-grow pr-1 nice-scroll">

          <div id="payInfo" class="mb-6 p-4 bg-gradient-to-br from-indigo-50 to-blue-50 dark:from-indigo-900/20 dark:to-blue-900/20 border border-indigo-100 dark:border-indigo-800 rounded-2xl"></div>

          <div class="mb-6">
            <label class="block text-sm font-bold text-slate-700 dark:text-slate-300 mb-2">
                Nomor WhatsApp Penumpang <span class="text-rose-500">*</span>
            </label>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                    <span class="text-slate-500 font-bold text-sm">+62</span>
                </div>
                <input type="tel" id="passengerPhone"
                    class="w-full pl-12 pr-11 py-3.5 bg-white dark:bg-slate-700 border-2 border-slate-200 dark:border-slate-600 rounded-2xl focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/20 transition-all text-slate-800 dark:text-slate-100 font-bold placeholder:font-normal placeholder:text-slate-400"
                    placeholder="812-3456-7890" inputmode="numeric">
                <div class="absolute inset-y-0 right-0 pr-3.5 flex items-center pointer-events-none">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-500" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                </div>
            </div>
            <p class="text-[10px] text-slate-500 mt-1.5 ml-1">Struk digital akan dikirim otomatis ke nomor ini.</p>
          </div>

          <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2 ml-1">Metode Pembayaran</p>
          <div class="space-y-3 mb-6">
            <button id="payQRIS" class="w-full flex items-center gap-3 p-4 rounded-2xl border-2 border-slate-200 dark:border-slate-700 hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-slate-700/50 transition-all group focus:outline-none focus:ring-2 focus:ring-blue-500">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-500 text-white flex items-center justify-center flex-shrink-0 shadow-md">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" /></svg>
                </div>
                <div class="text-left">
                    <div class="font-bold text-slate-800 dark:text-slate-100 group-hover:text-blue-700">QRIS (Transfer)</div>
                    <div class="text-xs text-slate-500">Upload bukti bayar</div>
                </div>
                <div class="ml-auto text-slate-400 group-hover:text-blue-500 transition-transform group-hover:rotate-180">▼</div>
            </button>

            <div id="qrisBox" class="hidden p-4 border-2 border-blue-200 dark:border-slate-600 rounded-2xl bg-blue-50/50 dark:bg-slate-700/50 animate-in fade-in slide-in-from-top-2 duration-200">

                <div class="text-center mb-4">
                    <p class="text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">Scan Barcode Bank BTN</p>

                    <img id="paymentQrisImage" src="{{ asset('assets/img/qris-placeholder.svg') }}" alt="QRIS" class="w-40 h-40 mx-auto bg-white rounded-2xl shadow-md border p-2 object-contain cursor-zoom-in hover:opacity-90 transition-opacity">

                    <p id="paymentQrisError" class="hidden text-[10px] text-red-500 mt-1 font-bold">⚠️ CSO belum mengupload QRIS</p>
                </div>

                <div class="space-y-2">
                    <p class="text-xs font-bold text-slate-600 dark:text-slate-300">Upload Bukti Transfer</p>

                    <div id="proofPreviewBox" class="hidden w-full h-40 bg-slate-200 dark:bg-slate-800 rounded-2xl overflow-hidden relative mb-2 border-2 border-slate-300 dark:border-slate-600">
                        <img id="proofPreviewImg" class="w-full h-full object-contain">
                        <button id="removeProof" class="absolute top-2 right-2 bg-red-600 hover:bg-red-700 text-white rounded-full p-1.5 shadow-md transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <label for="proofInput" class="flex items-center justify-center w-full px-4 py-4 bg-white dark:bg-slate-800 border-2 border-dashed border-blue-300 dark:border-slate-500 rounded-2xl cursor-pointer hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-slate-700 transition-all group">
                        <div class="text-center">
                            <svg class="w-8 h-8 mx-auto text-blue-400 group-hover:text-blue-600 mb-1 transition-transform group-hover:scale-110" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                            <span class="text-xs font-semibold text-slate-600 dark:text-slate-300">Klik untuk Foto Bukti</span>
                        </div>
                        <input type="file" id="proofInput" accept="image/*" capture="environment" class="hidden">
                    </label>
                </div>

                <button id="confirmQR" disabled class="mt-4 w-full bg-slate-300 dark:bg-slate-600 text-slate-500 font-bold py-3 rounded-2xl cursor-not-allowed transition-all shadow-sm">
                    Kirim &amp; Konfirmasi Pembayaran
                </button>
            </div>

            <button id="payCashCSO" class="w-full flex items-center gap-3 p-4 rounded-2xl border-2 border-slate-200 dark:border-slate-700 hover:border-green-500 hover:bg-green-50 dark:hover:bg-slate-700/50 transition-all group">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500 to-green-500 text-white flex items-center justify-center flex-shrink-0 shadow-md">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                </div>
                <div class="text-left">
                    <div class="font-bold text-slate-800 dark:text-slate-100 group-hover:text-green-700">Tunai ke CSO</div>
                    <div class="text-xs text-slate-500">Bayar cash di loket</div>
                </div>
            </button>

            <button id="payCashDriver" class="w-full flex items-center gap-3 p-4 rounded-2xl border-2 border-slate-200 dark:border-slate-700 hover:border-orange-500 hover:bg-orange-50 dark:hover:bg-slate-700/50 transition-all group">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-amber-500 to-orange-500 text-white flex items-center justify-center flex-shrink-0 shadow-md">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" /></svg>
                </div>
                <div class="text-left">
                    <div class="font-bold text-slate-800 dark:text-slate-100 group-hover:text-orange-700">Tunai ke Supir</div>
                    <div class="text-xs text-slate-500">Bayar saat sampai tujuan</div>
                </div>
            </button>
          </div>

      </div>
    </div>
  </div>

  <!-- Receipt Modal -->
  <div id="receiptModal" class="modal-pop fixed inset-0 bg-slate-900/60 hidden items-center justify-center p-4 z-30 backdrop-blur-md">
    <div class="modal-card glass dark:bg-slate-800/95 rounded-3xl shadow-2xl w-[380px] max-w-full p-5">
      <div class="flex items-center justify-between mb-3">
        <h3 class="font-extrabold text-grad text-lg">Struk Pembayaran</h3>
        <button id="closeReceipt" class="w-9 h-9 flex items-center justify-center bg-slate-100 dark:bg-slate-700 rounded-full text-slate-500 hover:bg-rose-500 hover:text-white transition-colors">✕</button>
      </div>

      <div id="receiptArea" class="bg-white p-2 rounded-2xl mx-auto border border-slate-200 shadow-inner"></div>

      <div class="mt-4 text-center">
        <p class="text-xs text-green-600 font-bold mb-3 flex items-center justify-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          Link struk telah dikirim ke WA Penumpang &amp; Supir.
        </p>
        <button id="closeReceipt2" class="btn-grad w-full text-white rounded-2xl px-4 py-3.5 text-sm font-bold">Tutup / Selesai</button>
      </div>
    </div>
  </div>

  <!-- Cash Confirm Modal -->
  <div id="cashConfirmModal" class="modal-pop fixed inset-0 bg-slate-900/60 hidden items-center justify-center p-4 z-50 backdrop-blur-md">
    <div class="modal-card glass dark:bg-slate-800/95 rounded-3xl shadow-2xl max-w-sm w-full p-6 text-center">

        <div class="w-16 h-16 bg-gradient-to-br from-amber-400 to-orange-500 text-white rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg avatar-ring">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
        </div>

        <h3 class="text-lg font-extrabold text-slate-800 dark:text-slate-100 mb-2">Konfirmasi Pembayaran</h3>
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">
            Apakah Anda yakin ingin memproses pesanan ini dengan metode <span id="confirmMethodName" class="font-bold text-slate-800 dark:text-white">TUNAI</span>?
        </p>

        <div class="grid grid-cols-2 gap-3">
            <button id="btnCancelCash" class="w-full py-3 rounded-2xl border-2 border-slate-200 text-slate-600 font-bold hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700 transition-all active:scale-95">
                Batal
            </button>
            <button id="btnProceedCash" class="btn-grad w-full py-3 rounded-2xl text-white font-bold">
                Ya, Proses
            </button>
        </div>
    </div>
  </div>

  <!-- Modal Ganti Supir -->
  <div id="changeDriverModal" class="modal-pop fixed inset-0 bg-slate-900/60 hidden items-center justify-center p-4 z-50 backdrop-blur-md">
      <div class="modal-card glass dark:bg-slate-800/95 rounded-3xl shadow-2xl max-w-sm w-full p-0 flex flex-col max-h-[80vh] overflow-hidden">
          <div class="p-4 border-b border-slate-100 dark:border-slate-700 flex justify-between items-center btn-grad text-white">
              <h3 class="font-bold text-lg">Pilih Supir Pengganti</h3>
              <button id="closeChangeDriver" class="w-8 h-8 flex items-center justify-center bg-white/20 rounded-full hover:bg-white/30 transition-colors">✕</button>
          </div>
          <div id="changeDriverList" class="overflow-y-auto flex-grow nice-scroll">
              <!-- Driver list injected by JS -->
          </div>
      </div>
  </div>

  <!-- Modal Pilih Supir (Initial Booking) -->
  <div id="selectDriverModal" class="modal-pop fixed inset-0 bg-slate-900/60 hidden items-center justify-center p-4 z-50 backdrop-blur-md">
      <div class="modal-card glass dark:bg-slate-800/95 rounded-3xl shadow-2xl max-w-md w-full p-0 flex flex-col max-h-[85vh] overflow-hidden">
          <div class="p-4 flex justify-between items-center bg-gradient-to-r from-emerald-500 to-teal-500 text-white">
              <div>
                  <h3 class="font-bold text-lg">Pilih Supir</h3>
                  <p class="text-xs text-emerald-50 font-bold flex items-center gap-1">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    Pembayaran Dikonfirmasi
                  </p>
              </div>
              <button id="closeSelectDriver" class="w-9 h-9 flex items-center justify-center bg-white/20 rounded-full hover:bg-white/30 transition-colors">✕</button>
          </div>
          <div class="p-3 bg-emerald-50/50 dark:bg-slate-900/50 text-xs text-emerald-700 dark:text-emerald-300 text-center font-medium">
              Silakan pilih supir dari antrian di bawah ini untuk menyelesaikan order.
          </div>
          <div id="selectDriverList" class="overflow-y-auto flex-grow p-4 space-y-3 bg-slate-50/50 dark:bg-slate-800/50 nice-scroll">
              <!-- Driver list injected by JS -->
          </div>
      </div>
  </div>

  <!-- QRIS Zoom Modal -->
  <div id="qrisZoomModal" class="fixed inset-0 bg-black/95 hidden items-center justify-center p-4 z-[60] backdrop-blur-sm cursor-zoom-out transition-opacity duration-300">
    <img id="qrisZoomImage" src="" class="max-w-full max-h-full object-contain rounded-2xl shadow-2xl scale-95 transition-transform duration-300">
    <div class="absolute bottom-10 text-white/70 text-sm bg-black/50 px-4 py-2 rounded-full pointer-events-none">
        Ketuk layar untuk menutup
    </div>
  </div>

  <script>
    window.companyQrisUrl = "{{ $companyQrisUrl ?? '' }}";
  </script>
  <script type="module">
    import { CsoApp } from '{{ asset("pos-assets/js/cso.js") }}';
    // Inisialisasi App via cso.js (sudah di-expose window.app)
  </script>
</body>
</html>
