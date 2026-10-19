<?php

declare(strict_types=1);

namespace App\Mcp\Presentation;

use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameSession\Data\GameActionOptionsData;
use App\Domain\GameSession\Data\StagedGameActionData;

final class GameActionPresenter
{
    public function __construct(private GameActionIdentifier $identifiers)
    {
    }

    /** @return array<string, mixed> */
    public function legalActions(GameActionOptionsData $result): array
    {
        $options = $result->options;
        if ($result->canFinishTurn) {
            $options[] = null;
        }

        $actions = [];
        foreach ($options as $option) {
            $id = $this->identifiers->forOption($option);
            $actions[$id] = [
                'action_id' => $id,
                'type' => $option?->type()->value ?? GameActionOptionType::FinishTurn->value,
                'parameters' => $option?->toArray() ?? [],
            ];
        }

        return ['version' => $result->version, 'actions' => array_values($actions)];
    }

    /** @return array<string, mixed> */
    public function staged(StagedGameActionData $staged): array
    {
        return [
            'version' => $staged->version,
            'action_id' => $this->identifiers->forOption($staged->option),
        ];
    }
}
