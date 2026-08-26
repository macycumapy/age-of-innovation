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

final class ChooseStartingResourcesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');
        $interaction = $game instanceof Game ? $game->state->pendingInteraction : null;

        return $game instanceof Game
            && $interaction?->type === PendingInteractionType::ChooseStartingResources
            && $game->active_player_id === $this->user()?->id
            && $game->players()
                ->whereKey($interaction->playerId)
                ->where('user_id', $this->user()?->id)
                ->exists();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $game = $this->route('game');
        $interaction = $game instanceof Game ? $game->state->pendingInteraction : null;
        $bookCount = (int) ($interaction?->context['bookCount'] ?? 0);
        $knowledgeStepCount = (int) ($interaction?->context['knowledgeStepCount'] ?? 0);
        $competencyIds = (array) ($interaction?->context['competencyIds'] ?? []);

        $rules = [
            'book_counts' => [
                Rule::requiredIf($bookCount > 0),
                Rule::prohibitedIf($bookCount === 0),
                'array:'.implode(',', array_column(KnowledgeDiscipline::cases(), 'value')),
            ],
            'knowledge_counts' => [
                Rule::requiredIf($knowledgeStepCount > 0),
                Rule::prohibitedIf($knowledgeStepCount === 0),
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
                Rule::requiredIf($knowledgeStepCount > 0),
                'integer',
                'min:0',
                'max:'.$knowledgeStepCount,
            ];
        }

        return $rules;
    }

    /** @return list<KnowledgeDiscipline> */
    public function bookDisciplines(): array
    {
        $bookCounts = $this->validated('book_counts', []);

        if (! is_array($bookCounts)) {
            return [];
        }

        $disciplines = [];

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $count = (int) ($bookCounts[$discipline->value] ?? 0);

            for ($index = 0; $index < $count; $index++) {
                $disciplines[] = $discipline;
            }
        }

        return $disciplines;
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $game = $this->route('game');
                $interaction = $game instanceof Game ? $game->state->pendingInteraction : null;
                $bookCount = (int) ($interaction?->context['bookCount'] ?? 0);
                $knowledgeStepCount = (int) ($interaction?->context['knowledgeStepCount'] ?? 0);
                $submittedCounts = $this->input('book_counts', []);
                $submittedKnowledgeCounts = $this->input('knowledge_counts', []);
                $assignedBookCount = is_array($submittedCounts)
                    ? array_sum(array_map('intval', $submittedCounts))
                    : 0;

                if ($assignedBookCount !== $bookCount) {
                    $validator->errors()->add(
                        'book_counts',
                        'Распределите все стартовые книги.',
                    );
                }

                $assignedKnowledgeStepCount = is_array($submittedKnowledgeCounts)
                    ? array_sum(array_map('intval', $submittedKnowledgeCounts))
                    : 0;

                if ($assignedKnowledgeStepCount !== $knowledgeStepCount) {
                    $validator->errors()->add(
                        'knowledge_counts',
                        'Распределите все стартовые шаги знаний.',
                    );
                }
            },
        ];
    }

    /** @return list<KnowledgeDiscipline> */
    public function knowledgeDisciplines(): array
    {
        $knowledgeCounts = $this->validated('knowledge_counts', []);

        if (! is_array($knowledgeCounts)) {
            return [];
        }

        $disciplines = [];

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $count = (int) ($knowledgeCounts[$discipline->value] ?? 0);

            for ($index = 0; $index < $count; $index++) {
                $disciplines[] = $discipline;
            }
        }

        return $disciplines;
    }

    public function competency(): ?Competency
    {
        $competencyId = $this->validated('competency_id');

        return is_string($competencyId) ? Competency::from($competencyId) : null;
    }
}
