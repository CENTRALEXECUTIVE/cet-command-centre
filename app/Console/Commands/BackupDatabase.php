<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * The Command Centre's own memory: a full database snapshot it takes of itself,
 * on a schedule, so nothing can ever be silently lost again. Each run dumps the
 * live database to a timestamped, gzipped file under storage/app/backups, then
 * prunes old ones. READ-ONLY against the data — it only ever COPIES, it can
 * never change or delete a booking. Restore with `cet:restore-database`.
 *
 * Nothing to configure: it reads the app's own DB credentials. mysqldump path
 * can be overridden with CET_MYSQLDUMP_PATH if the host puts it somewhere odd.
 *
 *   php artisan cet:backup-database            # take a snapshot now
 *   php artisan cet:backup-database --keep=72  # keep the newest 72 (default)
 */
class BackupDatabase extends Command
{
    protected $signature = 'cet:backup-database {--keep=72 : how many recent snapshots to keep}';

    protected $description = 'Take a gzipped snapshot of the database (the Command Centre backing itself up)';

    public function handle(): int
    {
        if (app()->environment('testing')) {
            return self::SUCCESS; // never shell out during tests
        }

        $conn = config('database.default');
        $c = config("database.connections.{$conn}");

        if (($c['driver'] ?? null) !== 'mysql') {
            $this->warn("Backups are for MySQL; the '{$conn}' connection is '{$c['driver']}'. Skipped.");

            return self::SUCCESS;
        }

        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir, 0755);

        $stamp = now()->format('Y-m-d_His');
        $file = "{$dir}/cet-{$c['database']}-{$stamp}.sql.gz";
        $bin = env('CET_MYSQLDUMP_PATH') ?: 'mysqldump';

        // Password via MYSQL_PWD env so it never appears in the process list.
        // --single-transaction: consistent snapshot without locking the app out.
        // --no-tablespaces: avoids a privilege shared hosting rarely grants.
        $cmd = sprintf(
            '%s --single-transaction --quick --no-tablespaces --default-character-set=utf8mb4 '
            .'--host=%s --port=%s --user=%s %s | gzip -9 > %s',
            escapeshellarg($bin),
            escapeshellarg((string) ($c['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($c['port'] ?? 3306)),
            escapeshellarg((string) $c['username']),
            escapeshellarg((string) $c['database']),
            escapeshellarg($file),
        );

        $process = Process::fromShellCommandline($cmd, base_path(), [
            'MYSQL_PWD' => (string) ($c['password'] ?? ''),
        ]);
        $process->setTimeout(900);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($file) || filesize($file) < 100) {
            @unlink($file); // never leave a truncated snapshot around
            $err = trim($process->getErrorOutput()) ?: 'mysqldump produced no output';
            $this->error('Backup FAILED — no snapshot written. '.$err);
            $this->line('If mysqldump is missing, set CET_MYSQLDUMP_PATH in .env to its full path.');

            return self::FAILURE;
        }

        $size = $this->human(filesize($file));
        $kept = self::prune($dir, (int) $this->option('keep'));
        $this->info('Snapshot saved: '.basename($file)." ({$size}). {$kept} snapshot(s) retained.");

        return self::SUCCESS;
    }

    /**
     * Keep the newest $keep snapshots and delete the rest, so backups can never
     * fill the disk. Always keeps at least one. Returns how many remain.
     */
    public static function prune(string $dir, int $keep): int
    {
        $keep = max(1, $keep);
        $files = collect(File::glob($dir.'/cet-*.sql.gz'))
            ->sortByDesc(fn ($f) => filemtime($f))
            ->values();

        $files->slice($keep)->each(fn ($f) => @unlink($f));

        return min($files->count(), $keep);
    }

    private function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1).' '.$unit;
            }
            $bytes /= 1024;
        }

        return round($bytes, 1).' TB';
    }
}
