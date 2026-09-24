<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class BuildWorkshopRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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
            && $game->state->pendingInteraction === null
            && ! $game->state->round->hasTakenMainAction;
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
