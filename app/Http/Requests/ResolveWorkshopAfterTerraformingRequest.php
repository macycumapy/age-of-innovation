<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;

final class ResolveWorkshopAfterTerraformingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->phase === GamePhase::Actions
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction?->type === PendingInteractionType::BuildWorkshopAfterTerraforming;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'build' => ['required', 'boolean'],
            'hex_id' => ['nullable', 'string'],
        ];
    }
}
