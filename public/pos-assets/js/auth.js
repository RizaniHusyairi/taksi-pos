// Authentication & role-based redirect (demo only)
import { DB } from './data.js';

const SESSION_KEY = 'ktpos_session';

export function login(username, password){
  const user = DB.findUserByUsername(username);
  if(!user || user.password !== password || !user.active) return false;
  localStorage.setItem(SESSION_KEY, JSON.stringify({ userId: user.id, role: user.role }));
  redirectByRole(user.role);
  return true;
}

export function currentUser(){
  const s = JSON.parse(localStorage.getItem(SESSION_KEY) || 'null');
  if(!s) return null;
  return DB.listUsers().find(u => u.id===s.userId) || null;
}

export function requireRole(role){
  const u = currentUser();
  if(!u){ window.location.href = '../index.html'; return; }
  if(u.role !== role){
    redirectByRole(u.role);
  }
}

export function logout(){
  localStorage.removeItem(SESSION_KEY);
  window.location.href = '../index.html';
}

function redirectByRole(role){
  if(role==='admin') window.location.href = './pages/admin.html#dashboard';
  else if(role==='cso') window.location.href = './pages/cso.html';
  else if(role==='driver') window.location.href = './pages/driver.html';
  else window.location.href = './index.html';
}
