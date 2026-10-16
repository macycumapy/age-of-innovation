<?php

declare(strict_types=1);

namespace App\Mcp\Validators;

use App\Mcp\Data\WaitForGameTurnData;
use Laravel\Mcp\Request;

final class WaitForGameTurnValidator
{
    public function validate(Request $request): WaitForGameTurnData
    {
        $data = $request->validate([
            'game_id' => ['sometimes', 'integer', 'min:1'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:0', 'max:20'],
        ]);

        return new WaitForGameTurnData(
            isset($data['game_id']) ? (int) $data['game_id'] : null,
            (int) ($data['timeout_seconds'] ?? 20),
        );
    }
}
