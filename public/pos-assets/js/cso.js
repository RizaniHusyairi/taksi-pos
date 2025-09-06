import { DB } from './data.js';
import { Utils } from './utils.js';
import { currentUser } from './auth.js';

export class CsoApp{
  init(){
    this.u = currentUser();
    this.cacheEls();
    this.bind();
    this.renderZones();
    this.renderDrivers();
    this.renderHistory();
  }
  cacheEls(){
    this.fromSel = document.getElementById('fromZone');
    this.toSel = document.getElementById('toZone');
    this.priceBox = document.getElementById('priceBox');
    this.driversList = document.getElementById('driversList');
    this.btnConfirm = document.getElementById('btnConfirmBooking');

    // modal
    this.modal = document.getElementById('paymentModal');
    this.payInfo = document.getElementById('payInfo');
    this.btnClosePay = document.getElementById('closePayModal');
    this.btnQRIS = document.getElementById('payQRIS');
    this.btnCashCSO = document.getElementById('payCashCSO');
    this.btnCashDriver = document.getElementById('payCashDriver');
    this.qrisBox = document.getElementById('qrisBox');
    this.btnConfirmQR = document.getElementById('confirmQR');
  }
  bind(){
    const onZonesChange = ()=>{
      const from = this.fromSel.value, to = this.toSel.value;
      const t = DB.findTariff(from,to);
      this.priceBox.textContent = t ? Utils.formatCurrency(t.price) : '-';
      this.btnConfirm.disabled = !t || !this.selectedDriver;
    };
    this.fromSel.addEventListener('change', onZonesChange);
    this.toSel.addEventListener('change', onZonesChange);

    document.getElementById('navHistory').addEventListener('click', (e)=>{
      e.preventDefault(); this.toggleHistory(true);
    });
    document.getElementById('refreshHistory').addEventListener('click', ()=> this.renderHistory());

    this.btnConfirm.addEventListener('click', (e)=>{
      e.preventDefault();
      if(!this.selectedDriver){ Utils.showToast('Pilih supir terlebih dahulu', 'error'); return; }
      const from = this.fromSel.value, to = this.toSel.value;
      const t = DB.findTariff(from,to);
      if(!t){ Utils.showToast('Tarif belum diatur untuk rute ini', 'error'); return; }
      // create booking
      this.currentBooking = DB.createBooking({ csoId: this.u.id, driverId: this.selectedDriver, from, to, price: t.price });
      this.openPayment();


    });

    this.btnClosePay.addEventListener('click', ()=> this.closePayment());
    this.modal.addEventListener('click', (e)=>{ if(e.target===this.modal) this.closePayment(); });

    // payment handlers
    this.btnQRIS.addEventListener('click', ()=>{ this.qrisBox.classList.remove('hidden'); });
    this.btnConfirmQR.addEventListener('click', ()=> this.finishPayment('QRIS'));
    this.btnCashCSO.addEventListener('click', ()=> this.finishPayment('CashCSO'));
    this.btnCashDriver.addEventListener('click', ()=> this.finishPayment('CashDriver'));
    // Receipt modal controls
    document.getElementById('closeReceipt')?.addEventListener('click', ()=> this.hideReceipt());
    document.getElementById('closeReceipt2')?.addEventListener('click', ()=> this.hideReceipt());
    document.getElementById('printReceipt')?.addEventListener('click', ()=> {
  const receiptHTML = document.getElementById('receiptArea').innerHTML;

  // Semua style CSS yang dibutuhkan untuk struk disalin ke sini
  const receiptCSS = `
    .rcpt58 {
      width: 280px; font-family: "Courier New", ui-monospace, Menlo, Consolas, monospace;
      font-size: 12px; color: #111; line-height: 1.35; background: #fff; padding: 6px 8px;
    }
    .rcpt58 .row { display: flex; justify-content: space-between; align-items: baseline; }
    .rcpt58 .muted { color:#6b7280; }
    .rcpt58 .r { text-align: right; }
    .rcpt58 .c { text-align: center; }
    .rcpt58 .hr { letter-spacing: .5px; margin: 4px 0; white-space: pre; }
    .rcpt58 .hr::before { content: "--------------------------------"; }
    .rcpt58 .reprint { margin: 4px 0 6px; }
    .rcpt58 .reprint .label { display:block; }
    .rcpt58 .reprint .phone { font-weight: 700; }
    .rcpt58 .meta { margin: 6px 0; }
    .rcpt58 .code { font-size: 11px; color:#6b7280; }
    .rcpt58 .thead { margin-top: 6px; font-weight: 700; }
    .rcpt58 .thead .item { width: 60%; }
    .rcpt58 .thead .qty { width: 40%; text-align: right; }
    .rcpt58 .itemrow { margin-top: 2px; }
    .rcpt58 .itemrow .name { font-weight: 700; }
    .rcpt58 .itemrow .qtyprice { text-align:right; }
    .rcpt58 .itemrow .amt { text-align: right; }
    .rcpt58 .totals .row { margin-top: 2px; }
    .rcpt58 .currency::before { content: "Rp "; }
    .rcpt58 .foot { margin-top: 8px; }
  `;

  const win = window.open('', 'PRINT', 'height=600,width=400');
  win.document.write('<!doctype html><html><head><title>Struk Pembayaran</title>');
  win.document.write('<style>' + receiptCSS + '</style>'); // Menyematkan CSS
  win.document.write('</head><body>');
  win.document.write(receiptHTML); // Menyisipkan HTML struk
  win.document.write('</body></html>');

  win.document.close();
  win.focus();
  
  // Memberi sedikit waktu agar browser sempat merender CSS sebelum mencetak
  setTimeout(() => {
    win.print();
    win.close();
  }, 250);
});

    this.historyBody = document.getElementById('csoTxTable');

  // Delegasi klik tombol "Lihat Struk"
  this.historyBody.addEventListener('click', (e) => {
    const btn = e.target.closest('.lihat-struk-btn');
    if (!btn) return;

    const txId = btn.dataset.id;
    // Ambil transaksi + booking terkait
    const tx = (DB.getTransactionById ? DB.getTransactionById(txId)
             : DB.listTransactions().find(x => String(x.id) === String(txId)));
    if (!tx) return Utils.showToast('Transaksi tidak ditemukan', 'error');

    const booking = (DB.getBooking ? DB.getBooking(tx.bookingId)
                   : DB.listBookings().find(b => String(b.id) === String(tx.bookingId)));

    //  Tampilkan modal struk
    this.showReceipt(tx, booking);
  });
  }

