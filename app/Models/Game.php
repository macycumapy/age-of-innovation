<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Models\Builders\GameBuilder;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id Уникальный идентификатор партии.
 * @property GameStatus $status Текущий статус жизненного цикла партии.
 * @property int $round Номер текущего раунда от 1 до 6.
 * @property GamePhase $phase Текущая фаза раунда.
 * @property int|null $active_player_id Пользователь, от которого ожидается следующее действие.
 * @property int $version Монотонно возрастающая версия состояния для контроля конкурентных изменений.
 * @property GameStateData $state Авторитетный снимок полного состояния партии.
 * @property string $rules_version Версия правил, по которой создана и проверяется партия.
 * @property string $random_seed Начальное значение для воспроизводимой случайной подготовки партии.
 * @property Carbon|null $started_at Дата и время выхода партии из лобби.
 * @property Carbon|null $finished_at Дата и время завершения партии.
 * @property Carbon|null $created_at Дата и время создания партии.
 * @property Carbon|null $updated_at Дата и время последнего обновления партии.
 * @property-read User|null $activePlayer Пользователь, от которого ожидается следующее действие.
 * @property-read Collection<int, GamePlayer> $players Участники партии.
 * @property-read Collection<int, GameAction> $actions Упорядоченная история действий партии.
 * @method static GameBuilder query()
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
#[UseEloquentBuilder(GameBuilder::class)]
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => GameStatus::Lobby->value,
        'round' => 1,
        'phase' => GamePhase::Setup->value,
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
            'status' => GameStatus::class,
            'phase' => GamePhase::class,
            'state' => GameStateData::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
