<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class DistributeRewardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->active_player_id === $this->user()?->id
            && in_array($game->state->pendingInteraction?->type, [
                PendingInteractionType::ChooseStartingResources,
                PendingInteractionType::ChooseCompetency,
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
        $competencyIds = $this->competencyIds();
        $bookCount = $this->bookCount();
        $rules = [
            'book_counts' => [
                Rule::requiredIf($bookCount > 0),
                'array:'.implode(',', array_column(KnowledgeDiscipline::cases(), 'value')),
            ],
            'competency_id' => [
                Rule::requiredIf($competencyIds !== []),
                Rule::prohibitedIf($competencyIds === []),
                Rule::enum(Competency::class),
                Rule::in($competencyIds),
            ],
        ];

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $rules['book_counts.'.$discipline->value] = [
                Rule::requiredIf($bookCount > 0),
                'integer',
                'min:0',
                'max:'.$bookCount,
            ];
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

    public function competency(): ?Competency
    {
        $competencyId = $this->validated('competency_id');

        return is_string($competencyId) ? Competency::from($competencyId) : null;
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

    /** @return array<string, mixed> */
    private function interactionContext(): array
    {
        $game = $this->route('game');

        return $game instanceof Game ? $game->state->pendingInteraction?->context ?? [] : [];
    }

    /** @return list<string> */
    private function competencyIds(): array
    {
        $game = $this->route('game');
        $interaction = $game instanceof Game ? $game->state->pendingInteraction : null;

        return $interaction?->type === PendingInteractionType::ChooseCompetency
            ? $interaction->optionIds
            : (array) ($interaction?->context['competencyIds'] ?? []);
    }
}
