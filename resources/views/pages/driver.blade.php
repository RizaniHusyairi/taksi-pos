<!DOCTYPE html>
<html lang="id" class="">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>Supir - Koperasi Taksi POS</title>
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
    /* Custom styles for the mobile app feel */
    body {
      -webkit-tap-highlight-color: transparent;
    }
    .view-section {
      animation: fadeIn 0.3s ease-in-out;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .bottom-nav {
      box-shadow: 0 -2px 10px rgba(0,0,0,0.08);
    }
    .nav-item.active svg { color: #2563eb; }
    .nav-item.active .nav-text { color: #2563eb; font-weight: 600; }
    
    /* Dark Mode Styles */
    .dark .bottom-nav { border-top-color: #334155; } /* slate-700 */
    .dark .nav-item.active .nav-text, .dark .nav-item.active svg { color: #60a5fa; } /* primary-400 */
  </style>
</head>
<body class="bg-slate-100 dark:bg-slate-900 min-h-screen flex flex-col font-sans transition-colors duration-300">

  <!-- Top Header -->
  <header class="bg-white/80 dark:bg-slate-800/80 backdrop-blur-sm border-b border-slate-200 dark:border-slate-700 p-4 flex items-center justify-between sticky top-0 z-20">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-full bg-primary-600 text-white grid place-items-center font-bold text-lg">KT</div>
      <div>
        <h1 class="font-bold text-slate-800 dark:text-slate-100">Koperasi Taksi POS</h1>
        <p id="pageTitle" class="text-xs text-slate-500 dark:text-slate-400">Beranda</p>
      </div>
    </div>
  </header>

  <!-- Main Content Area -->
  <main class="flex-grow p-4 pb-24 space-y-5">

    <!-- View: Beranda / Pesanan -->
    <section id="view-orders" class="view-section space-y-5">
      <!-- Active Order Card -->
      <div id="activeOrderBox" class="hidden">
        <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-2">Pesanan Aktif</h2>
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-lg border-2 border-pending p-4">
          <div class="flex items-center justify-between mb-3">
            <div class="font-bold text-slate-800 dark:text-slate-100">Anda Punya Order Baru!</div>
            <div class="text-xs bg-pending text-white px-2 py-0.5 rounded-full font-semibold animate-pulse">BARU</div>
          </div>
          <div id="activeOrderInfo" class="space-y-1 text-sm text-slate-700 dark:text-slate-300"></div>
          <button id="markComplete" class="mt-4 w-full bg-success hover:bg-emerald-600 text-white font-bold py-3 rounded-lg shadow-md transition-transform active:scale-95">
            Selesaikan Perjalanan
          </button>
        </div>
      </div>

      <!-- Driver Status Card -->
      <div>
        <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-2">Status Kerja</h2>
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4 flex items-center justify-between">
          <div>
            <div class="font-bold text-slate-800 dark:text-slate-100">Status Saya</div>
            <div id="driverStatusText" class="text-sm text-slate-500 dark:text-slate-400">Offline</div>
          </div>
          <label for="statusToggle" class="relative inline-flex items-center cursor-pointer">
            <input type="checkbox" id="statusToggle" class="sr-only peer">
            <div class="w-14 h-8 bg-slate-300 dark:bg-slate-600 rounded-full peer peer-focus:ring-2 peer-focus:ring-primary-300 peer-checked:bg-success transition-colors"></div>
            <span class="absolute left-1 top-1 w-6 h-6 bg-white rounded-full shadow-md transition-transform peer-checked:translate-x-6"></span>
          </label>
        </div>
      </div>
    </section>

    <!-- View: Dompet -->
    <section id="view-wallet" class="view-section hidden space-y-5">
      <!-- Wallet Balance Card -->
      <div>
          <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-2">Dompet Saya</h2>
          <div class="bg-gradient-to-br from-primary-600 to-primary-800 rounded-xl shadow-xl p-5 text-white">
              <div class="flex justify-between items-start">
                  <div>
                      <p class="text-sm opacity-80">Total Saldo</p>
                      <p id="walletBalance" class="text-4xl font-bold tracking-tight">Rp0</p>
                  </div>
                  <div class="w-8 h-8 rounded-full bg-white/20 text-white grid place-items-center font-bold">KT</div>
              </div>
              <p class="text-xs opacity-70 mt-4">*Saldo setelah dipotong komisi koperasi.</p>
          </div>
      </div>

      <!-- Withdrawal Form Card -->
      <div>
        <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-2">Tarik Dana</h2>
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4">
          <form id="formWd" class="space-y-3">
            <div>
              <label class="block text-sm font-medium text-slate-700 dark:text-slate-200 mb-1">Jumlah Penarikan (Rp)</label>
              <input id="wdAmount" type="number" min="10000" placeholder="Contoh: 50000" class="w-full rounded-lg border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:border-primary-500 focus:ring-primary-500" required>
            </div>
            <button class="w-full bg-primary-600 hover:bg-primary-700 text-white font-bold py-3 rounded-lg shadow-md transition-transform active:scale-95">Ajukan Penarikan</button>
          </form>
        </div>
      </div>

      <!-- Withdrawal History Card -->
      <div>
        <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-2">Riwayat Penarikan</h2>
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-slate-500 dark:text-slate-400">
                  <th class="py-2 font-semibold">Tanggal</th>
                  <th class="py-2 font-semibold">Jumlah</th>
                  <th class="py-2 font-semibold">Status</th>
                </tr>
              </thead>
              <tbody id="wdList" class="text-slate-700 dark:text-slate-300"></tbody>
            </table>
          </div>
        </div>
      </div>
    </section>

    <!-- View: Riwayat -->
    <section id="view-history" class="view-section hidden space-y-5">
       <!-- Filter Card -->
       <div>
        <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-2">Filter Riwayat</h2>
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-3">
          <div class="flex items-center gap-2">
            <input type="date" id="histFrom" class="w-full rounded-lg border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm p-2">
            <span class="text-slate-500 dark:text-slate-400">-</span>
            <input type="date" id="histTo" class="w-full rounded-lg border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm p-2">
            <button id="histFilter" class="p-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-700">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-600 dark:text-slate-300" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" /></svg>
            </button>
          </div>
        </div>
      </div>
      <!-- Trip History List -->
      <div>
        <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-2">Riwayat Perjalanan</h2>
        <div id="tripList" class="space-y-3">
          <!-- Trip cards will be inserted here by JS -->
        </div>
      </div>
    </section>
    
    <!-- View: Profil -->
    <section id="view-profile" class="view-section hidden space-y-5">
        <!-- Profile Info Card -->
        <div>
            <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-2">Informasi Akun</h2>
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4 space-y-3">
                <div class="flex items-center gap-4">
                    <div class="w-16 h-16 rounded-full bg-primary-100 dark:bg-primary-900/50 text-primary-600 dark:text-primary-400 grid place-items-center text-3xl font-bold">
                        <span id="profileInitial">A</span>
                    </div>
                    <div>
                        <p id="profileName" class="font-bold text-lg text-slate-800 dark:text-slate-100">Nama Supir</p>
                        <p class="text-sm text-slate-500 dark:text-slate-400">Supir Koperasi Taksi</p>
                    </div>
                </div>
                <div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Kendaraan</p>
                    <p id="profileCar" class="font-semibold text-slate-700 dark:text-slate-200">Avanza - B 1234 CD</p>
                </div>
            </div>
        </div>
         <!-- Theme Settings Card -->
         <div>
            <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-2">Pengaturan Tampilan</h2>
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4">
                <div class="flex items-center justify-between">
                    <p class="font-semibold text-slate-700 dark:text-slate-200">Mode Gelap</p>
                    <button id="theme-toggle" class="p-2 rounded-full hover:bg-slate-100 dark:hover:bg-slate-700">
                        <!-- Icons will be swapped by JS -->
                    </button>
                </div>
            </div>
        </div>
         <!-- Logout Button Card -->
         <div>
            <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-2">Aksi</h2>
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4">
                <button id="logoutBtn" class="w-full flex items-center justify-center gap-2 bg-danger/10 hover:bg-danger/20 text-danger font-bold py-3 rounded-lg transition-transform active:scale-95">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 102 0V4a1 1 0 00-1-1zm10.293 9.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L14.586 9H7a1 1 0 100 2h7.586l-1.293 1.293z" clip-rule="evenodd" />
                    </svg>
                    Keluar
                </button>
            </div>
        </div>
    </section>

  </main>

  <!-- Bottom Navigation -->
  <nav class="bottom-nav bg-white dark:bg-slate-800 w-full fixed bottom-0 left-0 z-20 h-20 flex justify-around items-center border-t border-slate-200 dark:border-slate-700">
    <a href="#orders" class="nav-item flex flex-col items-center justify-center text-slate-500 dark:text-slate-400 w-full h-full transition-colors active">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>
      <span class="nav-text text-xs mt-1">Beranda</span>
    </a>
    <a href="#wallet" class="nav-item flex flex-col items-center justify-center text-slate-500 dark:text-slate-400 w-full h-full transition-colors">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg>
      <span class="nav-text text-xs mt-1">Dompet</span>
    </a>
    <a href="#history" class="nav-item flex flex-col items-center justify-center text-slate-500 dark:text-slate-400 w-full h-full transition-colors">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
      <span class="nav-text text-xs mt-1">Riwayat</span>
    </a>
    <a href="#profile" class="nav-item flex flex-col items-center justify-center text-slate-500 dark:text-slate-400 w-full h-full transition-colors">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
      <span class="nav-text text-xs mt-1">Profil</span>
    </a>
  </nav>

  <script type="module">
    import { DriverApp } from '{{ asset("pos-assets/js/driver.js") }}';

    const app = new DriverApp();
    app.init();

  </script>
</body>
</html>
