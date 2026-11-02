<?php

declare(strict_types=1);

namespace App\Http\Controllers\Chat;

use App\Domain\Chat\Actions\SendChatMessageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\ChatHistoryRequest;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Http\Resources\ChatMessageResource;
use App\Models\Builders\ChatMessageBuilder;
use App\Models\ChatMessage;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class ChatMessageController extends Controller
{
    public function index(ChatHistoryRequest $request, Game $game): JsonResponse
    {
        $messages = ChatMessage::query()
            ->historyPageFor($game, $request->beforeId(), $request->afterId())
            ->get();

        return response()->json([
            'data' => ChatMessageResource::collection($messages->take(ChatMessageBuilder::PAGE_SIZE))->resolve($request),
            'hasMore' => $messages->count() > ChatMessageBuilder::PAGE_SIZE,
        ]);
    }

    public function store(StoreChatMessageRequest $request, Game $game, SendChatMessageAction $sendMessage): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $sendMessage->execute(
            $game,
            $user,
            strtolower((string) $request->validated('client_message_id')),
            (string) $request->validated('body'),
        );

        return (new ChatMessageResource($result->message))->response()->setStatusCode($result->created ? 201 : 200);
    }
}
