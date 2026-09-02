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
        $rules = [
            'discipline' => ['nullable', Rule::enum(KnowledgeDiscipline::class)],
            'knowledge_steps' => ['nullable', 'array'],
            'hex_id' => ['nullable', 'string'],
        ];

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $rules['knowledge_steps.'.$discipline->value] = ['nullable', 'integer', 'min:0', 'max:2'];
        }

        return $rules;
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

    /** @return list<KnowledgeDiscipline> */
    public function knowledgeDisciplines(): array
    {
        $disciplines = [];

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $stepCount = (int) $this->validated("knowledge_steps.{$discipline->value}", 0);

            for ($step = 0; $step < $stepCount; $step++) {
                $disciplines[] = $discipline;
            }
        }

        return $disciplines;
    }
}
