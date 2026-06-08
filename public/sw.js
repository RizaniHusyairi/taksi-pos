/*
 * Service Worker - Taksi POS (CSO & Admin PWA)
 *
 * Strategi:
 *  - Aset statis (CSS/JS/gambar/font, termasuk CDN)  -> stale-while-revalidate (cepat, tahan koneksi flaky)
 *  - Navigasi halaman (HTML)                          -> network-first, fallback cache bila offline
 *  - API / data dinamis / autentikasi / storage       -> network-only (selalu data terbaru, TIDAK di-cache)
 *
 * Naikkan VERSION setiap kali daftar aset berubah agar cache lama dibersihkan.
 */
const VERSION = 'v1.0.0';
const STATIC_CACHE = `taksi-pos-static-${VERSION}`;

// Aset inti yang di-precache saat install (aman, bukan data transaksi)
const PRECACHE_URLS = [
  '/pos-assets/css/style.css',
  '/pos-assets/js/utils.js',
  '/pos-assets/js/cso.js',
  '/pos-assets/js/admin.js',
  '/pos-assets/js/data.js',
  '/pos-assets/img/logo_taksi.png',
  '/pos-assets/img/logo-koperasi.png',
  '/pos-assets/img/qris-placeholder.svg',
  '/pos-assets/img/pwa/icon-192.png',
  '/pos-assets/img/pwa/icon-512.png',
  '/pos-assets/img/pwa/icon-maskable-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(STATIC_CACHE);
    // add() per item agar 1 file gagal tidak membatalkan seluruh instalasi
    await Promise.all(PRECACHE_URLS.map((url) => cache.add(url).catch(() => {})));
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys
        .filter((k) => k.startsWith('taksi-pos-static-') && k !== STATIC_CACHE)
        .map((k) => caches.delete(k))
    );
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Hanya tangani GET; POST/PUT/DELETE (mis. proses order) selalu langsung ke jaringan
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  const sameOrigin = url.origin === self.location.origin;

  // Network-only: data dinamis & endpoint yang tidak boleh basi/di-cache
  if (sameOrigin && (
        url.pathname.startsWith('/api') ||
        url.pathname.startsWith('/login') ||
        url.pathname.startsWith('/logout') ||
        url.pathname.startsWith('/receipt') ||
        url.pathname.startsWith('/storage') ||
        url.pathname === '/sw.js' ||
        url.pathname.endsWith('.webmanifest')
      )) {
    return; // biarkan browser mengambil dari jaringan
  }

  // Navigasi halaman: network-first
  if (req.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        return await fetch(req);
      } catch (e) {
        const cached = await caches.match(req);
        return cached || Response.error();
      }
    })());
    return;
  }

  // Aset statis: stale-while-revalidate
  if (isStaticAsset(url)) {
    event.respondWith(staleWhileRevalidate(req));
  }
});

function isStaticAsset(url) {
  if (url.origin === self.location.origin && url.pathname.startsWith('/pos-assets')) return true;

  const cdnHosts = [
    'cdn.tailwindcss.com',
    'fonts.googleapis.com',
    'fonts.gstatic.com',
    'cdnjs.cloudflare.com',
    'cdn.jsdelivr.net',
  ];
  if (cdnHosts.includes(url.hostname)) return true;

  return /\.(?:css|js|mjs|png|jpe?g|svg|gif|webp|woff2?|ttf|ico)$/i.test(url.pathname);
}

async function staleWhileRevalidate(req) {
  const cache = await caches.open(STATIC_CACHE);
  const cached = await cache.match(req);

  const networkFetch = fetch(req)
    .then((res) => {
      // Simpan respons valid (ok) atau opaque (CDN no-cors)
      if (res && (res.ok || res.type === 'opaque')) {
        cache.put(req, res.clone()).catch(() => {});
      }
      return res;
    })
    .catch(() => null);

  if (cached) return cached;          // kembalikan cache segera, update di background
  const fresh = await networkFetch;   // belum ada cache -> tunggu jaringan
  return fresh || Response.error();
}
