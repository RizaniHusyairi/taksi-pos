<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title> Taksi POS - Login</title>
  <meta name="csrf-token" content="{{ csrf_token() }}">

  <script src="https://cdn.tailwindcss.com"></script>

  <script>
    // Tailwind config - primary palette
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: {
              50:'#eff6ff',100:'#dbeafe',200:'#bfdbfe',300:'#93c5fd',400:'#60a5fa',
              500:'#3b82f6',600:'#2563eb',700:'#1d4ed8',800:'#1e40af',900:'#1e3a8a'
            }
          }
        }
      }
    }
  </script>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' fill='%231d4ed8'/><text x='50' y='58' font-size='48' text-anchor='middle' fill='white'>KT</text></svg>">
  <link rel="stylesheet" href="{{ asset('pos-assets/css/style.css') }}">
</head>
<body class="min-h-screen bg-slate-50 flex items-center justify-center p-4">
  <div class="w-full max-w-md bg-white shadow-xl rounded-xl p-8">
    <div class="flex items-center gap-3 mb-6">
      <div class="w-10 h-10 rounded-lg bg-primary-600 text-white grid place-items-center font-semibold">KT</div>
      <div>
        <h1 class="text-xl font-semibold text-slate-800">Taksi POS</h1>
        <p class="text-sm text-slate-500">Masuk untuk melanjutkan</p>
      </div>
    </div>

    <form id="loginForm" class="space-y-4" method="POST" action="{{ url('/login') }}">
      @csrf
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Username</label>
        <input id="username" type="text" required name="username" class="w-full rounded-lg border-slate-300 focus:border-primary-500 focus:ring-primary-500">
        @error('username')
              <span class="text-red-600 text-sm">{{ $message }}</span>
          @enderror
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
        <input id="password" type="password" required name="password" class="w-full rounded-lg border-slate-300 focus:border-primary-500 focus:ring-primary-500">
      </div>
      <button class="w-full bg-primary-600 hover:bg-primary-700 text-white font-medium py-2.5 rounded-lg">Masuk</button>
    </form>

    <div id="loginMsg" class="mt-4 text-sm text-center text-red-600 hidden">Username atau password salah.</div>

    
  </div>

</body>
</html>
