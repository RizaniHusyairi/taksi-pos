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


export class DriverApp {
  async init() {
    this.initTheme(); // Panggil inisialisasi tema
    this.cacheEls();
    this.bind();
    // Muat data awal yang penting saat aplikasi start
    await this.loadInitialData();
    
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
    this.activeBox = document.getElementById('activeOrderBox');
    this.activeInfo = document.getElementById('activeOrderInfo');
    this.btnComplete = document.getElementById('markComplete');
    this.statusToggle = document.getElementById('statusToggle');
    this.statusText = document.getElementById('driverStatusText');

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
  }

  bind() {

    this.statusToggle.addEventListener('change', (e) => this.handleStatusChange(e));
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
        this.renderStatus();
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
  }

  renderStatus() {
      if (!this.driverData) return;
      const status = this.driverData.driver_profile.status;
      const isAvailable = status === 'available';
      const isOntrip = status === 'ontrip';

      this.statusToggle.checked = isAvailable;
      this.statusToggle.disabled = isOntrip;

      if (isOntrip) {
        this.statusText.textContent = 'Sedang dalam perjalanan';
        this.statusText.className = 'text-sm text-pending font-semibold';
      } else if (isAvailable) {
        this.statusText.textContent = 'Tersedia (Online)';
        this.statusText.className = 'text-sm text-success font-semibold';
      } else {
        this.statusText.textContent = 'Offline';
        this.statusText.className = 'text-sm text-slate-500 dark:text-slate-400';
      }

      // ... (logika untuk mengubah teks status tidak berubah) ...
  }

  renderActiveOrder() {
      if (this.activeBooking) {
          this.activeBox.classList.remove('hidden');
          this.activeInfo.innerHTML = `
        <div class="flex items-center gap-2"><span class="font-semibold w-16">Rute:</span> <span>Bandara → ${this.activeBooking.zone_to.name}</span></div>
        <div class="flex items-center gap-2"><span class="font-semibold w-16">Tarif:</span> <span>${Utils.formatCurrency(this.activeBooking.price)}</span></div>
        <div class="flex items-center gap-2"><span class="font-semibold w-16">Status:</span> <span>${this.activeBooking.status}</span></div>
      `;

      } else {
          this.activeBox.classList.add('hidden');
      }
  }

  async renderWallet() {
      try {
          const [balance, withdrawals] = await Promise.all([
              fetchApi('/driver/balance'),
              fetchApi('/driver/withdrawals')
          ]);
          this.walletBalance.textContent = Utils.formatCurrency(balance.balance);
          // ... (logika render tabel riwayat withdrawal tidak berubah, gunakan data 'withdrawals') ...
          this.wdList.innerHTML = withdrawals.map(w => `<tr class="border-t dark:border-slate-700">
      <td class="py-2 pr-2">${new Date(w.requested_at).toLocaleDateString('id-ID')}</td>
      <td class="py-2 pr-2">${Utils.formatCurrency(w.amount)}</td>
      <td class="py-2"><span class="px-2 py-0.5 rounded-full text-xs font-medium ${w.status==='Pending'?'bg-yellow-100 text-yellow-800':(w.status==='Approved'?'bg-blue-100 text-blue-800':(w.status==='Paid'?'bg-green-100 text-green-800':'bg-red-100 text-red-700'))}">${w.status}</span></td>
    </tr>`).join('');
      } catch (error) {
          console.error("Gagal memuat data dompet:", error);
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
              return `
        <div class="bg-white dark:bg-slate-800 rounded-xl shadow-md p-4">
            <div class="flex justify-between items-start">
                <div>
                    <p class="font-bold text-slate-800 dark:text-slate-100">${t.route}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">${new Date(t.date).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })}</p>
                </div>
                <p class="font-bold text-lg text-success">${Utils.formatCurrency(t.amount)}</p>
            </div>
            <div class="mt-2 pt-2 border-t border-slate-100 dark:border-slate-700 flex justify-between text-xs">
                <span class="px-2 py-0.5 rounded-full bg-primary-100 dark:bg-primary-900/50 text-primary-800 dark:text-primary-300 font-medium">${t.method}</span>
                <span class="px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-800 dark:text-slate-200 font-medium">${t.status}</span>
            </div>
        </div>`;
          }).join('');
      } catch (error) {
          console.error("Gagal memuat riwayat perjalanan:", error);
      }
  }

  // --- FUNGSI AKSI (sekarang memanggil API) ---
    
  async handleStatusChange(e) {
      const newStatus = e.target.checked ? 'available' : 'offline';
      try {
          // Panggil API dan simpan data driver yang baru sebagai hasilnya
          const updatedDriverData = await fetchApi('/driver/status', {
              method: 'POST',
              body: JSON.stringify({ status: newStatus })
          });

          // Perbarui state lokal dengan data baru dari server
          this.driverData = updatedDriverData;
          
          // Render ulang komponen yang relevan dengan data baru
          this.renderStatus();
          this.renderProfile();

          Utils.showToast(`Status diubah menjadi: ${newStatus === 'available' ? 'Tersedia' : 'Offline'}`, 'success');

      } catch (error) {
          // Kembalikan toggle ke posisi semula jika terjadi error
          e.target.checked = !e.target.checked;
      }
  }

  async handleCompleteBooking() {
      if (!this.activeBooking) { Utils.showToast('Tidak ada order aktif', 'error'); return; }
      if (!confirm('Apakah Anda yakin perjalanan ini sudah selesai?')) return;
      try {
          await fetchApi(`/driver/bookings/${this.activeBooking.id}/complete`, { method: 'POST' });
          Utils.showToast('Perjalanan selesai', 'success');
          await this.loadInitialData(); // Muat ulang data untuk refresh status dan order
      } catch (error) { /* error ditangani fetchApi */ }
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
      } catch (error) { /* error ditangani fetchApi */ }
  }

  renderProfile() {
    this.profileInitial.textContent = this.u.name.charAt(0).toUpperCase();
    this.profileName.textContent = this.u.name;
    this.profileCar.textContent = `${this.u.car || '-'} • ${this.u.plate || '-'}`;
  }
}
