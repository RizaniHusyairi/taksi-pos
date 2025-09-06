<!DOCTYPE html>
<html lang="id" class="">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
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
    <button id="logoutBtn" class="p-2 rounded-full hover:bg-slate-100 dark:hover:bg-slate-700">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-slate-600 dark:text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
        </svg>
    </button>
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
    import { requireRole, logout, currentUser } from '../assets/js/auth.js';
    import { DB } from '../assets/js/data.js';
    import { Utils } from '../assets/js/utils.js';

    requireRole('cso');

    class CsoApp {
      init() {
        this.u = currentUser();
        this.initTheme();
        this.cacheEls();
        this.bind();
        this.renderAll();
        window.addEventListener('hashchange', () => this.route());
        this.route();
      }

      initTheme() {
        const updateTheme = () => {
            if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        };
        updateTheme();
      }

      cacheEls() {
        this.views = ['new', 'history'];
        this.pageTitle = document.getElementById('pageTitle');
        this.toSel = document.getElementById('toZone');
        this.priceBox = document.getElementById('priceBox');
        this.driversList = document.getElementById('driversList');
        this.btnConfirm = document.getElementById('btnConfirmBooking');
        this.modal = document.getElementById('paymentModal');
        this.payInfo = document.getElementById('payInfo');
        this.btnClosePay = document.getElementById('closePayModal');
        this.btnQRIS = document.getElementById('payQRIS');
        this.btnCashCSO = document.getElementById('payCashCSO');
        this.btnCashDriver = document.getElementById('payCashDriver');
        this.qrisBox = document.getElementById('qrisBox');
        this.btnConfirmQR = document.getElementById('confirmQR');
        this.historyList = document.getElementById('csoTxList');
        this.refreshHistoryBtn = document.getElementById('refreshHistory');
        this.lastReceiptData = null; // To store data for clean printing
      }

      bind() {
        document.getElementById('logoutBtn').addEventListener('click', () => logout());

        const onZoneChange = () => {
          const to = this.toSel.value;
          const zone = DB.getZoneById(to);
          this.priceBox.textContent = zone ? Utils.formatCurrency(zone.price) : '-';
          this.btnConfirm.disabled = !zone || !this.selectedDriver;
        };
        this.toSel.addEventListener('change', onZoneChange);

        this.refreshHistoryBtn.addEventListener('click', () => this.renderHistory());

        this.btnConfirm.addEventListener('click', (e) => {
          e.preventDefault();
          if (!this.selectedDriver) { Utils.showToast('Pilih supir terlebih dahulu', 'error'); return; }
          const to = this.toSel.value;
          const zone = DB.getZoneById(to);
          if (!zone) { Utils.showToast('Tarif belum diatur untuk rute ini', 'error'); return; }
          this.currentBooking = DB.createBooking({ csoId: this.u.id, driverId: this.selectedDriver, from: 'z0', to, price: zone.price });
          this.openPayment();
        });

        this.btnClosePay.addEventListener('click', () => this.closePayment());
        this.modal.addEventListener('click', (e) => { if (e.target === this.modal) this.closePayment(); });
        this.btnQRIS.addEventListener('click', () => { this.qrisBox.classList.remove('hidden'); });
        this.btnConfirmQR.addEventListener('click', () => this.finishPayment('QRIS'));
        this.btnCashCSO.addEventListener('click', () => this.finishPayment('CashCSO'));
        this.btnCashDriver.addEventListener('click', () => this.finishPayment('CashDriver'));
        
        document.getElementById('closeReceipt')?.addEventListener('click', () => this.hideReceipt());
        document.getElementById('closeReceipt2')?.addEventListener('click', () => this.hideReceipt());
        document.getElementById('printReceipt')?.addEventListener('click', () => this.printReceiptHandler());

        this.historyList.addEventListener('click', (e) => {
          const btn = e.target.closest('.btn-view-receipt');
          if (!btn) return;
          const txId = btn.dataset.tx;
          const tx = DB.listTransactions().find(x => x.id === txId);
          if (!tx) return Utils.showToast('Transaksi tidak ditemukan', 'error');
          const booking = DB.listBookings().find(b => b.id === tx.bookingId);
          this.showReceipt(tx, booking);
        });
      }

      route() {
        const hash = (location.hash || '#new').slice(1);
        this.views.forEach(v => {
          const el = document.getElementById('view-' + v);
          if (el) el.classList.toggle('hidden', v !== hash);
        });
        document.querySelectorAll('.nav-item').forEach(item => {
          item.classList.toggle('active', item.getAttribute('href') === '#' + hash);
        });
        const titles = { new: 'Pemesanan Baru', history: 'Riwayat Transaksi' };
        this.pageTitle.textContent = titles[hash] || 'Pemesanan Baru';
      }

      renderAll() {
        this.renderZones();
        this.renderDrivers();
        this.renderHistory();
      }

      renderZones() {
        const zones = DB.listZones().filter(z => z.id !== 'z0');
        const opts = zones.map(z => `<option value="${z.id}">${z.name}</option>`).join('');
        this.toSel.innerHTML = '<option value="">Pilih Tujuan</option>' + opts;
      }

      renderDrivers() {
        
        
        this.driversList.innerHTML = `<p class="text-slate-500">Memuat data supir...</p>`;

        try {
          // Panggil API Laravel secara asynchronous
          const response = await fetch('/api/drivers/available', {
              headers: {
                  'Accept': 'application/json',
                  // Untuk keamanan, Laravel butuh token otentikasi
                  'Authorization': `Bearer ${localStorage.getItem('api_token')}` 
              }
          });

          if (!response.ok) throw new Error('Gagal memuat data.');

          const drivers = await response.json(); // Data JSON dari Laravel

          // Logika render HTML tetap sama, hanya sumber datanya yang berbeda
          this.driversList.innerHTML = drivers.map(d => {
              // ... kode untuk membuat HTML card supir
              const st = DB.getDriverStatus(d.id);
              const avail = st === 'available';
              const statusText = avail ? 'Tersedia' : (st === 'ontrip' ? 'Sedang jalan' : 'Offline');
              return `
                <button type="button" data-driver="${d.id}" class="driver-card border-2 ${avail ? 'border-slate-200 dark:border-slate-700' : 'border-dashed border-slate-300 dark:border-slate-600'} rounded-xl p-3 text-left transition-all ${avail ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed'}">
                  <div class="flex items-center justify-between">
                    <div>
                      <div class="font-bold text-slate-800 dark:text-slate-100">${d.name}</div>
                      <div class="text-xs text-slate-500 dark:text-slate-400">${d.car || '-'} • ${d.plate || '-'}</div>
                    </div>
                    <div class="text-xs font-semibold px-2 py-0.5 rounded-full ${avail ? 'bg-success/10 text-success' : 'bg-slate-100 dark:bg-slate-600 text-slate-500 dark:text-slate-300'}">${statusText}</div>
                  </div>
                </button>`;
          }).join('');
          this.driversList.querySelectorAll('[data-driver]').forEach(btn => {
          btn.addEventListener('click', () => {
            const id = btn.dataset.driver;
            if (DB.getDriverStatus(id) !== 'available') return;
            this.selectedDriver = id;
            this.driversList.querySelectorAll('button').forEach(b => b.classList.remove('selected'));
            btn.classList.add('selected');
            const zone = DB.getZoneById(this.toSel.value);
            this.btnConfirm.disabled = !zone;
          });
        });

        } catch (error) {
          this.driversList.innerHTML = `<p class="text-danger">Gagal memuat data supir.</p>`;
          console.error(error);
        }
        
        
        
        const drivers = DB.listDrivers({ onlyActive: true });
        this.selectedDriver = null;
        this.driversList.innerHTML = drivers.map(d => {
          const st = DB.getDriverStatus(d.id);
          const avail = st === 'available';
          const statusText = avail ? 'Tersedia' : (st === 'ontrip' ? 'Sedang jalan' : 'Offline');
          return `
            <button type="button" data-driver="${d.id}" class="driver-card border-2 ${avail ? 'border-slate-200 dark:border-slate-700' : 'border-dashed border-slate-300 dark:border-slate-600'} rounded-xl p-3 text-left transition-all ${avail ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed'}">
              <div class="flex items-center justify-between">
                <div>
                  <div class="font-bold text-slate-800 dark:text-slate-100">${d.name}</div>
                  <div class="text-xs text-slate-500 dark:text-slate-400">${d.car || '-'} • ${d.plate || '-'}</div>
                </div>
                <div class="text-xs font-semibold px-2 py-0.5 rounded-full ${avail ? 'bg-success/10 text-success' : 'bg-slate-100 dark:bg-slate-600 text-slate-500 dark:text-slate-300'}">${statusText}</div>
              </div>
            </button>`;
        }).join('');
        this.driversList.querySelectorAll('[data-driver]').forEach(btn => {
          btn.addEventListener('click', () => {
            const id = btn.dataset.driver;
            if (DB.getDriverStatus(id) !== 'available') return;
            this.selectedDriver = id;
            this.driversList.querySelectorAll('button').forEach(b => b.classList.remove('selected'));
            btn.classList.add('selected');
            const zone = DB.getZoneById(this.toSel.value);
            this.btnConfirm.disabled = !zone;
          });
        });
      }

      openPayment() {
        const zones = DB.listZones();
        const zname = id => zones.find(z => z.id === id)?.name || id;
        this.qrisBox.classList.add('hidden');
        const b = this.currentBooking;
        const driverName = DB.listDrivers().find(d => d.id === b.driverId)?.name;
        this.payInfo.innerHTML = `
          <div class="space-y-1">
            <div class="flex justify-between"><span>Rute:</span> <span class="font-semibold text-right">${zname(b.from)} → ${zname(b.to)}</span></div>
            <div class="flex justify-between"><span>Supir:</span> <span class="font-semibold">${driverName}</span></div>
            <div class="flex justify-between"><span>Tarif:</span> <span class="font-semibold">${Utils.formatCurrency(b.price)}</span></div>
          </div>`;
        this.modal.classList.remove('hidden');
        this.modal.classList.add('flex');
      }

      closePayment() {
        this.modal.classList.add('hidden');
        this.modal.classList.remove('flex');
        this.currentBooking = null;
        this.renderDrivers();
      }

      finishPayment(method) {
        const booking = this.currentBooking;
        if (!booking) { Utils.showToast('Tidak ada booking aktif', 'error'); return; }
        const tx = DB.recordPayment(booking.id, method);
        Utils.showToast('Pembayaran tercatat', 'success');
        this.closePayment();
        this.renderHistory();
        this.showReceipt(tx, booking);
      }

      renderHistory() {
        const txs = DB.listTransactions().filter(t => t.csoId === this.u.id && Utils.isSameDay(t.createdAt, new Date()));
        txs.sort((a,b) => new Date(b.createdAt) - new Date(a.createdAt));
        
        if (txs.length === 0) {
            this.historyList.innerHTML = `<div class="text-center text-slate-500 dark:text-slate-400 p-8 bg-white dark:bg-slate-800 rounded-xl shadow-md">Belum ada transaksi hari ini.</div>`;
            return;
        }

        const zones = DB.listZones();
        const z = id => zones.find(z => z.id === id)?.name || id;
        const drivers = DB.listDrivers();
        const dn = id => drivers.find(d => d.id === id)?.name || id;
        const bookings = DB.listBookings();

        this.historyList.innerHTML = txs.map(t => {
          const b = bookings.find(b => b.id === t.bookingId);
          return `
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4">
              <div class="flex justify-between items-start">
                  <div>
                      <p class="font-bold text-slate-800 dark:text-slate-100">${z(b.from)} → ${z(b.to)}</p>
                      <p class="text-xs text-slate-500 dark:text-slate-400">Supir: ${dn(t.driverId)}</p>
                      <p class="text-xs text-slate-500 dark:text-slate-400">${new Date(t.createdAt).toLocaleString('id-ID', { timeStyle: 'short' })}</p>
                  </div>
                  <p class="font-bold text-lg text-primary-600 dark:text-primary-400">${Utils.formatCurrency(t.amount)}</p>
              </div>
              <div class="mt-2 pt-2 border-t border-slate-100 dark:border-slate-700 flex justify-between items-center">
                  <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-800 dark:text-slate-200 font-medium">${t.method}</span>
                  <button class="btn-view-receipt text-xs font-semibold text-primary-600 dark:text-primary-400 hover:underline" data-tx="${t.id}">
                    Lihat Struk
                  </button>
              </div>
            </div>`;
        }).join('');
      }
      
      generateReceiptHTML(tx, booking) {
        const zones = DB.listZones();
        const z = id => zones.find(z=>z.id===id)?.name || id;
        const d = new Date(tx.createdAt);
        const tanggal = d.toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }).replace('.', '');
        const waktu   = d.toLocaleTimeString('id-ID', { hour:'2-digit', minute:'2-digit' });
        const kasir   = (this.u?.name || "-").toLowerCase();
        const kode    = tx.id.toUpperCase();
        const tujuan  = z(booking.to).toUpperCase();
        const itemName= `Taksi: ${tujuan}`;
        const money = (n)=> new Intl.NumberFormat('id-ID').format(n);
        const harga = booking.price;
        const metode= tx.method.includes('Cash') ? "CASH" : "QRIS";

        return `<div class="rcpt58" style="color: #000;">
            <div class="c" style="font-weight:700; font-size: 14px; margin-bottom: 4px;">KOPERASI TAKSI POS</div>
            <div class="meta">
                <div class="row"><div>${tanggal} ${waktu}</div><div class="r">Kasir: ${kasir}</div></div>
                <div class="code">#${kode}</div>
            </div>
            <div class="hr"></div>
            <div class="itemrow">
                <div class="row"><div class="name">${itemName}</div><div class="amt">${money(harga)}</div></div>
            </div>
            <div class="hr"></div>
            <div class="totals">
                <div class="row" style="font-weight:700;"><div>Grand Total</div><div class="r">${money(harga)}</div></div>
                <div class="row"><div>${metode}</div><div class="r">${money(harga)}</div></div>
            </div>
            <div class="foot c" style="margin-top: 8px;">Terima kasih!</div>
        </div>`;
      } // <<<--- THIS IS WHERE THE FIX IS. I've added the missing closing brace.

      showReceipt(tx, booking) {
        const m = document.getElementById('receiptModal');
        const area = document.getElementById('receiptArea');
        // Store data for clean printing
        this.lastReceiptData = { tx, booking };
        area.innerHTML = this.generateReceiptHTML(tx, booking);
        m.classList.remove('hidden'); m.classList.add('flex');
      }

      hideReceipt() {
        const m = document.getElementById('receiptModal');
        m.classList.add('hidden'); m.classList.remove('flex');
      }

      printReceiptHandler() {
        if (!this.lastReceiptData) {
            Utils.showToast('Data struk tidak ditemukan.', 'error');
            return;
        }
        // Regenerate clean HTML from stored data
        const receiptHTML = this.generateReceiptHTML(this.lastReceiptData.tx, this.lastReceiptData.booking);
        
        const stylesheets = Array.from(document.styleSheets).map(sheet => sheet.href ? `<link rel="stylesheet" href="${sheet.href}">` : '').join('\n');
        const win = window.open('', 'PRINT', 'height=600,width=400');
        
        win.document.write(`<!doctype html><html><head><title>Struk</title>${stylesheets}<style>.rcpt58{color:#000!important}</style></head><body>${receiptHTML}</body></html>`);
        win.document.close();
        win.focus();
        setTimeout(() => { win.print(); win.close(); }, 500);
      }
    }

    const app = new CsoApp();
    app.init();

  </script>
</body>
</html>

