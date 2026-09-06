<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PowerAction;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UsePowerActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->phase === GamePhase::Actions
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction === null
            && ! $game->state->round->hasTakenMainAction;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::enum(PowerAction::class)],
            'sacrifice_amount' => ['required', 'integer', 'min:0'],
        ];
    }

    public function action(): PowerAction
    {
        return PowerAction::from((string) $this->validated('action'));
    }

    public function sacrificeAmount(): int
    {
        return (int) $this->validated('sacrifice_amount');
    }
}
