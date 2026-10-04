<?php

declare(strict_types=1);

namespace App\Domain\Settings\Services;

use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\Settings\Data\SettingsData;
use App\Domain\Settings\Enums\SettingKey;
use App\Models\Setting;

final class SettingsService
{
    public function get(): SettingsData
    {
        $settings = Setting::allCached();

        $botsEnabled = $settings[SettingKey::BotsEnabled->value] ?? SettingKey::BotsEnabled->defaultValue();
        $botDifficultyValues = $settings[SettingKey::BotDifficulties->value] ?? SettingKey::BotDifficulties->defaultValue();
        $botDifficulties = array_values(array_filter(
            GameBotDifficulty::cases(),
            fn (GameBotDifficulty $difficulty): bool => is_array($botDifficultyValues)
                && in_array($difficulty->value, $botDifficultyValues, true),
        ));

        return new SettingsData($botsEnabled === true, $botDifficulties);
    }
}
