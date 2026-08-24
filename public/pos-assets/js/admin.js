import { Utils } from './utils.js';

window.openPayModal = (id) => {
  document.getElementById('wdIdToPay').value = id;
  document.getElementById('modalUploadProof').classList.remove('hidden');
  document.getElementById('modalUploadProof').classList.add('flex');
};

document.getElementById('btnCancelProof')?.addEventListener('click', () => {
  document.getElementById('modalUploadProof').classList.add('hidden');
  document.getElementById('modalUploadProof').classList.remove('flex');
});

document.getElementById('formUploadProof')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const id = document.getElementById('wdIdToPay').value;
  const fileInput = document.getElementById('fileProof');

  if (fileInput.files.length === 0) return alert('Wajib upload bukti transfer untuk menyetujui!');

  const formData = new FormData();
  formData.append('proof_image', fileInput.files[0]);

  const submitBtn = e.target.querySelector('button[type="submit"]');
  submitBtn.textContent = 'Memproses...';
  submitBtn.disabled = true;

  try {
    const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    // Panggil endpoint APPROVE (bukan paid lagi)
    const res = await fetch(`/api/admin/withdrawals/${id}/approve`, {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': token },
      body: formData
    });

    if (!res.ok) throw new Error('Gagal memproses.');

    alert('Berhasil Disetujui & Bukti Terkirim!');
    document.getElementById('modalUploadProof').classList.add('hidden');
    document.getElementById('modalUploadProof').classList.remove('flex');

    window.location.reload();

  } catch (err) {
    alert('Terjadi kesalahan saat upload.');
    console.error(err);
  } finally {
    submitBtn.textContent = 'Setujui & Kirim';
    submitBtn.disabled = false;
  }
});

// Helper function untuk memanggil API
async function fetchApi(endpoint, options = {}) {
  const headers = {
    'Accept': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
  };

  // Jika body bukan FormData, set Content-Type ke JSON
  // (Jika FormData, jangan set Content-Type agar browser otomatis set multipart/form-data + boundary)
  if (!(options.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
  }

  // Asumsi token disimpan di localStorage setelah login
  const token = localStorage.getItem('authToken');
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

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
    alert(`Gagal berkomunikasi dengan server: ${error.message}`);
    throw error; // Lemparkan lagi agar bisa ditangkap oleh pemanggil
  }
}

export class AdminApp {
  constructor() {
    this.views = ['dashboard', 'queue', 'zones', 'users', 'user-form', 'finance-log', 'withdrawals', 'cso-deposits', 'report-revenue', 'report-driver', 'cso-performance', 'driver-map', 'api', 'wa', 'settings'];
    this.charts = {};
  }
  init() {
    window.addEventListener('hashchange', () => this.route());
    this.route();
    this.populateFilterDropdowns();
    this.initCommon();
    this.initTheme(); // Initialize Theme
    this.initSidebar(); // Drawer mobile + tutup saat pilih menu
  }

  // Sidebar: buka/tutup drawer di mobile. Di desktop selalu tampil (md:translate-x-0),
  // jadi menambah/menghapus '-translate-x-full' hanya berpengaruh di layar < md.
  initSidebar() {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const btn = document.getElementById('mobileMenu');
    if (!sidebar || !backdrop) return;
    const open = () => { sidebar.classList.remove('-translate-x-full'); backdrop.classList.remove('hidden'); };
    const close = () => { sidebar.classList.add('-translate-x-full'); backdrop.classList.add('hidden'); };
    btn?.addEventListener('click', open);
    backdrop.addEventListener('click', close);
    sidebar.querySelectorAll('.nav-link').forEach(a =>
      a.addEventListener('click', () => {
        if (window.matchMedia('(max-width: 767px)').matches) close();
      })
    );
  }

  // --- Theme Logic ---
  initTheme() {
    const themeBtn = document.getElementById('themeToggle');
    const iconSun = document.getElementById('iconSun');
    const iconMoon = document.getElementById('iconMoon');
    const html = document.documentElement;

    // Helper: Update Icons based on current state
    const updateIcons = () => {
      if (html.classList.contains('dark')) {
        iconSun.classList.remove('hidden');
        iconMoon.classList.add('hidden');
      } else {
        iconSun.classList.add('hidden');
        iconMoon.classList.remove('hidden');
      }
    };

    // 1. Cek Preference Awal
    const isDark = localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches);

    // Apply Class
    if (isDark) {
      html.classList.add('dark');
    } else {
      html.classList.remove('dark');
    }
    updateIcons();

