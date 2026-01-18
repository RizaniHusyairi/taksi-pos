<!DOCTYPE html>
<html lang="id" class="">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <meta name="csrf-token" content="{{ csrf_token() }}">

  <title>Supir - Taksi POS</title>
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
        <h1 class="font-bold text-slate-800 dark:text-slate-100">Taksi POS</h1>
        <p id="pageTitle" class="text-xs text-slate-500 dark:text-slate-400">Beranda</p>
      </div>
    </div>
  </header>

  <!-- Main Content Area -->
  <main class="flex-grow p-4 pb-24 space-y-5">

    <!-- View: Beranda / Pesanan -->
    <section id="view-orders" class="view-section space-y-5">
      <!-- Active Order Card -->
      <h2 id="text-book" class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-2">Pesanan Aktif</h2>
      <div id="activeOrderBox" class="hidden">
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
        <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-2">Antrian Bandara</h2>
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4 space-y-4">
          
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-slate-500 dark:text-slate-400">Status Saat Ini</div>
              <div id="driverStatusText" class="font-bold text-lg text-slate-800 dark:text-slate-100">Offline</div>
            </div>
            <div class="text-right">
              <div class="text-xs text-slate-500 dark:text-slate-400">Jarak ke Titik Kumpul</div>
              <div id="distanceText" class="font-mono font-semibold text-primary-600 dark:text-primary-400">Mencari GPS...</div>
            </div>
          </div>

          <button id="btnQueueAction" disabled class="w-full py-3 rounded-xl font-bold text-white shadow-md transition-all disabled:opacity-50 disabled:cursor-not-allowed bg-slate-400">
            Memuat Lokasi...
          </button>
          
          <p class="text-[10px] text-center text-slate-400">
            *Fitur ini menggunakan GPS. Pastikan Anda berada dalam radius 2 KM dari Bandara APT Pranoto.
          </p>
        </div>
      </div>
    </section>

    <!-- View: Dompet -->
    <section id="view-wallet" class="view-section hidden space-y-5">
      <div>
          <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-2">Dompet Saya</h2>
          <div class="bg-gradient-to-br from-primary-600 to-primary-800 rounded-xl shadow-xl p-5 text-white">
              <div class="flex justify-between items-start">
                  <div>
                      <p class="text-sm opacity-80">Saldo Bersih Siap Cair</p>
                      <p id="walletBalance" class="text-4xl font-bold tracking-tight">Rp0</p>
                  </div>
                  <div class="w-8 h-8 rounded-full bg-white/20 text-white grid place-items-center font-bold">KT</div>
              </div>
              <div class="mt-4 pt-4 border-t border-white/20 flex justify-between text-xs opacity-90">
                  <span>Pemasukan: <span id="infoIncome" class="font-bold">Rp0</span></span>
                  <span>Potongan Hutang: <span id="infoDebt" class="font-bold text-red-200">Rp0</span></span>
              </div>
          </div>
      </div>

      <div>
        <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-2">Aksi</h2>
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4 text-center">
          <p class="text-xs text-slate-500 mb-4">
            Klik tombol di bawah untuk mencairkan seluruh saldo bersih yang tersedia dari riwayat pesanan.
          </p>
          <button id="btnRequestWithdrawal" class="w-full bg-primary-600 hover:bg-primary-700 text-white font-bold py-3 rounded-lg shadow-md transition-transform active:scale-95 disabled:bg-slate-400 disabled:cursor-not-allowed">
             Cairkan Dana Sekarang
          </button>
        </div>
      </div>

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
            
          <div class="bg-white dark:bg-slate-800 rounded-xl shadow-[0_4px_15px_rgb(0,0,0,0.05)] p-5 relative overflow-hidden">
              
              <div class="absolute top-0 right-0 -mt-8 -mr-8 w-32 h-32 bg-primary-50 dark:bg-primary-900/20 rounded-full blur-2xl pointer-events-none"></div>

              <div class="relative z-10">
                  <div class="flex items-start gap-4 mb-4">
                      <div class="w-14 h-14 rounded-full bg-gradient-to-br from-primary-100 to-primary-50 dark:from-primary-900 dark:to-slate-800 text-primary-600 dark:text-primary-400 grid place-items-center text-2xl font-bold border-2 border-white dark:border-slate-700 shadow-sm flex-shrink-0">
                          <span id="profileInitial">A</span>
                      </div>
                      <div class="flex-1 pt-1">
                          <h3 id="profileName" class="font-bold text-lg text-slate-800 dark:text-slate-100 leading-tight truncate">Nama Supir</h3>
                           <div class="flex items-center gap-1 text-sm text-slate-500 dark:text-slate-400 mt-1">
                              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 opacity-70">
                                  <path fill-rule="evenodd" d="M2 3.5A1.5 1.5 0 013.5 2h1.148a1.5 1.5 0 011.465 1.175l.716 3.223a1.5 1.5 0 01-1.052 1.767l-.933.267c-.41.117-.643.555-.48.95a11.542 11.542 0 006.254 6.254c.395.163.833-.07.95-.48l.267-.933a1.5 1.5 0 011.767-1.052l3.223.716A1.5 1.5 0 0118 15.352V16.5a1.5 1.5 0 01-1.5 1.5H15c-1.149 0-2.263-.15-3.326-.43A13.022 13.022 0 012.43 8.326 13.019 13.019 0 012 5V3.5z" clip-rule="evenodd" />
                                </svg>
                              <p id="profilePhone">-</p>
                          </div>
                      </div>
                  </div>

                  <button id="btnOpenEditProfile" class="w-full mb-4 py-2.5 px-4 border border-primary-200 dark:border-primary-800 hover:bg-primary-50 dark:hover:bg-primary-900/30 text-primary-700 dark:text-primary-300 rounded-lg text-sm font-semibold transition-all active:scale-[0.98] flex items-center justify-center gap-2 group">
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 text-primary-500 group-hover:text-primary-700 transition-colors">
                          <path d="M5.433 13.917l1.262-3.155A4 4 0 017.58 9.42l6.92-6.918a2.121 2.121 0 013 3l-6.92 6.918c-.383.383-.84.685-1.343.886l-3.154 1.262a.5.5 0 01-.65-.65z" />
                          <path d="M3.5 5.75c0-.69.56-1.25 1.25-1.25H10A.75.75 0 0010 3H4.75A2.75 2.75 0 002 5.75v9.5A2.75 2.75 0 004.75 18h9.5A2.75 2.75 0 0017 15.25V10a.75.75 0 00-1.5 0v5.25c0 .69-.56 1.25-1.25 1.25h-9.5c-.69 0-1.25-.56-1.25-1.25v-9.5z" />
                        </svg>
                      <span>Edit Profil & Kendaraan</span>
                  </button>

                  <div class="pt-3 border-t border-slate-100 dark:border-slate-700/50 space-y-2">
                      <div class="flex justify-between items-center text-sm">
                          <span class="text-slate-500 dark:text-slate-400 flex items-center gap-2">
                              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 opacity-60">
                                  <path fill-rule="evenodd" d="M2 5a2 2 0 012-2h12a2 2 0 012 2v10a2 2 0 01-2 2H4a2 2 0 01-2-2V5zm3.172 6a2 2 0 10-2.829-2.828 2 2 0 002.829 2.828zm2.828-4a2 2 0 11-2.828 2.828 2 2 0 012.828-2.828zm10 0a2 2 0 11-2.828 2.828 2 2 0 012.828-2.828zm-2.828 4a2 2 0 102.828-2.828 2 2 0 00-2.828 2.828z" clip-rule="evenodd" />
                                </svg>
                              Kendaraan
                          </span>
                          <span id="profileCar" class="font-semibold text-slate-700 dark:text-slate-200">-</span>
                      </div>
                      <div class="flex justify-between items-center text-sm">
                          <span class="text-slate-500 dark:text-slate-400 flex items-center gap-2">
                              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 opacity-60">
                                  <path d="M3 4a2 2 0 00-2 2v1.161l8.441 4.221a1.25 1.25 0 001.118 0L19 7.162V6a2 2 0 00-2-2H3z" />
                                  <path d="M19 8.839l-7.77 3.885a2.75 2.75 0 01-2.46 0L1 8.839V14a2 2 0 002 2h14a2 2 0 002-2V8.839z" />
                                </svg>
                              Email
                          </span>
                          <span id="profileEmail" class="font-semibold text-slate-700 dark:text-slate-200 truncate max-w-[180px]">-</span>
                      </div>
                  </div>
              </div>
          </div>
            
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4 space-y-3 mt-3">
              <div class="flex justify-between items-center">
                  <h3 class="font-semibold text-slate-700 dark:text-slate-200">Rekening Pencairan</h3>
                  <button id="btnEditBank" class="text-xs text-primary-600 font-bold">Ubah</button>
              </div>
              <div>
                  <p class="text-xs text-slate-500 dark:text-slate-400">Bank & No. Rek</p>
                  <p id="txtBankInfo" class="font-semibold text-slate-800 dark:text-slate-100 text-lg">-</p>
              </div>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4 space-y-3 mt-3">
              <h3 class="font-semibold text-slate-700 dark:text-slate-200 border-b border-slate-100 dark:border-slate-700 pb-2 mb-3">Keamanan Akun</h3>
              
              <form id="formChangePassword" class="space-y-4">
                  <div>
                      <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Password Saat Ini</label>
                      <input type="password" id="currentPass" class="w-full rounded-lg border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm focus:border-primary-500 focus:ring-primary-500" required>
                  </div>
                  <div>
                      <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Password Baru</label>
                      <input type="password" id="newPass" class="w-full rounded-lg border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm focus:border-primary-500 focus:ring-primary-500" required minlength="6">
                  </div>
                  <div>
                      <label class="block text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Konfirmasi Password Baru</label>
                      <input type="password" id="confirmPass" class="w-full rounded-lg border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm focus:border-primary-500 focus:ring-primary-500" required minlength="6">
                  </div>
                  <div class="pt-2">
                      <button type="submit" class="w-full bg-slate-700 hover:bg-slate-800 dark:bg-slate-600 text-white text-sm font-bold py-3 rounded-lg shadow transition-transform active:scale-95">
                          Ganti Password
                      </button>
                  </div>
              </form>
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
                
            
                <a href="{{ route('logout') }}" 
                  onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
                  class="w-full flex items-center justify-center gap-2 bg-danger/10 hover:bg-danger/20 text-danger font-bold py-3 rounded-lg ...">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 102 0V4a1 1 0 00-1-1zm10.293 9.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L14.586 9H7a1 1 0 100 2h7.586l-1.293 1.293z" clip-rule="evenodd" />
                    </svg>
                    Keluar
                </a>
                <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
                    @csrf
                </form>
            </div>
        </div>
    </section>

  </main>

  <div id="bankModal" class="fixed inset-0 bg-black/60 hidden items-center justify-center p-4 z-50">
      <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-sm p-4">
          <h3 class="font-bold text-lg mb-4 text-slate-800 dark:text-white">Atur Rekening Bank</h3>
          
          <form id="formBank" class="space-y-4">
              
              <div class="bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 rounded-lg p-3 flex items-start gap-3">
                  <div class="text-blue-600 dark:text-blue-400 mt-0.5">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                          <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                      </svg>
                  </div>
                  <div>
                      <p class="text-sm font-bold text-blue-800 dark:text-blue-200">Wajib Bank BTN</p>
                      <p class="text-xs text-blue-700 dark:text-blue-300 mt-1">
                          Pencairan dana hanya dapat dilakukan ke rekening Bank BTN.
                      </p>
                  </div>
              </div>

              <div>
                  <label class="block text-sm mb-1 text-slate-600 dark:text-slate-300 font-medium">Nomor Rekening BTN</label>
                  <input id="inAccNumber" type="number" placeholder="Contoh: 000123xxxx" class="w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white focus:border-blue-500 focus:ring-blue-500 p-2.5" required>
              </div>

              <div class="flex gap-2 justify-end mt-4">
                  <button type="button" id="closeBankModal" class="px-4 py-2 text-slate-500 hover:text-slate-700 dark:text-slate-400">Batal</button>
                  <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg font-bold shadow transition-transform active:scale-95">Simpan</button>
              </div>
          </form>
      </div>
  </div>

  <div id="editProfileModal" class="fixed inset-0 bg-black/60 hidden items-center justify-center p-4 z-50">
    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-sm p-4 max-h-[90vh] overflow-y-auto">
        <h3 class="font-bold text-lg mb-4 text-slate-800 dark:text-white">Edit Profil</h3>
        
        <form id="formEditProfile" class="space-y-3">
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Nama Lengkap</label>
                <input id="editName" type="text" class="w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white text-sm" required>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">Username</label>
                <input id="editUsername" type="text" class="w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white text-sm" required>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">No. HP / WA</label>
                    <input id="editPhone" type="text" class="w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white text-sm" required>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Email</label>
                    <input id="editEmail" type="email" class="w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white text-sm" required>
                </div>
            </div>

            <div class="border-t border-slate-100 dark:border-slate-700 my-2"></div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Model Mobil</label>
                    <input id="editCarModel" type="text" placeholder="Avanza" class="w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white text-sm" required>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Plat Nomor</label>
                    <input id="editPlate" type="text" placeholder="KT 1234 XX" class="w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-700 dark:text-white text-sm" required>
                </div>
            </div>

            <div class="flex gap-2 justify-end mt-4 pt-2">
                <button type="button" id="closeEditProfile" class="px-4 py-2 text-slate-500 text-sm">Batal</button>
                <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white px-4 py-2 rounded-lg text-sm font-bold shadow">Simpan</button>
            </div>
        </form>
    </div>
