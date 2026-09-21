<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Recall the Command Centre's memory: restore the database from a snapshot taken
 * by cet:backup-database. Destructive (it replaces the current data), so it
 * refuses without --force AND takes a fresh safety snapshot of the CURRENT state
 * first — so even an unwanted restore is itself reversible.
 *
 *   php artisan cet:restore-database                 # list available snapshots
 *   php artisan cet:restore-database <file> --force  # restore that snapshot
 */
class RestoreDatabase extends Command
{
    protected $signature = 'cet:restore-database {file? : snapshot filename under storage/app/backups} {--force : actually restore (destructive)}';

    protected $description = 'Restore the database from a snapshot (recall a saved state)';

    public function handle(): int
    {
        $dir = storage_path('app/backups');
        $snaps = collect(File::glob($dir.'/cet-*.sql.gz'))->sortByDesc(fn ($f) => filemtime($f))->values();

        if ($snaps->isEmpty()) {
            $this->warn('No snapshots found in '.$dir.'. Run cet:backup-database first.');

            return self::SUCCESS;
        }

        $name = $this->argument('file');
        if (! $name) {
            $this->info('Available snapshots (newest first) — restore with: cet:restore-database <name> --force');
            foreach ($snaps->take(30) as $f) {
                $this->line('  '.basename($f).'   '.date('D d M H:i', filemtime($f)).'   '.round(filesize($f) / 1048576, 1).' MB');
            }

            return self::SUCCESS;
        }

        $file = $dir.'/'.basename($name); // basename: never escape the backups dir
        if (! is_file($file)) {
            $this->error('No such snapshot: '.basename($name).'. Run without a name to list them.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->warn('This REPLACES all current data with '.basename($file).'.');
            $this->line('Re-run with --force to go ahead. A safety snapshot of the current data is taken first.');

            return self::SUCCESS;
        }

        $conn = config('database.default');
        $c = config("database.connections.{$conn}");
        if (($c['driver'] ?? null) !== 'mysql') {
            $this->error("Restore is for MySQL; the '{$conn}' connection is '{$c['driver']}'.");

            return self::FAILURE;
        }

        // Safety net: snapshot the CURRENT state before overwriting it.
        $this->info('Taking a safety snapshot of the current data first…');
        Artisan::call('cet:backup-database', [], $this->output);

        $this->info('Restoring '.basename($file).' …');
        $cmd = sprintf(
            'gunzip -c %s | %s --host=%s --port=%s --user=%s %s',
            escapeshellarg($file),
            escapeshellarg(env('CET_MYSQL_PATH') ?: 'mysql'),
            escapeshellarg((string) ($c['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($c['port'] ?? 3306)),
            escapeshellarg((string) $c['username']),
            escapeshellarg((string) $c['database']),
        );
        $process = Process::fromShellCommandline($cmd, base_path(), ['MYSQL_PWD' => (string) ($c['password'] ?? '')]);
        $process->setTimeout(900);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('Restore FAILED. '.trim($process->getErrorOutput()));
            $this->line('Your data was not changed beyond the safety snapshot just taken.');

            return self::FAILURE;
        }

        $this->info('Restored from '.basename($file).'. Clearing caches…');
        Artisan::call('optimize:clear');

        return self::SUCCESS;
    }
}
