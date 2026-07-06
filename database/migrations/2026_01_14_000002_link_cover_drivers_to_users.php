<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Link each drivers-directory entry to a real assignable driver account, and
 * normalise the roster's phone numbers to +44. The driver accounts themselves
 * are created on demand (directory "Sync" action / add / edit) rather than here,
 * so this migration stays data-only and doesn't seed accounts into test runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cover_drivers', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        foreach (DB::table('cover_drivers')->get() as $d) {
            $phone = $this->intl($d->phone);
            if ($phone !== $d->phone) {
                DB::table('cover_drivers')->where('id', $d->id)->update(['phone' => $phone]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('cover_drivers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }

    private function intl(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return $phone;
        }
        $p = trim($phone);
        if (str_starts_with($p, '+')) {
            return $p;
        }
        if (str_starts_with($p, '0')) {
            return '+44 '.ltrim(substr($p, 1));
        }

        return '+44 '.$p;
    }
};
