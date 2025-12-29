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
<body class="bg-slate-50 min-h-screen">
  <div id="app" class="flex">
    <!-- Sidebar -->
    <aside class="w-64 bg-white border-r border-slate-200 min-h-screen hidden md:block">
      <div class="p-4 flex items-center gap-3 border-b">
        <div class="w-10 h-10 rounded-lg bg-primary-600 text-white grid place-items-center font-semibold">KT</div>
        <div>
          <div class="text-sm text-slate-500">Admin</div>
          <div class="font-semibold">Taksi POS</div>
        </div>
      </div>
      <nav class="p-3">
        <a href="#dashboard" class="nav-link block px-3 py-2 rounded-lg hover:bg-primary-50 text-slate-700">Dashboard</a>
        <div class="mt-3 text-xs uppercase text-slate-400 px-3">Operasional</div>
        <a href="#zones" class="nav-link block px-3 py-2 rounded-lg hover:bg-primary-50 text-slate-700">Manajemen Zona & Tarif</a>
        <a href="#users" class="nav-link block px-3 py-2 rounded-lg hover:bg-primary-50 text-slate-700">Manajemen Pengguna</a>
        <a href="#settings" class="nav-link block px-3 py-2 rounded-lg hover:bg-primary-50 text-slate-700">Pengaturan</a>
        <div class="mt-3 text-xs uppercase text-slate-400 px-3">Keuangan</div>
        <a href="#finance-log" class="nav-link block px-3 py-2 rounded-lg hover:bg-primary-50 text-slate-700">Transaction Log</a>
        <a href="#withdrawals" class="nav-link block px-3 py-2 rounded-lg hover:bg-primary-50 text-slate-700">Withdrawal Requests</a>
        <div class="mt-3 text-xs uppercase text-slate-400 px-3">Laporan</div>
        <a href="#report-revenue" class="nav-link block px-3 py-2 rounded-lg hover:bg-primary-50 text-slate-700">Laporan Pendapatan</a>
        <a href="#report-driver" class="nav-link block px-3 py-2 rounded-lg hover:bg-primary-50 text-slate-700">Laporan Kinerja Supir</a>
      </nav>
    </aside>

    <!-- Main -->
    <main class="flex-1">
      <!-- Topbar -->
      <header class="bg-white border-b border-slate-200 p-3 flex items-center justify-between sticky top-0">
        <button id="mobileMenu" class="md:hidden inline-flex items-center px-3 py-2 rounded-lg border">
          ☰
        </button>
        <div class="text-sm text-slate-500"><span id="pageTitle" class="font-semibold">Dashboard</span></div>
        <div class="flex items-center gap-2">
          <a href="{{ route('logout') }}" 
            onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
            class="text-sm text-slate-600 hover:text-primary-700">
            Keluar
          </a>

          <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
              @csrf
          </form>
      </div>
      </header>

      <section id="view-dashboard" class="p-4 space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div class="bg-white rounded-xl shadow-sm p-4">
            <div class="text-sm text-slate-500">Total Revenue Today</div>
            <div id="metricRevenueToday" class="text-2xl font-semibold mt-1">Rp0</div>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-4">
            <div class="text-sm text-slate-500">Total Transactions</div>
            <div id="metricTxCount" class="text-2xl font-semibold mt-1">0</div>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-4">
            <div class="text-sm text-slate-500">Active Drivers</div>
            <div id="metricActiveDrivers" class="text-2xl font-semibold mt-1">0</div>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-4">
            <div class="text-sm text-slate-500">Pending Withdrawals</div>
            <div id="metricPendingWd" class="text-2xl font-semibold mt-1">0</div>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div class="bg-white rounded-xl shadow-sm p-4">
            <div class="flex items-center justify-between">
              <div class="font-medium">Pendapatan Mingguan</div>
            </div>
            <canvas id="weeklyChart" height="120"></canvas>
          </div>
          <div class="bg-white rounded-xl shadow-sm p-4">
            <div class="flex items-center justify-between">
              <div class="font-medium">Pendapatan Bulanan</div>
            </div>
            <canvas id="monthlyChart" height="120"></canvas>
          </div>
        </div>
      </section>

      <section id="view-zones" class="p-4 hidden">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <div class="bg-white rounded-xl shadow-sm p-4 lg:col-span-1">
            <h3 class="font-semibold mb-3">Tambah/Edit Zona Tujuan</h3>
            <form id="formZone" class="space-y-3">
              <input type="hidden" id="zoneId">
              <div>
                <label class="block text-sm font-medium">Nama Zona</label>
                <input id="zoneName" class="w-full rounded-lg border-slate-300 focus:border-primary-500 focus:ring-primary-500" required>
              </div>
              <div>
                <label class="block text-sm font-medium">Tarif (Rp)</label>
                <input id="zonePrice" type="number" min="0" class="w-full rounded-lg border-slate-300" required>
              </div>
              <div class="flex gap-2">
                <button class="bg-primary-600 hover:bg-primary-700 text-white rounded-lg px-3 py-2">Simpan</button>
                <button type="button" id="zoneReset" class="rounded-lg px-3 py-2 border">Reset</button>
              </div>
            </form>
          </div>

          <div class="bg-white rounded-xl shadow-sm p-4 lg:col-span-2">
            <h3 class="font-semibold mb-3">Daftar Zona & Tarif Tujuan</h3>
            <p class="text-xs text-slate-500 mb-3">Semua perjalanan diasumsikan berasal dari titik pusat (misal: Bandara).</p>
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-slate-500">
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
        <div class="bg-white rounded-xl shadow-sm p-4">
          <div class="flex items-center justify-between mb-3">
            <h3 class="font-semibold">Manajemen Pengguna</h3>
            <button id="btnOpenCreateUser" class="bg-primary-600 hover:bg-primary-700 text-white rounded-lg px-3 py-2 text-sm">Tambah Pengguna</button>
          </div>
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-slate-500">
                <th class="py-2">Nama</th>
                <th class="py-2">Role</th>
                <th class="py-2">Username</th>
                <th class="py-2">Status</th>
                <th class="py-2 w-40">Aksi</th>
              </tr>
            </thead>
            <tbody id="usersTable"></tbody>
          </table>
        </div>

        <!-- Modal Create/Edit User -->
        <div id="userModal" class="fixed inset-0 bg-black/40 hidden items-center justify-center p-4">
          <div class="bg-white rounded-xl shadow-xl w-full max-w-lg p-4">
            <div class="flex items-center justify-between mb-2">
              <h3 id="userModalTitle" class="font-semibold">Tambah Pengguna</h3>
              <button id="userModalClose">✕</button>
            </div>
            <form id="formUser" class="space-y-3">
              <input type="hidden" id="userId">
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label class="block text-sm font-medium">Nama</label>
                  <input id="userName" required class="w-full rounded-lg border-slate-300"/>
                </div>
                <div>
                  <label class="block text-sm font-medium">Role</label>
                  <select id="userRole" class="w-full rounded-lg border-slate-300">
                    <option value="cso">CSO</option>
                    <option value="driver">Supir</option>
                    <option value="admin">Admin</option>
                  </select>
                </div>
              </div>
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label class="block text-sm font-medium">Username</label>
                  <input id="userUsername" required class="w-full rounded-lg border-slate-300"/>
                </div>
                <div>
                  <label class="block text-sm font-medium">Password</label>
                  <input id="userPassword" required class="w-full rounded-lg border-slate-300"/>
                </div>
              </div>
              <div class="grid grid-cols-2 gap-2" id="driverExtra" style="display:none">
                <div>
                  <label class="block text-sm font-medium">Kendaraan</label>
                  <input id="userCar" class="w-full rounded-lg border-slate-300" placeholder="Contoh: Avanza"/>
                </div>
                <div>
                  <label class="block text-sm font-medium">Nopol</label>
                  <input id="userPlate" class="w-full rounded-lg border-slate-300" placeholder="B 1234 CD"/>
                </div>
              </div>
              <div class="flex gap-2">
                <button class="bg-primary-600 hover:bg-primary-700 text-white rounded-lg px-3 py-2">Simpan</button>
                <button type="button" id="userModalCancel" class="rounded-lg px-3 py-2 border">Batal</button>
              </div>
            </form>
          </div>
        </div>
      </section>

      <section id="view-finance-log" class="p-4 hidden">
        <div class="bg-white rounded-xl shadow-sm p-4">
          <div class="grid md:grid-cols-4 gap-3 mb-3">
            <div>
              <label class="block text-xs">Tanggal Dari</label>
              <input type="date" id="fltDateFrom" class="w-full rounded-lg border-slate-300"/>
            </div>
            <div>
              <label class="block text-xs">Tanggal Sampai</label>
              <input type="date" id="fltDateTo" class="w-full rounded-lg border-slate-300"/>
            </div>
            <div>
              <label class="block text-xs">Supir</label>
              <select id="fltDriver" class="w-full rounded-lg border-slate-300"></select>
            </div>
            <div>
              <label class="block text-xs">CSO</label>
              <select id="fltCSO" class="w-full rounded-lg border-slate-300"></select>
            </div>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-slate-500">
                  <th class="py-2">Waktu</th>
                  <th class="py-2">CSO</th>
                  <th class="py-2">Supir</th>
                  <th class="py-2">Rute</th>
                  <th class="py-2">Metode</th>
                  <th class="py-2">Jumlah</th>
                </tr>
              </thead>
              <tbody id="txTable"></tbody>
            </table>
          </div>
        </div>
      </section>

      <section id="view-withdrawals" class="p-4 hidden">
        <div class="bg-white rounded-xl shadow-sm p-4">
          <h3 class="font-semibold mb-3">Permintaan Pencairan Dana</h3>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-slate-500">
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
        <div class="bg-white rounded-xl shadow-lg p-5 max-w-sm w-full">
            <h3 class="font-bold text-lg mb-2">Setujui Pencairan</h3>
            <p class="text-sm text-slate-500 mb-4">
                Silakan transfer ke rekening supir, lalu upload bukti transfer di sini untuk menyetujui (Approve).
            </p>
            
            <form id="formUploadProof">
                <input type="hidden" id="wdIdToPay">
                <input type="file" id="fileProof" accept="image/*" class="w-full text-sm mb-4 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100" required>
                
                <div class="flex justify-end gap-2">
                    <button type="button" id="btnCancelProof" class="px-4 py-2 text-slate-600 text-sm">Batal</button>
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-blue-700">Setujui & Kirim</button>
                </div>
            </form>
        </div>
      </div>

      <section id="view-report-revenue" class="p-4 hidden">
        <div class="bg-white rounded-xl shadow-sm p-4">
          <div class="flex items-center gap-2 mb-3">
            <h3 class="font-semibold">Laporan Pendapatan</h3>
            <select id="revRange" class="rounded-lg border-slate-300 text-sm">
              <option value="daily">Harian (7 hari)</option>
              <option value="weekly">Mingguan (8 minggu)</option>
              <option value="monthly">Bulanan (12 bulan)</option>
            </select>
          </div>
          <canvas id="revChart" height="140"></canvas>
        </div>
      </section>

      <section id="view-report-driver" class="p-4 hidden">
        <div class="bg-white rounded-xl shadow-sm p-4">
          <div class="flex items-center gap-2 mb-3">
            <h3 class="font-semibold">Laporan Kinerja Supir</h3>
            <select id="driverRankBy" class="rounded-lg border-slate-300 text-sm">
              <option value="trips">Berdasar Jumlah Perjalanan</option>
              <option value="revenue">Berdasar Total Pendapatan</option>
            </select>
          </div>
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-slate-500">
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
        <div class="bg-white rounded-xl shadow-sm p-4 max-w-lg mx-auto">
          <h3 class="font-semibold mb-3">Pengaturan Umum</h3>
          <form id="formSettings" class="space-y-3">
            <div>
              <label for="commissionRate" class="block text-sm font-medium">Komisi Koperasi (%)</label>
              <input type="number" id="commissionRate" min="0" max="100" class="w-full rounded-lg border-slate-300" required>
              <p class="text-xs text-slate-500 mt-1">
                Bagian (dalam persen) yang diambil koperasi dari setiap transaksi (QRIS/Tunai ke CSO). Sisanya menjadi hak supir.
              </p>
            </div>
            <div class="flex">
              <button class="bg-primary-600 hover:bg-primary-700 text-white rounded-lg px-3 py-2">Simpan Pengaturan</button>
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
