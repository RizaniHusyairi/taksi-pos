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
    this.views = ['dashboard', 'queue', 'zones', 'users', 'finance-log', 'withdrawals', 'report-revenue', 'report-driver', 'settings'];
    this.charts = {};
  }
  init() {
    window.addEventListener('hashchange', () => this.route());
    this.route();
    this.populateFilterDropdowns();
    this.initCommon();
    this.initTheme(); // Initialize Theme
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
    });
  }

  initCommon() {

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
      formData.append('wa_token', document.getElementById('waToken').value.trim());
      formData.append('admin_wa_number', document.getElementById('adminWaNumber').value.trim());

      // FILE UPLOAD
      const fileInput = document.getElementById('companyQris');
      if (fileInput && fileInput.files[0]) {
        formData.append('company_qris', fileInput.files[0]);
      }

      // Validasi sederhana
      if (isNaN(parseFloat(rateValue)) || parseFloat(rateValue) < 0 || parseFloat(rateValue) > 100) {
        alert('Masukkan nilai persentase antara 0 dan 100');
        return;
      }

      if (!emailValue) {
        alert('Email admin tidak boleh kosong.');
        return;
      }

      try {
        // 2. Kirim data ke API menggunakan POST via FormData
        // fetchApi sudah dimodifikasi untuk tidak memaksa Content-Type JSON jika body adalah FormData
        await fetchApi('/admin/settings', {
          method: 'POST',
          body: formData
        });
        alert('Pengaturan berhasil disimpan!');
      } catch (error) {
        console.error("Gagal menyimpan pengaturan:", error);
        alert('Gagal menyimpan pengaturan. Silakan coba lagi.');
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
        alert('Konfirmasi password tidak cocok');
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
        alert('Password berhasil diperbarui.');
        formAdminPassword.reset();
      } catch (error) {
        console.error("Gagal ganti password:", error);
        alert('Gagal ganti password: ' + (error.message || 'Terjadi kesalahan'));
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

      const payload = { name, price };

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
        alert('Zona berhasil disimpan!'); // Ganti Utils.showToast atau sesuaikan
        formZone.reset();
        document.getElementById('zoneId').value = '';
        await this.renderZones(); // Panggil renderZones dan tunggu selesai
      } catch (error) {
        console.error("Gagal menyimpan zona:", error);
      }
    });

    // Users modal
    const userModal = document.getElementById('userModal');
    const btnOpenCreateUser = document.getElementById('btnOpenCreateUser');
    const userModalClose = document.getElementById('userModalClose');
    const userModalCancel = document.getElementById('userModalCancel');
    const userRole = document.getElementById('userRole');

    function openModal(editing = false, data = null) {
      userModal.classList.remove('hidden');
      document.getElementById('userModalTitle').textContent = editing ? 'Edit Pengguna' : 'Tambah Pengguna';
      if (editing && data) {
        document.getElementById('userId').value = data.id;
        document.getElementById('userName').value = data.name || '';
        document.getElementById('userRole').value = data.role || 'cso';
        document.getElementById('userUsername').value = data.username || '';
        document.getElementById('userPassword').value = data.password || '';
        document.getElementById('userCar').value = data.car || '';
        document.getElementById('userPlate').value = data.plate || '';
      } else {
        document.getElementById('formUser').reset();
        document.getElementById('userId').value = '';
      }
      toggleDriverExtra();
    }
    function closeModal() { userModal.classList.add('hidden'); }

    function toggleDriverExtra() {
      const role = userRole.value;
      const extra = document.getElementById('driverExtra');
      extra.style.display = role === 'driver' ? 'grid' : 'none';
    }

    userRole?.addEventListener('change', toggleDriverExtra);
    btnOpenCreateUser?.addEventListener('click', () => openModal(false));
    userModalClose?.addEventListener('click', closeModal);
    userModalCancel?.addEventListener('click', closeModal);
    userModal?.addEventListener('click', (e) => { if (e.target === userModal) closeModal(); });
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

        // Panggil fungsi closeModal() yang sudah Anda miliki
        closeModal(); // Pastikan fungsi ini bisa diakses di sini

        // 3. Refresh data yang relevan setelah berhasil
        await this.renderUsers(); // Muat ulang tabel pengguna
        await this.populateFilterDropdowns(); // Muat ulang dropdown filter

      } catch (error) {
        console.error("Gagal menyimpan data pengguna:", error);
        alert('Gagal menyimpan data pengguna. Periksa kembali isian Anda.');
      }
    });

    // Finance filters
    ['fltDateFrom', 'fltDateTo', 'fltDriver', 'fltCSO'].forEach(id => {
      document.getElementById(id)?.addEventListener('change', () => this.renderTxLog());
    });

    // Revenue report range
    document.getElementById('revRange')?.addEventListener('change', () => this.renderRevReport());
    document.getElementById('driverRankBy')?.addEventListener('change', () => this.renderDriverReport());

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
  }

  renderAll() {
    this.renderDashboard();
    this.renderZones();
    this.renderUsers();
    this.renderTxLog();
    this.renderWithdrawals();
    this.renderRevReport();
    this.renderDriverReport();
    this.renderSettings();
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
      if (target === hash) a.classList.add('bg-primary-50', 'text-primary-700', 'dark:bg-slate-700', 'dark:text-primary-400');
      else a.classList.remove('bg-primary-50', 'text-primary-700', 'dark:bg-slate-700', 'dark:text-primary-400');
    });
  }
  titleOf(v) {
    return {
      'dashboard': 'Dashboard',
      'queue': 'Manajemen Antrian',
      'zones': 'Manajemen Zona & Tarif',
      'users': 'Manajemen Pengguna',
      'finance-log': 'Transaction Log',
      'withdrawals': 'Withdrawal Requests',
      'report-revenue': 'Laporan Pendapatan',
      'report-driver': 'Laporan Kinerja Supir',
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

      // 3. POPULASIKAN GRAFIK DARI DATA API
      const charts = dashboardData.charts;
      this.renderLineChart('weeklyChart', charts.weekly.labels, charts.weekly.values);
      this.renderBarChart('monthlyChart', charts.monthly.labels, charts.monthly.values);

    } catch (error) {
      console.error("Gagal memuat data dashboard:", error);
      // Tampilkan pesan error jika gagal memuat
      document.getElementById('metricRevenueToday').textContent = 'Error';
      document.getElementById('metricTxCount').textContent = 'Error';
      document.getElementById('metricActiveDrivers').textContent = 'Error';
      document.getElementById('metricPendingWd').textContent = 'Error';
    }
  }
  renderLineChart(id, labels, data) {
    const ctx = document.getElementById(id);
    if (this.charts[id]) this.charts[id].destroy();
    this.charts[id] = new Chart(ctx, {
      type: 'line',
      data: { labels, datasets: [{ label: 'Pendapatan', data, tension: .3 }] },
      options: { responsive: true, scales: { y: { beginAtZero: true } } }
    });
  }
  renderBarChart(id, labels, data) {
    const ctx = document.getElementById(id);
    if (this.charts[id]) this.charts[id].destroy();
    this.charts[id] = new Chart(ctx, {
      type: 'bar',
      data: { labels, datasets: [{ label: 'Pendapatan', data }] },
      options: { responsive: true, scales: { y: { beginAtZero: true } } }
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
    try {
      const zones = await fetchApi('/admin/zones');
      const tbody = document.getElementById('zonesTable');
      if (!tbody) return;
      tbody.innerHTML = zones.map(z => `<tr class="border-t">
        <td class="py-2">${z.name}</td>
        <td class="py-2">${Utils.formatCurrency(z.price)}</td>
        <td class="py-2">
          <button class="text-primary-700 text-sm" data-edit-zone='${JSON.stringify(z)}'>Edit</button>
          <button class="text-red-600 text-sm ml-2" data-del-zone="${z.id}">Hapus</button>
        </td>
      </tr>`).join('');
      tbody.querySelectorAll('[data-edit-zone]').forEach(btn => {
        btn.addEventListener('click', () => {
          const z = JSON.parse(btn.dataset.editZone);
          document.getElementById('zoneId').value = z.id;
          document.getElementById('zoneName').value = z.name;
          document.getElementById('zonePrice').value = z.price;
        });
      });
      tbody.querySelectorAll('[data-del-zone]').forEach(btn => {
        btn.addEventListener('click', async () => { // <-- Jadikan callback ini 'async'
          if (confirm('Hapus zona tujuan ini?')) {
            try {
              const zoneId = btn.dataset.delZone;
              // Ganti DB.deleteZone dengan fetchApi
              await fetchApi(`/admin/zones/${zoneId}`, {
                method: 'DELETE'
              });

              alert('Zona berhasil dihapus.');
              await this.renderZones(); // Panggil lagi untuk me-refresh tabel
            } catch (error) {
              console.error('Gagal menghapus zona:', error);
              alert('Gagal menghapus zona. Silakan coba lagi.');
            }
          }
        });
      });
    }
    catch (err) {
      console.error("Gagal memuat data zona:", error);
      document.getElementById('zonesTable').innerHTML = '<tr><td colspan="3">Gagal memuat data.</td></tr>';
    }


  }

  // ----- Users -----
  async renderUsers() {
    try {
      // 1. GANTI DB.listUsers() DENGAN PANGGILAN API
      const users = await fetchApi('/admin/users');
      const tbody = document.getElementById('usersTable');
      if (!tbody) return;

      tbody.innerHTML = users.map(u => {
        const carInfo = u.role === 'driver'
          ? `<div class="text-xs text-slate-500">${u.driver_profile?.car_model || '-'} • ${u.driver_profile?.plate_number || '-'}</div>`
          : '';

        // EDIT ICON (Pencil)
        const editIcon = `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" /></svg>`;

        // DELETE ICON (Trash)
        const deleteIcon = `<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>`;

        return `<tr class="border-t hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
          <td class="py-3 px-3">
            <div class="font-medium text-slate-900 dark:text-slate-100">${u.name}</div>
            ${carInfo.replace('text-slate-500', 'text-slate-500 dark:text-slate-400')}
          </td>
          <td class="py-3 px-3 capitalize text-slate-600 dark:text-slate-300">${u.role}</td>
          <td class="py-3 px-3 text-slate-600 dark:text-slate-300">${u.username}</td>
          <td class="py-3 px-3">
            <div class="flex items-center gap-2">
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

      // --- Event Listener untuk Tombol Edit ---
      tbody.querySelectorAll('[data-edit-u]').forEach(btn => {
        btn.addEventListener('click', () => {
          const userData = JSON.parse(btn.dataset.editU);
          this.openUserModal(userData);
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
            msgConfirmDelete.innerHTML = `Anda yakin ingin menghapus pengguna <span class="font-bold text-slate-800 dark:text-slate-100">${deleteTargetName}</span>?<br>Tindakan ini permanen.`;
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
  // Helper function untuk modal (bisa diletakkan di dalam class AdminApp)
  // Ini dimodifikasi dari event listener global Anda sebelumnya agar lebih rapi
  openUserModal(data) {
    // data = null artinya mode 'Tambah Pengguna'
    // data berisi objek user artinya mode 'Edit Pengguna'
    const isEditing = data !== null;

    document.getElementById('userModal').classList.remove('hidden');
    document.getElementById('userModalTitle').textContent = isEditing ? 'Edit Pengguna' : 'Tambah Pengguna';

    // 4. SESUAIKAN PENGISIAN FORM DENGAN STRUKTUR DATA BARU
    document.getElementById('userId').value = isEditing ? data.id : '';
    document.getElementById('userName').value = isEditing ? data.name : '';
    document.getElementById('userRole').value = isEditing ? data.role : 'cso';
    document.getElementById('userUsername').value = isEditing ? data.username : '';

    // Kosongkan password saat edit, minta pengguna mengisinya jika ingin mengubah
    document.getElementById('userPassword').value = '';
    document.getElementById('userPassword').placeholder = isEditing ? 'Isi untuk mengubah password' : 'Password wajib diisi';

    // Akses data supir dengan optional chaining
    document.getElementById('userCar').value = isEditing ? data.driver_profile?.car_model || '' : '';
    document.getElementById('userPlate').value = isEditing ? data.driver_profile?.plate_number || '' : '';

    // Tampilkan/sembunyikan field tambahan untuk supir
    const role = document.getElementById('userRole').value;
    document.getElementById('driverExtra').style.display = role === 'driver' ? 'grid' : 'none';
  }


  // ----- Finance: Transaction Log -----
  async renderTxLog() {
    const tbody = document.getElementById('txTable');
    if (!tbody) return;

    try {
      // 1. Ambil Filter
      const dateFrom = document.getElementById('fltDateFrom')?.value || '';
      const dateTo = document.getElementById('fltDateTo')?.value || '';
      const driverId = document.getElementById('fltDriver')?.value || '';
      const csoId = document.getElementById('fltCSO')?.value || '';

      // 2. Buat Query URL
      const params = new URLSearchParams();
      if (dateFrom) params.append('date_from', dateFrom);
      if (dateTo) params.append('date_to', dateTo);
      if (driverId) params.append('driver_id', driverId);
      if (csoId) params.append('cso_id', csoId);

      // 3. Panggil API
      const response = await fetchApi(`/admin/transactions?${params.toString()}`);
      const transactions = response.data;

      if (transactions.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-slate-500">Tidak ada data transaksi yang cocok.</td></tr>';
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

    } catch (error) {
      console.error("Gagal memuat log transaksi:", error);
      tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-red-500">Gagal memuat data.</td></tr>';
    }
  }

  // ----- Withdrawals -----
  async renderWithdrawals() { // <-- Jadikan async
    const tbody = document.getElementById('wdTable');
    if (!tbody) return;

    try {
      // 1. GANTI DB.list... DENGAN SATU PANGGILAN API
      const withdrawals = await fetchApi('/admin/withdrawals');

      if (withdrawals.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4">Tidak ada permintaan penarikan dana.</td></tr>';
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
            ? `<button class="text-xs text-blue-600 border border-blue-200 dark:text-blue-400 dark:border-blue-800 px-2 py-1 rounded hover:bg-blue-50 dark:hover:bg-slate-700" onclick="window.open('/storage/${w.proof_image}', '_blank')">Bukti</button>`
            : '';
          actionButtons = `<div class="flex items-center gap-1">${btnDetail} <span class="text-xs text-emerald-600 font-bold ml-1">Selesai</span> ${proofBtn}</div>`;
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
  async renderRevReport() { // <-- Jadikan async
    try {
      const range = document.getElementById('revRange')?.value || 'daily';

      // 1. PANGGIL API UNTUK MENDAPATKAN DATA LAPORAN YANG SUDAH JADI
      const reportData = await fetchApi(`/admin/reports/revenue?range=${range}`);

      const labels = reportData.labels;
      const data = reportData.values;

      // 2. SEMUA LOGIKA LOOPING DAN HELPER DIHAPUS.
      // Langsung render grafik dengan data dari API.
      const ctx = document.getElementById('revChart');
      if (!ctx) return;

      if (this.charts['revChart']) {
        this.charts['revChart'].destroy();
      }

      this.charts['revChart'] = new Chart(ctx, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [{
            label: 'Pendapatan',
            data: data,
            tension: 0.3,
            borderColor: '#3b82f6', //tailwind primary-500
            backgroundColor: '#dbeafe' //tailwind primary-100
          }]
        },
        options: {
          scales: {
            y: {
              beginAtZero: true
            }
          }
        }
      });

    } catch (error) {
      console.error("Gagal memuat laporan pendapatan:", error);
      // Mungkin tampilkan pesan error di canvas
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
        tbody.innerHTML = '<tr><td colspan="3" class="text-center py-4">Tidak ada data kinerja supir.</td></tr>';
        return;
      }

      // 2. SEMUA LOGIKA .map, .filter, .reduce, .sort DIHAPUS.
      // Langsung render data yang sudah matang dari API.
      tbody.innerHTML = reportData.map(driver => `
      <tr class="border-t">
        <td class="py-2">${driver.name}</td>
        <td class="py-2">${driver.trips}</td>
        <td class="py-2">${(driver.revenue || 0).toLocaleString('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 })}</td>
      </tr>
    `).join('');

    } catch (error) {
      console.error("Gagal memuat laporan kinerja supir:", error);
      tbody.innerHTML = '<tr><td colspan="3" class="text-center py-4">Gagal memuat data.</td></tr>';
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
      if (document.getElementById('mailPassword')) document.getElementById('mailPassword').value = settings.mail_password || ''; // Hati-hati menampilkan password
      if (document.getElementById('mailEncryption')) document.getElementById('mailEncryption').value = settings.mail_encryption || 'tls';
      if (document.getElementById('mailFromName')) document.getElementById('mailFromName').value = settings.mail_from_name || '';
      if (document.getElementById('waToken')) document.getElementById('waToken').value = settings.wa_token || '';
      if (document.getElementById('adminWaNumber')) document.getElementById('adminWaNumber').value = settings.admin_wa_number || '';

      // 3. QRIS Preview
      const previewQris = document.getElementById('previewQris');
      const placeholderQris = document.getElementById('placeholderQris');
      if (settings.company_qris_url && previewQris) {
        previewQris.src = settings.company_qris_url;
        previewQris.classList.remove('hidden');
        placeholderQris.classList.add('hidden');
      }

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
                  <button onclick="app.kickQueue(${q.user_id}, '${q.name}')" class="text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-slate-600 px-3 py-1 rounded text-xs font-bold border border-red-200 dark:border-red-900">
                      Kick
                  </button>
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
      await fetchApi(`/ admin / queue / ${userId} `, { method: 'DELETE' });
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
