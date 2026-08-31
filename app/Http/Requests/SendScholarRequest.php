<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SendScholarRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->phase === GamePhase::Actions
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction === null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'discipline' => ['required', Rule::enum(KnowledgeDiscipline::class)],
            'place' => ['required', 'boolean'],
        ];
    }

    public function discipline(): KnowledgeDiscipline
    {
        return KnowledgeDiscipline::from((string) $this->validated('discipline'));
    }
}
