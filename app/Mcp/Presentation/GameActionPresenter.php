<?php

declare(strict_types=1);

namespace App\Mcp\Presentation;

use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameSession\Data\ConfirmedGameActionData;
use App\Domain\GameSession\Data\GameActionOptionsData;
use App\Domain\GameSession\Data\StagedGameActionData;
use DomainException;

final class GameActionPresenter
{
    public function __construct(private GameActionIdentifier $identifiers)
    {
    }

    /** @return array<string, mixed> */
    public function confirmed(ConfirmedGameActionData $result): array
    {
        return [
            'confirmed' => true,
            'game_id' => $result->game->id,
            'version' => $result->game->version,
            'action' => [
                'type' => $result->action->option?->type()->value ?? GameActionOptionType::FinishTurn->value,
                'parameters' => $result->action->option?->toArray() ?? [],
            ],
            'continuation' => $result->continuation->value,
            'next_player_id' => $result->nextPlayerId,
        ];
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
            $id = $this->identifiers->forOption($option, $result->version);
            $action = [
                'action_id' => $id,
                'type' => $option?->type()->value ?? GameActionOptionType::FinishTurn->value,
                'parameters' => $option?->toArray() ?? [],
            ];
            if (isset($actions[$id]) && $actions[$id] !== $action) {
                throw new DomainException('Ambiguous action identifier.');
            }
            $actions[$id] = $action;
        }

        return ['version' => $result->version, 'actions' => array_values($actions)];
    }

    /** @return array<string, mixed> */
    public function staged(StagedGameActionData $staged): array
    {
        return [
            'version' => $staged->version,
            'action_id' => $this->identifiers->forOption($staged->option, $staged->version),
        ];
    }
}
