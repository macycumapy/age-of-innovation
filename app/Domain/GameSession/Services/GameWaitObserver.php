<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Services;

interface GameWaitObserver
{
    public function waiting(int $gameId, bool $notificationsAvailable): void;

    public function notificationsUnavailable(int $gameId): void;
}
