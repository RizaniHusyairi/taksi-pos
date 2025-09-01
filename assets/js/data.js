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
      { id: 'z0', name: 'Bandara APT Pranoto', price: 0 }, // Titik asal tetap
      { id: 'z1', name: 'Pusat Kota', price: 150000 },
      { id: 'z2', name: 'Stasiun', price: 120000 },
      { id: 'z3', name: 'Pelabuhan', price: 90000 }
    ],
    // tariffs array is removed
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

  // --- Users (no changes) ---
  listUsers(){ return this.load().users; },
  listDrivers({onlyActive=false}={}){
    return this.load().users.filter(u => u.role==='driver' && (!onlyActive || u.active));
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

  // --- Zones & Tariffs (Simplified) ---
  listZones(){ return this.load().zones; },
  getZoneById(id){ return this.load().zones.find(z => z.id === id); },
  upsertZone(zone){
    const d = this.load();
    if(!zone.id){ 
      zone.id = Utils.id('z'); 
      d.zones.push(zone); 
    } else {
      const i = d.zones.findIndex(z=>z.id===zone.id);
      if(i>=0) d.zones[i] = { ...d.zones[i], ...zone };
    }
    this.save(d);
    return zone;
  },
  deleteZone(id){
    const d = this.load();
    // Prevent deleting the base zone
    if (id === 'z0') return;
    d.zones = d.zones.filter(z=>z.id!==id);
    this.save(d);
  },
  // findTariff, listTariffs, upsertTariff, deleteTariff are removed.

  // --- Driver Status (no changes) ---
  getDriverStatus(id){ return (this.load().driverStatus || {})[id] || 'offline'; },
  setDriverStatus(id, status){
    const d = this.load(); d.driverStatus[id] = status; this.save(d);
  },

  // --- Bookings & Transactions (Simplified 'from') ---
  createBooking({csoId, driverId, from, to, price}){
    const d = this.load();
    const booking = {
      id: Utils.id('b'),
      csoId, driverId, from, to, price,
      status: 'Assigned',
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
      method,
      amount: b.price,
      createdAt: new Date().toISOString()
    };
    d.transactions.push(tx);
    if(method==='CashDriver'){ b.status = 'CashDriver'; } else { b.status='Paid'; }
    this.save(d);
    return tx;
  },
  listTransactions(){ return this.load().transactions; },

  // --- Settings ---
  commission(){ return this.load().commissionRate || 0; },
  setCommissionRate(rate){
    const d = this.load();
    d.commissionRate = rate;
    this.save(d);
  },

  // --- Withdrawals & Wallet (no changes) ---
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
  driverCredits(driverId){
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
