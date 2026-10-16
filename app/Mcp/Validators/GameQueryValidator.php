<?php

declare(strict_types=1);

namespace App\Mcp\Validators;

use App\Mcp\Data\GameQueryData;
use Laravel\Mcp\Request;

final class GameQueryValidator
{
    public function validate(Request $request): GameQueryData
    {
        $data = $request->validate([
            'game_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        return new GameQueryData(
            isset($data['game_id']) ? (int) $data['game_id'] : null,
        );
    }
}
