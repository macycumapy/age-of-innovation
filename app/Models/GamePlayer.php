<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GamePlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $game_id
 * @property int $user_id
 * @property int $seat
 * @property string|null $color
 * @property string|null $faction
 * @property string|null $homeland
 * @property bool $is_ready
 * @property int|null $result_place
 * @property int|null $final_score
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'game_id',
    'user_id',
    'seat',
    'color',
    'faction',
    'homeland',
    'is_ready',
    'result_place',
    'final_score',
])]
class GamePlayer extends Model
{
    /** @use HasFactory<GamePlayerFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_ready' => false,
    ];

    /** @return BelongsTo<Game, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_ready' => 'boolean',
        ];
    }
}
