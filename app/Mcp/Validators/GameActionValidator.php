<?php

declare(strict_types=1);

namespace App\Mcp\Validators;

use App\Mcp\Data\GameActionData;
use Laravel\Mcp\Request;

final class GameActionValidator
{
    public function validate(Request $request): GameActionData
    {
        $data = $request->validate([
            'game_id' => ['sometimes', 'integer', 'min:1'],
            'version' => ['required', 'integer', 'min:0'],
        ]);

        return new GameActionData(
            isset($data['game_id']) ? (int) $data['game_id'] : null,
            (int) $data['version'],
        );
    }
}
