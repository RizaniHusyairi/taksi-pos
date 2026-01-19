<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin - Taksi POS</title>
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: {
            primary: {
              50:'#eff6ff',100:'#dbeafe',200:'#bfdbfe',300:'#93c5fd',400:'#60a5fa',
              500:'#3b82f6',600:'#2563eb',700:'#1d4ed8',800:'#1e40af',900:'#1e3a8a'
            },
            pending:'#f59e0b', success:'#10b981'
          }
        }
      }
    }
  </script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="{{ asset('pos-assets/css/style.css') }}">
</head>
<body class="bg-slate-50 min-h-screen dark:bg-slate-900 dark:text-slate-100">
  <div id="app" class="flex">
    <!-- Sidebar -->
    <aside class="w-64 bg-white border-r border-slate-200 min-h-screen hidden md:block dark:bg-slate-800 dark:border-slate-700">
      <div class="p-4 flex items-center gap-3 border-b dark:border-slate-700">
        <div class="w-10 h-10 rounded-lg bg-primary-600 text-white grid place-items-center font-semibold">KT</div>
        <div>
          <div class="text-sm text-slate-500 dark:text-slate-400 dark:text-slate-400">Admin</div>
          <div class="font-semibold">Taksi POS</div>
        </div>
      </div>
      <nav class="p-3">
        <a href="#dashboard" class="nav-link block px-3 py-2 rounded-lg hover:bg-primary-50 text-slate-700 dark:text-slate-200 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-primary-400">Dashboard</a>
        <div class="mt-3 text-xs uppercase text-slate-400 px-3">Operasional</div>
        <a href="#queue" class="nav-link block px-3 py-2 rounded-lg hover:bg-primary-50 text-slate-700 dark:text-slate-200 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-primary-400">Manajemen Antrian</a>
        <a href="#zones" class="nav-link block px-3 py-2 rounded-lg hover:bg-primary-50 text-slate-700 dark:text-slate-200 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-primary-400">Manajemen Zona & Tarif</a>
        <a href="#users" class="nav-link block px-3 py-2 rounded-lg hover:bg-primary-50 text-slate-700 dark:text-slate-200 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-primary-400">Manajemen Pengguna</a>
        <a href="#settings" class="nav-link block px-3 py-2 rounded-lg hover:bg-primary-50 text-slate-700 dark:text-slate-200 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-primary-400">Pengaturan</a>
        <div class="mt-3 text-xs uppercase text-slate-400 px-3">Keuangan</div>
        <a href="#finance-log" class="nav-link block px-3 py-2 rounded-lg hover:bg-primary-50 text-slate-700 dark:text-slate-200 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-primary-400">Transaction Log</a>
        <a href="#withdrawals" class="nav-link block px-3 py-2 rounded-lg hover:bg-primary-50 text-slate-700 dark:text-slate-200 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-primary-400">Withdrawal Requests</a>
        <div class="mt-3 text-xs uppercase text-slate-400 px-3">Laporan</div>
        <a href="#report-revenue" class="nav-link block px-3 py-2 rounded-lg hover:bg-primary-50 text-slate-700 dark:text-slate-200 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-primary-400">Laporan Pendapatan</a>
        <a href="#report-driver" class="nav-link block px-3 py-2 rounded-lg hover:bg-primary-50 text-slate-700 dark:text-slate-200 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-primary-400">Laporan Kinerja Supir</a>
      </nav>
    </aside>

    <!-- Main -->
    <main class="flex-1">
      <!-- Topbar -->
      <header class="bg-white border-b border-slate-200 p-3 flex items-center justify-between sticky top-0 dark:bg-slate-800 dark:border-slate-700">
        <button id="mobileMenu" class="md:hidden inline-flex items-center px-3 py-2 rounded-lg border">
          ☰
        </button>
        <div class="text-sm text-slate-500 dark:text-slate-400"><span id="pageTitle" class="font-semibold">Dashboard</span></div>
        <div class="flex items-center gap-3">
          <!-- Theme Toggle -->
          <button id="themeToggle" class="p-2 text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg transition-colors" title="Toggle Dark Mode">
            <!-- Sun Icon (Default/Light) -->
            <svg id="iconMoon" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
            <!-- Moon Icon (Dark) -->
            <svg id="iconSun" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"></path>
            </svg>
          </button>

          <!-- Logout Icon -->
          <a href="{{ route('logout') }}" 
            onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
            class="p-2 text-slate-500 dark:text-slate-400 hover:text-red-600 hover:bg-red-50 dark:hover:bg-slate-700 rounded-lg transition-colors"
            title="Keluar">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
            </svg>
          </a>

          <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
              @csrf
          </form>
       </div>
      </header>

      <section id="view-dashboard" class="p-4 space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-sm p-4">
            <div class="text-sm text-slate-500 dark:text-slate-400">Total Revenue Today</div>
            <div id="metricRevenueToday" class="text-2xl font-semibold mt-1">Rp0</div>
          </div>
          <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-sm p-4">
            <div class="text-sm text-slate-500 dark:text-slate-400">Total Transactions</div>
            <div id="metricTxCount" class="text-2xl font-semibold mt-1">0</div>
          </div>
          <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-sm p-4">
            <div class="text-sm text-slate-500 dark:text-slate-400">Active Drivers</div>
            <div id="metricActiveDrivers" class="text-2xl font-semibold mt-1">0</div>
          </div>
          <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-sm p-4">
            <div class="text-sm text-slate-500 dark:text-slate-400">Pending Withdrawals</div>
            <div id="metricPendingWd" class="text-2xl font-semibold mt-1">0</div>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-sm p-4">
            <div class="flex items-center justify-between">
              <div class="font-medium">Pendapatan Mingguan</div>
            </div>
            <canvas id="weeklyChart" height="120"></canvas>
          </div>
          <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-sm p-4">
            <div class="flex items-center justify-between">
              <div class="font-medium">Pendapatan Bulanan</div>
            </div>
            <canvas id="monthlyChart" height="120"></canvas>
          </div>
        </div>
      </section>
      <section id="view-queue" class="p-4 hidden">
        <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-sm p-4">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="font-semibold text-lg">Antrian Driver (Live)</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Mengatur urutan dan status driver yang sedang standby.</p>
                </div>
                <button id="refreshQueue" class="p-2 rounded-full hover:bg-slate-100 text-slate-600 dark:text-slate-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 110 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" /></svg>
                </button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="py-3 px-4 text-left w-16">Posisi</th>
                            <th class="py-3 px-4 text-left">Nama Driver</th>
                            <th class="py-3 px-4 text-left">Nomor lambung</th>
                            <th class="py-3 px-4 text-left">Waktu Masuk</th>
                            <th class="py-3 px-4 text-center w-48">Atur Posisi</th>
                            <th class="py-3 px-4 text-center w-24">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="queueTableList" class="divide-y divide-slate-100">
                        </tbody>
                </table>
            </div>
        </div>
      </section>

      <section id="view-zones" class="p-4 hidden">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-sm p-4 lg:col-span-1">
            <h3 class="font-semibold mb-3">Tambah/Edit Zona Tujuan</h3>
            <form id="formZone" class="space-y-3">
              <input type="hidden" id="zoneId">
              <div>
                <label class="block text-sm font-medium">Nama Zona</label>
                <input id="zoneName" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400 focus:border-primary-500 focus:ring-primary-500" required>
              </div>
              <div>
                <label class="block text-sm font-medium">Tarif (Rp)</label>
                <input id="zonePrice" type="number" min="0" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400" required>
              </div>
              <div class="flex gap-2">
                <button class="bg-primary-600 hover:bg-primary-700 text-white rounded-lg px-3 py-2">Simpan</button>
                <button type="button" id="zoneReset" class="rounded-lg px-3 py-2 border">Reset</button>
              </div>
            </form>
          </div>

          <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-sm p-4 lg:col-span-2">
            <h3 class="font-semibold mb-3">Daftar Zona & Tarif Tujuan</h3>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">Semua perjalanan diasumsikan berasal dari titik pusat (misal: Bandara).</p>
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-slate-500 dark:text-slate-400">
                  <th class="py-2">Nama Zona Tujuan</th>
                  <th class="py-2">Tarif</th>
                  <th class="py-2 w-32">Aksi</th>
                </tr>
              </thead>
              <tbody id="zonesTable"></tbody>
            </table>
          </div>
        </div>
      </section>

      <section id="view-users" class="p-4 hidden">
        <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-sm p-4">
          <div class="flex items-center justify-between mb-3">
            <h3 class="font-semibold">Manajemen Pengguna</h3>
            <button id="btnOpenCreateUser" class="bg-primary-600 hover:bg-primary-700 text-white rounded-lg px-3 py-2 text-sm">Tambah Pengguna</button>
          </div>
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-slate-500 dark:text-slate-400">
                <th class="py-2">Nama</th>
                <th class="py-2">Role</th>
                <th class="py-2">Username</th>
                <th class="py-2 w-40">Aksi</th>
              </tr>
            </thead>
            <tbody id="usersTable"></tbody>
          </table>
        </div>

        <!-- Modal Create/Edit User -->
        <div id="userModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center p-4">
          <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-xl w-full max-w-lg p-4">
            <div class="flex items-center justify-between mb-2">
              <h3 id="userModalTitle" class="font-semibold">Tambah Pengguna</h3>
              <button id="userModalClose">✕</button>
            </div>
            <form id="formUser" class="space-y-3">
              <input type="hidden" id="userId">
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label class="block text-sm font-medium">Nama</label>
                  <input id="userName" required class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400"/>
                </div>
                <div>
                  <label class="block text-sm font-medium">Role</label>
                  <select id="userRole" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400">
                    <option value="cso">CSO</option>
                    <option value="driver">Supir</option>
                    <option value="admin">Admin</option>
                  </select>
                </div>
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label class="block text-sm font-medium">Username</label>
                  <input id="userUsername" required class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400"/>
                </div>
                <div>
                  <label class="block text-sm font-medium">Password</label>
                  <input id="userPassword" required class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400"/>
                </div>
              </div>
              <div class="grid grid-cols-2 gap-2" id="driverExtra" style="display:none">
                <div>
                  <label class="block text-sm font-medium">Kendaraan</label>
                  <input id="userCar" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400" placeholder="Contoh: Avanza"/>
                </div>
                <div>
                  <label class="block text-sm font-medium">Nopol</label>
                  <input id="userPlate" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400" placeholder="B 1234 CD"/>
                </div>
              </div>
              <div class="flex gap-2">
                <button class="bg-primary-600 hover:bg-primary-700 text-white rounded-lg px-3 py-2">Simpan</button>
                <button type="button" id="userModalCancel" class="rounded-lg px-3 py-2 border">Batal</button>
              </div>
            </form>
          </div>
        </div>

        <!-- Modal Confirm Delete -->
        <div id="modalConfirmDelete" class="fixed inset-0 bg-black/50 hidden items-center justify-center p-4 z-50 transition-opacity">
          <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-lg p-6 max-w-sm w-full text-center transform transition-all scale-100">
            <div class="w-14 h-14 bg-red-50 rounded-full flex items-center justify-center mx-auto mb-4">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </div>
            <h3 class="font-bold text-xl text-slate-800 dark:text-slate-100 mb-2">Hapus Pengguna?</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-6 leading-relaxed" id="msgConfirmDelete">
              Tindakan ini akan menghapus data pengguna secara permanen.
            </p>
            <div class="flex gap-3 justify-center">
              <button id="btnCancelDelete" class="flex-1 px-4 py-2.5 text-slate-600 dark:text-slate-300 font-semibold hover:bg-slate-100 rounded-xl transition-colors">
                Batal
              </button>
              <button id="btnConfirmDelete" class="flex-1 px-4 py-2.5 bg-red-600 text-white font-semibold rounded-xl hover:bg-red-700 transition-colors shadow-lg shadow-red-200">
                Ya, Hapus
              </button>
            </div>
          </div>
        </div>
      </section>

      <section id="view-finance-log" class="p-4 hidden">
        <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-sm p-4">
          <div class="grid md:grid-cols-4 gap-3 mb-3">
            <div>
              <label class="block text-xs">Tanggal Dari</label>
              <input type="date" id="fltDateFrom" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400"/>
            </div>
            <div>
              <label class="block text-xs">Tanggal Sampai</label>
              <input type="date" id="fltDateTo" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400"/>
            </div>
            <div>
              <label class="block text-xs">Supir</label>
              <select id="fltDriver" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400"></select>
            </div>
            <div>
              <label class="block text-xs">CSO</label>
              <select id="fltCSO" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400"></select>
            </div>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-slate-500 dark:text-slate-400">
                  <th class="py-2">Waktu</th>
                  <th class="py-2">CSO</th>
                  <th class="py-2">Supir</th>
                  <th class="py-2">Rute</th>
                  <th class="py-2">Metode</th>
                  <th class="py-2">Status Cair/Lunas</th> <th class="py-2">Jumlah</th>
                </tr>
              </thead>
              <tbody id="txTable"></tbody>
            </table>
          </div>
        </div>
      </section>

      <section id="view-withdrawals" class="p-4 hidden">
        <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-sm p-4">
          <h3 class="font-semibold mb-3">Permintaan Pencairan Dana</h3>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-slate-500 dark:text-slate-400">
                  <th class="py-2">Tanggal</th>
                  <th class="py-2">Supir</th>
                  <th class="py-2">Jumlah</th>
                  <th class="py-2">Status</th>
                  <th class="py-2 w-56">Aksi</th>
                </tr>
              </thead>
              <tbody id="wdTable"></tbody>
            </table>
          </div>
        </div>
      </section>
      <div id="modalUploadProof" class="fixed inset-0 bg-black/50 hidden items-center justify-center p-4 z-50">
        <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-lg p-5 max-w-sm w-full">
            <h3 class="font-bold text-lg mb-2">Setujui Pencairan</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
                Silakan transfer ke rekening supir, lalu upload bukti transfer di sini untuk menyetujui (Approve).
            </p>
            
            <form id="formUploadProof">
                <input type="hidden" id="wdIdToPay">
                <input type="file" id="fileProof" accept="image/*" class="w-full text-sm mb-4 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100" required>
                
                <div class="flex justify-end gap-2">
                    <button type="button" id="btnCancelProof" class="px-4 py-2 text-slate-600 dark:text-slate-300 text-sm">Batal</button>
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-blue-700">Setujui & Kirim</button>
                </div>
            </form>
        </div>
      </div>

      <div id="modalWdDetails" class="fixed inset-0 bg-black/50 hidden items-center justify-center p-4 z-50">
        <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-lg w-full max-w-2xl flex flex-col max-h-[90vh]">
            <div class="p-4 border-b flex justify-between items-center bg-slate-50 dark:bg-slate-700 dark:border-slate-600 rounded-t-xl">
                <div>
                    <h3 class="font-bold text-lg text-slate-800 dark:text-slate-100">Rincian Transaksi</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Daftar transaksi yang termasuk dalam pengajuan ini.</p>
                </div>
                <button id="btnCloseWdDetails" class="text-slate-400 hover:text-slate-600 dark:text-slate-300 text-2xl">&times;</button>
            </div>
            
            <div class="p-0 overflow-y-auto flex-1">
                <table class="w-full text-sm text-left">
                  <thead class="text-slate-500 dark:text-slate-400 sticky top-0">
                    <tr>
                        <th class="px-4 py-3">Tanggal</th>
                        <th class="px-4 py-3">Rute</th>
                        <th class="px-4 py-3" style="width: 120px;">Metode</th>
                        <th class="px-4 py-3 text-right">Nominal Awal</th>
                        <th class="px-4 py-3 text-right">Bersih (Ke Driver)</th>
                    </tr>
                </thead>
                
                <tbody id="wdDetailsList" class="divide-y divide-slate-100">
                    </tbody>
                
                <tfoot class=" font-bold text-slate-700 dark:text-slate-200">
                    <tr>
                        <td colspan="4" class="px-4 py-3 text-right">Total Pencairan (Net):</td>
                        <td class="px-4 py-3 text-right text-lg text-emerald-600" id="wdDetailsTotal">Rp0</td>
                    </tr>
                </tfoot>
                </table>
            </div>
            
            <div class="p-4 border-t dark:border-slate-600 flex justify-end">
                <button id="btnExitWdDetails" class="bg-slate-200 hover:bg-slate-300 text-slate-700 dark:bg-slate-600 dark:hover:bg-slate-500 dark:text-slate-200 px-4 py-2 rounded-lg text-sm font-medium">Tutup</button>
            </div>
        </div>
      </div>

      <section id="view-report-revenue" class="p-4 hidden">
        <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-sm p-4">
          <div class="flex items-center gap-2 mb-3">
            <h3 class="font-semibold">Laporan Pendapatan</h3>
            <select id="revRange" class="rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400 text-sm">
              <option value="daily">Harian (7 hari)</option>
              <option value="weekly">Mingguan (8 minggu)</option>
              <option value="monthly">Bulanan (12 bulan)</option>
            </select>
          </div>
          <canvas id="revChart" height="140"></canvas>
        </div>
      </section>

      <section id="view-report-driver" class="p-4 hidden">
        <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-sm p-4">
          <div class="flex items-center gap-2 mb-3">
            <h3 class="font-semibold">Laporan Kinerja Supir</h3>
            <select id="driverRankBy" class="rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400 text-sm">
              <option value="trips">Berdasar Jumlah Perjalanan</option>
              <option value="revenue">Berdasar Total Pendapatan</option>
            </select>
          </div>
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-slate-500 dark:text-slate-400">
                <th class="py-2">Supir</th>
                <th class="py-2">Perjalanan</th>
                <th class="py-2">Pendapatan</th>
              </tr>
            </thead>
            <tbody id="driverPerfTable"></tbody>
          </table>
        </div>
      </section>
      <section id="view-settings" class="p-4 hidden">
        <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-sm p-4 max-w-lg mx-auto">
          <h3 class="font-semibold mb-3">Pengaturan Umum</h3>
          
          <form id="formAdminPassword" class="space-y-4 mb-8 border-b pb-8">
            <h4 class="font-bold text-slate-700 dark:text-slate-200">Ganti Password Admin</h4>
            <div class="space-y-3">
              <div>
                  <label class="block text-sm font-medium text-slate-700 dark:text-slate-200">Password Saat Ini</label>
                  <input type="password" id="currentPassword" required class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400">
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-200">Password Baru</label>
                    <input type="password" id="newPassword" required minlength="6" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-200">Konfirmasi Password Baru</label>
                    <input type="password" id="confirmNewPassword" required minlength="6" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400">
                </div>
              </div>
              <button class="bg-slate-800 hover:bg-slate-900 dark:bg-slate-600 dark:hover:bg-slate-500 text-white rounded-lg px-4 py-2 text-sm font-medium">Update Password</button>
            </div>
          </form>

          <form id="formSettings" class="space-y-6"> 
            <div class="space-y-3 border-b pb-4">
              <h4 class="font-bold text-slate-700 dark:text-slate-200">Umum</h4>
              <div>
                  <label class="block text-sm font-medium text-slate-700 dark:text-slate-200">Email Notifikasi Admin</label>
                  <input type="email" id="adminEmail" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400">
              </div>
              <div>
                  <label class="block text-sm font-medium text-slate-700 dark:text-slate-200">Komisi Koperasi (%)</label>
                  <input type="number" id="commissionRate" min="0" max="100" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400">
              </div>
              
              <!-- QRIS Upload Section -->
              <div class="pt-2">
                  <label class="block text-sm font-medium text-slate-700 dark:text-slate-200 mb-1">QRIS Perusahaan</label>
                  <p class="text-xs text-slate-500 dark:text-slate-400 mb-2">Gambar ini akan muncul di aplikasi CSO saat pembayaran via QRIS.</p>
                  
                  <div class="flex items-start gap-4">
                      <div class="w-24 h-24 bg-slate-100 rounded-lg border border-slate-300 flex items-center justify-center overflow-hidden">
                          <img id="previewQris" src="" class="w-full h-full object-cover hidden">
                          <span id="placeholderQris" class="text-xs text-slate-400 text-center px-1">Belum ada QRIS</span>
                      </div>
                      <div class="flex-1">
                          <input type="file" id="companyQris" accept="image/*" class="w-full text-sm text-slate-500 dark:text-slate-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100"/>
                      </div>
                  </div>
              </div>
            </div>

            <div class="space-y-3">
                <h4 class="font-bold text-slate-700 dark:text-slate-200 flex items-center gap-2">
                    Konfigurasi SMTP
                    <span class="text-[10px] bg-yellow-100 text-yellow-800 px-2 py-0.5 rounded">Untuk Notifikasi</span>
                </h4>
                
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-slate-500 dark:text-slate-400">Mail Host</label>
                        <input type="text" id="mailHost" placeholder="smtp.gmail.com" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 dark:text-slate-400">Mail Port</label>
                        <input type="number" id="mailPort" placeholder="587" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400 text-sm">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-slate-500 dark:text-slate-400">Username (Email Pengirim)</label>
                        <input type="text" id="mailUsername" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 dark:text-slate-400">Password / App Password</label>
                        <input type="password" id="mailPassword" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400 text-sm">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-slate-500 dark:text-slate-400">Encryption</label>
                        <select id="mailEncryption" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400 text-sm">
                            <option value="tls">TLS (Port 587)</option>
                            <option value="ssl">SSL (Port 465)</option>
                            <option value="null">None</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 dark:text-slate-400">Nama Pengirim</label>
                        <input type="text" id="mailFromName" placeholder="Admin Koperasi" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400 text-sm">
                    </div>
                </div>
            </div>
            <div class="space-y-3 pt-4 border-t">
              <h4 class="font-bold text-slate-700 dark:text-slate-200 flex items-center gap-2">
                  <span class="text-green-600">
                      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                  </span>
                  Konfigurasi WhatsApp (Fonnte)
              </h4>
              
              <div class="grid grid-cols-2 gap-3">
                  <div>
                      <label class="block text-xs text-slate-500 dark:text-slate-400">API Token (Fonnte)</label>
                      <input type="text" id="waToken" placeholder="Contoh: 12345678xxxx" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400 text-sm">
                  </div>
                  <div>
                      <label class="block text-xs text-slate-500 dark:text-slate-400">Nomor WA Admin (Penerima)</label>
                      <input type="text" id="adminWaNumber" placeholder="0812xxxxxxxx" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400 text-sm">
                  </div>
              </div>
          </div>

            <div class="flex pt-2">
              <button class="bg-primary-600 hover:bg-primary-700 text-white rounded-lg px-4 py-2 w-full font-bold shadow transition-transform active:scale-95">Simpan Pengaturan</button>
            </div>
          </form>
        </div>
      </section>
    </main>
  </div>

  <script type="module">
    import { AdminApp } from '{{ asset("pos-assets/js/admin.js") }}';


    const app = new AdminApp();
    app.init();


    // Mobile sidebar toggler (simple)
    const mobileBtn = document.getElementById('mobileMenu');
    const aside = document.querySelector('aside');
    mobileBtn?.addEventListener('click', ()=>{
      if(aside.classList.contains('hidden')){ aside.classList.remove('hidden'); }
      else { aside.classList.add('hidden'); }
    });
  </script>
</body>
</html>
