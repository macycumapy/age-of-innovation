<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Builders\GameBuilder;
use Carbon\CarbonInterface;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id Уникальный идентификатор партии.
 * @property GameStatus $status Текущий статус жизненного цикла партии.
 * @property int $round Номер текущего раунда от 1 до 6.
 * @property GamePhase $phase Текущая фаза раунда.
 * @property int|null $active_player_id Пользователь, от которого ожидается следующее действие.
 * @property int|null $active_game_player_id Участник партии, от которого ожидается следующее действие.
 * @property CarbonInterface|null $current_turn_started_at Дата и время начала хода активного игрока.
 * @property int $version Монотонно возрастающая версия состояния для контроля конкурентных изменений.
 * @property GameStateData $state Авторитетный снимок полного состояния партии.
 * @property string $rules_version Версия правил, по которой создана и проверяется партия.
 * @property string $random_seed Начальное значение для воспроизводимой случайной подготовки партии.
 * @property CarbonInterface|null $started_at Дата и время выхода партии из лобби.
 * @property CarbonInterface|null $finished_at Дата и время завершения партии.
 * @property CarbonInterface|null $created_at Дата и время создания партии.
 * @property CarbonInterface|null $updated_at Дата и время последнего обновления партии.
 * @property-read User|null $activePlayer Пользователь, от которого ожидается следующее действие.
 * @property-read GamePlayer|null $activeGamePlayer Участник, от которого ожидается следующее действие.
 * @property-read Collection<int, GamePlayer> $players Участники партии.
 * @property-read Collection<int, GameAction> $actions Упорядоченная история действий партии.
 * @method static GameBuilder query()
 */
#[Fillable([
    'status',
    'round',
    'phase',
    'active_player_id',
    'active_game_player_id',
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

    protected static function booted(): void
    {
        static::saving(function (Game $game): void {
            if ($game->isDirty('active_player_id')) {
                $game->current_turn_started_at = $game->active_player_id === null ? null : now();

                if ($game->exists) {
                    $game->active_game_player_id = $game->active_player_id === null
                        ? null
                        : $game->players()->where('user_id', $game->active_player_id)->value('id');
                }
            } elseif ($game->isDirty('active_game_player_id')) {
                $game->current_turn_started_at = $game->active_game_player_id === null ? null : now();
                $game->active_player_id = $game->active_game_player_id === null
                    ? null
                    : GamePlayer::query()->find($game->active_game_player_id)?->user_id;
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function activePlayer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'active_player_id');
    }

    /** @return BelongsTo<GamePlayer, $this> */
    public function activeGamePlayer(): BelongsTo
    {
        return $this->belongsTo(GamePlayer::class, 'active_game_player_id');
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

    public function isActivePlayer(GamePlayer $player): bool
    {
        return $this->active_game_player_id === $player->id
            || ($this->active_game_player_id === null
                && $player->user_id !== null
                && $this->active_player_id === $player->user_id);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => GameStatus::class,
            'phase' => GamePhase::class,
            'state' => GameStateData::class,
            'current_turn_started_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