    // 2. Toggle Listener
    console.log('Init Theme: ThemeBtn found?', !!themeBtn);
    themeBtn?.addEventListener('click', () => {
      console.log('Theme Toggle Clicked');
      // Toggle Class
      html.classList.toggle('dark');

      // Simpan Preference & Update Icon
      if (html.classList.contains('dark')) {
        console.log('Switched to Dark');
        localStorage.theme = 'dark';
      } else {
        console.log('Switched to Light');
        localStorage.theme = 'light';
      }
      updateIcons();
      // Warna sumbu, grid, gradien, dan cincin donat dipilih saat grafik
      // DIBUAT — jadi keempat grafik harus digambar ulang, kalau tidak
      // palet lamanya ikut tertinggal setelah tema berganti.
      if (document.getElementById('weeklyChart')) this.renderDashboard();
    });
  }

  initCommon() {

    // Tab, pratinjau langsung, dan penanda "belum disimpan" di Pengaturan.
    this.initSettingsUi();

    // Settings form
    const formSettings = document.getElementById('formSettings');
    formSettings?.addEventListener('submit', async (e) => { // <-- Jadikan async
      e.preventDefault();

      const rateValue = document.getElementById('commissionRate').value;
      const emailValue = document.getElementById('adminEmail').value.trim(); // Ambil value email

      // Gunakan FormData agar bisa kirim file
      const formData = new FormData();
      formData.append('commission_rate', parseFloat(rateValue));
      formData.append('admin_email', emailValue);

      // Append field SMTP dkk
      const fields = [
        'mail_host', 'mail_port', 'mail_username', 'mail_password',
        'mail_encryption', 'mail_from_name', 'wa_token', 'admin_wa_number'
      ];

      fields.forEach(id => {
        const el = document.getElementById(id); // ID di HTML harus sesuai camelCase-nya dengan yang kita cari?
        // Wait, di HTML id-nya camelCase (adminWaNumber), tapi key DB snake_case (admin_wa_number).
        // Kita harus mapping.

        // Mapping ID -> Key DB
        // mailHost -> mail_host
        // mailPort -> mail_port
        // mailUsername -> mail_username
        // mailPassword -> mail_password
        // mailEncryption -> mail_encryption
        // mailFromName -> mail_from_name
        // waToken -> wa_token
        // adminWaNumber -> admin_wa_number
      });

      // Manual Append untuk memastikan ID benar
      formData.append('mail_host', document.getElementById('mailHost').value.trim());
      formData.append('mail_port', document.getElementById('mailPort').value.trim());
      formData.append('mail_username', document.getElementById('mailUsername').value.trim());
      formData.append('mail_password', document.getElementById('mailPassword').value.trim());
      formData.append('mail_encryption', document.getElementById('mailEncryption').value);
      formData.append('mail_from_address', document.getElementById('mailUsername').value.trim()); // Fallback sender
      formData.append('mail_from_name', document.getElementById('mailFromName').value.trim());
      // (Konfigurasi WhatsApp Gateway kini disimpan terpisah di menu WhatsApp Gateway.)
      const radiusEl = document.getElementById('airportRadiusKm');
      if (radiusEl && radiusEl.value.trim() !== '') {
        formData.append('airport_radius_km', radiusEl.value.trim());
      }
      // Titik pusat area bandara. Kosong = jangan ubah (server tetap pakai
      // nilai lama / bawaan config).
      const latEl = document.getElementById('airportLatitude');
      const lngEl = document.getElementById('airportLongitude');
      if (latEl && latEl.value.trim() !== '') {
        formData.append('airport_latitude', latEl.value.trim());
      }
      if (lngEl && lngEl.value.trim() !== '') {
        formData.append('airport_longitude', lngEl.value.trim());
      }
      // Tenggang di luar area (menit). Kosong = jangan ubah; 0 sah & berarti
      // auto-keluar antrian dinonaktifkan.
      const graceEl = document.getElementById('outOfAreaGraceMinutes');
      if (graceEl && graceEl.value.trim() !== '') {
        formData.append('out_of_area_grace_minutes', graceEl.value.trim());
      }
      // Jam operasi pelacakan lokasi (HH:MM). Kosong = jangan ubah.
      const opStartEl = document.getElementById('operatingStart');
      const opEndEl = document.getElementById('operatingEnd');
      if (opStartEl && opStartEl.value.trim() !== '') {
        formData.append('operating_start', opStartEl.value.trim());
      }
      if (opEndEl && opEndEl.value.trim() !== '') {
        formData.append('operating_end', opEndEl.value.trim());
      }

      // FILE UPLOAD
      const fileInput = document.getElementById('companyQris');
      if (fileInput && fileInput.files[0]) {
        formData.append('company_qris', fileInput.files[0]);
      }

      // Validasi sederhana. Nilai bermasalah ada di tab Umum, jadi buka tab itu
      // dulu — kalau tidak, pesan galat menunjuk isian yang sedang tersembunyi.
      if (isNaN(parseFloat(rateValue)) || parseFloat(rateValue) < 0 || parseFloat(rateValue) > 100) {
        this.showSettingsTab('umum');
        document.getElementById('commissionRate')?.focus();
        Utils.showToast('Masukkan persentase komisi antara 0 dan 100.', 'error');
        return;
      }

      if (!emailValue) {
        this.showSettingsTab('umum');
        document.getElementById('adminEmail')?.focus();
        Utils.showToast('Email admin tidak boleh kosong.', 'error');
        return;
      }

      const saveBtn = document.getElementById('btnSettingsSave');
      const saveLabel = saveBtn?.textContent;
      if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Menyimpan…'; }

      try {
        // 2. Kirim data ke API menggunakan POST via FormData
        // fetchApi sudah dimodifikasi untuk tidak memaksa Content-Type JSON jika body adalah FormData
        await fetchApi('/admin/settings', {
          method: 'POST',
          body: formData
        });
        Utils.showToast('Pengaturan berhasil disimpan.', 'success');
        this.markSettingsDirty(false);
        // Ambil ulang agar nilai efektif dari server (mis. radius yang dibulatkan
        // atau koordinat yang di-fallback) tampil apa adanya.
        this.renderSettings();
      } catch (error) {
        console.error("Gagal menyimpan pengaturan:", error);
        Utils.showToast('Gagal menyimpan pengaturan. Silakan coba lagi.', 'error');
      } finally {
        if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = saveLabel; }
      }

    });

    // Password form
    const formAdminPassword = document.getElementById('formAdminPassword');
    formAdminPassword?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const currentPassword = document.getElementById('currentPassword').value;
      const newPassword = document.getElementById('newPassword').value;
      const confirmNewPassword = document.getElementById('confirmNewPassword').value;

      if (newPassword !== confirmNewPassword) {
        Utils.showToast('Konfirmasi kata sandi tidak cocok.', 'error');
        return;
      }

      try {
        await fetchApi('/admin/settings/password', {
          method: 'POST',
          body: JSON.stringify({
            current_password: currentPassword,
            new_password: newPassword,
            new_password_confirmation: confirmNewPassword
          })
        });
        Utils.showToast('Kata sandi berhasil diperbarui.', 'success');
        formAdminPassword.reset();
        this.renderPasswordFeedback(); // kosongkan indikator kekuatan & kecocokan
      } catch (error) {
        console.error("Gagal ganti password:", error);
        Utils.showToast('Gagal ganti kata sandi: ' + (error.message || 'Terjadi kesalahan'), 'error');
      }
    });





    // Zones & Tariff forms
    const formZone = document.getElementById('formZone');
    const zoneReset = document.getElementById('zoneReset');


    formZone?.addEventListener('submit', async (e) => { // Tambahkan 'async'
      e.preventDefault();
      const id = document.getElementById('zoneId').value || null;
      const name = document.getElementById('zoneName').value.trim();
      const price = parseInt(document.getElementById('zonePrice').value, 10) || 0;
      if (!name) return;

      const payload = {
        name, price,
        category: document.getElementById('zoneCategory')?.value === 'luar' ? 'luar' : 'dalam',
        description: (document.getElementById('zoneDescription')?.value || '').trim() || null,
      };

      try {
        if (id) {
          // Jika ada ID, ini adalah UPDATE (PUT)
          await fetchApi(`/admin/zones/${id}`, {
            method: 'PUT',
            body: JSON.stringify(payload)
          });
        } else {
          // Jika tidak ada ID, ini adalah CREATE (POST)
          await fetchApi('/admin/zones', {
            method: 'POST',
            body: JSON.stringify(payload)
          });
        }
        this._resetZoneForm();
        await this.renderZones(); // refresh tabel (pencarian & urutan dipertahankan)
      } catch (error) {
        console.error("Gagal menyimpan zona:", error);
      }
    });

    zoneReset?.addEventListener('click', () => this._resetZoneForm());

    // Users modal
    const userModal = document.getElementById('userModal');
    const btnOpenCreateUser = document.getElementById('btnOpenCreateUser');
    const userModalClose = document.getElementById('userModalClose');
    const userModalCancel = document.getElementById('userModalCancel');
    const userRole = document.getElementById('userRole');

    function toggleDriverExtra() {
      const role = userRole.value;
      const extra = document.getElementById('driverExtra');
      if (extra) extra.style.display = role === 'driver' ? 'grid' : 'none';
    }

    userRole?.addEventListener('change', toggleDriverExtra);
    btnOpenCreateUser?.addEventListener('click', () => this.openUserForm(null));
    userModalCancel?.addEventListener('click', () => this.closeUserForm());
    // Listener Refresh Queue
    document.getElementById('refreshQueue')?.addEventListener('click', () => this.renderQueue());

    // Ganti event listener yang lama dengan yang ini
    document.getElementById('formUser')?.addEventListener('submit', async (e) => { // <-- Jadikan async
      e.preventDefault();

      const id = document.getElementById('userId').value || null;
      const password = document.getElementById('userPassword').value;

      // 1. Siapkan 'payload' dengan key yang sesuai dengan backend Laravel
      const payload = {
        name: document.getElementById('userName').value.trim(),
        role: document.getElementById('userRole').value,
        username: document.getElementById('userUsername').value.trim(),
        // Tambahan untuk role 'driver'
        car_model: document.getElementById('userCar').value.trim(),
        plate_number: document.getElementById('userPlate').value.trim()
      };

      // Hanya kirim password jika diisi (untuk create wajib, untuk edit opsional)
      if (password) {
        payload.password = password;
      } else if (!id) {
        // Jika ini adalah user baru dan password kosong
        alert('Password wajib diisi untuk pengguna baru.');
        return;
      }

      try {
        // 2. Tentukan aksi: UPDATE (PUT) atau CREATE (POST)
        if (id) {
          // Aksi UPDATE
          await fetchApi(`/admin/users/${id}`, {
            method: 'PUT',
            body: JSON.stringify(payload)
          });
        } else {
          // Aksi CREATE
          await fetchApi('/admin/users', {
            method: 'POST',
            body: JSON.stringify(payload)
          });
        }

        alert('Data pengguna berhasil disimpan!');

        this.closeUserForm();

        // 3. Refresh data yang relevan setelah berhasil
        await this.renderUsers(); // Muat ulang tabel pengguna
        await this.populateFilterDropdowns(); // Muat ulang dropdown filter

      } catch (error) {
        console.error("Gagal menyimpan data pengguna:", error);
        alert('Gagal menyimpan data pengguna. Periksa kembali isian Anda.');
      }
    });

    // Finance filters (live)
    ['fltDateFrom', 'fltDateTo', 'fltDriver', 'fltCSO', 'fltMethod'].forEach(id => {
      document.getElementById(id)?.addEventListener('change', () => this.renderTxLog());
    });
    let _txSearchTimer;
    document.getElementById('fltSearch')?.addEventListener('input', () => {
      clearTimeout(_txSearchTimer);
      _txSearchTimer = setTimeout(() => this.renderTxLog(), 300);
    });
    document.getElementById('btnTxExportPdf')?.addEventListener('click', () => this._exportTx('pdf'));
    document.getElementById('btnTxExportExcel')?.addEventListener('click', () => this._exportTx('excel'));

    // Revenue report range
    document.getElementById('formReportRevenue')?.addEventListener('submit', (e) => { e.preventDefault(); this.renderRevReport(); });
    document.getElementById('driverRankBy')?.addEventListener('change', () => this.renderDriverReport());
    ['csoPerfMonth', 'csoRankBy'].forEach(id =>
      document.getElementById(id)?.addEventListener('change', () => this.renderCsoReport()));
    document.getElementById('dashDriverMetric')?.addEventListener('change', () => this.renderPerformanceCharts());
    document.getElementById('btnRefreshMap')?.addEventListener('click', () => this.renderDriverMap());
    document.getElementById('btnClearRoute')?.addEventListener('click', () => this.clearRoute());
    document.getElementById('btnNewApiKey')?.addEventListener('click', () => this.createApiKey());
    document.getElementById('btnTestWa')?.addEventListener('click', () => this.testWa());
    document.getElementById('formWaConfig')?.addEventListener('submit', (e) => this.saveWaConfig(e));
    document.getElementById('btnRefreshWaLog')?.addEventListener('click', () => this.renderWaLog());
    ['waLogDirection', 'waLogStatus', 'waLogLimit'].forEach(id =>
      document.getElementById(id)?.addEventListener('change', () => this.renderWaLog()));
    document.getElementById('btnCopyApiKey')?.addEventListener('click', () => this.copyNewApiKey());
    document.getElementById('apiClientsTable')?.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-revoke]');
      if (btn) this.revokeApiKey(btn.getAttribute('data-revoke'));
    });
    document.getElementById('view-api')?.addEventListener('click', (e) => {
      const t = e.target.closest('[data-try]');
      if (t) this.tryApiEndpoint(t.getAttribute('data-try'));
    });

    this.renderAll();

    // Di dalam initCommon()
    document.getElementById('btnCloseWdDetails')?.addEventListener('click', () => {
      document.getElementById('modalWdDetails').classList.add('hidden');
      document.getElementById('modalWdDetails').classList.remove('flex');
    });
    document.getElementById('btnExitWdDetails')?.addEventListener('click', () => {
      document.getElementById('modalWdDetails').classList.add('hidden');
      document.getElementById('modalWdDetails').classList.remove('flex');
    });

    // Agar fungsi openWdDetails bisa dipanggil dari onclick string HTML

    window.app = this;
    window.openWdDetails = (id) => this.openWdDetails(id);
    window.openDepDetails = (id) => this.openDepDetails(id);

    // Modal rincian setoran CSO
    document.getElementById('btnCloseDepDetails')?.addEventListener('click', () => {
      const m = document.getElementById('modalDepDetails');
      m.classList.add('hidden');
      m.classList.remove('flex');
    });
    window.openActivityModal = (id) => this.openActivityModal(id);

    // Listeners Modal Activity
    document.getElementById('btnCloseActivity')?.addEventListener('click', () => {
      document.getElementById('modalActivity').classList.add('hidden');
      document.getElementById('modalActivity').classList.remove('flex');
    });
    document.getElementById('btnExitActivity')?.addEventListener('click', () => {
      document.getElementById('modalActivity').classList.add('hidden');
      document.getElementById('modalActivity').classList.remove('flex');
    });
  }

  renderAll() {
    this.renderDashboard();
    this.renderZones();
    this.renderUsers();
    this.renderTxLog();
    this.renderWithdrawals();
    this.renderCsoDeposits();
    this.renderRevReport();
    this.renderDriverReport();
    this.renderCsoReport();
    this.renderSettings();
    this.renderApiClients();
    this.renderQueue();
  }

  route() {
    const hash = (location.hash || '#dashboard').slice(1);
    this.views.forEach(v => {
      const el = document.getElementById('view-' + v);
      if (!el) return;
      if (v === hash) { el.classList.remove('hidden'); document.getElementById('pageTitle').textContent = this.titleOf(v); }
      else el.classList.add('hidden');
    });
    document.querySelectorAll('.nav-link').forEach(a => {
      const target = a.getAttribute('href').replace('#', '');
      a.classList.toggle('active', target === hash);
    });

    // Peta supir: render + auto-refresh tiap 15 dtk saat aktif, berhenti saat pindah.
    clearInterval(this._mapPoll);
    if (hash === 'driver-map') {
      this.renderDriverMap();
      this._mapPoll = setInterval(() => this.renderDriverMap(), 15000);
    }
    if (hash === 'wa') this.renderWa();
    // Peta pemilih titik pusat hidup di tab Pengaturan yang awalnya tersembunyi
    // → Leaflet perlu menghitung ulang ukurannya setiap tab ini dibuka.
    if (hash === 'settings') this.renderAirportCenterMap();
  }
  titleOf(v) {
    return {
      'dashboard': 'Dashboard',
      'queue': 'Manajemen Antrian',
      'zones': 'Manajemen Zona & Tarif',
      'users': 'Manajemen Pengguna',
      'user-form': 'Formulir Pengguna',
      'finance-log': 'Transaction Log',
      'withdrawals': 'Withdrawal Requests',
      'cso-deposits': 'Setoran Tunai CSO',
      'report-revenue': 'Laporan Pendapatan',
      'report-driver': 'Laporan Kinerja Supir',
      'cso-performance': 'Performa CSO',
      'driver-map': 'Peta Supir',
      'api': 'API Integrasi',
      'wa': 'WhatsApp Gateway',
      'settings': 'Pengaturan'
    }[v] || 'Dashboard';
  }

  // ----- Dashboard -----
  async renderDashboard() { // <-- Jadikan async
    try {
      // 1. BUAT SATU PANGGILAN API UNTUK SEMUA DATA DASHBOARD
      const dashboardData = await fetchApi('/admin/dashboard-stats');

      // 2. POPULASIKAN METRIK DARI DATA API
      const metrics = dashboardData.metrics;
      document.getElementById('metricRevenueToday').textContent = (metrics.revenue_today || 0).toLocaleString('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 });
      document.getElementById('metricTxCount').textContent = metrics.transactions_today || 0;
      document.getElementById('metricActiveDrivers').textContent = metrics.active_drivers || 0;
      document.getElementById('metricPendingWd').textContent = metrics.pending_withdrawals || 0;

      // 2b. KONTEKS: perbandingan vs kemarin & pecahan armada
      this._renderDelta('metricRevenueDelta', metrics.revenue_change_pct,
        `kemarin ${Utils.formatCurrency(metrics.revenue_yesterday || 0)}`);
      this._renderDelta('metricTxDelta', metrics.transactions_change_pct,
        `kemarin ${metrics.transactions_yesterday ?? 0} transaksi`);

      const setText = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
      setText('metricDriversBreakdown',
        `${metrics.drivers_in_queue ?? 0} mengantre · ${metrics.drivers_on_trip ?? 0} mengantar`);
      setText('metricPendingWdAmount',
        metrics.pending_withdrawals
          ? `Senilai ${Utils.formatCurrency(metrics.pending_withdrawals_amount || 0)}`
          : 'Tidak ada pengajuan tertunda');

      // 2c. Uang masuk hari ini + hutang setoran supir
      this._renderPayments(dashboardData.payments || {});

      // 2d. Denyut operasional
      this._renderRecentActivity(dashboardData.recent || []);

      // 3. POPULASIKAN GRAFIK DARI DATA API
      const charts = dashboardData.charts;
      this.renderLineChart('weeklyChart', charts.weekly.labels, charts.weekly.values);
      this.renderBarChart('monthlyChart', charts.monthly.labels, charts.monthly.values);

      // Grafik performa punya endpoint sendiri — jangan biarkan kegagalannya
      // menghapus metrik yang sudah berhasil tampil di atas.
      this.renderPerformanceCharts();

    } catch (error) {
      console.error("Gagal memuat data dashboard:", error);
      // Tampilkan pesan error jika gagal memuat
      document.getElementById('metricRevenueToday').textContent = 'Error';
      document.getElementById('metricTxCount').textContent = 'Error';
      document.getElementById('metricActiveDrivers').textContent = 'Error';
      document.getElementById('metricPendingWd').textContent = 'Error';
    }
  }
  // ----- Grafik performa Supir & CSO di dashboard -----

  /** Palet deret untuk grafik, diambil dari warna merek aplikasi. */
  static get CHART_COLORS() {
    return ['#14b8a6', '#38bdf8', '#f59e0b', '#8b5cf6', '#ec4899', '#10b981', '#f43f5e'];
  }

  /** Warna teks/garis grid yang menyesuaikan mode terang-gelap. */
  _chartTheme() {
    const gelap = document.documentElement.classList.contains('dark');
    return {
      gelap,
      teks: gelap ? '#94a3b8' : '#64748b',
      grid: gelap ? 'rgba(255,255,255,.07)' : 'rgba(15,23,42,.07)',
      tooltipBg: gelap ? 'rgba(15,23,42,.95)' : 'rgba(255,255,255,.97)',
      tooltipTeks: gelap ? '#e2e8f0' : '#0f172a',
      cincin: gelap ? '#0f172a' : '#ffffff',
    };
  }

  /**
   * Dua grafik performa: papan peringkat supir (batang horizontal) dan
   * kontribusi pesanan CSO bulan berjalan (donat).
   *
   * Keduanya memakai endpoint laporan yang sudah ada — tidak ada perhitungan
   * ulang di browser, jadi angkanya pasti sama dengan halaman laporan.
   */
  async renderPerformanceCharts() {
    const metrik = document.getElementById('dashDriverMetric')?.value || 'revenue';
    const bulan = new Date().toISOString().slice(0, 7);

    // Satu grafik gagal tidak boleh menjatuhkan yang lain → tangani terpisah.
    const [supir, cso] = await Promise.allSettled([
      fetchApi(`/admin/reports/driver-performance?sort_by=${metrik}`),
      fetchApi(`/admin/reports/cso-performance?month=${bulan}&sort_by=orders`),
    ]);

    if (supir.status === 'fulfilled') this._drawDriverChart(supir.value, metrik);
    else console.error('Gagal memuat papan peringkat supir:', supir.reason);

    if (cso.status === 'fulfilled') this._drawCsoChart(cso.value);
    else console.error('Gagal memuat kontribusi CSO:', cso.reason);
  }

  /** Batang horizontal: 6 supir teratas menurut metrik terpilih. */
  _drawDriverChart(drivers, metrik) {
    const canvas = document.getElementById('driverPerfChart');
    const kosong = document.getElementById('driverPerfEmpty');
    if (!canvas || typeof Chart === 'undefined') return;

    const ambil = (d) => metrik === 'trips' ? (d.trips || 0)
      : metrik === 'rating' ? Number(d.avg_rating || 0)
        : Number(d.revenue || 0);

    // Endpoint membalas array telanjang; jaga-jaga bila suatu saat dibungkus
    // amplop { data: [...] } — bentuk tak terduga tidak boleh menjatuhkan
    // seluruh render dashboard dengan TypeError.
    const list = Array.isArray(drivers) ? drivers : (Array.isArray(drivers?.data) ? drivers.data : []);
    const top = list.filter(d => ambil(d) > 0).slice(0, 6);
    canvas.classList.toggle('hidden', top.length === 0);
    kosong?.classList.toggle('hidden', top.length > 0);
    if (top.length === 0) { this.charts['driverPerfChart']?.destroy(); this.charts['driverPerfChart'] = null; return; }

    const t = this._chartTheme();
    const judul = { revenue: 'Pendapatan', trips: 'Jumlah Trip', rating: 'Rating Rata-rata' }[metrik];
    const fmt = (v) => metrik === 'revenue' ? Utils.formatCurrency(v)
      : metrik === 'rating' ? Number(v).toFixed(1) + ' ★'
        : v + ' trip';

    // Gradien mendatar supaya batangnya tidak terasa datar/mati.
    const ctx = canvas.getContext('2d');
    const grad = ctx.createLinearGradient(0, 0, canvas.width || 420, 0);
    grad.addColorStop(0, '#0d9488');
    grad.addColorStop(1, '#38bdf8');

    this.charts['driverPerfChart']?.destroy();
    this.charts['driverPerfChart'] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels: top.map(d => d.name),
        datasets: [{
          label: judul,
          data: top.map(ambil),
          backgroundColor: grad,
          hoverBackgroundColor: '#14b8a6',
          borderRadius: 8,
          borderSkipped: false,
          barThickness: 20,
        }],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 700, easing: 'easeOutQuart' },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: t.tooltipBg, titleColor: t.tooltipTeks, bodyColor: t.tooltipTeks,
            borderColor: t.grid, borderWidth: 1, padding: 10, displayColors: false,
            callbacks: {
              label: (c) => {
                const d = top[c.dataIndex];
                const baris = [`${judul}: ${fmt(c.parsed.x)}`];
                // Konteks tambahan supaya satu batang menceritakan lebih banyak.
                if (metrik !== 'trips') baris.push(`Trip: ${d.trips || 0}`);
                if (metrik !== 'revenue') baris.push(`Pendapatan: ${Utils.formatCurrency(d.revenue || 0)}`);
                if (metrik !== 'rating' && d.avg_rating) baris.push(`Rating: ${Number(d.avg_rating).toFixed(1)} ★ (${d.rating_count || 0})`);
                return baris;
              },
            },
          },
        },
        scales: {
          x: {
            beginAtZero: true,
            grid: { color: t.grid, drawBorder: false },
            ticks: {
              color: t.teks, font: { size: 11 },
              callback: (v) => metrik === 'revenue'
                ? 'Rp' + (v >= 1e6 ? (v / 1e6) + 'jt' : v / 1000 + 'rb')
                : v,
            },
          },
          y: { grid: { display: false, drawBorder: false }, ticks: { color: t.teks, font: { size: 11, weight: '600' } } },
        },
      },
    });
  }

  /** Donat: pangsa pesanan tiap CSO bulan berjalan, total di tengah. */
  _drawCsoChart(data) {
    const canvas = document.getElementById('csoPerfChart');
    const kosong = document.getElementById('csoPerfEmpty');
    const legend = document.getElementById('csoChartLegend');
    if (!canvas || typeof Chart === 'undefined') return;

    const rows = (data.csos || []).filter(c => c.orders > 0);
    const total = rows.reduce((a, c) => a + c.orders, 0);

    canvas.classList.toggle('hidden', total === 0);
    kosong?.classList.toggle('hidden', total > 0);
    if (legend) legend.innerHTML = '';
    const per = document.getElementById('csoChartPeriod');
    if (per && data.month) {
      const [y, m] = data.month.split('-');
      per.textContent = new Date(y, m - 1).toLocaleDateString('id-ID', { month: 'long', year: 'numeric' });
    }
    if (total === 0) { this.charts['csoPerfChart']?.destroy(); this.charts['csoPerfChart'] = null; return; }

    const t = this._chartTheme();
    const warna = AdminApp.CHART_COLORS;
    const esc = AdminApp.escapeHtml;

    // Legenda dibuat sendiri: menampilkan jumlah pesanan + nilai transaksi,
    // dua hal yang tidak muat di legenda bawaan Chart.js.
    if (legend) {
      legend.innerHTML = rows.map((c, i) => `
        <span class="flex items-center gap-1.5 text-gray-600 dark:text-gray-300">
          <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background:${warna[i % warna.length]}"></span>
          ${esc(c.name)}
          <b class="text-gray-800 dark:text-white">${c.orders}</b>
          <span class="text-gray-400">· ${Utils.formatCurrency(c.revenue)}</span>
        </span>`).join('');
    }

    // Plugin kecil untuk menulis total di lubang donat.
    const tulisTotal = {
      id: 'totalDiTengah',
      afterDraw(chart) {
        const { ctx, chartArea } = chart;
        if (!chartArea) return;
        const x = (chartArea.left + chartArea.right) / 2;
        const y = (chartArea.top + chartArea.bottom) / 2;
        ctx.save();
        ctx.textAlign = 'center';
        ctx.fillStyle = t.gelap ? '#f1f5f9' : '#0f172a';
        ctx.font = '700 26px Outfit, system-ui, sans-serif';
        ctx.fillText(String(total), x, y + 2);
        ctx.fillStyle = t.teks;
        ctx.font = '600 11px Outfit, system-ui, sans-serif';
        ctx.fillText('PESANAN', x, y + 20);
        ctx.restore();
      },
    };

    this.charts['csoPerfChart']?.destroy();
    this.charts['csoPerfChart'] = new Chart(canvas.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: rows.map(c => c.name),
        datasets: [{
          data: rows.map(c => c.orders),
          backgroundColor: rows.map((_, i) => warna[i % warna.length]),
          borderColor: t.cincin,
          borderWidth: 3,
          hoverOffset: 10,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '64%',
        animation: { animateRotate: true, duration: 800, easing: 'easeOutQuart' },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: t.tooltipBg, titleColor: t.tooltipTeks, bodyColor: t.tooltipTeks,
            borderColor: t.grid, borderWidth: 1, padding: 10,
            callbacks: {
              label: (c) => {
                const row = rows[c.dataIndex];
                const pangsa = (row.orders / total * 100).toFixed(1);
                return [
                  `${row.orders} pesanan (${pangsa}%)`,
                  `Selesai ${row.completed} · Batal ${row.cancelled}`,
                  `Nilai ${Utils.formatCurrency(row.revenue)}`,
                ];
              },
            },
          },
        },
      },
      plugins: [tulisTotal],
    });
  }

  /**
   * Lencana perubahan vs kemarin.
   *
   * `pct` bernilai null bila pembandingnya nol — server sengaja mengirim null
   * alih-alih angka, dan di sini ditampilkan sebagai "—" supaya tidak ada
   * "naik 100%" palsu dari basis kosong.
   */
  _renderDelta(id, pct, konteks) {
    const el = document.getElementById(id);
    if (!el) return;

    if (pct === null || pct === undefined) {
      el.className = 'mt-1.5 text-[11px] font-semibold text-gray-400';
      el.textContent = konteks ? `— ${konteks}` : '—';
      return;
    }
    const naik = pct >= 0;
    el.className = 'mt-1.5 text-[11px] font-semibold ' +
      (naik ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-500');
    el.textContent = `${naik ? '▲' : '▼'} ${Math.abs(pct)}%` + (konteks ? ` · ${konteks}` : '');
  }

  /** Batang bertumpuk metode bayar hari ini + hutang setoran supir. */
  _renderPayments(p) {
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    const cashCso = Number(p.cash_cso) || 0;
    const cashDriver = Number(p.cash_driver) || 0;
    const qris = Number(p.qris) || 0;
    const total = Number(p.total) || 0;

    set('payTotal', Utils.formatCurrency(total));
    set('payCashCso', Utils.formatCurrency(cashCso));
    set('payCashDriver', Utils.formatCurrency(cashDriver));
    set('payQris', Utils.formatCurrency(qris));
    set('payDriverDebt', Utils.formatCurrency(Number(p.driver_debt) || 0));

    // Total 0 → jangan bagi nol; batang dikosongkan dan diberi keterangan.
    const lebar = (n) => (total > 0 ? (n / total * 100) : 0).toFixed(2) + '%';
    const bar = (id, w) => { const el = document.getElementById(id); if (el) el.style.width = w; };
    bar('payBarCashCso', lebar(cashCso));
    bar('payBarCashDriver', lebar(cashDriver));
    bar('payBarQris', lebar(qris));
    document.getElementById('payEmpty')?.classList.toggle('hidden', total > 0);
  }

  /** Daftar transaksi terakhir. */
  _renderRecentActivity(rows) {
    const box = document.getElementById('recentActivity');
    if (!box) return;

    if (!rows.length) {
      box.innerHTML = '<p class="text-center text-xs text-gray-400 py-8">Belum ada transaksi.</p>';
      return;
    }

    const esc = AdminApp.escapeHtml;
    const gaya = {
      QRIS: ['bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400', 'QRIS'],
      CashCSO: ['bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400', 'Tunai CSO'],
      CashDriver: ['bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400', 'Tunai Supir'],
    };

    box.innerHTML = rows.map(r => {
      const [warna, label] = gaya[r.method] || ['bg-gray-100 text-gray-500', r.method || '-'];
      return `
      <div class="flex items-center gap-3 py-2.5">
        <span class="shrink-0 text-[10px] font-bold px-2 py-1 rounded-lg ${warna}">${esc(label)}</span>
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate">${esc(r.destination || '-')}</p>
          <p class="text-[11px] text-gray-400 truncate">${esc(r.driver || 'Tanpa supir')} · ${esc(r.cso || 'Tanpa CSO')}</p>
        </div>
        <div class="text-right shrink-0">
          <p class="text-sm font-bold text-gray-800 dark:text-gray-100">${Utils.formatCurrency(r.amount)}</p>
          <p class="text-[10px] text-gray-400">${AdminApp.waktuRelatif(r.created_at)}</p>
        </div>
      </div>`;
    }).join('');
  }

  /** "5 mnt lalu", "3 jam lalu", "12 Agu" — lebih cepat dibaca dari timestamp penuh. */
  static waktuRelatif(iso) {
    if (!iso) return '-';
    const t = new Date(iso);
    if (isNaN(t)) return '-';
    const detik = Math.floor((Date.now() - t.getTime()) / 1000);
    if (detik < 60) return 'baru saja';
    if (detik < 3600) return `${Math.floor(detik / 60)} mnt lalu`;
    if (detik < 86400) return `${Math.floor(detik / 3600)} jam lalu`;
    if (detik < 604800) return `${Math.floor(detik / 86400)} hari lalu`;
    return t.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
  }

  /** Sumbu rupiah ringkas: 2500000 → "Rp2,5jt". */
  static rupiahSingkat(v) {
    const n = Number(v) || 0;
    if (Math.abs(n) >= 1e9) return 'Rp' + (n / 1e9).toFixed(1).replace('.', ',') + 'm';
    if (Math.abs(n) >= 1e6) return 'Rp' + (n / 1e6).toFixed(1).replace('.', ',') + 'jt';
    if (Math.abs(n) >= 1e3) return 'Rp' + Math.round(n / 1e3) + 'rb';
    return 'Rp' + n;
  }

  /** Kerangka opsi bersama untuk grafik tren, agar sama dengan grafik performa. */
  _trendOptions(t) {
    return {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 700, easing: 'easeOutQuart' },
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: t.tooltipBg, titleColor: t.tooltipTeks, bodyColor: t.tooltipTeks,
          borderColor: t.grid, borderWidth: 1, padding: 10, displayColors: false,
          callbacks: { label: (c) => Utils.formatCurrency(c.parsed.y) },
        },
      },
      scales: {
        x: { grid: { display: false, drawBorder: false }, ticks: { color: t.teks, font: { size: 10 } } },
        y: {
          beginAtZero: true,
          grid: { color: t.grid, drawBorder: false },
          ticks: { color: t.teks, font: { size: 10 }, callback: (v) => AdminApp.rupiahSingkat(v) },
        },
      },
    };
  }

  renderLineChart(id, labels, data) {
    const canvas = document.getElementById(id);
    if (!canvas || typeof Chart === 'undefined') return;
    const t = this._chartTheme();
    const ctx = canvas.getContext('2d');

    // Gradien vertikal di bawah garis — memberi bobot visual tanpa menutupi data.
    const isi = ctx.createLinearGradient(0, 0, 0, canvas.height || 220);
    isi.addColorStop(0, 'rgba(20,184,166,0.30)');
    isi.addColorStop(1, 'rgba(20,184,166,0.02)');

    this.charts[id]?.destroy();
    this.charts[id] = new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: 'Pendapatan',
          data,
          fill: true,
          backgroundColor: isi,
          borderColor: '#14b8a6',
          borderWidth: 2.5,
          tension: 0.38,
          pointRadius: 0,
          pointHoverRadius: 6,
          pointBackgroundColor: '#14b8a6',
          pointHoverBorderColor: '#fff',
          pointHoverBorderWidth: 2,
        }],
      },
      options: this._trendOptions(t),
    });
  }

  renderBarChart(id, labels, data) {
    const canvas = document.getElementById(id);
    if (!canvas || typeof Chart === 'undefined') return;
    const t = this._chartTheme();
    const ctx = canvas.getContext('2d');

    const isi = ctx.createLinearGradient(0, 0, 0, canvas.height || 220);
    isi.addColorStop(0, '#38bdf8');
    isi.addColorStop(1, 'rgba(56,189,248,0.35)');

    this.charts[id]?.destroy();
    this.charts[id] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: 'Pendapatan',
          data,
          backgroundColor: isi,
          hoverBackgroundColor: '#0ea5e9',
          borderRadius: 7,
          borderSkipped: false,
          maxBarThickness: 26,
        }],
      },
      options: this._trendOptions(t),
    });
  }

  async populateFilterDropdowns() {
    try {
      // 1. Panggil API untuk supir dan CSO secara paralel
      const [drivers, csos] = await Promise.all([
        fetchApi('/admin/users/role/driver'),
        fetchApi('/admin/users/role/cso')
      ]);

      // 2. Siapkan elemen HTML <option> dari data API
      const driverOptions = ['<option value="">Semua Supir</option>']
        .concat(drivers.map(d => `<option value="${d.id}">${d.name}</option>`))
        .join('');

      const csoOptions = ['<option value="">Semua CSO</option>']
        .concat(csos.map(c => `<option value="${c.id}">${c.name}</option>`))
        .join('');

      // 3. Masukkan options ke dalam elemen <select>
      const fltDriver = document.getElementById('fltDriver');
      const fltCSO = document.getElementById('fltCSO');

      if (fltDriver) fltDriver.innerHTML = driverOptions;
      if (fltCSO) fltCSO.innerHTML = csoOptions;

    } catch (error) {
      console.error("Gagal memuat data untuk filter:", error);
      // Mungkin nonaktifkan atau beri pesan error di dropdown
    }
  }
  // ----- Zones & Tariffs -----
  async renderZones() {
    const tbody = document.getElementById('zonesTable');
    if (!tbody) return;
    try {
      this._zones = await fetchApi('/admin/zones');
    } catch (err) {
      console.error("Gagal memuat data zona:", err);
      tbody.innerHTML = '<tr><td colspan="3" class="py-8 text-center text-red-500">Gagal memuat data zona.</td></tr>';
      return;
    }
    this._zoneSort = this._zoneSort || { key: 'name', dir: 'asc' };
    this._bindZoneControls();
    this._renderZoneRows();
  }

  // Bind kontrol pencarian & sort SEKALI (elemennya statis di blade).
  _bindZoneControls() {
    if (this._zoneBound) return;
    this._zoneBound = true;
    const search = document.getElementById('zoneSearch');
    const clear = document.getElementById('zoneSearchClear');
    search?.addEventListener('input', () => {
      this._zoneQuery = search.value.trim().toLowerCase();
      clear?.classList.toggle('hidden', !search.value);
      this._renderZoneRows();
    });
    clear?.addEventListener('click', () => {
      if (search) search.value = '';
      this._zoneQuery = '';
      clear.classList.add('hidden');
      this._renderZoneRows();
      search?.focus();
    });
    document.querySelectorAll('#view-zones .sort-th').forEach(th => {
      th.addEventListener('click', () => {
        const key = th.dataset.sort;
        if (this._zoneSort.key === key) {
          this._zoneSort.dir = this._zoneSort.dir === 'asc' ? 'desc' : 'asc';
        } else {
          this._zoneSort = { key, dir: 'asc' };
        }
        this._renderZoneRows();
      });
    });
    // Toggle kategori Dalam/Luar Kota pada form.
    document.querySelectorAll('#formZone .zona-cat').forEach(btn => {
      btn.addEventListener('click', () => this._setZoneCategory(btn.dataset.cat));
    });
  }

  _setZoneCategory(cat) {
    const v = cat === 'luar' ? 'luar' : 'dalam';
    const input = document.getElementById('zoneCategory');
    if (input) input.value = v;
    document.querySelectorAll('#formZone .zona-cat').forEach(b =>
      b.classList.toggle('active', b.dataset.cat === v)
    );
  }

  // Filter + sort + render baris tabel zona.
  _renderZoneRows() {
    const tbody = document.getElementById('zonesTable');
    if (!tbody || !this._zones) return;
    const q = this._zoneQuery || '';
    const { key, dir } = this._zoneSort;

    document.querySelectorAll('#view-zones .sort-th').forEach(th => {
      th.classList.remove('sort-asc', 'sort-desc');
      if (th.dataset.sort === key) th.classList.add(dir === 'asc' ? 'sort-asc' : 'sort-desc');
    });

    const rows = this._zones
      .filter(z => (z.name || '').toLowerCase().includes(q))
      .sort((a, b) => {
        if (key === 'price') {
          const d = (Number(a.price) || 0) - (Number(b.price) || 0);
          return dir === 'asc' ? d : -d;
        }
        const c = (a.name || '').toLowerCase().localeCompare((b.name || '').toLowerCase());
        return dir === 'asc' ? c : -c;
      });

    const countEl = document.getElementById('zoneCount');
    if (countEl) countEl.textContent = rows.length;

    if (!rows.length) {
      tbody.innerHTML = `<tr><td colspan="3" class="py-10 text-center text-sm text-gray-400 dark:text-gray-500">${q ? `Tidak ada zona cocok dengan "${this._esc(q)}"` : 'Belum ada zona.'}</td></tr>`;
      return;
    }

    // Render per-grup (Dalam/Luar Kota) ala poster tarif — sort & search
    // tetap berlaku di dalam masing-masing grup.
    const rowHtml = (z, i) => `<tr style="animation-delay:${Math.min(i * 28, 340)}ms">
      <td class="py-3 px-5">
        <div class="font-medium text-gray-800 dark:text-gray-100">${this._esc(z.name)}</div>
        ${z.description ? `<div class="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5">${this._esc(z.description)}</div>` : ''}
      </td>
      <td class="py-3 px-5 text-right font-semibold text-gray-700 dark:text-gray-200 tabular-nums">${Utils.formatCurrency(z.price)}</td>
      <td class="py-3 px-5">
        <div class="flex items-center justify-center gap-1.5">
          <button class="zona-act zona-edit" title="Edit" data-edit-zone='${this._attr(JSON.stringify(z))}'><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg></button>
          <button class="zona-act zona-del" title="Hapus" data-del-zone="${z.id}"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
        </div>
      </td>
    </tr>`;

    const groups = [
      { key: 'dalam', cls: 'g-dalam', label: 'Dalam Kota Samarinda',
        icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>' },
      { key: 'luar', cls: 'g-luar', label: 'Luar Kota Samarinda',
        icon: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>' },
    ];

    let html = '';
    let idx = 0;
    groups.forEach(g => {
      const list = rows.filter(z => (z.category === 'luar' ? 'luar' : 'dalam') === g.key);
      if (!list.length) return;
      html += `<tr class="zona-group ${g.cls}" style="animation-delay:${Math.min(idx * 28, 340)}ms"><td colspan="3">${g.icon}${g.label} &middot; ${list.length}</td></tr>`;
      idx++;
      list.forEach(z => { html += rowHtml(z, idx); idx++; });
    });
    tbody.innerHTML = html;

    tbody.querySelectorAll('[data-edit-zone]').forEach(btn => {
      btn.addEventListener('click', () => {
        const z = JSON.parse(btn.dataset.editZone);
        document.getElementById('zoneId').value = z.id;
        document.getElementById('zoneName').value = z.name;
        document.getElementById('zonePrice').value = z.price;
        this._setZoneCategory(z.category);
        const desc = document.getElementById('zoneDescription');
        if (desc) desc.value = z.description || '';
        const mode = document.getElementById('zoneFormMode');
        if (mode) mode.textContent = `Mengedit zona: ${z.name}`;
        const st = document.getElementById('zoneSubmitText');
        if (st) st.textContent = 'Perbarui';
        document.getElementById('zoneName').focus();
      });
    });
    tbody.querySelectorAll('[data-del-zone]').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Hapus zona tujuan ini?')) return;
        try {
          await fetchApi(`/admin/zones/${btn.dataset.delZone}`, { method: 'DELETE' });
          await this.renderZones();
        } catch (error) {
          alert(error.message || 'Gagal menghapus zona. Silakan coba lagi.');
        }
      });
    });
  }

  _resetZoneForm() {
    const f = document.getElementById('formZone');
    if (f) f.reset();
    const zid = document.getElementById('zoneId');
    if (zid) zid.value = '';
    this._setZoneCategory('dalam');
    const mode = document.getElementById('zoneFormMode');
    if (mode) mode.textContent = 'Tambah zona & tarif baru';
    const st = document.getElementById('zoneSubmitText');
    if (st) st.textContent = 'Simpan';
  }

  _esc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  _attr(s) {
    return String(s).replace(/'/g, '&#39;');
  }

  // Render kontrol pagination (prev/next) di bawah sebuah tabel.
  _renderPager(pagerId, tbodyEl, meta, onGo) {
    const wrap = tbodyEl.closest('table')?.parentElement;
    if (!wrap) return;
    let pager = document.getElementById(pagerId);
    if (!pager) {
      pager = document.createElement('div');
      pager.id = pagerId;
      pager.className = 'flex items-center justify-between gap-3 mt-3 px-1 text-xs text-slate-500 dark:text-slate-400';
      wrap.insertAdjacentElement('afterend', pager);
    }
    const cur = meta.current_page || 1, last = meta.last_page || 1, total = meta.total ?? 0;
    const btn = (dir, label, disabled) => `<button data-pg="${dir}" class="px-3 py-1 rounded-lg border border-slate-200 dark:border-white/10 ${disabled ? 'opacity-40 cursor-not-allowed' : 'hover:bg-slate-100 dark:hover:bg-white/10'}" ${disabled ? 'disabled' : ''}>${label}</button>`;
    pager.innerHTML = `<span>Total ${total} • Halaman ${cur} / ${last}</span><span class="flex gap-2">${btn('prev', '‹ Sebelumnya', cur <= 1)}${btn('next', 'Berikutnya ›', cur >= last)}</span>`;
    const prev = pager.querySelector('[data-pg="prev"]');
    const next = pager.querySelector('[data-pg="next"]');
    if (prev) prev.onclick = () => { if (cur > 1) onGo(cur - 1); };
    if (next) next.onclick = () => { if (cur < last) onGo(cur + 1); };
  }

  // ----- Users -----
  async renderUsers(page = 1) {
    try {
      const tbody = document.getElementById('usersTable');
      if (!tbody) return;
      const _paged = await fetchApi(`/admin/users?page=${page}`);
      const users = _paged.data || _paged;

      tbody.innerHTML = users.map(u => {
        const carInfo = u.role === 'driver'
          ? `<div class="text-xs text-slate-500">${u.driver_profile?.car_model || '-'} • ${u.driver_profile?.plate_number || '-'}</div>`
          : '';

        // EDIT ICON (Pencil)
        const editIcon = `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" /></svg>`;

        // DELETE ICON (Trash)
        const deleteIcon = `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>`;

        // TOGGLE AKTIF/NONAKTIF
        const isActive = u.active !== false && u.active !== 0;
        const powerIcon = `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 2a1 1 0 011 1v6a1 1 0 11-2 0V3a1 1 0 011-1zM5.05 6.05a1 1 0 010 1.414 5 5 0 107.9 0 1 1 0 111.414-1.414 7 7 0 11-10.728 0 1 1 0 011.414 0z" clip-rule="evenodd" /></svg>`;
        const toggleBtn = `<button class="p-2 ${isActive ? 'text-amber-600 hover:bg-amber-50' : 'text-emerald-600 hover:bg-emerald-50'} dark:hover:bg-slate-600 rounded-lg transition-colors" title="${isActive ? 'Nonaktifkan' : 'Aktifkan'}" data-toggle-u="${u.id}" data-active="${isActive}">${powerIcon}</button>`;
        const statusBadge = isActive ? '' : `<span class="ml-2 align-middle text-[10px] font-bold uppercase px-1.5 py-0.5 rounded bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300">Nonaktif</span>`;

        return `<tr class="border-t hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors ${isActive ? '' : 'opacity-60'}">
          <td class="py-3 px-3">
            <div class="font-medium text-slate-900 dark:text-slate-100">${u.name}${statusBadge}</div>
            ${carInfo.replace('text-slate-500', 'text-slate-500 dark:text-slate-400')}
          </td>
          <td class="py-3 px-3 capitalize text-slate-600 dark:text-slate-300">${u.role}</td>
          <td class="py-3 px-3 text-slate-600 dark:text-slate-300">${u.username}</td>
          <td class="py-3 px-3">
            <div class="flex items-center gap-2">
                ${toggleBtn}
                <button class="p-2 text-blue-600 hover:bg-blue-50 dark:hover:bg-slate-600 rounded-lg transition-colors" title="Edit" data-edit-u='${JSON.stringify(u)}'>
                    ${editIcon}
                </button>
                <button class="p-2 text-red-600 hover:bg-red-50 dark:hover:bg-slate-600 rounded-lg transition-colors" title="Hapus" data-del-u="${u.id}" data-name="${u.name}">
                    ${deleteIcon}
                </button>
            </div>
          </td>
        </tr>`;
      }).join('');

      this._renderPager('usersPager', tbody, _paged, (p) => this.renderUsers(p));

      // --- Event Listener untuk Tombol Edit ---
      tbody.querySelectorAll('[data-edit-u]').forEach(btn => {
        btn.addEventListener('click', () => {
          const userData = JSON.parse(btn.dataset.editU);
          this.openUserForm(userData);
        });
      });

      // --- Event Listener untuk Tombol Aktif/Nonaktif ---
      tbody.querySelectorAll('[data-toggle-u]').forEach(btn => {
        btn.addEventListener('click', async () => {
          const id = btn.dataset.toggleU;
          const isActive = btn.dataset.active === 'true';
          if (!confirm(isActive
            ? 'Nonaktifkan pengguna ini? Sesi login & antriannya akan dihentikan.'
            : 'Aktifkan kembali pengguna ini?')) return;
          try {
            await fetchApi(`/admin/users/${id}/toggle-status`, { method: 'POST' });
            await this.renderUsers();
            if (this.populateFilterDropdowns) await this.populateFilterDropdowns();
          } catch (e) {
            console.error(e);
            alert('Gagal mengubah status pengguna.');
          }
        });
      });

      // --- Event Listener untuk Tombol Delete (MODAL CUSTOM) ---
      let deleteTargetId = null;
      let deleteTargetName = null;

      const modalConfirmDelete = document.getElementById('modalConfirmDelete');
      const btnCancelDelete = document.getElementById('btnCancelDelete');
      const btnConfirmDelete = document.getElementById('btnConfirmDelete');
      const msgConfirmDelete = document.getElementById('msgConfirmDelete');

      // Helper untuk menutup modal
      const closeDeleteModal = () => {
        modalConfirmDelete.classList.add('hidden');
        modalConfirmDelete.classList.remove('flex');
        deleteTargetId = null;
      };

      // Listener tombol 'Batal' di modal (Hanya pasang sekali, idealnya di initCommon, tapi di sini oke jika dicek duplikasi)
      // Agar aman dari duplikasi listener jika renderUsers dipanggil berkali-kali, 
      // kita gunakan 'onclick' property atau pastikan listener di-remove. 
      // Cara paling aman dlm konteks ini: pasang ulang dengan replace elemen atau cek if listener exists (susah di JS native).
      // KITA PINDAHKAN LISTENER MODAL KE LUAR LOOP renderUsers agar tidak double!

      // HAPUS LISTENER LAMA (JIKA ADA) DENGAN CARA CLONE ATAU ASSIGN ONCLICK LANGSUNG
      if (btnCancelDelete) btnCancelDelete.onclick = closeDeleteModal;

      if (btnConfirmDelete) btnConfirmDelete.onclick = async () => {
        if (!deleteTargetId) return;

        // Efek Loading
        btnConfirmDelete.textContent = 'Menghapus...';
        btnConfirmDelete.disabled = true;

        try {
          // PANGGIL API DELETE
          await fetchApi(`/admin/users/${deleteTargetId}`, {
            method: 'DELETE',
          });

          // Tutup Modal & Refresh
          closeDeleteModal();
          alert(`Pengguna "${deleteTargetName}" berhasil dihapus.`);
          await this.renderUsers();
          await this.populateFilterDropdowns();

        } catch (error) {
          console.error('Gagal menghapus pengguna:', error);
          alert(error.message || 'Gagal menghapus pengguna.');
          closeDeleteModal();
        } finally {
          btnConfirmDelete.textContent = 'Ya, Hapus';
          btnConfirmDelete.disabled = false;
        }
      };


      tbody.querySelectorAll('[data-del-u]').forEach(btn => {
        btn.addEventListener('click', () => {
          deleteTargetId = btn.dataset.delU;
          deleteTargetName = btn.dataset.name;

          // Set Pesan
          if (msgConfirmDelete) {
            msgConfirmDelete.innerHTML = `Anda yakin ingin menghapus pengguna <span class="font-bold text-slate-800">${deleteTargetName}</span>?<br>Tindakan ini permanen.`;
          }

          // Buka Modal
          if (modalConfirmDelete) {
            modalConfirmDelete.classList.remove('hidden');
            modalConfirmDelete.classList.add('flex');
          }
        });
      });

    } catch (error) {
      console.error("Gagal memuat data pengguna:", error);
      document.getElementById('usersTable').innerHTML = '<tr><td colspan="5">Gagal memuat data pengguna.</td></tr>';
    }

  }
  openUserForm(data) {
    try {
      const isEditing = data !== null;

      // Navigate to the user form "page"
      window.location.hash = '#user-form';

      document.getElementById('userFormTitle').textContent = isEditing ? 'Edit Akses Pengguna' : 'Tambah Pengguna Baru';

      // 4. SESUAIKAN PENGISIAN FORM DENGAN STRUKTUR DATA BARU
      const formUser = document.getElementById('formUser');
      if (formUser) formUser.reset();

      document.getElementById('userId').value = isEditing ? data.id : '';
      document.getElementById('userName').value = isEditing ? data.name : '';
      document.getElementById('userRole').value = isEditing ? data.role : 'cso';
      document.getElementById('userUsername').value = isEditing ? data.username : '';

      document.getElementById('userPassword').value = '';
      document.getElementById('userPassword').placeholder = isEditing ? 'Isi untuk mengubah password' : 'Password wajib diisi';

      document.getElementById('userCar').value = isEditing ? data.driver_profile?.car_model || '' : '';
      document.getElementById('userPlate').value = isEditing ? data.driver_profile?.plate_number || '' : '';

      const role = document.getElementById('userRole').value;
      const extra = document.getElementById('driverExtra');
      if (extra) extra.style.display = role === 'driver' ? 'grid' : 'none';

    } catch (error) {
      console.error("Crash during openUserForm:", error);
      alert("Terjadi error JS saat membuka formulir: " + error.message);
    }
  }

  closeUserForm() {
    window.location.hash = '#users';
  }


  // ----- Finance: Transaction Log -----
  // Kumpulkan filter transaksi saat ini → URLSearchParams (dipakai tabel & export).
  _txParams() {
    const p = new URLSearchParams();
    const g = (id) => (document.getElementById(id)?.value || '').trim();
    if (g('fltDateFrom')) p.append('date_from', g('fltDateFrom'));
    if (g('fltDateTo')) p.append('date_to', g('fltDateTo'));
    if (g('fltDriver')) p.append('driver_id', g('fltDriver'));
    if (g('fltCSO')) p.append('cso_id', g('fltCSO'));
    if (g('fltMethod')) p.append('method', g('fltMethod'));
    if (g('fltSearch')) p.append('search', g('fltSearch'));
    return p;
  }

  _exportTx(format) {
    window.open(`/admin/transactions/export/${format}?${this._txParams().toString()}`, '_blank');
  }

  _renderTxSummary(s) {
    const el = document.getElementById('txSummary');
    if (!el) return;
    if (!s) { el.innerHTML = ''; return; }
    const rp = (v) => Number(v || 0).toLocaleString('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 });
    const card = (label, val, hi) => `<div class="rounded-xl border px-3 py-2 ${hi ? 'bg-blue-600 border-blue-600' : 'bg-gray-50 border-gray-100 dark:bg-white/5 dark:border-white/10'}">
        <div class="text-[9px] uppercase tracking-wider ${hi ? 'text-blue-100' : 'text-gray-400'}">${label}</div>
        <div class="text-sm font-bold ${hi ? 'text-white' : 'text-slate-700 dark:text-slate-100'}">${val}</div>
      </div>`;
    el.innerHTML =
      card('Total Nilai', rp(s.total), true) +
      card('Transaksi', Number(s.count || 0).toLocaleString('id-ID')) +
      card('QRIS', rp(s.qris)) +
      card('Tunai Kasir', rp(s.cash_cso)) +
      card('Tunai Supir', rp(s.cash_driver));
  }

  async renderTxLog(page = 1) {
    const tbody = document.getElementById('txTable');
    if (!tbody) return;

    try {
      const params = this._txParams();
      params.append('page', page);
      const response = await fetchApi(`/admin/transactions?${params.toString()}`);
      const transactions = response.data || [];
      this._renderTxSummary(response.summary);

      if (transactions.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-6 text-slate-500">Tidak ada data transaksi yang cocok.</td></tr>';
        if (response.meta) this._renderPager('txPager', tbody, response.meta, (p) => this.renderTxLog(p));
        return;
      }

      // 4. Render HTML
      tbody.innerHTML = transactions.map(t => {
        // Ambil data dari t.booking, bukan langsung dari t
        const booking = t.booking || {};
        const cso = booking.cso || {};
        const driver = booking.driver || {};

        const csoName = cso?.name || '<span class="text-slate-400 italic dark:text-slate-500">Self/Driver</span>';
        const driverName = driver?.name || '-';

        // --- LOGIKA BARU PENENTUAN TUJUAN ---
        let destName = '<span class="text-red-400">?</span>';
        let destBadge = '';

        if (t.booking?.zone_to) {
          // Jika ada Zona (Order via CSO)
          destName = t.booking.zone_to.name;
        } else if (t.booking?.manual_destination) {
          // Jika Manual (Dapat Penumpang Sendiri)
          destName = t.booking.manual_destination;
          destBadge = '<span class="ml-1 text-[10px] bg-yellow-100 text-yellow-700 px-1.5 py-0.5 rounded border border-yellow-200">Manual</span>';
        }

        // -------------------------------------

        // --- LOGIKA BARU STATUS PENCAIRAN ---
        let payoutBadge = '';
        const pStatus = t.payout_status || 'Unpaid'; // Default Unpaid

        if (t.method === 'CashDriver') {
          // Logika Hutang Driver
          if (pStatus === 'Paid') {
            payoutBadge = `<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-green-100 text-green-700 border border-green-200">Lunas (Komisi Dibayar)</span>`;
          } else if (pStatus === 'Processing') {
            payoutBadge = `<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-yellow-100 text-yellow-700 border border-yellow-200">Proses Potong</span>`;
          } else {
            payoutBadge = `<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-red-100 text-red-700 border border-red-200">Belum Lunas</span>`;
          }
        } else {
          // Logika Pemasukan (QRIS/CashCSO)
          if (pStatus === 'Paid') {
            payoutBadge = `<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-700 border border-blue-200">Sudah Cair</span>`;
          } else if (pStatus === 'Processing') {
            payoutBadge = `<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-yellow-100 text-yellow-700 border border-yellow-200">Sedang Diproses</span>`;
          } else {
            payoutBadge = `<span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-500 border border-slate-200">Belum Dicairkan</span>`;
          }
        }
        // --- FORMAT RUPIAH (FIX) ---
        // Pastikan t.amount dikonversi ke Number dulu agar tidak error jika data string
        const formattedAmount = Number(t.amount).toLocaleString('id-ID', {
          style: 'currency',
          currency: 'IDR',
          minimumFractionDigits: 0
        });
        // ------------------------------------

        return `<tr class="border-t hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <td class="py-3 px-2 text-slate-600 dark:text-slate-300 text-xs">${new Date(t.created_at).toLocaleString('id-ID')}</td>
          <td class="py-3 px-2 font-medium text-xs dark:text-slate-200">${csoName}</td>
          <td class="py-3 px-2 text-xs dark:text-slate-200">${driverName}</td>
          <td class="py-3 px-2 text-slate-700 dark:text-slate-200 text-xs">${destName} ${destBadge}</td>
          <td class="py-3 px-2">
            <span class="px-2 py-1 rounded text-[10px] font-medium ${t.method === 'CashDriver' ? 'bg-orange-100 text-orange-800' : 'bg-purple-100 text-purple-800'}">
                ${t.method === 'CashDriver' ? 'Tunai (Supir)' : (t.method === 'CashCSO' ? 'Tunai (Kasir)' : t.method)}
            </span>
          </td>
          <td class="py-3 px-2">${payoutBadge}</td> <td class="py-3 px-2 font-mono text-right pr-4 font-bold text-slate-700 dark:text-slate-200 text-xs">
            ${formattedAmount}
          </td>
        </tr>`;
      }).join('');

      if (response.meta) this._renderPager('txPager', tbody, response.meta, (p) => this.renderTxLog(p));

    } catch (error) {
      console.error("Gagal memuat log transaksi:", error);
      tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-red-500">Gagal memuat data.</td></tr>';
    }
  }

  // ----- Withdrawals -----
  async renderWithdrawals(page = 1) {
    const tbody = document.getElementById('wdTable');
    if (!tbody) return;

    try {
      const _paged = await fetchApi(`/admin/withdrawals?page=${page}`);
      const withdrawals = _paged.data || _paged;

      if (withdrawals.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4">Tidak ada permintaan penarikan dana.</td></tr>';
        this._renderPager('wdPager', tbody, _paged, (p) => this.renderWithdrawals(p));
        return;
      }

      // 2. HAPUS FUNGSI MANUAL LOOKUP 'byName'
      // Nama supir kini bisa diakses langsung via w.driver.name
      tbody.innerHTML = withdrawals.map(w => {

        // --- BAGIAN INI YANG HILANG SEBELUMNYA (Definisi bankInfo) ---
        const bankInfo = w.driver && w.driver.driver_profile
          ? `<div class="text-xs font-bold text-slate-700 dark:text-slate-200">${w.driver.driver_profile.bank_name || '-'}</div>
               <div class="text-xs font-mono text-slate-500 dark:text-slate-400">${w.driver.driver_profile.account_number || '-'}</div>`
          : '<span class="text-xs text-red-500 italic">Belum set rekening</span>';
        // ---
        console.log("driver:", w);
        // Tampilkan Info Bank
        const currentStatus = w.status.toLowerCase();
        let actionButtons = '';
        // Tambahkan tombol DETAIL di semua status
        const btnDetail = `<button class="text-xs bg-slate-200 hover:bg-slate-300 text-slate-700 dark:bg-slate-600 dark:text-slate-200 dark:hover:bg-slate-500 px-2 py-1 rounded mr-1" onclick="window.openWdDetails(${w.id})">Detail</button>`;

        if (w.status === 'Pending') {
          actionButtons = `
                 <div class="flex items-center gap-1">
                     ${btnDetail}
                     <button class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded shadow" onclick="window.openPayModal(${w.id})">Bayar</button>
                     <button class="text-xs bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded shadow" data-wd-act="reject" data-id="${w.id}">Tolak</button>
                 </div>
             `;
        } else if (w.status === 'Approved') {
          let proofBtn = w.proof_image
            ? `<button class="text-xs text-blue-600 border border-blue-200 dark:text-blue-400 dark:border-blue-800 px-2 py-1 rounded hover:bg-blue-50 dark:hover:bg-slate-700 mx-1" onclick="window.open('/storage/${w.proof_image}', '_blank')">Bukti</button>`
            : '';

          let printBtn = `<button class="text-xs bg-slate-200 hover:bg-slate-300 text-slate-700 dark:bg-slate-600 dark:text-slate-200 dark:hover:bg-slate-500 px-2 py-1 rounded" onclick="window.open('/admin/withdrawals/${w.id}/export', '_blank')">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 inline mb-0.5" viewBox="0 0 20 20" fill="currentColor">
                 <path fill-rule="evenodd" d="M5 4v3H4a2 2 0 00-2 2v3a2 2 0 002 2h1v2a2 2 0 002 2h6a2 2 0 002-2v-2h1a2 2 0 002-2V9a2 2 0 00-2-2h-1V4a2 2 0 00-2-2H7a2 2 0 00-2 2zm8 0H7v3h6V4zm0 8H7v4h6v-4z" clip-rule="evenodd" />
                 PDF
              </svg>
          </button>`;

          actionButtons = `<div class="flex items-center gap-1">${btnDetail} <span class="text-xs text-emerald-600 font-bold ml-1">Selesai</span> ${proofBtn} ${printBtn}</div>`;
        } else {
          actionButtons = `<div class="flex items-center gap-1">${btnDetail} <span class="text-xs text-red-500 italic ml-1">Ditolak</span></div>`;
        }
        return `<tr class="border-t hover:bg-slate-50 dark:hover:bg-slate-700">
              <td class="py-2 align-top dark:text-slate-300">${new Date(w.requested_at).toLocaleString('id-ID')}</td>
              <td class="py-2 align-top">
                  <div class="font-medium dark:text-slate-200">${w.driver?.name || 'Supir Dihapus'}</div>
                  ${bankInfo}
              </td>
              <td class="py-2 align-top font-mono dark:text-slate-200">${parseInt(w.amount).toLocaleString('id-ID', { style: 'currency', currency: 'IDR' })}</td>
              <td class="py-2 align-top">${this.wdBadge(w.status)}</td>
              <td class="py-2 align-top">${actionButtons}</td>
          </tr>`;
      }).join('');

      this._renderPager('wdPager', tbody, _paged, (p) => this.renderWithdrawals(p));

      tbody.querySelectorAll('[data-wd-act="reject"]').forEach(btn => {
        btn.addEventListener('click', async () => {
          if (!confirm('Yakin ingin MENOLAK pencairan ini?')) return;
          try {
            const id = btn.dataset.id;
            await fetchApi(`/admin/withdrawals/${id}/reject`, { method: 'POST' });
            alert('Permintaan ditolak.');
            this.renderWithdrawals();
          } catch (e) { console.error(e); }
        });
      });

      // 3. UBAH EVENT LISTENER UNTUK MEMANGGIL API

    } catch (error) {
      console.error("Gagal memuat data withdrawal:", error);
      tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4">Gagal memuat data.</td></tr>';
    }
  }

  /** Rincian transaksi tunai yang tercakup dalam satu setoran. */
  async openDepDetails(id) {
    const modal = document.getElementById('modalDepDetails');
    const body  = document.getElementById('depDetailBody');
    if (!modal || !body) return;

    body.innerHTML = '<tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">Memuat...</td></tr>';
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    try {
      const res = await fetchApi(`/admin/cso-deposits/${id}/details`);
      const { deposit, transactions } = res;

      document.getElementById('depDetailSub').textContent =
        `${deposit.cso?.name || 'CSO'} • ${(deposit.period_dates || []).join(', ') || '-'}`;
      document.getElementById('depDetailTotal').textContent = Utils.formatCurrency(deposit.amount);

      body.innerHTML = transactions.length
        ? transactions.map(t => `<tr>
              <td class="px-4 py-2 dark:text-slate-300">${new Date(t.created_at).toLocaleString('id-ID')}</td>
              <td class="px-4 py-2 dark:text-slate-300">${t.booking?.zone_to?.name || t.booking?.manual_destination || '-'}</td>
              <td class="px-4 py-2 dark:text-slate-300">${t.booking?.driver?.name || '-'}</td>
              <td class="px-4 py-2 text-right font-mono dark:text-slate-200">${Utils.formatCurrency(t.amount)}</td>
            </tr>`).join('')
        : '<tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">Tidak ada transaksi.</td></tr>';
    } catch (e) {
      body.innerHTML = '<tr><td colspan="4" class="px-4 py-6 text-center text-red-500">Gagal memuat rincian.</td></tr>';
    }
  }

  // ----- Setoran Tunai CSO -----
  async renderCsoDeposits(page = 1) {
    const tbody = document.getElementById('depTable');
    if (!tbody) return;

    // Filter status dipasang sekali; defaultnya 'Pending' karena itulah yang
    // butuh tindakan admin.
    const sel = document.getElementById('depFilterStatus');
    if (sel && !sel.dataset.bound) {
      sel.dataset.bound = '1';
      sel.addEventListener('change', () => this.renderCsoDeposits());
    }
    const status = sel ? sel.value : 'Pending';

    try {
      const _paged = await fetchApi(`/admin/cso-deposits?page=${page}${status ? `&status=${status}` : ''}`);
      const rows = _paged.data || _paged;

      if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-slate-500">Tidak ada setoran pada filter ini.</td></tr>';
        this._renderPager('depPager', tbody, _paged, (p) => this.renderCsoDeposits(p));
        return;
      }

      tbody.innerHTML = rows.map(d => {
        const tanggal = (d.period_dates || []);
        // Tanggal yang dicakup bisa banyak — tampilkan ringkas, selebihnya
        // dihitung, supaya baris tabel tidak meledak.
        const tglRingkas = tanggal.length > 3
          ? `${tanggal.slice(0, 3).join(', ')} <span class="text-slate-400">+${tanggal.length - 3} lagi</span>`
          : (tanggal.join(', ') || '-');

        const btnDetail = `<button class="text-xs bg-slate-200 hover:bg-slate-300 text-slate-700 dark:bg-slate-600 dark:text-slate-200 dark:hover:bg-slate-500 px-2 py-1 rounded" onclick="window.openDepDetails(${d.id})">Detail</button>`;
        const btnBukti = d.proof_image
          ? `<button class="text-xs text-blue-600 border border-blue-200 dark:text-blue-400 dark:border-blue-800 px-2 py-1 rounded hover:bg-blue-50 dark:hover:bg-slate-700" onclick="window.open('/storage/${d.proof_image}', '_blank')">Bukti</button>`
          : '';

        let aksi;
        if (d.status === 'Pending') {
          aksi = `<div class="flex items-center justify-end gap-1">${btnDetail}${btnBukti}
              <button class="text-xs bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1 rounded shadow" data-dep-act="approve" data-id="${d.id}">Terima</button>
              <button class="text-xs bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded shadow" data-dep-act="reject" data-id="${d.id}">Tolak</button>
            </div>`;
        } else if (d.status === 'Approved') {
          aksi = `<div class="flex items-center justify-end gap-1">${btnDetail}${btnBukti}<span class="text-xs text-emerald-600 font-bold ml-1">Diterima</span></div>`;
        } else {
          aksi = `<div class="flex items-center justify-end gap-1">${btnDetail}<span class="text-xs text-red-500 italic ml-1" title="${(d.admin_note || '').replace(/"/g, '&quot;')}">Ditolak</span></div>`;
        }

        const catatan = d.status === 'Rejected' && d.admin_note
          ? `<div class="text-[11px] text-red-500 mt-0.5">${d.admin_note}</div>` : '';

        return `<tr class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
            <td class="py-3 px-5 align-top dark:text-slate-300">${d.submitted_at ? new Date(d.submitted_at).toLocaleString('id-ID') : '-'}</td>
            <td class="py-3 px-5 align-top">
              <div class="font-medium dark:text-slate-200">${d.cso?.name || 'CSO Dihapus'}</div>
              <div class="text-xs text-slate-500 dark:text-slate-400">${d.transactions_count} transaksi</div>
            </td>
            <td class="py-3 px-5 align-top text-xs dark:text-slate-300">${tglRingkas}</td>
            <td class="py-3 px-5 align-top text-right font-mono dark:text-slate-200">${Utils.formatCurrency(d.amount)}</td>
            <td class="py-3 px-5 align-top text-center">${this.wdBadge(d.status)}${catatan}</td>
            <td class="py-3 px-5 align-top text-right">${aksi}</td>
          </tr>`;
      }).join('');

      this._renderPager('depPager', tbody, _paged, (p) => this.renderCsoDeposits(p));

      tbody.querySelectorAll('[data-dep-act="approve"]').forEach(btn => {
        btn.addEventListener('click', async () => {
          if (!confirm('Konfirmasi bahwa uang tunai sudah Anda TERIMA dari CSO?')) return;
          await fetchApi(`/admin/cso-deposits/${btn.dataset.id}/approve`, { method: 'POST', body: JSON.stringify({}) });
          alert('Setoran diterima.');
          this.renderCsoDeposits();
        });
      });

      tbody.querySelectorAll('[data-dep-act="reject"]').forEach(btn => {
        btn.addEventListener('click', async () => {
          // Alasan WAJIB — CSO harus tahu apa yang perlu diperbaiki, dan
          // server pun menolak permintaan tanpa alasan.
          const alasan = prompt('Alasan penolakan (wajib, akan dikirim ke CSO):');
          if (alasan === null) return;
          if (!alasan.trim()) { alert('Alasan tidak boleh kosong.'); return; }
          await fetchApi(`/admin/cso-deposits/${btn.dataset.id}/reject`, {
            method: 'POST',
            body: JSON.stringify({ admin_note: alasan.trim() }),
          });
          alert('Setoran ditolak, tagihan dikembalikan ke CSO.');
          this.renderCsoDeposits();
        });
      });
    } catch (error) {
      console.error('Gagal memuat setoran CSO:', error);
      tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4">Gagal memuat data.</td></tr>';
    }
  }

  // Fungsi wdBadge tidak perlu diubah, karena ini hanya helper untuk styling
  wdBadge(s) {
    const status = s.toLowerCase();
    let cls = 'bg-slate-100 text-slate-600';
    if (status === 'pending') cls = 'bg-yellow-100 text-yellow-800 border border-yellow-200';
    if (status === 'approved') cls = 'bg-emerald-100 text-emerald-800 border border-emerald-200';
    if (status === 'rejected') cls = 'bg-red-100 text-red-800 border border-red-200';

    return `<span class="px-2 py-1 rounded-full text-[10px] font-bold uppercase tracking-wide ${cls}">${s}</span>`;
  }
  // ----- Reports -----
  async renderRevReport() {
    const monthEl = document.getElementById('repRevMonth');
    if (!monthEl) return;

    // Default: bulan berjalan → laporan langsung tampil, tidak kosong.
    if (!monthEl.value) {
      const d = new Date();
      monthEl.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    }

    try {
      const r = await fetchApi(`/admin/reports/revenue?month=${monthEl.value}`);
      const rp = (n) => 'Rp ' + Math.round(Number(n) || 0).toLocaleString('id-ID');
      const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };

      set('repRevCashCSO', rp(r.cash_cso));
      set('repRevCashDriver', rp(r.cash_driver));
      set('repRevQris', rp(r.qris));
      set('repRevFee', rp(r.fee));
      set('repRevTotal', rp(r.total));

      // Label potongan mengikuti rate komisi aktual (bukan "15%" statis).
      if (r.fee_rate != null) set('repRevFeeLabel', `Potongan Sistem ${Math.round(r.fee_rate * 100)}%`);

      document.getElementById('repRevResult')?.classList.remove('hidden');
    } catch (error) {
      console.error("Gagal memuat laporan pendapatan:", error);
    }
  }

  // Fungsi getWeek() dan sumWeek() tidak lagi diperlukan dan bisa dihapus.

  // ----- Reports: Driver Performance -----
  async renderDriverReport() { // <-- Jadikan async
    const tbody = document.getElementById('driverPerfTable');
    if (!tbody) return;

    try {
      const sortBy = document.getElementById('driverRankBy')?.value || 'trips';

      // 1. PANGGIL API UNTUK MENDAPATKAN LAPORAN KINERJA YANG SUDAH JADI DAN TERURUT
      const reportData = await fetchApi(`/admin/reports/driver-performance?sort_by=${sortBy}`);

      if (reportData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4">Tidak ada data kinerja supir.</td></tr>';
        return;
      }

      // 2. SEMUA LOGIKA .map, .filter, .reduce, .sort DIHAPUS.
      // Langsung render data yang sudah matang dari API.
      tbody.innerHTML = reportData.map(driver => {
        const avg = driver.avg_rating != null ? Number(driver.avg_rating) : null;
        const cnt = driver.rating_count || 0;
        const rating = avg
          ? `<span class="text-amber-500 font-bold">★ ${avg.toFixed(1)}</span> <span class="text-xs text-slate-400">(${cnt})</span>`
          : '<span class="text-xs text-slate-400">—</span>';
        return `
      <tr class="border-t hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
        <td class="py-3 px-5 font-medium dark:text-slate-200">${AdminApp.escapeHtml(driver.name)}</td>
        <td class="py-3 px-5 text-center dark:text-slate-300">${driver.trips}</td>
        <td class="py-3 px-5 text-right dark:text-slate-300">${(driver.revenue || 0).toLocaleString('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 })}</td>
        <td class="py-3 px-5 text-center">${rating}</td>
      </tr>`;
      }).join('');

    } catch (error) {
      console.error("Gagal memuat laporan kinerja supir:", error);
      tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4">Gagal memuat data.</td></tr>';
    }
  }

  // ----- Halaman Pengaturan: tab, pratinjau langsung, penanda belum tersimpan -----

  /** Ikon mata (buka/tutup) untuk tombol tampilkan-kata-sandi. */
  static eyeIcon(open) {
    const d = open
      ? 'M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21'
      : 'M15 12a3 3 0 11-6 0 3 3 0 016 0zM2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z';
    return `<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${d}"></path></svg>`;
  }

  /**
   * Pasang seluruh perilaku halaman Pengaturan. Dipanggil sekali dari
   * initCommon(). Semua id isian tetap sama seperti sebelumnya — yang berubah
   * hanya tata letak dan lapisan interaksinya.
   */
  initSettingsUi() {
    const $ = (id) => document.getElementById(id);

    // --- Tombol mata: satu handler tergedelasi menggantikan empat blok
    // onclick raksasa yang dulu ditulis ulang di setiap input. ---
    document.querySelectorAll('[data-toggle-password]').forEach(btn => {
      btn.innerHTML = AdminApp.eyeIcon(false);
      btn.addEventListener('click', () => {
        const input = $(btn.getAttribute('data-toggle-password'));
        if (!input) return;
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.innerHTML = AdminApp.eyeIcon(show);
        btn.setAttribute('aria-label', show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
      });
    });

    // --- Tab ---
    const tabs = $('settingsTabs');
    tabs?.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-stab]');
      if (btn) this.showSettingsTab(btn.getAttribute('data-stab'));
    });
    this.showSettingsTab('umum');

    // --- Penanda "belum disimpan" ---
    const form = $('formSettings');
    form?.addEventListener('input', () => this.markSettingsDirty(true));
    form?.addEventListener('change', () => this.markSettingsDirty(true));
    $('btnSettingsReset')?.addEventListener('click', () => {
      this.renderSettings();               // ambil ulang nilai tersimpan
      this.markSettingsDirty(false);
      Utils.showToast('Perubahan dibatalkan.');
    });

    // --- Pratinjau langsung ---
    $('commissionRate')?.addEventListener('input', () => this.renderCommissionPreview());
    ['operatingStart', 'operatingEnd'].forEach(id =>
      $(id)?.addEventListener('input', () => this.renderOperatingPreview()));
    $('outOfAreaGraceMinutes')?.addEventListener('input', () => this.renderOutOfAreaGracePreview());
    ['newPassword', 'confirmNewPassword'].forEach(id =>
      $(id)?.addEventListener('input', () => this.renderPasswordFeedback()));

    // --- Unggah QRIS: pratinjau seketika + seret-lepas ---
    const file = $('companyQris');
    const drop = $('qrisDrop');
    const showPicked = (f) => {
      if (!f) return;
      const name = $('qrisFileName');
      if (name) { name.textContent = f.name; name.classList.remove('hidden'); }
      const img = $('previewQris');
      if (img) {
        img.src = URL.createObjectURL(f);
        img.classList.remove('hidden');
        $('placeholderQris')?.classList.add('hidden');
      }
      this.markSettingsDirty(true);
    };
    file?.addEventListener('change', () => showPicked(file.files[0]));
    if (drop && file) {
      ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, (e) => {
        e.preventDefault(); drop.classList.add('dragover');
      }));
      ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, (e) => {
        e.preventDefault(); drop.classList.remove('dragover');
      }));
      drop.addEventListener('drop', (e) => {
        const f = e.dataTransfer?.files?.[0];
        if (!f || !f.type.startsWith('image/')) return;
        // DataTransfer dipakai agar berkas hasil seret benar-benar masuk ke
        // input file — jalur simpan yang lama membaca dari fileInput.files[0].
        const dt = new DataTransfer();
        dt.items.add(f);
        file.files = dt.files;
        showPicked(f);
      });
    }
  }

  /** Tampilkan satu tab Pengaturan, sembunyikan sisanya. */
  showSettingsTab(name) {
    document.querySelectorAll('#settingsTabs [data-stab]').forEach(b =>
      b.classList.toggle('active', b.getAttribute('data-stab') === name));
    document.querySelectorAll('[data-spanel]').forEach(p =>
      p.classList.toggle('hidden', p.getAttribute('data-spanel') !== name));

    // Bilah simpan hanya relevan untuk formSettings; tab Keamanan punya tombol
    // sendiri dan formnya terpisah.
    document.getElementById('settingsSaveBar')?.classList.toggle('hidden', name === 'keamanan');

    // Leaflet menghitung ukuran 0 selama panelnya tersembunyi → gambar ulang
    // begitu tab Area benar-benar terlihat.
    if (name === 'area') this.renderAirportCenterMap();
  }

  /** Nyalakan/matikan penanda perubahan belum tersimpan. */
  markSettingsDirty(dirty) {
    this._settingsDirty = dirty;
    document.getElementById('settingsDirtyChip')?.classList.toggle('hidden', !dirty);
    document.getElementById('settingsDirtyChip')?.classList.toggle('flex', dirty);
    document.getElementById('btnSettingsReset')?.classList.toggle('hidden', !dirty);
    const note = document.getElementById('settingsSaveNote');
    if (note) {
      note.textContent = dirty
        ? 'Perubahan belum dikirim ke server.'
        : 'Semua perubahan tersimpan.';
      note.classList.toggle('text-amber-600', dirty);
      note.classList.toggle('dark:text-amber-400', dirty);
    }
  }

  /** Simulasi pembagian komisi dari tarif contoh Rp100.000. */
  renderCommissionPreview() {
    const bar = document.getElementById('commissionBarCoop');
    if (!bar) return;
    const raw = parseFloat(document.getElementById('commissionRate')?.value);
    const pct = Math.min(100, Math.max(0, Number.isFinite(raw) ? raw : 0));
    const coop = 100000 * pct / 100;

    bar.style.width = pct + '%';
    const driverBar = document.getElementById('commissionBarDriver');
    if (driverBar) driverBar.style.width = (100 - pct) + '%';
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    set('commissionCoop', Utils.formatCurrency(coop));
    set('commissionDriver', Utils.formatCurrency(100000 - coop));
  }

  /** Ringkasan durasi jendela pelacakan lokasi. */
  renderOperatingPreview() {
    const el = document.getElementById('operatingPreview');
    if (!el) return;
    const s = document.getElementById('operatingStart')?.value;
    const e = document.getElementById('operatingEnd')?.value;
    if (!s || !e) { el.textContent = '—'; return; }

    const toMin = (t) => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
    const a = toMin(s), b = toMin(e);
    if (a === b) { el.textContent = '24 jam nonstop'; return; }
    // Jendela boleh melewati tengah malam (mis. 22:00–06:00).
    const span = (b - a + 1440) % 1440;
    el.textContent = `${Math.floor(span / 60)} jam ${span % 60} menit`;
  }

  /** Ringkasan tenggang supir di luar area sebelum antriannya hangus. */
  renderOutOfAreaGracePreview() {
    const el = document.getElementById('outOfAreaGracePreview');
    if (!el) return;
    const raw = document.getElementById('outOfAreaGraceMinutes')?.value;
    if (raw === undefined || raw.trim() === '') { el.textContent = '—'; return; }

    const m = parseInt(raw, 10);
    if (!Number.isFinite(m) || m < 0) { el.textContent = '—'; return; }
    if (m === 0) { el.textContent = 'Auto-keluar nonaktif'; return; }
    if (m < 60) { el.textContent = `${m} menit`; return; }

    const jam = Math.floor(m / 60), menit = m % 60;
    el.textContent = menit ? `${jam} jam ${menit} menit` : `${jam} jam`;
  }

  /** Kekuatan kata sandi baru + kecocokan dengan ulangannya. */
  renderPasswordFeedback() {
    const pw = document.getElementById('newPassword')?.value || '';
    const cf = document.getElementById('confirmNewPassword')?.value || '';

    const bar = document.getElementById('pwStrengthBar');
    const txt = document.getElementById('pwStrengthText');
    if (bar && txt) {
      let score = 0;
      if (pw.length >= 6) score++;
      if (pw.length >= 10) score++;
      if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
      if (/\d/.test(pw)) score++;
      if (/[^A-Za-z0-9]/.test(pw)) score++;
      const level = pw.length === 0 ? -1 : Math.min(3, Math.floor(score * 3 / 5));
      const gaya = [
        ['20%', 'bg-red-500', 'Lemah — tambah panjang & variasi karakter'],
        ['50%', 'bg-amber-500', 'Sedang'],
        ['75%', 'bg-lime-500', 'Cukup kuat'],
        ['100%', 'bg-emerald-500', 'Kuat'],
      ];
      bar.className = 'h-full transition-all duration-300 ' + (level < 0 ? 'bg-gray-300' : gaya[level][1]);
      bar.style.width = level < 0 ? '0%' : gaya[level][0];
      txt.textContent = level < 0 ? ' ' : gaya[level][2];
    }

    const m = document.getElementById('pwMatch');
    if (m) {
      if (!cf) { m.textContent = ' '; m.className = 'sthint mt-1'; }
      else if (pw === cf) { m.textContent = '✓ Kata sandi cocok'; m.className = 'text-[11px] mt-1 font-semibold text-emerald-600 dark:text-emerald-400'; }
      else { m.textContent = '✗ Belum cocok'; m.className = 'text-[11px] mt-1 font-semibold text-red-500'; }
    }
  }

  // ----- Performa CSO -----

  /** Lolos-kan teks buatan pengguna sebelum masuk ke innerHTML. */
  static escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  }

  /**
   * Tabel performa CSO untuk satu bulan. Semua agregasi & pengurutan dikerjakan
   * server (lihat adminGetCsoPerformanceReport) — di sini murni menggambar.
   */
  async renderCsoReport() {
    const tbody = document.getElementById('csoPerfTable');
    if (!tbody) return;

    const monthEl = document.getElementById('csoPerfMonth');
    // Kunjungan pertama: isi dengan bulan berjalan supaya tidak mengirim kosong.
    if (monthEl && !monthEl.value) monthEl.value = new Date().toISOString().slice(0, 7);

    const esc = AdminApp.escapeHtml;
    const rupiah = (n) => (Number(n) || 0).toLocaleString('id-ID', {
      style: 'currency', currency: 'IDR', minimumFractionDigits: 0,
    });

    try {
      const month = monthEl?.value || '';
      const sortBy = document.getElementById('csoRankBy')?.value || 'orders';
      const data = await fetchApi(`/admin/reports/cso-performance?month=${encodeURIComponent(month)}&sort_by=${sortBy}`);

      const s = data.summary || {};
      const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
      set('csoSumOrders', s.orders ?? 0);
      set('csoSumRevenue', rupiah(s.revenue));
      set('csoSumCancelled', s.cancelled ?? 0);

      const rows = data.csos || [];
      if (rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4">Belum ada akun CSO terdaftar.</td></tr>';
        return;
      }

      tbody.innerHTML = rows.map(c => {
        const batal = c.cancelled > 0
          ? `<span class="text-red-500 font-semibold">${c.cancelled}</span> <span class="text-xs text-slate-400">(${c.cancel_rate}%)</span>`
          : '<span class="text-xs text-slate-400">—</span>';
        const terakhir = c.last_order_at
          ? new Date(c.last_order_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
          : '<span class="text-xs text-slate-400">Belum pernah</span>';
        return `
      <tr class="border-t hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
        <td class="py-3 px-5 font-medium dark:text-slate-200">${esc(c.name)}
          <span class="block text-[11px] text-slate-400 font-normal">${esc(c.username)}</span></td>
        <td class="py-3 px-5 text-center dark:text-slate-300">${c.orders}</td>
        <td class="py-3 px-5 text-center text-emerald-600 dark:text-emerald-400 font-semibold">${c.completed}</td>
        <td class="py-3 px-5 text-center">${batal}</td>
        <td class="py-3 px-5 text-right dark:text-slate-300">${rupiah(c.revenue)}</td>
        <td class="py-3 px-5 text-right dark:text-slate-300">${rupiah(c.avg_order_value)}</td>
        <td class="py-3 px-5 text-center dark:text-slate-300">${terakhir}</td>
      </tr>`;
      }).join('');

    } catch (error) {
      console.error('Gagal memuat performa CSO:', error);
      tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4">Gagal memuat data.</td></tr>';
    }
  }

  // ----- Pemilih Titik Pusat Area Bandara (Pengaturan) -----

  /** Koordinat bawaan bila setting & input sama-sama kosong. */
  static get DEFAULT_AIRPORT_CENTER() {
    return { lat: -0.371975, lng: 117.257919 };
  }

  /**
   * Peta kecil di menu Pengaturan untuk memilih pusat geofence dengan menggeser
   * pin. Dipanggil dari renderSettings() (saat data setting masuk) DAN dari
   * route() (saat tab Pengaturan dibuka) — Leaflet menghitung ukuran 0 di
   * kontainer tersembunyi, jadi perlu invalidateSize setiap tab ditampilkan.
   */
  renderAirportCenterMap() {
    const mapEl = document.getElementById('airportCenterMap');
    if (!mapEl || typeof L === 'undefined') return;

    const latEl = document.getElementById('airportLatitude');
    const lngEl = document.getElementById('airportLongitude');
    const def = AdminApp.DEFAULT_AIRPORT_CENTER;
    const num = (el, fallback) => {
      const v = parseFloat(el && el.value);
      return Number.isFinite(v) ? v : fallback;
    };
    const center = [num(latEl, def.lat), num(lngEl, def.lng)];

    if (!this._centerMap) {
      // Zoom awal sekadar nilai antara — fitBounds di bawah yang menentukan.
      this._centerMap = L.map(mapEl, { zoomControl: true }).setView(center, 13);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap',
      }).addTo(this._centerMap);

      // Lingkaran radius: warna sengaja disamakan dengan peta supir supaya
      // admin mengenalinya sebagai geofence yang sama.
      this._centerCircle = L.circle(center, {
        radius: this._airportRadiusMeters(),
        color: '#2563eb', weight: 2, fillColor: '#3b82f6', fillOpacity: 0.07,
      }).addTo(this._centerMap);

      // Sengaja tanpa tooltip: pin sudah jelas menandai pusat, dan kotak tooltip
      // di tengah peta justru menutupi area yang sedang diatur.
      this._centerMarker = L.marker(center, { draggable: true, autoPan: true })
        .addTo(this._centerMap);

      // Gagang radius: bulatan kecil di tepi lingkaran. Digeser menjauh/mendekat
      // dari pusat = radius membesar/mengecil.
      this._radiusHandle = L.marker(this._radiusHandleLatLng(center), {
        draggable: true,
        icon: L.divIcon({
          className: 'aj-radius-handle',
          html: '<div style="width:16px;height:16px;border-radius:50%;background:#2563eb;border:3px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4);cursor:ew-resize;"></div>',
          iconSize: [16, 16],
          iconAnchor: [8, 8],
        }),
      }).addTo(this._centerMap)
        .bindTooltip('Geser untuk mengubah radius', { direction: 'top', offset: [0, -12] });

      // --- Peta -> kotak angka ---
      this._centerMarker.on('drag', (e) => this._syncAirportCenter(e.target.getLatLng()));
      this._centerMap.on('click', (e) => {
        this._centerMarker.setLatLng(e.latlng);
        this._syncAirportCenter(e.latlng);
      });

      // --- Gagang radius -> lingkaran + nilai tersimpan ---
      this._radiusHandle.on('drag', (e) => {
        const c = this._centerMarker.getLatLng();
        this._setAirportRadius(this._centerMap.distance(c, e.target.getLatLng()));
      });
      // Setelah dilepas, kembalikan gagang ke sisi timur lingkaran supaya
      // posisinya selalu konsisten (dan radius hasil pembulatan terlihat pas).
      this._radiusHandle.on('dragend', () => {
        this._radiusHandle.setLatLng(this._radiusHandleLatLng(this._centerMarker.getLatLng()));
      });

      // --- Kotak angka -> peta ---
      const onTyped = () => {
        const lat = parseFloat(latEl && latEl.value);
        const lng = parseFloat(lngEl && lngEl.value);
        // Kosong / bukan angka / di luar rentang: diamkan saja. Admin mungkin
        // baru mengetik separuh angka — jangan lompatkan pin, jangan protes.
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
        if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return;
        const ll = L.latLng(lat, lng);
        this._centerMarker.setLatLng(ll);
        this._centerCircle.setLatLng(ll);
        this._radiusHandle.setLatLng(this._radiusHandleLatLng(ll));
        this._centerMap.panTo(ll);
      };
      latEl?.addEventListener('input', onTyped);
      lngEl?.addEventListener('input', onTyped);

      document.getElementById('btnResetAirportCenter')
        ?.addEventListener('click', () => {
          const saved = this._savedCenter || def;
          const ll = L.latLng(saved.lat, saved.lng);
          this._centerMarker.setLatLng(ll);
          if (Number.isFinite(this._savedRadiusKm)) {
            this._setAirportRadius(this._savedRadiusKm * 1000);
          }
          this._syncAirportCenter(ll);
          this._centerMap.fitBounds(this._centerCircle.getBounds(), { padding: [28, 28] });
          // Kembali ke nilai tersimpan = tidak ada lagi yang perlu disimpan.
          this.markSettingsDirty(false);
        });
    } else {
      // Render ulang (mis. data setting baru masuk): geser ke titik terbaru.
      this._centerMarker.setLatLng(center);
      this._centerCircle.setLatLng(center);
      this._centerCircle.setRadius(this._airportRadiusMeters());
      this._radiusHandle.setLatLng(this._radiusHandleLatLng(center));
      this._centerMap.setView(center, this._centerMap.getZoom());
    }

    this._renderRadiusLabel();

    // Peta mungkin dibuat saat container tersembunyi → perbaiki ukurannya, lalu
    // bingkai seluruh lingkaran. Zoom tetap TIDAK bisa dipakai: dengan radius
    // 2,6 km di panel setinggi 320px, tepi lingkaran beserta gagangnya jatuh di
    // luar kotak peta dan admin tidak bisa menggapainya. fitBounds membuatnya
    // selalu muat, baik radiusnya 0,1 km maupun 50 km.
    setTimeout(() => {
      if (!this._centerMap) return;
      this._centerMap.invalidateSize();
      this._centerMap.fitBounds(this._centerCircle.getBounds(), { padding: [28, 28] });
    }, 60);
  }

  /** Radius geofence saat ini dalam meter, dibaca dari input tersembunyi. */
  _airportRadiusMeters() {
    const km = parseFloat(document.getElementById('airportRadiusKm')?.value);
    return (Number.isFinite(km) && km > 0 ? km : 2.6) * 1000;
  }

  /**
   * Simpan radius baru (meter) → lingkaran, input tersembunyi, dan labelnya.
   * Dijepit ke rentang yang diterima server (0,1–50 km) supaya geseran ekstrem
   * tidak menghasilkan nilai yang nanti ditolak validasi saat disimpan.
   */
  _setAirportRadius(meters) {
    // Dibulatkan DULU ke 2 desimal, baru dipakai untuk lingkaran maupun input —
    // supaya yang terlihat, yang tersimpan, dan posisi gagang selalu satu angka
    // yang sama (tanpa ini lingkaran dan gagang meleset beberapa meter).
    const km = +Math.min(50, Math.max(0.1, meters / 1000)).toFixed(2);
    const el = document.getElementById('airportRadiusKm');
    if (el) el.value = km.toFixed(2);
    if (this._centerCircle) this._centerCircle.setRadius(km * 1000);
    this._renderRadiusLabel();
    this.markSettingsDirty(true); // lihat catatan di _syncAirportCenter
  }

  /** Tampilkan radius berjalan di samping peta. */
  _renderRadiusLabel() {
    const el = document.getElementById('airportRadiusLabel');
    if (!el) return;
    const km = parseFloat(document.getElementById('airportRadiusKm')?.value);
    el.textContent = Number.isFinite(km) ? `${km.toFixed(2).replace('.', ',')} km` : '—';
  }

  /**
   * Posisi gagang radius: tepat di sisi TIMUR lingkaran.
   *
   * Memakai rumus titik tujuan pada bola (bearing 90°) dengan jari-jari Bumi
   * yang SAMA dipakai Leaflet untuk map.distance(). Ini penting: radius dibaca
   * balik sebagai jarak gagang ke pusat, jadi kalau penempatannya memakai
   * pendekatan datar (111320·cos φ) hasilnya meleset ~0,2% dan radius akan
   * menyusut sedikit demi sedikit tiap kali admin memegang-melepas gagang.
   */
  _radiusHandleLatLng(center) {
    const c = L.latLng(center);
    const R = (L.CRS.Earth && L.CRS.Earth.R) || 6371000;
    const rad = Math.PI / 180, deg = 180 / Math.PI;
    const d = this._airportRadiusMeters() / R; // jarak sudut
    const lat1 = c.lat * rad;
    // Bearing 90° → cos θ = 0, sin θ = 1.
    const lat2 = Math.asin(Math.sin(lat1) * Math.cos(d));
    const lng2 = c.lng * rad + Math.atan2(
      Math.sin(d) * Math.cos(lat1),
      Math.cos(d) - Math.sin(lat1) * Math.sin(lat2),
    );
    return L.latLng(lat2 * deg, lng2 * deg);
  }

  /** Tulis posisi pin ke kotak angka + geser lingkaran & gagang mengikutinya. */
  _syncAirportCenter(latlng) {
    const latEl = document.getElementById('airportLatitude');
    const lngEl = document.getElementById('airportLongitude');
    // 6 desimal ≈ presisi 0,1 m — jauh lebih halus dari kebutuhan geofence.
    if (latEl) latEl.value = latlng.lat.toFixed(6);
    if (lngEl) lngEl.value = latlng.lng.toFixed(6);
    if (this._centerCircle) this._centerCircle.setLatLng(latlng);
    if (this._radiusHandle) this._radiusHandle.setLatLng(this._radiusHandleLatLng(latlng));
    // Menulis .value lewat skrip tidak memicu event 'input', jadi penanda
    // "belum disimpan" harus dinyalakan dari sini.
    this.markSettingsDirty(true);
  }

  // ----- Peta Supir + Rekap Keluar-Masuk Bandara -----
  async renderDriverMap() {
    const mapEl = document.getElementById('adminDriverMap');
    if (!mapEl || typeof L === 'undefined') return;

    let data;
    try {
      data = await fetchApi('/admin/driver-locations');
    } catch (e) {
      console.error('Gagal memuat peta supir:', e);
      return;
    }

    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const base = data.base || {};
    const drivers = data.drivers || [];
    const ranking = data.ranking || [];
    const centerLat = base.latitude || -0.371975;
    const centerLng = base.longitude || 117.257919;

    // Inisialisasi peta sekali saja.
    if (!this._driverMap) {
      this._driverMap = L.map(mapEl, { zoomControl: true }).setView([centerLat, centerLng], 13);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap',
      }).addTo(this._driverMap);
      this._driverMarkers = L.layerGroup().addTo(this._driverMap);
      // Sambungkan tombol "Lihat Rute" di dalam popup marker supir.
      this._driverMap.on('popupopen', (e) => {
        const el = e.popup.getElement();
        const btn = el && el.querySelector('[data-route]');
        if (btn) btn.onclick = () => this.showDriverRoute(btn.getAttribute('data-route'));
      });
    }
    // Titik pusat + lingkaran radius: DIPERBARUI tiap render, bukan sekali saat
    // peta dibuat — admin bisa mengubah keduanya di Pengaturan dan perubahannya
    // harus langsung terlihat tanpa reload halaman.
    const center = [centerLat, centerLng];
    if (this._airportCircle) {
      this._airportCircle.setLatLng(center);
      this._airportCircle.setRadius((base.radius_km || 0) * 1000);
    } else if (base.radius_km) {
      this._airportCircle = L.circle(center, {
        radius: base.radius_km * 1000,
        color: '#2563eb', weight: 2, fillColor: '#3b82f6', fillOpacity: 0.07,
      }).addTo(this._driverMap);
    }
    if (this._airportMarker) {
      this._airportMarker.setLatLng(center);
    } else {
      this._airportMarker = L.marker(center).addTo(this._driverMap)
        .bindPopup('<b>Titik Pusat Area Bandara</b>');
    }

    // Peta mungkin dibuat saat container tersembunyi → perbaiki ukurannya.
    setTimeout(() => { if (this._driverMap) this._driverMap.invalidateSize(); }, 60);

    // Refresh marker supir.
    this._driverMarkers.clearLayers();
    drivers.forEach(d => {
      const color = d.status === 'ontrip' ? '#0ea5e9' : (d.in_area ? '#22c55e' : '#94a3b8');
      const icon = L.divIcon({
        className: 'aj-driver-marker',
        html: `<div style="background:${color};width:30px;height:30px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:2.5px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;"><span style="transform:rotate(45deg);color:#fff;font-size:11px;font-weight:800;">${d.line_number ?? ''}</span></div>`,
        iconSize: [30, 30], iconAnchor: [15, 30], popupAnchor: [0, -30],
      });
      L.marker([d.latitude, d.longitude], { icon }).addTo(this._driverMarkers)
        .bindPopup(
          `<div style="min-width:180px;font-family:system-ui,sans-serif">
            <div style="font-weight:800;font-size:14px;color:#0f172a">${esc(d.name)}</div>
            <div style="font-size:12px;color:#64748b;margin:2px 0 7px">${esc(d.car_model || '-')} &bull; ${esc(d.plate_number || '-')}</div>
            <span style="display:inline-block;font-size:10px;font-weight:800;letter-spacing:.3px;padding:2px 9px;border-radius:12px;background:${color}22;color:${color}">${esc((d.status || '').toUpperCase())}</span>
            <div style="margin-top:9px;padding-top:8px;border-top:1px solid #eef2f7;display:flex;gap:14px;font-size:12px;color:#334155">
              <span>Masuk: <b style="color:#16a34a">${d.airport_entries}</b>x</span>
              <span>Keluar: <b style="color:#d97706">${d.airport_exits}</b>x</span>
            </div>
            <button data-route="${d.id}" style="margin-top:10px;width:100%;background:#7c3aed;color:#fff;border:none;border-radius:8px;padding:7px;font-size:11px;font-weight:700;cursor:pointer">Lihat Rute Hari Ini</button>
          </div>`
        );
    });

    // Statistik ringkas.
    const totEntries = ranking.reduce((s, r) => s + (r.entries || 0), 0);
    const totExits = ranking.reduce((s, r) => s + (r.exits || 0), 0);
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    set('mapStatOnMap', drivers.length);
    set('mapStatEntries', totEntries);
    set('mapStatExits', totExits);

    // Tabel rekap keluar-masuk (semua supir).
    const rankBody = document.getElementById('airportRankTable');
    if (rankBody) {
      rankBody.innerHTML = ranking.length
        ? ranking.map((r, i) => {
            const badge = r.in_area
              ? '<span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-green-100 text-green-700">DI AREA</span>'
              : '<span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">LUAR</span>';
            const line = r.line_number ? `<span class="text-xs text-slate-400 ml-1">#${esc(r.line_number)}</span>` : '';
            return `<tr class="hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors">
              <td class="py-3 px-5 text-center text-slate-400">${i + 1}</td>
              <td class="py-3 px-5 font-medium text-slate-700 dark:text-slate-200">${esc(r.name)}${line}</td>
              <td class="py-3 px-5 text-center font-bold text-green-600">${r.entries}</td>
              <td class="py-3 px-5 text-center font-bold text-amber-600">${r.exits}</td>
              <td class="py-3 px-5 text-center">${badge}</td>
            </tr>`;
          }).join('')
        : '<tr><td colspan="5" class="text-center py-6 text-slate-400">Belum ada data.</td></tr>';
    }
  }

  // ----- Rute pergerakan supir (polyline di peta) -----
  async showDriverRoute(id) {
    if (!this._driverMap) return;
    try {
      const res = await fetchApi('/admin/drivers/' + id + '/route');
      this.clearRoute();
      const pts = (res.points || []).map(p => [p.lat, p.lng]);
      if (pts.length < 2) {
        alert('Belum ada cukup jejak rute untuk supir ini hari ini.');
        return;
      }
      this._routeLayer = L.layerGroup().addTo(this._driverMap);
      L.polyline(pts, { color: '#7c3aed', weight: 4, opacity: 0.85 }).addTo(this._routeLayer);
      L.circleMarker(pts[0], { radius: 6, weight: 2, color: '#fff', fillColor: '#16a34a', fillOpacity: 1 })
        .bindPopup('Awal rute').addTo(this._routeLayer);
      L.circleMarker(pts[pts.length - 1], { radius: 6, weight: 2, color: '#fff', fillColor: '#dc2626', fillOpacity: 1 })
        .bindPopup('Posisi terakhir').addTo(this._routeLayer);
      this._driverMap.fitBounds(L.latLngBounds(pts), { padding: [40, 40] });
      document.getElementById('btnClearRoute')?.classList.remove('hidden');
    } catch (e) {
      console.error('Gagal memuat rute:', e);
      alert('Gagal memuat rute supir.');
    }
  }

  clearRoute() {
    if (this._routeLayer && this._driverMap) {
      this._driverMap.removeLayer(this._routeLayer);
      this._routeLayer = null;
    }
    document.getElementById('btnClearRoute')?.classList.add('hidden');
  }

  // ----- API Integrasi (Management API keys) -----
  async renderApiClients() {
    const tbody = document.getElementById('apiClientsTable');
    if (!tbody) return;
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    try {
      const clients = await fetchApi('/admin/api-clients');
      tbody.innerHTML = clients.length
        ? clients.map(c => {
            const used = c.last_used_at
              ? new Date(c.last_used_at).toLocaleString('id-ID')
              : '<span class="text-slate-400">belum pernah</span>';
            return `<tr class="hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors">
              <td class="py-3 px-5 font-medium text-slate-700 dark:text-slate-200">${esc(c.name)}</td>
              <td class="py-3 px-5 font-mono text-xs text-slate-500">${esc(c.key_prefix)}…</td>
              <td class="py-3 px-5 text-xs text-slate-500">${used}</td>
              <td class="py-3 px-5 text-right"><button data-revoke="${c.id}" class="text-red-600 hover:text-red-800 text-xs font-semibold">Cabut</button></td>
            </tr>`;
          }).join('')
        : '<tr><td colspan="4" class="text-center py-6 text-slate-400">Belum ada API key. Klik "Buat API Key".</td></tr>';
    } catch (e) {
      console.error('Gagal memuat API clients:', e);
      tbody.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-red-500">Gagal memuat.</td></tr>';
    }
  }

  async createApiKey() {
    const name = prompt('Nama klien (mis. "Website Koperasi Pusat"):');
    if (!name || !name.trim()) return;
    try {
      const res = await fetchApi('/admin/api-clients', {
        method: 'POST',
        body: JSON.stringify({ name: name.trim() }),
      });
      const box = document.getElementById('newApiKeyBox');
      const val = document.getElementById('newApiKeyValue');
      if (box && val && res.key) {
        val.textContent = res.key;
        box.classList.remove('hidden');
      }
      this.renderApiClients();
    } catch (e) {
      alert('Gagal membuat API key.');
    }
  }

  copyNewApiKey() {
    const val = document.getElementById('newApiKeyValue');
    if (!val || !val.textContent) return;
    navigator.clipboard?.writeText(val.textContent).then(() => {
      const btn = document.getElementById('btnCopyApiKey');
      if (btn) {
        const t = btn.textContent;
        btn.textContent = 'Tersalin!';
        setTimeout(() => { btn.textContent = t; }, 1500);
      }
    });
  }

  async revokeApiKey(id) {
    if (!confirm('Cabut API key ini? Sistem yang memakainya langsung kehilangan akses.')) return;
    try {
      await fetchApi('/admin/api-clients/' + id, { method: 'DELETE' });
      document.getElementById('newApiKeyBox')?.classList.add('hidden');
      this.renderApiClients();
    } catch (e) {
      alert('Gagal mencabut API key.');
    }
  }

  // Pratinjau response endpoint Management API langsung dari panel.
  async tryApiEndpoint(ep) {
    const panel = document.getElementById('apiPreviewPanel');
    const body = document.getElementById('apiPreviewBody');
    if (!panel || !body) return;
    const epLabel = document.getElementById('apiPreviewEp');
    const status = document.getElementById('apiPreviewStatus');
    panel.classList.remove('hidden');
    if (epLabel) epLabel.textContent = '/' + ep;
    if (status) { status.textContent = 'memuat…'; status.className = 'text-xs font-bold text-slate-400'; }
    body.textContent = '';
    try {
      const res = await fetchApi('/admin/api-preview?endpoint=' + encodeURIComponent(ep));
      body.textContent = JSON.stringify(res, null, 2);
      if (status) { status.textContent = '200 OK'; status.className = 'text-xs font-bold text-green-500'; }
    } catch (err) {
      body.textContent = 'Gagal memuat response.' + (err && err.message ? ' ' + err.message : '');
      if (status) { status.textContent = 'error'; status.className = 'text-xs font-bold text-red-500'; }
    }
    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  // Kirim tes notifikasi WhatsApp via gateway (pakai konfigurasi tersimpan).
  async testWa() {
    const to = (document.getElementById('waTestTo')?.value
      || document.getElementById('adminWaNumber')?.value || '').trim();
    const box = document.getElementById('waTestResult');
    const show = (cls, txt) => {
      if (!box) return;
      box.className = 'text-xs rounded-lg p-2 ' + cls;
      box.textContent = txt;
      box.classList.remove('hidden');
    };
    if (!to) {
      show('bg-amber-100 text-amber-800', 'Isi nomor tujuan tes dulu (atau isi Nomor WA Admin).');
      return;
    }
    show('bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-200', 'Mengirim tes…');
    try {
      const res = await fetchApi('/admin/wa/test', {
        method: 'POST',
        body: JSON.stringify({ to }),
      });
      if (res.ok) show('bg-green-100 text-green-800', '✓ ' + (res.message || 'Terkirim ke gateway.'));
      else show('bg-red-100 text-red-700', '✗ ' + (res.message || 'Gagal mengirim.'));
    } catch (e) {
      show('bg-red-100 text-red-700', '✗ Gagal menghubungi server.');
    }
  }

  // ----- WhatsApp Gateway (konfigurasi + log) -----
  async renderWa() {
    try {
      const s = await fetchApi('/admin/settings');
      const wt = document.getElementById('waToken');
      if (wt) {
        wt.value = '';
        wt.placeholder = s.wa_token_is_set ? '•••••••• (kosongkan untuk tetap)' : 'Belum diatur';
      }
      const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
      set('adminWaNumber', s.admin_wa_number || '');
      set('waEndpoint', s.wa_endpoint || '');
      // Device ID tidak lagi diisi admin: nilainya tetap 0 = pakai device
      // bawaan API Key (lihat WhatsAppService::deviceId()).
    } catch (e) {
      console.error('Gagal memuat konfigurasi WA:', e);
    }
    this.renderWaLog();
  }

  async saveWaConfig(e) {
    e.preventDefault();
    const fd = new FormData();
    fd.append('wa_token', document.getElementById('waToken').value.trim());
    fd.append('admin_wa_number', document.getElementById('adminWaNumber').value.trim());
    fd.append('wa_endpoint', document.getElementById('waEndpoint').value.trim());
    fd.append('wa_device_id', '0'); // 0 = pakai device bawaan API Key
    try {
      await fetchApi('/admin/settings', { method: 'POST', body: fd });
      alert('Konfigurasi WhatsApp Gateway disimpan!');
      this.renderWa();
    } catch (err) {
      alert('Gagal menyimpan konfigurasi.');
    }
  }

  async renderWaLog() {
    const tbody = document.getElementById('waLogTable');
    if (!tbody) return;
    const esc = (x) => String(x == null ? '' : x).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const dir = document.getElementById('waLogDirection')?.value || '';
    const st = document.getElementById('waLogStatus')?.value || '';
    const lim = document.getElementById('waLogLimit')?.value || '20';
    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-slate-400">Memuat…</td></tr>';
    try {
      const q = new URLSearchParams({ limit: lim });
      if (dir) q.set('direction', dir);
      if (st) q.set('status', st);
      const res = await fetchApi('/admin/wa/messages?' + q.toString());
      if (!res.ok) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-center py-4 text-red-500">${esc(res.message || 'Gagal memuat log. Cek API Key & gateway.')}</td></tr>`;
        return;
      }
      const d = res.data;
      let list = Array.isArray(d) ? d
        : (d?.data?.messages || d?.messages || (Array.isArray(d?.data) ? d.data : null) || d?.items || []);
      if (!Array.isArray(list)) list = [];
      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-6 text-slate-400">Belum ada pesan.</td></tr>';
        return;
      }
      tbody.innerHTML = list.map(m => {
        const to = m.to || m.recipient || m.phone || m.number || '-';
        const body = m.body || m.message || m.text || m.content || '';
        const direction = m.direction || m.type || '-';
        const status = m.status || '-';
        const at = m.createdAt || m.created_at || m.timestamp || m.sentAt || m.updatedAt || '';
        let when = '-';
        try { if (at) when = new Date(at).toLocaleString('id-ID'); } catch (_) {}
        const sc = /sent|deliver|read|success/i.test(status) ? 'text-green-600'
          : /fail|error|reject/i.test(status) ? 'text-red-600' : 'text-amber-600';
        return `<tr class="hover:bg-slate-50 dark:hover:bg-slate-700/40">
          <td class="py-2.5 px-4 font-mono text-xs text-slate-600 dark:text-slate-300">${esc(to)}</td>
          <td class="py-2.5 px-4 text-xs text-slate-600 dark:text-slate-300"><div class="max-w-xs truncate">${esc(body)}</div></td>
          <td class="py-2.5 px-4 text-center text-[11px]">${esc(direction)}</td>
          <td class="py-2.5 px-4 text-center text-[11px] font-bold ${sc}">${esc(status)}</td>
          <td class="py-2.5 px-4 text-xs text-slate-500">${esc(when)}</td>
        </tr>`;
      }).join('');
    } catch (e) {
      console.error('Gagal memuat log WA:', e);
      tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-red-500">Gagal memuat log.</td></tr>';
    }
  }

  // ----- Settings -----
  // ----- Settings -----
  async renderSettings() {
    try {
      const settings = await fetchApi('/admin/settings');

      // 1. Render Komisi
      const rateInput = document.getElementById('commissionRate');
      if (rateInput && settings.commission_rate !== undefined) {
        const rate = parseFloat(settings.commission_rate);
        rateInput.value = (rate * 100);
      }

      // Radius area bandara
      const radiusInput = document.getElementById('airportRadiusKm');
      if (radiusInput && settings.airport_radius_km !== undefined) {
        radiusInput.value = settings.airport_radius_km;
      }

      // Titik pusat area bandara (nilai efektif: setting || bawaan config)
      const latInput = document.getElementById('airportLatitude');
      const lngInput = document.getElementById('airportLongitude');
      if (latInput && settings.airport_latitude !== undefined) {
        latInput.value = settings.airport_latitude;
      }
      if (lngInput && settings.airport_longitude !== undefined) {
        lngInput.value = settings.airport_longitude;
      }
      // Titik tersimpan dipegang untuk tombol "Kembalikan ke titik tersimpan",
      // lalu peta pemilihnya digambar dari nilai yang baru saja diisi.
      if (settings.airport_latitude !== undefined && settings.airport_longitude !== undefined) {
        this._savedCenter = {
          lat: parseFloat(settings.airport_latitude),
          lng: parseFloat(settings.airport_longitude),
        };
      }
      if (settings.airport_radius_km !== undefined) {
        this._savedRadiusKm = parseFloat(settings.airport_radius_km);
      }
      this.renderAirportCenterMap();

      // Tenggang di luar area. Cek !== undefined (bukan truthy) supaya nilai 0
      // — yang berarti "auto-keluar dinonaktifkan" — tidak ikut terbuang.
      const graceInput = document.getElementById('outOfAreaGraceMinutes');
      if (graceInput && settings.out_of_area_grace_minutes !== undefined) {
        graceInput.value = settings.out_of_area_grace_minutes;
      }
      this.renderOutOfAreaGracePreview();

      // Jam operasi pelacakan lokasi
      const opStart = document.getElementById('operatingStart');
      const opEnd = document.getElementById('operatingEnd');
      if (opStart && settings.operating_start) opStart.value = settings.operating_start;
      if (opEnd && settings.operating_end) opEnd.value = settings.operating_end;

      // 2. Render Email (BARU)
      const emailInput = document.getElementById('adminEmail');
      if (emailInput && settings.admin_email) {
        emailInput.value = settings.admin_email;
      } else if (emailInput) {
        emailInput.value = ''; // Kosongkan jika belum diset
      }

      // 2. SMTP (BARU)
      if (document.getElementById('mailHost')) document.getElementById('mailHost').value = settings.mail_host || '';
      if (document.getElementById('mailPort')) document.getElementById('mailPort').value = settings.mail_port || '';
      if (document.getElementById('mailUsername')) document.getElementById('mailUsername').value = settings.mail_username || '';
      // Field rahasia: server tidak pernah mengirim nilainya. Selalu kosongkan;
      // placeholder menandai sudah dikonfigurasi (kosongkan untuk mempertahankan).
      if (document.getElementById('mailPassword')) {
        const mp = document.getElementById('mailPassword');
        mp.value = '';
        mp.placeholder = settings.mail_password_is_set ? '•••••••• (kosongkan untuk tetap)' : 'Belum diatur';
      }
      if (document.getElementById('mailEncryption')) document.getElementById('mailEncryption').value = settings.mail_encryption || 'tls';
      if (document.getElementById('mailFromName')) document.getElementById('mailFromName').value = settings.mail_from_name || '';
      // (Field WhatsApp Gateway dipindah ke menu WhatsApp Gateway — lihat renderWa().)

      // 3. QRIS Preview
      const previewQris = document.getElementById('previewQris');
      const placeholderQris = document.getElementById('placeholderQris');
      if (settings.company_qris_url && previewQris) {
        previewQris.src = settings.company_qris_url;
        previewQris.classList.remove('hidden');
        placeholderQris.classList.add('hidden');
      }

      // 4. Lapisan tampilan: penanda SMTP + pratinjau langsung.
      const smtpSiap = !!(settings.mail_host && settings.mail_username && settings.mail_password_is_set);
      const chip = document.getElementById('smtpStatus');
      if (chip) {
        chip.textContent = smtpSiap ? 'Terkonfigurasi' : 'Belum lengkap';
        chip.className = 'text-[10px] px-2 py-0.5 rounded-full uppercase tracking-widest border ' + (smtpSiap
          ? 'bg-emerald-50 text-emerald-600 border-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-400 dark:border-emerald-500/30'
          : 'bg-amber-50 text-amber-600 border-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:border-amber-500/30');
      }
      const dot = document.getElementById('smtpStatusDot');
      if (dot) dot.className = 'w-1.5 h-1.5 rounded-full ' + (smtpSiap ? 'bg-emerald-500' : 'bg-amber-500');

      this.renderCommissionPreview();
      this.renderOperatingPreview();
      // Nilai baru saja datang dari server → belum ada yang perlu disimpan.
      this.markSettingsDirty(false);

    } catch (error) {
      console.error("Gagal memuat pengaturan:", error);
    }
  }

  // --- FUNGSI BARU: Buka Modal Detail dengan Hitungan Komisi ---
  async openWdDetails(id) {
    const modal = document.getElementById('modalWdDetails');
    const list = document.getElementById('wdDetailsList');
    const totalEl = document.getElementById('wdDetailsTotal');

    list.innerHTML = '<tr><td colspan="5" class="p-4 text-center">Memuat data dan menghitung komisi...</td></tr>';
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    try {
      // 1. Ambil Data Transaksi DAN Data Setting (Untuk tahu Rate Komisi) secara paralel
      const [transactions, settings] = await Promise.all([
        fetchApi(`/admin/withdrawals/${id}/details`),
        fetchApi('/admin/settings')
      ]);

      if (transactions.length === 0) {
        list.innerHTML = '<tr><td colspan="5" class="p-4 text-center">Data tidak ditemukan.</td></tr>';
        totalEl.textContent = 'Rp0';
        return;
      }

      // Ambil rate komisi (default 0.2 atau 20% jika error)
      const rate = parseFloat(settings.commission_rate) || 0.2;

      let totalNet = 0;

      list.innerHTML = transactions.map(t => {
        const amount = t.amount;
        let netAmount = 0;
        let rowClass = '';
        let netClass = '';
        let calculationInfo = '';

        // --- LOGIKA HITUNG NET ---
        if (t.method === 'CashDriver') {
          // Jika CashDriver: Driver BERHUTANG komisi ke admin

          // Cek apakah ini booking Manual (Tidak ada Zone To)
          const isManual = !t.booking?.zone_to;

          if (isManual) {
            // Manual: Flat Fee 10.000
            const debt = 10000;
            netAmount = -debt;
            calculationInfo = `<div class="text-[10px] text-red-400">Potongan Flat 10rb</div>`;
          } else {
            // Standard: Komisi Persentase
            const debt = amount * rate;
            netAmount = -debt;
            calculationInfo = `<div class="text-[10px] text-red-400">Potongan Komisi ${(rate * 100)}%</div>`;
          }

          rowClass = 'bg-red-50/50';
          netClass = 'text-red-600 font-bold';

        } else {
          // Jika QRIS/CashCSO: Driver MENERIMA sisa setelah komisi
          netAmount = amount * (1 - rate);

          rowClass = '';
          netClass = 'text-emerald-600 font-bold';
          calculationInfo = `<div class="text-[10px] text-slate-400">Pendapatan Bersih</div>`;
        }

        // Akumulasi Total Akhir
        totalNet += netAmount;

        const routeName = t.booking?.zone_to?.name || t.booking?.manual_destination || 'Manual';

        return `
          <tr class="border-b border-slate-50 dark:border-slate-700 ${rowClass}">
              <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">${new Date(t.created_at).toLocaleString('id-ID')}</td>
              <td class="px-4 py-3 text-xs font-medium text-slate-700 dark:text-slate-300">
                  ${routeName}
              </td>
              <td class="px-4 py-3 text-xs">
                  <span class="px-2 py-1 rounded-full border ${t.method === 'CashDriver' ? 'bg-orange-100 text-orange-700 border-orange-200' : 'bg-blue-100 text-blue-700 border-blue-200'} text-[10px] font-bold">
                    ${t.method === 'CashDriver' ? 'Tunai (Supir)' : (t.method === 'CashCSO' ? 'Tunai (Kasir)' : t.method)}
                  </span>
              </td>
              <td class="px-4 py-3 text-xs font-mono text-right text-slate-500 dark:text-slate-400">
                  ${Utils.formatCurrency(amount)}
              </td>
              <td class="px-4 py-3 text-right">
                  <div class="${netClass} font-mono text-sm">
                    ${netAmount < 0 ? '-' : ''} ${Utils.formatCurrency(Math.abs(netAmount))}
                  </div>
                  ${calculationInfo}
              </td>
          </tr>`;
      }).join('');

      // Tampilkan Total Bersih (Harus sama dengan jumlah yang diajukan di Withdrawal)
      totalEl.textContent = Utils.formatCurrency(totalNet);

    } catch (error) {
      console.error(error);
      list.innerHTML = '<tr><td colspan="5" class="p-4 text-center text-red-500">Gagal memuat detail.</td></tr>';
    }
  }

  // ----- Queue Management -----

  async renderQueue() {
    const tbody = document.getElementById('queueTableList');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4">Memuat data...</td></tr>';

    try {
      const queue = await fetchApi('/admin/queue');

      if (queue.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-8 text-slate-400">Antrian Kosong.</td></tr>';
        return;
      }

      tbody.innerHTML = queue.map((q, index) => {
        const isFirst = index === 0;
        const isLast = index === queue.length - 1;


        return `
          <tr class="hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors group" >
              <td class="py-3 px-4 font-bold text-slate-700 dark:text-slate-200">#${q.real_position}</td>
              <td class="py-3 px-4 font-medium text-slate-800 dark:text-slate-100">${q.name}</td>
              <td class="py-3 px-4">
                  <div class="flex items-center gap-2">
                      <span class="font-mono bg-slate-100 dark:bg-slate-600 dark:text-slate-200 px-2 py-1 rounded text-xs">L${q.line_number}</span>
                      <button onclick="app.editLineNumber(${q.user_id}, '${q.line_number}')" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 opacity-0 group-hover:opacity-100 transition-opacity">
                          <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" /></svg>
                      </button>
                  </div>
              </td>
              <td class="py-3 px-4 text-xs text-slate-500 dark:text-slate-400">${new Date(q.joined_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}</td>
              <td class="py-3 px-4 text-center">
                  <div class="flex justify-center gap-1">
                      <button onclick="app.moveQueue(${q.user_id}, 'up')" class="p-1 rounded hover:bg-blue-100 dark:hover:bg-slate-600 text-blue-600 dark:text-blue-400 disabled:opacity-30" ${isFirst ? 'disabled' : ''}>
                          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z" clip-rule="evenodd" /></svg>
                      </button>
                      <button onclick="app.moveQueue(${q.user_id}, 'down')" class="p-1 rounded hover:bg-blue-100 dark:hover:bg-slate-600 text-blue-600 dark:text-blue-400 disabled:opacity-30" ${isLast ? 'disabled' : ''}>
                          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                      </button>
                  </div>
              </td>
              <td class="py-3 px-4 text-center">
                  <div class="flex items-center justify-center gap-2">
                        <button onclick="window.openActivityModal(${q.user_id})" class="text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-slate-600 px-3 py-1 rounded text-xs font-bold border border-indigo-200 dark:border-indigo-900">
                             Log
                        </button>
                        <button onclick="app.kickQueue(${q.user_id}, '${q.name}')" class="text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-slate-600 px-3 py-1 rounded text-xs font-bold border border-red-200 dark:border-red-900">
                             Kick
                        </button>
                    </div>
              </td>
          </tr >
          `;
      }).join('');

    } catch (error) {
      console.error(error);
      tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-red-500">Gagal memuat antrian.</td></tr>';
    }
  }

  // Aksi: Naik/Turun Antrian
  async moveQueue(userId, direction) {
    try {
      await fetchApi('/admin/queue/move', {
        method: 'POST',
        body: JSON.stringify({ user_id: userId, direction: direction })
      });
      this.renderQueue(); // Refresh tabel
    } catch (error) {
      alert('Gagal mengubah urutan.');
    }
  }

  // Aksi: Kick Driver
  async kickQueue(userId, name) {
    if (!confirm(`Keluarkan ${name} dari antrian ? Status driver akan menjadi Offline.`)) return;

    try {
      await fetchApi(`/admin/queue/${userId}`, { method: 'DELETE' });
      this.renderQueue(); // Refresh tabel
      // Update juga dashboard stats jika sedang tampil (opsional)
    } catch (error) {
      alert('Gagal mengeluarkan driver.');
    }
  }

  // Aksi: Edit Line Number
  async editLineNumber(userId, currentVal) {
    const newVal = prompt("Masukkan Line Number Baru:", currentVal);
    if (newVal === null || newVal === currentVal) return;

    try {
      await fetchApi('/admin/queue/line-number', {
        method: 'POST',
        body: JSON.stringify({ user_id: userId, line_number: newVal })
      });
      this.renderQueue(); // Refresh
    } catch (error) {
      alert('Gagal update line number.');
    }
  }

  // --- FUNGSI BARU: Modal Activity ---
  async openActivityModal(userId) {
    const modal = document.getElementById('modalActivity');
    const list = document.getElementById('activityList');

    list.innerHTML = '<tr><td colspan="3" class="p-4 text-center">Memuat log aktivitas...</td></tr>';
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    try {
      const logs = await fetchApi(`/admin/drivers/${userId}/activity`);

      if (logs.length === 0) {
        list.innerHTML = '<tr><td colspan="3" class="p-4 text-center text-slate-500">Belum ada aktivitas tercatat.</td></tr>';
        return;
      }

      const typeMap = {
        'QUEUE_JOIN': 'Masuk Antrian',
        'QUEUE_JOIN_AUTO': 'Masuk Antrian (Otomatis)',
        'QUEUE_JOIN_REPAIR': 'Masuk Antrian (Sistem)',
        'QUEUE_LEAVE': 'Keluar Antrian',
        'QUEUE_LEAVE_AUTO': 'Keluar Antrian (Timeout)',
        'TRIP_START_SELF': 'Mulai Trip',
        'TRIP_FINISH': 'Selesai Mengantar',
        'AREA_LEAVE_WARNING': 'Peringatan: Keluar Area',
        'AREA_RETURN': 'Kembali ke Area',
        'ORDER_RECEIVED': 'Dapat Order (CSO)',
        'QUEUE_LEAVE_ORDER': 'Keluar Antrian (Order)',
        'ORDER_REASSIGNED_IN': 'Terima Order Alihan',
        'ORDER_REASSIGNED_OUT': 'Order Dialihkan',
        'ORDER_QUEUE_OVERRIDE': 'Mendahului Antrian',
        'QUEUE_SKIPPED': 'Dilewati (Order ke Supir Lain)'
      };

      list.innerHTML = logs.map(log => {
        const typeLabel = typeMap[log.activity_type] || log.activity_type;
        return `
            <tr class="border-b border-slate-50 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700">
                <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400 whitespace-nowrap">
                    ${new Date(log.created_at).toLocaleString('id-ID')}
                </td>
                <td class="px-4 py-3">
                    <span class="bg-indigo-50 text-indigo-700 px-2 py-1 rounded text-[10px] font-bold border border-indigo-100 dark:bg-indigo-900 dark:text-indigo-200 dark:border-indigo-800">
                        ${typeLabel}
                    </span>
                </td>
                <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
                    ${log.description || '-'}
                </td>
            </tr>
        `;
      }).join('');

    } catch (error) {
      console.error(error);
      list.innerHTML = '<tr><td colspan="3" class="p-4 text-center text-red-500">Gagal memuat log.</td></tr>';
    }
  }

  // Helper function openUserModal perlu disesuaikan sedikit untuk field car/plate
  openUserModal(data) {
    const isEditing = data !== null;
    document.getElementById('userModal').classList.remove('hidden');
    document.getElementById('userModalTitle').textContent = isEditing ? 'Edit Pengguna' : 'Tambah Pengguna';

    document.getElementById('userId').value = isEditing ? data.id : '';
    document.getElementById('userName').value = isEditing ? data.name : '';
    document.getElementById('userRole').value = isEditing ? data.role : 'cso';
    document.getElementById('userUsername').value = isEditing ? data.username : '';

    document.getElementById('userPassword').value = '';
    document.getElementById('userPassword').placeholder = isEditing ? 'Isi untuk mengubah password' : 'Password wajib diisi';

    // Sesuaikan nama properti object data dari backend
    document.getElementById('userCar').value = isEditing ? data.driver_profile?.car_model || '' : '';
    document.getElementById('userPlate').value = isEditing ? data.driver_profile?.plate_number || '' : '';

    const role = document.getElementById('userRole').value;
    document.getElementById('driverExtra').style.display = role === 'driver' ? 'grid' : 'none';
  }


}
