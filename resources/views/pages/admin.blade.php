<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Dashboard - Premium POS</title>
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="icon" href="{{ asset('pos-assets/img/logo_taksi.png') }}" type="image/png">

  {{-- PWA: installable + cache aset statis (data tetap network-first) --}}
  @include('partials.pwa-head', [
    'appName'    => 'Admin POS',
    'themeColor' => '#0d9488',
    'manifest'   => asset('manifest-admin.webmanifest'),
  ])

  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          fontFamily: {
            sans: ['Outfit', 'sans-serif'],
            inter: ['Inter', 'sans-serif'],
          },
          colors: {
            // A rich, modern dark palette
            bgDark: '#0B0F19',
            cardDark: 'rgba(23, 32, 51, 0.7)',
            primary: {
              50: '#f0fdfa', 100: '#ccfbf1', 200: '#99f6e4', 300: '#5eead4', 400: '#2dd4bf',
              500: '#14b8a6', 600: '#0d9488', 700: '#0f766e', 800: '#115e59', 900: '#134e4a',
            },
            accent: '#38bdf8', // Light blue accent
            pending: '#f59e0b', 
            success: '#10b981',
            danger: '#ef4444'
          }
        }
      }
    }
  </script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <link rel="stylesheet" href="{{ asset('pos-assets/css/style.css') }}">
  <style>
    /* Premium Glassmorphism & Animations */
    .glass-card {
      background: rgba(255, 255, 255, 0.03);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid rgba(255, 255, 255, 0.05);
      box-shadow: 0 4px 30px rgba(0, 0, 0, 0.1);
    }
    .dark .glass-card {
      background: rgba(17, 24, 39, 0.6);
      border: 1px solid rgba(255, 255, 255, 0.08);
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    }
    .glass-card:hover {
      transform: translateY(-3px);
      border-color: rgba(255, 255, 255, 0.15);
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.4);
      transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    }
    
    .nav-btn.active {
      background: linear-gradient(135deg, rgba(20, 184, 166, 0.15), rgba(56, 189, 248, 0.1));
      border-right: 3px solid #14b8a6;
      color: #38bdf8;
      font-weight: 600;
    }
    
    .animated-bg {
      position: fixed;
      top: 0; left: 0; width: 100vw; height: 100vh;
      z-index: -1;
      background: linear-gradient(to bottom right, #0B0F19, #111827);
      overflow: hidden;
    }
    .animated-bg::before, .animated-bg::after {
      content: '';
      position: absolute;
      width: 600px; height: 600px;
      border-radius: 50%;
      filter: blur(120px);
      opacity: 0.15;
      animation: float 20s infinite alternate;
    }
    .animated-bg::before {
      background: #14b8a6;
      top: -10%; left: -10%;
    }
    .animated-bg::after {
      background: #38bdf8;
      bottom: -10%; right: -10%;
      animation-delay: -10s;
    }
    @keyframes float {
      0% { transform: translate(0, 0) scale(1); }
      100% { transform: translate(50px, 50px) scale(1.1); }
    }
    
    .text-gradient {
      background: linear-gradient(135deg, #2dd4bf, #38bdf8);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    
    /* Smooth Transitions */
    * { transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease; }
    
    /* Scrollbar */
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); }
    ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); rounded: auto; }
    ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.2); }
  </style>
