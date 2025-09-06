import { DB } from './data.js';
import { Utils } from './utils.js';
import { currentUser } from './auth.js';

export class DriverApp{
  init(){
    this.u = currentUser();
    this.cacheEls();
    this.bind();
    this.renderStatus();
    this.renderActiveOrder();
    this.renderWallet();
    this.renderWithdrawals();
    this.renderWalletHistory();
    this.renderTrips();
    window.addEventListener('hashchange', ()=> this.route());
    this.route();
    // simulate "live" updates by reacting to storage changes
    window.addEventListener('storage', ()=>{
      this.renderActiveOrder(); this.renderWallet(); this.renderWithdrawals(); this.renderTrips();
    });
  }
  cacheEls(){
    this.activeBox = document.getElementById('activeOrderBox');
    this.activeInfo= document.getElementById('activeOrderInfo');
    this.btnComplete = document.getElementById('markComplete');
    this.statusBox = document.getElementById('driverStatus');
    this.btnAvail = document.getElementById('setAvail');
    this.btnOffline = document.getElementById('setOffline');
    this.walletBalance = document.getElementById('walletBalance');
    this.wdForm = document.getElementById('formWd');
  }
  bind(){
    this.btnAvail.addEventListener('click', ()=>{ DB.setDriverStatus(this.u.id,'available'); this.renderStatus(); Utils.showToast('Status: Tersedia','success'); });
    this.btnOffline.addEventListener('click', ()=>{ DB.setDriverStatus(this.u.id,'offline'); this.renderStatus(); Utils.showToast('Status: Offline'); });
    this.btnComplete.addEventListener('click', ()=>{
      const b = this.getActiveBooking();
      if(!b){ Utils.showToast('Tidak ada order aktif'); return; }
      DB.completeBooking(b.id);
      Utils.showToast('Perjalanan selesai','success');
      this.renderActiveOrder();
      this.renderStatus();
      this.renderTrips();
    });
    this.wdForm.addEventListener('submit',(e)=>{
      e.preventDefault();
      const amount = parseInt(document.getElementById('wdAmount').value,10)||0;
      const balance = DB.driverBalance(this.u.id);
      if(amount<=0){ Utils.showToast('Jumlah tidak valid','error'); return; }
      if(amount>balance){ Utils.showToast('Jumlah melebihi saldo','error'); return; }
      DB.createWithdrawal(this.u.id, amount);
      Utils.showToast('Pengajuan pencairan dikirim','success');
      document.getElementById('wdAmount').value='';
      this.renderWithdrawals(); this.renderWallet();
    });

    document.getElementById('histFilter').addEventListener('click', ()=> this.renderTrips());
  }
  route(){
    const hash = (location.hash || '#orders').slice(1);
    ['orders','wallet','history'].forEach(v=>{
      document.getElementById('view-'+v).classList.toggle('hidden', v!==hash);
    });
  }
  renderStatus(){
    const st = DB.getDriverStatus(this.u.id);
    const lbl = st==='available' ? '<span class="text-success">Tersedia</span>' : (st==='ontrip' ? '<span class="text-pending">Sedang jalan</span>' : '<span class="text-slate-500">Offline</span>');
    this.statusBox.innerHTML = `Status: ${lbl}`;
  }
  getActiveBooking(){
    return DB.listBookings().find(b => b.driverId===this.u.id && b.status!=='Completed');
  }
  renderActiveOrder(){
    const b = this.getActiveBooking();
    const zones = DB.listZones(); const z = id => zones.find(z=>z.id===id)?.name || id;
    if(b){
      this.activeBox.classList.remove('hidden');
      this.activeInfo.innerHTML = `
        <div>Rute: <span class="font-medium">${z(b.from)} → ${z(b.to)}</span></div>
        <div>Tarif: <span class="font-medium">${Utils.formatCurrency(b.price)}</span></div>
        <div>Status: <span class="font-medium">${b.status}</span></div>
      `;
      if(b.status==='Completed'){ this.activeBox.classList.add('hidden'); }
    }else{
      this.activeBox.classList.add('hidden');
    }
  }
  renderWallet(){
    const balance = DB.driverBalance(this.u.id);
    this.walletBalance.textContent = Utils.formatCurrency(balance);
  }
  renderWithdrawals(){
    const wds = DB.driverWithdrawals(this.u.id);
    const tbody = document.getElementById('wdList');
    tbody.innerHTML = wds.map(w=>`<tr class="border-t">
      <td class="py-2">${new Date(w.requestedAt).toLocaleString('id-ID')}</td>
      <td class="py-2">${Utils.formatCurrency(w.amount)}</td>
      <td class="py-2"><span class="px-2 py-0.5 rounded text-xs ${w.status==='Pending'?'bg-yellow-100 text-yellow-800':(w.status==='Approved'?'bg-blue-100 text-blue-800':(w.status==='Paid'?'bg-green-100 text-green-800':'bg-slate-100 text-slate-700'))}">${w.status}</span></td>
    </tr>`).join('');
  }
  renderWalletHistory(){
    const credits = DB.driverCredits(this.u.id).map(c => ({
      at:c.createdAt, text: `Kredit: ${c.method} (${c.bookingId})`, amount: c.credit
    }));
    const debits  = DB.driverWithdrawals(this.u.id)
      .filter(w => w.status==='Approved' || w.status==='Paid')
      .map(w => ({ at:w.processedAt||w.requestedAt, text:`Debet: Withdrawal (${w.status})`, amount: -w.amount }));
    const all = credits.concat(debits).sort((a,b)=> new Date(b.at)-new Date(a.at));
    const tbody = document.getElementById('walletTx');
    tbody.innerHTML = all.map(r=>`<tr class="border-t">
      <td class="py-2">${new Date(r.at).toLocaleString('id-ID')}</td>
      <td class="py-2">${r.text}</td>
      <td class="py-2 ${r.amount<0?'text-red-600':'text-success'}">${Utils.formatCurrency(r.amount)}</td>
    </tr>`).join('');
  }
  renderTrips(){
    const tbody = document.getElementById('tripTable');
    const from = document.getElementById('histFrom').value;
    const to   = document.getElementById('histTo').value;
    const df = from ? new Date(from) : null;
    const dt = to ? new Date(to) : null;
    if(df){ df.setHours(0,0,0,0); }
    if(dt){ dt.setHours(0,0,0,0); }
    const zones = DB.listZones(); const z = id => zones.find(z=>z.id===id)?.name || id;
    const tx = DB.listTransactions().filter(t => t.driverId===this.u.id);
    const bookings = DB.listBookings();
    const rows = tx.filter(t => {
      const d = new Date(t.createdAt); d.setHours(0,0,0,0);
      if(df && d < df) return false;
      if(dt && d > dt) return false;
      return true;
    }).map(t => {
      const b = bookings.find(b => b.id===t.bookingId);
      return { t, b };
    }).sort((a,b) => new Date(b.t.createdAt) - new Date(a.t.createdAt));

    tbody.innerHTML = rows.map(({t,b})=>`<tr class="border-t">
      <td class="py-2">${new Date(t.createdAt).toLocaleString('id-ID')}</td>
      <td class="py-2">${z(b.from)} → ${z(b.to)}</td>
      <td class="py-2">${t.method}</td>
      <td class="py-2">${Utils.formatCurrency(t.amount)}</td>
      <td class="py-2">${b.status}</td>
    </tr>`).join('');
  }
}
