<?php

declare(strict_types=1);

namespace App\Mcp\Presentation;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameSession\Data\GameActionOptionsData;
use DomainException;

final class GameActionIdentifier
{
    public function forOption(?GameActionOption $option, int $version): string
    {
        return $option === null
            ? GameActionOptionType::FinishTurn->value
            : 'v'.$version.':'.substr(hash('sha256', json_encode([$option->type()->value, $option->toArray()], JSON_THROW_ON_ERROR)), 0, 16);
    }

    public function resolve(string $id, GameActionOptionsData $options): ?GameActionOption
    {
        if ($id === GameActionOptionType::FinishTurn->value && $options->canFinishTurn) {
            return null;
        }
        $selected = null;
        foreach ($options->options as $option) {
            if ($this->forOption($option, $options->version) === $id) {
                if ($selected !== null && ($selected->type() !== $option->type() || $selected->toArray() !== $option->toArray())) {
                    throw new DomainException('Ambiguous action identifier.');
                }
                $selected = $option;
            }
        }

        if ($selected !== null) {
            return $selected;
        }

        throw new DomainException('Select an action from get_legal_actions.');
    }
}
