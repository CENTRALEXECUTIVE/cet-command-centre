<?php

namespace Tests\Feature;

use App\Console\Commands\BackupDatabase;
use Tests\TestCase;

class BackupDatabaseTest extends TestCase
{
    public function test_prune_keeps_only_the_newest_snapshots(): void
    {
        $dir = storage_path('app/backups-test-'.uniqid());
        mkdir($dir, 0755, true);

        // 5 snapshots, oldest → newest by mtime.
        $files = [];
        for ($i = 0; $i < 5; $i++) {
            $f = "{$dir}/cet-db-2026010{$i}_000000.sql.gz";
            file_put_contents($f, 'x');
            touch($f, now()->subDays(5 - $i)->getTimestamp());
            $files[$i] = $f;
        }

        $kept = BackupDatabase::prune($dir, 2);

        $this->assertSame(2, $kept);
        $this->assertFileDoesNotExist($files[0]); // oldest three gone
        $this->assertFileDoesNotExist($files[1]);
        $this->assertFileDoesNotExist($files[2]);
        $this->assertFileExists($files[3]);       // newest two kept
        $this->assertFileExists($files[4]);

        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }

    public function test_prune_always_keeps_at_least_one(): void
    {
        $dir = storage_path('app/backups-test-'.uniqid());
        mkdir($dir, 0755, true);
        $f = "{$dir}/cet-db-20260101_000000.sql.gz";
        file_put_contents($f, 'x');

        $this->assertSame(1, BackupDatabase::prune($dir, 0));
        $this->assertFileExists($f);

        unlink($f);
        rmdir($dir);
    }

    public function test_command_is_registered_and_safe_in_tests(): void
    {
        // In the testing env it must no-op (never shell out to mysqldump).
        $this->artisan('cet:backup-database')->assertSuccessful();
    }
}
