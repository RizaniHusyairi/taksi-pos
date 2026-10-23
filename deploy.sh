#!/usr/bin/env bash
#
# Update/redeploy taksi-pos di server produksi.
#
#   cd /var/www/taksi-pos && bash deploy.sh
#
# Langkahnya sama dengan bagian "Update/redeploy berikutnya" di DEPLOYMENT.md,
# ditambah pengecekan awal, storage:link, restart worker yang aman, dan cek
# kesehatan aplikasi di akhir.
#
# Variabel opsional (set sebelum menjalankan, mis. `BRANCH=main bash deploy.sh`):
#   APP_DIR          folder aplikasi                 (bawaan: folder skrip ini)
#   BRANCH           cabang git yang di-deploy       (bawaan: cabang aktif)
#   PHP              perintah php                    (bawaan: php)
#   PHP_FPM_SERVICE  nama service php-fpm untuk di-reload, mis. php8.3-fpm
#                    (kosong = tidak di-reload)
#   WORKER_GROUP     grup supervisor worker antrian  (bawaan: taksi-worker)
#   SKIP_HEALTHCHECK isi 1 untuk melewati cek /up di akhir
#
# Bila satu langkah gagal, skrip berhenti dan aplikasi DIBIARKAN dalam mode
# maintenance — kode/database mungkin setengah terbarui, jangan dibuka ke
# pengguna sebelum diperiksa. Pesan di layar menyebut langkah mana yang gagal
# dan cara membuka kembali / kembali ke versi sebelumnya.

set -Eeuo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"
PHP="${PHP:-php}"
WORKER_GROUP="${WORKER_GROUP:-taksi-worker}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-}"

LANGKAH="persiapan"
SUDAH_DOWN=0
COMMIT_LAMA=""

merah()  { printf '\033[31m%s\033[0m\n' "$*"; }
hijau()  { printf '\033[32m%s\033[0m\n' "$*"; }
kuning() { printf '\033[33m%s\033[0m\n' "$*"; }
langkah() { LANGKAH="$1"; printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }

gagal() {
  local kode=$?
  echo
  merah "✖ DEPLOY GAGAL pada langkah: ${LANGKAH} (kode keluar ${kode})"
  if [[ "$SUDAH_DOWN" == 1 ]]; then
    kuning "  Aplikasi masih dalam MODE MAINTENANCE (sengaja, demi keamanan data)."
    kuning "  Setelah masalahnya diperbaiki, jalankan ulang:  bash deploy.sh"
    kuning "  Atau buka kembali apa adanya:                  ${PHP} artisan up"
  fi
  if [[ -n "$COMMIT_LAMA" ]]; then
    kuning "  Kembali ke kode sebelumnya:  git reset --hard ${COMMIT_LAMA}"
    kuning "  (Migrasi yang sudah jalan TIDAK ikut mundur — periksa dulu"
    kuning "   '${PHP} artisan migrate:status' sebelum memakai migrate:rollback.)"
  fi
  exit "$kode"
}
trap gagal ERR

perlu() {
  command -v "$1" >/dev/null 2>&1 || { merah "Perintah '$1' tidak ditemukan di server."; return 1; }
}

cd "$APP_DIR"

# --- 1. Pengecekan awal (sebelum menyentuh apa pun) -------------------------
langkah "Pengecekan awal"
perlu git
perlu "$PHP"
perlu composer
[[ -f artisan ]] || { merah "File artisan tidak ada di ${APP_DIR} — APP_DIR salah?"; false; }
[[ -f .env ]]    || { merah ".env belum ada. Salin dari .env.example & isi dulu (lihat DEPLOYMENT.md §2)."; false; }

# Perubahan lokal di server akan bentrok dengan git pull — hentikan sebelum
# aplikasi diturunkan, bukan di tengah jalan.
if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
  merah "Ada file yang diubah langsung di server (belum di-commit):"
  git status --short --untracked-files=no
  merah "Simpan/buang dulu perubahan itu (git stash / git checkout -- <file>)."
  false