</div>

  <div id="proofModal" class="fixed inset-0 bg-black/90 hidden items-center justify-center p-4 z-50 backdrop-blur-sm" onclick="this.classList.add('hidden')">
    <div class="relative max-w-sm w-full animate-in fade-in zoom-in duration-200">
        <img id="imgProof" src="" class="w-full rounded-xl shadow-2xl border-2 border-white/20 object-contain max-h-[80vh] bg-black">
        
        <div class="text-center mt-4">
            <span class="text-xs text-white/80 bg-white/10 px-3 py-1.5 rounded-full">
                Ketuk layar untuk menutup
            </span>
        </div>
    </div>
  </div>

  <div id="leaveQueueModal" class="fixed inset-0 bg-black/60 hidden items-center justify-center p-4 z-50">
    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-full max-w-sm p-5">
        <h3 class="font-bold text-lg mb-4 text-slate-800 dark:text-white">Konfirmasi Keluar</h3>
        
        <form id="formLeaveQueue" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-2">Alasan Keluar Antrian:</label>
                
                <div class="space-y-2">
                    <label class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700">
                        <input type="radio" name="reason" value="self" class="w-4 h-4 text-primary-600">
                        <span class="text-sm text-slate-700 dark:text-slate-200">Dapat Penumpang Sendiri</span>
                    </label>
                    <label class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700">
                        <input type="radio" name="reason" value="other" class="w-4 h-4 text-primary-600" checked>
                        <span class="text-sm text-slate-700 dark:text-slate-200">Alasan Lainnya (Istirahat/Pulang)</span>
                    </label>
                </div>
            </div>

            <div id="boxSelfPassenger" class="hidden space-y-3 pt-2 border-t border-dashed">
                <div class="p-3 bg-yellow-50 text-yellow-800 text-xs rounded-lg">
                    Saldo dompet akan <strong>dipotong Rp 10.000</strong> sebagai komisi koperasi.
                </div>
                <div>
                    <label class="block text-xs mb-1 text-slate-500">Tujuan Penumpang</label>
                    <input type="text" id="manualDest" placeholder="Contoh: Hotel Bumi Senyiur" class="w-full rounded-lg border-slate-300 text-sm">
                </div>
                <div>
                    <label class="block text-xs mb-1 text-slate-500">Nominal Deal (Rp)</label>
                    <input type="number" id="manualPrice" placeholder="Contoh: 150000" class="w-full rounded-lg border-slate-300 text-sm">
                </div>
            </div>

            <div id="boxOtherReason" class="block space-y-3">
                <div>
                    <label class="block text-xs mb-1 text-slate-500">Keterangan (Opsional)</label>
                    <textarea id="otherReasonText" rows="2" class="w-full rounded-lg border-slate-300 text-sm" placeholder="Contoh: Mau makan siang"></textarea>
                </div>
            </div>

            <div class="flex gap-2 justify-end mt-4">
                <button type="button" id="btnCancelLeave" class="px-4 py-2 text-slate-500 text-sm">Batal</button>
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-bold">Proses Keluar</button>
            </div>
        </form>
    </div>
  </div>

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

  <script>
      // Inject Config dari Laravel ke JS Global
      window.taksiConfig = {
          driver_queue: @json(config('taksi.driver_queue'))
      };
  </script>
  <script type="module">
    import { DriverApp } from '{{ asset("pos-assets/js/driver.js") }}';

    const app = new DriverApp();
    app.init();

  </script>
</body>
</html>
