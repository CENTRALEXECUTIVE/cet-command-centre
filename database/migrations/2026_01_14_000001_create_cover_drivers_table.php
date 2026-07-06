<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A lightweight directory of drivers the office uses (incl. third-party/cover
 * drivers who don't log in) — name, contact number, vehicle reg and make/model —
 * so a driver can be picked when preparing a booking's WhatsApp reminder. Seeded
 * with the current roster.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cover_drivers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('vehicle_reg')->nullable();
            $table->string('vehicle')->nullable();   // colour make model, e.g. "Black Mercedes V Class"
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $roster = [
            ['Kash', '07785 729671', 'AM64 FAR', 'Black Mercedes V Class'],
            ['Mansoor', '07984 304038', 'YC74 XBD', 'Grey Mercedes Vito'],
            ['Toseef', '07446 887038', 'BD21 UML', 'Silver Mercedes Vito'],
            ['Hamza', '07805 470398', 'HA11 ZYG', 'Black Mercedes V Class'],
            ['Mehtab', '07395 565934', 'SB24 OMG', 'Black Ford Tourneo'],
            ['Waleed', '07557 771787', 'V14 XEC', 'Black Mercedes E Class Estate'],
            ['Ibrar', '07854 841856', 'BM11 NAS', 'Black BMW 5 Series Estate'],
            ['Majid', '07730 437557', 'RV20 URK', 'Black Mercedes E-Class'],
            ['Abdi', '07534 283126', 'LR69 HHP', 'Black Mercedes E Class'],
            ['Jabir', '07495 003457', 'KW68 VOT', 'Blue Mercedes E Class'],
            ['Hamza (E-Class)', '07375 704992', 'KM20 YZJ', 'Black Mercedes E-Class Estate'],
        ];

        foreach ($roster as [$name, $phone, $reg, $vehicle]) {
            DB::table('cover_drivers')->updateOrInsert(
                ['vehicle_reg' => $reg],
                ['name' => $name, 'phone' => $phone, 'vehicle' => $vehicle, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cover_drivers');
    }
};
