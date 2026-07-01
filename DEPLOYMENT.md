# Panduan Deploy Produksi — taksi-pos

Target: **VPS Linux (Ubuntu/Debian) + Nginx + PHP-FPM + MySQL/MariaDB**.
Panduan ini menutup perbaikan kesiapan-launch **#1–#5**.

> Versi PHP: gunakan **8.3 atau 8.4**. (PHP 8.5 butuh `--ignore-platform-reqs` karena
> `nette/schema` membatasi di 8.4 — hindari di produksi.)

---

## 0. Prasyarat (sekali per server)

```bash
sudo apt update
sudo apt install -y nginx mysql-server php8.3-fpm php8.3-cli php8.3-mysql \
  php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath \
  php8.3-sqlite3 unzip git supervisor certbot python3-certbot-nginx
# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

---

## 1. Ambil kode & dependency

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone <REPO_URL> taksi-pos && cd taksi-pos
sudo chown -R $USER:www-data /var/www/taksi-pos

composer install --no-dev --optimize-autoloader
```

---

## 2. [#1] Konfigurasi `.env` produksi

```bash
cp .env.production.example .env
php artisan key:generate
nano .env   # isi DB_*, MAIL_*, dst.
```

Pastikan **wajib**:
- `APP_ENV=production`
- `APP_DEBUG=false`  ← kalau `true`, stack trace + kredensial bocor ke publik.
- `APP_URL=https://kaj.aptpairport.id`
- `SANCTUM_STATEFUL_DOMAINS=kaj.aptpairport.id` dan `SESSION_DOMAIN=.kaj.aptpairport.id`
  ← **tanpa ini panel admin 401 di semua panggilan API** (login admin berbasis sesi-cookie).

---

## 3. [#3] Database MySQL/MariaDB

```bash
sudo mysql
```
```sql
CREATE DATABASE taksi_pos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'taksi_user'@'127.0.0.1' IDENTIFIED BY 'GANTI_PASSWORD_KUAT';
GRANT ALL PRIVILEGES ON taksi_pos.* TO 'taksi_user'@'127.0.0.1';
FLUSH PRIVILEGES;  EXIT;
```

Lalu migrasi (kode sudah portabel ke MySQL — index & helper tanggal sudah lintas-DB):

```bash
# DB baru (launch perdana): buat tabel + akun awal (admin/cso/driver, semua pw 123456 → SEGERA GANTI)
php artisan migrate --force --seed

# ATAU kalau mau memindahkan data dari SQLite lama: export dulu lalu import,
# atau pakai tool seperti `php artisan db:seed` / migrasi manual sesuai kebutuhan.
```

> Setelah seed, **segera ganti semua password default** lewat panel/akun masing-masing.

---

## 4. Izin folder & optimisasi

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> Tiap kali ubah `.env`, jalankan ulang `php artisan config:cache`.

---

## 5. [#2] Web server Nginx + PHP-FPM + HTTPS

```bash
sudo cp deploy/nginx.conf /etc/nginx/sites-available/taksi-pos
# sesuaikan server_name, root, versi php-fpm socket di file itu
sudo ln -s /etc/nginx/sites-available/taksi-pos /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# SSL gratis (Let's Encrypt):
sudo certbot --nginx -d kaj.aptpairport.id
```

> **Jangan** pakai `php artisan serve` di produksi (itu server dev — mudah mati).

---

## 6. [#4] Cron untuk scheduler (rotasi antrian & backup)

`queue:rotate` (rotasi giliran harian) **dan** `backup:db` sudah dijadwalkan di
`routes/console.php`, tapi hanya menyala jika cron memanggil `schedule:run` tiap menit:

```bash
sudo crontab -u www-data -e
```
Tambahkan:
```cron
* * * * * cd /var/www/taksi-pos && php artisan schedule:run >> /dev/null 2>&1
```
Verifikasi: `php artisan schedule:list` (harus muncul `queue:rotate` 00:00 & `backup:db` 02:00).

---

## 7. Worker antrian (Supervisor)

`QUEUE_CONNECTION=database` → butuh worker (wajib begitu notifikasi dibuat async — #6):

```bash
sudo cp deploy/supervisor-taksi-worker.conf /etc/supervisor/conf.d/taksi-worker.conf
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start taksi-worker:*
```

---

## 8. [#5] Backup database

- Command `php artisan backup:db` sudah ada (mysqldump → `storage/app/backups`, kompres gzip, rotasi `--keep`).
- Sudah **dijadwalkan harian 02:00**. Pastikan `mysqldump` terpasang (paket `mysql-client`).
- **Disarankan backup off-site**: setelah `backup:db`, salin file `storage/app/backups/*.sql.gz`
  ke object storage (S3/Spaces) atau server lain via cron terpisah — agar aman bila server hilang.
- Uji manual: `php artisan backup:db` lalu cek `ls storage/app/backups`.

---

## ✅ Checklist verifikasi pasca-deploy

- [ ] `https://kaj.aptpairport.id` tampil (login), gembok SSL hijau.
- [ ] Login **admin** → buka tab Pengguna/Pencairan: data tampil (tidak 401) → `SANCTUM_STATEFUL_DOMAINS` benar.
- [ ] Login **driver/CSO** dari aplikasi → profil & lokasi jalan.
- [ ] Picu satu error sengaja → **tidak** menampilkan stack trace (artinya `APP_DEBUG=false`).
- [ ] `php artisan schedule:list` menampilkan `queue:rotate` & `backup:db`.
- [ ] `php artisan backup:db` menghasilkan file di `storage/app/backups`.
- [ ] `sudo supervisorctl status` → `taksi-worker` RUNNING.

---

## 🔁 Update/redeploy berikutnya

```bash
cd /var/www/taksi-pos
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo supervisorctl restart taksi-worker:*
php artisan up
```

---

## Peta perbaikan #1–#5

| # | Perbaikan | Di panduan ini |
|---|---|---|
| 1 | `APP_DEBUG=false` + `APP_ENV=production` | §2 + `.env.production.example` |
| 2 | Web server produksi (bukan `artisan serve`) | §5 + `deploy/nginx.conf` |
| 3 | SQLite → MySQL/MariaDB | §3 |
| 4 | Cron `schedule:run` (rotasi harian) | §6 |
| 5 | Backup DB otomatis | §8 + `php artisan backup:db` (terjadwal) |
