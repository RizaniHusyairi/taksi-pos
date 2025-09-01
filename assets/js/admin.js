import { DB } from './data.js';
import { Utils } from './utils.js';

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
    formZone?.addEventListener('submit', (e)=>{
      e.preventDefault();
      const id = document.getElementById('zoneId').value || null;
      const name = document.getElementById('zoneName').value.trim();
      const price = parseInt(document.getElementById('zonePrice').value, 10) || 0;
      if(!name) return;
      DB.upsertZone({ id, name, price });
      Utils.showToast('Zona disimpan', 'success');
      formZone.reset();
      document.getElementById('zoneId').value='';
      this.renderZones();
    });
    zoneReset?.addEventListener('click', ()=>{
      document.getElementById('formZone').reset();
      document.getElementById('zoneId').value='';
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
  renderZones(){
    const zones = DB.listZones().filter(z => z.id !== 'z0');
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
      btn.addEventListener('click', ()=>{
        if(confirm('Hapus zona tujuan ini?')){
          DB.deleteZone(btn.dataset.delZone);
          this.renderZones();
        }
      });
    });
  }
  
    // ----- Users -----
  renderUsers(){
    const users = DB.listUsers();
    const tbody = document.getElementById('usersTable');
    if(!tbody) return;
    tbody.innerHTML = users.map(u=>`<tr class="border-t">
      <td class="py-2">
        <div class="font-medium">${u.name}</div>
        ${u.role==='driver' ? `<div class="text-xs text-slate-500">${u.car||'-'} • ${u.plate||'-'}</div>`:''}
      </td>
      <td class="py-2 capitalize">${u.role}</td>
      <td class="py-2">${u.username}</td>
      <td class="py-2">${u.active?'<span class="text-success font-medium">Aktif</span>':'<span class="text-slate-400">Nonaktif</span>'}</td>
      <td class="py-2">
        <button class="text-primary-700 text-sm" data-edit-u="${u.id}">Edit</button>
        <button class="text-sm ml-2 ${u.active?'text-red-600':'text-success'}" data-toggle-u="${u.id}">${u.active?'Nonaktifkan':'Aktifkan'}</button>
      </td>
    </tr>`).join('');

    tbody.querySelectorAll('[data-edit-u]').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const u = users.find(x=>x.id===btn.dataset.editU);
        const openEvent = new CustomEvent('openUserModal',{ detail: u });
        document.dispatchEvent(openEvent);
      });
    });
    document.addEventListener('openUserModal', (e)=>{
      const data = e.detail;
      document.getElementById('userModal').classList.remove('hidden');
      document.getElementById('userModalTitle').textContent = 'Edit Pengguna';
      document.getElementById('userId').value = data.id;
      document.getElementById('userName').value = data.name || '';
      document.getElementById('userRole').value = data.role || 'cso';
      document.getElementById('userUsername').value = data.username || '';
      document.getElementById('userPassword').value = data.password || '';
      document.getElementById('userCar').value = data.car || '';
      document.getElementById('userPlate').value = data.plate || '';
      document.getElementById('driverExtra').style.display = data.role==='driver' ? 'grid' : 'none';
    });

    tbody.querySelectorAll('[data-toggle-u]').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const u = users.find(x=>x.id===btn.dataset.toggleU);
        DB.setUserActive(u.id, !u.active);
        Utils.showToast('Status pengguna diubah','success');
        this.renderUsers();
      });
    });
  }

  // ----- Finance: Transaction Log -----
  renderTxLog(){
    const tbody = document.getElementById('txTable');
    if(!tbody) return;
    const tx = DB.listTransactions();
    const drivers = DB.listDrivers();
    const csos = DB.listCSOs();
    const zones = DB.listZones();
    const zname = id => zones.find(z=>z.id===id)?.name || id;
    const byId = (arr,id) => arr.find(x=>x.id===id)?.name || '-';
    const df = Utils.parseDateInput(document.getElementById('fltDateFrom')?.value);
    const dt = Utils.parseDateInput(document.getElementById('fltDateTo')?.value);
    const dId = document.getElementById('fltDriver')?.value || '';
    const cId = document.getElementById('fltCSO')?.value || '';

    const bookings = DB.listBookings();
    const row = (t)=>{
      const b = bookings.find(b=>b.id===t.bookingId);
      return `<tr class="border-t">
        <td class="py-2">${new Date(t.createdAt).toLocaleString('id-ID')}</td>
        <td class="py-2">${byId(csos, t.csoId)}</td>
        <td class="py-2">${byId(drivers, t.driverId)}</td>
        <td class="py-2">${zname(b.from)} → ${zname(b.to)}</td>
        <td class="py-2">${t.method}</td>
        <td class="py-2">${Utils.formatCurrency(t.amount)}</td>
      </tr>`;
    };

    const filtered = tx.filter(t => {
      const d = new Date(t.createdAt); d.setHours(0,0,0,0);
      if(df && d < df) return false;
      if(dt && d > dt) return false;
      if(dId && t.driverId!==dId) return false;
      if(cId && t.csoId!==cId) return false;
      return true;
    });
    tbody.innerHTML = filtered.map(row).join('');
  }

  // ----- Withdrawals -----
  renderWithdrawals(){
    const tbody = document.getElementById('wdTable');
    if(!tbody) return;
    const wds = DB.listWithdrawals();
    const drivers = DB.listDrivers();
    const byName = id => drivers.find(d=>d.id===id)?.name || id;
    tbody.innerHTML = wds.map(w=>`<tr class="border-t">
      <td class="py-2">${new Date(w.requestedAt).toLocaleString('id-ID')}</td>
      <td class="py-2">${byName(w.driverId)}</td>
      <td class="py-2">${Utils.formatCurrency(w.amount)}</td>
      <td class="py-2">${this.wdBadge(w.status)}</td>
      <td class="py-2">
        <button class="text-xs rounded border px-2 py-1" data-wd-act="Approve" data-id="${w.id}">Approve</button>
        <button class="text-xs rounded border px-2 py-1 ml-1" data-wd-act="Reject" data-id="${w.id}">Reject</button>
        <button class="text-xs rounded border px-2 py-1 ml-1 bg-success text-white" data-wd-act="Paid" data-id="${w.id}">Mark Paid</button>
      </td>
    </tr>`).join('');

    tbody.querySelectorAll('[data-wd-act]').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const act = btn.dataset.wdAct;
        DB.setWithdrawalStatus(btn.dataset.id, act);
        Utils.showToast('Status pengajuan diubah','success');
        this.renderWithdrawals();
      });
    });
  }
  wdBadge(s){
    const cls = s==='Pending'?'bg-yellow-100 text-yellow-800':(s==='Approved'?'bg-blue-100 text-blue-800':(s==='Paid'?'bg-green-100 text-green-800':'bg-slate-100 text-slate-700'));
    return `<span class="px-2 py-0.5 rounded text-xs ${cls}">${s}</span>`;
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
