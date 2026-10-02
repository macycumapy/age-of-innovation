<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChoosePalaceRequest extends FormRequest
{
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
            && $game->state->pendingInteraction?->type === PendingInteractionType::ChoosePalace;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $game = $this->route('game');
        $interaction = $game instanceof Game ? $game->state->pendingInteraction : null;
        $optionIds = $interaction->optionIds ?? [];

        return [
            'palace_id' => ['required', Rule::enum(PalaceAbility::class), Rule::in($optionIds)],
        ];
    }

    public function palace(): PalaceAbility
    {
        return PalaceAbility::from((string) $this->validated('palace_id'));
    }
}
