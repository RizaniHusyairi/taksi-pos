// Simple localStorage-backed datastore for demo purposes
import { Utils } from './utils.js';

const STORAGE_KEY = 'ktpos_data';
const SESSION_KEY = 'ktpos_session';

function seed(){
  const existing = localStorage.getItem(STORAGE_KEY);
  if(existing) return;
  const data = {
    users: [
      { id:'u1', role:'admin',  name:'Admin Utama', username:'admin',  password:'123456', active:true, createdAt: new Date().toISOString() },
      { id:'u2', role:'cso',    name:'CSO A',       username:'cso1',   password:'123456', active:true, createdAt: new Date().toISOString() },
      { id:'u3', role:'driver', name:'Budi',        username:'driver1',password:'123456', active:true, car:'Avanza', plate:'B 1234 CD', createdAt: new Date().toISOString() },
      { id:'u4', role:'driver', name:'Andi',        username:'driver2',password:'123456', active:true, car:'Xenia',  plate:'B 2345 EF', createdAt: new Date().toISOString() },
      { id:'u5', role:'driver', name:'Siti',        username:'driver3',password:'123456', active:false, car:'Ertiga', plate:'B 3456 GH', createdAt: new Date().toISOString() }
    ],
    zones: [
      { id:'z1', name:'Zona Bandara' },
      { id:'z2', name:'Zona Pusat Kota' },
      { id:'z3', name:'Zona Stasiun' },
      { id:'z4', name:'Zona Pelabuhan' }
    ],
    tariffs: [
      { id:'t1', from:'z1', to:'z2', price:150000 },
      { id:'t2', from:'z1', to:'z3', price:120000 },
      { id:'t3', from:'z2', to:'z3', price:80000 },
      { id:'t4', from:'z2', to:'z4', price:90000 },
      { id:'t5', from:'z3', to:'z2', price:80000 }
    ],
    bookings: [],
    transactions: [],
    withdrawals: [],
    driverStatus: { 'u3':'available', 'u4':'available', 'u5':'offline' },
    commissionRate: 0.2,
    bank: { name: 'Bank BTN', qrisImage: 'assets/img/qris-placeholder.svg' }
  };
  localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
}
seed();

