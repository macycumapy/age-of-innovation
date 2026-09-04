<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\Innovation;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MakeInnovationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->phase === GamePhase::Actions
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction === null
            && ! $game->state->round->hasTakenMainAction;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'innovation' => ['required', Rule::enum(Innovation::class)],
            'book_counts' => ['required', 'array'],
            'book_counts.banking' => ['required', 'integer', 'min:0'],
            'book_counts.law' => ['required', 'integer', 'min:0'],
            'book_counts.engineering' => ['required', 'integer', 'min:0'],
            'book_counts.medicine' => ['required', 'integer', 'min:0'],
        ];
    }

    public function innovation(): Innovation
    {
        return Innovation::from((string) $this->validated('innovation'));
    }

    /** @return array<string, int> */
    public function bookCounts(): array
    {
        return array_map('intval', (array) $this->validated('book_counts'));
    }
}
