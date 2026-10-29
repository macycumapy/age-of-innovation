<?php

declare(strict_types=1);

namespace App\Mcp\Validators;

use App\Mcp\Data\ExecuteGameActionData;
use Laravel\Mcp\Request;

final class ExecuteGameActionValidator
{
    public function validate(Request $request): ExecuteGameActionData
    {
        $data = $request->validate([
            'game_id' => ['sometimes', 'integer', 'min:1'],
            'version' => ['required', 'integer', 'min:0'],
            'action_id' => ['required', 'string', 'max:64'],
        ]);

        return new ExecuteGameActionData(
            isset($data['game_id']) ? (int) $data['game_id'] : null,
            (int) $data['version'],
            $data['action_id'],
        );
    }
}
