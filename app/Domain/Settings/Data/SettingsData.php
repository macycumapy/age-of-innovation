<?php

declare(strict_types=1);

namespace App\Domain\Settings\Data;

use App\Domain\Game\Enums\GameBotDifficulty;

final readonly class SettingsData
{
    /** @param list<GameBotDifficulty> $botDifficulties */
    public function __construct(public bool $botsEnabled, public array $botDifficulties)
    {
    }

    /** @return array{bots: array{enabled: bool, available_difficulties: list<string>}} */
    public function toArray(): array
    {
        return [
            'bots' => [
                'enabled' => $this->botsEnabled,
                'available_difficulties' => array_map(fn (GameBotDifficulty $difficulty): string => $difficulty->value, $this->botDifficulties),
            ],
        ];
    }
}
