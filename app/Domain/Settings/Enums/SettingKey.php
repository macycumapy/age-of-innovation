<?php

declare(strict_types=1);

namespace App\Domain\Settings\Enums;

enum SettingKey: string
{
    case BotsEnabled = 'bots.enabled';
    case BotDifficulties = 'bots.available_difficulties';

    /** @return bool|list<string> */
    public function defaultValue(): bool|array
    {
        return match ($this) {
            self::BotsEnabled => false,
            self::BotDifficulties => ['fast', 'balanced'],
        };
    }
}
