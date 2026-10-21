<?php

declare(strict_types=1);

namespace App\Mcp\Presentation;

use App\Domain\GameSession\Data\GameStateViewData;
use App\Domain\GameSession\Data\GameTurnContextData;
use LogicException;

final class GameContextUpdatePresenter
{
    public function __construct(private GameStatePresenter $state)
    {
    }

    /** @return array<string, mixed> */
    public function present(GameStateViewData $before, GameTurnContextData $after): array
    {
        if ($before->gameId !== $after->view->gameId || $before->playerId !== $after->view->playerId
            || $before->userId !== $after->view->userId || $before->version >= $after->view->version
            || $after->view->version !== $after->actions->version) {
            throw new LogicException('Context update must belong to the same player and game with a newer shared version.');
        }

        $next = $this->state->turnContext($after);
        $snapshot = ['format' => 'context-snapshot-v1', 'context' => $next];
        $delta = [
            'format' => 'context-delta-v1',
            'game_id' => $before->gameId,
            'base_version' => $before->version,
            'version' => $after->view->version,
            'changes' => $this->changes($this->state->present($before), $this->state->present($after->view)),
            'actions' => $next['actions'],
        ];

        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        return strlen(json_encode($delta, $flags)) < strlen(json_encode($snapshot, $flags)) ? $delta : $snapshot;
    }

    /**
     * @param list<int|string> $path
     * @return list<array<string, mixed>>
     */
    private function changes(mixed $before, mixed $after, array $path = []): array
    {
        if ($before === $after) {
            return [];
        }

        if (! is_array($before) || ! is_array($after) || array_is_list($before) !== array_is_list($after)
            || (array_is_list($before) && count($before) !== count($after))) {
            return [['op' => 'set', 'path' => $path, 'value' => $after]];
        }

        $changes = [];
        foreach ($before as $key => $value) {
            if (! array_key_exists($key, $after)) {
                $changes[] = ['op' => 'remove', 'path' => [...$path, $key]];
            }
        }
        foreach ($after as $key => $value) {
            $changes = [...$changes, ...(! array_key_exists($key, $before)
                ? [['op' => 'set', 'path' => [...$path, $key], 'value' => $value]]
                : $this->changes($before[$key], $value, [...$path, $key]))];
        }

        return $changes;
    }
}
