// import { DB } from '../assets/js/data.js';
import { Utils } from './utils.js';

async function fetchApi(endpoint, options = {}) {
    // ... (copy paste fungsi fetchApi dari cso.js) ...
    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    };
    try {
        const response = await fetch(`/api${endpoint}`, { 
        ...options, 
        headers,
        credentials: 'include' // Sertakan cookie untuk autentikasi berbasis sesi
        });
        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message || 'Terjadi kesalahan');
        }
        // Handle respons kosong (misal: 204 No Content)
        if (response.status === 204) return null;
        return response.json();
    } catch (error) {
        Utils.showToast(error.message, 'error');
        throw error;
    }
}

// Tambahkan helper global untuk akses dari onclick HTML string

window.viewProof = (path) => {
    if (!path || path === 'null') {
        Utils.showToast('Bukti transfer tidak ditemukan', 'error');
        return;
    }
    // Set src gambar
    const imgEl = document.getElementById('imgProof');
    // Pastikan path diawali /storage/
    imgEl.src = `/storage/${path}`;
    
    // Buka Modal
    const modal = document.getElementById('proofModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
};

export class DriverApp {
    
    // --- TAMBAHKAN DI BAGIAN ATAS CLASS ---
    constructor() {
        // Koordinat Bandara APT Pranoto (Sesuaikan presisi-nya nanti)
        this.AIRPORT_LAT = -0.372158; 
        this.AIRPORT_LNG = 117.258153;
        this.MAX_RADIUS_KM = 2.0; // Radius toleransi
        
        this.currentLat = null;
        this.currentLng = null;
        this.watchId = null;
    }

    async init() {
        this.initTheme(); // Panggil inisialisasi tema
        this.cacheEls();
        this.bind();
        // Muat data awal yang penting saat aplikasi start
        await this.loadInitialData();

        // Mulai pantau lokasi GPS segera setelah init
        this.startLocationWatcher();
        
        window.addEventListener('hashchange', () => this.route());
        this.route();
        window.addEventListener('storage', (e) => {
        // Abaikan update storage dari tema agar tidak re-render
        
        });
    }

    initTheme() {
        this.themeToggleBtn = document.getElementById('theme-toggle');
        this.sunIcon = `<svg class="w-6 h-6 text-slate-700 dark:text-slate-200" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>`;
        this.moonIcon = `<svg class="w-6 h-6 text-slate-700 dark:text-slate-200" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>`;

        const updateTheme = () => {
            const theme = localStorage.getItem('theme') || 'system';
            if (theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
                this.themeToggleBtn.innerHTML = this.sunIcon;
            } else {
                document.documentElement.classList.remove('dark');
                this.themeToggleBtn.innerHTML = this.moonIcon;
            }
        };

        this.themeToggleBtn.addEventListener('click', () => {
            const currentTheme = localStorage.getItem('theme') || 'system';
            const isDark = document.documentElement.classList.contains('dark');
            // Cycle: system -> light -> dark -> system
            let newTheme;
            if (isDark) {
                newTheme = 'light';
            } else {
                newTheme = 'dark';
            }
            localStorage.setItem('theme', newTheme);
            updateTheme();
        });

        // Listen for system theme changes
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', updateTheme);
        
        updateTheme(); // Set initial theme
    }

    cacheEls() {
        // Views
        this.views = ['orders', 'wallet', 'history', 'profile'];
        this.pageTitle = document.getElementById('pageTitle');
        
        // Orders View
        this.textBook = document.getElementById('text-book');
        this.activeBox = document.getElementById('activeOrderBox');
        this.activeInfo = document.getElementById('activeOrderInfo');
        this.btnComplete = document.getElementById('markComplete');
        this.statusToggle = document.getElementById('statusToggle');
        this.statusText = document.getElementById('driverStatusText');

        // -- UPDATE BAGIAN INI UNTUK UI BARU --
        this.statusText = document.getElementById('driverStatusText');
        this.distanceText = document.getElementById('distanceText');
        this.btnQueue = document.getElementById('btnQueueAction');

        // Wallet View
        this.walletBalance = document.getElementById('walletBalance');
        this.wdForm = document.getElementById('formWd');
        this.wdList = document.getElementById('wdList');

        // History View
        this.tripList = document.getElementById('tripList');

        // Profile View
        this.profileInitial = document.getElementById('profileInitial');
        this.profileName = document.getElementById('profileName');
        this.profileCar = document.getElementById('profileCar');
        this.logoutBtn = document.getElementById('logoutBtn');

        this.bankModal = document.getElementById('bankModal');
        this.formBank = document.getElementById('formBank');
        this.txtBankInfo = document.getElementById('txtBankInfo');
        this.proofModal = document.getElementById('proofModal');
        this.imgProof = document.getElementById('imgProof');
    }

    bind() {

        this.btnQueue?.addEventListener('click', () => this.handleQueueAction());
        this.btnComplete.addEventListener('click', () => this.handleCompleteBooking());
        this.wdForm.addEventListener('submit', (e) => this.handleWithdrawalRequest(e));
        document.getElementById('histFilter').addEventListener('click', () => this.renderTrips());

        document.querySelectorAll('.nav-item').forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault(); // Mencegah perilaku default dari tag <a>
                const targetHash = item.getAttribute('href');
                
                // Hanya ubah hash jika berbeda untuk memicu event 'hashchange'
                if (window.location.hash !== targetHash) {
                    window.location.hash = targetHash;
                }
            });
        });

        document.getElementById('btnEditBank')?.addEventListener('click', () => {
            this.bankModal.classList.remove('hidden');
            this.bankModal.classList.add('flex');
            // Isi form dengan data lama jika ada
            const p = this.driverData?.driver_profile;
            if(p) {
                document.getElementById('inBankName').value = p.bank_name || '';
                document.getElementById('inAccNumber').value = p.account_number || '';
            }
        });

        document.getElementById('closeBankModal')?.addEventListener('click', () => {
            this.bankModal.classList.add('hidden');
            this.bankModal.classList.remove('flex');
        });

        this.formBank?.addEventListener('submit', (e) => this.handleUpdateBank(e));

    }

    // --- METODE BARU: PEMANTAUAN LOKASI ---
    startLocationWatcher() {
        if (!navigator.geolocation) {
            this.distanceText.textContent = "GPS Error";
            Utils.showToast('Browser tidak mendukung GPS', 'error');
            return;
        }

        // Pantau lokasi secara real-time
        this.watchId = navigator.geolocation.watchPosition(
            (position) => {
                this.currentLat = position.coords.latitude;
                this.currentLng = position.coords.longitude;
                // Setiap lokasi berubah, cek apakah tombol boleh aktif
                this.updateQueueUI(); 
            },
            (error) => {
                console.error("GPS Error:", error);
                this.distanceText.textContent = "Hilang Sinyal";
                this.updateQueueUI(true); // true = ada error
            },
            { enableHighAccuracy: true, maximumAge: 5000, timeout: 10000 }
        );
    }

    // Hitung jarak (Haversine Formula) di Javascript
    calculateDistance(lat1, lon1, lat2, lon2) {
        const R = 6371; // Radius bumi KM
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                  Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                  Math.sin(dLon/2) * Math.sin(dLon/2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        return R * c;
    }

    // --- LOGIKA UTAMA UI (YANG KAMU MINTA) ---
    updateQueueUI(gpsError = false) {
        if (!this.driverData) return;

        // 1. Ambil "Status Virtual" dari Backend
        // Backend mengirim 'available' jika ada di tabel Queue.
        // Backend mengirim 'ontrip' jika ada active_booking.
        // Backend mengirim 'offline' jika tidak keduanya.
        const virtualStatus = this.driverData.driver_profile.status;
        
        // 2. Terjemahkan ke Bahasa Manusia (Boolean)
        const isInQueue = (virtualStatus === 'available'); 
        const isOnTrip  = (virtualStatus === 'ontrip' || this.driverData.active_booking != null);

        // 3. Hitung Jarak
        let dist = 9999;
        if (this.currentLat && this.currentLng) {
            dist = this.calculateDistance(this.AIRPORT_LAT, this.AIRPORT_LNG, this.currentLat, this.currentLng);
            this.distanceText.textContent = dist.toFixed(2) + " km";
        }

        // 4. Update Teks Status (Label Atas)
        if (isOnTrip) {
            this.statusText.textContent = 'Sedang Mengantar';
            this.statusText.className = 'font-bold text-lg text-pending';
        } else if (isInQueue) {
            this.statusText.textContent = 'Dalam Antrian (Online)';
            this.statusText.className = 'font-bold text-lg text-success';
        } else {
            this.statusText.textContent = 'Belum Antri (Offline)';
            this.statusText.className = 'font-bold text-lg text-slate-500 dark:text-slate-400';
        }

        // 5. Update Tombol Aksi
        const btn = this.btnQueue;
        btn.className = "w-full py-3 rounded-xl font-bold text-white shadow-md transition-all"; // Reset class

        // KONDISI 1: SEDANG JALAN (Orderan)
        if (isOnTrip) {
            btn.disabled = true;
            btn.textContent = "Selesaikan Order Dulu";
            btn.classList.add('bg-slate-300', 'dark:bg-slate-700', 'cursor-not-allowed');
            return;
        }

        // KONDISI 2: SUDAH DALAM ANTRIAN
        // Driver bisa keluar antrian kapan saja, tidak peduli jarak.
        if (isInQueue) {
            btn.disabled = false;
            btn.textContent = "Keluar Antrian";
            btn.classList.add('bg-danger', 'hover:bg-red-600', 'active:scale-95');
            return;
        }

        // KONDISI 3: BELUM ANTRI (Mau Masuk)
        // Cek GPS dulu
        if (gpsError || !this.currentLat) {
            btn.disabled = true;
            btn.textContent = "Menunggu Sinyal GPS...";
            btn.classList.add('bg-slate-400', 'cursor-not-allowed');
        } 
        else if (dist > this.MAX_RADIUS_KM) {
            // Diluar Jangkauan
            btn.disabled = true;
            btn.textContent = `Terlalu Jauh (${dist.toFixed(1)} km)`;
            btn.classList.add('bg-slate-400', 'cursor-not-allowed', 'opacity-70');
        } 
        else {
            // Aman: Dalam Jangkauan & GPS Oke
            btn.disabled = false;
            btn.textContent = "Masuk Antrian";
            btn.classList.add('bg-primary-600', 'hover:bg-primary-700', 'active:scale-95');
        }
    }

    route() {
        const hash = (location.hash || '#orders').slice(1);
        this.views.forEach(v => {
        const el = document.getElementById('view-' + v);
        if (el) el.classList.toggle('hidden', v !== hash);
        });

        document.querySelectorAll('.nav-item').forEach(item => {
        item.classList.toggle('active', item.getAttribute('href') === '#' + hash);
        });

        const titles = { orders: 'Beranda', wallet: 'Dompet', history: 'Riwayat', profile: 'Profil' };
        this.pageTitle.textContent = titles[hash] || 'Beranda';

        // Bagian ini yang memuat data secara on-demand
        switch(hash) {
            case 'wallet':
                this.renderWallet(); // <-- Dipanggil HANYA saat tab Dompet dibuka
                break;
            case 'history':
                this.renderTrips(); // <-- Dipanggil HANYA saat tab Riwayat dibuka
                break;
            // Tidak perlu case untuk 'orders' atau 'profile' 
            // karena datanya sudah dimuat oleh loadInitialData()
        }
    }

    // --- FUNGSI BARU UNTUK MEMUAT DATA ---
    async loadInitialData() {
        try {
            // Sekarang 'data' adalah objek driver itu sendiri
            const data = await fetchApi('/driver/profile');
            // Langsung simpan seluruh respons sebagai data driver
            this.driverData = data;
            
            // Ambil active_booking dari properti yang sudah kita tambahkan
            this.activeBooking = data.active_booking;
            
            this.renderProfile();
            this.updateQueueUI();
            this.renderActiveOrder();
        } catch (error) {
            console.error("Gagal memuat data awal driver:", error);
        }
    }

    renderProfile() {
        if (!this.driverData) return;
        this.profileInitial.textContent = this.driverData.name.charAt(0).toUpperCase();
        this.profileName.textContent = this.driverData.name;
        const profile = this.driverData.driver_profile;
        this.profileCar.textContent = `${profile.car_model || '-'} • ${profile.plate_number || '-'}`;

        const p = this.driverData.driver_profile;
        
        // Render Info Bank
        if (p.bank_name && p.account_number) {
            this.txtBankInfo.textContent = `${p.bank_name} - ${p.account_number}`;
            this.txtBankInfo.classList.remove('text-red-500');
        } else {
            this.txtBankInfo.textContent = 'Belum diatur (Wajib isi)';
            this.txtBankInfo.classList.add('text-red-500');
        }
    }

    renderStatus() {
        this.updateQueueUI();

        // ... (logika untuk mengubah teks status tidak berubah) ...
    }

    // --- ACTION HANDLER ---
    async handleQueueAction() {
        // Logika sederhana: 
        // Kalau status 'available' (queue), berarti user klik tombol "Keluar".
        // Kalau status 'offline', berarti user klik tombol "Masuk".
        const virtualStatus = this.driverData.driver_profile.status;
        const action = (virtualStatus === 'available') ? 'leave' : 'join';

        // Validasi GPS hanya jika mau JOIN
        const payload = { action: action };
        if (action === 'join') {
            if (this.currentLat && this.currentLng) {
                payload.latitude = this.currentLat;
                payload.longitude = this.currentLng;
            } else {
                Utils.showToast('Lokasi GPS belum siap.', 'error');
                return;
            }
        }

        // Loading State
        this.btnQueue.disabled = true;
        this.btnQueue.textContent = "Memproses...";

        try {
            const response = await fetchApi('/driver/status', {
                method: 'POST',
                body: JSON.stringify(payload)
            });

            // Update Data Lokal
            // Backend mengembalikan user object terbaru dengan status virtual yang sudah diupdate
            this.driverData = response.data;

            this.updateQueueUI();
            
            const msg = (action === 'join') ? 'Berhasil masuk antrian!' : 'Anda keluar antrian.';
            Utils.showToast(msg, 'success');

        } catch (error) {
            // Jika gagal (misal ditolak server), kembalikan tombol
            this.updateQueueUI(); 
        }
    }


    renderActiveOrder() {
        if (this.activeBooking) {
            this.activeBox.classList.remove('hidden');
            this.textBook.textContent = 'Pesanan Aktif';
            this.activeInfo.innerHTML = `
            <div class="flex items-center gap-2"><span class="font-semibold w-16">Rute:</span> <span>Bandara → ${this.activeBooking.zone_to.name}</span></div>
            <div class="flex items-center gap-2"><span class="font-semibold w-16">Tarif:</span> <span>${Utils.formatCurrency(this.activeBooking.price)}</span></div>
            <div class="flex items-center gap-2"><span class="font-semibold w-16">Status:</span> <span>${this.activeBooking.status}</span></div>
        `;
            console.log('Order aktif ditemukan:', this.activeBooking);

        } else {
            this.activeBox.classList.add('hidden');
            this.textBook.textContent = '';
            console.log('Tidak ada order aktif');
        }
    }

    async renderWallet() {
        try {
            const [balance, withdrawals] = await Promise.all([
                fetchApi('/driver/balance'),
                fetchApi('/driver/withdrawals')
            ]);
            
            this.walletBalance.textContent = Utils.formatCurrency(balance.balance);
            
            // Render Tabel Riwayat
            if (withdrawals.length === 0) {
                this.wdList.innerHTML = `<tr><td colspan="3" class="text-center py-4 text-slate-400 text-xs">Belum ada riwayat penarikan.</td></tr>`;
                return;
            }

            this.wdList.innerHTML = withdrawals.map(w => {
                // Logika Tombol Bukti
                // Tombol muncul jika status Approved/Paid DAN ada file gambarnya
                let proofBtn = '';
                if (['Approved', 'Paid'].includes(w.status) && w.proof_image) {
                    proofBtn = `
                    <button onclick="event.stopPropagation(); window.viewProof('${w.proof_image}')" 
                        class="mt-1 text-[10px] font-bold text-primary-600 bg-primary-50 dark:bg-primary-900/30 border border-primary-200 dark:border-primary-800 px-2 py-1 rounded flex items-center gap-1 hover:bg-primary-100 transition-colors">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                        Lihat Bukti
                    </button>`;
                }

                // Warna badge status
                let badgeClass = 'bg-slate-100 text-slate-600';
                if(w.status === 'Pending') badgeClass = 'bg-yellow-100 text-yellow-800';
                if(w.status === 'Approved' || w.status === 'Paid') badgeClass = 'bg-emerald-100 text-emerald-800';
                if(w.status === 'Rejected') badgeClass = 'bg-red-100 text-red-800';

                return `
                <tr class="border-t border-slate-100 dark:border-slate-700">
                    <td class="py-3 pr-2 align-top">
                        <div class="text-slate-700 dark:text-slate-200 font-medium text-sm">
                            ${new Date(w.requested_at).toLocaleDateString('id-ID', {day: 'numeric', month: 'short'})}
                        </div>
                        <div class="text-[10px] text-slate-400">
                            ${new Date(w.requested_at).toLocaleTimeString('id-ID', {hour: '2-digit', minute:'2-digit'})}
                        </div>
                        ${proofBtn}
                    </td>
                    <td class="py-3 pr-2 align-top font-mono font-medium text-slate-800 dark:text-slate-100">
                        ${Utils.formatCurrency(w.amount)}
                    </td>
                    <td class="py-3 align-top text-left">
                        <span class="px-2 py-1 rounded text-[10px] font-bold uppercase tracking-wide ${badgeClass}">
                            ${w.status}
                        </span>
                    </td>
                </tr>`;
            }).join('');

        } catch (error) { 
            console.error(error);
            this.wdList.innerHTML = `<tr><td colspan="3" class="text-center text-red-500 py-4">Gagal memuat data.</td></tr>`;
        }
    }
    async renderTrips() {

        // ... (logika mengambil tanggal dari filter tidak berubah) ...
        const from = document.getElementById('histFrom').value;
        const to = document.getElementById('histTo').value;
        const df = from ? new Date(from) : null;
        const dt = to ? new Date(to) : null;
        if (df) { df.setHours(0, 0, 0, 0); }
        if (dt) { dt.setHours(23, 59, 59, 999); }

        const params = new URLSearchParams();
        // ... (tambahkan date_from dan date_to ke params) ...
        if (df) params.append('date_from', df.toISOString());
        if (dt) params.append('date_to', dt.toISOString());
        
        try {
            const history = await fetchApi(`/driver/history?${params.toString()}`);
            // ... (logika render riwayat perjalanan tidak berubah, gunakan data 'history') ...
            if (history.length === 0) {
                this.tripList.innerHTML = `<div class="text-center text-slate-500 dark:text-slate-400 p-8 bg-white dark:bg-slate-800 rounded-xl shadow-md">Tidak ada riwayat perjalanan pada rentang tanggal yang dipilih.</div>`;
                return;
            }

            this.tripList.innerHTML = history.map(t => {
                console.log(t);
                return `
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="font-bold text-slate-800 dark:text-slate-100">${t.booking.zone_to.name}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">${new Date(t.created_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })}</p>
                    </div>
                    <p class="font-bold text-lg text-success">${Utils.formatCurrency(t.amount)}</p>
                </div>
                <div class="mt-2 pt-2 border-t border-slate-100 dark:border-slate-700 flex justify-between text-xs">
                    <span class="px-2 py-0.5 rounded-full bg-primary-100 dark:bg-primary-900/50 text-primary-800 dark:text-primary-300 font-medium">${t.method}</span>
                    <span class="px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-800 dark:text-slate-200 font-medium">${t.booking.status}</span>
                </div>
            </div>`;
            }).join('');
        } catch (error) {
            console.error("Gagal memuat riwayat perjalanan:", error);
        }
    }

    // --- FUNGSI AKSI (sekarang memanggil API) ---
        
    async handleStatusChange(e) {
        // 1. Mencegah toggle berubah dulu sebelum validasi server sukses
        e.preventDefault(); 
        
        const targetCheckbox = e.target;
        const wantToOnline = !targetCheckbox.checked; // Keadaan saat ini (sebelum di-klik user adalah checked=false jika offline)
        // Koreksi logika checkbox:
        // Jika checkbox tadi tidak dicentang, dan user klik, maka user ingin mencentang (Online)
        // e.preventDefault() membuat checked tidak berubah secara visual dulu.
        
        // Logika: 
        // Status awal visual: OFFLINE (unchecked). User klik.
        // Kita preventDefault.
        // Kita cek lokasi. Jika sukses -> set checked = true.
        
        const nextStatus = targetCheckbox.checked ? 'offline' : 'available'; 
        // Karena preventDefault(), 'checked' masih status LAMA.
        // Jadi jika checkbox sekarang false (offline), user klik ingin jadi 'available'.

        if (nextStatus === 'available') {
            // --- JIKA INGIN MASUK ANTRIAN (ONLINE) ---
            Utils.showToast('Sedang memeriksa lokasi Anda...', 'info');

            if (!navigator.geolocation) {
                Utils.showToast('Browser Anda tidak mendukung Geolocation.', 'error');
                return;
            }

            navigator.geolocation.getCurrentPosition(
                async (position) => {
                    // Sukses dapat lokasi
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    
                    await this.sendStatusUpdate(nextStatus, lat, lng, targetCheckbox);
                },
                (error) => {
                    // Gagal dapat lokasi
                    console.error(error);
                    let msg = 'Gagal mengambil lokasi GPS.';
                    if(error.code === 1) msg = 'Izin lokasi ditolak. Mohon aktifkan GPS.';
                    Utils.showToast(msg, 'error');
                },
                { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
            );

        } else {
            // --- JIKA INGIN KELUAR ANTRIAN (OFFLINE) ---
            // Tidak perlu cek lokasi untuk offline
            await this.sendStatusUpdate('offline', null, null, targetCheckbox);
        }
    }

    async sendStatusUpdate(status, lat, lng, checkboxEl) {
        try {
            const payload = { status: status };
            if (lat && lng) {
                payload.latitude = lat;
                payload.longitude = lng;
            }

            // Panggil API (Note: API response structure kita ubah sedikit di backend tadi)
            const response = await fetchApi('/driver/status', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            
            // Jika sukses (tidak throw error):
            // 1. Update visual checkbox secara manual
            checkboxEl.checked = (status === 'available');
            
            // 2. Update data lokal
            // response.data berisi user object dr backend
            this.driverData = response.data; 

            // 3. Render ulang UI
            this.renderStatus();
            this.renderProfile();

            const msg = status === 'available' 
                ? 'Berhasil masuk antrian bandara!' 
                : 'Anda sekarang Offline.';
            Utils.showToast(msg, 'success');

        } catch (error) {
            // Error sudah ditangani fetchApi (toast muncul), 
            // tapi kita pastikan checkbox visual sesuai status asli (batal berubah)
            checkboxEl.checked = (status !== 'available');
            
            // Pesan spesifik jika error 422 (Kejauhan) sudah muncul via Utils.showToast dari fetchApi,
            // tapi jika ingin custom handling bisa di sini.
        }
    }

    async handleCompleteBooking() {
        if (!this.driverData.active_booking) return;
        if (!confirm('Selesaikan perjalanan ini?')) return;
    
        try {
            // Backend akan return profile terbaru (status akan kembali ke 'offline' otomatis)
            const updatedProfile = await fetchApi(`/driver/bookings/${this.driverData.active_booking.id}/complete`, { method: 'POST' });
            
            this.driverData = updatedProfile;
            
            // UI Update
            this.renderActiveOrder(); // Kotak order hilang
            this.updateQueueUI(); // Tombol antrian aktif kembali (bisa klik Masuk)
            this.renderProfile();
    
            Utils.showToast('Perjalanan selesai', 'success');
        } catch (error) { /* handled */ }
    }
    
    async handleWithdrawalRequest(e) {
        e.preventDefault();
        const amount = parseInt(document.getElementById('wdAmount').value, 10);
        if (isNaN(amount) || amount < 10000) {
            Utils.showToast('Jumlah penarikan minimal Rp 10.000', 'error');
            return;
        }
        try {
            await fetchApi('/driver/withdrawals', {
                method: 'POST',
                body: JSON.stringify({ amount })
            });
            Utils.showToast('Pengajuan penarikan dikirim', 'success');
            document.getElementById('wdAmount').value = '';
            this.renderWallet(); // Refresh data dompet
        } 
        catch (error) { /* error ditangani fetchApi */ }
    }

    async handleUpdateBank(e) {
        e.preventDefault();
        const payload = {
            bank_name: document.getElementById('inBankName').value,
            account_number: document.getElementById('inAccNumber').value
        };
        
        try {
            const res = await fetchApi('/driver/bank-details', { // Buat route baru di api.php
                method: 'POST',
                body: JSON.stringify(payload)
            });
            this.driverData = res.data;
            this.renderProfile();
            this.bankModal.classList.add('hidden');
            this.bankModal.classList.remove('flex');
            Utils.showToast('Rekening berhasil disimpan', 'success');
        } catch(err) { /* handled */ }
    }


}
