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
        this.renderProfile();
        window.addEventListener('hashchange', () => this.route());
        this.route();
        this.checkGlobalQris();
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
        this.views = ['new', 'history', 'profile'];
        this.pageTitle = document.getElementById('pageTitle');
        this.toSel = document.getElementById('toZone'); // hidden input penyimpan zona terpilih
        this.zoneGrid = document.getElementById('zoneGrid');
        this.zoneSearch = document.getElementById('zoneSearch');
        this.zoneSearchClear = document.getElementById('zoneSearchClear');
        this.zoneNoResult = document.getElementById('zoneNoResult');
        this.zoneCount = document.getElementById('zoneCount');
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
        this.inpPassengerPhone = document.getElementById('passengerPhone');

        this.inpStartDate = document.getElementById('filterStartDate');
        this.inpEndDate = document.getElementById('filterEndDate');
        this.btnFilterHistory = document.getElementById('btnFilterHistory');

        // --- ELEMEN PROFILE BARU ---
        this.profileInitial = document.getElementById('profileInitial');
        this.profileNameDisplay = document.getElementById('profileNameDisplay');

        this.formEditProfile = document.getElementById('formEditProfile');
        this.inpEditName = document.getElementById('editName');
        this.inpEditUsername = document.getElementById('editUsername');

        this.formChangePassword = document.getElementById('formChangePassword');
        this.inpCurrentPass = document.getElementById('currentPass');
        this.inpNewPass = document.getElementById('newPass');
        this.inpConfirmPass = document.getElementById('confirmPass');

        // Modal Konfirmasi Tunai (BARU)
        this.cashConfirmModal = document.getElementById('cashConfirmModal');
        this.btnCancelCash = document.getElementById('btnCancelCash');
        this.btnProceedCash = document.getElementById('btnProceedCash');
        this.confirmMethodName = document.getElementById('confirmMethodName');

        // QRIS Profile Elements
        this.inpUploadQris = document.getElementById('inpUploadQris');
        this.profileQrisPreview = document.getElementById('profileQrisPreview');
        this.profileQrisPlaceholder = document.getElementById('profileQrisPlaceholder');
        this.qrisLoading = document.getElementById('qrisLoading');

        // QRIS Payment Elements
        this.paymentQrisImage = document.getElementById('paymentQrisImage');
        this.paymentQrisError = document.getElementById('paymentQrisError');

        // QRIS Zoom Elements (BARU)
        this.qrisZoomModal = document.getElementById('qrisZoomModal');
        this.qrisZoomImage = document.getElementById('qrisZoomImage');



        // State

        this.pendingCashMethod = null;
        this.zones = [];
        this.selectedDriverId = null;
        this.selectedOrderData = null;
        this.selectedBookingIdForChange = null;

        // Modal Ganti Supir
        this.changeDriverModal = document.getElementById('changeDriverModal');
        this.changeDriverList = document.getElementById('changeDriverList');

        // Modal Pilih Supir (Driver Selection after Payment)
        this.selectDriverModal = document.getElementById('selectDriverModal');
        this.selectDriverList = document.getElementById('selectDriverList');
        this.btnCloseSelectDriver = document.getElementById('closeSelectDriver');

        // State Baru untuk Payment First
        this.paymentVerified = false;
        this.pendingPaymentPayload = null;
    }

    // --- HELPER BARU: Open Zoom Modal ---
    openZoomModal(imageUrl) {
        if (!imageUrl) return;

        this.qrisZoomImage.src = imageUrl;
        this.qrisZoomModal.classList.remove('hidden');
        this.qrisZoomModal.classList.add('flex');

        // Efek animasi kecil agar terlihat smooth
        setTimeout(() => {
            this.qrisZoomImage.classList.remove('scale-95');
            this.qrisZoomImage.classList.add('scale-100');
        }, 10);
    }



    bind() {
        // Event listener untuk tombol logout sudah ditangani oleh form di blade

        // Pencarian zona (kartu)
        this.zoneSearch?.addEventListener('input', (e) => {
            const val = e.target.value;
            this.zoneSearchClear?.classList.toggle('hidden', val.length === 0);
            this.filterZones(val);
        });
        this.zoneSearchClear?.addEventListener('click', () => {
            this.zoneSearch.value = '';
            this.zoneSearchClear.classList.add('hidden');
            this.filterZones('');
            this.zoneSearch.focus();
        });

        this.refreshHistoryBtn.addEventListener('click', () => {
            const icon = document.getElementById('refreshHistoryIcon');
            if (icon) {
                icon.classList.remove('spin-once');
                void icon.offsetWidth;
                icon.classList.add('spin-once');
            }
            this.renderHistory();
        });

        // Ganti Logic Tombol Confirm -> Jadi "Input Pembayaran"
        this.btnConfirm.addEventListener('click', () => this.processBooking()); // Nama fx tetap processBooking tapi loginya berubah

        // Event listener pembayaran

        this.proofInput.addEventListener('change', (e) => this.handleProofSelect(e));
        this.removeProofBtn.addEventListener('click', () => this.resetProof());
        this.btnClosePay.addEventListener('click', () => this.closePayment());
        this.modal.addEventListener('click', (e) => { if (e.target === this.modal) this.closePayment(); });

        // Listener Upload QRIS

        this.btnQRIS.addEventListener('click', () => {
            if (this.qrisBox.classList.contains('hidden')) {
                this.showQrisBox(); // <-- Kita buat fungsi helper baru ini
            } else {
                this.qrisBox.classList.add('hidden');
            }
        });
        this.btnConfirmQR.addEventListener('click', () => this.finishPayment('QRIS'));
        this.btnCashCSO.addEventListener('click', () => this.askCashConfirmation('CashCSO'));
        this.btnCashDriver.addEventListener('click', () => this.askCashConfirmation('CashDriver'));

        this.btnCancelCash?.addEventListener('click', () => {
            this.cashConfirmModal.classList.add('hidden');
            this.cashConfirmModal.classList.remove('flex');
            this.pendingCashMethod = null;
        });

        this.btnProceedCash?.addEventListener('click', () => {
            // Jika user klik Ya, baru jalankan finishPayment
            if (this.pendingCashMethod) {
                this.finishPayment(this.pendingCashMethod);
                // Tutup modal konfirmasi
                this.cashConfirmModal.classList.add('hidden');
                this.cashConfirmModal.classList.remove('flex');
            }
        });

        // 1. Saat gambar kecil diklik -> Buka Zoom
        this.paymentQrisImage?.addEventListener('click', () => {
            // Hanya izinkan zoom jika gambar bukan placeholder (tidak grayscale)
            // Atau cek jika src valid
            if (this.paymentQrisImage.src && !this.paymentQrisImage.classList.contains('grayscale')) {
                this.openZoomModal(this.paymentQrisImage.src);
            } else {
                Utils.showToast('QRIS belum tersedia untuk diperbesar.', 'error');
            }
        });

        // 2. Saat modal zoom diklik -> Tutup Zoom
        this.qrisZoomModal?.addEventListener('click', () => {
            this.qrisZoomImage.classList.remove('scale-100');
            this.qrisZoomImage.classList.add('scale-95');

            setTimeout(() => {
                this.qrisZoomModal.classList.add('hidden');
                this.qrisZoomModal.classList.remove('flex');
            }, 150); // Delay sedikit sesuai durasi transisi
        });

        // Event listener struk
        document.getElementById('closeReceipt')?.addEventListener('click', () => this.hideReceipt());
        document.getElementById('closeReceipt2')?.addEventListener('click', () => this.hideReceipt());
        // Event listener untuk tombol cetak & lihat struk akan di-bind setelah history dirender

        // --- LISTENER PROFILE BARU ---
        if (this.formEditProfile) {
            this.formEditProfile.addEventListener('submit', (e) => this.handleUpdateProfile(e));
        }
        if (this.formChangePassword) {
            this.formChangePassword.addEventListener('submit', (e) => this.handleChangePassword(e));
        }

        this.btnFilterHistory?.addEventListener('click', () => this.renderHistory());
        const today = new Date().toISOString().split('T')[0];
        if (this.inpStartDate) this.inpStartDate.value = today;
        if (this.inpEndDate) this.inpEndDate.value = today;

        // Listener Modal Ganti Supir
        document.getElementById('closeChangeDriver')?.addEventListener('click', () => {
            this.changeDriverModal.classList.add('hidden');
        });

        // Listener Modal Pilih Supir
        this.btnCloseSelectDriver?.addEventListener('click', () => {
            this.selectDriverModal.classList.add('hidden');
        });
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

        const titles = {
            new: 'Pemesanan Baru',
            history: 'Riwayat Transaksi'
            , profile: 'Profil Saya'
        };
        this.pageTitle.textContent = titles[hash] || 'CSO Panel';

        if (hash === 'profile') {
            this.renderProfile();
        }
    }

    // --- ANIMASI INDIKATOR LANGKAH (1 Tujuan -> 2 Bayar -> 3 Supir) ---
    setStep(active) {
        const doneCls = ['btn-grad', 'text-white', 'shadow-md'];
        const idleCls = ['bg-slate-200', 'dark:bg-slate-700', 'text-slate-500', 'dark:text-slate-400'];
        const activeLabel = ['text-indigo-600', 'dark:text-indigo-300'];

        for (let i = 1; i <= 3; i++) {
            const dot = document.getElementById('stepDot' + i);
            const label = document.getElementById('stepLabel' + i);
            if (!dot) continue;

            if (i <= active) {
                dot.classList.add(...doneCls);
                dot.classList.remove(...idleCls);
                label?.classList.add(...activeLabel);
                label?.classList.remove('text-slate-400');
            } else {
                dot.classList.remove(...doneCls);
                dot.classList.add(...idleCls);
                label?.classList.remove(...activeLabel);
                label?.classList.add('text-slate-400');
            }
        }

        // Garis penghubung ikut menyala
        const line1 = document.getElementById('stepLine1');
        const line2 = document.getElementById('stepLine2');
        if (line1) line1.className = 'step-line h-1 flex-1 rounded-full -mt-4 ' + (active >= 2 ? 'btn-grad' : 'bg-slate-200 dark:bg-slate-700');
        if (line2) line2.className = 'step-line h-1 flex-1 rounded-full -mt-4 ' + (active >= 3 ? 'btn-grad' : 'bg-slate-200 dark:bg-slate-700');
    }




    renderAll() {
        this.renderZones();
        this.renderDrivers();
        this.renderHistory();
    }

    // --- RENDER FUNCTIONS (NOW ASYNC) ---

    // Skeleton loader untuk grid zona
    zoneSkeleton(count = 4) {
        return Array.from({ length: count }).map(() => `
            <div class="rounded-2xl p-3.5 border-2 border-slate-100 dark:border-slate-700 space-y-2.5">
                <div class="skeleton w-9 h-9 rounded-xl"></div>
                <div class="skeleton h-3 w-3/4 rounded"></div>
                <div class="skeleton h-3 w-1/2 rounded"></div>
            </div>`).join('');
    }

    async renderZones() {
        if (!this.zoneGrid) return;
        this.zoneGrid.innerHTML = this.zoneSkeleton(4);

        try {
            const zones = await fetchApi('/cso/zones');
            this.zones = zones; // Simpan data zona

            if (!zones.length) {
                this.zoneGrid.innerHTML = `<div class="col-span-2 text-center py-6 text-sm font-semibold text-slate-400">Belum ada zona tujuan.</div>`;
                return;
            }

            this.renderZoneCards();
            this.zoneSearch?.removeAttribute('disabled');
        } catch (error) {
            this.zoneGrid.innerHTML = `<div class="col-span-2 text-center py-6 text-sm font-semibold text-rose-500">Gagal memuat tujuan.</div>`;
        }
    }

    // Bangun seluruh kartu zona sekali, lalu pasang listener
    renderZoneCards() {
        const pinSvg = `<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><circle cx="12" cy="11" r="2.5"/></svg>`;
        const checkSvg = `<svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>`;

        this.zoneGrid.innerHTML = this.zones.map((z, i) => {
            const name = z.name || 'Zona';
            const isSel = String(this.toSel.value) === String(z.id);
            const delay = Math.min(i * 0.04, 0.3);
            return `
            <button type="button"
                class="zone-card group relative text-left rounded-2xl p-3.5 border-2 border-slate-200 dark:border-slate-700 bg-white/70 dark:bg-slate-800/60 transition-all duration-300 hover:-translate-y-0.5 hover:border-indigo-400 hover:shadow-lg active:scale-[.98] ${isSel ? 'selected' : ''}"
                data-zone-id="${z.id}" data-zone-name="${name.toLowerCase()}"
                style="animation: fadeUp .45s cubic-bezier(.22,1,.36,1) both; animation-delay: ${delay}s;">
                <div class="flex items-start justify-between">
                    <div class="zone-icon w-9 h-9 rounded-xl bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-300 flex items-center justify-center">
                        ${pinSvg}
                    </div>
                    <span class="zone-check w-6 h-6 rounded-full btn-grad text-white flex items-center justify-center shadow-md">${checkSvg}</span>
                </div>
                <div class="font-bold text-slate-800 dark:text-slate-100 text-sm mt-2.5 leading-tight line-clamp-2">${name}</div>
                <div class="flex items-baseline gap-1 mt-1">
                    <span class="text-[10px] text-slate-400 font-medium">Tarif</span>
                    <span class="text-grad font-extrabold text-sm">${Utils.formatCurrency(z.price)}</span>
                </div>
            </button>`;
        }).join('');

        // Pasang listener pilih zona
        this.zoneGrid.querySelectorAll('.zone-card').forEach(card => {
            card.addEventListener('click', () => this.selectZone(card));
        });

        // Terapkan filter pencarian yang sedang aktif (jika ada)
        this.filterZones(this.zoneSearch?.value || '');
    }

    // Pilih satu zona
    selectZone(card) {
        this.zoneGrid.querySelectorAll('.zone-card.selected').forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');
        this.toSel.value = card.dataset.zoneId;
        this.updatePrice();
    }

    // Filter kartu zona berdasarkan teks pencarian
    filterZones(query) {
        if (!this.zoneGrid) return;
        const q = (query || '').trim().toLowerCase();
        let visible = 0;
        this.zoneGrid.querySelectorAll('.zone-card').forEach(card => {
            const match = (card.dataset.zoneName || '').includes(q);
            card.classList.toggle('hidden', !match);
            if (match) visible++;
        });
        this.zoneNoResult?.classList.toggle('hidden', visible !== 0);
        if (this.zoneCount) {
            this.zoneCount.textContent = q ? `${visible} dari ${this.zones.length} zona` : `${this.zones.length} zona`;
        }
    }

    // Reset pilihan & pencarian zona (dipanggil setelah order selesai)
    clearZoneSelection() {
        this.zoneGrid?.querySelectorAll('.zone-card.selected').forEach(c => c.classList.remove('selected'));
        if (this.zoneSearch) this.zoneSearch.value = '';
        this.zoneSearchClear?.classList.add('hidden');
        this.filterZones('');
    }

    // Skeleton loader untuk daftar supir
    driverSkeleton(count = 3) {
        return Array.from({ length: count }).map(() => `
            <div class="glass rounded-2xl p-3 flex items-center gap-3 border border-white/40 dark:border-slate-700">
                <div class="skeleton w-11 h-11 rounded-xl flex-shrink-0"></div>
                <div class="flex-1 space-y-2">
                    <div class="skeleton h-3.5 w-2/3 rounded"></div>
                    <div class="skeleton h-2.5 w-1/2 rounded"></div>
                </div>
                <div class="skeleton h-5 w-14 rounded-full"></div>
            </div>`).join('');
    }

    async renderDrivers() {
        this.driversList.innerHTML = this.driverSkeleton(3);

        try {
            const drivers = await fetchApi('/cso/available-drivers');

            if (drivers.length === 0) {
                this.driversList.innerHTML = `
                <div class="glass rounded-2xl p-8 text-center">
                    <div class="w-14 h-14 mx-auto rounded-full bg-slate-100 dark:bg-slate-700 flex items-center justify-center mb-3">
                        <svg class="w-7 h-7 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z"/></svg>
                    </div>
                    <p class="text-slate-500 dark:text-slate-400 font-semibold text-sm">Tidak ada supir standby.</p>
                </div>`;
                return;
            }

            let hasRejoinHeaderRendered = false;

            this.driversList.innerHTML = drivers.map((d, index) => {
                const profile = d.driver_profile || {};
                const queueNumber = d.queue_score < 1000 ? (d.queue_score + 1) : '-';
                const queueScore = d.queue_score || 0;
                const isReady = profile.status === 'standby' || profile.status === 'available';

                let badgeHtml = '';
                let wrapperClass = '';
                let queueBadgeClass = '';

                if (isReady) {
                    // STATUS READY
                    wrapperClass = 'glass border-white/50 dark:border-slate-700 card-hover';
                    queueBadgeClass = 'btn-grad text-white';
                    badgeHtml = `<span class="inline-flex items-center gap-1 bg-emerald-100 dark:bg-emerald-500/20 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-500/30 text-[10px] px-2 py-1 rounded-full font-bold">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 pulse-dot"></span>Ready
                    </span>`;
                } else {
                    // STATUS OFFLINE
                    wrapperClass = 'bg-slate-50/70 dark:bg-slate-900/40 border-slate-100 dark:border-slate-800 opacity-60';
                    queueBadgeClass = 'bg-slate-300 dark:bg-slate-700 text-slate-600 dark:text-slate-300';
                    badgeHtml = `<span class="bg-slate-200 dark:bg-slate-700 text-slate-500 dark:text-slate-400 border border-slate-300 dark:border-slate-600 text-[10px] px-2 py-1 rounded-full font-bold">${profile.status || 'Offline'}</span>`;
                }

                const lineNumber = profile.line_number
                    ? `<span class="font-mono text-[10px] font-bold text-indigo-600 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-500/20 px-1.5 py-0.5 rounded mr-2">#L${profile.line_number}</span>`
                    : '';

                // --- LOGIKA SEPARATOR REJOIN ---
                let separatorHtml = '';
                if (queueScore >= 1000 && !hasRejoinHeaderRendered) {
                    hasRejoinHeaderRendered = true;
                    separatorHtml = `
                    <div class="relative py-3">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-slate-300 dark:border-slate-600 border-dashed"></div>
                        </div>
                        <div class="relative flex justify-center">
                            <span class="glass px-3 py-1 text-[10px] font-bold text-amber-600 dark:text-amber-300 uppercase tracking-widest border border-amber-200 dark:border-amber-500/30 rounded-full">
                                ↺ Antrian Rejoin
                            </span>
                        </div>
                    </div>`;
                }

                return `
            ${separatorHtml}
            <div class="driver-card relative w-full border rounded-2xl p-3.5 text-left transition-all duration-300 ${wrapperClass}">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3 overflow-hidden">
                        <div class="flex-shrink-0 w-11 h-11 rounded-xl ${queueBadgeClass} flex items-center justify-center text-lg font-extrabold shadow-md">
                            ${queueNumber}
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center">
                                ${lineNumber}
                                <div class="font-bold text-slate-800 dark:text-slate-100 text-sm truncate">${d.name}</div>
                            </div>
                            <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 flex items-center gap-2 truncate">
                                <svg class="w-3.5 h-3.5 text-slate-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13l2-5a2 2 0 012-1.5h10A2 2 0 0119 8l2 5M5 16h.01M19 16h.01M4 13h16v3a1 1 0 01-1 1H5a1 1 0 01-1-1v-3z"/></svg>
                                <span>${profile.car_model || '-'}</span>
                                <span class="w-1 h-1 rounded-full bg-slate-300"></span>
                                <span class="font-mono">${profile.plate_number || '-'}</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-col items-end gap-2 pl-2">
                        ${badgeHtml}
                    </div>
                </div>
            </div>`;
            }).join('');

        } catch (error) {
            console.error("Error render drivers:", error);
            this.driversList.innerHTML = `<div class="glass rounded-2xl p-5 text-center text-rose-600 font-semibold">Gagal memuat antrian.</div>`;
        }
    }

    // --- NEW: RENDER DRIVERS FOR SELECTION MODAL ---
    async renderDriversForSelection() {
        this.selectDriverList.innerHTML = this.driverSkeleton(3);

        try {
            const drivers = await fetchApi('/cso/available-drivers');

            if (drivers.length === 0) {
                this.selectDriverList.innerHTML = `<div class="glass rounded-2xl p-6 text-center text-slate-500 font-semibold">Tidak ada supir standby saat ini.</div>`;
                return;
            }

            let hasRejoinHeaderRendered = false;

            this.selectDriverList.innerHTML = drivers.map((d, index) => {
                const profile = d.driver_profile || {};

                // Filter hanya yang Available/Standby untuk dipilih
                const rawStatus = (profile.status || '').toLowerCase();
                const isStandby = (rawStatus === 'standby' || rawStatus === 'available');

                if (!isStandby) return ''; // Skip offline drivers in selection modal

                const queueNumber = d.queue_score < 1000 ? (d.queue_score + 1) : '-';
                const queueScore = d.queue_score || 0;

                const lineNumber = profile.line_number
                    ? `<span class="font-mono text-xs font-bold text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded mr-2">#L${profile.line_number}</span>`
                    : '';

                // --- LOGIKA SEPARATOR REJOIN ---
                let separatorHtml = '';
                if (queueScore >= 1000 && !hasRejoinHeaderRendered) {
                    hasRejoinHeaderRendered = true;
                    separatorHtml = `
                     <div class="relative py-3">
                         <div class="absolute inset-0 flex items-center">
                             <div class="w-full border-t border-slate-300 dark:border-slate-600 border-dashed"></div>
                         </div>
                         <div class="relative flex justify-center">
                             <span class="bg-white dark:bg-slate-900 px-3 py-1 text-[10px] font-bold text-amber-600 dark:text-amber-300 uppercase tracking-widest border border-amber-200 dark:border-amber-500/30 rounded-full">
                                 ↺ Antrian Rejoin
                             </span>
                         </div>
                     </div>`;
                }

                return `
                ${separatorHtml}
                <div class="group relative w-full border border-emerald-100 dark:border-emerald-900/50 bg-white dark:bg-slate-800 rounded-2xl p-3.5 shadow-sm hover:shadow-lg hover:border-emerald-400 hover:-translate-y-0.5 transition-all duration-300">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3 overflow-hidden">
                            <div class="flex-shrink-0 w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-500 text-white flex items-center justify-center text-lg font-extrabold shadow-md">
                                ${queueNumber}
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center">
                                    ${lineNumber}
                                    <div class="font-bold text-slate-800 dark:text-slate-100 text-sm truncate">${d.name}</div>
                                </div>
                                <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 flex items-center gap-2">
                                    <span>${profile.car_model || '-'}</span>
                                    <span class="w-1 h-1 rounded-full bg-slate-300"></span>
                                    <span class="font-mono">${profile.plate_number || '-'}</span>
                                </div>
                            </div>
                        </div>
                        <button class="btn-select-final bg-gradient-to-br from-emerald-500 to-teal-500 text-white px-4 py-2.5 rounded-xl text-xs font-bold shadow-md hover:shadow-lg active:scale-95 transition-all flex items-center gap-1" data-driver-id="${d.id}">
                            PILIH
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                        </button>
                    </div>
                </div>`;
            }).join('');

            // Bind Click Events
            this.selectDriverList.querySelectorAll('.btn-select-final').forEach(btn => {
                btn.addEventListener('click', () => {
                    const driverId = btn.dataset.driverId;
                    this.finalizeOrder(driverId);
                });
            });

        } catch (error) {
            console.error("Error render selection drivers:", error);
            this.selectDriverList.innerHTML = `<div class="text-center p-4 text-red-600">Gagal memuat antrian.</div>`;
        }
    }

    openSelectDriverModal() {
        this.selectDriverModal.classList.remove('hidden');
        this.selectDriverModal.classList.add('flex');
        this.renderDriversForSelection();

        // Langkah 3: Pilih Supir
        this.setStep(3);
    }

    async renderHistory() {
        this.historyList.innerHTML = Array.from({ length: 3 }).map(() => `
            <div class="glass rounded-2xl p-4 space-y-3 border border-white/40 dark:border-slate-700">
                <div class="flex justify-between">
                    <div class="space-y-2"><div class="skeleton h-3.5 w-32 rounded"></div><div class="skeleton h-2.5 w-20 rounded"></div></div>
                    <div class="skeleton h-6 w-20 rounded-full"></div>
                </div>
                <div class="skeleton h-12 w-full rounded-xl"></div>
            </div>`).join('');

        try {
            // 1. Ambil Nilai Filter
            const start = this.inpStartDate?.value || '';
            const end = this.inpEndDate?.value || '';

            // 2. Buat URL dengan Query Params
            let url = '/cso/history';
            const params = new URLSearchParams();

            if (start && end) {
                params.append('start_date', start);
                params.append('end_date', end);
            }

            // Jika ada params, tambahkan ke URL (contoh: /cso/history?start_date=2024-01-01&end_date=...)
            if (params.toString()) {
                url += `? ${params.toString()}`;
            }

            // 3. Panggil API
            const transactions = await fetchApi(url);

            if (transactions.length === 0) {
                this.historyList.innerHTML = `
            <div class="text-center py-10">
                        <div class="bg-slate-50 dark:bg-slate-800/50 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-3">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>
                        </div>
                        <p class="text-slate-500 dark:text-slate-400">Tidak ada transaksi ditemukan.</p>
                        ${(start && end) ? '<p class="text-xs text-slate-400 mt-1">Coba ubah filter tanggal.</p>' : ''}
                    </div>`;
                return;
            }

            this.historyList.innerHTML = transactions.map(tx => {

                const booking = tx.booking || {};
                const driver = booking.driver || {};
                const zoneTo = booking.zone_to || {};
                const profile = driver.driver_profile || {};

                const passengerPhone = booking.passenger_phone || '-';
                const rawStatus = (booking.status || 'Assigned');

                // --- FORMAT RUPIAH ---
                // Mengubah angka (misal: 150000) menjadi "Rp 150.000"
                const formattedPrice = Number(tx.amount).toLocaleString('id-ID', {
                    style: 'currency',
                    currency: 'IDR',
                    minimumFractionDigits: 0
                });

                // --- LOGIKA STATUS PERJALANAN (BARU) ---
                let statusBadge = '';
                let statusText = '';
                let statusIcon = '';
                let accentBar = '';

                switch (rawStatus) {
                    case 'Assigned':
                        statusText = 'Sedang Menjemput';
                        statusBadge = 'bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-500/20 dark:text-amber-300 dark:border-amber-500/30';
                        accentBar = 'bg-amber-400';
                        statusIcon = `<svg class="w-3 h-3 animate-bounce" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`;
                        break;
                    case 'OnTrip': // Perlu update di sisi Driver App nanti
                        statusText = 'Dalam Perjalanan';
                        statusBadge = 'bg-blue-100 text-blue-700 border-blue-200 dark:bg-blue-500/20 dark:text-blue-300 dark:border-blue-500/30';
                        accentBar = 'bg-blue-500';
                        statusIcon = `<svg class="w-3 h-3 animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>`;
                        break;
                    case 'Completed': // Perlu update di sisi Driver App nanti
                        statusText = 'Selesai';
                        statusBadge = 'bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-500/20 dark:text-emerald-300 dark:border-emerald-500/30';
                        accentBar = 'bg-emerald-500';
                        statusIcon = `<svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>`;
                        break;
                    case 'Cancelled':
                        statusText = 'Dibatalkan';
                        statusBadge = 'bg-rose-100 text-rose-700 border-rose-200 dark:bg-rose-500/20 dark:text-rose-300 dark:border-rose-500/30';
                        accentBar = 'bg-rose-500';
                        break;
                    default:
                        // Fallback untuk data lama (Paid/CashDriver) dianggap Selesai/Arsip
                        statusText = rawStatus;
                        statusBadge = 'bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-700 dark:text-slate-300 dark:border-slate-600';
                        accentBar = 'bg-slate-400';
                }

                // Line Number Supir
                const lineDisplay = profile.line_number ? `<span class="text-[10px] font-bold bg-indigo-50 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-300 px-1.5 py-0.5 rounded ml-1">#L${profile.line_number}</span>` : '';

                // Tombol Lihat Bukti (Hanya jika QRIS & Ada Bukti)

                let btnProof = '';
                if (tx.method === 'QRIS' && tx.payment_proof) {
                    const proofUrl = `/storage/${tx.payment_proof}`;
                    btnProof = `
            <button class="btn-view-proof flex items-center gap-1 text-xs font-bold text-blue-600 dark:text-blue-400 hover:text-blue-700 transition-colors" data-proof-url="${proofUrl}">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                Bukti
            </button>`;
                }

                return `
            <div class="glass card-hover rounded-2xl shadow-sm border border-white/50 dark:border-slate-700 p-4 overflow-hidden relative">
                    <div class="absolute left-0 top-0 bottom-0 w-1 ${accentBar}"></div>

                    <div class="flex justify-between items-start mb-3">
                        <div>
                            <div class="font-bold text-slate-800 dark:text-slate-100 text-sm flex items-center gap-1.5">
                                <span class="text-slate-400">Bandara</span>
                                <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                                <span>${zoneTo.name || 'Unknown'}</span>
                            </div>
                            <div class="text-xs text-slate-500 mt-0.5 font-medium">
                                ${new Date(tx.created_at).toLocaleString('id-ID', { hour: '2-digit', minute: '2-digit' })} • #${tx.id}
                            </div>
                        </div>
                        <div class="flex items-center gap-1 px-2.5 py-1 rounded-full border ${statusBadge}">
                            ${statusIcon}
                            <span class="text-[10px] font-bold uppercase tracking-wide">${statusText}</span>
                        </div>
                    </div>

                    <div class="bg-slate-50/80 dark:bg-slate-700/40 rounded-xl p-3 space-y-2 text-xs">
                        <div class="flex justify-between items-center border-b border-slate-200/70 dark:border-slate-600/70 pb-2 mb-2">
                            <div class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                                <div class="w-6 h-6 rounded-lg bg-indigo-100 dark:bg-indigo-500/20 text-indigo-500 dark:text-indigo-300 flex items-center justify-center">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v3h-3zM4.75 12.094A5.973 5.973 0 004 15v3H1v-3a3 3 0 013.75-2.906z" /></svg>
                                </div>
                                <span class="font-bold">${driver.name || '-'}</span>
                                ${lineDisplay}
                            </div>
                            <div class="text-grad font-extrabold text-sm">
                                ${formattedPrice}
                            </div>
                        </div>

                        <div class="flex items-center gap-2 text-slate-600 dark:text-slate-300">
                            <div class="w-6 h-6 rounded-lg bg-green-100 dark:bg-green-500/20 text-green-600 dark:text-green-300 flex items-center justify-center">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor"><path d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 5V3z" /></svg>
                            </div>
                            <span class="font-mono font-semibold">${passengerPhone}</span>
                        </div>
                    </div>

                    <div class="flex justify-between items-center pt-3 mt-1 border-t border-slate-100 dark:border-slate-700">
                        <span class="text-[10px] px-2.5 py-1 rounded-full border bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-700 dark:text-slate-300 dark:border-slate-600 font-bold">
                            ${tx.method === 'CashDriver' ? '💵 Tunai (Supir)' : (tx.method === 'CashCSO' ? '💵 Tunai (Kasir)' : '🏦 ' + tx.method)}
                        </span>
                        <div class="flex items-center gap-2">
                            ${this.renderChangeDriverButton(tx.booking, rawStatus)}
                            ${btnProof}
                            <button class="btn-view-receipt flex items-center gap-1 text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 transition-colors" data-tx-object='${JSON.stringify(tx)}'>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 011.414.586l4 4a1 1 0 01.586 1.414V19a2 2 0 01-2 2z" /></svg>
                                Struk
                            </button>
                        </div>
                    </div>
                </div>`;
            }).join('');

            // Re-bind listener tombol struk
            this.historyList.querySelectorAll('.btn-view-receipt').forEach(btn => {
                btn.addEventListener('click', () => {
                    // Parse ulang data object dari string JSON
                    const txData = JSON.parse(btn.dataset.txObject);
                    this.showReceipt(txData);
                });
            });

            // Re-bind listener tombol Ganti Supir
            this.historyList.querySelectorAll('.btn-change-driver').forEach(btn => {
                btn.addEventListener('click', () => {
                    this.openChangeDriverModal(btn.dataset.bookingId);
                });
            });

            // Re-bind listener tombol bukti
            this.historyList.querySelectorAll('.btn-view-proof').forEach(btn => {
                btn.addEventListener('click', () => {
                    const url = btn.dataset.proofUrl;
                    this.openZoomModal(url);
                });
            });

        } catch (error) {
            console.error(error);
            this.historyList.innerHTML = `<div class="glass rounded-2xl p-6 text-center text-rose-500 font-semibold">Gagal memuat riwayat.</div>`;
        }
    }

    // --- LOGIC FUNCTIONS (ACTIONS) ---

    updatePrice() {
        const zoneId = this.toSel.value;
        const zone = this.zones.find(z => z.id == zoneId);
        this.priceBox.textContent = zone ? Utils.formatCurrency(zone.price) : '-';

        // Animasi pop pada angka tarif
        this.priceBox.classList.remove('price-pop');
        void this.priceBox.offsetWidth; // reflow agar animasi bisa diulang
        this.priceBox.classList.add('price-pop');

        // RESET PAYMENT jika ganti tujuan
        this.paymentVerified = false;
        this.pendingPaymentPayload = null;
        // Tidak perlu re-render drivers karena Dashboard hanya Read-Only sekarang
        // this.renderDrivers();

        // Update indikator langkah
        this.setStep(zoneId ? 1 : 1);

        this.updateConfirmButtonState();
    }
    updateConfirmButtonState() {
        const zoneId = this.toSel.value;
        // Tombol hanya butuh Zone ID, tidak butuh Driver ID lagi
        this.btnConfirm.disabled = !zoneId;

        if (this.paymentVerified) {
            this.btnConfirm.innerHTML = `
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                <span>Pembayaran Selesai</span>`;
            this.btnConfirm.classList.add('is-done');
            this.btnConfirm.disabled = true; // Disable jika sudah bayar
        } else {
            this.btnConfirm.innerHTML = `
                <span>Input Pembayaran</span>
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>`;
            this.btnConfirm.classList.remove('is-done');
        }
    }

    async processBooking() {
        console.log('--- processBooking (Payment Phase) Triggered ---');
        console.log('Current Zone Value:', this.toSel.value);

        // Cek Logika: HANYA BUTUH ZONE
        if (!this.toSel.value) {
            alert('Tujuan belum dipilih');
            return;
        }

        console.log('Validation Passed. Proceeding to openPayment.');

        // 1. Simpan data pilihan ke memori sementara (Belum ke DB)
        const zoneId = this.toSel.value;
        const zoneObj = this.zones.find(z => z.id == zoneId);

        if (!zoneObj) {
            alert('Data zona tidak valid. Silakan refresh.');
            return;
        }

        this.selectedOrderData = {
            // driver_id: this.selectedDriverId, // <-- SKIP DRIVER
            zone_id: zoneId,
            price: zoneObj.price,
            zone_name: zoneObj.name
        };

        this.openPayment();
    }

    openPayment() {
        if (!this.selectedOrderData) return;

        // Reset UI Modal
        this.qrisBox.classList.add('hidden');
        this.resetProof();
        this.inpPassengerPhone.value = ''; // Reset Phone Input

        // Tampilkan Info di Modal
        this.payInfo.innerHTML = `
            <div class="space-y-1">
            <div class="flex justify-between"><span>Rute:</span> <span class="font-semibold text-right">Bandara → ${this.selectedOrderData.zone_name}</span></div>
            <div class="flex justify-between"><span>Tarif:</span> <span class="font-semibold">${Utils.formatCurrency(this.selectedOrderData.price)}</span></div>
        </div>`;

        this.modal.classList.add('flex');
        this.modal.classList.remove('hidden');

        // Langkah 2: Bayar
        this.setStep(2);
    }

    closePayment() {
        this.modal.classList.add('hidden');
        this.modal.classList.remove('flex');
        // Tidak perlu cancelBooking ke API karena data belum masuk DB
        this.selectedOrderData = null;
        this.resetProof();

        // Kembalikan indikator langkah jika pembayaran belum terverifikasi
        if (!this.paymentVerified) this.setStep(1);
    }

    askCashConfirmation(method) {
        // 1. Validasi Nomor HP dulu (Supaya tidak muncul modal kalau nomor HP kosong)
        let phone = this.inpPassengerPhone.value.trim();
        if (!phone) {
            Utils.showToast('Nomor WhatsApp Penumpang WAJIB diisi!', 'error');
            this.inpPassengerPhone.focus();
            return;
        }

        // 2. Simpan metode yang dipilih ke variabel sementara
        this.pendingCashMethod = method;

        // 3. Update Teks di Modal agar informatif
        const label = method === 'CashCSO' ? 'TUNAI KE CSO' : 'TUNAI KE SUPIR';
        this.confirmMethodName.textContent = label;

        // 4. Buka Modal
        this.cashConfirmModal.classList.remove('hidden');
        this.cashConfirmModal.classList.add('flex');
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

        // 1. Ambil & Validasi Nomor HP
        let phone = this.inpPassengerPhone.value.trim();

        if (!phone) {
            Utils.showToast('Nomor WhatsApp Penumpang WAJIB diisi!', 'error');
            this.inpPassengerPhone.focus();
            return;
        }

        phone = phone.replace(/\D/g, '');
        if (phone.length < 10) {
            Utils.showToast('Nomor HP tidak valid.', 'error');
            return;
        }

        // --- NEW FLOW: JANGAN KIRIM KE API DULU ---
        // Simpan data pembayaran ke state

        try {
            // Validasi Khusus QRIS
            let proofFile = null;
            if (method === 'QRIS') {
                const file = this.proofInput.files[0];
                if (!file) throw new Error("Wajib foto bukti transfer QRIS.");
                proofFile = file;
            }

            // Simpan State
            this.pendingPaymentPayload = {
                zone_id: this.selectedOrderData.zone_id,
                method: method,
                passenger_phone: phone,
                payment_proof: proofFile
            };

            this.paymentVerified = true;
            this.closePayment();

            Utils.showToast('Pembayaran OK. Silakan pilih supir.', 'success');

            // BUKA MODAL PILIH SUPIR
            this.openSelectDriverModal();

            this.updateConfirmButtonState(); // Disable tombol pembayaran

        } catch (error) {
            console.error(error);
            Utils.showToast(error.message, 'error');
        }
    }

    // --- FINALISASI ORDER (DIPANGGIL SAAT PILIH SUPIR) ---
    async finalizeOrder(driverId) {
        if (!this.paymentVerified || !this.pendingPaymentPayload) {
            Utils.showToast('Selesaikan pembayaran terlebih dahulu!', 'error');
            return;
        }

        if (!confirm('Assign order ke supir ini?')) return;

        const payload = this.pendingPaymentPayload;

        try {
            const formData = new FormData();
            formData.append('driver_id', driverId);
            formData.append('zone_id', payload.zone_id);
            formData.append('method', payload.method);
            formData.append('passenger_phone', payload.passenger_phone);

            if (payload.payment_proof) {
                formData.append('payment_proof', payload.payment_proof);
            }

            const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            // Show Loading Indicator (Global or Toast)
            Utils.showToast('Memproses Order...', 'info');

            const response = await fetch('/api/cso/process-order', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                body: formData
            });

            if (!response.ok) {
                const errData = await response.json();
                throw new Error(errData.message || 'Gagal memproses order');
            }

            const result = await response.json();

            Utils.showToast('Order berhasil dibuat!', 'success');

            // Tutup Modal Select Driver
            this.selectDriverModal.classList.add('hidden');

            // Reset
            this.paymentVerified = false;
            this.pendingPaymentPayload = null;
            this.selectedDriverId = null;
            this.toSel.value = ''; // Reset pilihan zona
            this.clearZoneSelection(); // Bersihkan highlight kartu & pencarian
            this.priceBox.textContent = '-';
            this.inpPassengerPhone.value = '';
            this.setStep(1); // Kembali ke langkah awal

            // Render Ulang
            this.renderHistory();
            this.renderDrivers(); // Refresh antrian di dashboard

            // Tampilkan Struk
            // Pastikan result.data berisi objek yang sesuai untuk struk
            if (result.data) {
                this.lastReceiptData = result.data;
                this.showReceipt(this.lastReceiptData);
            }

        } catch (error) {
            console.error(error);
            Utils.showToast(error.message, 'error');
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
            // --- KHUSUS ANDROID (RawBT) ---

            // Masalah Utama: RawBT tidak bisa baca src="/pos-assets/..."
            // Solusi: Kita HAPUS tag <img> untuk versi Bluetooth agar tidak error/blank
            // Atau ganti dengan Text Header Saja

            // Kita buat HTML khusus RawBT yang lebih sederhana (Tanpa CSS eksternal/Gambar URL)
            let rawBtHTML = receiptHTML;

            // A. Hapus tag <img> (Penyebab utama Blank Page)
            // Regex ini akan menghapus semua tag <img ... >
            rawBtHTML = rawBtHTML.replace(/<img[^>]*>/g, '');

            // B. Tambahkan Judul Teks Pengganti Logo (Opsional, agar tidak kosong melompong di atas)
            // Kita sisipkan teks sebelum "KOPERASI ANGKASA JAYA" jika logo dihapus
            // (Sebenarnya di generateReceiptHTML text KOPERASI sudah ada, jadi aman dihapus saja img-nya)

            // C. Encode ke Base64
            const base64Data = btoa(unescape(encodeURIComponent(rawBtHTML)));

            // D. Gunakan Skema 'intent:' (Lebih stabil daripada 'rawbt:')
            // Format: intent:base64,{DATA}#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;end;
            const intentUrl = `intent: base64, ${base64Data} #Intent; scheme = rawbt; package = ru.a402d.rawbtprinter; S.browser_fallback_url = ${window.location.href}; end; `;

            window.location.href = intentUrl;

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
                const message = `Halo, berikut adalah struk pembayaran taksi Anda(Kode: #${code}).Silakan unduh file PDF di atas.Terima kasih.`;
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
        // --- 1. LOGIKA DATA ---
        const isBooking = tx.zone_id !== undefined;
        const booking = isBooking ? tx : tx.booking;

        // Ambil Data Driver & Profile
        const driverObj = isBooking ? tx.driver : booking?.driver;
        const profile = driverObj?.driver_profile || {};

        // Logika Line Number
        const lineNo = profile.line_number ? `#L${profile.line_number}` : '';
        const driverDisplayName = driverObj ? `${lineNo}` : 'N/A';

        // Logika Tanggal & Waktu
        const dateTime = new Date(tx.created_at || Date.now());
        const tanggal = dateTime.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
        const waktu = dateTime.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });

        const zoneName = isBooking
            ? this.zones.find(z => z.id == tx.zone_id)?.name
            : tx.booking?.zone_to?.name;

        const price = tx.price || tx.amount;
        const amountStr = new Intl.NumberFormat('id-ID').format(price);
        // ID Transaksi untuk Link
        const txId = tx.id || booking?.transaction?.id || booking?.id;
        const code = String(txId).toUpperCase();

        const csoObj = tx.cso || booking?.cso;
        const kasirName = csoObj?.name?.split(' ')[0] || 'Admin';
        const methodDisplay = tx.method === 'CashDriver' ? 'TUNAI (SUPIR)' : (tx.method === 'CashCSO' ? 'TUNAI (KASIR)' : 'QRIS');

        // --- 2. LOGIKA QR CODE (BARU) ---
        // Membuat URL Lengkap ke halaman struk publik
        const publicUrl = `${window.location.origin}/receipt/${txId}`;

        // Generate QR Code menggunakan API (Gratis & Cepat)
        // size=100x100 cukup untuk struk thermal
        const qrCodeImgUrl = `https://api.qrserver.com/v1/create-qr-code/?size=100x100&margin=0&data=${encodeURIComponent(publicUrl)}`;

        // --- 3. STYLE CSS ---
        const style = `
            font-family: 'Courier New', Courier, monospace;
            font-size: 9px;
            width: 90%;
            max-width: 58mm; 
            margin: 0 auto;
            color: #000;
            font-weight: bold;
            line-height: 1.2;
            padding: 5px 0;
        `;

        const center = `text-align: center;`;
        const bold = `font-weight: bold;`;
        const flexBetween = `display: flex; justify-content: space-between;`;
        const dashedLine = `border-bottom: 1px dashed #000; margin: 5px 0;`;
        const mb1 = `margin-bottom: 4px;`;

        const logoUrl = '/pos-assets/img/logo-apt.svg';

        // Logo Header
        const logoHtml = `
            <div style="${center} margin-bottom: 8px;">
                <img src="${logoUrl}" alt="Logo" style="width: 80px; height: auto; display: block; margin: 0 auto;">
                <div style="${bold} font-size: 12px; margin-top: 6px;">KOPERASI ANGKASA JAYA</div>
                 <div style="font-size: 8px;">Bandara Udara APT. Pranoto Samarinda</div>
            </div>
        `;

        // --- 4. HTML STRUK ---
        return `
        <div style="${style}">
            
            ${logoHtml}

            <div style="${flexBetween} ${mb1}">
                <div>
                    <div style="font-size: 8px; color: #333;">Waktu</div>
                    <div>${tanggal} ${waktu}</div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 8px; color: #333;">Kasir</div>
                    <div>${kasirName}</div>
                </div>
            </div>

            <div style="${flexBetween} ${mb1}">
                <div>Supir:</div>
                <div>${driverDisplayName}</div>
            </div>

            <div style="${mb1}">No. ${code}</div>

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
            
            <div style="${flexBetween} ${bold} ${mb1} font-size: 10px;">
                <div>Grand Total</div>
                <div>Rp ${amountStr}</div>
            </div>

            <div style="${flexBetween} ${mb1}">
                <div>Bayar (${methodDisplay})</div>
                <div>Rp ${amountStr}</div>
            </div>

            <div style="${dashedLine}"></div>
            
            <div style="${center} margin-top: 8px; margin-bottom: 8px;">
                <img src="${qrCodeImgUrl}" style="width: 80px; height: 80px; display: block; margin: 0 auto;">
                <div style="font-size: 8px; margin-top: 2px;">Scan untuk cek struk online</div>
            </div>
            <div style="${center} font-size: 8px; color: #333;">
                <div style="margin-top: 5px;">Powered by Koperasi Angkasa Jaya</div>
                <div>Simpan struk ini sebagai bukti.</div>
            </div>

        </div>`;
    }

    async renderProfile() {
        try {
            const user = await fetchApi('/cso/profile');

            // Update Display
            this.profileNameDisplay.textContent = user.name;
            this.profileInitial.textContent = user.name.charAt(0).toUpperCase();

            // Isi Form Edit
            this.inpEditName.value = user.name;
            this.inpEditUsername.value = user.username;

        } catch (error) {
            console.error('Gagal memuat profil:', error);
            Utils.showToast('Gagal memuat data profil', 'error');
        }
    }

    async handleUpdateProfile(e) {
        e.preventDefault();

        const payload = {
            name: this.inpEditName.value,
            username: this.inpEditUsername.value
        };

        const btn = this.formEditProfile.querySelector('button');
        const originalText = btn.textContent;
        btn.textContent = 'Menyimpan...';
        btn.disabled = true;

        try {
            await fetchApi('/cso/profile/update', {
                method: 'POST',
                body: JSON.stringify(payload)
            });

            Utils.showToast('Profil berhasil diperbarui', 'success');
            this.renderProfile(); // Refresh tampilan

        } catch (error) {
            Utils.showToast(error.message, 'error');
        } finally {
            btn.textContent = originalText;
            btn.disabled = false;
        }
    }

    async handleChangePassword(e) {
        e.preventDefault();

        const current = this.inpCurrentPass.value;
        const newVal = this.inpNewPass.value;
        const confirmVal = this.inpConfirmPass.value;

        if (newVal !== confirmVal) {
            Utils.showToast('Konfirmasi password baru tidak cocok.', 'error');
            return;
        }

        const payload = {
            current_password: current,
            new_password: newVal,
            new_password_confirmation: confirmVal
        };

        const btn = this.formChangePassword.querySelector('button');
        const originalText = btn.textContent;
        btn.textContent = 'Memproses...';
        btn.disabled = true;

        try {
            await fetchApi('/cso/profile/password', {
                method: 'POST',
                body: JSON.stringify(payload)
            });

            Utils.showToast('Password berhasil diubah!', 'success');
            this.formChangePassword.reset();

        } catch (error) {
            Utils.showToast(error.message, 'error');
        } finally {
            btn.textContent = originalText;
            btn.disabled = false;
        }
    }

    showQrisBox() {
        this.qrisBox.classList.remove('hidden');
        this.qrisBox.scrollIntoView({
            behavior: 'smooth', block: 'center'
        });
    }

    // --- HELPER UNTUK GANTI SUPIR (CHANGE DRIVER) ---

    renderChangeDriverButton(booking, status) {
        // Hanya tampil jika status Assigned
        if (status !== 'Assigned') return '';

        // Hitung selisih waktu
        const created = new Date(booking.created_at);
        const now = new Date();
        const diffMs = now - created;
        const diffMins = Math.floor(diffMs / 60000);
        const TIMEOUT_MINS = 10;

        // Button State
        let disabled = diffMins < TIMEOUT_MINS;
        let btnClass = disabled
            ? 'bg-slate-100 text-slate-400 cursor-not-allowed border-slate-200'
            : 'bg-red-50 text-red-600 hover:bg-red-600 hover:text-white border-red-200 shadow-sm';

        let label = disabled
            ? `Tunggu ${TIMEOUT_MINS - diffMins}m`
            : 'Ganti Supir';

        return `
        <button class="btn-change-driver px-3 py-1.5 rounded-lg text-xs font-bold border transition-colors flex items-center gap-1 ${btnClass}" 
            ${disabled ? 'disabled' : ''} 
            data-booking-id="${booking.id}">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                <path d="M8 5a1 1 0 100 2h5.586l-1.293 1.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L13.586 5H8zM12 15a1 1 0 100-2H6.414l1.293-1.293a1 1 0 10-1.414-1.414l-3 3a1 1 0 000 1.414l3 3a1 1 0 001.414-1.414L6.414 15H12z" />
            </svg>
            ${label}
        </button>`;
    }

    async openChangeDriverModal(bookingId) {
        this.selectedBookingIdForChange = bookingId;
        this.changeDriverModal.classList.remove('hidden');
        this.changeDriverModal.classList.add('flex');
        this.changeDriverList.innerHTML = '<div class="p-6 text-center text-slate-500 font-semibold">Memuat supir...</div>';

        try {
            const drivers = await fetchApi('/cso/available-drivers');

            if (drivers.length === 0) {
                this.changeDriverList.innerHTML = '<div class="p-6 text-center text-rose-500 font-semibold">Tidak ada supir standby.</div>';
                return;
            }

            this.changeDriverList.innerHTML = drivers.map(d => {
                const profile = d.driver_profile || {};
                const initial = (d.name || '?').charAt(0).toUpperCase();
                return `
                <div class="group flex items-center justify-between gap-3 p-3.5 border-b border-slate-100 dark:border-slate-700 last:border-0 hover:bg-indigo-50/60 dark:hover:bg-slate-700/60 cursor-pointer transition-colors" onclick="window.app.doChangeDriver(${d.id})">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-10 h-10 rounded-xl btn-grad text-white flex items-center justify-center font-extrabold shadow-md flex-shrink-0">${initial}</div>
                        <div class="min-w-0">
                            <div class="font-bold text-slate-800 dark:text-slate-100 truncate">${d.name}</div>
                            <div class="text-xs text-slate-500 truncate">${profile.car_model || '-'} • ${profile.plate_number || '-'}</div>
                        </div>
                    </div>
                    <button class="text-xs btn-grad text-white px-3.5 py-2 rounded-xl font-bold flex-shrink-0 group-hover:scale-105 transition-transform">Pilih</button>
                </div>`;
            }).join('');

        } catch (e) {
            this.changeDriverList.innerHTML = '<div class="p-6 text-center text-rose-500 font-semibold error">Gagal memuat supir.</div>';
        }
    }

    async doChangeDriver(newDriverId) {
        if (!confirm('Yakin ingin mengalihkan order ke supir ini?')) return;

        try {
            const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            const res = await fetch(`/api/cso/bookings/${this.selectedBookingIdForChange}/change-driver`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token
                },
                body: JSON.stringify({ new_driver_id: newDriverId })
            });

            const data = await res.json();
            if (!res.ok) throw new Error(data.message || 'Gagal ganti supir');

            Utils.showToast('Supir berhasil diganti!', 'success');
            this.changeDriverModal.classList.add('hidden');
            this.changeDriverModal.classList.remove('flex');
            this.renderHistory(); // Refresh list

        } catch (e) {
            Utils.showToast(e.message, 'error');
        }
    }

    checkGlobalQris() {
        // Cek Global QRIS URL
        if (window.companyQrisUrl) {
            this.paymentQrisImage.src = window.companyQrisUrl;
            this.paymentQrisImage.classList.remove('opacity-50', 'grayscale');
            this.paymentQrisError.classList.add('hidden');
            this.proofInput.disabled = false;
        } else {
            this.paymentQrisImage.src = '/pos-assets/img/qris-placeholder.svg';
            this.paymentQrisImage.classList.add('opacity-50', 'grayscale');
            this.paymentQrisError.classList.remove('hidden');
            this.paymentQrisError.textContent = '⚠️ QRIS belum diatur oleh Admin';
            Utils.showToast('Global QRIS belum diatur oleh Admin.', 'error');
        }
    }
}

// Expose app instance globally so inline onclick works
window.app = new CsoApp();
window.app.init();

export { CsoApp };