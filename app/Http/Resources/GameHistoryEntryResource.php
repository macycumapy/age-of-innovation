<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GameAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GameAction */
final class GameHistoryEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'type' => $this->type->value,
            'payload' => $this->payload,
            'stateVersionBefore' => $this->state_version_before,
            'stateVersionAfter' => $this->state_version_after,
            'player' => $this->player === null ? null : [
                'id' => $this->player->id,
                'name' => $this->player->name,
            ],
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
