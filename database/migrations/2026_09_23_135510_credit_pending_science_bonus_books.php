<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        $this->updateSavedStates(1);
    }

    public function down(): void
    {
        $this->updateSavedStates(-1);
    }

    private function updateSavedStates(int $direction): void
    {
        DB::table('games')
            ->select(['id', 'state'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $games) use ($direction): void {
                foreach ($games as $game) {
                    $state = json_decode((string) $game->state, true, flags: JSON_THROW_ON_ERROR);
                    $updatedState = $this->creditPendingScienceBooks($state, $direction);

                    if ($updatedState !== $state) {
                        DB::table('games')->where('id', $game->id)->update([
                            'state' => json_encode($updatedState, JSON_THROW_ON_ERROR),
                        ]);
                    }
                }
            });
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function creditPendingScienceBooks(array $state, int $direction): array
    {
        $interaction = $state['pendingInteraction'] ?? null;
        if (is_array($interaction)
            && ($interaction['type'] ?? null) === 'choose_science_bonus_books') {
            $playerId = $interaction['playerId'] ?? null;
            $bookCount = (int) ($interaction['context']['bookCount'] ?? 0);

            foreach ($state['players'] ?? [] as $index => $player) {
                if (is_array($player) && ($player['playerId'] ?? null) === $playerId) {
                    $unassigned = (int) ($player['resources']['books']['unassigned'] ?? 0);
                    $state['players'][$index]['resources']['books']['unassigned'] = max(
                        0,
                        $unassigned + ($direction * $bookCount),
                    );
                    break;
                }
            }
        }

        foreach (['turnStartSnapshot', 'townChoiceCheckpoint'] as $snapshotKey) {
            if (is_array($state[$snapshotKey] ?? null)) {
                $state[$snapshotKey] = $this->creditPendingScienceBooks($state[$snapshotKey], $direction);
            }
        }

        return $state;
    }
};
