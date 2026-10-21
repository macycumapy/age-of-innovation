<?php

declare(strict_types=1);

namespace App\Mcp\Validators;

use App\Domain\GameSession\Queries\WaitForGameTurnQuery;
use App\Mcp\Data\WaitForGameTurnData;
use Laravel\Mcp\Request;

final class WaitForGameTurnValidator
{
    public function validate(Request $request): WaitForGameTurnData
    {
        $data = $request->validate([
            'game_id' => ['sometimes', 'integer', 'min:1'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:0', 'max:'.WaitForGameTurnQuery::TIMEOUT_SECONDS],
            'context_id' => ['sometimes', 'filled', 'string', 'regex:/\A[a-f0-9]{32}\z/'],
        ]);

        return new WaitForGameTurnData(
            isset($data['game_id']) ? (int) $data['game_id'] : null,
            (int) ($data['timeout_seconds'] ?? WaitForGameTurnQuery::TIMEOUT_SECONDS),
            $data['context_id'] ?? null,
        );
    }
}
