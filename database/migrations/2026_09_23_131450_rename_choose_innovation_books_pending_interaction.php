<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        $this->replacePendingInteractionType('choose_innovation_books', 'choose_innovation_reward');
    }

    public function down(): void
    {
        $this->replacePendingInteractionType('choose_innovation_reward', 'choose_innovation_books');
    }

    private function replacePendingInteractionType(string $from, string $to): void
    {
        DB::table('games')
            ->select(['id', 'state'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $games) use ($from, $to): void {
                foreach ($games as $game) {
                    $state = json_decode((string) $game->state, true, flags: JSON_THROW_ON_ERROR);
                    $updatedState = $this->replaceValue($state, $from, $to);

                    if ($updatedState !== $state) {
                        DB::table('games')->where('id', $game->id)->update([
                            'state' => json_encode($updatedState, JSON_THROW_ON_ERROR),
                        ]);
                    }
                }
            });
    }

    private function replaceValue(mixed $value, string $from, string $to): mixed
    {
        if ($value === $from) {
            return $to;
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $nestedValue) {
            $value[$key] = $this->replaceValue($nestedValue, $from, $to);
        }

        return $value;
    }
};