</head>
<body class="bg-gray-50 text-gray-800 dark:bg-bgDark dark:text-gray-200 antialiased selection:bg-primary-500 selection:text-white font-sans">
  <div class="animated-bg dark:block hidden"></div>

  <div id="app" class="flex">
    <!-- Sidebar -->
    <aside class="w-64 glass-card border-r border-gray-200 dark:border-white/10 min-h-screen hidden md:flex flex-col relative z-10 transition-all duration-300">
      <div class="p-6 flex items-center gap-4 border-b border-gray-200 dark:border-white/10">
        <img src="{{ asset('pos-assets/img/logo_taksi.png') }}" alt="Logo" class="w-12 h-12 object-contain bg-white rounded-xl shadow-lg p-1" />
        <div>
          <div class="text-xs font-medium text-primary-600 dark:text-accent uppercase tracking-wider mb-1">Administrator</div>
          <div class="font-bold text-gray-800 dark:text-white leading-tight">POS Angkasa<br>Jaya</div>
        </div>
      </div>
      
      <div class="flex-1 overflow-y-auto py-6 px-3 custom-scrollbar">
        <nav class="space-y-1">
          <!-- Dashboard -->
          <a href="#dashboard" class="nav-link nav-btn active flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 group">
            <svg class="w-5 h-5 opacity-70 group-hover:opacity-100 group-hover:text-primary-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
            <span class="font-medium">Dashboard</span>
          </a>

          <div class="pt-6 pb-2 px-4 text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Operasional</div>
          
          <a href="#queue" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 group">
            <svg class="w-5 h-5 opacity-70 group-hover:opacity-100 group-hover:text-primary-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            <span class="font-medium">Manajemen Antrian</span>
          </a>
          
          <a href="#zones" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 group">
            <svg class="w-5 h-5 opacity-70 group-hover:opacity-100 group-hover:text-primary-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            <span class="font-medium">Zona & Tarif</span>
          </a>
          
          <a href="#users" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 group">
            <svg class="w-5 h-5 opacity-70 group-hover:opacity-100 group-hover:text-primary-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
            <span class="font-medium">Pengguna</span>
          </a>

          <a href="#settings" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 group">
            <svg class="w-5 h-5 opacity-70 group-hover:opacity-100 group-hover:text-primary-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            <span class="font-medium">Pengaturan</span>
          </a>

          <div class="pt-6 pb-2 px-4 text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Keuangan</div>
          
          <a href="#finance-log" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 group">
            <svg class="w-5 h-5 opacity-70 group-hover:opacity-100 group-hover:text-primary-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
            <span class="font-medium">Log Transaksi</span>
          </a>
          
          <a href="#withdrawals" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 group">
            <svg class="w-5 h-5 opacity-70 group-hover:opacity-100 group-hover:text-primary-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <span class="font-medium">Pencairan Dana</span>
          </a>

          <div class="pt-6 pb-2 px-4 text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Laporan</div>
          
          <a href="#report-revenue" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 group">
            <svg class="w-5 h-5 opacity-70 group-hover:opacity-100 group-hover:text-primary-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path></svg>
            <span class="font-medium">Pendapatan</span>
          </a>
          
          <a href="#report-driver" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 group">
            <svg class="w-5 h-5 opacity-70 group-hover:opacity-100 group-hover:text-primary-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
            <span class="font-medium">Kinerja Supir</span>
          </a>

          <div class="pt-6 pb-2 px-4 text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Live</div>

          <a href="#driver-map" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 group">
            <svg class="w-5 h-5 opacity-70 group-hover:opacity-100 group-hover:text-primary-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            <span class="font-medium">Peta Supir</span>
          </a>

          <div class="pt-6 pb-2 px-4 text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Sistem</div>

          <a href="#api" class="nav-link flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 group">
            <svg class="w-5 h-5 opacity-70 group-hover:opacity-100 group-hover:text-primary-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path></svg>
            <span class="font-medium">API Integrasi</span>
          </a>
        </nav>
      </div>
      
      <!-- User profile at bottom -->
      <div class="p-4 border-t border-gray-200 dark:border-white/10 mt-auto">
        <div class="glass-card rounded-xl p-3 flex items-center justify-between">
          <div class="flex items-center gap-3 overflow-hidden">
            <div class="w-8 h-8 rounded-full bg-gradient-to-tr from-accent to-primary-600 text-white flex items-center justify-center text-sm font-bold shadow-md">A</div>
            <div class="truncate">
              <p class="text-sm font-semibold text-gray-800 dark:text-white truncate">Admin System</p>
              <p class="text-[10px] text-gray-500 dark:text-gray-400 truncate">admin@taksipos.test</p>
            </div>
          </div>
        </div>
      </div>
    </aside>

    <!-- Main -->
    <main class="flex-1 flex flex-col h-screen overflow-hidden relative z-10">
      <!-- Topbar -->
      <header class="glass-card border-b border-gray-200 dark:border-white/10 px-6 py-4 flex items-center justify-between sticky top-0 z-20">
        <div class="flex items-center gap-4">
          <button id="mobileMenu" class="md:hidden inline-flex items-center p-2 rounded-xl border border-gray-200 dark:border-white/10 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition-colors">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path></svg>
          </button>
          <div>
            <h1 id="pageTitle" class="text-2xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-primary-600 to-accent dark:from-primary-400 dark:to-accent tracking-tight">Dashboard Utama</h1>
            <p class="text-xs text-gray-500 dark:text-gray-400 font-medium">Ringkasan Sistem & Performa</p>
          </div>
        </div>

        <div class="flex items-center gap-3">
          <!-- Theme Toggle -->
          <button id="themeToggle" class="p-2.5 text-gray-500 bg-white dark:bg-cardDark border border-gray-200 dark:text-gray-400 dark:border-white/10 hover:text-primary-500 hover:border-primary-200 dark:hover:border-primary-500/30 rounded-xl transition-all shadow-sm" title="Ubah Tema">
            <!-- Sun Icon (Default/Light) -->
            <svg id="iconMoon" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
            <!-- Moon Icon (Dark) -->
            <svg id="iconSun" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"></path>
            </svg>
          </button>

          <!-- Notification Bell -->
          <button class="p-2.5 text-gray-500 bg-white dark:bg-cardDark border border-gray-200 dark:text-gray-400 dark:border-white/10 hover:text-primary-500 hover:border-primary-200 dark:hover:border-primary-500/30 rounded-xl transition-all shadow-sm relative">
             <div class="absolute top-2 right-2.5 w-2 h-2 bg-red-500 rounded-full border border-white dark:border-cardDark"></div>
             <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
          </button>

          <!-- Logout Icon -->
          <a href="{{ route('logout') }}" 
            onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
            class="p-2.5 text-gray-500 bg-white dark:bg-cardDark border border-gray-200 dark:text-gray-400 dark:border-white/10 hover:text-red-500 hover:border-red-200 dark:hover:border-red-500/30 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-xl transition-all shadow-sm group"
            title="Keluar">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 transform group-hover:translate-x-0.5 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
            </svg>
          </a>

          <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
              @csrf
          </form>
       </div>
      </header>

      <!-- Scrollable Content Area -->
      <div class="flex-1 overflow-y-auto p-4 md:p-6 custom-scrollbar">

        <!-- DASHBOARD VIEW -->
        <section id="view-dashboard" class="space-y-6">
          <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">
            <!-- Metric 1 -->
            <div class="glass-card rounded-2xl p-5 relative overflow-hidden group">
              <div class="absolute -right-6 -top-6 w-24 h-24 bg-primary-500/10 rounded-full blur-xl group-hover:bg-primary-500/20 transition-all"></div>
              <div class="flex justify-between items-start mb-4">
                <div>
                  <p class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Pendapatan</p>
                  <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Hari ini</p>
                </div>
                <div class="p-2 bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400 rounded-xl">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
              </div>
              <div id="metricRevenueToday" class="text-3xl font-extrabold text-gray-800 dark:text-white tracking-tight">Rp0</div>
            </div>

            <!-- Metric 2 -->
            <div class="glass-card rounded-2xl p-5 relative overflow-hidden group">
              <div class="absolute -right-6 -top-6 w-24 h-24 bg-accent/10 rounded-full blur-xl group-hover:bg-accent/20 transition-all"></div>
              <div class="flex justify-between items-start mb-4">
                <div>
                  <p class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Transaksi</p>
                  <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">Hari ini</p>
                </div>
                <div class="p-2 bg-sky-50 dark:bg-sky-500/10 text-sky-500 dark:text-sky-400 rounded-xl">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
                </div>
              </div>
              <div id="metricTxCount" class="text-3xl font-extrabold text-gray-800 dark:text-white tracking-tight">0</div>
            </div>

            <!-- Metric 3 -->
            <div class="glass-card rounded-2xl p-5 relative overflow-hidden group">
               <div class="absolute -right-6 -top-6 w-24 h-24 bg-emerald-500/10 rounded-full blur-xl group-hover:bg-emerald-500/20 transition-all"></div>
               <div class="flex justify-between items-start mb-4">
                <div>
                  <p class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Supir Aktif</p>
                  <p class="text-xs text-green-500 mt-0.5 font-medium flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse"></span> Standby / OnTrip
                  </p>
                </div>
                <div class="p-2 bg-emerald-50 dark:bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 rounded-xl">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                </div>
              </div>
              <div id="metricActiveDrivers" class="text-3xl font-extrabold text-gray-800 dark:text-white tracking-tight">0</div>
            </div>

             <!-- Metric 4 -->
             <div class="glass-card rounded-2xl p-5 relative overflow-hidden group">
               <div class="absolute -right-6 -top-6 w-24 h-24 bg-orange-500/10 rounded-full blur-xl group-hover:bg-orange-500/20 transition-all"></div>
               <div class="flex justify-between items-start mb-4">
                <div>
                  <p class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Penarikan Saldo</p>
                  <p class="text-xs text-orange-500 mt-0.5 font-medium">Menunggu Persetujuan</p>
                </div>
                <div class="p-2 bg-orange-50 dark:bg-orange-500/10 text-orange-600 dark:text-orange-400 rounded-xl">
                   <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
              </div>
              <div id="metricPendingWd" class="text-3xl font-extrabold text-gray-800 dark:text-white tracking-tight">0</div>
            </div>
          </div>

          <!-- Charts -->
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="glass-card rounded-2xl p-5">
              <div class="flex items-center justify-between mb-4">
                <div>
                  <h3 class="font-bold text-gray-800 dark:text-white text-lg">Tren Mingguan</h3>
                  <p class="text-xs text-gray-500">Omset 7 hari terakhir</p>
                </div>
                 <div class="p-1.5 bg-gray-100 dark:bg-white/5 rounded-lg text-gray-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path></svg>
                 </div>
              </div>
              <canvas id="weeklyChart" height="100"></canvas>
            </div>
            
            <div class="glass-card rounded-2xl p-5">
              <div class="flex items-center justify-between mb-4">
                <div>
                  <h3 class="font-bold text-gray-800 dark:text-white text-lg">Tren Tahunan</h3>
                  <p class="text-xs text-gray-500">Omset per bulan di tahun ini</p>
                </div>
                 <div class="p-1.5 bg-gray-100 dark:bg-white/5 rounded-lg text-gray-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                 </div>
              </div>
              <canvas id="monthlyChart" height="100"></canvas>
            </div>
          </div>
        </section>
      <!-- QUEUE VIEW -->
      <section id="view-queue" class="hidden space-y-6">
        <div class="glass-card rounded-2xl p-5">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h3 class="font-bold text-gray-800 dark:text-white text-lg">Antrian Supir (Live)</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Mengatur urutan dan status supir standby.</p>
                </div>
                <button id="refreshQueue" class="p-2.5 rounded-xl bg-gray-100/50 hover:bg-gray-200/50 dark:bg-white/5 dark:hover:bg-white/10 text-gray-600 dark:text-gray-300 transition-colors" title="Muat Ulang">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 110 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd" /></svg>
                </button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5 bg-white/50 dark:bg-black/20 backdrop-blur-sm">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                        <tr>
                            <th class="py-4 px-5 w-16 text-center">Posisi</th>
                            <th class="py-4 px-5">Nama Supir</th>
                            <th class="py-4 px-5">No. Lambung</th>
                            <th class="py-4 px-5">Waktu Antri</th>
                            <th class="py-4 px-5 text-center w-48">Urutan</th>
                            <th class="py-4 px-5 text-center w-24">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="queueTableList" class="divide-y divide-gray-100 dark:divide-white/5">
                        <!-- Datang via JS -->
                    </tbody>
                </table>
            </div>
        </div>
      </section>

      <!-- ZONES VIEW -->
      <section id="view-zones" class="hidden space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <div class="glass-card rounded-2xl p-5 lg:col-span-1">
            <h3 class="font-bold text-gray-800 dark:text-white text-lg mb-6">Data Zona Tujuan</h3>
            <form id="formZone" class="space-y-4">
              <input type="hidden" id="zoneId">
              <div>
                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Nama Zona</label>
                <input id="zoneName" class="w-full rounded-xl border-gray-200 bg-white/50 dark:border-white/10 dark:bg-black/20 focus:ring-primary-500 focus:border-primary-500 text-sm py-2.5 px-3 transition-colors" required>
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Tarif (Rp)</label>
                <input id="zonePrice" type="number" min="0" class="w-full rounded-xl border-gray-200 bg-white/50 dark:border-white/10 dark:bg-black/20 focus:ring-primary-500 focus:border-primary-500 text-sm py-2.5 px-3 transition-colors" required>
              </div>
              <div class="flex gap-3 pt-2">
                <button class="flex-1 bg-gradient-to-r from-primary-600 to-primary-500 hover:from-primary-700 hover:to-primary-600 text-white rounded-xl py-2.5 font-semibold text-sm shadow-md shadow-primary-500/30 transition-all">Simpan</button>
                <button type="button" id="zoneReset" class="flex-1 bg-gray-100 hover:bg-gray-200 dark:bg-white/5 dark:hover:bg-white/10 text-gray-600 dark:text-gray-300 rounded-xl py-2.5 font-medium text-sm transition-colors border border-transparent dark:border-white/5">Reset</button>
              </div>
            </form>
          </div>

          <div class="glass-card rounded-2xl p-5 lg:col-span-2">
            <div class="mb-6">
              <h3 class="font-bold text-gray-800 dark:text-white text-lg">Daftar Tarif Zona</h3>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Perkiraan biaya dari titik awal (Bandara).</p>
            </div>
            <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5 bg-white/50 dark:bg-black/20 backdrop-blur-sm">
              <table class="w-full text-sm text-left">
                <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                  <tr>
                    <th class="py-4 px-5">Tujuan</th>
                    <th class="py-4 px-5 text-right">Tarif Dasar</th>
                    <th class="py-4 px-5 text-center w-32">Opsi</th>
                  </tr>
                </thead>
                <tbody id="zonesTable" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <!-- USERS VIEW -->
      <section id="view-users" class="hidden space-y-6">
        <div class="glass-card rounded-2xl p-5">
          <div class="flex items-center justify-between mb-6">
            <div>
               <h3 class="font-bold text-gray-800 dark:text-white text-lg">Akses Pengguna</h3>
               <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Kelola Admin, CSO, dan Supir</p>
            </div>
            <button id="btnOpenCreateUser" class="bg-gradient-to-r from-primary-600 to-primary-500 hover:from-primary-700 hover:to-primary-600 text-white rounded-xl px-4 py-2 text-sm font-semibold shadow-md shadow-primary-500/30 flex items-center gap-2 transition-all">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
              Tambah Baru
            </button>
          </div>
          <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5 bg-white/50 dark:bg-black/20 backdrop-blur-sm">
            <table class="w-full text-sm text-left">
              <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                <tr>
                  <th class="py-4 px-5">Profil</th>
                  <th class="py-4 px-5">Tingkat Akses</th>
                  <th class="py-4 px-5">Username</th>
                  <th class="py-4 px-5 text-right w-40">Tindakan</th>
                </tr>
              </thead>
              <tbody id="usersTable" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
            </table>
          </div>
        </div>
      </section>

        <!-- USER FORM VIEW -->
      <section id="view-user-form" class="hidden space-y-6">
        <div class="glass-card rounded-2xl p-6 lg:w-2/3 xl:w-1/2 mx-auto">
          <div class="flex items-center mb-6 border-b border-gray-100 dark:border-white/10 pb-4 gap-3">
            <button id="userModalCancel" type="button" class="text-gray-400 hover:text-gray-700 dark:hover:text-white transition-colors bg-gray-100 dark:bg-white/5 hover:bg-gray-200 dark:hover:bg-white/10 p-2 rounded-xl">
               <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
            </button>
            <h3 id="userFormTitle" class="font-bold text-xl text-gray-800 dark:text-white">Formulir Pengguna</h3>
          </div>
          <form id="formUser" class="space-y-5">
            <input type="hidden" id="userId">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
              <div>
                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Nama Lengkap</label>
                <input id="userName" required class="w-full rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50/50 dark:bg-black/20 focus:ring-2 focus:ring-primary-500/50 focus:border-primary-500 text-sm py-3 px-4 transition-all"/>
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Peran</label>
                <select id="userRole" class="w-full rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50/50 dark:bg-black/20 focus:ring-2 focus:ring-primary-500/50 focus:border-primary-500 text-sm py-3 px-4 transition-all cursor-pointer">
                  <option value="cso">CSO (Kasir)</option>
                  <option value="driver">Supir</option>
                  <option value="admin">Administrator</option>
                </select>
              </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
              <div>
                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Username Akses</label>
                <input id="userUsername" required class="w-full rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50/50 dark:bg-black/20 focus:ring-2 focus:ring-primary-500/50 focus:border-primary-500 text-sm py-3 px-4 transition-all"/>
              </div>
              <div>
                 <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Katasandi</label>
                <div class="relative">
                  <input id="userPassword" type="password" required class="w-full rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50/50 dark:bg-black/20 focus:ring-2 focus:ring-primary-500/50 focus:border-primary-500 text-sm py-3 pl-4 pr-10 transition-all" placeholder="••••••••"/>
                  <button type="button" onclick="const pass=document.getElementById('userPassword'); if(pass.type==='password'){pass.type='text';this.innerHTML='<svg class=\'w-5 h-5\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21\'></path></svg>';}else{pass.type='password';this.innerHTML='<svg class=\'w-5 h-5\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M15 12a3 3 0 11-6 0 3 3 0 016 0z\'></path><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z\'></path></svg>';}" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                  </button>
                </div>
              </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 bg-gradient-to-br from-gray-50 to-white dark:from-white/5 dark:to-transparent p-5 rounded-xl border border-gray-100 dark:border-white/5 mt-6" id="driverExtra" style="display:none">
              <div>
                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5 flex items-center gap-2">
                  <svg class="w-4 h-4 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
                  Tipe Kendaraan
                </label>
                <input id="userCar" class="w-full rounded-xl border-gray-200 bg-white/80 dark:border-white/10 dark:bg-black/40 focus:ring-primary-500 focus:border-primary-500 text-sm py-2.5 px-3" placeholder="Contoh: Innova"/>
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Nomor Plat</label>
                <input id="userPlate" class="w-full rounded-xl border-gray-200 bg-white/80 dark:border-white/10 dark:bg-black/40 focus:ring-primary-500 focus:border-primary-500 text-sm py-2.5 px-3 uppercase tracking-wider font-mono font-bold text-center" placeholder="B 1234 XY"/>
              </div>
            </div>
            <div class="pt-6 mt-6 border-t border-gray-100 dark:border-white/10 flex justify-end">
              <button class="bg-gradient-to-r from-primary-600 to-primary-500 hover:from-primary-700 hover:to-primary-600 text-white rounded-xl px-8 py-3 font-semibold text-sm shadow-lg shadow-primary-500/30 transition-all flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                Simpan Basis Data
              </button>
            </div>
          </form>
        </div>
      </section>

        <!-- MIGRATED DELETE MODAL -->
        <div id="modalConfirmDelete" class="fixed inset-0 bg-black/60 backdrop-blur-sm hidden items-center justify-center p-4 z-50 opacity-0 transition-opacity">
          <div class="glass-card bg-white/95 dark:bg-gray-900/95 rounded-2xl shadow-2xl p-6 max-w-sm w-full text-center transform scale-95 transition-all">
            <div class="w-16 h-16 bg-red-100 dark:bg-red-500/20 rounded-full border-4 border-white dark:border-gray-800 flex items-center justify-center mx-auto mb-4 shadow-sm">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </div>
             <h3 class="font-bold text-xl text-gray-800 dark:text-white mb-2">Konfirmasi Hapus</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-6 leading-relaxed px-4" id="msgConfirmDelete">
              Anda yakin? Data yang dihapus tidak dapat dikembalikan.
            </p>
            <div class="flex gap-3 justify-center">
              <button id="btnCancelDelete" class="flex-1 px-4 py-2.5 text-gray-600 dark:text-gray-300 font-semibold bg-gray-100 hover:bg-gray-200 dark:bg-white/5 dark:hover:bg-white/10 rounded-xl transition-colors">
                Batal
              </button>
              <button id="btnConfirmDelete" class="flex-1 px-4 py-2.5 bg-red-500 text-white font-semibold rounded-xl hover:bg-red-600 transition-colors shadow-lg shadow-red-500/30">
                Hancurkan
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- FINANCE LOG -->
      <section id="view-finance-log" class="hidden space-y-6">
        <div class="glass-card rounded-2xl p-5">
           <div class="flex flex-col md:flex-row gap-4 mb-6 border-b border-gray-100 dark:border-white/10 pb-6">
               <div class="flex-1 grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div>
                      <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Mulai Waktu</label>
                      <input type="date" id="fltDateFrom" class="w-full rounded-xl border-gray-200 bg-gray-50 text-gray-700 dark:border-white/10 dark:bg-black/20 dark:text-gray-200 text-sm py-2 px-3 focus:ring-primary-500 focus:border-primary-500 transition-colors"/>
                    </div>
                    <div>
                      <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Akhir Waktu</label>
                      <input type="date" id="fltDateTo" class="w-full rounded-xl border-gray-200 bg-gray-50 text-gray-700 dark:border-white/10 dark:bg-black/20 dark:text-gray-200 text-sm py-2 px-3 focus:ring-primary-500 focus:border-primary-500 transition-colors"/>
                    </div>
                    <div>
                      <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Pilah Supir</label>
                      <select id="fltDriver" class="w-full rounded-xl border-gray-200 bg-gray-50 text-gray-700 dark:border-white/10 dark:bg-black/20 dark:text-gray-200 text-sm py-2 px-3 focus:ring-primary-500 focus:border-primary-500 transition-colors cursor-pointer"></select>
                    </div>
                    <div>
                      <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Petugas/CSO</label>
                      <select id="fltCSO" class="w-full rounded-xl border-gray-200 bg-gray-50 text-gray-700 dark:border-white/10 dark:bg-black/20 dark:text-gray-200 text-sm py-2 px-3 focus:ring-primary-500 focus:border-primary-500 transition-colors cursor-pointer"></select>
                    </div>
               </div>
           </div>

           <!-- Pencarian + Metode + Export (live) -->
           <div class="flex flex-col md:flex-row items-stretch gap-3 mb-4">
             <input id="fltSearch" type="text" placeholder="🔎  Cari supir, CSO, atau destinasi..." class="flex-1 rounded-xl border-gray-200 bg-gray-50 text-gray-700 dark:border-white/10 dark:bg-black/20 dark:text-gray-200 text-sm py-2 px-4 focus:ring-primary-500 focus:border-primary-500 transition-colors"/>
             <select id="fltMethod" class="rounded-xl border-gray-200 bg-gray-50 text-gray-700 dark:border-white/10 dark:bg-black/20 dark:text-gray-200 text-sm py-2 px-3 focus:ring-primary-500 focus:border-primary-500 cursor-pointer">
               <option value="">Semua Metode</option>
               <option value="QRIS">QRIS</option>
               <option value="CashCSO">Tunai (Kasir)</option>
               <option value="CashDriver">Tunai (Supir)</option>
             </select>
             <button id="btnTxExportExcel" type="button" class="inline-flex items-center justify-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold px-4 py-2 rounded-xl shadow-sm transition-colors">
               <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 4a2 2 0 012-2h10a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V4zm5.1 3a.5.5 0 00-.42.77L9.4 10l-1.72 2.23a.5.5 0 00.42.77h1a.5.5 0 00.42-.23L10.5 11.2l.98 1.57a.5.5 0 00.42.23h1a.5.5 0 00.42-.77L11.6 10l1.72-2.23a.5.5 0 00-.42-.77h-1a.5.5 0 00-.42.23L10.5 8.8l-.98-1.57A.5.5 0 009.1 7h-1z" clip-rule="evenodd"/></svg>
               Excel
             </button>
             <button id="btnTxExportPdf" type="button" class="inline-flex items-center justify-center gap-1.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold px-4 py-2 rounded-xl shadow-sm transition-colors">
               <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a2 2 0 00-2 2v12a2 2 0 002 2h12a2 2 0 002-2V7.41A2 2 0 0017.41 6L14 2.59A2 2 0 0012.59 2H4zm3 9a1 1 0 100 2h6a1 1 0 100-2H7zm0 3a1 1 0 100 2h4a1 1 0 100-2H7z" clip-rule="evenodd"/></svg>
               PDF
             </button>
           </div>

           <!-- Ringkasan live (seluruh hasil filter) -->
           <div id="txSummary" class="grid grid-cols-2 md:grid-cols-5 gap-2 mb-4"></div>

          <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5 bg-white/50 dark:bg-black/20 backdrop-blur-sm">
            <table class="w-full text-sm text-left whitespace-nowrap">
              <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                <tr>
                  <th class="py-4 px-5">Waktu Server</th>
                  <th class="py-4 px-5">Pembuat (CSO)</th>
                  <th class="py-4 px-5">Armada Supir</th>
                  <th class="py-4 px-5">Destinasi</th>
                  <th class="py-4 px-5">Arus Uang</th>
                  <th class="py-4 px-5 text-center">Status Lunas</th>
                  <th class="py-4 px-5 text-right">Rupiah</th>
                </tr>
              </thead>
              <tbody id="txTable" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- WITHDRAWALS -->
      <section id="view-withdrawals" class="hidden space-y-6">
        <div class="glass-card rounded-2xl p-5">
           <div class="flex items-center justify-between mb-6">
               <div>
                  <h3 class="font-bold text-gray-800 dark:text-white text-lg">Permintaan Penarikan (WD)</h3>
                  <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Supir meminta pencairan saldo digital (QRIS/Transfer).</p>
               </div>
           </div>
          <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5 bg-white/50 dark:bg-black/20 backdrop-blur-sm">
            <table class="w-full text-sm text-left">
              <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                <tr>
                  <th class="py-4 px-5">Waktu Req</th>
                  <th class="py-4 px-5">Mitra Supir</th>
                  <th class="py-4 px-5 text-right">Nilai Tarik</th>
                  <th class="py-4 px-5 text-center">Status</th>
                  <th class="py-4 px-5 text-right w-56">Respons</th>
                </tr>
              </thead>
              <tbody id="wdTable" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
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

      <div id="modalActivity" class="fixed inset-0 bg-black/50 hidden items-center justify-center p-4 z-50">
        <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-lg w-full max-w-2xl flex flex-col max-h-[90vh]">
            <div class="p-4 border-b flex justify-between items-center bg-slate-50 dark:bg-slate-700 dark:border-slate-600 rounded-t-xl">
                <div>
                    <h3 class="font-bold text-lg text-slate-800 dark:text-slate-100">Log Aktivitas Supir</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Riwayat aktivitas driver terpilih.</p>
                </div>
                <button id="btnCloseActivity" class="text-slate-400 hover:text-slate-600 dark:text-slate-300 text-2xl">&times;</button>
            </div>
            
            <div class="p-0 overflow-y-auto flex-1">
                <table class="w-full text-sm text-left">
                  <thead class="text-slate-500 dark:text-slate-400 sticky top-0 bg-white dark:bg-slate-800">
                    <tr>
                        <th class="px-4 py-3">Waktu</th>
                        <th class="px-4 py-3">Jenis Aktivitas</th>
                        <th class="px-4 py-3">Keterangan</th>
                    </tr>
                </thead>
                <tbody id="activityList" class="divide-y divide-slate-100 dark:divide-slate-700">
                </tbody>
                </table>
            </div>
            
            <div class="p-4 border-t dark:border-slate-600 flex justify-end">
                <button id="btnExitActivity" class="bg-slate-200 hover:bg-slate-300 text-slate-700 dark:bg-slate-600 dark:hover:bg-slate-500 dark:text-slate-200 px-4 py-2 rounded-lg text-sm font-medium">Tutup</button>
            </div>
        </div>
      </div>

      <!-- REPORT REVENUE -->
      <section id="view-report-revenue" class="hidden space-y-6">
        <div class="glass-card rounded-2xl p-5">
           <div class="flex items-center justify-between mb-6">
               <div>
                  <h3 class="font-bold text-gray-800 dark:text-white text-lg">Laporan Pendapatan Berdasarkan Metode Bayar</h3>
                  <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Sistem pencatatan terpadu untuk arus kas POS.</p>
               </div>
           </div>

          <form id="formReportRevenue" class="mb-6 border-b border-gray-100 dark:border-white/10 pb-6 flex flex-col md:flex-row items-end gap-3">
            <div class="flex-1">
              <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Pilih Bulan</label>
              <input type="month" id="repRevMonth" class="w-full rounded-xl border-gray-200 bg-gray-50 text-gray-700 dark:border-white/10 dark:bg-black/20 dark:text-gray-200 text-sm py-2 px-3 focus:ring-primary-500 focus:border-primary-500 transition-colors" required>
            </div>
            <button type="submit" class="bg-gradient-to-r from-primary-600 to-primary-500 hover:from-primary-700 hover:to-primary-600 text-white rounded-xl px-6 py-2 h-[42px] font-semibold text-sm shadow-md shadow-primary-500/30 transition-all">
              Hasilkan Laporan
            </button>
          </form>

          <div id="repRevResult" class="hidden animate-fade-in">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
              <div class="bg-primary-50 dark:bg-primary-500/10 p-4 rounded-xl border border-primary-100 dark:border-primary-500/20">
                <div class="text-[10px] font-bold text-primary-600 dark:text-primary-400 uppercase tracking-wider mb-1">Tunai CSO</div>
                <div id="repRevCashCSO" class="text-xl font-extrabold text-gray-800 dark:text-white">Rp 0</div>
              </div>
              <div class="bg-green-50 dark:bg-emerald-500/10 p-4 rounded-xl border border-green-100 dark:border-emerald-500/20">
                <div class="text-[10px] font-bold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider mb-1">Tunai Supir</div>
                <div id="repRevCashDriver" class="text-xl font-extrabold text-gray-800 dark:text-white">Rp 0</div>
              </div>
              <div class="bg-blue-50 dark:bg-blue-500/10 p-4 rounded-xl border border-blue-100 dark:border-blue-500/20">
                <div class="text-[10px] font-bold text-blue-600 dark:text-blue-400 uppercase tracking-wider mb-1">Transfer</div>
                <div id="repRevTransfer" class="text-xl font-extrabold text-gray-800 dark:text-white">Rp 0</div>
              </div>
              <div class="bg-purple-50 dark:bg-purple-500/10 p-4 rounded-xl border border-purple-100 dark:border-purple-500/20">
                <div class="text-[10px] font-bold text-purple-600 dark:text-purple-400 uppercase tracking-wider mb-1">QRIS</div>
                <div id="repRevQris" class="text-xl font-extrabold text-gray-800 dark:text-white">Rp 0</div>
              </div>
            </div>
            
            <!-- Tambahan: Potongan Supir -->
            <div class="flex justify-end gap-6 border-t border-gray-100 dark:border-white/10 pt-4 px-2">
               <div>
                  <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1 text-right">Potongan Sistem 15%</div>
                  <div id="repRevFee" class="text-lg font-bold text-red-500 dark:text-red-400 text-right">Rp 0</div>
               </div>
               <div>
                  <div class="text-[10px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1 text-right">Total Transaksi Kotor</div>
                  <div id="repRevTotal" class="text-2xl font-extrabold text-gray-800 dark:text-white text-right">Rp 0</div>
               </div>
            </div>
          </div>
        </div>
      </section>

      <!-- REPORT DRIVER -->
      <section id="view-report-driver" class="hidden space-y-6">
        <div class="glass-card rounded-2xl p-5">
           <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6 border-b border-gray-100 dark:border-white/10 pb-6">
               <div>
                  <h3 class="font-bold text-gray-800 dark:text-white text-lg">Laporan Kinerja Supir</h3>
                  <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Total trip, pendapatan, &amp; rating dari penumpang.</p>
               </div>
               <div>
                  <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Urutkan</label>
                  <select id="driverRankBy" class="rounded-xl border-gray-200 bg-gray-50 text-gray-700 dark:border-white/10 dark:bg-black/20 dark:text-gray-200 text-sm py-2 px-3 focus:ring-primary-500 focus:border-primary-500 cursor-pointer">
                    <option value="trips">Trip Terbanyak</option>
                    <option value="revenue">Pendapatan Tertinggi</option>
                    <option value="rating">Rating Tertinggi</option>
                  </select>
               </div>
           </div>

          <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5 bg-white/50 dark:bg-black/20 backdrop-blur-sm">
            <table class="w-full text-sm text-left">
              <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                <tr>
                  <th class="py-4 px-5">Nama Supir</th>
                  <th class="py-4 px-5 text-center">Total Trip</th>
                  <th class="py-4 px-5 text-right">Pendapatan</th>
                  <th class="py-4 px-5 text-center">Rating Penumpang</th>
                </tr>
              </thead>
              <tbody id="driverPerfTable" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
            </table>
          </div>
        </div>
      </section>
      <!-- DRIVER MAP VIEW -->
      <section id="view-driver-map" class="hidden space-y-6">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div class="glass-card rounded-2xl p-5">
            <p class="text-[10px] text-gray-400 uppercase tracking-wider font-bold">Supir Aktif di Peta</p>
            <p id="mapStatOnMap" class="text-3xl font-extrabold text-gray-800 dark:text-white mt-1">0</p>
          </div>
          <div class="glass-card rounded-2xl p-5">
            <p class="text-[10px] text-gray-400 uppercase tracking-wider font-bold">Total Masuk Bandara</p>
            <p id="mapStatEntries" class="text-3xl font-extrabold text-green-600 mt-1">0</p>
          </div>
          <div class="glass-card rounded-2xl p-5">
            <p class="text-[10px] text-gray-400 uppercase tracking-wider font-bold">Total Keluar Bandara</p>
            <p id="mapStatExits" class="text-3xl font-extrabold text-amber-500 mt-1">0</p>
          </div>
        </div>

        <div class="glass-card rounded-2xl p-2 relative">
          <div class="absolute top-4 right-4 z-[500] flex gap-3 text-[10px] font-bold bg-white/90 dark:bg-slate-800/90 backdrop-blur rounded-full px-3 py-1.5 shadow">
            <span class="flex items-center gap-1 text-gray-600 dark:text-gray-300"><span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>Menunggu</span>
            <span class="flex items-center gap-1 text-gray-600 dark:text-gray-300"><span class="w-2.5 h-2.5 rounded-full bg-sky-500"></span>Mengantar</span>
            <span class="flex items-center gap-1 text-gray-600 dark:text-gray-300"><span class="w-2.5 h-2.5 rounded-full bg-slate-400"></span>Luar Area</span>
          </div>
          <button id="btnClearRoute" type="button" class="hidden absolute top-4 left-4 z-[500] flex items-center gap-1.5 text-[11px] font-bold bg-purple-600 hover:bg-purple-700 text-white rounded-full px-3 py-1.5 shadow transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            Bersihkan Rute
          </button>
          <div id="adminDriverMap" style="height: 460px; z-index: 1;" class="rounded-xl overflow-hidden bg-gray-100 dark:bg-black/20"></div>
        </div>

        <div class="glass-card rounded-2xl p-5">
          <div class="flex items-center justify-between mb-4 border-b border-gray-100 dark:border-white/10 pb-4">
            <div>
              <h3 class="font-bold text-gray-800 dark:text-white text-lg">Rekap Keluar-Masuk Bandara</h3>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Berapa kali tiap supir masuk &amp; keluar area bandara (akumulatif).</p>
            </div>
            <button id="btnRefreshMap" type="button" class="text-xs font-semibold text-primary-600 hover:text-primary-800 flex items-center gap-1.5">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
              Segarkan
            </button>
          </div>
          <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5 bg-white/50 dark:bg-black/20 backdrop-blur-sm">
            <table class="w-full text-sm text-left">
              <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                <tr>
                  <th class="py-4 px-5 text-center">#</th>
                  <th class="py-4 px-5">Nama Supir</th>
                  <th class="py-4 px-5 text-center">Masuk</th>
                  <th class="py-4 px-5 text-center">Keluar</th>
                  <th class="py-4 px-5 text-center">Posisi</th>
                </tr>
              </thead>
              <tbody id="airportRankTable" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- API INTEGRATION VIEW -->
      <section id="view-api" class="hidden space-y-6">
        <div class="glass-card rounded-2xl p-5">
          <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-5 border-b border-gray-100 dark:border-white/10 pb-5">
            <div>
              <h3 class="font-bold text-gray-800 dark:text-white text-lg">API Integrasi Koperasi</h3>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Kelola API key agar website manajemen koperasi menarik data (read-only) dari sistem ini.</p>
            </div>
            <button id="btnNewApiKey" type="button" class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-600 hover:bg-primary-700 text-white text-sm font-semibold px-4 py-2.5 transition-colors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
              Buat API Key
            </button>
          </div>

          <div id="newApiKeyBox" class="hidden mb-5 rounded-xl border border-amber-300 dark:border-amber-500/40 bg-amber-50 dark:bg-amber-500/10 p-4">
            <p class="text-xs font-bold text-amber-700 dark:text-amber-400 mb-2">⚠ Salin sekarang — kunci lengkap TIDAK akan ditampilkan lagi.</p>
            <div class="flex items-center gap-2">
              <code id="newApiKeyValue" class="flex-1 text-sm font-mono bg-white dark:bg-black/30 rounded-lg px-3 py-2 break-all text-gray-800 dark:text-gray-100"></code>
              <button id="btnCopyApiKey" type="button" class="shrink-0 rounded-lg bg-amber-600 hover:bg-amber-700 text-white text-xs font-semibold px-3 py-2 transition-colors">Salin</button>
            </div>
          </div>

          <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5 bg-white/50 dark:bg-black/20 backdrop-blur-sm">
            <table class="w-full text-sm text-left">
              <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                <tr>
                  <th class="py-4 px-5">Nama Klien</th>
                  <th class="py-4 px-5">Prefix Key</th>
                  <th class="py-4 px-5">Terakhir Dipakai</th>
                  <th class="py-4 px-5 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody id="apiClientsTable" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
            </table>
          </div>
        </div>

        <div class="glass-card rounded-2xl p-5">
          <h3 class="font-bold text-gray-800 dark:text-white text-lg mb-1">Dokumentasi Endpoint</h3>
          <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Berikan info ini ke developer sistem koperasi. Semua endpoint <b>read-only</b>.</p>
          <div class="space-y-3">
            <div class="rounded-lg bg-gray-50 dark:bg-black/20 p-3">
              <p class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mb-1">Base URL</p>
              <code class="font-mono text-sm text-primary-600 dark:text-primary-400 break-all">{{ url('/api/v1/management') }}</code>
            </div>
            <div class="rounded-lg bg-gray-50 dark:bg-black/20 p-3">
              <p class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mb-1">Autentikasi (header tiap request)</p>
              <code class="font-mono text-sm text-gray-700 dark:text-gray-200 break-all">Authorization: Bearer &lt;api_key&gt;</code>
            </div>
            <div class="rounded-lg bg-gray-50 dark:bg-black/20 p-3 divide-y divide-gray-200/70 dark:divide-white/5">
              @foreach ([
                ['me', '/me', '— cek koneksi'],
                ['transactions', '/transactions', '?date_from=&date_to=&per_page='],
                ['revenue/summary', '/revenue/summary', '?date_from=&date_to='],
                ['drivers', '/drivers', '— daftar supir'],
                ['withdrawals', '/withdrawals', '?status='],
              ] as $ep)
              <div class="flex items-center justify-between gap-3 py-1.5">
                <p class="font-mono text-xs text-gray-700 dark:text-gray-200 truncate"><span class="text-green-600 font-bold">GET</span> {{ $ep[1] }} <span class="text-gray-400">{{ $ep[2] }}</span></p>
                <button data-try="{{ $ep[0] }}" type="button" class="shrink-0 text-[11px] font-semibold text-primary-600 hover:text-white hover:bg-primary-600 border border-primary-300 dark:border-primary-500/40 rounded-md px-2.5 py-1 transition-colors">Coba ▸</button>
              </div>
              @endforeach
            </div>

            <div id="apiPreviewPanel" class="hidden">
              <div class="flex items-center justify-between mb-1.5">
                <p class="text-[10px] uppercase tracking-wider text-gray-400 font-bold">Response <span id="apiPreviewEp" class="text-primary-500 font-mono normal-case"></span></p>
                <span id="apiPreviewStatus" class="text-xs font-bold"></span>
              </div>
              <pre id="apiPreviewBody" class="text-xs font-mono leading-relaxed bg-slate-900 text-green-300 rounded-lg p-3.5 overflow-auto max-h-96 whitespace-pre"></pre>
              <p class="text-[10px] text-gray-400 mt-1.5">Pratinjau lewat akun admin — datanya sama dengan yang diterima sistem eksternal (yang wajib memakai API key).</p>
            </div>
          </div>
        </div>
      </section>

      <!-- SETTINGS VIEW -->
      <section id="view-settings" class="hidden space-y-6">
        <div class="glass-card rounded-2xl p-5 max-w-2xl mx-auto">
          <div class="mb-8 border-b border-gray-100 dark:border-white/10 pb-6">
            <h3 class="font-bold text-gray-800 dark:text-white text-xl">Pengaturan Sistem</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Konfigurasi akun admin, komisi, dan parameter sistem lainnya.</p>
          </div>
          
          <form id="formAdminPassword" class="space-y-5 mb-8 border-b border-gray-100 dark:border-white/10 pb-8">
            <h4 class="font-bold text-gray-700 dark:text-gray-200 text-lg flex items-center gap-2">
               <svg class="w-5 h-5 text-primary-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
               Ganti Kata Sandi Admin
            </h4>
            <div class="space-y-4">
              <div>
                  <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Kata Sandi Saat Ini</label>
                  <div class="relative">
                    <input type="password" id="currentPassword" required class="w-full rounded-xl border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-black/20 focus:ring-primary-500 focus:border-primary-500 text-sm py-2.5 pl-3 pr-10 transition-colors">
                    <button type="button" onclick="const pass=document.getElementById('currentPassword'); if(pass.type==='password'){pass.type='text';this.innerHTML='<svg class=\'w-5 h-5\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21\'></path></svg>';}else{pass.type='password';this.innerHTML='<svg class=\'w-5 h-5\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M15 12a3 3 0 11-6 0 3 3 0 016 0z\'></path><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z\'></path></svg>';}" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                      </svg>
                    </button>
                  </div>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Kata Sandi Baru</label>
                    <div class="relative">
                      <input type="password" id="newPassword" required minlength="6" class="w-full rounded-xl border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-black/20 focus:ring-primary-500 focus:border-primary-500 text-sm py-2.5 pl-3 pr-10 transition-colors">
                      <button type="button" onclick="const pass=document.getElementById('newPassword'); if(pass.type==='password'){pass.type='text';this.innerHTML='<svg class=\'w-5 h-5\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21\'></path></svg>';}else{pass.type='password';this.innerHTML='<svg class=\'w-5 h-5\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M15 12a3 3 0 11-6 0 3 3 0 016 0z\'></path><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z\'></path></svg>';}" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                      </button>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Ulangi Kata Sandi Baru</label>
                    <div class="relative">
                      <input type="password" id="confirmNewPassword" required minlength="6" class="w-full rounded-xl border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-black/20 focus:ring-primary-500 focus:border-primary-500 text-sm py-2.5 pl-3 pr-10 transition-colors">
                      <button type="button" onclick="const pass=document.getElementById('confirmNewPassword'); if(pass.type==='password'){pass.type='text';this.innerHTML='<svg class=\'w-5 h-5\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21\'></path></svg>';}else{pass.type='password';this.innerHTML='<svg class=\'w-5 h-5\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M15 12a3 3 0 11-6 0 3 3 0 016 0z\'></path><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z\'></path></svg>';}" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                      </button>
                    </div>
                </div>
              </div>
              <button class="bg-gray-800 hover:bg-gray-900 dark:bg-white dark:hover:bg-gray-100 dark:text-gray-900 text-white rounded-xl px-5 py-2.5 text-sm font-semibold transition-colors shadow-md">Perbarui Kata Sandi</button>
            </div>
          </form>

          <form id="formSettings" class="space-y-8"> 
            <div class="space-y-4 border-b border-gray-100 dark:border-white/10 pb-8">
              <h4 class="font-bold text-gray-700 dark:text-gray-200 text-lg flex items-center gap-2">
                <svg class="w-5 h-5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                Variabel Umum
              </h4>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Email Notifikasi Admin</label>
                    <input type="email" id="adminEmail" class="w-full rounded-xl border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-black/20 focus:ring-primary-500 focus:border-primary-500 text-sm py-2.5 px-3 transition-colors">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Komisi Koperasi (%)</label>
                    <input type="number" id="commissionRate" min="0" max="100" class="w-full rounded-xl border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-black/20 focus:ring-primary-500 focus:border-primary-500 text-sm py-2.5 px-3 transition-colors">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Radius Area Bandara (km)</label>
                    <input type="number" id="airportRadiusKm" min="0.1" max="50" step="0.1" class="w-full rounded-xl border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-black/20 focus:ring-primary-500 focus:border-primary-500 text-sm py-2.5 px-3 transition-colors">
                    <p class="text-[10px] text-gray-400 mt-1">Jangkauan geofence auto-antrian &amp; peta supir.</p>
                </div>
              </div>
              
              <!-- QRIS Upload Section -->
              <div class="pt-4">
                  <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Kode QRIS Perusahaan</label>
                  <p class="text-[11px] text-gray-400 dark:text-gray-500 mb-3">Gambar ini akan muncul di aplikasi CSO saat pembayaran non-tunai via QRIS.</p>
                  
                  <div class="flex flex-col sm:flex-row items-center sm:items-start gap-5">
                      <div class="w-28 h-28 bg-gray-50 dark:bg-black/30 rounded-2xl border-2 border-dashed border-gray-300 dark:border-white/20 flex flex-col items-center justify-center overflow-hidden relative group">
                          <img id="previewQris" src="" class="w-full h-full object-cover hidden absolute inset-0 z-10 transition-transform group-hover:scale-105">
                          <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-gray-400 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                          <span id="placeholderQris" class="text-[10px] font-medium text-gray-400 text-center px-2 relative z-0">Belum ada QRIS</span>
                      </div>
                      <div class="flex-1 w-full">
                          <input type="file" id="companyQris" accept="image/*" class="w-full text-sm text-gray-500 dark:text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-primary-50 dark:file:bg-primary-500/10 file:text-primary-700 dark:file:text-primary-400 hover:file:bg-primary-100 dark:hover:file:bg-primary-500/20 transition-all cursor-pointer"/>
                      </div>
                  </div>
              </div>
            </div>

            <div class="space-y-4">
                <h4 class="font-bold text-gray-700 dark:text-gray-200 text-lg flex items-center gap-2">
                    <svg class="w-5 h-5 text-pending" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                    Konfigurasi SMTP
                    <span class="text-[10px] bg-pending/20 text-pending px-2 py-0.5 rounded-full uppercase tracking-widest ml-2 border border-pending/30">Notifikasi</span>
                </h4>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Mail Host</label>
                        <input type="text" id="mailHost" placeholder="smtp.gmail.com" class="w-full rounded-xl border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-black/20 focus:ring-primary-500 focus:border-primary-500 text-sm py-2.5 px-3 transition-colors font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Mail Port</label>
                        <input type="number" id="mailPort" placeholder="587" class="w-full rounded-xl border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-black/20 focus:ring-primary-500 focus:border-primary-500 text-sm py-2.5 px-3 transition-colors font-mono">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Username (Email Pengirim)</label>
                        <input type="text" id="mailUsername" class="w-full rounded-xl border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-black/20 focus:ring-primary-500 focus:border-primary-500 text-sm py-2.5 px-3 transition-colors">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 dark:text-slate-400 mb-1.5">Password / App Password</label>
                        <div class="relative">
                          <input type="password" id="mailPassword" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400 text-sm pl-3 pr-10 py-2.5 transition-colors">
                          <button type="button" onclick="const pass=document.getElementById('mailPassword'); if(pass.type==='password'){pass.type='text';this.innerHTML='<svg class=\'w-5 h-5\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21\'></path></svg>';}else{pass.type='password';this.innerHTML='<svg class=\'w-5 h-5\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M15 12a3 3 0 11-6 0 3 3 0 016 0z\'></path><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z\'></path></svg>';}" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                            </svg>
                          </button>
                        </div>
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
    import { AdminApp } from '{{ asset("pos-assets/js/admin.js") }}?v={{ time() }}';


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

  {{-- Banner "Install App" kustom --}}
  @include('partials.pwa-install-banner', [
    'appName'      => 'Admin POS',
    'appKey'       => 'admin',
    'bottomOffset' => '1.5rem',
  ])
</body>
</html>
