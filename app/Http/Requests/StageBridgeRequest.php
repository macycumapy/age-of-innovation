<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;

final class StageBridgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction?->type === PendingInteractionType::PlaceBridge;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'from_hex_id' => ['required', 'string'],
            'to_hex_id' => ['required', 'string', 'different:from_hex_id'],
        ];
    }

    public function fromHexId(): string
    {
        return (string) $this->validated('from_hex_id');
    }

    public function toHexId(): string
    {
        return (string) $this->validated('to_hex_id');
    }
}
