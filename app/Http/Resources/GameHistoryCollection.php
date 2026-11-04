<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GameAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class GameHistoryCollection extends ResourceCollection
{
    /** @var class-string<GameHistoryEntryResource> */
    public $collects = GameHistoryEntryResource::class;

    /** @return array{data: array<int, mixed>, hasMore: bool} */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->take(GameAction::HISTORY_PAGE_SIZE)->values()->all(),
            'hasMore' => $this->collection->count() > GameAction::HISTORY_PAGE_SIZE,
        ];
    }
}
