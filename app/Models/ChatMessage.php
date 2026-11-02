<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Builders\ChatMessageBuilder;
use Carbon\CarbonInterface;
use Database\Factories\ChatMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $game_id
 * @property int|null $user_id
 * @property string $client_message_id
 * @property string $body
 * @property CarbonInterface $created_at
 * @property-read Game $game
 * @property-read User|null $user
 * @method static ChatMessageBuilder query()
 */
#[Fillable(['game_id', 'user_id', 'client_message_id', 'body'])]
#[UseEloquentBuilder(ChatMessageBuilder::class)]
class ChatMessage extends Model
{
    /** @use HasFactory<ChatMessageFactory> */
    use HasFactory;

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
}
