<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;

final readonly class GameActionOptionsData
{
    /** @param list<GameActionOption> $options */
    public function __construct(
        public int $version,
        public array $options,
        public bool $canFinishTurn,
    ) {
    }

    public function allows(?GameActionOption $selected): bool
    {
        if ($selected === null) {
            return $this->canFinishTurn;
        }
        foreach ($this->options as $option) {
            if ($option->type() === $selected->type() && $option->toArray() === $selected->toArray()) {
                return true;
            }
        }

        return false;
    }
}
