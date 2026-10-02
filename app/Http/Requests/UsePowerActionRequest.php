<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\GameEngine\Economy\Enums\PowerAction;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UsePowerActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');
        $user = $this->user();

        if (! $game instanceof Game || ! $user instanceof User) {
            return false;
        }

        $player = $game->players()->whereBelongsTo($user)->first();

        return $player instanceof GamePlayer
            && $game->phase->isActionPhase()
            && $game->isActivePlayer($player)
            && ($game->state->pendingInteraction !== null || ! $game->state->round->hasTakenMainAction);
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
