
import { Utils } from './utils.js';
import { apiFetch } from './core/api.js';
import { escapeHTML } from './core/sanitize.js';




class CsoApp {
  init() {
    this.initTheme();
    this.cacheEls();
    this.bind();
    this.renderAll();
    window.addEventListener('hashchange', () => this.route());
    this.route();
  }

    initTheme() {
        this.themeToggleBtn = document.getElementById('themeToggleBtn');
        this.iconDark = document.getElementById('theme-icon-dark');
        this.iconLight = document.getElementById('theme-icon-light');

        const updateTheme = () => {
            const isDarkMode = localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', isDarkMode);
            this.iconDark.classList.toggle('hidden', !isDarkMode);
            this.iconLight.classList.toggle('hidden', isDarkMode);
        };
        
        this.themeToggleBtn.addEventListener('click', () => {
            const isDarkMode = document.documentElement.classList.contains('dark');
            localStorage.theme = isDarkMode ? 'light' : 'dark';
            updateTheme();
        });

        updateTheme(); // Jalankan saat pertama kali dimuat
    }



  cacheEls() {
      this.views = ['new', 'history'];
      this.pageTitle = document.getElementById('pageTitle');
      this.toSel = document.getElementById('toZone');
      this.priceBox = document.getElementById('priceBox');
      this.driversList = document.getElementById('driversList');
      this.btnConfirm = document.getElementById('btnConfirmBooking');
      
      // Modal Pembayaran
      this.modal = document.getElementById('paymentModal');
      this.payInfo = document.getElementById('payInfo');
      this.btnClosePay = document.getElementById('closePayModal');
      this.btnQRIS = document.getElementById('payQRIS');
      this.btnCashCSO = document.getElementById('payCashCSO');
      this.btnCashDriver = document.getElementById('payCashDriver');
      this.qrisBox = document.getElementById('qrisBox');
      this.btnConfirmQR = document.getElementById('confirmQR');
      
      // Riwayat & Struk
      this.historyList = document.getElementById('csoTxList');
      this.refreshHistoryBtn = document.getElementById('refreshHistory');
      this.receiptModal = document.getElementById('receiptModal');
      this.receiptArea = document.getElementById('receiptArea');
      
      // State
      this.zones = [];
      this.selectedDriverId = null;
      this.currentBooking = null;
  }



