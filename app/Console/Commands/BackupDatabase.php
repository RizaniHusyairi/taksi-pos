<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class BackupDatabase extends Command
{
    protected $signature = 'backup:db {--keep=14 : Jumlah backup terbaru yang disimpan}';

    protected $description = 'Backup database ke storage/app/backups (mysqldump untuk MySQL, copy file untuk SQLite), dengan rotasi.';

    public function handle(): int
    {
        $conn = config('database.default');
        $dir = storage_path('app/backups');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $stamp = now()->format('Y-m-d_His');

        if ($conn === 'sqlite') {
            // Dev / skala sangat kecil: cukup salin file DB-nya.
            $src = config('database.connections.sqlite.database');
            $dest = "{$dir}/backup_{$stamp}.sqlite";
            if (!is_file($src) || !@copy($src, $dest)) {
                $this->error("Gagal menyalin file SQLite: {$src}");
                return self::FAILURE;
            }
            $this->info("Backup SQLite tersimpan: {$dest}");
        } else {
            // Produksi (MySQL/MariaDB): mysqldump terkompresi gzip.
            $c = config("database.connections.{$conn}");
            $dest = "{$dir}/backup_{$stamp}.sql.gz";

            // Kredensial lewat file sementara (--defaults-extra-file) agar password
            // TIDAK pernah muncul di daftar proses (lebih aman dari --password=).
            $cnf = tempnam(sys_get_temp_dir(), 'bkp');
            file_put_contents($cnf, "[client]\n"
                . "host = {$c['host']}\n"
                . 'port = ' . ($c['port'] ?? 3306) . "\n"
                . "user = {$c['username']}\n"
                . 'password = "' . $c['password'] . "\"\n");

            $cmd = sprintf(
                'mysqldump --defaults-extra-file=%s --single-transaction --quick --no-tablespaces %s | gzip > %s',
                escapeshellarg($cnf),
                escapeshellarg($c['database']),
                escapeshellarg($dest)
            );

            $process = Process::fromShellCommandline($cmd);
            $process->setTimeout(900);
            $process->run();
            @unlink($cnf);

            if (!$process->isSuccessful()) {
                @unlink($dest);
                $this->error('mysqldump gagal: ' . trim($process->getErrorOutput()));
                return self::FAILURE;
            }
            $this->info("Backup MySQL tersimpan: {$dest}");
        }

        // Rotasi: simpan N backup terbaru, hapus sisanya.
        $keep = max(1, (int) $this->option('keep'));
        $files = glob("{$dir}/backup_*") ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a)); // terbaru dulu
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
        $this->info('Rotasi selesai (menyimpan ' . min(count($files), $keep) . ' backup terbaru).');

        return self::SUCCESS;
    }
}