fi

BRANCH="${BRANCH:-$(git rev-parse --abbrev-ref HEAD)}"
COMMIT_LAMA="$(git rev-parse --short HEAD)"
echo "Folder : ${APP_DIR}"
echo "Cabang : ${BRANCH}"
echo "Versi  : ${COMMIT_LAMA} (sebelum update)"

git fetch --prune origin "$BRANCH"
if [[ "$(git rev-parse HEAD)" == "$(git rev-parse "origin/${BRANCH}")" ]]; then
  kuning "Kode sudah versi terbaru — tetap lanjut untuk menyegarkan cache & worker."
fi

# --- 2. Mode maintenance ------------------------------------------------------
langkah "Mode maintenance"
"$PHP" artisan down --retry=15 || true
SUDAH_DOWN=1

# --- 3. Ambil kode -------------------------------------------------------------
langkah "Ambil kode terbaru (git pull)"
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"
COMMIT_BARU="$(git rev-parse --short HEAD)"

# --- 4. Dependency PHP ---------------------------------------------------------
langkah "Composer install"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# --- 5. Database --------------------------------------------------------------
langkah "Migrasi database"
"$PHP" artisan migrate --force

# --- 6. Storage link (foto profil, bukti transfer, bukti setoran) -------------
langkah "Storage link"
if [[ -L public/storage || -d public/storage ]]; then
  echo "public/storage sudah ada."
else
  "$PHP" artisan storage:link
fi

# --- 7. Cache konfigurasi, route, view ----------------------------------------
langkah "Bersihkan & bangun ulang cache"
"$PHP" artisan optimize:clear
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache

# --- 8. Worker antrian & PHP-FPM ----------------------------------------------
langkah "Restart worker antrian"
# queue:restart memberi sinyal ke SEMUA worker untuk berhenti setelah job
# yang sedang jalan selesai; supervisor lalu menyalakannya dengan kode baru.
"$PHP" artisan queue:restart
if command -v supervisorctl >/dev/null 2>&1; then
  if sudo -n true 2>/dev/null; then
    sudo supervisorctl restart "${WORKER_GROUP}:*" \
      || kuning "supervisorctl restart gagal — worker tetap diganti lewat queue:restart."
  else
    kuning "Tanpa sudo tanpa-password: lewati supervisorctl (queue:restart sudah cukup)."
  fi
else
  kuning "supervisorctl tidak ada — pastikan worker antrian berjalan (DEPLOYMENT.md §7)."
fi

if [[ -n "$PHP_FPM_SERVICE" ]]; then
  langkah "Reload ${PHP_FPM_SERVICE} (kosongkan OPcache)"
  sudo systemctl reload "$PHP_FPM_SERVICE"
fi

# --- 9. Buka kembali & cek kesehatan -------------------------------------------
langkah "Buka aplikasi"
"$PHP" artisan up
SUDAH_DOWN=0

if [[ "${SKIP_HEALTHCHECK:-0}" != 1 ]] && command -v curl >/dev/null 2>&1; then
  langkah "Cek kesehatan (/up)"
  APP_URL="$(grep -E '^APP_URL=' .env | tail -n1 | cut -d= -f2- | tr -d "\"' ")"
  if [[ -n "$APP_URL" ]]; then
    if curl -fsS --max-time 15 -o /dev/null "${APP_URL%/}/up"; then
      hijau "${APP_URL%/}/up merespons OK."
    else
      # Aplikasi sudah dibuka: laporkan keras, tapi jangan turunkan lagi.
      merah "⚠ ${APP_URL%/}/up TIDAK merespons. Periksa storage/logs/laravel.log & log web server."
      exit 1
    fi
  else
    kuning "APP_URL kosong di .env — cek kesehatan dilewati."
  fi
fi

trap - ERR
echo
hijau "✔ Deploy selesai: ${COMMIT_LAMA} → ${COMMIT_BARU}"
echo "  Pengingat: aplikasi HP (APK) tidak ikut ter-update lewat deploy ini."
