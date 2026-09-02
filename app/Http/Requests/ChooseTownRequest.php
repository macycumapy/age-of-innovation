<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\TownTile;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChooseTownRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction?->type === PendingInteractionType::ChooseTown;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['town_tile' => ['required', Rule::enum(TownTile::class)]];
    }

    public function townTile(): TownTile
    {
        return TownTile::from((string) $this->validated('town_tile'));
    }
}
