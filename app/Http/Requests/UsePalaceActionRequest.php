<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UsePalaceActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game && $game->phase === GamePhase::Actions
            && $game->active_player_id === $this->user()?->id && $game->state->pendingInteraction === null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'discipline' => ['nullable', Rule::enum(KnowledgeDiscipline::class)],
            'hex_id' => ['nullable', 'string'],
        ];
    }

    public function discipline(): ?KnowledgeDiscipline
    {
        $value = $this->validated('discipline');

        return is_string($value) ? KnowledgeDiscipline::from($value) : null;
    }

    public function hexId(): ?string
    {
        $value = $this->validated('hex_id');

        return is_string($value) ? $value : null;
    }
}
