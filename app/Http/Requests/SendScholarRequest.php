<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SendScholarRequest extends FormRequest
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
            && $game->phase->isActionPhase()
            && $game->isActivePlayer($player)
            && $game->state->pendingInteraction === null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'discipline' => ['required', Rule::enum(KnowledgeDiscipline::class)],
            'place' => ['required', 'boolean'],
        ];
    }

    public function discipline(): KnowledgeDiscipline
    {
        return KnowledgeDiscipline::from((string) $this->validated('discipline'));
    }
}
