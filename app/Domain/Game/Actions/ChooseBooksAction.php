<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class ChooseBooksAction
{
    public function __construct(
        private ChooseScienceBonusBooksAction $chooseScienceBonusBooks,
        private ChooseInnovationBooksAction $chooseInnovationBooks,
        private ChooseTownBooksAction $chooseTownBooks,
    ) {
    }

    /** @param array<string, int> $bookCounts */
    public function execute(Game $game, User $user, array $bookCounts): Game
    {
        return match ($game->state->pendingInteraction?->type) {
            PendingInteractionType::ChooseScienceBonusBooks => $this->chooseScienceBonusBooks->execute($game, $user, $this->disciplines($bookCounts)),
            PendingInteractionType::ChooseInnovationBooks => $this->chooseInnovationBooks->execute($game, $user, $bookCounts),
            PendingInteractionType::ChooseTownBooks => $this->chooseTownBooks->execute($game, $user, $this->disciplines($bookCounts)),
            default => throw ValidationException::withMessages(['book_counts' => 'Сейчас нельзя распределить книги.']),
        };
    }

    /**
     * @param array<string, int> $bookCounts
     * @return list<KnowledgeDiscipline>
     */
    private function disciplines(array $bookCounts): array
    {
        $disciplines = [];

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            for ($count = $bookCounts[$discipline->value] ?? 0; $count > 0; $count--) {
                $disciplines[] = $discipline;
            }
        }

        return $disciplines;
    }
}
