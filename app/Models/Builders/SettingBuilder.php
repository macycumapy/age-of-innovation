<?php

declare(strict_types=1);

namespace App\Models\Builders;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/** @extends Builder<Setting> */
final class SettingBuilder extends Builder
{
    private const string CACHE_KEY = 'settings.all';

    /** @return array<string, mixed> */
    public function allCached(): array
    {
        return Cache::remember(self::CACHE_KEY, 3600, fn (): array => $this->getModel()->newQuery()
            ->get(['key', 'value'])
            ->mapWithKeys(fn (Setting $setting): array => [$setting->key => $setting->value])
            ->all());
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
