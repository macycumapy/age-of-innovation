<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GameActionType;
use Database\Factories\GameActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $game_id
 * @property int $sequence
 * @property int|null $player_id
 * @property GameActionType $type
 * @property array<string, mixed> $payload
 * @property array<int, array<string, mixed>>|null $events
 * @property int $state_version_before
 * @property int $state_version_after
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'game_id',
    'sequence',
    'player_id',
    'type',
    'payload',
    'events',
    'state_version_before',
    'state_version_after',
])]
class GameAction extends Model
{
    /** @use HasFactory<GameActionFactory> */
    use HasFactory;

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return BelongsTo<User, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(User::class, 'player_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => GameActionType::class,
            'payload' => 'array',
            'events' => 'array',
        ];
    }
}
