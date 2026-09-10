<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UseRoundBonusActionRequest extends FormRequest
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
        return ['discipline' => ['nullable', Rule::enum(KnowledgeDiscipline::class)]];
    }

    public function discipline(): ?KnowledgeDiscipline
    {
        $discipline = $this->validated('discipline');

        return is_string($discipline) ? KnowledgeDiscipline::from($discipline) : null;
    }
}
