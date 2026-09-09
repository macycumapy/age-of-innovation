<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ChooseBooksRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->active_player_id === $this->user()?->id
            && in_array($game->state->pendingInteraction?->type, [
                PendingInteractionType::ChooseScienceBonusBooks,
                PendingInteractionType::ChooseInnovationBooks,
                PendingInteractionType::ChooseShippingBooks,
                PendingInteractionType::ChooseTerraformingBooks,
                PendingInteractionType::ChoosePalaceBooks,
                PendingInteractionType::ChooseTownBooks,
                PendingInteractionType::ChooseFelineTownBonus,
            ], true);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = ['book_counts' => ['required', 'array:'.implode(',', array_column(KnowledgeDiscipline::cases(), 'value'))]];

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $rules['book_counts.'.$discipline->value] = ['required', 'integer', 'min:0', 'max:'.$this->bookCount()];
            $rules['knowledge_counts.'.$discipline->value] = [
                $this->knowledgeStepCount() > 0 ? 'required' : 'nullable',
                'integer',
                'min:0',
                'max:'.$this->knowledgeStepCount(),
            ];
        }

        $rules['knowledge_counts'] = [$this->knowledgeStepCount() > 0 ? 'required' : 'nullable', 'array'];

        return $rules;
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_sum($this->validated('book_counts', [])) !== $this->bookCount()) {
                $validator->errors()->add('book_counts', 'Распределите все полученные книги.');
            }

            if (array_sum($this->validated('knowledge_counts', [])) !== $this->knowledgeStepCount()) {
                $validator->errors()->add('knowledge_counts', 'Распределите все полученные шаги знаний.');
            }
        }];
    }

    /** @return array<string, int> */
    public function bookCounts(): array
    {
        return array_map('intval', (array) $this->validated('book_counts'));
    }

    /** @return array<string, int> */
    public function knowledgeCounts(): array
    {
        return array_map('intval', (array) $this->validated('knowledge_counts', []));
    }

    private function bookCount(): int
    {
        $game = $this->route('game');

        return $game instanceof Game ? (int) ($game->state->pendingInteraction?->context['bookCount'] ?? 0) : 0;
    }

    private function knowledgeStepCount(): int
    {
        $game = $this->route('game');

        return $game instanceof Game
            ? (int) ($game->state->pendingInteraction?->context['knowledgeStepCount'] ?? 0)
            : 0;
    }
}
