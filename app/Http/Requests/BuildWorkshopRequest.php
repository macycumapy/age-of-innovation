<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\GamePhase;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;

final class BuildWorkshopRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->phase === GamePhase::Actions
            && $game->active_player_id === $this->user()?->id
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