  toggleHistory(show){
    document.getElementById('view-new').classList.toggle('hidden', show);
    document.getElementById('view-history').classList.toggle('hidden', !show);
  }

  renderZones(){
    const zones = DB.listZones();
    const opts = zones.map(z=>`<option value="${z.id}">${z.name}</option>`).join('');
    this.fromSel.innerHTML = '<option value="">Pilih</option>' + opts;
    this.toSel.innerHTML = '<option value="">Pilih</option>' + opts;
  }

  renderDrivers(){
    const drivers = DB.listDrivers({onlyActive:true});
    this.selectedDriver = null;
    this.driversList.innerHTML = drivers.map(d=>{
      const st = DB.getDriverStatus(d.id);
      const avail = st==='available';
      return `<button type="button" data-driver="${d.id}" class="border rounded-lg p-3 text-left ${avail?'':'opacity-50 cursor-not-allowed'}">
        <div class="font-medium">${d.name}</div>
        <div class="text-xs text-slate-500">${d.car||'-'} • ${d.plate||'-'}</div>
        <div class="text-xs mt-1 ${avail?'text-success':'text-slate-400'}">${avail?'Tersedia':(st==='ontrip'?'Sedang jalan':'Offline')}</div>
      </button>`;
    }).join('');
    this.driversList.querySelectorAll('[data-driver]').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const id = btn.dataset.driver;
        if(DB.getDriverStatus(id)!=='available') return;
        this.selectedDriver = id;
        this.driversList.querySelectorAll('button').forEach(b=>b.classList.remove('ring','ring-primary-500'));
        btn.classList.add('ring','ring-primary-500');
        const t = DB.findTariff(this.fromSel.value, this.toSel.value);
        this.btnConfirm.disabled = !t;
      });
    });
  }

  openPayment(){
    const zones = DB.listZones();
    const zname = id => zones.find(z=>z.id===id)?.name || id;
    this.qrisBox.classList.add('hidden');
    const b = this.currentBooking;
    this.payInfo.innerHTML = `
      <div class="text-sm">
        <div><span class="text-slate-500">Rute:</span> <span class="font-medium">${zname(b.from)} → ${zname(b.to)}</span></div>
        <div><span class="text-slate-500">Supir:</span> <span class="font-medium">${DB.listDrivers().find(d=>d.id===b.driverId)?.name}</span></div>
        <div><span class="text-slate-500">Tarif:</span> <span class="font-medium">${Utils.formatCurrency(b.price)}</span></div>
      </div>`;
    this.modal.classList.remove('hidden');
    this.modal.classList.add('flex');
  }
  closePayment(){ this.modal.classList.add('hidden'); this.modal.classList.remove('flex'); this.currentBooking=null; this.renderDrivers(); }

  finishPayment(method){
    const booking = this.currentBooking;
    if(!booking){
      Utils.showToast('Tidak ada booking aktif', 'error');
      return;
    }

    const tx = DB.recordPayment(booking.id, method);
    Utils.showToast('Pembayaran tercatat', 'success');

    // tutup modal bayar (ini mengosongkan this.currentBooking di closePayment())
    this.closePayment();

    // refresh riwayat CSO
    this.renderHistory();

    // tampilkan struk dengan data "booking" yang sudah disalin
    this.showReceipt(tx, booking);
  }

  renderHistory(){
    const tbody = document.getElementById('csoTxTable');
    //  const tx = DB.listTransactions({ csoId: u.id, day: new Date() });
    const tx = DB.listTransactions().filter(t => DB.session().userId===t.csoId && Utils.isSameDay(t.createdAt, new Date()));
    const zones = DB.listZones();
    const z = id => zones.find(z=>z.id===id)?.name || id;
    const drivers = DB.listDrivers();
    const dn = id => drivers.find(d=>d.id===id)?.name || id;
    const bookings = DB.listBookings();

    tbody.innerHTML = tx.map(t => {
      const b = bookings.find(b=>b.id===t.bookingId);
      return `<tr class="border-t">
        <td class="py-2">${new Date(t.createdAt).toLocaleString('id-ID')}</td>
        <td class="py-2">${z(b.from)} → ${z(b.to)}</td>
        <td class="py-2">${dn(t.driverId)}</td>
        <td class="py-2">${t.method}</td>
        <td class="py-2">${Utils.formatCurrency(t.amount)}</td>
        <td class="px-4 py-2">
        <button class="bg-blue-500 hover:bg-blue-600 text-white text-sm px-2 py-1 rounded lihat-struk-btn" data-id="${t.id}">
          Lihat Struk
        </button>
      </td>
      </tr>`;
    }).join('');
  }



  // ----- Receipt helpers -----
  generateReceiptHTML(tx, booking){
  const zones = DB.listZones();
  const z = id => zones.find(z=>z.id===id)?.name || id;

  // ====== KONFIGURASI AGAR MIRIP FOTO ======
  const MERCHANT_TOP = "";                // foto ada stempel, kita kosongkan teksnya
  const SHOW_REPRINT = true;              // tampilkan (Reprint)
  const REPRINT_PHONE = "0811519883";     // seperti foto
  const FOOTER_TEXT = "Powered by Taksi Koperasi POS"; // mengikuti foto
  // ========================================

  // format tanggal & jam: "17 Agu 2025 10:21"
  const d = new Date(tx.createdAt);
  const tanggal = d.toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }).replace('.', '');
  const waktu   = d.toLocaleTimeString('id-ID', { hour:'2-digit', minute:'2-digit' });
  const kasir   = (this.u?.name || "-").toLowerCase();
  const kode    = tx.id.toUpperCase();

  // item: "ZONA 3" (ambil angka jika ada)
  const tujuan  = z(booking.to).toUpperCase();
  const zonaNum = (tujuan.match(/\d+/)?.[0]) || tujuan.replace("ZONA ","");
  const itemName= isNaN(zonaNum) ? tujuan : `ZONA ${zonaNum}`;

  const money = (n)=> new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 0, maximumFractionDigits: 0
  }).format(n);

  const harga = booking.price;
  const amount= harga;
  const metode= tx.method.toUpperCase(); // "QRIS" | "CASHCSO" | "CASHDRIVER" -> tampilkan label CASH/QRIS
  const methodLabel = (metode === "QRIS") ? "QRIS" : "CASH";

  return `
<div class="rcpt58">
  ${MERCHANT_TOP ? `<div class="c" style="margin-bottom:4px;font-weight:700">${MERCHANT_TOP}</div>` : ``}

  <div class="reprint">
    ${SHOW_REPRINT ? `<span class="label">(Reprint)</span>` : ``}
    <span class="phone">${REPRINT_PHONE}</span>
  </div>

  <div class="meta">
    <div class="row"><div>Waktu Penjualan</div><div class="r">Kasir</div></div>
    <div class="row"><div>${tanggal} ${waktu}</div><div class="r">${kasir}</div></div>
    <div class="code">#${kode}</div>
  </div>

  <div class="hr"></div>

  <div class="thead row">
    <div class="item">Item</div>
    <div class="qty r">Jumlah</div>
  </div>

  <div class="itemrow">
    <div class="row">
      <div class="name">${itemName}</div>
      <div class="qtyprice">${money(harga)} x1</div>
    </div>
    <div class="amt">${money(amount)}</div>
  </div>

  <div class="hr"></div>

  <div class="totals">
    <div class="row"><div>Subtotal</div><div class="r">${money(amount)}</div></div>
    <div class="row"><div>Grand Total</div><div class="r"><span class="currency"></span>${money(amount)}</div></div>
    <div class="row"><div>${methodLabel}</div><div class="r"><span class="currency"></span>${money(amount)}</div></div>
  </div>

  <div class="foot c">${FOOTER_TEXT}</div>
</div>`;
}

  showReceipt(tx, booking){
    const m = document.getElementById('receiptModal');
    const area = document.getElementById('receiptArea');
    area.innerHTML = this.generateReceiptHTML(tx, booking);
    m.classList.remove('hidden'); m.classList.add('flex');
  }
  hideReceipt(){
    const m = document.getElementById('receiptModal');
    m.classList.add('hidden'); m.classList.remove('flex');
  }

}

// File: assets/js/cso.js



// Pasang event listener tombol Lihat Struk
  document.querySelectorAll(".lihat-struk-btn").forEach(btn=>{
    btn.addEventListener("click", (e)=>{
      const id = e.target.getAttribute("data-id");
      const txs = JSON.parse(localStorage.getItem("transactions") || "[]");
      const tx = txs.find(t => t.id == id);
      if (tx) {
        const receiptHTML = generateReceiptHTML(tx);
        showReceipt(receiptHTML);
      }
    });
  });