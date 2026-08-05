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
 * @property int $id Уникальный идентификатор записи в журнале действий.
 * @property int $game_id Партия, к которой относится действие.
 * @property int $sequence Постоянная хронологическая позиция в журнале действий партии.
 * @property int|null $player_id Пользователь, отправивший действие, или null для системного действия.
 * @property GameActionType $type Команда, применённая к состоянию партии.
 * @property array<string, mixed> $payload Проверенные аргументы команды, переданные игроком.
 * @property array<int, array<string, mixed>>|null $events Доменные события, созданные командой.
 * @property int $state_version_before Версия состояния, относительно которой проверялась команда.
 * @property int $state_version_after Версия состояния, полученная после выполнения команды.
 * @property Carbon|null $created_at Дата и время записи действия.
 * @property Carbon|null $updated_at Дата и время последнего обновления записи действия.
 * @property-read Game $game Партия, к которой относится действие.
 * @property-read User|null $player Пользователь, отправивший действие.
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
