<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\TerrainType;
use Database\Factories\GamePlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id Уникальный идентификатор участника партии.
 * @property int $game_id Партия, в которой участвует пользователь.
 * @property int $user_id Пользователь, управляющий участником.
 * @property int $seat Постоянная позиция игрока в партии.
 * @property PlayerColor|null $color Выбранный цвет компонентов.
 * @property Faction|null $faction Выбранное сообщество.
 * @property TerrainType|null $homeland Выбранный тип родной местности.
 * @property bool $is_ready Подтвердил ли участник готовность в лобби.
 * @property int|null $result_place Итоговое место после завершения партии.
 * @property int|null $final_score Итоговое количество победных очков.
 * @property Carbon|null $created_at Дата и время присоединения участника.
 * @property Carbon|null $updated_at Дата и время последнего обновления участника.
 * @property-read Game $game Партия, в которой участвует пользователь.
 * @property-read User $user Пользователь, управляющий участником.
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
            'color' => PlayerColor::class,
            'faction' => Faction::class,
            'homeland' => TerrainType::class,
            'is_ready' => 'boolean',
        ];
    }
}
