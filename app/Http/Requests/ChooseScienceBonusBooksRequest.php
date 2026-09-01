<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ChooseScienceBonusBooksRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->phase === GamePhase::ScienceBonus
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction?->type === PendingInteractionType::ChooseScienceBonusBooks;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = ['book_counts' => ['required', 'array:'.implode(',', array_column(KnowledgeDiscipline::cases(), 'value'))]];
        $bookCount = $this->bookCount();

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $rules['book_counts.'.$discipline->value] = ['required', 'integer', 'min:0', 'max:'.$bookCount];
        }

        return $rules;
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_sum($this->validated('book_counts', [])) !== $this->bookCount()) {
                $validator->errors()->add('book_counts', 'Распределите все полученные книги.');
            }
        }];
    }

    /** @return list<KnowledgeDiscipline> */
    public function disciplines(): array
    {
        $disciplines = [];

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            for ($index = 0; $index < (int) $this->validated('book_counts.'.$discipline->value, 0); $index++) {
                $disciplines[] = $discipline;
            }
        }

        return $disciplines;
    }

    private function bookCount(): int
    {
        $game = $this->route('game');

        return $game instanceof Game ? (int) ($game->state->pendingInteraction?->context['bookCount'] ?? 0) : 0;
    }
}
