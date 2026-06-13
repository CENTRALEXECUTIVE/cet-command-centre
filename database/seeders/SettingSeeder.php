<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'calendar_id', 'value' => 'admin@centralexecutivetransfers.co.uk', 'group' => 'calendar', 'description' => 'Google Calendar that receives booking events'],
            ['key' => 'ico_registration_number', 'value' => '', 'group' => 'gdpr', 'description' => 'ICO registration number shown on customer-facing pages'],
            ['key' => 'gps_retention_days', 'value' => '90', 'type' => 'int', 'group' => 'gdpr', 'description' => 'Days before GPS pings are auto-deleted'],
            ['key' => 'privacy_policy_version', 'value' => '1.0', 'group' => 'gdpr', 'description' => 'Current privacy policy version'],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting + ['type' => $setting['type'] ?? 'string']
            );
        }
    }
}
