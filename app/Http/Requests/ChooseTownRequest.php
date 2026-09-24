<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\TownTile;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
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
        $user = $this->user();

        if (! $game instanceof Game || ! $user instanceof User) {
            return false;
        }

        $player = $game->players()->whereBelongsTo($user)->first();

        return $player instanceof GamePlayer
            && $game->isActivePlayer($player)
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
