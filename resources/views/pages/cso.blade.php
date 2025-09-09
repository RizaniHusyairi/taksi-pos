<!DOCTYPE html>
<html lang="id" class="">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <meta name="csrf-token" content="{{ csrf_token() }}">

  <title>CSO - Koperasi Taksi POS</title>
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
          }
        }
      }
    }
  </script>
  <link rel="stylesheet" href="{{ asset('pos-assets/css/style.css') }}">
  <style>
    body { -webkit-tap-highlight-color: transparent; }
    .view-section { animation: fadeIn 0.3s ease-in-out; }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .bottom-nav { box-shadow: 0 -2px 10px rgba(0,0,0,0.08); }
    .nav-item.active svg, .nav-item.active .nav-text { color: #2563eb; font-weight: 600; }
    .dark .bottom-nav { border-top-color: #334155; }
    .dark .nav-item.active .nav-text, .dark .nav-item.active svg { color: #60a5fa; }
    .driver-card.selected {
        box-shadow: 0 0 0 3px #3b82f6; /* ring-primary-500 */
        border-color: #3b82f6;
    }
  </style>
</head>
<body class="bg-slate-100 dark:bg-slate-900 min-h-screen flex flex-col font-sans transition-colors duration-300">

  <!-- Top Header -->
  <header class="bg-white/80 dark:bg-slate-800/80 backdrop-blur-sm border-b border-slate-200 dark:border-slate-700 p-4 flex items-center justify-between sticky top-0 z-20">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-full bg-primary-600 text-white grid place-items-center font-bold text-lg">KT</div>
      <div>
        <h1 class="font-bold text-slate-800 dark:text-slate-100">CSO Panel</h1>
        <p id="pageTitle" class="text-xs text-slate-500 dark:text-slate-400">Pemesanan Baru</p>
      </div>
    </div>
    <div class="flex items-center gap-1">
      <button id="themeToggleBtn" class="p-2 rounded-full hover:bg-slate-100 dark:hover:bg-slate-700">
          <svg id="theme-icon-dark" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-slate-600 dark:text-slate-300 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" /></svg>
          <svg id="theme-icon-light" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-slate-600 dark:text-slate-300 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
      </button>
      <a href="{{ route('logout') }}" 
        onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
        class="p-2 rounded-full hover:bg-slate-100 dark:hover:bg-slate-700">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-slate-600 dark:text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
          </svg>
      </a>
  
      <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
          @csrf
      </form>

    </div>
    {{-- <button id="logoutBtn" class="p-2 rounded-full hover:bg-slate-100 dark:hover:bg-slate-700">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-slate-600 dark:text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
        </svg>
    </button> --}}
  </header>

  <!-- Main Content Area -->
  <main class="flex-grow p-4 pb-24 space-y-5">
    
    <!-- View: Pemesanan Baru -->
    <section id="view-new" class="view-section space-y-5">
      <!-- Route Selection Card -->
      <div>
        <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-2">1. Pilih Tujuan</h2>
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4 space-y-4">
          <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-200 mb-1">Zona Tujuan</label>
            <select id="toZone" class="w-full rounded-lg border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:border-primary-500 focus:ring-primary-500"></select>
          </div>
        </div>
      </div>
      
      <!-- Tariff Card -->
      <div>
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4 flex items-center justify-between">
            <span class="font-semibold text-slate-700 dark:text-slate-200">Tarif Perjalanan</span>
            <span id="priceBox" class="text-2xl font-bold text-primary-600 dark:text-primary-400">-</span>
        </div>
      </div>

      <!-- Driver Selection Card -->
      <div>
        <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-2">2. Pilih Supir</h2>
        <div id="driversList" class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <!-- Driver cards will be inserted here -->
        </div>
      </div>

      <!-- Confirmation Button -->
      <div class="sticky bottom-24">
        <button id="btnConfirmBooking" disabled class="w-full bg-primary-600 hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-bold py-4 rounded-xl shadow-lg transition-transform active:scale-95">
          Konfirmasi & Proses Pembayaran
        </button>
      </div>
    </section>

    <!-- View: Riwayat -->
    <section id="view-history" class="view-section hidden space-y-5">
        <div>
            <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-2 flex justify-between items-center">
                <span>Riwayat Transaksi Hari Ini</span>
                <button id="refreshHistory" class="p-2 rounded-full hover:bg-slate-200 dark:hover:bg-slate-700">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-600 dark:text-slate-300" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 110 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" />
                    </svg>
                </button>
            </h2>
            <div id="csoTxList" class="space-y-3">
                <!-- History cards will be inserted here -->
            </div>
        </div>
    </section>

  </main>

  <!-- Bottom Navigation -->
  <nav class="bottom-nav bg-white dark:bg-slate-800 w-full fixed bottom-0 left-0 z-20 h-20 flex justify-around items-center border-t border-slate-200 dark:border-slate-700">
    <a href="#new" class="nav-item flex flex-col items-center justify-center text-slate-500 dark:text-slate-400 w-full h-full transition-colors active">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <span class="nav-text text-xs mt-1">Pesan</span>
    </a>
    <a href="#history" class="nav-item flex flex-col items-center justify-center text-slate-500 dark:text-slate-400 w-full h-full transition-colors">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <span class="nav-text text-xs mt-1">Riwayat</span>
    </a>
  </nav>

  <!-- Payment Modal -->
  <div id="paymentModal" class="fixed inset-0 bg-black/60 hidden items-center justify-center p-4 z-30">
    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl max-w-lg w-full p-4 view-section">
      <div class="flex items-center justify-between mb-2">
        <h3 class="font-semibold text-slate-800 dark:text-slate-100">Proses Pembayaran</h3>
        <button id="closePayModal" class="text-slate-500 dark:text-slate-400">✕</button>
      </div>
      <div id="payInfo" class="text-sm text-slate-600 dark:text-slate-300 mb-4 p-3 bg-slate-50 dark:bg-slate-700 rounded-lg"></div>
      <div class="grid grid-cols-1 gap-3">
        <button id="payQRIS" class="rounded-lg border border-slate-300 dark:border-slate-600 p-3 text-left hover:bg-primary-50 dark:hover:bg-slate-700">
          <div class="font-medium text-slate-800 dark:text-slate-100">QRIS</div>
          <div class="text-xs text-slate-500 dark:text-slate-400">Tampilkan QR & konfirmasi</div>
        </button>
        <button id="payCashCSO" class="rounded-lg border border-slate-300 dark:border-slate-600 p-3 text-left hover:bg-primary-50 dark:hover:bg-slate-700">
          <div class="font-medium text-slate-800 dark:text-slate-100">Tunai ke CSO</div>
          <div class="text-xs text-slate-500 dark:text-slate-400">Konfirmasi penerimaan</div>
        </button>
        <button id="payCashDriver" class="rounded-lg border border-slate-300 dark:border-slate-600 p-3 text-left hover:bg-primary-50 dark:hover:bg-slate-700">
          <div class="font-medium text-slate-800 dark:text-slate-100">Tunai ke Supir</div>
          <div class="text-xs text-slate-500 dark:text-slate-400">Penumpang bayar ke supir</div>
        </button>
      </div>
      <div id="qrisBox" class="hidden mt-4 p-3 border border-slate-300 dark:border-slate-600 rounded-lg text-center">
        <div class="text-sm mb-2 text-slate-700 dark:text-slate-200">Pindai QRIS Bank BTN</div>
        <img src="../assets/img/qris-placeholder.svg" alt="QRIS" class="w-48 h-48 mx-auto bg-white rounded-md">
        <button id="confirmQR" class="mt-3 w-full bg-success text-white rounded-lg px-3 py-2 font-semibold">Konfirmasi Pembayaran QRIS</button>
      </div>
    </div>
  </div>

  <!-- Receipt Modal -->
  <div id="receiptModal" class="fixed inset-0 bg-black/60 hidden items-center justify-center p-4 z-30">
    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-[380px] max-w-full p-4 view-section">
      <div class="flex items-center justify-between mb-2">
        <h3 class="font-semibold text-slate-800 dark:text-slate-100">Struk Pembayaran</h3>
        <button id="closeReceipt" class="text-slate-500 dark:text-slate-400">✕</button>
      </div>
      <div class="text-xs text-slate-500 dark:text-slate-400 mb-2">Pratinjau struk (58mm).</div>
      <div id="receiptArea" class="bg-white p-2 rounded-md mx-auto"></div>
      <div class="mt-4 flex gap-2 justify-end">
        <button id="printReceipt" class="rounded-lg border border-slate-300 dark:border-slate-600 px-4 py-2 text-sm font-semibold text-slate-700 dark:text-slate-200">Cetak</button>
        <button id="closeReceipt2" class="bg-primary-600 hover:bg-primary-700 text-white rounded-lg px-4 py-2 text-sm font-semibold">Selesai</button>
      </div>
    </div>
  </div>

  <script type="module">
    import { CsoApp } from '{{ asset("pos-assets/js/cso.js") }}';
    

    // Buat instance dan jalankan aplikasi
    const app = new CsoApp();
    app.init();



  </script>
</body>
</html>

