<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Settings\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (SettingKey::cases() as $key) {
            Setting::query()->firstOrCreate(['key' => $key->value], ['value' => $key->defaultValue()]);
        }

        Setting::query()->clearCache();
    }
}
