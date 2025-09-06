// Utilities shared across modules
export const Utils = {
  id(prefix='id'){
    return prefix + '_' + Math.random().toString(36).slice(2,10);
  },
  formatCurrency(n){
    try{
      return new Intl.NumberFormat('id-ID', { style:'currency', currency:'IDR', maximumFractionDigits:0 }).format(n||0);
    }catch(e){ return 'Rp' + (n||0).toLocaleString('id-ID'); }
  },
  todayStr(){
    const d = new Date(); d.setHours(0,0,0,0); return d.toISOString();
  },
  isSameDay(a,b){
    const da = new Date(a), db = new Date(b);
    return da.getFullYear()===db.getFullYear() && da.getMonth()===db.getMonth() && da.getDate()===db.getDate();
  },
  parseDateInput(v){
    if(!v) return null;
    const d = new Date(v); d.setHours(0,0,0,0); return d;
  },
  showToast(msg, type='info'){
    let el = document.getElementById('kt-toast');
    if(!el){
      el = document.createElement('div');
      el.id = 'kt-toast';
      el.className = 'fixed bottom-4 left-1/2 -translate-x-1/2 z-50';
      document.body.appendChild(el);
    }
    const box = document.createElement('div');
    const color = type==='success' ? 'bg-emerald-600' : (type==='error'?'bg-red-600':'bg-slate-800');
    box.className = color + ' text-white px-4 py-2 rounded-lg shadow mb-2';
    box.textContent = msg;
    el.appendChild(box);
    setTimeout(()=> box.remove(), 2500);
  }
};
