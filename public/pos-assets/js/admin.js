import { Utils } from './utils.js';

// Helper function untuk memanggil API
async function fetchApi(endpoint, options = {}) {
    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        // Pastikan Anda memiliki meta tag CSRF di admin.blade.php jika menggunakan web routes
        // 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') 
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
    };

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

export class AdminApp{
  constructor(){
    this.views = ['dashboard','zones','users','finance-log','withdrawals','report-revenue','report-driver','settings'];
    this.charts = {};
  }
  init(){
    window.addEventListener('hashchange', ()=> this.route());
    this.route();
    // Panggil di sini agar dropdown terisi saat halaman dimuat
    this.populateFilterDropdowns(); 
    this.initCommon();
  }
  initCommon(){

    // Settings form
    const formSettings = document.getElementById('formSettings');
    formSettings?.addEventListener('submit', async (e) => { // <-- Jadikan async
      e.preventDefault();
      
      const rateValue = document.getElementById('commissionRate').value;
      
      // Buat payload untuk dikirim ke API
      const payload = {
        commission_rate: parseFloat(rateValue)
        // tambahkan pengaturan lain di sini jika ada
      };

      // Validasi sederhana di frontend
      if (isNaN(payload.commission_rate) || payload.commission_rate < 0 || payload.commission_rate > 100) {
        alert('Masukkan nilai persentase antara 0 dan 100');
        return;
      }

      try {
        // 2. Kirim data ke API untuk disimpan menggunakan POST
        await fetchApi('/admin/settings', {
          method: 'POST',
          body: JSON.stringify(payload)
        });
        alert('Pengaturan berhasil disimpan!');
      } catch (error) {
        console.error("Gagal menyimpan pengaturan:", error);
        alert('Gagal menyimpan pengaturan. Silakan coba lagi.');
      }
    });
    

    

    // Zones & Tariff forms
    const formZone = document.getElementById('formZone');
    const zoneReset = document.getElementById('zoneReset');


    formZone?.addEventListener('submit', async (e)=>{ // Tambahkan 'async'
      e.preventDefault();
      const id = document.getElementById('zoneId').value || null;
      const name = document.getElementById('zoneName').value.trim();
      const price = parseInt(document.getElementById('zonePrice').value, 10) || 0;
      if(!name) return;

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

    function openModal(editing=false, data=null){
      userModal.classList.remove('hidden');
      document.getElementById('userModalTitle').textContent = editing ? 'Edit Pengguna' : 'Tambah Pengguna';
      if(editing && data){
        document.getElementById('userId').value = data.id;
        document.getElementById('userName').value = data.name || '';
        document.getElementById('userRole').value = data.role || 'cso';
        document.getElementById('userUsername').value = data.username || '';
        document.getElementById('userPassword').value = data.password || '';
        document.getElementById('userCar').value = data.car || '';
        document.getElementById('userPlate').value = data.plate || '';
      }else{
        document.getElementById('formUser').reset();
        document.getElementById('userId').value = '';
      }
      toggleDriverExtra();
    }
    function closeModal(){ userModal.classList.add('hidden'); }

    function toggleDriverExtra(){
      const role = userRole.value;
      const extra = document.getElementById('driverExtra');
      extra.style.display = role==='driver' ? 'grid' : 'none';
    }

    userRole?.addEventListener('change', toggleDriverExtra);
    btnOpenCreateUser?.addEventListener('click', ()=> openModal(false));
    userModalClose?.addEventListener('click', closeModal);
    userModalCancel?.addEventListener('click', closeModal);
    userModal?.addEventListener('click', (e)=>{ if(e.target===userModal) closeModal(); });

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
    ['fltDateFrom','fltDateTo','fltDriver','fltCSO'].forEach(id=>{
      document.getElementById(id)?.addEventListener('change', ()=> this.renderTxLog());
    });

    // Revenue report range
    document.getElementById('revRange')?.addEventListener('change', ()=> this.renderRevReport());
    document.getElementById('driverRankBy')?.addEventListener('change', ()=> this.renderDriverReport());

    this.renderAll();
  }

  renderAll(){
    this.renderDashboard();
    this.renderZones();
    this.renderUsers();
    this.renderTxLog();
    this.renderWithdrawals();
    this.renderRevReport();
    this.renderDriverReport();
    this.renderSettings();
  }

  route(){
    const hash = (location.hash || '#dashboard').slice(1);
    this.views.forEach(v => {
      const el = document.getElementById('view-'+v);
      if(!el) return;
      if(v===hash){ el.classList.remove('hidden'); document.getElementById('pageTitle').textContent = this.titleOf(v); }
      else el.classList.add('hidden');
    });
    document.querySelectorAll('.nav-link').forEach(a=>{
      const target = a.getAttribute('href').replace('#','');
      if(target===hash) a.classList.add('bg-primary-50','text-primary-700');
      else a.classList.remove('bg-primary-50','text-primary-700');
    });
  }
  titleOf(v){
    return {
      'dashboard':'Dashboard',
      'zones':'Manajemen Zona & Tarif',
      'users':'Manajemen Pengguna',
      'finance-log':'Transaction Log',
      'withdrawals':'Withdrawal Requests',
      'report-revenue':'Laporan Pendapatan',
      'report-driver':'Laporan Kinerja Supir',
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
  renderLineChart(id, labels, data){
    const ctx = document.getElementById(id);
    if(this.charts[id]) this.charts[id].destroy();
    this.charts[id] = new Chart(ctx, {
      type: 'line',
      data: { labels, datasets: [{ label:'Pendapatan', data, tension:.3 }]},
      options:{ responsive:true, scales:{ y:{ beginAtZero:true } } }
    });
  }
  renderBarChart(id, labels, data){
    const ctx = document.getElementById(id);
    if(this.charts[id]) this.charts[id].destroy();
    this.charts[id] = new Chart(ctx, {
      type: 'bar',
      data: { labels, datasets: [{ label:'Pendapatan', data }]},
      options:{ responsive:true, scales:{ y:{ beginAtZero:true } } }
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
  async renderZones(){
    try{
      const zones = await fetchApi('/admin/zones'); 
      const tbody = document.getElementById('zonesTable');
      if(!tbody) return;
      tbody.innerHTML = zones.map(z=>`<tr class="border-t">
        <td class="py-2">${z.name}</td>
        <td class="py-2">${Utils.formatCurrency(z.price)}</td>
        <td class="py-2">
          <button class="text-primary-700 text-sm" data-edit-zone='${JSON.stringify(z)}'>Edit</button>
          <button class="text-red-600 text-sm ml-2" data-del-zone="${z.id}">Hapus</button>
        </td>
      </tr>`).join('');
      tbody.querySelectorAll('[data-edit-zone]').forEach(btn=>{
        btn.addEventListener('click', ()=>{
          const z = JSON.parse(btn.dataset.editZone);
          document.getElementById('zoneId').value = z.id;
          document.getElementById('zoneName').value = z.name;
          document.getElementById('zonePrice').value = z.price;
        });
      });
      tbody.querySelectorAll('[data-del-zone]').forEach(btn=>{
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
    catch(err){
      console.error("Gagal memuat data zona:", error);
      document.getElementById('zonesTable').innerHTML = '<tr><td colspan="3">Gagal memuat data.</td></tr>';
    }
    

  }
  
  // ----- Users -----
  async renderUsers(){
    try {
      // 1. GANTI DB.listUsers() DENGAN PANGGILAN API
      const users = await fetchApi('/admin/users');
      const tbody = document.getElementById('usersTable');
      if (!tbody) return;

      tbody.innerHTML = users.map(u => {
        // 2. SESUAIKAN CARA AKSES DATA SUPIR DENGAN OPTIONAL CHAINING (?.)
        const carInfo = u.role === 'driver'
          ? `<div class="text-xs text-slate-500">${u.driver_profile?.car_model || '-'} • ${u.driver_profile?.plate_number || '-'}</div>`
          : '';

        const statusBadge = u.active
          ? '<span class="text-success font-medium">Aktif</span>'
          : '<span class="text-slate-400">Nonaktif</span>';

        return `<tr class="border-t">
          <td class="py-2">
            <div class="font-medium">${u.name}</div>
            ${carInfo}
          </td>
          <td class="py-2 capitalize">${u.role}</td>
          <td class="py-2">${u.username}</td>
          <td class="py-2">${statusBadge}</td>
          <td class="py-2">
            <button class="text-primary-700 text-sm" data-edit-u='${JSON.stringify(u)}'>Edit</button>
            <button class="text-sm ml-2 ${u.active ? 'text-red-600' : 'text-green-600'}" data-toggle-u="${u.id}">
              ${u.active ? 'Nonaktifkan' : 'Aktifkan'}
            </button>
          </td>
        </tr>`;
      }).join('');

      // --- Event Listener untuk Tombol Edit ---
      tbody.querySelectorAll('[data-edit-u]').forEach(btn => {
        btn.addEventListener('click', () => {
          const userData = JSON.parse(btn.dataset.editU);
          // Panggil fungsi untuk membuka dan mengisi modal
          this.openUserModal(userData);
        });
      });
      
      // --- Event Listener untuk Tombol Toggle Status (SUDAH DIUBAH) ---
      tbody.querySelectorAll('[data-toggle-u]').forEach(btn => {
        btn.addEventListener('click', async () => {
          const userId = btn.dataset.toggleU;
          if (confirm('Anda yakin ingin mengubah status pengguna ini?')) {
            try {
              // 3. GANTI DB.setUserActive DENGAN PANGGILAN API
              await fetchApi(`/admin/users/${userId}/toggle-status`, {
                method: 'POST',
              });
              alert('Status pengguna berhasil diubah.');
              await this.renderUsers(); // Refresh tabel
            } catch (error) {
              console.error('Gagal mengubah status pengguna:', error);
              alert('Gagal mengubah status pengguna.');
            }
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
  async renderTxLog() { // <-- Jadikan fungsi ini async
    const tbody = document.getElementById('txTable');
    if (!tbody) return;

    try {
      // 1. Dapatkan nilai filter dari form
      const dateFrom = document.getElementById('fltDateFrom')?.value || '';
      const dateTo = document.getElementById('fltDateTo')?.value || '';
      const driverId = document.getElementById('fltDriver')?.value || '';
      const csoId = document.getElementById('fltCSO')?.value || '';

      // 2. Buat query string untuk URL API
      const params = new URLSearchParams();
      if (dateFrom) params.append('date_from', dateFrom);
      if (dateTo) params.append('date_to', dateTo);
      if (driverId) params.append('driver_id', driverId);
      if (csoId) params.append('cso_id', csoId);

      // 3. Panggil API dengan filter. Server akan melakukan sisanya.
      // Perhatikan bahwa properti 'data' mungkin perlu disesuaikan jika Anda menggunakan paginasi
      const response = await fetchApi(`/admin/transactions?${params.toString()}`);
      const transactions = response.data; // Jika menggunakan paginasi, data ada di properti 'data'

      if (transactions.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4">Tidak ada data transaksi yang cocok.</td></tr>';
        return;
      }

      // 4. Render data yang sudah siap dari API. Tidak perlu filter/join manual.
      tbody.innerHTML = transactions.map(t => {
        // Akses data relasi langsung dari objek transaksi
        const csoName = t.cso?.name || '-';
        const driverName = t.driver?.name || '-';
        // Asumsi rute selalu dari Bandara ke tujuan
        const route = `Bandara → ${t.booking?.zone_to?.name || 'Tujuan tidak diketahui'}`;

        return `<tr class="border-t">
          <td class="py-2">${new Date(t.created_at).toLocaleString('id-ID')}</td>
          <td class="py-2">${csoName}</td>
          <td class="py-2">${driverName}</td>
          <td class="py-2">${route}</td>
          <td class="py-2">${t.method}</td>
          <td class="py-2">${t.amount.toLocaleString('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 })}</td>
        </tr>`;
      }).join('');

    } catch (error) {
      console.error("Gagal memuat log transaksi:", error);
      tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4">Gagal memuat data.</td></tr>';
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
          // Hanya tampilkan tombol aksi jika statusnya masih 'Pending' atau 'Approved'
          const actionButtons = w.status === 'Pending' 
              ? `<button class="text-xs rounded border px-2 py-1" data-wd-act="approve" data-id="${w.id}">Approve</button>
                <button class="text-xs rounded border px-2 py-1 ml-1" data-wd-act="reject" data-id="${w.id}">Reject</button>`
              : (w.status === 'Approved' 
                  ? `<button class="text-xs rounded border px-2 py-1 ml-1 bg-success text-white" data-wd-act="paid" data-id="${w.id}">Mark Paid</button>`
                  : ''); // Jangan tampilkan tombol jika sudah Paid atau Rejected

          return `<tr class="border-t">
            <td class="py-2">${new Date(w.requested_at).toLocaleString('id-ID')}</td>
            <td class="py-2">${w.driver?.name || 'Supir tidak ditemukan'}</td>
            <td class="py-2">${w.amount.toLocaleString('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 })}</td>
            <td class="py-2">${this.wdBadge(w.status)}</td>
            <td class="py-2">${actionButtons}</td>
          </tr>`
      }).join('');

      // 3. UBAH EVENT LISTENER UNTUK MEMANGGIL API
      tbody.querySelectorAll('[data-wd-act]').forEach(btn => {
        btn.addEventListener('click', async () => { // <-- Jadikan async
          const action = btn.dataset.wdAct; // 'approve', 'reject', 'paid'
          const withdrawalId = btn.dataset.id;
          const actionText = {
              approve: 'menyetujui',
              reject: 'menolak',
              paid: 'menandai lunas'
          };

          if (confirm(`Anda yakin ingin ${actionText[action] || 'memproses'} permintaan ini?`)) {
            try {
              // Tentukan endpoint berdasarkan aksi
              let endpoint = `/admin/withdrawals/${withdrawalId}/${action}`;

              // Panggil API dengan method POST
              await fetchApi(endpoint, { method: 'POST' });
              
              alert(`Permintaan berhasil di-${action === 'paid' ? 'tandai lunas' : (action === 'approve' ? 'setujui' : 'tolak')}.`);
              await this.renderWithdrawals(); // Refresh tabel
            } catch (error) {
              console.error(`Gagal melakukan aksi '${action}':`, error);
              alert('Gagal memproses permintaan.');
            }
          }
        });
      });

    } catch (error) {
      console.error("Gagal memuat data withdrawal:", error);
      tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4">Gagal memuat data.</td></tr>';
    }
  }

  // Fungsi wdBadge tidak perlu diubah, karena ini hanya helper untuk styling
  wdBadge(s) {
      const cls = s === 'Pending' ? 'bg-yellow-100 text-yellow-800' : (s === 'Approved' ? 'bg-blue-100 text-blue-800' : (s === 'Paid' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-700'));
      return `<span class="px-2 py-0.5 rounded text-xs font-medium ${cls}">${s}</span>`;
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
async renderSettings() { // <-- Jadikan async
  try {
    // 1. Panggil API untuk mendapatkan semua pengaturan
    const settings = await fetchApi('/admin/settings');
    const rateInput = document.getElementById('commissionRate');

    if (rateInput && settings.commission_rate !== undefined) {
      // Ambil nilai komisi dari hasil API
      const rate = parseFloat(settings.commission_rate);
      // Tampilkan sebagai persen, misal 0.2 menjadi 20
      rateInput.value = (rate * 100);
    }
  } catch (error) {
    console.error("Gagal memuat pengaturan:", error);
  }
}

}
