<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $status
 * @property int $round
 * @property string $phase
 * @property int|null $active_player_id
 * @property int $version
 * @property array<string, mixed> $state
 * @property string $rules_version
 * @property string $random_seed
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'status',
    'round',
    'phase',
    'active_player_id',
    'version',
    'state',
    'rules_version',
    'random_seed',
    'started_at',
    'finished_at',
])]
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'lobby',
        'round' => 1,
        'phase' => 'setup',
        'version' => 0,
        'state' => '[]',
        'rules_version' => '1.2',
    ];

    /** @return BelongsTo<User, $this> */
    public function activePlayer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'active_player_id');
    }

    /** @return HasMany<GamePlayer, $this> */
    public function players(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    /** @return HasMany<GameAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(GameAction::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
