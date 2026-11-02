<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Broadcasting\GameChatChannel;
use App\Domain\Chat\Actions\SendChatMessageAction;
use App\Domain\Chat\Data\ChatMessageData;
use App\Events\ChatMessageCreated;
use App\Models\Builders\ChatMessageBuilder;
use App\Models\ChatMessage;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GameChatTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_participant_can_send_a_message_without_changing_the_game(): void
    {
        $player = GamePlayer::factory()->create();
        $before = $player->game->getAttributes();
        $clientId = (string) Str::uuid();
        Event::fake([ChatMessageCreated::class]);

        $this->actingAs($player->user)
            ->postJson(route('games.chat.messages.store', $player->game), [
                'body' => '  Привет!  ',
                'client_message_id' => $clientId,
                'user_id' => User::factory()->create()->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Привет!')
            ->assertJsonPath('data.clientMessageId', $clientId)
            ->assertJsonPath('data.authorId', $player->user_id)
            ->assertJsonPath('data.authorName', $player->user->name);

        $this->assertSame($before, $player->game->fresh()->getAttributes());
        $this->assertSame(0, $player->game->actions()->count());
        $this->assertFalse(Schema::hasColumn('chat_messages', 'author_name'));
        Event::assertDispatchedTimes(ChatMessageCreated::class, 1);
    }

    public function test_retry_returns_the_same_message_and_does_not_broadcast_again(): void
    {
        $player = GamePlayer::factory()->create();
        Event::fake([ChatMessageCreated::class]);
        $payload = ['body' => 'Привет', 'client_message_id' => (string) Str::uuid()];
        $this->actingAs($player->user);

        $first = $this->postJson(route('games.chat.messages.store', $player->game), $payload)->assertCreated();
        $this->postJson(route('games.chat.messages.store', $player->game), $payload)
            ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, ChatMessage::query()->count());
        Event::assertDispatchedTimes(ChatMessageCreated::class, 1);
    }

    public function test_reusing_a_client_id_with_another_body_is_rejected(): void
    {
        $player = GamePlayer::factory()->create();
        Event::fake([ChatMessageCreated::class]);
        $clientId = (string) Str::uuid();
        $this->actingAs($player->user);
        $url = route('games.chat.messages.store', $player->game);

        $this->postJson($url, ['body' => 'Первое', 'client_message_id' => $clientId])->assertCreated();
        $this->postJson($url, ['body' => 'Другое', 'client_message_id' => $clientId])
            ->assertUnprocessable()->assertJsonValidationErrors('client_message_id');
        $this->assertSame('Первое', ChatMessage::query()->sole()->body);
    }

    public function test_client_ids_are_scoped_to_author_and_game_and_normalized(): void
    {
        $first = GamePlayer::factory()->create(['seat' => 1]);
        $second = GamePlayer::factory()->for($first->game)->create(['seat' => 2]);
        $otherGamePlayer = GamePlayer::factory()->for($first->user)->create();
        Event::fake([ChatMessageCreated::class]);
        $clientId = strtoupper((string) Str::uuid());

        foreach ([$first, $second, $otherGamePlayer] as $player) {
            $this->actingAs($player->user)
                ->postJson(route('games.chat.messages.store', $player->game), [
                    'body' => 'Привет', 'client_message_id' => $clientId,
                ])
                ->assertCreated()
                ->assertJsonPath('data.clientMessageId', strtolower($clientId));
        }
        $this->assertSame(3, ChatMessage::query()->count());
    }

    public function test_history_is_paginated_and_does_not_leak_other_games(): void
    {
        $player = GamePlayer::factory()->create();
        $messages = ChatMessage::factory()->for($player->game)->for($player->user)->count(55)->create();
        ChatMessage::factory()->create();
        $this->actingAs($player->user);

        $this->getJson(route('games.chat.messages.index', $player->game))
            ->assertOk()->assertJsonCount(50, 'data')
            ->assertJsonPath('data.0.id', $messages->last()->id)
            ->assertJsonPath('data.49.id', $messages[5]->id)
            ->assertJsonPath('hasMore', true);

        $this->getJson(route('games.chat.messages.index', ['game' => $player->game, 'before_id' => $messages[5]->id]))
            ->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.id', $messages[4]->id)
            ->assertJsonPath('hasMore', false);
    }

    public function test_reconnect_catches_up_in_ascending_batches_without_skipping_messages(): void
    {
        $player = GamePlayer::factory()->create();
        $messages = ChatMessage::factory()->for($player->game)->for($player->user)->count(55)->create();
        $this->actingAs($player->user);

        $this->getJson(route('games.chat.messages.index', ['game' => $player->game, 'after_id' => 0]))
            ->assertOk()->assertJsonCount(50, 'data')
            ->assertJsonPath('data.0.id', $messages[0]->id)
            ->assertJsonPath('data.49.id', $messages[49]->id)
            ->assertJsonPath('hasMore', true);

        $this->getJson(route('games.chat.messages.index', ['game' => $player->game, 'after_id' => $messages[49]->id]))
            ->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.id', $messages[50]->id)
            ->assertJsonPath('hasMore', false);
    }

    public function test_empty_chat_and_exhausted_cursors_return_an_empty_page(): void
    {
        $player = GamePlayer::factory()->create();
        $this->actingAs($player->user)
            ->getJson(route('games.chat.messages.index', $player->game))
            ->assertOk()->assertExactJson(['data' => [], 'hasMore' => false]);
    }

    public function test_empty_cursors_keep_newest_first_order_and_a_full_page_is_not_marked_as_incomplete(): void
    {
        $player = GamePlayer::factory()->create();
        $messages = ChatMessage::factory()->for($player->game)->for($player->user)->count(ChatMessageBuilder::PAGE_SIZE)->create();

        $this->actingAs($player->user)
            ->getJson(route('games.chat.messages.index', $player->game).'?before_id=&after_id=')
            ->assertOk()->assertJsonCount(ChatMessageBuilder::PAGE_SIZE, 'data')
            ->assertJsonPath('data.0.id', $messages->last()->id)
            ->assertJsonPath('data.49.id', $messages->first()->id)
            ->assertJsonPath('hasMore', false);
    }

    public function test_history_cursors_at_the_first_and_last_messages_return_empty_pages(): void
    {
        $player = GamePlayer::factory()->create();
        $message = ChatMessage::factory()->for($player->game)->for($player->user)->create();
        $this->actingAs($player->user);

        foreach (['before_id', 'after_id'] as $cursor) {
            $this->getJson(route('games.chat.messages.index', ['game' => $player->game, $cursor => $message->id]))
                ->assertOk()->assertExactJson(['data' => [], 'hasMore' => false]);
        }
    }

    public function test_guests_and_nonparticipants_cannot_read_send_or_subscribe(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'chat-test-key',
            'broadcasting.connections.reverb.secret' => 'chat-test-secret',
            'broadcasting.connections.reverb.app_id' => 'chat-test',
        ]);
        require base_path('routes/channels.php');
        $player = GamePlayer::factory()->create();
        $url = route('games.chat.messages.index', $player->game);
        $payload = ['body' => 'Привет', 'client_message_id' => (string) Str::uuid()];
        $this->getJson($url)->assertUnauthorized();
        $this->postJson($url, $payload)->assertUnauthorized();
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->getJson($url)->assertForbidden();
        $this->postJson($url, $payload)->assertForbidden();
        $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-game-chat.{$player->game_id}.{$player->id}",
            'socket_id' => '123.456',
        ])->assertForbidden();
        $this->assertSame(0, ChatMessage::query()->count());
    }

    public function test_removed_player_loses_http_access_and_is_excluded_from_queued_broadcasts(): void
    {
        $owner = GamePlayer::factory()->create(['seat' => 1]);
        $removed = GamePlayer::factory()->for($owner->game)->create(['seat' => 2]);
        $message = ChatMessage::factory()->for($owner->game)->for($owner->user)->create();
        $event = new ChatMessageCreated(ChatMessageData::fromMessage($message));

        $this->actingAs($owner->user)
            ->delete(route('games.players.destroy', ['game' => $owner->game, 'gamePlayer' => $removed]))
            ->assertNoContent();

        $this->actingAs($removed->user)->getJson(route('games.chat.messages.index', $owner->game))->assertForbidden();
        $this->postJson(route('games.chat.messages.store', $owner->game), [
            'body' => 'Уже нельзя', 'client_message_id' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->assertFalse(app(GameChatChannel::class)->join($removed->user, $owner->game_id, $removed->id));
        $this->assertSame(
            ["private-game-chat.{$owner->game_id}.{$owner->id}"],
            array_map(fn ($channel): string => $channel->name, $event->broadcastOn()),
        );

        $rejoined = GamePlayer::factory()->for($owner->game)->for($removed->user)->create(['seat' => 2]);
        $this->assertNotContains(
            "private-game-chat.{$owner->game_id}.{$removed->id}",
            array_map(fn ($channel): string => $channel->name, $event->broadcastOn()),
        );
        $this->assertTrue(app(GameChatChannel::class)->join($removed->user, $owner->game_id, $rejoined->id));
    }

    public function test_members_cannot_subscribe_as_another_player_or_to_another_game(): void
    {
        $first = GamePlayer::factory()->create(['seat' => 1]);
        $second = GamePlayer::factory()->for($first->game)->create(['seat' => 2]);
        $other = Game::factory()->create();
        $channel = app(GameChatChannel::class);

        $this->assertTrue($channel->join($first->user, $first->game_id, $first->id));
        $this->assertFalse($channel->join($first->user, $first->game_id, $second->id));
        $this->assertFalse($channel->join($first->user, $other->id, $first->id));
    }

    public function test_channel_authorization_endpoint_accepts_the_current_member(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'chat-test-key',
            'broadcasting.connections.reverb.secret' => 'chat-test-secret',
            'broadcasting.connections.reverb.app_id' => 'chat-test',
        ]);
        require base_path('routes/channels.php');
        $player = GamePlayer::factory()->create();

        $this->actingAs($player->user)->postJson('/broadcasting/auth', [
            'channel_name' => "private-game-chat.{$player->game_id}.{$player->id}",
            'socket_id' => '123.456',
        ])->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_action_checks_membership_again_even_if_the_request_was_previously_authorized(): void
    {
        $player = GamePlayer::factory()->create();
        $user = $player->user;
        $game = $player->game;
        $player->delete();

        $this->expectException(AuthorizationException::class);
        app(SendChatMessageAction::class)->execute($game, $user, (string) Str::uuid(), 'Привет');
    }

    #[DataProvider('invalidMessages')]
    public function test_invalid_messages_are_rejected(mixed $body, mixed $clientId, string $field): void
    {
        $player = GamePlayer::factory()->create();
        $this->actingAs($player->user)
            ->postJson(route('games.chat.messages.store', $player->game), [
                'body' => $body, 'client_message_id' => $clientId,
            ])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame(0, ChatMessage::query()->count());
    }

    /** @return array<string, array{mixed, mixed, string}> */
    public static function invalidMessages(): array
    {
        $uuid = '00000000-0000-4000-8000-000000000001';

        return [
            'empty' => ['', $uuid, 'body'],
            'whitespace' => [" \n ", $uuid, 'body'],
            'too long' => [str_repeat('Я', 2001), $uuid, 'body'],
            'array' => [['text'], $uuid, 'body'],
            'missing id' => ['Текст', null, 'client_message_id'],
            'invalid id' => ['Текст', 'invalid', 'client_message_id'],
        ];
    }

    public function test_history_rejects_invalid_and_conflicting_cursors(): void
    {
        $player = GamePlayer::factory()->create();
        $this->actingAs($player->user);
        $url = route('games.chat.messages.index', $player->game);

        $this->getJson($url.'?before_id=-1')->assertUnprocessable()->assertJsonValidationErrors('before_id');
        $this->getJson($url.'?after_id=abc')->assertUnprocessable()->assertJsonValidationErrors('after_id');
        $this->getJson($url.'?before_id=2&after_id=1')->assertUnprocessable();
    }

    public function test_finished_games_keep_their_chat_available(): void
    {
        $player = GamePlayer::factory()->for(Game::factory()->finished())->create();
        Event::fake([ChatMessageCreated::class]);

        $this->actingAs($player->user)->postJson(route('games.chat.messages.store', $player->game), [
            'body' => 'Спасибо за игру!', 'client_message_id' => (string) Str::uuid(),
        ])->assertCreated();
        $this->getJson(route('games.chat.messages.index', $player->game))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_message_at_the_length_limit_preserves_unicode_and_line_breaks(): void
    {
        $player = GamePlayer::factory()->create();
        Event::fake([ChatMessageCreated::class]);
        $body = "Привет!\n".str_repeat('Я', 1992);

        $this->actingAs($player->user)->postJson(route('games.chat.messages.store', $player->game), [
            'body' => $body, 'client_message_id' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('data.body', $body);
    }

    public function test_deleting_an_author_preserves_their_messages_and_shows_a_placeholder(): void
    {
        $reader = GamePlayer::factory()->create(['seat' => 1]);
        $author = GamePlayer::factory()->for($reader->game)->create(['seat' => 2]);
        $message = ChatMessage::factory()->for($reader->game)->for($author->user)->create();
        $author->user->delete();

        $this->actingAs($reader->user)->getJson(route('games.chat.messages.index', $reader->game))
            ->assertOk()->assertJsonPath('data.0.id', $message->id)
            ->assertJsonPath('data.0.authorId', null)
            ->assertJsonPath('data.0.authorName', 'Удалённый пользователь');
    }

    public function test_history_displays_the_authors_current_name(): void
    {
        $player = GamePlayer::factory()->create();
        $message = ChatMessage::factory()->for($player->game)->for($player->user)->create();
        $player->user->update(['name' => 'Новое имя']);

        $this->actingAs($player->user)->getJson(route('games.chat.messages.index', $player->game))
            ->assertOk()->assertJsonPath('data.0.id', $message->id)
            ->assertJsonPath('data.0.authorName', 'Новое имя');
    }

    public function test_deleting_a_game_also_deletes_its_chat(): void
    {
        $message = ChatMessage::factory()->create();
        $message->game->delete();

        $this->assertModelMissing($message);
    }

    public function test_undoing_game_history_preserves_chat_messages(): void
    {
        $owner = GamePlayer::factory()->ready()->create(['seat' => 1]);
        GamePlayer::factory()->ready()->for($owner->game)->create(['seat' => 2]);
        config(['auth.admin_user_id' => (string) $owner->user_id]);
        Event::fake([ChatMessageCreated::class]);
        $this->actingAs($owner->user);
        $chatUrl = route('games.chat.messages.store', $owner->game);
        $this->postJson($chatUrl, ['body' => 'До начала', 'client_message_id' => (string) Str::uuid()])->assertCreated();
        $this->post(route('games.start', $owner->game))->assertNoContent();
        $this->postJson($chatUrl, ['body' => 'После начала', 'client_message_id' => (string) Str::uuid()])->assertCreated();

        $this->delete(route('games.history.latest.destroy', $owner->game))->assertNoContent();

        $this->assertSame(0, $owner->game->actions()->count());
        $this->getJson(route('games.chat.messages.index', $owner->game))
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.body', 'После начала')
            ->assertJsonPath('data.1.body', 'До начала');
    }

    public function test_message_is_broadcast_only_after_commit_and_not_after_rollback(): void
    {
        $player = GamePlayer::factory()->create();
        Event::fake([ChatMessageCreated::class]);
        DB::beginTransaction();

        app(SendChatMessageAction::class)->execute($player->game, $player->user, (string) Str::uuid(), 'Откат');
        Event::assertNotDispatched(ChatMessageCreated::class);
        DB::rollBack();
        Event::assertNotDispatched(ChatMessageCreated::class);
        $this->assertSame(0, ChatMessage::query()->count());

        DB::beginTransaction();
        app(SendChatMessageAction::class)->execute($player->game, $player->user, (string) Str::uuid(), 'Фиксация');
        Event::assertNotDispatched(ChatMessageCreated::class);
        DB::commit();
        Event::assertDispatchedTimes(ChatMessageCreated::class, 1);
    }

    public function test_broadcast_uses_the_default_queue_and_only_serializes_public_message_fields(): void
    {
        $player = GamePlayer::factory()->create();
        Queue::fake();
        app(SendChatMessageAction::class)->execute($player->game, $player->user, (string) Str::uuid(), 'Привет');

        Queue::assertPushed(BroadcastEvent::class, function (BroadcastEvent $job, ?string $queue) use ($player): bool {
            $this->assertNull($queue);
            $this->assertSame('chat.message.created', $job->event->broadcastAs());
            $this->assertSame(3, $job->tries);
            $this->assertSame([1, 5, 10], $job->backoff);
            $this->assertSame($player->user_id, $job->event->broadcastWith()['message']['authorId']);
            $this->assertArrayNotHasKey('email', $job->event->broadcastWith()['message']);

            return true;
        });
    }

    public function test_sending_is_rate_limited(): void
    {
        $player = GamePlayer::factory()->create();
        Event::fake([ChatMessageCreated::class]);
        $this->actingAs($player->user);
        $payload = ['body' => 'Привет', 'client_message_id' => (string) Str::uuid()];
        $url = route('games.chat.messages.store', $player->game);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson($url, $payload)->assertSuccessful();
        }
        $this->postJson($url, $payload)->assertTooManyRequests();
        $this->assertSame(1, ChatMessage::query()->count());
    }
}
