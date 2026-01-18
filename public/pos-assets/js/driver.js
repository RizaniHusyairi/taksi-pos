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
        const config = window.taksiConfig?.driver_queue || {};
        this.AIRPORT_LAT = config.latitude || -0.371975;
        this.AIRPORT_LNG = config.longitude || 117.257919;
        this.MAX_RADIUS_KM = config.max_radius_km || 2.0; // Radius toleransi

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
        this.startAutoLocationSender();

        window.addEventListener('hashchange', () => this.route());
        this.route();
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
        // TAMBAHAN BARU
        this.infoIncome = document.getElementById('infoIncome');
        this.infoDebt = document.getElementById('infoDebt');
        this.btnRequestWithdrawal = document.getElementById('btnRequestWithdrawal');
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

        // Cache Modal Baru
        this.leaveModal = document.getElementById('leaveQueueModal');
        this.formLeave = document.getElementById('formLeaveQueue');
        this.boxSelf = document.getElementById('boxSelfPassenger');
        this.boxOther = document.getElementById('boxOtherReason');
        this.btnCancelLeave = document.getElementById('btnCancelLeave');
        // Radio buttons
        this.radioReasons = document.getElementsByName('reason');

        // --- TAMBAHAN ELEMEN PASSWORD ---
        this.formChangePassword = document.getElementById('formChangePassword');
        this.inpCurrentPass = document.getElementById('currentPass');
        this.inpNewPass = document.getElementById('newPass');
        this.inpConfirmPass = document.getElementById('confirmPass');

        // --- ELEMEN BARU ---
        this.profilePhone = document.getElementById('profilePhone');
        this.profileEmail = document.getElementById('profileEmail');

        // Modal Edit Profil
        this.editProfileModal = document.getElementById('editProfileModal');
        this.btnOpenEditProfile = document.getElementById('btnOpenEditProfile');
        this.closeEditProfile = document.getElementById('closeEditProfile');
        this.formEditProfile = document.getElementById('formEditProfile');

        // Input Form Edit
        this.inpEditName = document.getElementById('editName');
        this.inpEditUsername = document.getElementById('editUsername');
        this.inpEditPhone = document.getElementById('editPhone');
        this.inpEditEmail = document.getElementById('editEmail');
        this.inpEditCar = document.getElementById('editCarModel');
        this.inpEditPlate = document.getElementById('editPlate');
    }

    bind() {

        this.btnQueue?.addEventListener('click', () => this.handleQueueAction());
        // this.btnComplete.addEventListener('click', () => this.handleOrderAction());
        document.getElementById('histFilter').addEventListener('click', () => this.renderTrips());

        if (this.btnRequestWithdrawal) {
            this.btnRequestWithdrawal.addEventListener('click', () => this.handleWithdrawalRequest());
        }
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
            if (p) {
                document.getElementById('inAccNumber').value = p.account_number || '';
            }
        });

        document.getElementById('closeBankModal')?.addEventListener('click', () => {
            this.bankModal.classList.add('hidden');
            this.bankModal.classList.remove('flex');
        });

        this.formBank?.addEventListener('submit', (e) => this.handleUpdateBank(e));

        // Listener Modal Edit Profil
        this.btnOpenEditProfile?.addEventListener('click', () => this.openEditProfile());
        this.closeEditProfile?.addEventListener('click', () => {
            this.editProfileModal.classList.add('hidden');
            this.editProfileModal.classList.remove('flex');
        });

        // Listener Submit Form
        this.formEditProfile?.addEventListener('submit', (e) => this.submitEditProfile(e));

        // Listener Modal Keluar
        this.btnCancelLeave?.addEventListener('click', () => {
            this.leaveModal.classList.add('hidden');
            this.leaveModal.classList.remove('flex');
        });

        // Listener Radio Button (Toggle Form)
        this.radioReasons.forEach(radio => {
            radio.addEventListener('change', (e) => {
                if (e.target.value === 'self') {
                    this.boxSelf.classList.remove('hidden');
                    this.boxOther.classList.add('hidden');
                } else {
                    this.boxSelf.classList.add('hidden');
                    this.boxOther.classList.remove('hidden');
                }
            });
        });

        // Listener Submit Form Keluar
        this.formLeave?.addEventListener('submit', (e) => this.submitLeaveQueue(e));

        // --- TAMBAHAN LISTENER PASSWORD ---
        if (this.formChangePassword) {
            this.formChangePassword.addEventListener('submit', (e) => this.handleChangePassword(e));
        }

        if (this.activeBox) {
            this.activeBox.addEventListener('click', (e) => {
                // Cek jika yang diklik adalah tombol #btnOrderAction atau anak elemennya
                const btn = e.target.closest('#btnOrderAction');
                if (btn) {
                    this.handleOrderAction();
                }
            });
        }

    }

    startAutoLocationSender() {
        if (!navigator.geolocation) {
            if (this.distanceText) this.distanceText.textContent = "GPS Error";
            return;
        }

        // Gunakan watchPosition agar realtime
        this.watchId = navigator.geolocation.watchPosition(
            (position) => {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;

                this.currentLat = lat;
                this.currentLng = lng;

                // Hitung jarak lokal untuk UI Driver (Visual saja)
                const dist = this.calculateDistance(this.AIRPORT_LAT, this.AIRPORT_LNG, lat, lng);
                if (this.distanceText) this.distanceText.textContent = dist.toFixed(2) + " km";

                // KIRIM KE SERVER (Throttling: Agar tidak spam server tiap milidetik)
                // Kita kirim setiap kali lokasi berubah signifikan atau interval waktu
                // Untuk simpelnya, kita pakai throttle manual sederhana:
                const now = Date.now();
                if (!this.lastSent || now - this.lastSent > 5000) { // Kirim tiap 5 detik
                    this.sendLocationUpdate(lat, lng);
                    this.lastSent = now;
                }
            },
            (error) => { console.error("GPS Error:", error); },
            { enableHighAccuracy: true, maximumAge: 0 }
        );
    }

    async sendLocationUpdate(lat, lng) {
        try {
            const res = await fetchApi('/driver/location', {
                method: 'POST',
                body: JSON.stringify({ latitude: lat, longitude: lng })
            });

            // Update status text saja, tombol diurus oleh updateQueueUI
            if (res.status === 'standby') {
                // Jangan hide tombol di sini!
                // Update data lokal agar UI reaktif
                if (this.driverData && this.driverData.driver_profile) {
                    this.driverData.driver_profile.status = 'standby';
                    this.updateQueueUI();
                }
            } else if (res.status === 'offline') {
                if (this.driverData && this.driverData.driver_profile) {
                    this.driverData.driver_profile.status = 'offline';
                    this.updateQueueUI();
                }
            }
        } catch (e) {
            console.error("Gagal update lokasi", e);
        }
    }
    // Hitung jarak (Haversine Formula) di Javascript
    calculateDistance(lat1, lon1, lat2, lon2) {
        const R = 6371; // Radius bumi KM
        const dLat = (lat2 - lat1) * Math.PI / 180;
        const dLon = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
            Math.sin(dLon / 2) * Math.sin(dLon / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    }

    // --- LOGIKA UTAMA UI (YANG KAMU MINTA) ---
    // --- UPDATE UI STATUS ANTRIAN ---
    updateQueueUI(gpsError = false) {
        if (!this.driverData) return;

        const profile = this.driverData.driver_profile;
        const virtualStatus = profile.status; // 'standby', 'offline', 'ontrip'

        // Cek apakah sedang ada booking aktif?
        const isOnTrip = (virtualStatus === 'ontrip' || this.driverData.active_booking != null);

        // Cek apakah standby (Di antrian & di lokasi)
        const isStandby = (virtualStatus === 'standby');

        // Hitung Jarak
        let dist = 9999;
        if (this.currentLat && this.currentLng) {
            dist = this.calculateDistance(this.AIRPORT_LAT, this.AIRPORT_LNG, this.currentLat, this.currentLng);
            if (this.distanceText) this.distanceText.textContent = dist.toFixed(2) + " km";
        }

        // Update Teks Status Header
        if (isOnTrip) {
            this.statusText.textContent = 'Sedang Mengantar';
            this.statusText.className = 'font-bold text-lg text-pending';
        } else if (isStandby) {
            this.statusText.textContent = 'Standby (Siap Dipilih)';
            this.statusText.className = 'font-bold text-lg text-success';
        } else {
            this.statusText.textContent = 'Di Luar Area / Offline';
            this.statusText.className = 'font-bold text-lg text-slate-500 dark:text-slate-400';
        }

        // --- LOGIKA TOMBOL UTAMA ---
        const btn = this.btnQueue;
        btn.classList.remove('hidden'); // Pastikan tombol selalu muncul dulu
        btn.disabled = false;
        btn.className = "w-full py-3 rounded-xl font-bold text-white shadow-md transition-all";

        // KONDISI 1: SEDANG NARIK
        if (isOnTrip) {
            btn.disabled = true;
            btn.textContent = "Selesaikan Order Dulu";
            btn.classList.add('bg-slate-300', 'dark:bg-slate-700', 'cursor-not-allowed');
            return;
        }

        // KONDISI 2: STANDBY (Bisa Keluar)
        // INI YANG DIMINTA: Tetap bisa klik keluar
        if (isStandby) {
            btn.textContent = "Keluar Antrian";
            btn.classList.add('bg-danger', 'hover:bg-red-600', 'active:scale-95');
            // Saat diklik, ini akan memicu handleQueueAction -> 'leave' -> Buka Modal
            return;
        }

        // KONDISI 3: OFFLINE (Bisa Masuk / Menunggu GPS)
        // Jika offline, berarti dia keluar manual atau belum sampai.
        // Kita izinkan masuk manual (Re-join) atau tunggu GPS.

        if (gpsError || !this.currentLat) {
            btn.disabled = true;
            btn.textContent = "Menunggu Sinyal GPS...";
            btn.classList.add('bg-slate-400', 'cursor-not-allowed');
        }
        else if (dist > this.MAX_RADIUS_KM) {
            btn.disabled = true;
            btn.textContent = `Terlalu Jauh (${dist.toFixed(1)} km)`;
            btn.classList.add('bg-slate-400', 'cursor-not-allowed', 'opacity-70');
        }
        else {
            // Jarak dekat tapi status offline (berarti habis keluar manual)
            // Tampilkan tombol "Masuk Antrian" (Manual Re-join)
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
        switch (hash) {
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
        // Data Tambahan (Email & HP)
        if (this.profilePhone) this.profilePhone.textContent = this.driverData.phone_number || '-';
        if (this.profileEmail) this.profileEmail.textContent = this.driverData.email || '-';
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

    // --- HANDLE TOMBOL UTAMA (Action Handler) ---
    async handleQueueAction() {
        // Ambil status terbaru dari data lokal
        const profile = this.driverData.driver_profile;
        const currentStatus = profile.status; // Bisa 'standby', 'offline', atau 'ontrip'

        // Tentukan Aksi Berdasarkan Status
        let action = '';

        if (currentStatus === 'standby') {
            // Jika sedang Standby, berarti tombol berfungsi untuk "Keluar Antrian"
            action = 'leave';
        } else {
            // Jika Offline/Lainnya, berarti tombol berfungsi untuk "Masuk Antrian"
            action = 'join';
        }

        console.log(`Status: ${currentStatus}, Action: ${action}`); // Debugging

        // --- EKSEKUSI AKSI ---

        // 1. JIKA AKSI LEAVE -> BUKA MODAL
        if (action === 'leave') {
            this.leaveModal.classList.remove('hidden');
            this.leaveModal.classList.add('flex');

            // Reset form modal
            this.formLeave.reset();
            this.boxSelf.classList.add('hidden');
            this.boxOther.classList.remove('hidden');
            return;
        }

        // 2. JIKA AKSI JOIN -> LANGSUNG EKSEKUSI API
        if (action === 'join') {
            if (this.currentLat && this.currentLng) {
                this.executeStatusChange({
                    action: 'join',
                    latitude: this.currentLat,
                    longitude: this.currentLng
                });
            } else {
                Utils.showToast('Lokasi GPS belum siap.', 'error');
            }
        }
    }


    renderActiveOrder() {
        if (this.activeBooking) {
            this.activeBox.classList.remove('hidden');
            this.textBook.textContent = 'Pesanan Berjalan';

            // 1. Ambil UI Config berdasarkan status
            // Asumsi status di DB: 'Assigned' (Jemput), 'OnTrip'/'Started' (Jalan)
            // Jika DB masih pakai 'Paid'/'CashDriver' untuk status awal, anggap itu 'Assigned'
            let status = this.activeBooking.status;
            if (['Paid', 'CashDriver'].includes(status)) status = 'Assigned';

            const ui = this.getDriverStatusUI(status);

            // 2. Format Data
            const price = Utils.formatCurrency(this.activeBooking.price);
            const passengerPhone = this.activeBooking.passenger_phone || '-';
            const waLink = `https://wa.me/${passengerPhone.replace(/^0/, '62').replace(/\D/g, '')}`;

            // 3. Render HTML Card Baru
            this.activeBox.innerHTML = `
            <div class="bg-white dark:bg-slate-800 rounded-xl shadow-lg overflow-hidden border border-slate-100 dark:border-slate-700">
                
                <div class="${ui.bgClass} p-5 text-center text-white transition-colors duration-300">
                    <div class="flex justify-center mb-2">${ui.icon}</div>
                    <h2 class="text-xl font-black tracking-wider uppercase">${ui.title}</h2>
                    <p class="text-white/90 text-xs font-medium mt-1">${ui.desc}</p>
                </div>

                <div class="p-5 space-y-5">
                    
                    <div class="flex items-start gap-4">
                        <div class="flex flex-col items-center mt-1">
                            <div class="w-3 h-3 rounded-full bg-slate-300 ring-4 ring-slate-100 dark:ring-slate-700"></div>
                            <div class="w-0.5 h-12 bg-slate-200 dark:bg-slate-600 my-1"></div>
                            <div class="w-3 h-3 rounded-full bg-primary-600 ring-4 ring-primary-100 dark:ring-primary-900"></div>
                        </div>
                        <div class="flex-1 space-y-6">
                            <div>
                                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Titik Jemput</p>
                                <p class="font-bold text-slate-800 dark:text-slate-100 text-sm">Bandara APT Pranoto</p>
                            </div>
                            <div>
                                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Tujuan</p>
                                <p class="font-bold text-slate-800 dark:text-slate-100 text-lg leading-tight">
                                    ${this.activeBooking.zone_to.name}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-slate-50 dark:bg-slate-700/50 rounded-xl p-3 border border-slate-100 dark:border-slate-600 flex items-center justify-between">
                        <div>
                            <p class="text-[10px] text-slate-400 font-bold uppercase mb-1">Penumpang</p>
                            <a href="tel:${passengerPhone}" class="flex items-center gap-1 font-mono font-bold text-slate-700 dark:text-slate-200">
                                ${passengerPhone}
                            </a>
                        </div>
                        <div class="flex gap-2">
                            <a href="${waLink}" target="_blank" class="w-10 h-10 rounded-lg bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400 flex items-center justify-center hover:bg-green-200 transition-colors">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                            </a>
                        </div>
                    </div>
                    
                    <div class="text-center pb-2">
                         <span class="text-xs text-slate-400">Tarif Perjalanan</span>
                         <div class="text-xl font-bold text-slate-800 dark:text-slate-100">${price}</div>
                    </div>

                    <button id="btnOrderAction" class="w-full ${ui.btnClass} text-white font-bold py-3.5 rounded-xl shadow-lg transition-transform active:scale-95 flex items-center justify-center gap-2">
                        ${ui.btnText}
                    </button>
                </div>
            </div>`;

        } else {
            this.activeBox.classList.add('hidden');
            this.textBook.textContent = '';
        }
    }

    async renderWallet() {
        try {
            const [balanceData, withdrawals] = await Promise.all([
                fetchApi('/driver/balance'),
                fetchApi('/driver/withdrawals')
            ]);

            // 1. Render Saldo Utama
            this.walletBalance.textContent = Utils.formatCurrency(balanceData.balance);

            // 2. Render Detail (Pemasukan vs Hutang)
            // Asumsi backend kirim data income_pending & debt_pending (sesuai controller baru)
            if (this.infoIncome) this.infoIncome.textContent = Utils.formatCurrency(balanceData.income_pending || 0);
            if (this.infoDebt) this.infoDebt.textContent = Utils.formatCurrency(balanceData.debt_pending || 0);

            // --- LOGIKA TOMBOL BARU ---
            const profile = this.driverData?.driver_profile;
            const hasAccount = profile && profile.account_number;

            // Reset class dasar tombol
            this.btnRequestWithdrawal.className = "w-full font-bold py-3 rounded-lg shadow-md transition-transform active:scale-95 text-white";

            if (!hasAccount) {
                // KASUS 1: Belum ada rekening
                this.btnRequestWithdrawal.disabled = true;
                this.btnRequestWithdrawal.textContent = "Lengkapi Rekening BTN Dulu";
                this.btnRequestWithdrawal.classList.add('bg-slate-400', 'cursor-not-allowed');

            } else if (balanceData.balance < 10000) {
                // KASUS 2: Saldo kurang
                this.btnRequestWithdrawal.disabled = true;
                this.btnRequestWithdrawal.textContent = "Saldo Belum Cukup (< 10rb)";
                this.btnRequestWithdrawal.classList.add('bg-slate-400', 'cursor-not-allowed');

            } else {
                // KASUS 3: Siap Cair
                this.btnRequestWithdrawal.disabled = false;
                this.btnRequestWithdrawal.textContent = "Cairkan Dana Sekarang";
                this.btnRequestWithdrawal.classList.add('bg-primary-600', 'hover:bg-primary-700');
            }

            // 3. Atur Status Tombol
            if (balanceData.balance < 10000) {
                this.btnRequestWithdrawal.disabled = true;
                this.btnRequestWithdrawal.textContent = "Saldo Belum Cukup (< 10rb)";
                this.btnRequestWithdrawal.classList.add('bg-slate-400');
            } else {
                this.btnRequestWithdrawal.disabled = false;
                this.btnRequestWithdrawal.textContent = "Cairkan Dana Sekarang";
                this.btnRequestWithdrawal.classList.remove('bg-slate-400');
            }

            // 4. Render Tabel Riwayat (Sama seperti kode lama)
            if (withdrawals.length === 0) {
                this.wdList.innerHTML = `<tr><td colspan="3" class="text-center py-4 text-slate-400 text-xs">Belum ada riwayat penarikan.</td></tr>`;
                return;
            }

            this.wdList.innerHTML = withdrawals.map(w => {
                // ... (COPY PASTE LOGIKA RENDER TABEL DARI KODE LAMA ANDA) ...
                // Kode render tabel di pesan sebelumnya sudah bagus, pakai itu saja.
                let badgeClass = 'bg-slate-100 text-slate-600';
                if (w.status === 'Pending') badgeClass = 'bg-yellow-100 text-yellow-800';
                if (w.status === 'Approved' || w.status === 'Paid') badgeClass = 'bg-emerald-100 text-emerald-800';

                return `
               <tr class="border-t border-slate-100 dark:border-slate-700">
                   <td class="py-3 pr-2 align-top">
                       <div class="text-slate-700 dark:text-slate-200 font-medium text-sm">
                           ${new Date(w.requested_at).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' })}
                       </div>
                       <div class="text-[10px] text-slate-400">
                           ${new Date(w.requested_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}
                       </div>
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
        const from = document.getElementById('histFrom').value;
        const to = document.getElementById('histTo').value;
        const params = new URLSearchParams();
        if (from) params.append('date_from', from);
        if (to) params.append('date_to', to);

        try {
            const history = await fetchApi(`/driver/history?${params.toString()}`);

            if (history.length === 0) {
                this.tripList.innerHTML = `<div class="text-center text-slate-500 p-4">Tidak ada riwayat.</div>`;
                return;
            }

            this.tripList.innerHTML = history.map(t => {
                // 1. Tentukan Nama Tujuan
                const destName = t.booking.zone_to
                    ? t.booking.zone_to.name
                    : (t.booking.manual_destination || 'Tujuan Manual');

                // 2. LOGIKA STATUS CAIR/LUNAS
                const pStatus = t.payout_status || 'Unpaid';
                let statusBadge = '';

                if (t.method === 'CashDriver') {
                    // --- KASUS HUTANG (Driver bawa uang tunai) ---
                    if (pStatus === 'Paid') {
                        statusBadge = `<span class="text-[10px] font-bold text-emerald-600 bg-emerald-100 border border-emerald-200 px-1.5 py-0.5 rounded">Lunas</span>`;
                    } else if (pStatus === 'Processing') {
                        statusBadge = `<span class="text-[10px] font-bold text-yellow-600 bg-yellow-100 border border-yellow-200 px-1.5 py-0.5 rounded">Proses Potong</span>`;
                    } else {
                        statusBadge = `<span class="text-[10px] font-bold text-red-600 bg-red-100 border border-red-200 px-1.5 py-0.5 rounded">Belum Lunas</span>`;
                    }
                } else {
                    // --- KASUS PEMASUKAN (QRIS/CashCSO) ---
                    if (pStatus === 'Paid') {
                        statusBadge = `<span class="text-[10px] font-bold text-emerald-600 bg-emerald-100 border border-emerald-200 px-1.5 py-0.5 rounded">Sudah Cair</span>`;
                    } else if (pStatus === 'Processing') {
                        statusBadge = `<span class="text-[10px] font-bold text-blue-600 bg-blue-100 border border-blue-200 px-1.5 py-0.5 rounded">Sedang Diproses</span>`;
                    } else {
                        statusBadge = `<span class="text-[10px] font-bold text-slate-500 bg-slate-100 border border-slate-200 px-1.5 py-0.5 rounded">Belum Dicairkan</span>`;
                    }
                }

                // 3. Render Kartu
                return `
                <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4 mb-3 border-l-4 border-primary-500">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="font-bold text-slate-800 dark:text-slate-100 text-sm">${destName}</p>
                            <p class="text-xs text-slate-500 mt-1">${new Date(t.created_at).toLocaleString('id-ID')}</p>
                            <div class="mt-2">
                                ${statusBadge}
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="font-bold text-success">${Utils.formatCurrency(t.amount)}</p>
                            <span class="text-[10px] bg-slate-100 dark:bg-slate-700 px-2 py-0.5 rounded text-slate-500 inline-block mt-1">
                                ${t.method === 'CashDriver' ? 'Tunai (Supir)' : (t.method === 'CashCSO' ? 'Tunai (Kasir)' : t.method)}
                            </span>
                        </div>
                    </div>
                </div>`;
            }).join('');
        } catch (error) { console.error(error); }
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
                    if (error.code === 1) msg = 'Izin lokasi ditolak. Mohon aktifkan GPS.';
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

    // Helper: Menerjemahkan Status & Warna untuk Supir
    getDriverStatusUI(status) {
        switch (status) {
            case 'Assigned':
                return {
                    title: 'JEMPUT PENUMPANG',
                    desc: 'Segera menuju lokasi penjemputan (Bandara).',
                    bgClass: 'bg-yellow-500', // Kuning
                    btnText: 'Mulai Perjalanan',
                    btnClass: 'bg-blue-600 hover:bg-blue-700',
                    icon: '<svg class="w-8 h-8 text-white animate-bounce" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>'
                };
            // Status baru: OnTrip
            case 'OnTrip':
                return {
                    title: 'ANTAR KE TUJUAN',
                    desc: 'Perjalanan menuju lokasi tujuan.',
                    bgClass: 'bg-blue-600', // Biru
                    btnText: 'Selesaikan Perjalanan',
                    btnClass: 'bg-success hover:bg-emerald-600',
                    icon: '<svg class="w-8 h-8 text-white animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>'
                };
            default:
                return {
                    title: status,
                    desc: 'Menunggu konfirmasi...',
                    bgClass: 'bg-slate-500',
                    btnText: 'Proses',
                    btnClass: 'bg-slate-600',
                    icon: ''
                };
        }
    }

    async handleCompleteBooking() {
        if (!this.driverData.active_booking) return;
        if (!confirm('Selesaikan perjalanan ini?')) return;

        try {
            // Tampilkan loading
            this.btnComplete.disabled = true;
            this.btnComplete.textContent = "Memproses...";

            // Panggil API Complete
            await fetchApi(`/driver/bookings/${this.driverData.active_booking.id}/complete`, { method: 'POST' });

            // --- PERBAIKAN UTAMA DI SINI ---
            // Jangan update manual parsial. Reload data utuh dari server agar 100% sinkron.
            await this.loadInitialData();

            Utils.showToast('Perjalanan selesai', 'success');
        } catch (error) {
            /* handled */
            console.error(error);
            // Kembalikan tombol jika error
            this.renderActiveOrder();
        }
    }

    async handleWithdrawalRequest() {
        if (!confirm("Apakah Anda yakin ingin mencairkan semua saldo bersih yang tersedia?")) return;

        // UI Loading
        this.btnRequestWithdrawal.disabled = true;
        this.btnRequestWithdrawal.textContent = "Memproses...";

        try {
            // Panggil API (tanpa body, karena nominal otomatis di backend)
            await fetchApi('/driver/withdrawals', {
                method: 'POST'
            });

            Utils.showToast('Pengajuan pencairan berhasil dikirim!', 'success');

            // Refresh tampilan dompet
            this.renderWallet();
        }
        catch (error) {
            // Reset tombol jika error
            this.renderWallet();
        }
    }

    async handleUpdateBank(e) {
        e.preventDefault();
        const payload = {
            bank_name: 'Bank BTN',
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
        } catch (err) { /* handled */ }
    }

    // Method Baru untuk Submit Form Modal
    async submitLeaveQueue(e) {
        e.preventDefault();

        // Ambil nilai radio yang terpilih
        const reason = document.querySelector('input[name="reason"]:checked').value;

        const payload = {
            action: 'leave',
            reason: reason
        };

        if (reason === 'self') {
            const dest = document.getElementById('manualDest').value;
            const price = document.getElementById('manualPrice').value;

            if (!dest || !price) {
                return Utils.showToast('Mohon isi tujuan dan nominal', 'error');
            }

            payload.manual_destination = dest;
            payload.manual_price = price;
        } else {
            payload.other_reason = document.getElementById('otherReasonText').value;
        }

        // Tutup modal & Panggil API
        this.leaveModal.classList.add('hidden');
        this.leaveModal.classList.remove('flex');

        await this.executeStatusChange(payload);
    }

    // Helper untuk eksekusi API (Masuk/Keluar Antrian)
    async executeStatusChange(payload) {
        // 1. Kunci Tombol & Tampilkan Loading
        if (this.btnQueue) {
            this.btnQueue.disabled = true;
            this.btnQueue.textContent = "Memproses...";
        }

        try {
            // 2. Panggil API
            const response = await fetchApi('/driver/status', {
                method: 'POST',
                body: JSON.stringify(payload)
            });

            // 3. PERBAIKAN UTAMA: Update Data Lokal LANGSUNG dari Response
            // Backend DriverApiController->setStatus mengembalikan data user terbaru di response.data
            // Kita pakai data ini langsung agar UI berubah instan (tanpa menunggu loadInitialData)
            if (response.data) {
                this.driverData = response.data;

                // Jika di response backend belum ada active_booking, kita pertahankan yang lama biar aman
                if (this.activeBooking && !this.driverData.active_booking) {
                    this.driverData.active_booking = this.activeBooking;
                }
            }

            // 4. Update UI Secara Paksa SEKARANG JUGA
            // Karena this.driverData sudah baru (status: standby), tombol akan langsung berubah merah
            this.updateQueueUI();

            // 5. Pesan Sukses
            if (payload.action === 'join') {
                Utils.showToast('Berhasil masuk antrian!', 'success');
            } else if (payload.reason === 'self') {
                Utils.showToast('Data penumpang dicatat. Saldo terpotong Rp 10.000', 'success');
                this.renderWallet();
            } else {
                Utils.showToast('Anda keluar antrian.', 'success');
            }

            // 6. Sinkronisasi Penuh di Background (Opsional, biar data benar-benar valid)
            // Tidak perlu 'await' agar UI tidak nge-freeze
            this.loadInitialData();

        } catch (error) {
            console.error("Gagal update status:", error);
            // Jika gagal, kembalikan UI ke kondisi semula (berdasarkan data terakhir yang valid)
            this.updateQueueUI();
        }
    }

    async handleOrderAction() {
        if (!this.activeBooking) return;

        const currentStatus = this.activeBooking.status;
        // Logic mapping status (Sesuaikan dengan backend Anda)
        // Jika 'Paid'/'Assigned' -> Ubah jadi 'Started'
        // Jika 'Started' -> Ubah jadi 'Completed'

        let nextEndpoint = '';
        let confirmMsg = '';
        console.log("Current Booking Status:", this.activeBooking);

        if (['Paid', 'CashDriver', 'Assigned'].includes(currentStatus)) {
            nextEndpoint = `/driver/bookings/${this.activeBooking.id}/start`; // Endpoint backend untuk mulai jalan
            confirmMsg = 'Mulai perjalanan sekarang?';
        } else if (currentStatus === 'OnTrip') {
            nextEndpoint = `/driver/bookings/${this.activeBooking.id}/complete`; // Endpoint backend untuk selesai
            confirmMsg = 'Selesaikan perjalanan?';
        }

        if (!confirm(confirmMsg)) return;

        try {
            // Contoh call API (Sesuaikan dengan controller driver Anda)
            await fetchApi(nextEndpoint, {
                method: 'POST',
                body: JSON.stringify({ booking_id: this.activeBooking.id })
            });

            // Refresh data
            await this.loadInitialData();
            Utils.showToast('Status berhasil diperbarui', 'success');

        } catch (e) {
            console.error(e);
            Utils.showToast('Gagal update status', 'error');
        }
    }

    // Helper untuk update status ke API (misal: start trip)
    async updateOrderStatus(newStatus) {
        try {
            // Tampilkan loading di tombol
            const btn = document.getElementById('btnMainAction'); // ID Tombol dinamis yg kita buat
            if (btn) btn.textContent = "Memproses...";

            await fetchApi(`/driver/bookings/${this.activeBooking.id}/start`, {
                method: 'POST'
            });

            // --- PERBAIKAN: RELOAD DATA ---
            await this.loadInitialData();

            Utils.showToast('Perjalanan dimulai!', 'success');

        } catch (error) {
            console.error(error);
            Utils.showToast('Gagal update status', 'error');
            this.renderActiveOrder(); // Reset tombol
        }
    }

    async handleChangePassword(e) {
        e.preventDefault();

        const current = this.inpCurrentPass.value;
        const newVal = this.inpNewPass.value;
        const confirmVal = this.inpConfirmPass.value;

        // Validasi Frontend Sederhana
        if (newVal !== confirmVal) {
            Utils.showToast('Konfirmasi password baru tidak cocok.', 'error');
            return;
        }

        if (newVal.length < 6) {
            Utils.showToast('Password minimal 6 karakter.', 'error');
            return;
        }

        // Payload
        const payload = {
            current_password: current,
            new_password: newVal,
            new_password_confirmation: confirmVal
        };

        // UI Loading state
        const btn = this.formChangePassword.querySelector('button');
        const originalText = btn.textContent;
        btn.textContent = 'Memproses...';
        btn.disabled = true;

        try {
            await fetchApi('/driver/change-password', {
                method: 'POST',
                body: JSON.stringify(payload)
            });

            Utils.showToast('Password berhasil diubah!', 'success');
            this.formChangePassword.reset(); // Reset form jika sukses

        } catch (error) {
            // Error sudah dihandle oleh fetchApi (toast), tapi kita biarkan form tidak ter-reset
            console.error(error);
        } finally {
            // Kembalikan tombol
            btn.textContent = originalText;
            btn.disabled = false;
        }
    }
    // Buka Modal dan Isi Nilai Awal
    openEditProfile() {
        const u = this.driverData;
        const p = u.driver_profile || {};

        this.inpEditName.value = u.name;
        this.inpEditUsername.value = u.username;
        this.inpEditPhone.value = u.phone_number || '';
        this.inpEditEmail.value = u.email || '';
        this.inpEditCar.value = p.car_model || '';
        this.inpEditPlate.value = p.plate_number || '';

        this.editProfileModal.classList.remove('hidden');
        this.editProfileModal.classList.add('flex');
    }

    // Kirim Data ke API
    async submitEditProfile(e) {
        e.preventDefault();

        // Siapkan Payload
        const payload = {
            name: this.inpEditName.value,
            username: this.inpEditUsername.value,
            phone_number: this.inpEditPhone.value,
            email: this.inpEditEmail.value,
            car_model: this.inpEditCar.value,
            plate_number: this.inpEditPlate.value
        };

        const btn = this.formEditProfile.querySelector('button[type="submit"]');
        const originalText = btn.textContent;
        btn.textContent = 'Menyimpan...';
        btn.disabled = true;

        try {
            const response = await fetchApi('/driver/profile/update', {
                method: 'POST',
                body: JSON.stringify(payload)
            });

            // Update data lokal dengan respon terbaru dari server
            this.driverData = response.data;

            // Render ulang tampilan
            this.renderProfile();

            // Tutup Modal
            this.editProfileModal.classList.add('hidden');
            this.editProfileModal.classList.remove('flex');

            Utils.showToast('Profil berhasil diperbarui!', 'success');

        } catch (error) {
            console.error(error);
            // Error sudah dihandle fetchApi (muncul toast), 
            // tapi tombol harus kita reset manual
        } finally {
            btn.textContent = originalText;
            btn.disabled = false;
        }
    }

}
