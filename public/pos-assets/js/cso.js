
import { Utils } from './utils.js';


async function fetchApi(endpoint, options = {}) {
  const headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
  };
  // Untuk API Sanctum, session cookie sudah cukup, tapi jika butuh token:
  // const token = localStorage.getItem('authToken');
  // if (token) headers['Authorization'] = `Bearer ${token}`;

  try {
      const response = await fetch(`/api${endpoint}`, { 
        ...options, 
        headers,
        credentials: 'include' // Sertakan cookie untuk autentikasi berbasis sesi
    });
      if (!response.ok) {
          const errorData = await response.json();
          throw new Error(errorData.message || 'Terjadi kesalahan pada server');
      }
      return response.json();
  } catch (error) {
      console.error(`API Error on ${endpoint}:`, error);
      Utils.showToast(error.message, 'error');
      throw error;
  }
}

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

//   initTheme() {
//     const updateTheme = () => {
//         if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
//             document.documentElement.classList.add('dark');
//         } else {
//             document.documentElement.classList.remove('dark');
//         }
//     };
//     updateTheme();
//   }

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

      this.proofInput.addEventListener('change', (e) => this.handleProofSelect(e));
      this.removeProofBtn.addEventListener('click', () => this.resetProof());
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
      // TAMBAHKAN LISTENER INI:
      document.getElementById('shareReceipt')?.addEventListener('click', () => this.shareReceiptHandler());
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
          const zones = await fetchApi('/cso/zones');
          this.zones = zones; // Simpan data zona
          const opts = zones.map(z => `<option value="${z.id}">${z.name}</option>`).join('');
          this.toSel.innerHTML = '<option value="">Pilih Tujuan</option>' + opts;
      } catch (error) {
          this.toSel.innerHTML = '<option value="">Gagal memuat tujuan</option>';
      }
  }

  async renderDrivers() {
    this.driversList.innerHTML = `
        <div class="p-8 text-center text-slate-400 flex flex-col items-center animate-pulse">
            <svg class="w-8 h-8 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <span class="text-sm">Memuat antrian...</span>
        </div>`;
    
    this.selectedDriverId = null;
    this.updateConfirmButtonState();

    try {
        const drivers = await fetchApi('/cso/available-drivers');

        if (drivers.length === 0) {
            this.driversList.innerHTML = `
            <div class="text-center p-6 bg-slate-50 dark:bg-slate-800/50 rounded-xl border-2 border-dashed border-slate-300 dark:border-slate-700">
                <p class="text-slate-500 font-medium">Antrian Kosong</p>
                <p class="text-xs text-slate-400 mt-1">Belum ada supir yang masuk antrian.</p>
            </div>`;
            return;
        }

        // Render List
        this.driversList.innerHTML = drivers.map((d, index) => {
            const profile = d.driver_profile || {};
            const queueNumber = index + 1; // Urutan 1, 2, 3...
            const isFirst = index === 0; // Cek apakah ini antrian pertama

            // Style khusus untuk urutan #1 (Rekomendasi)
            const wrapperClass = isFirst 
                ? 'bg-white dark:bg-slate-800 border-primary-500 ring-1 ring-primary-500 shadow-md transform scale-[1.01]' 
                : 'bg-white dark:bg-slate-800 border-slate-200 dark:border-slate-700 hover:border-primary-300';
            
            const numBadgeClass = isFirst
                ? 'bg-primary-600 text-white shadow-lg shadow-primary-500/30'
                : 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400';

            return `
            <button type="button" data-driver-id="${d.id}" class="driver-card relative w-full border rounded-xl p-3 text-left transition-all duration-200 group ${wrapperClass}">
                <div class="flex items-center gap-4">
                    
                    <div class="flex-shrink-0 w-12 h-12 rounded-xl flex items-center justify-center text-xl font-bold ${numBadgeClass}">
                        ${queueNumber}
                    </div>

                    <div class="flex-grow min-w-0">
                        <div class="flex justify-between items-start">
                            <div class="truncate pr-2">
                                <div class="font-bold text-slate-800 dark:text-slate-100 text-base group-hover:text-primary-600 transition-colors">
                                    ${d.name}
                                </div>
                                <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 flex items-center gap-2">
                                    <span class="bg-slate-100 dark:bg-slate-700 px-1.5 py-0.5 rounded text-[10px] font-mono tracking-wide text-slate-600 dark:text-slate-300">
                                        ${profile.plate_number || '---'}
                                    </span>
                                    <span class="truncate">${profile.car_model || 'Kendaraan ?'}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex-shrink-0">
                    <div class="w-6 h-6 rounded-full border-2 border-slate-300 dark:border-slate-600 group-hover:border-primary-400 flex items-center justify-center">
                                    <div class="w-3 h-3 rounded-full bg-primary-600 opacity-0 group-[.selected]:opacity-100 transition-opacity"></div>
                               </div>
                         
                    </div>
                </div>
                
                <div class="absolute inset-0 border-2 border-primary-600 rounded-xl opacity-0 group-[.selected]:opacity-100 pointer-events-none transition-opacity"></div>
            </button>`;
        }).join('');

        // Re-bind Event Listeners
        this.driversList.querySelectorAll('[data-driver-id]').forEach(btn => {
            btn.addEventListener('click', () => {
                this.selectedDriverId = btn.dataset.driverId;
                
                // Hapus class selected dari semua
                this.driversList.querySelectorAll('.driver-card').forEach(b => b.classList.remove('selected'));
                
                // Tambah class selected ke yang diklik
                btn.classList.add('selected');
                
                this.updateConfirmButtonState();
            });
        });

    } catch (error) {
        console.error(error);
        this.driversList.innerHTML = `
            <div class="text-center p-4 text-danger bg-red-50 dark:bg-red-900/10 rounded-xl border border-red-200">
                Gagal memuat antrian supir. <br>
                <button onclick="window.location.reload()" class="underline text-sm mt-1">Muat Ulang</button>
            </div>`;
    }
  }

  async renderHistory() {
    this.historyList.innerHTML = `<div class="text-center text-slate-500">Memuat riwayat...</div>`;
    try {
        const transactions = await fetchApi('/cso/history');

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
                        <p class="font-bold text-slate-800 dark:text-slate-100">Bandara → ${zoneTo?.name || 'N/A'}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Supir: ${driver?.name || 'N/A'}</p>
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
          this.currentBooking = await fetchApi('/cso/bookings', {
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
          <div class="flex justify-between"><span>Rute:</span> <span class="font-semibold text-right">Bandara → ${selectedZone?.name || 'N/A'}</span></div>
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

  // --- LOGIKA PREVIEW FOTO ---
    handleProofSelect(e) {
        const file = e.target.files[0];
        if (file) {
            // Tampilkan Preview
            const reader = new FileReader();
            reader.onload = (e) => {
                this.proofPreviewImg.src = e.target.result;
                this.proofPreviewBox.classList.remove('hidden');
                // Aktifkan Tombol Konfirmasi (Ubah warna jadi Hijau)
                this.btnConfirmQR.disabled = false;
                this.btnConfirmQR.classList.remove('bg-slate-300', 'text-slate-500', 'cursor-not-allowed', 'dark:bg-slate-700');
                this.btnConfirmQR.classList.add('bg-success', 'text-white', 'hover:bg-emerald-600', 'shadow-lg');
            };
            reader.readAsDataURL(file);
        }
    }

    resetProof() {
        this.proofInput.value = ''; // Reset input file
        this.proofPreviewBox.classList.add('hidden');
        // Matikan Tombol Konfirmasi
        this.btnConfirmQR.disabled = true;
        this.btnConfirmQR.classList.add('bg-slate-300', 'text-slate-500', 'cursor-not-allowed', 'dark:bg-slate-700');
        this.btnConfirmQR.classList.remove('bg-success', 'text-white', 'hover:bg-emerald-600', 'shadow-lg');
    }



    // --- UPDATE LOGIKA PEMBAYARAN (FormData) ---
    async finishPayment(method) {
        if (!this.currentBooking) { 
            Utils.showToast('Tidak ada booking aktif', 'error'); 
            return; 
        }

        // Setup Loading State
        let originalBtnText = '';
        let btnElement = null;

        if (method === 'QRIS') {
            btnElement = this.btnConfirmQR;
            originalBtnText = btnElement.textContent;
            btnElement.textContent = 'Mengupload Bukti...';
            btnElement.disabled = true;
        }

        try {
            // GUNAKAN FORMDATA (Wajib untuk upload file)
            const formData = new FormData();
            formData.append('booking_id', this.currentBooking.id);
            formData.append('method', method);

            // Jika QRIS, tambahkan file foto
            if (method === 'QRIS') {
                const file = this.proofInput.files[0];
                if (!file) {
                    throw new Error("Wajib menyertakan foto bukti transfer untuk QRIS.");
                }
                formData.append('payment_proof', file);
            }

            // Panggil API (Perhatikan fetchApi di cso.js harus mendukung FormData)
            // KITA HARUS MODIFIKASI fetchApi SEDIKIT atau panggil fetch manual di sini
            // Agar aman, kita panggil fetch manual khusus upload ini
            
            const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            const response = await fetch('/api/cso/payment', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': token,
                    'Accept': 'application/json'
                    // JANGAN SET Content-Type manually saat pakai FormData! Browser akan otomatis set boundary.
                },
                body: formData
            });

            if (!response.ok) {
                const errData = await response.json();
                throw new Error(errData.message || 'Gagal memproses pembayaran');
            }
            
            const transaction = await response.json(); // Hasil sukses

            Utils.showToast('Pembayaran berhasil & Bukti tersimpan', 'success');
            
            this.showReceipt(this.currentBooking);
            this.closePayment();
            this.renderHistory();
            this.resetProof(); // Reset form foto untuk order berikutnya

        } catch (error) {
            console.error(error);
            Utils.showToast(error.message, 'error');
        } finally {
            // Reset Loading State
            if (btnElement) {
                btnElement.textContent = originalText;
                btnElement.disabled = false;
            }
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
        // 1. Cek data
        if (!this.lastReceiptData) {
            Utils.showToast('Data struk tidak ditemukan.', 'error');
            return;
        }

        // 2. Generate HTML Struk
        const receiptHTML = this.generateReceiptHTML(this.lastReceiptData);

        // 3. Deteksi apakah user menggunakan Android
        const isAndroid = /Android/i.test(navigator.userAgent);

        if (isAndroid) {
            // --- METODE CETAK BLUETOOTH (VIA RAWBT) ---
            
            // Kita perlu mengubah HTML menjadi Base64 agar bisa dikirim lewat URL
            // Trik 'unescape' + 'encodeURIComponent' digunakan untuk menangani karakter UTF-8 (seperti Rp, emoji, dll)
            const base64Data = btoa(unescape(encodeURIComponent(receiptHTML)));
            
            // Format URL Scheme untuk RawBT
            // Opsi: cut=1 (potong kertas), h=auto (tinggi otomatis)
            const rawbtUrl = `rawbt:data:text/html;base64,${base64Data}`;
            
            // Buka aplikasi RawBT
            window.location.href = rawbtUrl;

        } else {
            // --- METODE CETAK STANDARD (DESKTOP/LAPTOP) ---
            
            const printWindow = window.open('', 'PRINT', 'height=600,width=400');
            
            printWindow.document.write('<!doctype html><html><head><title>Struk</title>');
            // Sertakan CSS agar tampilan sama
            printWindow.document.write('<link rel="stylesheet" href="/pos-assets/css/style.css">'); 
            printWindow.document.write('<style>body { margin: 0; padding: 0; } @page { margin: 0; }</style>');
            printWindow.document.write('</head><body>');
            printWindow.document.write(receiptHTML);
            printWindow.document.write('</body></html>');
            
            printWindow.document.close();
            printWindow.focus();
            
            // Beri jeda sedikit agar style termuat
            setTimeout(() => {
                printWindow.print();
                printWindow.close();
            }, 500);
        }
    }

    
    async shareReceiptHandler() {
        if (!this.lastReceiptData) {
            Utils.showToast('Data struk tidak ditemukan.', 'error');
            return;
        }

        // 1. Minta Nomor WA Penumpang dulu
        let phoneNumber = prompt("Masukkan Nomor WhatsApp Penumpang untuk mengirim PDF:");
        if (!phoneNumber) return; // Batal jika kosong

        // Format nomor HP (08xx -> 628xx)
        phoneNumber = phoneNumber.replace(/\D/g, '');
        if (phoneNumber.startsWith('0')) {
            phoneNumber = '62' + phoneNumber.substring(1);
        }

        // 2. Beri Feedback Loading
        const btn = document.getElementById('shareReceipt');
        const originalText = btn.innerHTML;
        btn.innerHTML = '⏳ Membuat PDF...';
        btn.disabled = true;

        try {
            // Ambil elemen struk yang sedang tampil di modal
            const element = document.getElementById('receiptArea');
            
            // Kode Transaksi untuk nama file
            const code = this.lastReceiptData.id || 'TRX';

            // 3. Gunakan html2canvas untuk memotret HTML menjadi Gambar
            // scale: 2 agar hasil PDF tajam (tidak pecah)
            const canvas = await html2canvas(element, { scale: 2, useCORS: true });
            const imgData = canvas.toDataURL('image/png');

            // 4. Setup PDF (Ukuran kertas disesuaikan dengan struk)
            // 58mm = 58 unit di jsPDF (kurang lebih)
            // Kita pakai format custom width 58, height menyesuaikan panjang struk
            const imgWidth = 58; 
            const pageHeight = (canvas.height * imgWidth) / canvas.width;
            
            // Akses jsPDF dari window (karena pakai CDN)
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('p', 'mm', [imgWidth, pageHeight + 10]); // +10mm padding bawah

            // Masukkan gambar ke PDF
            doc.addImage(imgData, 'PNG', 0, 0, imgWidth, pageHeight);

            // 5. Download File PDF
            const fileName = `Struk_Pembayaran_${code}.pdf`;
            doc.save(fileName);

            Utils.showToast('PDF berhasil didownload!', 'success');

            // 6. Buka WhatsApp
            // Beri jeda sedikit agar download selesai dulu
            setTimeout(() => {
                const message = `Halo, berikut adalah struk pembayaran taksi Anda (Kode: #${code}). Silakan unduh file PDF di atas. Terima kasih.`;
                const url = `https://wa.me/${phoneNumber}?text=${encodeURIComponent(message)}`;
                
                // Buka WA di tab baru
                window.open(url, '_blank');
                
                // Beri instruksi ke CSO
                alert(`File "${fileName}" telah didownload ke perangkat ini.\n\nWhatsApp akan terbuka sekarang. Silakan KLIK tombol lampiran (Paperclip) di WhatsApp dan kirim file PDF tersebut.`);
            }, 1000);

        } catch (error) {
            console.error(error);
            Utils.showToast('Gagal membuat PDF.', 'error');
        } finally {
            // Kembalikan tombol seperti semula
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    }

    generateReceiptHTML(tx) {
        // Logika penentuan variabel (Booking vs Transaction)
        const isBooking = tx.zone_id !== undefined;
        const booking = isBooking ? tx : tx.booking;

        // Data-data Struk
        const dateTime = new Date(tx.created_at || Date.now());
        const tanggal = dateTime.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
        const waktu = dateTime.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
        
        // Data Transaksi
        const zoneName = isBooking 
            ? this.zones.find(z => z.id == tx.zone_id)?.name 
            : tx.booking?.zone_to?.name;
        
        const price = tx.price || tx.amount;
        const amountStr = new Intl.NumberFormat('id-ID').format(price);
        const code = String(tx.id || booking?.id || 'N/A').toUpperCase();
        
        // Nama Kasir (CSO)
        const kasirName = tx.cso?.name?.split(' ')[0] || 'Admin'; // Ambil nama depan saja biar muat

        // Metode Pembayaran
        const methodDisplay = tx.method?.includes('Cash') ? "CASH" : "QRIS";

        // --- STYLE CSS INLINE (Agar tercetak rapi di printer thermal) ---
        // Kita menggunakan font Courier New agar lebar huruf sama (Monospace)
        const style = `
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            width: 100%;
            max-width: 58mm; /* Lebar kertas thermal standar */
            color: #000;
            line-height: 1.2;
        `;
        
        const center = `text-align: center;`;
        const bold = `font-weight: bold;`;
        const flexBetween = `display: flex; justify-content: space-between;`;
        const dashedLine = `border-bottom: 1px dashed #000; margin: 5px 0;`;
        const mb1 = `margin-bottom: 4px;`;
        const mt2 = `margin-top: 8px;`;

        // Logo Placeholder (Ganti src dengan URL logo koperasimu)
        // Gunakan filter grayscale agar logo tercetak jelas di printer hitam putih
        const logoHtml = `
            <div style="${center} margin-bottom: 8px;">
                <div style="font-size: 24px; line-height: 1;">✈️</div> 
                <div style="${bold} font-size: 14px; margin-top: 4px;">KOPERASI ANGKASA JAYA</div>
            </div>
        `;

        // --- HTML STRUK ---
        return `
        <div style="${style}">
            
            ${logoHtml}

            <div style="${flexBetween} ${mb1}">
                <div>
                    <div style="font-size: 10px;">Waktu Penjualan</div>
                    <div>${tanggal} ${waktu}</div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 10px;">Kasir</div>
                    <div>${kasirName}</div>
                </div>
            </div>

            <div style="${mb1}">#${code}</div>

            <div style="${dashedLine}"></div>

            <div style="${flexBetween} ${bold} ${mb1}">
                <div>Item</div>
                <div>Jumlah</div>
            </div>

            <div style="${dashedLine}"></div>

            <div style="${mb1}">
                <div style="${bold} text-transform: uppercase;">${zoneName || 'ZONA ?'}</div>
                <div style="${flexBetween}">
                    <div style="padding-left: 10px;">${amountStr} x1</div>
                    <div>${amountStr}</div>
                </div>
            </div>

            <div style="${dashedLine}"></div>

            <div style="${flexBetween} ${mb1}">
                <div>Subtotal</div>
                <div>${amountStr}</div>
            </div>
            
            <div style="${flexBetween} ${bold} ${mb1} font-size: 14px;">
                <div>Grand Total</div>
                <div>Rp ${amountStr}</div>
            </div>

            <div style="${flexBetween} ${mb1}">
                <div>${methodDisplay}</div>
                <div>Rp ${amountStr}</div>
            </div>

            <div style="${center} ${mt2} font-size: 10px; color: #555;">
                <div style="${dashedLine}"></div>
                <div style="margin-top: 5px;">Powered by IT Bandara</div>
                <div>Simpan struk ini sebagai bukti.</div>
            </div>

        </div>`;
    }
  
}
export { CsoApp };