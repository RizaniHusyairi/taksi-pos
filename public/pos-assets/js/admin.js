import { Utils } from './utils.js';

// Helper function untuk memanggil API
async function fetchApi(endpoint, options = {}) {
    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        // Pastikan Anda memiliki meta tag CSRF di admin.blade.php jika menggunakan web routes
        // 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') 
    };

    // Asumsi token disimpan di localStorage setelah login
    const token = localStorage.getItem('authToken'); 
    if (token) {
        headers['Authorization'] = `Bearer ${token}`;
    }

    try {
        const response = await fetch(`/api${endpoint}`, { ...options, headers });
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
    this.initCommon();
  }
  initCommon(){

    // Settings form
    const formSettings = document.getElementById('formSettings');
    formSettings?.addEventListener('submit', (e) => {
      e.preventDefault();
      const rateInput = document.getElementById('commissionRate').value;
      const rateDecimal = parseFloat(rateInput) / 100;
      if (isNaN(rateDecimal) || rateDecimal < 0 || rateDecimal > 1) {
        Utils.showToast('Masukkan nilai persentase antara 0 dan 100', 'error');
        return;
      }
      DB.setCommissionRate(rateDecimal);
      Utils.showToast('Pengaturan komisi berhasil disimpan', 'success');
    });
    // Populate select filters with drivers and CSOs
    const dOpt = ['<option value="">Semua</option>']
      .concat(DB.listDrivers().map(d=>`<option value="${d.id}">${d.name}</option>`)).join('');
    const cOpt = ['<option value="">Semua</option>']
      .concat(DB.listCSOs().map(c=>`<option value="${c.id}">${c.name}</option>`)).join('');
    const fltDriver = document.getElementById('fltDriver');
    const fltCSO = document.getElementById('fltCSO');
    if(fltDriver) fltDriver.innerHTML = dOpt;
    if(fltCSO) fltCSO.innerHTML = cOpt;

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

    document.getElementById('formUser')?.addEventListener('submit', (e)=>{
      e.preventDefault();
      const id = document.getElementById('userId').value || null;
      const user = {
        id,
        name: document.getElementById('userName').value.trim(),
        role: document.getElementById('userRole').value,
        username: document.getElementById('userUsername').value.trim(),
        password: document.getElementById('userPassword').value,
        car: document.getElementById('userCar').value.trim(),
        plate: document.getElementById('userPlate').value.trim()
      };
      DB.upsertUser(user);
      Utils.showToast('Pengguna disimpan','success');
      closeModal();
      // refresh tables and filters
      this.renderUsers();
      this.initCommon();
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
  renderDashboard(){
    const tx = DB.listTransactions();
    const todayRev = tx.filter(t => Utils.isSameDay(t.createdAt, new Date())).reduce((a,b)=>a+b.amount,0);
    const driversActive = DB.listDrivers().filter(d=>DB.getDriverStatus(d.id)==='available' || DB.getDriverStatus(d.id)==='ontrip').length;
    const pendingWd = DB.listWithdrawals().filter(w=>w.status==='Pending').length;

    document.getElementById('metricRevenueToday').textContent = Utils.formatCurrency(todayRev);
    document.getElementById('metricTxCount').textContent = tx.length;
    document.getElementById('metricActiveDrivers').textContent = driversActive;
    document.getElementById('metricPendingWd').textContent = pendingWd;

    // Weekly and Monthly charts
    const weekly = this.groupRevenue('day', 7);
    const monthly= this.groupRevenue('month', 12);

    this.renderLineChart('weeklyChart', weekly.labels, weekly.values);
    this.renderBarChart('monthlyChart', monthly.labels, monthly.values);
  }
  groupRevenue(type='day', count=7){
    const tx = DB.listTransactions();
    const map = new Map();
    const now = new Date();
    for(let i=count-1;i>=0;i--){
      const d = new Date(now);
      if(type==='day'){ d.setDate(now.getDate()-i); const key=d.toISOString().slice(0,10); map.set(key,0); }
      if(type==='month'){ d.setMonth(now.getMonth()-i); const key=d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0'); map.set(key,0); }
    }
    tx.forEach(t => {
      const dt = new Date(t.createdAt);
      const key = (type==='day') ? dt.toISOString().slice(0,10)
                                 : (dt.getFullYear()+'-'+String(dt.getMonth()+1).padStart(2,'0'));
      if(map.has(key)) map.set(key, map.get(key)+t.amount);
    });
    return { labels: Array.from(map.keys()), values: Array.from(map.values()) };
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
  renderRevReport(){
    const range = document.getElementById('revRange')?.value || 'daily';
    const tx = DB.listTransactions();
    const labels=[], data=[];
    const now = new Date();
    if(range==='daily'){
      for(let i=6;i>=0;i--){
        const d = new Date(now); d.setDate(now.getDate()-i);
        const key = d.toISOString().slice(0,10);
        labels.push(key);
        data.push(tx.filter(t => t.createdAt.slice(0,10)===key).reduce((a,b)=>a+b.amount,0));
      }
    }else if(range==='weekly'){
      // last 8 weeks
      for(let i=7;i>=0;i--){
        const d = new Date(now); d.setDate(now.getDate()-i*7);
        const week = getWeek(d);
        labels.push(`${d.getFullYear()}-W${String(week).padStart(2,'0')}`);
        data.push(sumWeek(tx, d.getFullYear(), week));
      }
    }else{
      for(let i=11;i>=0;i--){
        const d = new Date(now); d.setMonth(now.getMonth()-i);
        const key = d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0');
        labels.push(key);
        data.push(tx.filter(t => (new Date(t.createdAt).getFullYear()===d.getFullYear() && new Date(t.createdAt).getMonth()===d.getMonth())).reduce((a,b)=>a+b.amount,0));
      }
    }
    const ctx = document.getElementById('revChart');
    if(this.charts['revChart']) this.charts['revChart'].destroy();
    this.charts['revChart'] = new Chart(ctx, { type:'line', data:{ labels, datasets:[{ label:'Pendapatan', data, tension:.3 }] }, options:{ scales:{ y:{ beginAtZero:true }}}});
    function getWeek(date){
      const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
      const dayNum = d.getUTCDay() || 7;
      d.setUTCDate(d.getUTCDate() + 4 - dayNum);
      const yearStart = new Date(Date.UTC(d.getUTCFullYear(),0,1));
      return Math.ceil((((d - yearStart) / 86400000) + 1)/7);
    }
    function sumWeek(tx, year, week){
      return tx.filter(t => {
        const d = new Date(t.createdAt);
        const w = getWeek(d);
        return d.getFullYear()===year && w===week;
      }).reduce((a,b)=>a+b.amount,0);
    }
  }

  renderDriverReport(){
    const by = document.getElementById('driverRankBy')?.value || 'trips';
    const drivers = DB.listDrivers();
    const bookings = DB.listBookings();
    const tx = DB.listTransactions();
    const com = DB.commission();
    const rows = drivers.map(d => {
      const myTrips = bookings.filter(b => b.driverId===d.id && b.status==='Completed');
      const myRevenue = tx.filter(t => t.driverId===d.id).reduce((a,b)=>a+b.amount,0);
      const earned = tx.filter(t => t.driverId===d.id && (t.method!=='CashDriver')).reduce((a,b)=>a+Math.round(b.amount*(1-com)),0);
      return { id:d.id, name:d.name, trips: myTrips.length, revenue: myRevenue, earned };
    });
    rows.sort((a,b)=> (by==='trips' ? b.trips - a.trips : b.revenue - a.revenue));

    const tbody = document.getElementById('driverPerfTable');
    if(!tbody) return;
    tbody.innerHTML = rows.map(r=>`<tr class="border-t">
      <td class="py-2">${r.name}</td>
      <td class="py-2">${r.trips}</td>
      <td class="py-2">${Utils.formatCurrency(r.revenue)}</td>
    </tr>`).join('');
  }

  renderSettings(){
    const rate = DB.commission();
    const rateInput = document.getElementById('commissionRate');
    if(rateInput) {
      // Tampilkan sebagai persen, misal 0.2 menjadi 20
      rateInput.value = (rate * 100).toFixed(0);
    }
  }

}
