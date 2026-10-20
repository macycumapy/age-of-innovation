<?php

declare(strict_types=1);

namespace App\Mcp\Validators;

use App\Mcp\Data\GameComponentData;
use App\Mcp\Enums\GameComponentType;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;

final class GameComponentValidator
{
    public function validate(Request $request): GameComponentData
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(GameComponentType::class)],
            'id' => ['required', 'string', 'max:64'],
        ]);

        return new GameComponentData(GameComponentType::from($data['type']), $data['id']);
    }
}
