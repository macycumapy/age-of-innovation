<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;

final class PlaceStartingBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->active_player_id === $this->user()?->id
            && $game->players()->where('user_id', $this->user()?->id)->exists();
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'hex_id' => ['required', 'string', 'max:20'],
        ];
    }
}
