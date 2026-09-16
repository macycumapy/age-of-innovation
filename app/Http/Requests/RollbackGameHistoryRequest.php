<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;

final class RollbackGameHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return app()->environment('local', 'testing')
            && $game instanceof Game
            && $game->players()
                ->where('seat', 1)
                ->where('user_id', $this->user()?->id)
                ->exists();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
