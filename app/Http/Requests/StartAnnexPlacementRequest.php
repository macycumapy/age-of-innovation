<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;

final class StartAnnexPlacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game && $game->active_player_id === $this->user()?->id;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['hex_id' => ['required', 'string']];
    }

    public function hexId(): string
    {
        return (string) $this->validated('hex_id');
    }
}
