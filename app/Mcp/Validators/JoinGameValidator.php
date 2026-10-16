<?php

declare(strict_types=1);

namespace App\Mcp\Validators;

use App\Mcp\Data\JoinGameData;
use Laravel\Mcp\Request;

final class JoinGameValidator
{
    public function validate(Request $request): JoinGameData
    {
        $data = $request->validate([
            'game_id' => ['required', 'integer', 'min:1'],
        ]);

        return new JoinGameData(
            (int) $data['game_id'],
        );
    }
}
