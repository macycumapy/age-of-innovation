<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\TerrainType;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChoosePlanningBundleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->active_player_id === $this->user()?->id;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'homeland' => ['required', Rule::enum(TerrainType::class)->except(TerrainType::Water)],
        ];
    }

    public function homeland(): TerrainType
    {
        return TerrainType::from($this->string('homeland')->toString());
    }
}
