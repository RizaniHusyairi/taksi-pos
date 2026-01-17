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
          }
        }
      }
    }
    </script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
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
        <h2 class="text-sm font-semibold text-slate-600 dark:text-slate-300 mb-2">2. Pilih Supir (Antrian)</h2>
        
        <div class="flex justify-between text-xs text-slate-400 px-2 mb-1">
            <span>Urutan</span>
            <span></span>
        </div>

        <div id="driversList" class="flex flex-col gap-3">
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
    <section id="view-profile" class="view-section hidden space-y-5">
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-6 text-center">
            <div class="w-20 h-20 bg-primary-100 text-primary-600 rounded-full flex items-center justify-center text-3xl font-bold mx-auto mb-3">
                <span id="profileInitial">U</span>
            </div>
            <h2 id="profileNameDisplay" class="text-xl font-bold text-slate-800 dark:text-slate-100">Nama User</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">Customer Service Officer</p>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4">
            <h3 class="font-semibold text-slate-700 dark:text-slate-200 mb-4 border-b pb-2 border-slate-100 dark:border-slate-700">Edit Biodata</h3>
            <form id="formEditProfile" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Nama Lengkap</label>
                    <input type="text" id="editName" class="w-full rounded-lg border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm" required>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Username</label>
                    <input type="text" id="editUsername" class="w-full rounded-lg border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm" required>
                </div>
                <div class="text-right">
                    <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white text-sm font-bold py-2 px-4 rounded-lg shadow transition-transform active:scale-95">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4">
            <h3 class="font-semibold text-slate-700 dark:text-slate-200 mb-4 border-b pb-2 border-slate-100 dark:border-slate-700">QRIS Pembayaran</h3>
            
            <div class="text-center">
                <div class="w-48 h-48 mx-auto bg-slate-100 dark:bg-slate-900 border-2 border-dashed border-slate-300 dark:border-slate-600 rounded-xl flex items-center justify-center overflow-hidden mb-4 relative group">
                    <img id="profileQrisPreview" src="" class="w-full h-full object-contain hidden">
                    <div id="profileQrisPlaceholder" class="text-slate-400 text-xs">
                        Belum ada QRIS
                    </div>
                    
                    <div id="qrisLoading" class="absolute inset-0 bg-black/50 hidden items-center justify-center text-white text-xs font-bold">
                        Mengupload...
                    </div>
                </div>

                <div class="flex justify-center">
                    <label for="inpUploadQris" class="bg-blue-100 hover:bg-blue-200 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300 px-4 py-2 rounded-lg text-sm font-semibold cursor-pointer transition-colors flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
                        Upload QRIS Baru
                    </label>
                    <input type="file" id="inpUploadQris" accept="image/*" class="hidden">
                </div>
                <p class="text-[10px] text-slate-400 mt-2">Format: JPG/PNG. Max: 2MB. QRIS ini akan muncul saat penumpang memilih pembayaran QRIS.</p>
            </div>
        </div>

      
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4">
            <h3 class="font-semibold text-slate-700 dark:text-slate-200 mb-4 border-b pb-2 border-slate-100 dark:border-slate-700">Ganti Password</h3>
            <form id="formChangePassword" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Password Saat Ini</label>
                    <input type="password" id="currentPass" class="w-full rounded-lg border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm" required>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Password Baru</label>
                    <input type="password" id="newPass" class="w-full rounded-lg border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm" required minlength="6">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">Konfirmasi Password Baru</label>
                    <input type="password" id="confirmPass" class="w-full rounded-lg border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 text-sm" required minlength="6">
                </div>
                <div class="text-right">
                    <button type="submit" class="bg-slate-700 hover:bg-slate-800 dark:bg-slate-600 text-white text-sm font-bold py-2 px-4 rounded-lg shadow transition-transform active:scale-95">
                        Update Password
                    </button>
                </div>
            </form>
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
    <a href="#profile" class="nav-item flex flex-col items-center justify-center text-slate-500 dark:text-slate-400 w-full h-full transition-colors">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
      </svg>
      <span class="nav-text text-xs mt-1">Profil</span>
    </a>
  </nav>

  <!-- Payment Modal -->
  <div id="paymentModal" class="fixed inset-0 bg-black/60 hidden items-center justify-center p-4 z-30 backdrop-blur-sm transition-opacity">
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl max-w-md w-full p-6 view-section relative overflow-hidden flex flex-col max-h-[90vh]">
      
      <div class="flex items-center justify-between mb-4 flex-shrink-0">
        <div>
            <h3 class="font-bold text-xl text-slate-800 dark:text-slate-100">Konfirmasi Order</h3>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Pastikan data perjalanan sudah benar</p>
        </div>
        <button id="closePayModal" class="p-2 bg-slate-100 dark:bg-slate-700 rounded-full text-slate-500 hover:text-slate-700 transition-colors">✕</button>
      </div>

      <div class="overflow-y-auto flex-grow pr-1">
          
          <div id="payInfo" class="mb-6 p-4 bg-primary-50 dark:bg-primary-900/20 border border-primary-100 dark:border-primary-800 rounded-xl"></div>

          <div class="mb-6">
            <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">
                Nomor WhatsApp Penumpang <span class="text-red-500">*</span>
            </label>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <span class="text-slate-500 font-bold text-sm">+62</span>
                </div>
                <input type="tel" id="passengerPhone" 
                    class="w-full pl-12 pr-4 py-3 bg-white dark:bg-slate-700 border-2 border-slate-200 dark:border-slate-600 rounded-xl focus:border-primary-500 focus:ring-4 focus:ring-primary-500/20 transition-all text-slate-800 dark:text-slate-100 font-bold placeholder:font-normal placeholder:text-slate-400" 
                    placeholder="812-3456-7890" inputmode="numeric">
                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-green-500" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                </div>
            </div>
            <p class="text-[10px] text-slate-500 mt-1 ml-1">Struk digital akan dikirim otomatis ke nomor ini.</p>
          </div>

          <div class="space-y-3 mb-6">
            <button id="payQRIS" class="w-full flex items-center gap-3 p-4 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-slate-700 transition-all group focus:outline-none focus:ring-2 focus:ring-blue-500">
                <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center flex-shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" /></svg>
                </div>
                <div class="text-left">
                    <div class="font-bold text-slate-800 dark:text-slate-100 group-hover:text-blue-700">QRIS (Transfer)</div>
                    <div class="text-xs text-slate-500">Upload bukti bayar</div>
                </div>
                <div class="ml-auto text-slate-400">▼</div>
            </button>

            <div id="qrisBox" class="hidden p-4 border border-blue-200 dark:border-slate-600 rounded-xl bg-blue-50/50 dark:bg-slate-700/50 animate-in fade-in slide-in-from-top-2 duration-200">
                
                <div class="text-center mb-4">
                    <p class="text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">Scan Barcode Bank BTN</p>
                    
                    <img id="paymentQrisImage" src="{{ asset('assets/img/qris-placeholder.svg') }}" alt="QRIS" class="w-40 h-40 mx-auto bg-white rounded-lg shadow-sm border p-2 object-contain cursor-zoom-in hover:opacity-90 transition-opacity">
                    
                    <p id="paymentQrisError" class="hidden text-[10px] text-red-500 mt-1 font-bold">⚠️ CSO belum mengupload QRIS</p>
                </div>

                <div class="space-y-2">
                    <p class="text-xs font-bold text-slate-600 dark:text-slate-300">Upload Bukti Transfer</p>
                    
                    <div id="proofPreviewBox" class="hidden w-full h-40 bg-slate-200 dark:bg-slate-800 rounded-lg overflow-hidden relative mb-2 border-2 border-slate-300 dark:border-slate-600">
                        <img id="proofPreviewImg" class="w-full h-full object-contain">
                        <button id="removeProof" class="absolute top-2 right-2 bg-red-600 hover:bg-red-700 text-white rounded-full p-1.5 shadow-md transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <label for="proofInput" class="flex items-center justify-center w-full px-4 py-4 bg-white dark:bg-slate-800 border-2 border-dashed border-blue-300 dark:border-slate-500 rounded-xl cursor-pointer hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-slate-700 transition-all group">
                        <div class="text-center">
                            <svg class="w-8 h-8 mx-auto text-blue-400 group-hover:text-blue-600 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                            <span class="text-xs font-semibold text-slate-600 dark:text-slate-300">Klik untuk Foto Bukti</span>
                        </div>
                        <input type="file" id="proofInput" accept="image/*" capture="environment" class="hidden">
                    </label>
                </div>

                <button id="confirmQR" disabled class="mt-4 w-full bg-slate-300 dark:bg-slate-600 text-slate-500 font-bold py-3 rounded-xl cursor-not-allowed transition-all shadow-sm">
                    Kirim & Konfirmasi Pembayaran
                </button>
            </div>

            <button id="payCashCSO" class="w-full flex items-center gap-3 p-4 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-green-500 hover:bg-green-50 dark:hover:bg-slate-700 transition-all group">
                <div class="w-10 h-10 rounded-full bg-green-100 text-green-600 flex items-center justify-center flex-shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                </div>
                <div class="text-left">
                    <div class="font-bold text-slate-800 dark:text-slate-100 group-hover:text-green-700">Tunai ke CSO</div>
                    <div class="text-xs text-slate-500">Bayar cash di loket</div>
                </div>
            </button>

            <button id="payCashDriver" class="w-full flex items-center gap-3 p-4 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-orange-500 hover:bg-orange-50 dark:hover:bg-slate-700 transition-all group">
                <div class="w-10 h-10 rounded-full bg-orange-100 text-orange-600 flex items-center justify-center flex-shrink-0">
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
  <div id="receiptModal" class="fixed inset-0 bg-black/60 hidden items-center justify-center p-4 z-30">
    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-xl w-[380px] max-w-full p-4 view-section">
      <div class="flex items-center justify-between mb-2">
        <h3 class="font-semibold text-slate-800 dark:text-slate-100">Struk Pembayaran</h3>
        <button id="closeReceipt" class="text-slate-500 dark:text-slate-400">✕</button>
      </div>
      
      <div id="receiptArea" class="bg-white p-2 rounded-md mx-auto border border-slate-200"></div>
      
      <div class="mt-4 text-center">
        <p class="text-xs text-green-600 font-semibold mb-2">✓ Link struk telah dikirim ke WA Penumpang & Supir.</p>
        <button id="closeReceipt2" class="w-full bg-primary-600 hover:bg-primary-700 text-white rounded-lg px-4 py-3 text-sm font-bold">Tutup / Selesai</button>
      </div>
    </div>
  </div>

  <div id="cashConfirmModal" class="fixed inset-0 bg-black/60 hidden items-center justify-center p-4 z-50 backdrop-blur-sm">
    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-2xl max-w-sm w-full p-6 text-center transform transition-all scale-100">
        
        <div class="w-16 h-16 bg-yellow-100 text-yellow-600 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
        </div>

        <h3 class="text-lg font-bold text-slate-800 dark:text-slate-100 mb-2">Konfirmasi Pembayaran</h3>
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">
            Apakah Anda yakin ingin memproses pesanan ini dengan metode <span id="confirmMethodName" class="font-bold text-slate-800 dark:text-white">TUNAI</span>?
        </p>

        <div class="grid grid-cols-2 gap-3">
            <button id="btnCancelCash" class="w-full py-2.5 rounded-lg border border-slate-300 text-slate-600 font-semibold hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-700">
                Batal
            </button>
            <button id="btnProceedCash" class="w-full py-2.5 rounded-lg bg-primary-600 text-white font-bold hover:bg-primary-700 shadow-lg active:scale-95 transition-transform">
                Ya, Proses
            </button>
        </div>
    </div>
  </div>

  <div id="qrisZoomModal" class="fixed inset-0 bg-black/95 hidden items-center justify-center p-4 z-[60] backdrop-blur-sm cursor-zoom-out transition-opacity duration-300">
    <img id="qrisZoomImage" src="" class="max-w-full max-h-full object-contain rounded-lg shadow-2xl scale-95 transition-transform duration-300">
    <div class="absolute bottom-10 text-white/70 text-sm bg-black/50 px-4 py-2 rounded-full pointer-events-none">
        Ketuk layar untuk menutup
    </div>
  </div>

  <script type="module">
    import { CsoApp } from '{{ asset("pos-assets/js/cso.js") }}';
    




  </script>
</body>
</html>

