<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use Illuminate\Validation\ValidationException;

final class ApplyChooseTownBooksAction
{
    public function __construct(private StartLizardTownBonusAction $startLizardTownBonus)
    {
    }

    /** @param array<string, int> $bookCounts */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        array $bookCounts,
    ): int {
        $interaction = $state->pendingInteraction;
        $bookCount = (int) ($interaction?->context['bookCount'] ?? 0);

        if ($interaction?->type !== PendingInteractionType::ChooseTownBooks
            || $interaction->playerId !== $player->playerId
            || ! $this->hasOnlyDisciplines($bookCounts)
            || array_sum($bookCounts) !== $bookCount
            || $player->resources->books->unassigned < $bookCount) {
            throw ValidationException::withMessages(['book_counts' => 'Нельзя распределить книги города.']);
        }

        foreach ($bookCounts as $discipline => $count) {
            $player->resources->books->{$discipline} += $count;
        }
        $player->resources->books->unassigned -= $bookCount;
        $state->pendingInteraction = null;

        if (($interaction->context['felineBonusPending'] ?? false) === true) {
            $player->resources->books->unassigned++;
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::ChooseFelineTownBonus,
                $player->playerId,
                [],
                [
                    'bookCount' => 1,
                    'knowledgeStepCount' => 3,
                    'builtHexId' => (string) ($interaction->context['builtHexId'] ?? ''),
                    'queuedBuiltHexIds' => $interaction->context['queuedBuiltHexIds'] ?? [],
                ],
            );
        } elseif (($interaction->context['lizardBonusPending'] ?? false) === true
            && $player->faction === Faction::Lizards) {
            $this->startLizardTownBonus->execute($state, $player);
        }

        return $player->userId;
    }

    /** @param array<string, int> $bookCounts */
    private function hasOnlyDisciplines(array $bookCounts): bool
    {
        $disciplineIds = array_column(KnowledgeDiscipline::cases(), 'value');

        return collect($bookCounts)->keys()->every(
            static fn (string $discipline): bool => in_array($discipline, $disciplineIds, true),
        ) && collect($bookCounts)->every(
            static fn (int $count): bool => $count >= 0,
        );
    }
}
