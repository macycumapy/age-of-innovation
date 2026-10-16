<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\StagedGameActionData;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\Cache;

final class StagedGameActionStore
{
    public function __construct(private PlayerGameActionOptions $options)
    {
    }

    public function get(GamePlayer $player): ?StagedGameActionData
    {
        $staged = Cache::get($this->key($player));

        if (! is_array($staged) || ! is_int($staged['version'] ?? null)
            || $staged['version'] < 0 || ! is_string($staged['type'] ?? null)
            || ! is_array($staged['parameters'] ?? null)) {
            return null;
        }

        if ($staged['type'] === GameActionOptionType::FinishTurn->value) {
            return $staged['parameters'] === [] ? new StagedGameActionData($staged['version'], null) : null;
        }

        $game = $player->game()->firstOrFail();
        foreach ($this->options->get($game, $player)->options as $option) {
            if ($option->type()->value === $staged['type'] && $option->toArray() === $staged['parameters']) {
                return new StagedGameActionData($staged['version'], $option);
            }
        }

        return null;
    }

    public function put(GamePlayer $player, StagedGameActionData $staged): void
    {
        Cache::put($this->key($player), [
            'version' => $staged->version,
            'type' => $staged->option?->type()->value ?? GameActionOptionType::FinishTurn->value,
            'parameters' => $staged->option?->toArray() ?? [],
        ], now()->addHour());
    }

    public function forget(GamePlayer $player): void
    {
        Cache::forget($this->key($player));
    }

    private function key(GamePlayer $player): string
    {
        return 'game:staged-action:'.$player->id;
    }
}
