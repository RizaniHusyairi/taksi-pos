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
    /* ================= PANEL NOTIFIKASI ================= */
    /* Warnanya ditulis di sini, bukan lewat utility dark: dari Tailwind CDN.
       Panel ini MENUTUPI konten, jadi ia harus benar-benar opak — dan warna
       lewat utility sempat tidak tembus di sini sementara elemen lain dengan
       kelas yang sama baik-baik saja. Menuliskannya sebagai komponen (pola
       yang sama dengan .glass-card & .sidebar-glass) menghilangkan
       ketergantungan itu sekaligus membuat kontrasnya pasti. */
    .notif-panel { background: #ffffff; border: 1px solid #e5e7eb; }
    .dark .notif-panel { background: #172033; border-color: rgba(255, 255, 255, 0.10); }
    .notif-panel .notif-title { color: #1f2937; }
    .dark .notif-panel .notif-title { color: #f8fafc; }
    .notif-panel .notif-row:hover { background: #f9fafb; }
    .dark .notif-panel .notif-row:hover { background: rgba(255, 255, 255, 0.05); }
    .notif-panel .notif-sep { border-color: #f3f4f6; }
    .dark .notif-panel .notif-sep { border-color: rgba(255, 255, 255, 0.06); }

    .glass-card:hover {
      transform: translateY(-3px);
      border-color: rgba(255, 255, 255, 0.15);
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.4);
      transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    }
    
    /* ================= SIDEBAR ================= */
    /* Panel kaca: cukup opak agar teks tajam & terbaca di light/dark. */
    .sidebar-glass {
      background: rgba(255, 255, 255, 0.78);
      backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px);
    }
    .dark .sidebar-glass {
      background: rgba(13, 18, 30, 0.88);
      backdrop-filter: blur(22px); -webkit-backdrop-filter: blur(22px);
    }

    /* Area scroll nav: overscroll-contain => scroll di sidebar TIDAK merembet
       ke body/konten kanan (ini akar bug sebelumnya). */
    .sidebar-scroll { overscroll-behavior: contain; }
    .sidebar-scroll::-webkit-scrollbar { width: 5px; }
    .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
    .sidebar-scroll::-webkit-scrollbar-thumb {
      background: linear-gradient(180deg, #2dd4bf, #38bdf8); border-radius: 999px;
    }

    /* Logo glow saat hover */
    .brand-logo { position: relative; transition: transform .35s cubic-bezier(.25,.8,.25,1); }
    .brand-logo:hover { transform: rotate(-4deg) scale(1.05); }
    .brand-logo::after {
      content:''; position:absolute; inset:-5px; border-radius:1.1rem; z-index:-1;
      background: radial-gradient(circle, rgba(45,212,191,.55), transparent 70%);
      opacity:0; filter: blur(7px); transition: opacity .4s;
    }
    .brand-logo:hover::after { opacity:1; }

    /* Label seksi */
    .nav-section {
      display:flex; align-items:center; gap:.5rem;
      padding: 1rem .9rem .4rem; font-size:.63rem; font-weight:700;
      letter-spacing:.1em; text-transform:uppercase; color:#94a3b8;
    }
    .dark .nav-section { color:#64748b; }
    .nav-section::before {
      content:''; width:14px; height:2px; border-radius:2px; flex-shrink:0;
      background: linear-gradient(90deg, #14b8a6, transparent);
    }

    /* Item nav */
    .nav-link {
      position:relative; display:flex; align-items:center; gap:.7rem;
      margin:.12rem 0; padding:.6rem .7rem; border-radius:.85rem;
      color:#475569; font-weight:500; overflow:hidden; cursor:pointer;
      transition: color .25s, background .25s, transform .22s cubic-bezier(.25,.8,.25,1);
    }
    .dark .nav-link { color:#cbd5e1; }
    .nav-link:hover { color:#0d9488; background: rgba(20,184,166,.08); transform: translateX(5px); }
    .dark .nav-link:hover { color:#5eead4; background: rgba(20,184,166,.12); }

    /* Bar indikator kiri yang tumbuh (animatif) */
    .nav-link::before {
      content:''; position:absolute; left:0; top:50%; width:3px; height:0;
      transform: translateY(-50%); border-radius:0 4px 4px 0;
      background: linear-gradient(180deg, #2dd4bf, #38bdf8);
      transition: height .28s cubic-bezier(.25,.8,.25,1);
    }
    .nav-link:hover::before { height:45%; }

    /* Ikon jadi "chip" tanpa wrapper — padding + bg langsung di <svg>. */
    .nav-link svg {
      width:34px; height:34px; padding:7px; box-sizing:border-box; flex-shrink:0;
      border-radius:.7rem; color:inherit;
      transition: transform .25s cubic-bezier(.25,.8,.25,1), background .25s, color .25s, box-shadow .25s;
    }
    .nav-link:hover svg { background: rgba(20,184,166,.12); transform: scale(1.08) rotate(-4deg); }

    /* Status AKTIF */
    .nav-link.active {
      color:#0f766e; font-weight:600;
      background: linear-gradient(135deg, rgba(20,184,166,.16), rgba(56,189,248,.10));
      box-shadow: 0 6px 16px -6px rgba(20,184,166,.45);
    }
    .dark .nav-link.active {
      color:#5eead4;
      background: linear-gradient(135deg, rgba(20,184,166,.24), rgba(56,189,248,.12));
      box-shadow: 0 6px 20px -8px rgba(45,212,191,.5);
    }
    .nav-link.active::before { height:66%; }
    .nav-link.active svg {
      background: linear-gradient(135deg, #14b8a6, #38bdf8); color:#fff;
      box-shadow: 0 6px 14px -3px rgba(20,184,166,.6);
    }

    /* Entrance staggered */
    @keyframes navIn { from{opacity:0; transform:translateX(-14px);} to{opacity:1; transform:translateX(0);} }
    #sidebar nav > * { animation: navIn .45s both; }
    #sidebar nav>*:nth-child(1){animation-delay:.03s}  #sidebar nav>*:nth-child(2){animation-delay:.06s}
    #sidebar nav>*:nth-child(3){animation-delay:.09s}  #sidebar nav>*:nth-child(4){animation-delay:.12s}
    #sidebar nav>*:nth-child(5){animation-delay:.15s}  #sidebar nav>*:nth-child(6){animation-delay:.18s}
    #sidebar nav>*:nth-child(7){animation-delay:.21s}  #sidebar nav>*:nth-child(8){animation-delay:.24s}
    #sidebar nav>*:nth-child(9){animation-delay:.27s}  #sidebar nav>*:nth-child(10){animation-delay:.30s}
    #sidebar nav>*:nth-child(11){animation-delay:.33s} #sidebar nav>*:nth-child(12){animation-delay:.36s}
    #sidebar nav>*:nth-child(13){animation-delay:.39s} #sidebar nav>*:nth-child(14){animation-delay:.42s}
    #sidebar nav>*:nth-child(n+15){animation-delay:.45s}
    
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

    /* ================= ZONA & TARIF ================= */
    .zona-input{width:100%;border-radius:.75rem;font-size:.875rem;padding:.6rem .75rem;background:rgba(255,255,255,.6);border:1px solid #e5e7eb;color:#1f2937;transition:border-color .2s,box-shadow .2s,background .2s}
    .dark .zona-input{background:rgba(0,0,0,.25);border-color:rgba(255,255,255,.1);color:#e5e7eb}
    .zona-input::placeholder{color:#9ca3af}
    .zona-input:focus{outline:none;border-color:#14b8a6;box-shadow:0 0 0 3px rgba(20,184,166,.18);background:#fff}
    .dark .zona-input:focus{background:rgba(0,0,0,.4)}
    .sort-th{cursor:pointer;transition:color .2s,background .2s;white-space:nowrap}
    .sort-th:hover{color:#0d9488;background:rgba(20,184,166,.06)}
    .dark .sort-th:hover{color:#5eead4;background:rgba(20,184,166,.08)}
    .sort-ind::after{content:'\2195';opacity:.4;margin-left:5px;font-size:11px;display:inline-block;transition:opacity .2s}
    .sort-th.sort-asc .sort-ind::after{content:'\2191';opacity:1;color:#14b8a6}
    .sort-th.sort-desc .sort-ind::after{content:'\2193';opacity:1;color:#14b8a6}
    @keyframes zoneRowIn{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:translateY(0)}}
    #zonesTable tr{animation:zoneRowIn .32s both;transition:background .18s}
    #zonesTable tr:not(.zona-group):hover{background:rgba(20,184,166,.06)}
    .dark #zonesTable tr:not(.zona-group):hover{background:rgba(20,184,166,.09)}
    .zona-act{width:32px;height:32px;display:inline-grid;place-items:center;border-radius:.6rem;transition:background .2s,transform .2s,color .2s}
    .zona-act:hover{transform:translateY(-1px)}
    .zona-act svg{width:16px;height:16px}
    .zona-edit{color:#0d9488}
    .zona-edit:hover{background:rgba(20,184,166,.14)}
    .zona-del{color:#ef4444}
    .zona-del:hover{background:rgba(239,68,68,.14)}
    /* Toggle kategori (Dalam/Luar Kota) di form */
    .zona-cat{display:flex;align-items:center;justify-content:center;gap:6px;padding:.55rem .5rem;border-radius:.75rem;font-size:.78rem;font-weight:600;color:#6b7280;background:rgba(148,163,184,.12);border:1px solid transparent;cursor:pointer;transition:color .2s,background .2s,box-shadow .2s,transform .15s}
    .dark .zona-cat{color:#94a3b8;background:rgba(148,163,184,.09)}
    .zona-cat svg{width:15px;height:15px}
    .zona-cat:hover{transform:translateY(-1px)}
    .zona-cat.active[data-cat="dalam"]{color:#fff;background:linear-gradient(135deg,#0284c7,#38bdf8);box-shadow:0 5px 14px -4px rgba(56,189,248,.55)}
    .zona-cat.active[data-cat="luar"]{color:#78350f;background:linear-gradient(135deg,#fbbf24,#f59e0b);box-shadow:0 5px 14px -4px rgba(245,158,11,.55)}
    /* Baris header grup pada tabel (Dalam/Luar Kota — warna ala poster) */
    .zona-group td{padding:.55rem 1.25rem;font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase}
    .zona-group td svg{width:14px;height:14px;display:inline-block;vertical-align:-2px;margin-right:6px}
    .zona-group.g-dalam td{background:rgba(56,189,248,.12);color:#0369a1}
    .dark .zona-group.g-dalam td{background:rgba(56,189,248,.10);color:#7dd3fc}
    .zona-group.g-luar td{background:rgba(245,158,11,.12);color:#b45309}
    .dark .zona-group.g-luar td{background:rgba(245,158,11,.10);color:#fbbf24}

    /* ===== Halaman Pengaturan ===== */
    /* Dipakai juga oleh #repRevResult yang sudah memakai kelas ini sejak lama
       tapi belum pernah didefinisikan — sekarang animasinya benar-benar jalan. */
    @keyframes fadeInUp{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
    .animate-fade-in{animation:fadeInUp .28s ease both}

    /* Tab */
    .stab{
      display:flex;align-items:center;gap:.45rem;white-space:nowrap;
      padding:.6rem .95rem;border-bottom:2px solid transparent;border-radius:.6rem .6rem 0 0;
      font-size:.82rem;font-weight:600;color:#64748b;cursor:pointer;
      transition:color .2s,background .2s,border-color .2s;
    }
    .dark .stab{color:#94a3b8}
    .stab svg{width:16px;height:16px}
    .stab:hover{color:#0d9488;background:rgba(20,184,166,.07)}
    .dark .stab:hover{color:#5eead4;background:rgba(20,184,166,.10)}
    .stab.active{color:#0d9488;border-bottom-color:#14b8a6;background:rgba(20,184,166,.08)}
    .dark .stab.active{color:#5eead4;background:rgba(20,184,166,.12)}

    /* Label & isian */
    .stlabel{display:block;font-size:.7rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#64748b;margin-bottom:.4rem}
    .dark .stlabel{color:#94a3b8}
    .stlabel-sm{display:block;font-size:.72rem;font-weight:600;color:#64748b;margin-bottom:.3rem}
    .dark .stlabel-sm{color:#94a3b8}
    .sthint{font-size:.69rem;line-height:1.5;color:#94a3b8}
    .dark .sthint{color:#64748b}
    .stinput{
      width:100%;border-radius:.75rem;border:1px solid #e5e7eb;background:#f9fafb;
      font-size:.875rem;padding:.62rem .75rem;color:#0f172a;
      transition:border-color .2s,box-shadow .2s,background .2s;
    }
    .stinput:focus{outline:none;border-color:#14b8a6;box-shadow:0 0 0 3px rgba(20,184,166,.18);background:#fff}
    .dark .stinput{border-color:rgba(255,255,255,.10);background:rgba(0,0,0,.22);color:#e2e8f0}
    .dark .stinput:focus{background:rgba(0,0,0,.30)}
    .steye{
      position:absolute;right:.6rem;top:50%;transform:translateY(-50%);
      color:#94a3b8;cursor:pointer;transition:color .2s;line-height:0;
    }
    .steye:hover{color:#475569}
    .dark .steye:hover{color:#e2e8f0}
    .steye svg{width:18px;height:18px}

    /* Wadah unggah QRIS saat berkas diseret ke atasnya */
    #qrisDrop.dragover{border-color:#14b8a6;background:rgba(20,184,166,.07)}
  </style>
</head>
<body class="bg-gray-50 text-gray-800 dark:bg-bgDark dark:text-gray-200 antialiased selection:bg-primary-500 selection:text-white font-sans">
  <div class="animated-bg dark:block hidden"></div>

  <div id="app" class="flex h-screen overflow-hidden">
    <!-- Backdrop drawer (mobile) -->
    <div id="sidebarBackdrop" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-30 md:hidden hidden"></div>
    <!-- Sidebar -->
    <aside id="sidebar" class="sidebar-glass w-64 h-screen flex flex-col fixed md:static inset-y-0 left-0 z-40 -translate-x-full md:translate-x-0 transition-transform duration-300 border-r border-gray-200 dark:border-white/10">
      <div class="p-6 flex items-center gap-4 border-b border-gray-200 dark:border-white/10">
        <img src="{{ asset('pos-assets/img/logo_taksi.png') }}" alt="Logo" class="w-12 h-12 object-contain bg-white rounded-xl shadow-lg p-1" />
        <div>
          <div class="text-xs font-medium text-primary-600 dark:text-accent uppercase tracking-wider mb-1">Administrator</div>
          <div class="font-bold text-gray-800 dark:text-white leading-tight">POS Angkasa<br>Jaya</div>
        </div>
      </div>
      
      <div class="flex-1 overflow-y-auto sidebar-scroll py-4 px-3">
        <nav class="space-y-1">
          <!-- Dashboard -->
          <a href="#dashboard" class="nav-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
            <span class="font-medium">Dashboard</span>
          </a>

          <div class="nav-section">Operasional</div>
          
          <a href="#queue" class="nav-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            <span class="font-medium">Manajemen Antrian</span>
          </a>
          
          <a href="#zones" class="nav-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            <span class="font-medium">Zona & Tarif</span>
          </a>
          
          <a href="#users" class="nav-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
            <span class="font-medium">Pengguna</span>
          </a>

          <a href="#settings" class="nav-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            <span class="font-medium">Pengaturan</span>
          </a>

          <div class="nav-section">Keuangan</div>
          
          <a href="#finance-log" class="nav-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
            <span class="font-medium">Log Transaksi</span>
          </a>
          
          <a href="#withdrawals" class="nav-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <span class="font-medium">Pencairan Dana</span>
          </a>

          <a href="#cso-deposits" class="nav-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
            <span class="font-medium">Setoran CSO</span>
          </a>

          <a href="#driver-deposits" class="nav-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m3 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H10a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            <span class="font-medium">Setoran Supir</span>
          </a>

          <a href="#method-disputes" class="nav-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path></svg>
            <span class="font-medium">Sengketa Pembayaran</span>
            <span id="navDisputeBadge" class="hidden ml-auto text-[10px] font-bold bg-red-500 text-white rounded-full px-1.5 py-0.5"></span>
          </a>

          <div class="nav-section">Laporan</div>
          
          <a href="#report-revenue" class="nav-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path></svg>
            <span class="font-medium">Pendapatan</span>
          </a>
          
          <a href="#report-driver" class="nav-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
            <span class="font-medium">Kinerja Supir</span>
          </a>

          <a href="#cso-performance" class="nav-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 18v-6a6 6 0 10-12 0v6M4 14h2a1 1 0 011 1v3a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm16 0v4a1 1 0 01-1 1h-1a1 1 0 01-1-1v-3a1 1 0 011-1h2z"></path></svg>
            <span class="font-medium">Performa CSO</span>
          </a>

          <a href="#fraud-signals" class="nav-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19H19a2 2 0 001.75-2.97l-6.93-12a2 2 0 00-3.5 0l-6.93 12A2 2 0 005.07 19z"></path></svg>
            <span class="font-medium">Sinyal Kecurangan</span>
          </a>

          <div class="nav-section">Live</div>

          <a href="#driver-map" class="nav-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            <span class="font-medium">Peta Supir</span>
          </a>

          <div class="nav-section">Sistem</div>

          <a href="#api" class="nav-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path></svg>
            <span class="font-medium">API Integrasi</span>
          </a>

          <a href="#wa" class="nav-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path></svg>
            <span class="font-medium">WhatsApp Gateway</span>
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
          <div class="relative" id="notifWrap">
            <button id="btnNotif" type="button" aria-haspopup="true" aria-expanded="false" title="Notifikasi"
              class="p-2.5 text-gray-500 bg-white dark:bg-cardDark border border-gray-200 dark:text-gray-400 dark:border-white/10 hover:text-primary-500 hover:border-primary-200 dark:hover:border-primary-500/30 rounded-xl transition-all shadow-sm relative">
               <!-- Lencana diisi dari data; tidak ada lagi titik merah yang
                    di-hardcode dan selalu menyala. -->
               <span id="notifBadge" class="hidden absolute -top-1.5 -right-1.5 min-w-[18px] h-[18px] px-1 flex items-center justify-center text-[10px] font-bold bg-red-500 text-white rounded-full border-2 border-white dark:border-cardDark"></span>
               <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
            </button>

            <!-- Di layar sempit panel dipatok ke viewport (fixed + inset kiri
                 kanan); dropdown yang digantung pada tombol akan menjorok
                 keluar tepi kiri layar karena loncengnya sendiri sudah dekat
                 tepi kanan. Mulai lebar sm ke atas ia kembali jadi dropdown
                 biasa di bawah tombol. -->
            <div id="notifPanel" class="notif-panel hidden fixed left-3 right-3 top-[4.5rem] w-auto sm:absolute sm:left-auto sm:right-0 sm:top-full sm:mt-2 sm:w-[22rem] rounded-2xl shadow-2xl z-50 overflow-hidden">
              <div class="flex items-center justify-between px-4 py-3 border-b notif-sep">
                <h4 class="font-bold text-sm notif-title">Notifikasi</h4>
                <button id="btnNotifReadAll" type="button" class="text-[11px] font-semibold text-primary-600 dark:text-primary-400 hover:underline">Tandai semua terbaca</button>
              </div>

              <!-- Ringkasan antrean: yang MASIH menunggu keputusan, termasuk
                   yang notifikasinya sudah lama terbaca. -->
              <div id="notifPending" class="hidden px-4 py-3 bg-amber-50/60 dark:bg-amber-500/10 border-b border-amber-100 dark:border-amber-500/20 space-y-1"></div>

              <div id="notifList" class="max-h-[22rem] overflow-y-auto custom-scrollbar"></div>
            </div>
          </div>

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
              <div id="metricRevenueDelta" class="mt-1.5 text-[11px] font-semibold text-gray-400">&nbsp;</div>
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
              <div id="metricTxDelta" class="mt-1.5 text-[11px] font-semibold text-gray-400">&nbsp;</div>
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
              <div id="metricDriversBreakdown" class="mt-1.5 text-[11px] font-semibold text-gray-400">&nbsp;</div>
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
              <div id="metricPendingWdAmount" class="mt-1.5 text-[11px] font-semibold text-gray-400">&nbsp;</div>
            </div>
          </div>

          <!-- Gambaran operasional hari ini: uang masuk + denyut aktivitas -->
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <!-- Uang masuk hari ini -->
            <div class="glass-card rounded-2xl p-5">
              <div class="flex items-start justify-between mb-4">
                <div>
                  <h3 class="font-bold text-gray-800 dark:text-white text-lg">Uang Masuk Hari Ini</h3>
                  <p class="text-xs text-gray-500 dark:text-gray-400">Rincian per metode pembayaran</p>
                </div>
                <div id="payTotal" class="text-xl font-extrabold text-gray-800 dark:text-white">Rp 0</div>
              </div>

              <div class="h-3 w-full rounded-full bg-gray-100 dark:bg-white/10 overflow-hidden flex">
                <div id="payBarCashCso" class="h-full bg-emerald-500 transition-all duration-500" style="width:0%"></div>
                <div id="payBarCashDriver" class="h-full bg-amber-500 transition-all duration-500" style="width:0%"></div>
                <div id="payBarQris" class="h-full bg-violet-500 transition-all duration-500" style="width:0%"></div>
              </div>
              <p id="payEmpty" class="hidden text-[11px] text-gray-400 mt-2">Belum ada transaksi hari ini.</p>

              <div class="grid grid-cols-3 gap-3 mt-4">
                <div>
                  <p class="flex items-center gap-1.5 text-[10px] font-bold text-gray-400 uppercase tracking-wider"><span class="w-2 h-2 rounded-full bg-emerald-500"></span>Tunai CSO</p>
                  <p id="payCashCso" class="text-sm font-bold text-gray-800 dark:text-gray-100 mt-1">Rp 0</p>
                </div>
                <div>
                  <p class="flex items-center gap-1.5 text-[10px] font-bold text-gray-400 uppercase tracking-wider"><span class="w-2 h-2 rounded-full bg-amber-500"></span>Tunai Supir</p>
                  <p id="payCashDriver" class="text-sm font-bold text-gray-800 dark:text-gray-100 mt-1">Rp 0</p>
                </div>
                <div>
                  <p class="flex items-center gap-1.5 text-[10px] font-bold text-gray-400 uppercase tracking-wider"><span class="w-2 h-2 rounded-full bg-violet-500"></span>QRIS</p>
                  <p id="payQris" class="text-sm font-bold text-gray-800 dark:text-gray-100 mt-1">Rp 0</p>
                </div>
              </div>

              <div class="mt-4 pt-4 border-t border-gray-100 dark:border-white/10 flex items-center justify-between gap-3">
                <div>
                  <p class="text-[10px] font-bold text-amber-600 dark:text-amber-400 uppercase tracking-wider">Hutang Setoran Supir</p>
                  <p class="text-[10px] text-gray-400 mt-0.5">Tunai yang masih dipegang supir &amp; belum disetor</p>
                </div>
                <p id="payDriverDebt" class="text-lg font-extrabold text-amber-600 dark:text-amber-400 shrink-0">Rp 0</p>
              </div>
            </div>

            <!-- Aktivitas terbaru -->
            <div class="glass-card rounded-2xl p-5">
              <div class="flex items-center justify-between mb-4">
                <div>
                  <h3 class="font-bold text-gray-800 dark:text-white text-lg">Aktivitas Terbaru</h3>
                  <p class="text-xs text-gray-500 dark:text-gray-400">8 transaksi terakhir</p>
                </div>
                <div class="p-1.5 bg-gray-100 dark:bg-white/5 rounded-lg text-gray-400">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </div>
              </div>
              <div id="recentActivity" class="divide-y divide-gray-100 dark:divide-white/5 -my-2"></div>
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
              <div class="relative" style="height: 210px;"><canvas id="weeklyChart"></canvas></div>
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
              <div class="relative" style="height: 210px;"><canvas id="monthlyChart"></canvas></div>
            </div>
          </div>

          <!-- Performa Supir & CSO -->
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <!-- Papan peringkat supir -->
            <div class="glass-card rounded-2xl p-5">
              <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                <div>
                  <h3 class="font-bold text-gray-800 dark:text-white text-lg">Papan Peringkat Supir</h3>
                  <p class="text-xs text-gray-500 dark:text-gray-400">6 supir teratas sepanjang waktu</p>
                </div>
                <select id="dashDriverMetric" class="rounded-xl border-gray-200 bg-gray-50 text-gray-700 dark:border-white/10 dark:bg-black/20 dark:text-gray-200 text-xs py-1.5 px-2.5 focus:ring-primary-500 focus:border-primary-500 cursor-pointer">
                  <option value="revenue">Pendapatan</option>
                  <option value="trips">Jumlah Trip</option>
                  <option value="rating">Rating</option>
                </select>
              </div>
              <div class="relative" style="height: 240px;">
                <canvas id="driverPerfChart"></canvas>
              </div>
              <p id="driverPerfEmpty" class="hidden text-center text-xs text-gray-400 py-10">Belum ada data kinerja supir.</p>
            </div>

            <!-- Kontribusi CSO -->
            <div class="glass-card rounded-2xl p-5">
              <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                <div>
                  <h3 class="font-bold text-gray-800 dark:text-white text-lg">Kontribusi Pesanan CSO</h3>
                  <p id="csoChartPeriod" class="text-xs text-gray-500 dark:text-gray-400">Bulan berjalan</p>
                </div>
                <div class="p-1.5 bg-gray-100 dark:bg-white/5 rounded-lg text-gray-400">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"></path></svg>
                </div>
              </div>
              <div class="relative" style="height: 240px;">
                <canvas id="csoPerfChart"></canvas>
              </div>
              <div id="csoChartLegend" class="flex flex-wrap gap-x-4 gap-y-1.5 mt-4 text-xs"></div>
              <p id="csoPerfEmpty" class="hidden text-center text-xs text-gray-400 py-10">Belum ada pesanan bulan ini.</p>
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
          <!-- FORM ZONA -->
          <div class="glass-card rounded-2xl p-6 lg:col-span-1 self-start">
            <div class="flex items-center gap-3 mb-6">
              <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-primary-500 to-accent text-white grid place-items-center shadow-lg shadow-primary-500/30">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
              </div>
              <div>
                <h3 class="font-bold text-gray-800 dark:text-white text-lg leading-tight">Data Zona Tujuan</h3>
                <p id="zoneFormMode" class="text-xs text-gray-500 dark:text-gray-400">Tambah zona &amp; tarif baru</p>
              </div>
            </div>
            <form id="formZone" class="space-y-5">
              <input type="hidden" id="zoneId">
              <div>
                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Nama Zona</label>
                <div class="relative">
                  <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg></span>
                  <input id="zoneName" placeholder="mis. Samboja" class="zona-input pl-10" required>
                </div>
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Tarif (Rp)</label>
                <div class="relative">
                  <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm font-semibold pointer-events-none">Rp</span>
                  <input id="zonePrice" type="number" min="0" placeholder="0" class="zona-input pl-10" required>
                </div>
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Kategori</label>
                <div class="grid grid-cols-2 gap-2">
                  <button type="button" class="zona-cat active" data-cat="dalam">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                    Dalam Kota
                  </button>
                  <button type="button" class="zona-cat" data-cat="luar">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    Luar Kota
                  </button>
                </div>
                <input type="hidden" id="zoneCategory" value="dalam">
              </div>
              <div>
                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-widest mb-1.5">Keterangan Area <span class="font-normal normal-case tracking-normal text-gray-400">(opsional)</span></label>
                <input id="zoneDescription" placeholder="mis. Depan Bandara" class="zona-input">
              </div>
              <div class="flex gap-3 pt-1">
                <button class="flex-1 bg-gradient-to-r from-primary-600 to-primary-500 hover:from-primary-700 hover:to-primary-600 text-white rounded-xl py-2.5 font-semibold text-sm shadow-md shadow-primary-500/30 transition-all hover:shadow-lg hover:shadow-primary-500/40 hover:-translate-y-0.5 flex items-center justify-center gap-2">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                  <span id="zoneSubmitText">Simpan</span>
                </button>
                <button type="button" id="zoneReset" class="px-5 bg-gray-100 hover:bg-gray-200 dark:bg-white/5 dark:hover:bg-white/10 text-gray-600 dark:text-gray-300 rounded-xl py-2.5 font-medium text-sm transition-colors">Reset</button>
              </div>
            </form>
          </div>

          <!-- TABEL ZONA -->
          <div class="glass-card rounded-2xl p-6 lg:col-span-2">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-5">
              <div>
                <h3 class="font-bold text-gray-800 dark:text-white text-lg flex items-center gap-2">
                  Daftar Tarif Zona
                  <span id="zoneCount" class="text-[11px] font-bold px-2 py-0.5 rounded-full bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300">0</span>
                </h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Perkiraan biaya dari titik awal (Bandara).</p>
              </div>
              <div class="relative sm:w-60">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg></span>
                <input id="zoneSearch" type="text" placeholder="Cari zona..." class="zona-input pl-9 pr-8 py-2">
                <button type="button" id="zoneSearchClear" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hidden"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
              </div>
            </div>
            <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5 bg-white/40 dark:bg-black/20 backdrop-blur-sm">
              <table class="w-full text-sm text-left">
                <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider select-none">
                  <tr>
                    <th class="py-3.5 px-5 sort-th" data-sort="name">Tujuan<span class="sort-ind"></span></th>
                    <th class="py-3.5 px-5 text-right sort-th" data-sort="price">Tarif Dasar<span class="sort-ind"></span></th>
                    <th class="py-3.5 px-5 text-center w-28">Opsi</th>
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
      <!-- ===== Setoran tunai CSO ke admin ===== -->
      <section id="view-cso-deposits" class="hidden space-y-6">
        <div class="glass-card rounded-2xl p-5">
          <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <div>
              <h3 class="font-bold text-gray-800 dark:text-white text-lg">Setoran Tunai CSO</h3>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Uang tunai penumpang (metode <b>Tunai ke Kasir</b>) yang diserahkan CSO ke admin.</p>
            </div>
            <select id="depFilterStatus" class="zona-input" style="width:auto;min-width:170px">
              <option value="Pending">Menunggu Verifikasi</option>
              <option value="Approved">Disetujui</option>
              <option value="Rejected">Ditolak</option>
              <option value="">Semua Status</option>
            </select>
          </div>
          <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5 bg-white/50 dark:bg-black/20 backdrop-blur-sm">
            <table class="w-full text-sm text-left">
              <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                <tr>
                  <th class="py-4 px-5">Waktu Setor</th>
                  <th class="py-4 px-5">CSO</th>
                  <th class="py-4 px-5">Tanggal Dicakup</th>
                  <th class="py-4 px-5 text-right">Nilai Setoran</th>
                  <th class="py-4 px-5 text-center">Status</th>
                  <th class="py-4 px-5 text-right w-56">Respons</th>
                </tr>
              </thead>
              <tbody id="depTable" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
            </table>
          </div>
          <div id="depPager" class="mt-4"></div>
        </div>
      </section>

      <!-- Setoran tunai SUPIR: pelunasan utang komisi atas order yang dibayar
           tunai langsung ke supir. Bentuknya sengaja kembar dengan Setoran CSO
           di atas supaya admin tidak perlu belajar dua alur yang berbeda. -->
      <section id="view-driver-deposits" class="hidden space-y-6">
        <div class="glass-card rounded-2xl p-5">
          <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
            <div>
              <h3 class="font-bold text-gray-800 dark:text-white text-lg">Setoran Tunai Supir</h3>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Pelunasan <b>utang komisi</b> supir atas order yang dibayar <b>Tunai ke Supir</b>. Nominalnya adalah bagian koperasi, bukan tarif penuh.</p>
            </div>
            <select id="ddepFilterStatus" class="zona-input" style="width:auto;min-width:170px">
              <option value="Pending">Menunggu Verifikasi</option>
              <option value="Approved">Disetujui</option>
              <option value="Rejected">Ditolak</option>
              <option value="">Semua Status</option>
            </select>
          </div>
          <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5 bg-white/50 dark:bg-black/20 backdrop-blur-sm">
            <table class="w-full text-sm text-left">
              <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                <tr>
                  <th class="py-4 px-5">Waktu Setor</th>
                  <th class="py-4 px-5">Supir</th>
                  <th class="py-4 px-5">Tanggal Dicakup</th>
                  <th class="py-4 px-5 text-right">Nilai Setoran</th>
                  <th class="py-4 px-5 text-center">Status</th>
                  <th class="py-4 px-5 text-right w-56">Respons</th>
                </tr>
              </thead>
              <tbody id="ddepTable" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
            </table>
          </div>
          <div id="ddepPager" class="mt-4"></div>
        </div>
      </section>

      <div id="modalDdepDetails" class="fixed inset-0 bg-black/50 hidden items-center justify-center p-4 z-50">
        <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-lg w-full max-w-2xl flex flex-col max-h-[90vh]">
          <div class="p-4 border-b flex justify-between items-center bg-slate-50 dark:bg-slate-700 dark:border-slate-600 rounded-t-xl">
            <div>
              <h3 class="font-bold text-lg text-slate-800 dark:text-slate-100">Rincian Setoran Supir</h3>
              <p class="text-xs text-slate-500 dark:text-slate-400" id="ddepDetailSub">Order tunai yang utangnya dilunasi setoran ini.</p>
            </div>
            <button id="btnCloseDdepDetails" class="text-slate-400 hover:text-slate-600 dark:text-slate-300 text-2xl">&times;</button>
          </div>
          <div class="p-0 overflow-y-auto flex-1">
            <table class="w-full text-sm text-left">
              <thead class="text-slate-500 dark:text-slate-400 sticky top-0 bg-slate-50 dark:bg-slate-700">
                <tr>
                  <th class="px-4 py-3">Waktu</th>
                  <th class="px-4 py-3">Rute</th>
                  <th class="px-4 py-3">CSO</th>
                  <th class="px-4 py-3 text-right">Tarif</th>
                </tr>
              </thead>
              <tbody id="ddepDetailBody" class="divide-y divide-slate-100 dark:divide-slate-700"></tbody>
            </table>
          </div>
          <div class="p-4 border-t dark:border-slate-600 flex justify-between items-center">
            <div>
              <span class="text-sm text-slate-500 dark:text-slate-400">Nilai setoran (komisi)</span>
              <p class="text-[11px] text-slate-400">Tarif di atas adalah ongkos penumpang, bukan yang disetor.</p>
            </div>
            <span id="ddepDetailTotal" class="font-bold text-lg text-slate-800 dark:text-slate-100">-</span>
          </div>
        </div>
      </div>

      <!-- Sengketa metode pembayaran: supir menyanggah menerima uang tunai.
           Mengabulkannya MEMINDAHKAN kewajiban uang dari supir ke CSO, jadi
           konsekuensinya harus terbaca jelas sebelum admin menekan tombol. -->
      <section id="view-method-disputes" class="hidden space-y-6">
        <div class="glass-card rounded-2xl p-5">
          <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <div>
              <h3 class="font-bold text-gray-800 dark:text-white text-lg">Sengketa Pembayaran</h3>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-3xl">
                Supir menyanggah bahwa ia menerima uang tunai atas sebuah order. Sistem tidak bisa tahu uang fisik berpindah ke siapa —
                keputusannya ada di tangan Anda, setelah bertanya ke kedua pihak.
              </p>
            </div>
            <select id="mdFilterStatus" class="zona-input" style="width:auto;min-width:170px">
              <option value="Open">Belum Diputus</option>
              <option value="Upheld">Dikabulkan</option>
              <option value="Rejected">Ditolak</option>
              <option value="">Semua</option>
            </select>
          </div>

          <div class="rounded-xl border border-amber-200 dark:border-amber-500/30 bg-amber-50/70 dark:bg-amber-500/10 p-4 mb-5">
            <p class="text-xs text-amber-800 dark:text-amber-300 leading-relaxed">
              <b>Bila dikabulkan:</b> order dikoreksi menjadi <b>Tunai ke Kasir</b>. Utang komisi lepas dari supir dan berubah menjadi hak pemasukannya,
              sementara <b>uangnya menjadi kewajiban setoran CSO</b> dan langsung muncul di menu Setoran CSO. Kedua pihak diberi tahu lewat WhatsApp.
              Keputusan ini <b>tidak bisa dibatalkan</b> dari panel.
            </p>
          </div>

          <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5 bg-white/50 dark:bg-black/20 backdrop-blur-sm">
            <table class="w-full text-sm text-left">
              <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                <tr>
                  <th class="py-4 px-5">Waktu Sanggah</th>
                  <th class="py-4 px-5">Order</th>
                  <th class="py-4 px-5">Supir &rarr; CSO</th>
                  <th class="py-4 px-5">Alasan Supir</th>
                  <th class="py-4 px-5 text-right">Tarif</th>
                  <th class="py-4 px-5 text-center">Status</th>
                  <th class="py-4 px-5 text-right w-52">Keputusan</th>
                </tr>
              </thead>
              <tbody id="mdTable" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
            </table>
          </div>
          <div id="mdPager" class="mt-4"></div>
        </div>
      </section>

      <!-- Sinyal kecurangan: tiga daftar yang menuntut PERTANYAAN, bukan vonis. -->
      <section id="view-fraud-signals" class="hidden space-y-6">
        <div class="glass-card rounded-2xl p-5">
          <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
              <h3 class="font-bold text-gray-800 dark:text-white text-lg">Sinyal Kecurangan</h3>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-2xl">
                Tiga pola yang <b>layak ditanyakan</b>, bukan bukti pelanggaran. Setiap angka disajikan bersama pembandingnya —
                putuskan setelah bertanya ke orangnya, jangan menghukum berdasarkan tabel ini saja.
              </p>
            </div>
            <div class="flex flex-wrap items-end gap-2">
              <div>
                <label class="block text-[10px] font-bold uppercase text-gray-500 dark:text-gray-400 mb-1">Dari</label>
                <input type="date" id="fsFrom" class="zona-input" style="width:auto">
              </div>
              <div>
                <label class="block text-[10px] font-bold uppercase text-gray-500 dark:text-gray-400 mb-1">Sampai</label>
                <input type="date" id="fsTo" class="zona-input" style="width:auto">
              </div>
              <button id="fsApply" class="bg-primary-600 hover:bg-primary-700 text-white text-sm font-semibold px-4 py-2 rounded-lg shadow">Terapkan</button>
            </div>
          </div>
        </div>

        <!-- A. Override antrian -->
        <div class="glass-card rounded-2xl p-5">
          <div class="flex flex-wrap items-baseline justify-between gap-2 mb-1">
            <h4 class="font-bold text-gray-800 dark:text-white">Melewati Giliran Antrian</h4>
            <span class="text-xs text-gray-500 dark:text-gray-400">Rata-rata semua CSO: <b id="fsAvgRate">-</b></span>
          </div>
          <p class="text-xs text-gray-500 dark:text-gray-400 mb-4 max-w-3xl">
            Melewati giliran itu <b>sah</b> — mobil mogok, penumpang menolak, supir tak muncul. Yang perlu ditanyakan adalah
            CSO yang jauh menyimpang dari rata-rata rekannya, atau yang selalu mendahulukan supir yang sama dengan mengorbankan orang yang sama.
          </p>
          <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5">
            <table class="w-full text-sm text-left">
              <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                <tr>
                  <th class="py-3 px-5">CSO</th>
                  <th class="py-3 px-5 text-right">Total Order</th>
                  <th class="py-3 px-5 text-right">Override</th>
                  <th class="py-3 px-5 text-right">Rasio</th>
                  <th class="py-3 px-5">Pasangan Paling Sering</th>
                </tr>
              </thead>
              <tbody id="fsOverrideBody" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
            </table>
          </div>
          <p class="text-[11px] text-amber-600 dark:text-amber-400 mt-3">
            Catatan: kolom ini baru terisi untuk order yang dibuat sejak fitur pencatatan override aktif. Order lama tampil sebagai 0.
          </p>
        </div>

        <!-- B. Nomor penumpang berulang -->
        <div class="glass-card rounded-2xl p-5">
          <div class="flex flex-wrap items-baseline justify-between gap-2 mb-1">
            <h4 class="font-bold text-gray-800 dark:text-white">Nomor Penumpang Berulang</h4>
            <label class="text-xs text-gray-500 dark:text-gray-400">Minimal dipakai
              <input type="number" id="fsPhoneMin" min="2" max="100" value="3" class="zona-input inline-block ml-1" style="width:70px"> kali
            </label>
          </div>
          <p class="text-xs text-gray-500 dark:text-gray-400 mb-4 max-w-3xl">
            Nomor penumpang adalah satu-satunya jalur verifikasi independen: dari sanalah penumpang menerima struk berisi tarif yang benar-benar tercatat.
            Berulang belum tentu curang — pelanggan tetap itu nyata. Yang mencolok adalah nomor yang <b>selalu dipakai CSO yang sama</b>.
          </p>
          <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5">
            <table class="w-full text-sm text-left">
              <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                <tr>
                  <th class="py-3 px-5">Nomor</th>
                  <th class="py-3 px-5 text-right">Dipakai</th>
                  <th class="py-3 px-5 text-right">Jumlah CSO</th>
                  <th class="py-3 px-5">CSO Pemakai</th>
                </tr>
              </thead>
              <tbody id="fsPhoneBody" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
            </table>
          </div>
        </div>

        <!-- C. Keluar area tanpa order -->
        <div class="glass-card rounded-2xl p-5">
          <h4 class="font-bold text-gray-800 dark:text-white mb-1">Keluar Area Tanpa Order Tercatat</h4>
          <p class="text-xs text-gray-500 dark:text-gray-400 mb-4 max-w-3xl">
            Supir yang meninggalkan area bandara antara 15 menit &ndash; 4 jam lalu kembali, tanpa satu pun order miliknya yang menutupi rentang waktu itu.
            Trip yang <b>jujur dilaporkan</b> (termasuk lewat tombol &ldquo;Dapat Penumpang Sendiri&rdquo;) otomatis tidak muncul di sini.
            Tetap saja ini sinyal, bukan bukti — supir bisa saja sedang mengantar keluarganya sendiri.
          </p>
          <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5">
            <table class="w-full text-sm text-left">
              <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                <tr>
                  <th class="py-3 px-5">Supir</th>
                  <th class="py-3 px-5 text-right">Jumlah Kepergian</th>
                  <th class="py-3 px-5 text-right">Total Durasi</th>
                  <th class="py-3 px-5">Terakhir Kembali</th>
                  <th class="py-3 px-5 text-right">Aktivitas</th>
                </tr>
              </thead>
              <tbody id="fsTripBody" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
            </table>
          </div>
        </div>
      </section>

      <div id="modalDepDetails" class="fixed inset-0 bg-black/50 hidden items-center justify-center p-4 z-50">
        <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-lg w-full max-w-2xl flex flex-col max-h-[90vh]">
          <div class="p-4 border-b flex justify-between items-center bg-slate-50 dark:bg-slate-700 dark:border-slate-600 rounded-t-xl">
            <div>
              <h3 class="font-bold text-lg text-slate-800 dark:text-slate-100">Rincian Setoran</h3>
              <p class="text-xs text-slate-500 dark:text-slate-400" id="depDetailSub">Transaksi tunai yang tercakup dalam setoran ini.</p>
            </div>
            <button id="btnCloseDepDetails" class="text-slate-400 hover:text-slate-600 dark:text-slate-300 text-2xl">&times;</button>
          </div>
          <div class="p-0 overflow-y-auto flex-1">
            <table class="w-full text-sm text-left">
              <thead class="text-slate-500 dark:text-slate-400 sticky top-0 bg-slate-50 dark:bg-slate-700">
                <tr>
                  <th class="px-4 py-3">Waktu</th>
                  <th class="px-4 py-3">Rute</th>
                  <th class="px-4 py-3">Supir</th>
                  <th class="px-4 py-3 text-right">Nominal</th>
                </tr>
              </thead>
              <tbody id="depDetailBody" class="divide-y divide-slate-100 dark:divide-slate-700"></tbody>
            </table>
          </div>
          <div class="p-4 border-t dark:border-slate-600 flex justify-between items-center">
            <span class="text-sm text-slate-500 dark:text-slate-400">Total</span>
            <span id="depDetailTotal" class="font-bold text-lg text-slate-800 dark:text-slate-100">-</span>
          </div>
        </div>
      </div>

      <div id="modalUploadProof" class="fixed inset-0 bg-black/50 hidden items-center justify-center p-4 z-50">
        <div class="bg-white dark:bg-slate-800 dark:text-slate-100 rounded-xl shadow-lg p-5 max-w-sm w-full">
            <h3 class="font-bold text-lg mb-2">Setujui Pencairan</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
                Silakan transfer ke tujuan pencairan supir (rekening bank atau e-wallet yang tertera pada baris pengajuan), lalu upload bukti transfer di sini untuk menyetujui (Approve).
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

        <!-- Filter periode + export -->
        <div class="glass-card rounded-2xl p-5">
          <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
            <div>
              <h3 class="font-bold text-gray-800 dark:text-white text-lg">Laporan Pendapatan</h3>
              <p id="repRevRangeLabel" class="text-xs text-gray-500 dark:text-gray-400 mt-1">Memuat…</p>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-end gap-3">
              <div>
                <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Dari</label>
                <input type="date" id="repRevFrom" class="w-full rounded-xl border-gray-200 bg-gray-50 text-gray-700 dark:border-white/10 dark:bg-black/20 dark:text-gray-200 text-sm py-2 px-3 focus:ring-primary-500 focus:border-primary-500 transition-colors">
              </div>
              <div>
                <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Sampai</label>
                <input type="date" id="repRevTo" class="w-full rounded-xl border-gray-200 bg-gray-50 text-gray-700 dark:border-white/10 dark:bg-black/20 dark:text-gray-200 text-sm py-2 px-3 focus:ring-primary-500 focus:border-primary-500 transition-colors">
              </div>
              <button id="repRevApply" type="button" class="bg-gradient-to-r from-primary-600 to-primary-500 hover:from-primary-700 hover:to-primary-600 text-white rounded-xl px-6 py-2 h-[42px] font-semibold text-sm shadow-md shadow-primary-500/30 transition-all">
                Terapkan
              </button>
            </div>
          </div>

          <div class="flex flex-wrap items-center gap-2 mt-4 pt-4 border-t border-gray-100 dark:border-white/10">
            <span class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mr-1">Pintasan</span>
            <button type="button" data-rev-preset="today" class="rev-preset px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 text-gray-600 dark:bg-white/5 dark:hover:bg-white/10 dark:text-gray-300 transition-colors">Hari Ini</button>
            <button type="button" data-rev-preset="7d" class="rev-preset px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 text-gray-600 dark:bg-white/5 dark:hover:bg-white/10 dark:text-gray-300 transition-colors">7 Hari</button>
            <button type="button" data-rev-preset="month" class="rev-preset px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 text-gray-600 dark:bg-white/5 dark:hover:bg-white/10 dark:text-gray-300 transition-colors">Bulan Ini</button>
            <button type="button" data-rev-preset="lastmonth" class="rev-preset px-3 py-1.5 rounded-lg text-xs font-semibold bg-gray-100 hover:bg-gray-200 text-gray-600 dark:bg-white/5 dark:hover:bg-white/10 dark:text-gray-300 transition-colors">Bulan Lalu</button>

            <div class="ml-auto flex items-center gap-2">
              <a id="repRevExportPdf" href="#" target="_blank" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-red-50 hover:bg-red-100 text-red-600 dark:bg-red-500/10 dark:hover:bg-red-500/20 dark:text-red-400 transition-colors">Export PDF</a>
              <a id="repRevExportExcel" href="#" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-emerald-50 hover:bg-emerald-100 text-emerald-600 dark:bg-emerald-500/10 dark:hover:bg-emerald-500/20 dark:text-emerald-400 transition-colors">Export Excel</a>
            </div>
          </div>
        </div>

        <div id="repRevResult" class="hidden animate-fade-in space-y-6">

          <!-- KPI -->
          <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
            <div class="glass-card rounded-2xl p-5">
              <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Pendapatan Kotor</div>
              <div id="repRevGross" class="text-2xl font-extrabold text-gray-800 dark:text-white">Rp 0</div>
              <div id="repRevDelta" class="text-xs mt-1 text-gray-400">—</div>
            </div>
            <div class="glass-card rounded-2xl p-5">
              <div id="repRevFeeLabel" class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Potongan Sistem</div>
              <div id="repRevFee" class="text-2xl font-extrabold text-red-500 dark:text-red-400">Rp 0</div>
              <div id="repRevFeeNote" class="text-xs mt-1 text-gray-400">—</div>
            </div>
            <div class="glass-card rounded-2xl p-5">
              <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Bersih ke Supir</div>
              <div id="repRevNet" class="text-2xl font-extrabold text-emerald-600 dark:text-emerald-400">Rp 0</div>
              <div class="text-xs mt-1 text-gray-400">Kotor dikurangi potongan</div>
            </div>
            <div class="glass-card rounded-2xl p-5">
              <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Jumlah Transaksi</div>
              <div id="repRevCount" class="text-2xl font-extrabold text-gray-800 dark:text-white">0</div>
              <div id="repRevAvg" class="text-xs mt-1 text-gray-400">—</div>
            </div>
          </div>

          <!-- Tren harian -->
          <div class="glass-card rounded-2xl p-5">
            <h4 class="font-bold text-gray-800 dark:text-white text-sm mb-4">Tren Pendapatan Harian</h4>
            <div class="h-[260px]"><canvas id="repRevTrendChart"></canvas></div>
          </div>

          <!-- Metode bayar -->
          <div class="glass-card rounded-2xl p-5">
            <h4 class="font-bold text-gray-800 dark:text-white text-sm mb-4">Rincian Metode Bayar</h4>
            <div id="repRevMethods" class="grid grid-cols-1 sm:grid-cols-3 gap-4"></div>
          </div>

          <!-- Posisi kas -->
          <div class="glass-card rounded-2xl p-5 border-l-4 border-amber-400">
            <div class="flex items-start justify-between gap-4 mb-4">
              <div>
                <h4 class="font-bold text-gray-800 dark:text-white text-sm">Posisi Kas — Uang Tunai di Luar</h4>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                  Saldo <strong>saat ini</strong>, bukan bagian dari rentang tanggal di atas.
                </p>
              </div>
              <div class="text-right shrink-0">
                <div class="text-[10px] font-bold text-amber-500 uppercase tracking-wider">Total di Luar</div>
                <div id="repRevCashTotal" class="text-xl font-extrabold text-amber-600 dark:text-amber-400">Rp 0</div>
              </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="bg-amber-50 dark:bg-amber-500/10 p-4 rounded-xl border border-amber-100 dark:border-amber-500/20">
                <div class="text-[10px] font-bold text-amber-600 dark:text-amber-400 uppercase tracking-wider mb-1">Di Kasir, Belum Disetor</div>
                <div id="repRevCashCsoUnsettled" class="text-lg font-extrabold text-gray-800 dark:text-white">Rp 0</div>
              </div>
              <div class="bg-sky-50 dark:bg-sky-500/10 p-4 rounded-xl border border-sky-100 dark:border-sky-500/20">
                <div class="text-[10px] font-bold text-sky-600 dark:text-sky-400 uppercase tracking-wider mb-1">Menunggu Verifikasi</div>
                <div id="repRevCashCsoProcessing" class="text-lg font-extrabold text-gray-800 dark:text-white">Rp 0</div>
              </div>
              <div class="bg-rose-50 dark:bg-rose-500/10 p-4 rounded-xl border border-rose-100 dark:border-rose-500/20">
                <div class="text-[10px] font-bold text-rose-600 dark:text-rose-400 uppercase tracking-wider mb-1">Di Supir, Belum Disetor</div>
                <div id="repRevCashDriverOut" class="text-lg font-extrabold text-gray-800 dark:text-white">Rp 0</div>
              </div>
            </div>
          </div>

          <!-- Rincian per zona -->
          <div class="glass-card rounded-2xl p-5">
            <h4 class="font-bold text-gray-800 dark:text-white text-sm mb-4">Pendapatan per Zona Tujuan</h4>
            <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5 bg-white/50 dark:bg-black/20 backdrop-blur-sm">
              <table class="w-full text-sm text-left">
                <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                  <tr>
                    <th class="py-4 px-5">Zona</th>
                    <th class="py-4 px-5 text-center">Trip</th>
                    <th class="py-4 px-5 text-right">Rata-rata</th>
                    <th class="py-4 px-5 text-right">Pendapatan</th>
                  </tr>
                </thead>
                <tbody id="repRevZoneTable" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
              </table>
            </div>
          </div>

          <!-- Kontribusi CSO & Supir -->
          <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            <div class="glass-card rounded-2xl p-5">
              <h4 class="font-bold text-gray-800 dark:text-white text-sm mb-4">Kontribusi per CSO</h4>
              <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5 bg-white/50 dark:bg-black/20 backdrop-blur-sm">
                <table class="w-full text-sm text-left">
                  <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                    <tr>
                      <th class="py-4 px-5">Nama CSO</th>
                      <th class="py-4 px-5 text-center">Trx</th>
                      <th class="py-4 px-5 text-right">Pendapatan</th>
                    </tr>
                  </thead>
                  <tbody id="repRevCsoTable" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
                </table>
              </div>
            </div>

            <div class="glass-card rounded-2xl p-5">
              <h4 class="font-bold text-gray-800 dark:text-white text-sm mb-4">Kontribusi per Supir</h4>
              <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5 bg-white/50 dark:bg-black/20 backdrop-blur-sm">
                <table class="w-full text-sm text-left">
                  <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                    <tr>
                      <th class="py-4 px-5">Nama Supir</th>
                      <th class="py-4 px-5 text-center">Trx</th>
                      <th class="py-4 px-5 text-right">Pendapatan</th>
                    </tr>
                  </thead>
                  <tbody id="repRevDriverTable" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
                </table>
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

      <!-- CSO PERFORMANCE VIEW -->
      <section id="view-cso-performance" class="hidden space-y-6">
        <div class="glass-card rounded-2xl p-5">
          <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-6 border-b border-gray-100 dark:border-white/10 pb-6">
            <div>
              <h3 class="font-bold text-gray-800 dark:text-white text-lg">Performa CSO</h3>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Pesanan yang dibuat tiap CSO beserta nilainya, per bulan.</p>
            </div>
            <div class="flex flex-col sm:flex-row gap-3">
              <div>
                <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Bulan</label>
                <input type="month" id="csoPerfMonth" class="w-full rounded-xl border-gray-200 bg-gray-50 text-gray-700 dark:border-white/10 dark:bg-black/20 dark:text-gray-200 text-sm py-2 px-3 focus:ring-primary-500 focus:border-primary-500 transition-colors">
              </div>
              <div>
                <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Urutkan</label>
                <select id="csoRankBy" class="rounded-xl border-gray-200 bg-gray-50 text-gray-700 dark:border-white/10 dark:bg-black/20 dark:text-gray-200 text-sm py-2 px-3 focus:ring-primary-500 focus:border-primary-500 cursor-pointer">
                  <option value="orders">Pesanan Terbanyak</option>
                  <option value="revenue">Nilai Tertinggi</option>
                  <option value="cancelled">Pembatalan Terbanyak</option>
                </select>
              </div>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="bg-primary-50 dark:bg-primary-500/10 p-4 rounded-xl border border-primary-100 dark:border-primary-500/20">
              <div class="text-[10px] font-bold text-primary-600 dark:text-primary-400 uppercase tracking-wider mb-1">Total Pesanan</div>
              <div id="csoSumOrders" class="text-2xl font-extrabold text-gray-800 dark:text-white">0</div>
            </div>
            <div class="bg-green-50 dark:bg-emerald-500/10 p-4 rounded-xl border border-green-100 dark:border-emerald-500/20">
              <div class="text-[10px] font-bold text-emerald-600 dark:text-emerald-400 uppercase tracking-wider mb-1">Nilai Transaksi</div>
              <div id="csoSumRevenue" class="text-2xl font-extrabold text-gray-800 dark:text-white">Rp 0</div>
            </div>
            <div class="bg-red-50 dark:bg-red-500/10 p-4 rounded-xl border border-red-100 dark:border-red-500/20">
              <div class="text-[10px] font-bold text-red-500 dark:text-red-400 uppercase tracking-wider mb-1">Dibatalkan</div>
              <div id="csoSumCancelled" class="text-2xl font-extrabold text-gray-800 dark:text-white">0</div>
            </div>
          </div>

          <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5 bg-white/50 dark:bg-black/20 backdrop-blur-sm">
            <table class="w-full text-sm text-left">
              <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                <tr>
                  <th class="py-4 px-5">Nama CSO</th>
                  <th class="py-4 px-5 text-center">Pesanan</th>
                  <th class="py-4 px-5 text-center">Selesai</th>
                  <th class="py-4 px-5 text-center">Dibatalkan</th>
                  <th class="py-4 px-5 text-right">Nilai Transaksi</th>
                  <th class="py-4 px-5 text-right">Rata-rata/Pesanan</th>
                  <th class="py-4 px-5 text-center">Aktivitas Terakhir</th>
                </tr>
              </thead>
              <tbody id="csoPerfTable" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
            </table>
          </div>
          <p class="text-[10px] text-gray-400 dark:text-gray-500 mt-3">&ldquo;Selesai&rdquo; = trip sudah dirampungkan supir. Pesanan yang masih berjalan (belum/baru dibayar) ikut dihitung di kolom Pesanan, jadi Selesai + Dibatalkan tidak selalu sama dengan Pesanan. Aktivitas terakhir dihitung sepanjang waktu, bukan hanya bulan terpilih.</p>
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

      <!-- WHATSAPP GATEWAY VIEW -->
      <section id="view-wa" class="hidden space-y-6">
        <!-- Push notification aplikasi supir (FCM). Ditaruh di halaman yang
             sama dengan WhatsApp karena keduanya kanal pemberitahuan, dan
             admin biasanya mengeceknya bersamaan saat supir mengeluh
             "tidak dapat notifikasi order". -->
        <div class="glass-card rounded-2xl p-5">
          <div class="flex flex-wrap items-start justify-between gap-3 mb-4 border-b border-gray-100 dark:border-white/10 pb-4">
            <div>
              <h3 class="font-bold text-gray-800 dark:text-white text-lg">Push Notification Aplikasi Supir (FCM)</h3>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Notifikasi &ldquo;Order Baru Masuk&rdquo; yang muncul di HP supir. Gratis dari Google &mdash; tidak ada biaya per pesan.</p>
            </div>
            <span id="fcmBadge" class="text-[11px] font-bold px-2.5 py-1 rounded-full bg-slate-100 text-slate-500">Memeriksa…</span>
          </div>

          <div id="fcmReason" class="hidden rounded-xl p-4 mb-4 text-xs leading-relaxed"></div>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
            <div class="rounded-xl border border-gray-100 dark:border-white/5 p-3">
              <div class="text-[10px] uppercase font-bold text-gray-400 tracking-wider">Project Firebase</div>
              <div id="fcmProject" class="text-sm font-mono mt-1 dark:text-slate-200 break-all">&ndash;</div>
            </div>
            <div class="rounded-xl border border-gray-100 dark:border-white/5 p-3">
              <div class="text-[10px] uppercase font-bold text-gray-400 tracking-wider">Supir Siap Menerima</div>
              <div id="fcmTokens" class="text-sm font-bold mt-1 dark:text-slate-200">&ndash;</div>
              <div class="text-[10px] text-gray-400 mt-0.5">Supir tanpa token tidak akan dapat push walau kredensial benar.</div>
            </div>
            <div class="rounded-xl border border-gray-100 dark:border-white/5 p-3">
              <div class="text-[10px] uppercase font-bold text-gray-400 tracking-wider">Mode Antrean</div>
              <div id="fcmQueue" class="text-sm font-mono mt-1 dark:text-slate-200">&ndash;</div>
              <div id="fcmQueueHint" class="text-[10px] text-gray-400 mt-0.5"></div>
            </div>
          </div>

          <div class="flex flex-wrap items-end gap-2">
            <div class="flex-1 min-w-[220px]">
              <label class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Kirim tes ke supir</label>
              <select id="fcmTestDriver" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white text-sm"></select>
            </div>
            <button id="btnFcmTest" class="bg-primary-600 hover:bg-primary-700 text-white text-sm font-semibold px-4 py-2 rounded-lg shadow">Kirim Tes Push</button>
            <button id="btnFcmRefresh" class="text-sm px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 dark:text-slate-200">Muat Ulang</button>
          </div>
          <p id="fcmTestResult" class="text-xs mt-3"></p>
        </div>

        <div class="glass-card rounded-2xl p-5">
          <div class="mb-4 border-b border-gray-100 dark:border-white/10 pb-4">
            <h3 class="font-bold text-gray-800 dark:text-white text-lg">Konfigurasi WhatsApp Gateway</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Kredensial gateway untuk notifikasi WA (struk, order, pencairan).</p>
          </div>
          <form id="formWaConfig" class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs text-slate-500 dark:text-slate-400 mb-1">API Key (header <code class="font-mono">X-API-Key</code>)</label>
              <input type="text" id="waToken" placeholder="wag_&lt;prefix&gt;.&lt;secret&gt;" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400 text-sm">
              <p class="text-[10px] text-slate-400 mt-1">Buat di menu <b>API Keys</b> gateway. Pastikan key punya scope <code class="font-mono">message.send</code>.</p>
            </div>
            <div>
              <label class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Nomor WA Admin (penerima notifikasi)</label>
              <input type="text" id="adminWaNumber" placeholder="0812xxxxxxxx" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400 text-sm">
            </div>
            <div class="md:col-span-2">
              <label class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Base URL Gateway</label>
              <input type="text" id="waEndpoint" placeholder="https://wg.aptpairport.id/api/v1" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400 text-sm">
              <p class="text-[10px] text-slate-400 mt-1">Cukup base URL-nya; sistem menambahkan sendiri <code class="font-mono">/messages/send</code> untuk mengirim dan <code class="font-mono">/messages</code> untuk log. URL kirim lengkap juga masih diterima.</p>
            </div>
            <div class="md:col-span-2">
              <button type="submit" class="bg-primary-600 hover:bg-primary-700 text-white rounded-lg px-4 py-2.5 w-full font-bold shadow transition-transform active:scale-95">Simpan Konfigurasi</button>
            </div>
          </form>
        </div>

        <div class="glass-card rounded-2xl p-5">
          <h3 class="font-bold text-gray-800 dark:text-white text-lg mb-3">Tes Kirim Notifikasi</h3>
          <div class="flex items-end gap-2">
            <div class="flex-1">
              <label class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Kirim WA tes ke nomor</label>
              <input type="text" id="waTestTo" placeholder="0812xxxxxxxx (default: Nomor WA Admin)" class="w-full rounded-lg border border-slate-400 dark:bg-slate-700 dark:border-slate-600 dark:text-white dark:placeholder-slate-400 text-sm">
            </div>
            <button type="button" id="btnTestWa" class="shrink-0 inline-flex items-center gap-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg px-4 py-2 text-sm font-semibold transition-transform active:scale-95">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
              Kirim Tes
            </button>
          </div>
          <p class="text-[11px] text-slate-400 mt-1.5">Pakai konfigurasi tersimpan — simpan API Key dulu sebelum tes.</p>
          <p id="waTestResult" class="hidden text-xs rounded-lg p-2 mt-2"></p>
        </div>

        <div class="glass-card rounded-2xl p-5">
          <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-4 border-b border-gray-100 dark:border-white/10 pb-4">
            <div>
              <h3 class="font-bold text-gray-800 dark:text-white text-lg">Log Pengiriman</h3>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Riwayat pesan dari gateway (GET /messages).</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
              <select id="waLogDirection" class="rounded-lg border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-black/20 dark:text-gray-200 text-xs py-1.5 px-2">
                <option value="">Semua arah</option>
                <option value="OUTBOUND">Keluar</option>
                <option value="INBOUND">Masuk</option>
              </select>
              <select id="waLogStatus" class="rounded-lg border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-black/20 dark:text-gray-200 text-xs py-1.5 px-2">
                <option value="">Semua status</option>
                <option value="SENT">SENT</option>
                <option value="QUEUED">QUEUED</option>
                <option value="FAILED">FAILED</option>
              </select>
              <select id="waLogLimit" class="rounded-lg border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-black/20 dark:text-gray-200 text-xs py-1.5 px-2">
                <option value="20">20</option>
                <option value="50">50</option>
                <option value="100">100</option>
              </select>
              <button id="btnRefreshWaLog" type="button" class="text-xs font-semibold text-primary-600 hover:text-primary-800 flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                Segarkan
              </button>
            </div>
          </div>
          <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-white/5 bg-white/50 dark:bg-black/20">
            <table class="w-full text-sm text-left">
              <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase text-[10px] font-bold tracking-wider">
                <tr>
                  <th class="py-3 px-4">Nomor</th>
                  <th class="py-3 px-4">Pesan</th>
                  <th class="py-3 px-4 text-center">Arah</th>
                  <th class="py-3 px-4 text-center">Status</th>
                  <th class="py-3 px-4">Waktu</th>
                </tr>
              </thead>
              <tbody id="waLogTable" class="divide-y divide-gray-100 dark:divide-white/5"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- SETTINGS VIEW -->
      <section id="view-settings" class="hidden space-y-6">
        <div class="glass-card rounded-2xl max-w-5xl mx-auto overflow-hidden">

          <!-- ===== Kepala + navigasi tab ===== -->
          <div class="px-6 pt-6 bg-gradient-to-br from-primary-500/[0.07] to-transparent dark:from-primary-500/10 border-b border-gray-100 dark:border-white/10">
            <div class="flex flex-wrap items-start justify-between gap-4">
              <div>
                <h3 class="font-bold text-gray-800 dark:text-white text-xl">Pengaturan Sistem</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Konfigurasi tarif, area operasi, notifikasi, dan keamanan akun admin.</p>
              </div>
              <div id="settingsDirtyChip" class="hidden items-center gap-2 text-[11px] font-semibold text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 rounded-full px-3 py-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                Ada perubahan belum disimpan
              </div>
            </div>

            <nav id="settingsTabs" class="flex gap-1 overflow-x-auto -mb-px mt-5 pb-0" role="tablist">
              <button type="button" class="stab" data-stab="umum" role="tab">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                Umum
              </button>
              <button type="button" class="stab" data-stab="area" role="tab">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                Area &amp; Jam
              </button>
              <button type="button" class="stab" data-stab="pembayaran" role="tab">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 6v12a1 1 0 001 1h14a1 1 0 001-1V6M9 10h2v2H9v-2zm4 4h2v2h-2v-2z"></path></svg>
                Pembayaran
              </button>
              <button type="button" class="stab" data-stab="email" role="tab">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                Email
                <span id="smtpStatusDot" class="w-1.5 h-1.5 rounded-full bg-gray-300 dark:bg-white/20"></span>
              </button>
              <button type="button" class="stab" data-stab="keamanan" role="tab">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                Keamanan
              </button>
            </nav>
          </div>

          <!-- ===== Panel yang dikelola formSettings ===== -->
          <form id="formSettings">
            <div class="p-6 space-y-6">

              <!-- ---- UMUM ---- -->
              <div data-spanel="umum" class="space-y-5 animate-fade-in">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                  <div>
                    <label for="adminEmail" class="stlabel">Email Notifikasi Admin</label>
                    <input type="email" id="adminEmail" placeholder="admin@koperasi.id" class="stinput">
                    <p class="sthint">Tujuan pemberitahuan penarikan dana &amp; peringatan sistem.</p>
                  </div>
                  <div>
                    <label for="commissionRate" class="stlabel">Komisi Koperasi (%)</label>
                    <div class="relative">
                      <input type="number" id="commissionRate" min="0" max="100" step="0.1" class="stinput pr-9">
                      <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-gray-400 pointer-events-none">%</span>
                    </div>
                    <p class="sthint">Potongan dari setiap transaksi supir.</p>
                  </div>
                  <div>
                    <label for="maxDriverDebt" class="stlabel">Batas Utang Supir (Rp)</label>
                    <input type="number" id="maxDriverDebt" min="0" step="1000" placeholder="0" class="stinput">
                    <p class="sthint">
                      Supir yang utang komisinya melewati angka ini tidak bisa masuk antrian sampai menyetor tunai.
                      <b>Isi 0 untuk mematikan pembatasan.</b>
                    </p>
                  </div>
                </div>

                <!-- Simulasi komisi: berubah seketika saat angka diketik -->
                <div class="rounded-xl border border-primary-100 dark:border-primary-500/20 bg-primary-50/60 dark:bg-primary-500/10 p-4">
                  <p class="text-[10px] font-bold text-primary-600 dark:text-primary-400 uppercase tracking-widest mb-3">Simulasi dari tarif Rp100.000</p>
                  <div class="flex items-center gap-4">
                    <div class="flex-1">
                      <div class="h-2.5 w-full rounded-full bg-white dark:bg-black/30 overflow-hidden flex">
                        <div id="commissionBarCoop" class="h-full bg-primary-500 transition-all duration-300" style="width:0%"></div>
                        <div id="commissionBarDriver" class="h-full bg-emerald-500 transition-all duration-300" style="width:100%"></div>
                      </div>
                    </div>
                  </div>
                  <div class="flex flex-wrap gap-x-6 gap-y-1 mt-3 text-xs">
                    <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-primary-500"></span>Koperasi <b id="commissionCoop" class="text-gray-800 dark:text-gray-100">Rp 0</b></span>
                    <span class="flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-500"></span>Supir <b id="commissionDriver" class="text-gray-800 dark:text-gray-100">Rp 100.000</b></span>
                  </div>
                </div>
              </div>

              <!-- ---- AREA & JAM ---- -->
              <div data-spanel="area" class="hidden space-y-6 animate-fade-in">
                <div>
                  <div class="flex items-baseline justify-between gap-3 mb-1.5">
                    <label class="stlabel mb-0">Area Bandara (Geofence)</label>
                    <span class="text-[11px] text-gray-500 dark:text-gray-400">Radius <b id="airportRadiusLabel" class="text-primary-600 dark:text-primary-400">&mdash;</b></span>
                  </div>
                  <p class="sthint mb-3">Jangkauan auto-antrian &amp; peta supir. <b>Geser pin biru</b> untuk memindahkan titik pusat, dan <b>geser bulatan biru di tepi lingkaran</b> untuk mengubah radius.</p>

                  <div id="airportCenterMap" style="height: 340px; z-index: 1;" class="rounded-xl overflow-hidden bg-gray-100 dark:bg-black/20 ring-1 ring-gray-200 dark:ring-white/10"></div>

                  <div class="flex flex-wrap items-center justify-between gap-2 mt-3">
                    <p class="sthint">Kotak angka di bawah tersinkron dua arah dengan peta.</p>
                    <button type="button" id="btnResetAirportCenter" class="text-[11px] font-semibold text-primary-600 dark:text-primary-400 hover:underline shrink-0">Kembalikan ke pengaturan tersimpan</button>
                  </div>

                  <!-- Radius tidak diketik: nilainya lahir dari geseran di peta.
                       Tetap sebagai input agar jalur simpan & validasi tidak berubah. -->
                  <input type="hidden" id="airportRadiusKm">

                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
                    <div>
                      <label for="airportLatitude" class="stlabel-sm">Lintang / Latitude</label>
                      <input type="number" id="airportLatitude" min="-90" max="90" step="any" placeholder="-0.371975" class="stinput font-mono">
                    </div>
                    <div>
                      <label for="airportLongitude" class="stlabel-sm">Bujur / Longitude</label>
                      <input type="number" id="airportLongitude" min="-180" max="180" step="any" placeholder="117.257919" class="stinput font-mono">
                    </div>
                  </div>
                  <p id="airportCenterHint" class="sthint mt-2">Perubahan baru berlaku setelah menekan <b>Simpan Pengaturan</b>. Dikosongkan = nilai lama dipertahankan.</p>
                </div>

                <div class="border-t border-gray-100 dark:border-white/10 pt-5">
                  <label class="stlabel">Tenggang di Luar Area</label>
                  <p class="sthint mb-3">Supir yang sudah masuk antrian lalu keluar dari lingkaran di atas diberi tenggang selama ini untuk kembali. Lewat dari itu, antriannya hangus dan ia harus menekan <b>Masuk Antrian</b> lagi secara manual.</p>
                  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                    <div class="sm:col-span-2">
                      <label for="outOfAreaGraceMinutes" class="stlabel-sm">Batas waktu (menit)</label>
                      <input type="number" id="outOfAreaGraceMinutes" min="0" max="720" step="1" placeholder="60" class="stinput">
                    </div>
                    <div class="rounded-xl bg-gray-50 dark:bg-black/20 border border-gray-100 dark:border-white/10 px-3 py-2.5">
                      <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Tenggang</p>
                      <p id="outOfAreaGracePreview" class="text-sm font-bold text-gray-800 dark:text-gray-100">&mdash;</p>
                    </div>
                  </div>
                  <p class="sthint mt-2">Isi <b>0</b> untuk menonaktifkan &mdash; supir tetap ditandai di luar area, tapi antriannya tidak pernah hangus sendiri.</p>
                </div>

                <div class="border-t border-gray-100 dark:border-white/10 pt-5">
                  <label class="stlabel">Jam Operasi Pelacakan (WITA)</label>
                  <p class="sthint mb-3">Di luar jam ini aplikasi driver otomatis mematikan GPS demi hemat baterai &amp; privasi &mdash; kecuali sedang mengantar penumpang.</p>
                  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                    <div>
                      <label for="operatingStart" class="stlabel-sm">Mulai</label>
                      <input type="time" id="operatingStart" class="stinput">
                    </div>
                    <div>
                      <label for="operatingEnd" class="stlabel-sm">Selesai</label>
                      <input type="time" id="operatingEnd" class="stinput">
                    </div>
                    <div class="rounded-xl bg-gray-50 dark:bg-black/20 border border-gray-100 dark:border-white/10 px-3 py-2.5">
                      <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest">Durasi</p>
                      <p id="operatingPreview" class="text-sm font-bold text-gray-800 dark:text-gray-100">&mdash;</p>
                    </div>
                  </div>
                  <p class="sthint mt-2">Samakan jam mulai &amp; selesai untuk 24 jam nonstop.</p>
                </div>
              </div>

              <!-- ---- PEMBAYARAN ---- -->
              <div data-spanel="pembayaran" class="hidden space-y-4 animate-fade-in">
                <label class="stlabel">Kode QRIS Perusahaan</label>
                <p class="sthint">Gambar ini muncul di aplikasi CSO saat penumpang membayar non-tunai.</p>

                <div id="qrisDrop" class="flex flex-col sm:flex-row items-center gap-5 rounded-2xl border-2 border-dashed border-gray-300 dark:border-white/20 p-5 transition-colors">
                  <div class="w-32 h-32 shrink-0 bg-gray-50 dark:bg-black/30 rounded-2xl flex flex-col items-center justify-center overflow-hidden relative group">
                    <img id="previewQris" src="" alt="Pratinjau QRIS" class="w-full h-full object-cover hidden absolute inset-0 z-10 transition-transform group-hover:scale-105">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-gray-400 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    <span id="placeholderQris" class="text-[10px] font-medium text-gray-400 text-center px-2 relative z-0">Belum ada QRIS</span>
                  </div>
                  <div class="flex-1 w-full text-center sm:text-left">
                    <p class="text-sm font-semibold text-gray-700 dark:text-gray-200">Seret gambar ke sini, atau pilih berkas</p>
                    <p class="sthint mt-1 mb-3">Format JPG/PNG, maksimal 2 MB.</p>
                    <input type="file" id="companyQris" accept="image/*" class="w-full text-sm text-gray-500 dark:text-gray-400 file:mr-4 file:py-2.5 file:px-5 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-primary-50 dark:file:bg-primary-500/10 file:text-primary-700 dark:file:text-primary-400 hover:file:bg-primary-100 dark:hover:file:bg-primary-500/20 transition-all cursor-pointer">
                    <p id="qrisFileName" class="text-[11px] font-semibold text-primary-600 dark:text-primary-400 mt-2 hidden"></p>
                  </div>
                </div>
              </div>

              <!-- ---- EMAIL / SMTP ---- -->
              <div data-spanel="email" class="hidden space-y-5 animate-fade-in">
                <div class="flex items-center gap-2">
                  <label class="stlabel mb-0">Konfigurasi SMTP</label>
                  <span id="smtpStatus" class="text-[10px] px-2 py-0.5 rounded-full uppercase tracking-widest border bg-gray-100 text-gray-500 border-gray-200 dark:bg-white/5 dark:text-gray-400 dark:border-white/10">Memuat…</span>
                </div>
                <p class="sthint -mt-3">Dipakai untuk mengirim notifikasi penarikan dana ke email admin.</p>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div class="md:col-span-2">
                    <label for="mailHost" class="stlabel-sm">Mail Host</label>
                    <input type="text" id="mailHost" placeholder="smtp.gmail.com" class="stinput font-mono">
                  </div>
                  <div>
                    <label for="mailPort" class="stlabel-sm">Port</label>
                    <input type="number" id="mailPort" placeholder="587" class="stinput font-mono">
                  </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label for="mailUsername" class="stlabel-sm">Username (Email Pengirim)</label>
                    <input type="text" id="mailUsername" class="stinput">
                  </div>
                  <div>
                    <label for="mailPassword" class="stlabel-sm">Password / App Password</label>
                    <div class="relative">
                      <input type="password" id="mailPassword" class="stinput pr-10">
                      <button type="button" data-toggle-password="mailPassword" class="steye" aria-label="Tampilkan kata sandi"></button>
                    </div>
                  </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label for="mailEncryption" class="stlabel-sm">Enkripsi</label>
                    <select id="mailEncryption" class="stinput cursor-pointer">
                      <option value="tls">TLS (Port 587)</option>
                      <option value="ssl">SSL (Port 465)</option>
                      <option value="null">Tanpa Enkripsi</option>
                    </select>
                  </div>
                  <div>
                    <label for="mailFromName" class="stlabel-sm">Nama Pengirim</label>
                    <input type="text" id="mailFromName" placeholder="Admin Koperasi" class="stinput">
                  </div>
                </div>
              </div>
            </div>

            <!-- ===== Bilah simpan (menempel di bawah kartu) ===== -->
            <div id="settingsSaveBar" class="sticky bottom-0 flex flex-wrap items-center justify-between gap-3 px-6 py-4 border-t border-gray-100 dark:border-white/10 bg-white/85 dark:bg-[#0f172a]/85 backdrop-blur-md">
              <p id="settingsSaveNote" class="text-[11px] text-gray-400 dark:text-gray-500">Semua perubahan tersimpan.</p>
              <div class="flex items-center gap-2">
                <button type="button" id="btnSettingsReset" class="hidden text-sm font-semibold text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-white px-4 py-2.5 rounded-xl transition-colors">Batalkan</button>
                <button type="submit" id="btnSettingsSave" class="bg-gradient-to-r from-primary-600 to-primary-500 hover:from-primary-700 hover:to-primary-600 text-white rounded-xl px-6 py-2.5 font-bold text-sm shadow-md shadow-primary-500/30 transition-all active:scale-95">Simpan Pengaturan</button>
              </div>
            </div>
          </form>

          <!-- ===== KEAMANAN (form terpisah — form tidak boleh bersarang) ===== -->
          <form id="formAdminPassword" data-spanel="keamanan" class="hidden p-6 space-y-5 animate-fade-in">
            <div>
              <label class="stlabel mb-0">Ganti Kata Sandi Admin</label>
              <p class="sthint mt-1">Minimal 6 karakter. Anda tidak akan keluar otomatis setelah menggantinya.</p>
            </div>

            <div class="max-w-md space-y-4">
              <div>
                <label for="currentPassword" class="stlabel-sm">Kata Sandi Saat Ini</label>
                <div class="relative">
                  <input type="password" id="currentPassword" required class="stinput pr-10">
                  <button type="button" data-toggle-password="currentPassword" class="steye" aria-label="Tampilkan kata sandi"></button>
                </div>
              </div>
              <div>
                <label for="newPassword" class="stlabel-sm">Kata Sandi Baru</label>
                <div class="relative">
                  <input type="password" id="newPassword" required minlength="6" class="stinput pr-10">
                  <button type="button" data-toggle-password="newPassword" class="steye" aria-label="Tampilkan kata sandi"></button>
                </div>
                <div class="h-1.5 w-full rounded-full bg-gray-100 dark:bg-white/10 mt-2 overflow-hidden">
                  <div id="pwStrengthBar" class="h-full w-0 bg-gray-300 transition-all duration-300"></div>
                </div>
                <p id="pwStrengthText" class="sthint mt-1">&nbsp;</p>
              </div>
              <div>
                <label for="confirmNewPassword" class="stlabel-sm">Ulangi Kata Sandi Baru</label>
                <div class="relative">
                  <input type="password" id="confirmNewPassword" required minlength="6" class="stinput pr-10">
                  <button type="button" data-toggle-password="confirmNewPassword" class="steye" aria-label="Tampilkan kata sandi"></button>
                </div>
                <p id="pwMatch" class="sthint mt-1">&nbsp;</p>
              </div>
              <button type="submit" class="bg-gray-800 hover:bg-gray-900 dark:bg-white dark:hover:bg-gray-100 dark:text-gray-900 text-white rounded-xl px-5 py-2.5 text-sm font-semibold transition-all active:scale-95 shadow-md">Perbarui Kata Sandi</button>
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
