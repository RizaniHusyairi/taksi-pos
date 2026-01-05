
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
      this.proofInput = document.getElementById('proofInput');
      this.proofPreviewBox = document.getElementById('proofPreviewBox');
      this.proofPreviewImg = document.getElementById('proofPreviewImg');
      this.removeProofBtn = document.getElementById('removeProof');
      this.btnConfirmQR = document.getElementById('confirmQR');
      
      // Riwayat & Struk
      this.historyList = document.getElementById('csoTxList');
      this.refreshHistoryBtn = document.getElementById('refreshHistory');
      this.receiptModal = document.getElementById('receiptModal');
      this.receiptArea = document.getElementById('receiptArea');
      
      // State
      this.zones = [];
      this.selectedDriverId = null;
      this.selectedOrderData = null;
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
    // 1. Tampilan Loading
    this.driversList.innerHTML = `
        <div class="p-8 text-center text-slate-400 flex flex-col items-center animate-pulse">
            <svg class="w-8 h-8 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <span class="text-sm">Memuat antrian driver...</span>
        </div>`;
    
    // Reset pilihan saat refresh list
    this.selectedDriverId = null;
    this.updateConfirmButtonState();

    try {
        // 2. Ambil Data dari API
        // Pastikan endpoint ini mengembalikan list yang sudah diurutkan (sort_order) dari Backend
        const drivers = await fetchApi('/cso/available-drivers');

        // 3. Cek Jika Kosong
        if (drivers.length === 0) {
            this.driversList.innerHTML = `
            <div class="text-center p-6 bg-slate-50 dark:bg-slate-800/50 rounded-xl border-2 border-dashed border-slate-300 dark:border-slate-700">
                <p class="text-slate-500 font-medium">Antrian Kosong</p>
                <p class="text-xs text-slate-400 mt-1">Belum ada supir yang terdaftar dalam antrian hari ini.</p>
            </div>`;
            return;
        }

        // 4. Render List dengan Logika: Standby vs Offline
        this.driversList.innerHTML = drivers.map((d) => {
            const profile = d.driver_profile || {};
            
            // --- PERBAIKAN DI SINI ---
            // 1. Ambil raw status, jika null anggap string kosong
            const rawStatus = profile.status || ''; 
            
            // 2. Debugging: Cek di Console Browser (F12) apa isinya

            // 3. Ubah ke huruf kecil semua sebelum membandingkan
            const isStandby = rawStatus.toLowerCase() === 'standby';
            
            // Variabel Tampilan
            let wrapperClass, badgeHtml, btnHtml, clickAttribute;

            if (isStandby) {
                // KONDISI 1: STANDBY (Bisa Dipilih)
                // Style: Putih, Hover Biru, Cursor Pointer
                wrapperClass = 'bg-white dark:bg-slate-800 border-slate-200 dark:border-slate-700 hover:border-blue-400 cursor-pointer group';
                
                // Badge Hijau
                badgeHtml = `<span class="bg-emerald-100 text-emerald-700 border border-emerald-200 text-[10px] px-2 py-0.5 rounded-full font-bold">Berada di lokasi</span>`;
                
                // Tombol Pilih
                btnHtml = `<div class="text-xs font-bold text-blue-600 bg-blue-50 px-3 py-1.5 rounded-lg group-hover:bg-blue-600 group-hover:text-white transition-colors">Pilih</div>`;
                
                // Attribute penanda agar bisa diklik (lihat event listener di bawah)
                clickAttribute = `data-driver-id="${d.id}"`;

            } else {
                // KONDISI 2: OFFLINE / DI LUAR AREA (Tidak Bisa Dipilih)
                // Style: Abu-abu, Opacity rendah, Cursor not-allowed
                wrapperClass = 'bg-slate-50 dark:bg-slate-900 border-slate-100 dark:border-slate-800 opacity-60 cursor-not-allowed';
                
                // Badge Abu
                badgeHtml = `<span class="bg-slate-200 text-slate-500 border border-slate-300 text-[10px] px-2 py-0.5 rounded-full font-bold">Di Luar Area</span>`;
                
                // Teks Menunggu
                btnHtml = `<div class="text-xs font-medium text-slate-400">Menunggu...</div>`;
                
                // Tidak ada data-driver-id, jadi tidak akan ditangkap event listener
                clickAttribute = '';
            }

            // Tampilkan Nomor Punggung (Line Number) misal: #L5
            const lineNumber = profile.line_number 
                ? `<span class="font-mono text-xs font-bold text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded mr-2">#L${profile.line_number}</span>` 
                : '';

            return `
            <div ${clickAttribute} class="driver-card relative w-full border rounded-xl p-3 text-left transition-all duration-200 mb-2 ${wrapperClass}">
                <div class="flex items-center justify-between">
                    
                    <div class="flex items-center gap-3 overflow-hidden">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-slate-200 dark:bg-slate-700 flex items-center justify-center text-sm font-bold text-slate-600 dark:text-slate-300">
                            ${d.name.charAt(0)}
                        </div>

                        <div class="min-w-0">
                            <div class="flex items-center">
                                ${lineNumber}
                                <div class="font-bold text-slate-800 dark:text-slate-100 text-sm truncate">${d.name}</div>
                            </div>
                            <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 flex items-center gap-2 truncate">
                                <span>${profile.car_model || '-'}</span>
                                <span class="w-1 h-1 rounded-full bg-slate-300"></span>
                                <span class="font-mono">${profile.plate_number || '-'}</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col items-end gap-2 pl-2">
                        ${badgeHtml}
                        ${btnHtml}
                    </div>

                </div>
                
                <div class="absolute inset-0 border-2 border-blue-600 rounded-xl opacity-0 pointer-events-none transition-opacity selection-ring"></div>
            </div>`;
        }).join('');

        // 5. Pasang Event Listener (Hanya untuk yang Standby)
        // Kita hanya query elemen yang punya atribut `data-driver-id`
        this.driversList.querySelectorAll('[data-driver-id]').forEach(card => {
            card.addEventListener('click', () => {
                const id = card.dataset.driverId;
                
                // Update State
                this.selectedDriverId = id;

                // Visual Feedback: Hapus highlight lama, pasang highlight baru
                this.driversList.querySelectorAll('.selection-ring').forEach(el => el.classList.remove('opacity-100'));
                const ring = card.querySelector('.selection-ring');
                if (ring) ring.classList.add('opacity-100');

                // Update UI Tombol Konfirmasi Utama
                this.updateConfirmButtonState();
            });
        });

    } catch (error) {
        console.error("Error render drivers:", error);
        this.driversList.innerHTML = `
            <div class="text-center p-4 text-red-600 bg-red-50 rounded-xl border border-red-200">
                <p class="text-sm font-bold">Gagal memuat antrian.</p>
                <button onclick="window.location.reload()" class="underline text-xs mt-1">Muat Ulang</button>
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

        // 1. Simpan data pilihan ke memori sementara (Belum ke DB)
        const zoneId = this.toSel.value;
        const zoneObj = this.zones.find(z => z.id == zoneId); // Cari object zona dari array zones
        
        this.selectedOrderData = {
            driver_id: this.selectedDriverId,
            zone_id: zoneId,
            price: zoneObj.price,
            zone_name: zoneObj.name
        };

        // 2. Langsung Buka Modal Pembayaran
        this.openPayment();
    }

  openPayment() {
        if (!this.selectedOrderData) return;
        
        // Reset UI Modal
        this.qrisBox.classList.add('hidden');
        this.resetProof(); 

        // Tampilkan Info di Modal
        this.payInfo.innerHTML = `
        <div class="space-y-1">
            <div class="flex justify-between"><span>Rute:</span> <span class="font-semibold text-right">Bandara → ${this.selectedOrderData.zone_name}</span></div>
            <div class="flex justify-between"><span>Tarif:</span> <span class="font-semibold">${Utils.formatCurrency(this.selectedOrderData.price)}</span></div>
        </div>`;
        
        this.modal.classList.add('flex');
        this.modal.classList.remove('hidden');
    }

  closePayment() {
        this.modal.classList.add('hidden');
        this.modal.classList.remove('flex');
        // Tidak perlu cancelBooking ke API karena data belum masuk DB
        this.selectedOrderData = null; 
        this.resetProof();
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
    // --- LOGIKA FINALISASI (Satu-satunya API Call) ---
    async finishPayment(method) {
        if (!this.selectedOrderData) return;

        // Setup Loading UI
        let originalBtnText = '';
        let btnElement = null;

        if (method === 'QRIS') {
            btnElement = this.btnConfirmQR;
            originalBtnText = btnElement.textContent;
            btnElement.textContent = 'Memproses...';
            btnElement.disabled = true;
        }

        try {
            const formData = new FormData();
            // Ambil data dari memori sementara
            formData.append('driver_id', this.selectedOrderData.driver_id);
            formData.append('zone_id', this.selectedOrderData.zone_id);
            formData.append('method', method);

            // Validasi Bukti QRIS
            if (method === 'QRIS') {
                const file = this.proofInput.files[0];
                if (!file) throw new Error("Wajib foto bukti transfer QRIS.");
                formData.append('payment_proof', file);
            }

            const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            
            // PANGGIL ROUTE BARU
            const response = await fetch('/api/cso/process-order', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                body: formData
            });

            if (!response.ok) {
                const errData = await response.json();
                throw new Error(errData.message || 'Gagal memproses order');
            }
            
            const result = await response.json(); // Berisi data booking lengkap dari backend

            Utils.showToast('Order Berhasil!', 'success');
            
            // Simpan data hasil response untuk dicetak struknya
            // Backend harus return struktur yang cocok dengan generateReceiptHTML
            // Di controller tadi kita sudah return $booking->load(...)
            this.lastReceiptData = result.data; 
            
            // Tampilkan Struk
            this.showReceipt(this.lastReceiptData);
            
            // Bersihkan UI
            this.closePayment();     // Tutup modal bayar
            this.renderDrivers();    // Refresh list driver (driver tadi harusnya hilang)
            this.renderHistory();    // Refresh history transaksi

        } catch (error) {
            console.error(error);
            Utils.showToast(error.message, 'error');
        } finally {
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

const app = new CsoApp(); // 1. Buat instance aplikasi
app.init();               // 2. Jalankan aplikasi
window.csoApp = app;      // 3. Simpan ke Global Window agar bisa dipanggil onclick HTML

export { CsoApp };