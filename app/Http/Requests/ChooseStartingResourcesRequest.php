<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

        return [
            'book_discipline' => [
                Rule::requiredIf($bookCount > 0),
                Rule::prohibitedIf($bookCount === 0),
                Rule::enum(KnowledgeDiscipline::class),
            ],
            'knowledge_disciplines' => [
                Rule::requiredIf($knowledgeStepCount > 0),
                Rule::prohibitedIf($knowledgeStepCount === 0),
                'array',
                'size:'.$knowledgeStepCount,
            ],
            'knowledge_disciplines.*' => [Rule::enum(KnowledgeDiscipline::class)],
        ];
    }

    public function bookDiscipline(): ?KnowledgeDiscipline
    {
        $discipline = $this->validated('book_discipline');

        return is_string($discipline) ? KnowledgeDiscipline::from($discipline) : null;
    }

    /** @return list<KnowledgeDiscipline> */
    public function knowledgeDisciplines(): array
    {
        $disciplines = $this->validated('knowledge_disciplines', []);

        if (! is_array($disciplines)) {
            return [];
        }

        return array_map(
            static fn (mixed $discipline): KnowledgeDiscipline => KnowledgeDiscipline::from((string) $discipline),
            $disciplines,
        );
    }
}
