<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ResolvePalaceWaterTownRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction?->type === PendingInteractionType::OfferPalaceWaterTown;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'accept' => ['required', 'boolean'],
            'water_hex_id' => [Rule::requiredIf($this->boolean('accept')), 'nullable', 'string'],
        ];
    }
}
