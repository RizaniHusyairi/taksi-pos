<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Taksi POS - Login Angkasa Jaya</title>
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <link rel="icon" href="{{ asset('pos-assets/img/logo_taksi.png') }}" type="image/png">

  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    // Tailwind config - primary (blue-900 base) & accent (amber-500 base)
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          fontFamily: {
            sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif'],
          },
          colors: {
            primary: {
              50:'#eff6ff', 100:'#dbeafe', 200:'#bfdbfe', 300:'#93c5fd', 400:'#60a5fa',
              500:'#3b82f6', 600:'#2563eb', 700:'#1d4ed8', 800:'#1e40af', 900:'#1e3a8a', 950:'#172554'
            },
            accent: {
              400: '#fbbf24', 500: '#f59e0b', 600: '#d97706'
            }
          },
          animation: {
            'fade-in': 'fadeIn 0.5s ease-out',
            'slide-up': 'slideUp 0.6s ease-out'
          },
          keyframes: {
            fadeIn: {
              '0%': { opacity: '0' },
              '100%': { opacity: '1' }
            },
            slideUp: {
              '0%': { opacity: '0', transform: 'translateY(20px)' },
              '100%': { opacity: '1', transform: 'translateY(0)' }
            }
          }
        }
      }
    }
  </script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family: 'Inter', sans-serif; }
    .glass-panel {
      background: rgba(255, 255, 255, 0.85);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid rgba(255, 255, 255, 0.4);
    }
    .max-w-sm{
      max-width: 27rem;
    }
  </style>
