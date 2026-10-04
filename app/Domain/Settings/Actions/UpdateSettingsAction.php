<?php

declare(strict_types=1);

namespace App\Domain\Settings\Actions;

use App\Domain\Settings\Data\SettingsData;
use App\Models\Setting;
use Illuminate\Support\Arr;

final class UpdateSettingsAction
{
    public function execute(SettingsData $settings): void
    {
        $rows = collect(Arr::dot($settings->toArray(), depth: 1))
            ->map(fn (bool|array $value, string $key): array => [
                'key' => $key,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
            ])
            ->values()
            ->all();

        Setting::query()->upsert($rows, ['key'], ['value']);

        Setting::clearCache();
    }
}
