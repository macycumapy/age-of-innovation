<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\RoundBonus;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class PassRequest extends FormRequest
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
        $schoolCount = $this->schoolKnowledgeStepCount();

        $rules = [
            'knowledge_counts' => [
                Rule::requiredIf($schoolCount > 0),
                Rule::prohibitedIf($schoolCount === 0),
                'array:'.implode(',', array_column(KnowledgeDiscipline::cases(), 'value')),
            ],
        ];

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $rules['knowledge_counts.'.$discipline->value] = [
                Rule::requiredIf($schoolCount > 0),
                'integer',
                'min:0',
                'max:'.$schoolCount,
            ];
        }

        return $rules;
    }

    /** @return list<KnowledgeDiscipline> */
    public function knowledgeDisciplines(): array
    {
        $knowledgeCounts = $this->validated('knowledge_counts', []);
        $disciplines = [];

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            for ($index = 0; $index < (int) ($knowledgeCounts[$discipline->value] ?? 0); $index++) {
                $disciplines[] = $discipline;
            }
        }

        return $disciplines;
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $schoolCount = $this->schoolKnowledgeStepCount();
            $knowledgeCounts = $this->input('knowledge_counts', []);
            $assignedCount = is_array($knowledgeCounts)
                ? array_sum(array_map('intval', $knowledgeCounts))
                : 0;

            if ($assignedCount !== $schoolCount) {
                $validator->errors()->add('knowledge_counts', 'Распределите все шаги знаний за школы.');
            }
        }];
    }

    private function schoolKnowledgeStepCount(): int
    {
        $game = $this->route('game');

        if (! $game instanceof Game) {
            return 0;
        }

        $player = $game->players()->where('user_id', $this->user()?->id)->first();
        $playerState = $player instanceof GamePlayer
            ? collect($game->state->players)->firstWhere('playerId', $player->id)
            : null;

        if (! $playerState instanceof GamePlayerStateData || $playerState->roundBonus !== RoundBonus::PassSchool) {
            return 0;
        }

        return count(array_filter(
            $game->state->board->hexes,
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->id
                && ! $hex->building->isNeutral
                && $hex->building->type === BuildingType::School,
        ));
    }
}
