<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\GamePhase;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;

final class SacrificePowerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->phase === GamePhase::Actions
            && $game->active_player_id === $this->user()?->id;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
        ];
    }

    public function amount(): int
    {
        return (int) $this->validated('amount');
    }
}