  bind() {
      // Event listener untuk tombol logout sudah ditangani oleh form di blade

      this.toSel.addEventListener('change', () => this.updatePrice());
      this.refreshHistoryBtn.addEventListener('click', () => this.renderHistory());
      this.btnConfirm.addEventListener('click', () => this.processBooking());

      // Event listener pembayaran
      this.btnClosePay.addEventListener('click', () => this.closePayment());
      this.modal.addEventListener('click', (e) => { if (e.target === this.modal) this.closePayment(); });
      this.btnQRIS.addEventListener('click', () => { this.qrisBox.classList.remove('hidden'); });
      this.btnConfirmQR.addEventListener('click', () => this.finishPayment('QRIS'));
      this.btnCashCSO.addEventListener('click', () => this.finishPayment('CashCSO'));
      this.btnCashDriver.addEventListener('click', () => this.finishPayment('CashDriver'));
      
      // Event listener struk
      document.getElementById('closeReceipt')?.addEventListener('click', () => this.hideReceipt());
      document.getElementById('closeReceipt2')?.addEventListener('click', () => this.hideReceipt());
      // Event listener untuk tombol cetak & lihat struk akan di-bind setelah history dirender

      document.getElementById('printReceipt')?.addEventListener('click', () => this.printReceiptHandler());
  }
  route() {
      // Fungsi routing tidak berubah, sudah bagus
      const hash = (location.hash || '#new').slice(1);
      this.views.forEach(v => {
          document.getElementById('view-' + v)?.classList.toggle('hidden', v !== hash);
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

   // --- RENDER FUNCTIONS (NOW ASYNC) ---

  async renderZones() {
      try {
          const zones = await apiFetch('/api/cso/zones');
          this.zones = zones; // Simpan data zona
          const opts = zones.map(z => `<option value="${z.id}">${z.name}</option>`).join('');
          this.toSel.innerHTML = '<option value="">Pilih Tujuan</option>' + opts;
      } catch (error) {
          this.toSel.innerHTML = '<option value="">Gagal memuat tujuan</option>';
      }
  }

  async renderDrivers() {
    this.driversList.innerHTML = `<p class="text-slate-500">Memuat data supir...</p>`;
    this.selectedDriverId = null;
    this.updateConfirmButtonState();

    try {
        const drivers = await apiFetch('/api/cso/available-drivers');

        if (drivers.length === 0) {
            this.driversList.innerHTML = `<p class="text-center col-span-full p-4 bg-white dark:bg-slate-800 rounded-xl">Tidak ada supir yang tersedia.</p>`;
            return;
        }

        this.driversList.innerHTML = drivers.map(d => {
            const profile = d.driver_profile || {};
            const status = profile.status || 'offline';
            const isAvailable = status === 'available';
            return `
            <button type="button" data-driver-id="${d.id}" class="driver-card border-2 ${isAvailable ? 'border-slate-200 dark:border-slate-700' : 'border-dashed border-slate-300 dark:border-slate-600'} rounded-xl p-3 text-left transition-all ${isAvailable ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed'}">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="font-bold text-slate-800 dark:text-slate-100">${escapeHTML(d.name)}</div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">${profile.car_model || '-'} • ${profile.plate_number || '-'}</div>
                    </div>
                    <div class="text-xs font-semibold px-2 py-0.5 rounded-full ${isAvailable ? 'bg-success/10 text-success' : 'bg-slate-100 dark:bg-slate-600 text-slate-500 dark:text-slate-300'}">
                        ${status.charAt(0).toUpperCase() + status.slice(1)}
                    </div>
                </div>
            </button>`;
        }).join('');

        // Bind event listener ke tombol supir yang baru dirender
        this.driversList.querySelectorAll('[data-driver-id]').forEach(btn => {
            btn.addEventListener('click', () => {
                this.selectedDriverId = btn.dataset.driverId;
                this.driversList.querySelectorAll('button').forEach(b => b.classList.remove('selected'));
                btn.classList.add('selected');
                this.updateConfirmButtonState();
            });
        });

    } catch (error) {
        this.driversList.innerHTML = `<p class="text-danger">Gagal memuat data supir.</p>`;
    }
  }

  async renderHistory() {
    this.historyList.innerHTML = `<div class="text-center text-slate-500">Memuat riwayat...</div>`;
    try {
        const transactions = await apiFetch('/api/cso/history');

        if (transactions.length === 0) {
            this.historyList.innerHTML = `<div class="text-center text-slate-500 dark:text-slate-400 p-8 bg-white dark:bg-slate-800 rounded-xl shadow-md">Belum ada transaksi hari ini.</div>`;
            return;
        }

        this.historyList.innerHTML = transactions.map(tx => {
            
            const booking = tx.booking;
            const driver = tx.booking.driver;
            const zoneTo = booking.zone_to;
            return `
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="font-bold text-slate-800 dark:text-slate-100">Bandara → ${escapeHTML(zoneTo?.name || 'N/A')}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Supir: ${escapeHTML(driver?.name || 'N/A')}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">${new Date(tx.created_at).toLocaleString('id-ID', { timeStyle: 'short' })}</p>
                    </div>
                    <p class="font-bold text-lg text-primary-600 dark:text-primary-400">${tx.amount.toLocaleString('id-ID', {style:'currency', currency:'IDR', minimumFractionDigits:0})}</p>
                </div>
                <div class="mt-2 pt-2 border-t border-slate-100 dark:border-slate-700 flex justify-between items-center">
                    <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-800 dark:text-slate-200 font-medium">${tx.method}</span>
                    <button class="btn-view-receipt text-xs font-semibold text-primary-600 dark:text-primary-400 hover:underline" data-tx-object='${JSON.stringify(tx)}'>
                        Lihat Struk
                    </button>
                </div>
            </div>`;
        }).join('');
        
        // Re-bind event listener untuk tombol struk
        this.historyList.querySelectorAll('.btn-view-receipt').forEach(btn => {
            btn.addEventListener('click', () => {
                const tx = JSON.parse(btn.dataset.txObject);
                this.showReceipt(tx);
            });
        });

    } catch (error) {
        this.historyList.innerHTML = `<div class="text-center text-danger p-8 bg-white dark:bg-slate-800 rounded-xl">Gagal memuat riwayat.</div>`;
    }
  }

  // --- LOGIC FUNCTIONS (ACTIONS) ---

  updatePrice() {
      const zoneId = this.toSel.value;
    const zone = this.zones.find(z => z.id == zoneId);
    this.priceBox.textContent = zone ? Utils.formatCurrency(zone.price) : '-'; // <-- Gunakan Utils
    this.updateConfirmButtonState();
  }
  updateConfirmButtonState() {
      const zoneId = this.toSel.value;
      this.btnConfirm.disabled = !zoneId || !this.selectedDriverId;
  }

  async processBooking() {
      if (!this.selectedDriverId || !this.toSel.value) {
          alert('Silakan pilih tujuan dan supir terlebih dahulu.');
          return;
      }

      try {
          const bookingData = {
              driver_id: this.selectedDriverId,
              zone_id: this.toSel.value
          };
          this.currentBooking = await apiFetch('/api/cso/bookings', {
              method: 'POST',
              body: JSON.stringify(bookingData)
          });
          this.openPayment();
      } catch (error) {
          // Error sudah ditangani oleh fetchApi
      }
  }

  openPayment() {
      if (!this.currentBooking) return;
      this.qrisBox.classList.add('hidden');
      const booking = this.currentBooking;
      const selectedZone = this.zones.find(z => z.id == booking.zone_id);

      this.payInfo.innerHTML = `
      <div class="space-y-1">
          <div class="flex justify-between"><span>Rute:</span> <span class="font-semibold text-right">Bandara → ${escapeHTML(selectedZone?.name || 'N/A')}</span></div>
          <div class="flex justify-between"><span>Tarif:</span> <span class="font-semibold">${booking.price.toLocaleString('id-ID', {style:'currency', currency:'IDR', minimumFractionDigits:0})}</span></div>
      </div>`;
      this.modal.classList.add('flex');
      this.modal.classList.remove('hidden');
  }

  closePayment() {
      this.modal.classList.add('hidden');
      this.modal.classList.remove('flex');
      this.currentBooking = null;
      this.renderDrivers(); // Refresh driver list
  }

  async finishPayment(method) {
      if (!this.currentBooking) { 
        Utils.showToast('Tidak ada booking aktif', 'error'); // <-- Gunakan Utils
        return; 
    }

      try {
          const paymentData = {
              booking_id: this.currentBooking.id,
              method: method
          };
          const transaction = apiFetch('/api/cso/payment', {
              method: 'POST',
              body: JSON.stringify(paymentData)
          });
          
          Utils.showToast('Pembayaran berhasil dicatat', 'success'); // <-- Gunakan Utils
        
        this.showReceipt(this.currentBooking);
        this.closePayment();
        this.renderHistory();
      } catch (error) {
          // error ditangani fetchApi
      }
  }

  showReceipt(txOrBookingObject) {

        this.lastReceiptData = txOrBookingObject; // <-- TAMBAHKAN BARIS INI
        // Fungsi ini sekarang bisa menerima objek transaksi dari riwayat
        // atau objek booking dari proses pembayaran baru
        const receiptHTML = this.generateReceiptHTML(txOrBookingObject);
        this.receiptArea.innerHTML = receiptHTML;
        this.receiptModal.classList.add('flex');
        this.receiptModal.classList.remove('hidden');
    }
    
    hideReceipt() {
        this.receiptModal.classList.add('hidden');
        this.receiptModal.classList.remove('flex');
    }
    printReceiptHandler() {
        // Cek apakah ada data struk yang tersimpan
        if (!this.lastReceiptData) {
            Utils.showToast('Data struk tidak ditemukan.', 'error');
            return;
        }

        // Buat ulang HTML struk untuk memastikan formatnya bersih
        const receiptHTML = this.generateReceiptHTML(this.lastReceiptData);
        
        // Buka jendela baru untuk dicetak
        const printWindow = window.open('', 'PRINT', 'height=600,width=400');
        
        printWindow.document.write('<!doctype html><html><head><title>Struk Pembayaran</title>');
        // Salin style dari halaman utama agar struk terlihat sama
        printWindow.document.write('<link rel="stylesheet" href="{{ asset("pos-assets/css/style.css") }}">'); // Sesuaikan path jika perlu
        printWindow.document.write('<style>body { margin: 0; background: #fff; } .rcpt58 { color: #000 !important; }</style>');
        printWindow.document.write('</head><body>');
        printWindow.document.write(receiptHTML);
        printWindow.document.write('</body></html>');
        
        printWindow.document.close();
        printWindow.focus();
        
        // Beri waktu sejenak untuk memuat, lalu cetak dan tutup
        setTimeout(() => {
            printWindow.print();
            printWindow.close();
        }, 500);
    }

    generateReceiptHTML(tx) {
         // tx di sini bisa berupa objek booking (dari pembayaran baru)
    // atau objek transaksi (dari riwayat)
    const isBooking = tx.zone_id !== undefined;
    const booking = isBooking ? tx : tx.booking;

    // --- Variabel Baru Didefinisikan Di Sini ---
    const dateTime = new Date(tx.created_at || Date.now());
    const amount = tx.price || tx.amount;
    const zoneName = isBooking 
        ? this.zones.find(z => z.id == tx.zone_id)?.name 
        : tx.booking?.zone_to?.name;

    // Variabel untuk struk
    const tanggal = dateTime.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }).replace('.', '');
    const waktu = dateTime.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
    const kasir = tx.cso?.name?.toLowerCase() || 'cso'; // Menggunakan nama CSO dari data API jika ada
    // Ubah ID menjadi String terlebih dahulu, baru diubah ke huruf besar
    const kode = String(tx.id || booking?.id || 'N/A').toUpperCase();
    const itemName = `Taksi: ${(zoneName || 'N/A').toUpperCase()}`;
    const metode = tx.method?.includes('Cash') ? "CASH" : "QRIS";
    
    // Helper untuk format mata uang
    const money = (n) => new Intl.NumberFormat('id-ID').format(n);

    // --- HTML Struk (Struktur tidak berubah) ---
    return `<div class="rcpt58" style="color: #000;">
        <div class="c" style="font-weight:700; font-size: 14px; margin-bottom: 4px;">KOPERASI TAKSI POS</div>
        <div class="meta">
            <div class="row"><div>${tanggal} ${waktu}</div><div class="r">Kasir: ${kasir}</div></div>
            <div class="code">#${kode}</div>
        </div>
        <div class="hr"></div>
        <div class="itemrow">
            <div class="row"><div class="name">${itemName}</div><div class="amt">${money(amount)}</div></div>
        </div>
        <div class="hr"></div>
        <div class="totals">
            <div class="row" style="font-weight:700;"><div>Grand Total</div><div class="r">${money(amount)}</div></div>
            <div class="row"><div>${metode}</div><div class="r">${money(amount)}</div></div>
        </div>
        <div class="foot c" style="margin-top: 8px;">Terima kasih!</div>
    </div>`;
    }
  
}
export { CsoApp };