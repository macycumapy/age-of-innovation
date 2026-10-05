<?php

declare(strict_types=1);

namespace App\Http\Requests\Game;

use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UseFactionActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->phase->isActionPhase()
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction === null;
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
