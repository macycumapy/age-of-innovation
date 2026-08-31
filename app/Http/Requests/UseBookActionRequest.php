<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\BookAction;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UseBookActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->phase === GamePhase::Actions
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction === null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::enum(BookAction::class)],
            'book_counts' => ['required', 'array'],
            'book_counts.banking' => ['required', 'integer', 'min:0'],
            'book_counts.law' => ['required', 'integer', 'min:0'],
            'book_counts.engineering' => ['required', 'integer', 'min:0'],
            'book_counts.medicine' => ['required', 'integer', 'min:0'],
            'discipline' => ['nullable', Rule::enum(KnowledgeDiscipline::class)],
            'hex_id' => ['nullable', 'string'],
        ];
    }

    public function action(): BookAction
    {
        return BookAction::from((string) $this->validated('action'));
    }

    /** @return array<string, int> */
    public function bookCounts(): array
    {
        return array_map('intval', (array) $this->validated('book_counts'));
    }

    public function discipline(): ?KnowledgeDiscipline
    {
        $discipline = $this->validated('discipline');

        return is_string($discipline) ? KnowledgeDiscipline::from($discipline) : null;
    }

    public function hexId(): ?string
    {
        $hexId = $this->validated('hex_id');

        return is_string($hexId) ? $hexId : null;
    }
}