export const DB = {
  // --- low level helpers ---
  load(){ return JSON.parse(localStorage.getItem(STORAGE_KEY)); },
  save(d){ localStorage.setItem(STORAGE_KEY, JSON.stringify(d)); },
  session(){ return JSON.parse(localStorage.getItem(SESSION_KEY)); },

  // --- Users ---
  listUsers(){ return this.load().users; },
  listDrivers({onlyActive=false}={}){
    const d = this.load().users.filter(u => u.role==='driver' && (!onlyActive || u.active));
    return d;
  },
  listCSOs(){ return this.load().users.filter(u => u.role==='cso'); },
  findUserByUsername(username){
    return this.load().users.find(u => u.username===username);
  },
  upsertUser(user){
    const d = this.load();
    if(!user.id){
      user.id = Utils.id('u');
      user.createdAt = new Date().toISOString();
      user.active = true;
      d.users.push(user);
    }else{
      const idx = d.users.findIndex(u => u.id===user.id);
      if(idx>=0) d.users[idx] = { ...d.users[idx], ...user };
    }
    this.save(d);
    return user;
  },
  setUserActive(id, active){
    const d = this.load();
    const u = d.users.find(x=>x.id===id);
    if(u){ u.active = !!active; this.save(d); }
  },

  // --- Zones & Tariffs ---
  listZones(){ return this.load().zones; },
  upsertZone(zone){
    const d = this.load();
    if(!zone.id){ zone.id = Utils.id('z'); d.zones.push(zone); }
    else{
      const i = d.zones.findIndex(z=>z.id===zone.id);
      if(i>=0) d.zones[i] = { ...d.zones[i], ...zone };
    }
    this.save(d);
    return zone;
  },
  deleteZone(id){
    const d = this.load();
    d.zones = d.zones.filter(z=>z.id!==id);
    d.tariffs = d.tariffs.filter(t => t.from!==id && t.to!==id);
    this.save(d);
  },
  listTariffs(){ return this.load().tariffs; },
  upsertTariff(t){
    const d = this.load();
    if(!t.id){ t.id = Utils.id('t'); d.tariffs.push(t); }
    else{
      const i = d.tariffs.findIndex(x=>x.id===t.id);
      if(i>=0) d.tariffs[i] = { ...d.tariffs[i], ...t };
    }
    this.save(d); return t;
  },
  deleteTariff(id){
    const d = this.load(); d.tariffs = d.tariffs.filter(t=>t.id!==id); this.save(d);
  },
  findTariff(from, to){
    return this.load().tariffs.find(t => t.from===from && t.to===to);
  },

  // --- Driver Status ---
  getDriverStatus(id){ return (this.load().driverStatus || {})[id] || 'offline'; },
  setDriverStatus(id, status){
    const d = this.load(); d.driverStatus[id] = status; this.save(d);
  },

  // --- Bookings & Transactions ---
  createBooking({csoId, driverId, from, to, price}){
    const d = this.load();
    const booking = {
      id: Utils.id('b'),
      csoId, driverId, from, to, price,
      status: 'Assigned', // Assigned -> Paid/CashDriver -> Completed
      createdAt: new Date().toISOString()
    };
    d.bookings.push(booking);
    d.driverStatus[driverId] = 'ontrip';
    this.save(d);
    return booking;
  },
  listBookings(){ return this.load().bookings; },
  setBookingStatus(id, status){
    const d = this.load(); const b = d.bookings.find(x=>x.id===id);
    if(b){ b.status = status; this.save(d); }
  },
  completeBooking(id){
    const d = this.load(); const b = d.bookings.find(x=>x.id===id);
    if(b){ b.status = 'Completed'; d.driverStatus[b.driverId] = 'available'; this.save(d); }
  },

  recordPayment(bookingId, method){
    const d = this.load();
    const b = d.bookings.find(x=>x.id===bookingId);
    if(!b) throw new Error('Booking not found');
    const tx = {
      id: Utils.id('tx'),
      bookingId: b.id,
      csoId: b.csoId, driverId: b.driverId,
      method, // 'QRIS' | 'CashCSO' | 'CashDriver'
      whoReceived: method==='QRIS' ? 'QRIS' : (method==='CashCSO'?'CSO':'Driver'),
      amount: b.price,
      createdAt: new Date().toISOString()
    };
    d.transactions.push(tx);
    if(method==='CashDriver'){ b.status = 'CashDriver'; } else { b.status='Paid'; }
    this.save(d);
    return tx;
  },
  listTransactions(){ return this.load().transactions; },

  // --- Withdrawals ---
  createWithdrawal(driverId, amount){
    const d = this.load();
    const wd = {
      id: Utils.id('wd'), driverId, amount, status:'Pending',
      requestedAt: new Date().toISOString(), processedAt:null, remarks:''
    };
    d.withdrawals.push(wd); this.save(d); return wd;
  },
  listWithdrawals(){ return this.load().withdrawals; },
  setWithdrawalStatus(id, status){
    const d = this.load(); const w = d.withdrawals.find(x=>x.id===id);
    if(w){ w.status = status; w.processedAt = new Date().toISOString(); this.save(d); }
  },

  // --- Wallet calculations for drivers ---
  commission(){ return this.load().commissionRate || 0; },
  driverCredits(driverId){
    // From QRIS or CashCSO: credit amount * (1 - commission)
    const { transactions } = this.load();
    const com = this.commission();
    return transactions
      .filter(t => t.driverId===driverId && (t.method==='QRIS' || t.method==='CashCSO'))
      .map(t => ({ ...t, credit: Math.round(t.amount * (1 - com)) }));
  },
  driverWithdrawals(driverId){
    return this.load().withdrawals.filter(w => w.driverId===driverId);
  },
  driverBalance(driverId){
    const credits = this.driverCredits(driverId).reduce((a,b)=>a+b.credit,0);
    const debits  = this.driverWithdrawals(driverId)
      .filter(w => w.status==='Approved' || w.status==='Paid')
      .reduce((a,b)=>a+b.amount,0);
    return credits - debits;
  }
};