</head>
<body class="min-h-screen bg-slate-50 flex flex-col md:flex-row antialiased overflow-hidden">

  <!-- LEFT COLUMN : HERO IMAGE (60%) -->
  <div class="hidden md:flex md:w-[60%] relative bg-primary-900 justify-center items-center">
    <!-- Placeholder Image (Taksi Fleet / Modern Blue Theme) -->
    <img src="{{asset('pos-assets/img/logo_taksi.png')}}" alt="Armada Taksi" class="absolute inset-0 w-full h-full object-scale-down mix-blend-overlay opacity-60">
    <div class="absolute inset-0 bg-gradient-to-t from-primary-950 via-primary-900/60 to-transparent"></div>
    
    <div class="relative z-10 px-12 lg:px-24 text-white animate-slide-up">
      <div class="mb-6 inline-flex items-center justify-center p-3 bg-white/10 rounded-2xl backdrop-blur-sm border border-white/20">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-accent-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
        </svg>
      </div>
      <h1 class="text-4xl lg:text-5xl font-bold mb-4 leading-tight">Melayani Sepenuh Hati,<br><span class="text-accent-400">Menjangkau Setiap Destinasi.</span></h1>
      <p class="text-primary-100 text-lg max-w-lg mb-8 opacity-90 leading-relaxed">Sistem Koperasi Terpadu Angkasa Jaya memudahkan administrasi dan pencatatan perjalanan harian Anda.</p>
      
      <div class="flex items-center gap-4 text-sm font-medium text-white/80">
        <div class="flex -space-x-3">
          <div class="w-10 h-10 rounded-full border-2 border-primary-900 bg-primary-700 flex items-center justify-center"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg></div>
          <div class="w-10 h-10 rounded-full border-2 border-primary-900 bg-primary-600 flex items-center justify-center"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg></div>
          <div class="w-10 h-10 rounded-full border-2 border-primary-900 bg-accent-500 flex items-center justify-center"><svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div>
        </div>
        <p>Dipercaya oleh ratusan<br>pengemudi profesional.</p>
      </div>
    </div>
  </div>

  <!-- RIGHT COLUMN : LOGIN FORM (40%) -->
  <div class="w-full md:w-[40%] min-h-screen relative flex items-center justify-center p-6 sm:p-12 relative">
    
    <!-- Mobile Background Image Setup (Hidden on Desktop) -->
    <div class="absolute inset-0 md:hidden bg-primary-900 z-0">
         <img src="{{asset('pos-assets/img/logo_taksi.png')}}" alt="Taksi Background" class="w-full h-full object-scale-down mix-blend-overlay opacity-50">
         <div class="absolute inset-0 bg-gradient-to-t from-primary-900 via-primary-900/80 to-transparent"></div>
    </div>

    <!-- MAIN FORM CONTAINER -->
    <div class="w-full max-w-sm relative z-10 glass-panel md:bg-white md:backdrop-filter-none border-0 md:border md:shadow-2xl rounded-[28px] p-8 md:p-10 animate-fade-in shadow-black/10">
      
      <!-- Logo Header -->
      <div class="flex items-center gap-3 mb-8">
        <img src="{{ asset('pos-assets/img/logo_taksi.png') }}" alt="Logo" class="w-12 h-12 object-contain bg-white rounded-xl shadow-lg p-1 shadow-primary-900/30" />
        <div>
          <h1 class="text-2xl font-bold text-gray-900 tracking-tight">TAKSI</h1>
          <p class="text-xs font-semibold text-accent-500 uppercase tracking-widest mt-0.5">Koperasi Angkasa Jaya</p>
        </div>
      </div>

      <div class="mb-8">
        <h2 class="text-2xl font-bold text-gray-900 mb-2">Selamat Datang 👋</h2>
        <p class="text-sm text-gray-500 font-medium">Masuk dengan akun Koperasi Anda untuk memulai perjalanan hari ini.</p>
      </div>

      <form id="loginForm" class="space-y-5" method="POST" action="{{ url('/login') }}" onsubmit="document.getElementById('btnSubmit').classList.add('opacity-80', 'cursor-wait'); document.getElementById('btnText').classList.add('hidden'); document.getElementById('btnSpinner').classList.remove('hidden');">
        @csrf
        
        <!-- Username Field -->
        <div>
          <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-2">Username</label>
          <div class="relative group">
            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
              <svg class="h-5 w-5 text-gray-400 group-focus-within:text-primary-600 transition-colors" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
            </div>
            <input id="username" type="text" name="username" placeholder="Masukkan Username" required autofocus
              class="w-full pl-11 pr-4 py-3.5 bg-gray-50/50 border @error('username') border-red-300 ring-4 ring-red-50 @else border-gray-200 focus:ring-4 focus:ring-primary-50 focus:border-primary-500 @enderror rounded-2xl text-sm transition-all text-gray-800 placeholder-gray-400 font-medium outline-none">
          </div>
          @error('username')
              <div class="flex items-center gap-1.5 mt-2 text-red-600">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span class="text-xs font-semibold">{{ $message }}</span>
              </div>
          @enderror
        </div>

        <!-- Password Field -->
        <div>
          <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-2">Kata Sandi</label>
          <div class="relative group">
            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
              <svg class="h-5 w-5 text-gray-400 group-focus-within:text-primary-600 transition-colors" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
              </svg>
            </div>
            <input id="password" type="password" name="password" placeholder="••••••••" required 
              class="w-full pl-11 pr-12 py-3.5 bg-gray-50/50 border border-gray-200 focus:ring-4 focus:ring-primary-50 focus:border-primary-500 rounded-2xl text-sm transition-all text-gray-800 font-medium outline-none placeholder-gray-400 tracking-wider">
            
            <!-- Eye Toggle -->
            <button type="button" onclick="const p=document.getElementById('password'); if(p.type==='password'){p.type='text';this.innerHTML='<svg class=\'w-5 h-5\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21\'></path></svg>';}else{p.type='password';this.innerHTML='<svg class=\'w-5 h-5\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M15 12a3 3 0 11-6 0 3 3 0 016 0z\'></path><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z\'></path></svg>';}" class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-gray-600 transition-colors">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
              </svg>
            </button>
          </div>
        </div>

        <div class="pt-4">
          <button id="btnSubmit" type="submit" class="w-full flex items-center justify-center bg-gradient-to-r from-accent-500 to-accent-600 hover:from-accent-600 hover:to-accent-700 text-white font-bold text-sm py-4 px-4 rounded-full shadow-lg shadow-accent-500/30 transition-all active:scale-[0.98]">
            <span id="btnText" class="tracking-widest uppercase">MASUK SEKARANG</span>
            <svg id="btnSpinner" class="animate-spin hidden h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
          </button>
        </div>
      </form>
      
      <!-- Footer Info -->
      <div class="mt-8 text-center">
        <p class="text-[11px] font-medium text-gray-400">Butuh bantuan? Silakan hubungi <a href="#" class="text-primary-600 hover:underline">Pengurus Koperasi</a>.</p>
        <p class="text-[10px] text-gray-400 mt-2">&copy; {{ date('Y') }} Koperasi Taksi Angkasa Jaya.</p>
      </div>

    </div>
  </div>

</body>
</html>
