<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ChooseTownBooksRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game
            && $game->active_player_id === $this->user()?->id
            && $game->state->pendingInteraction?->type === PendingInteractionType::ChooseTownBooks;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['book_counts' => ['required', 'array']];

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $rules['book_counts.'.$discipline->value] = ['required', 'integer', 'min:0', 'max:2'];
        }

        return $rules;
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_sum(array_map('intval', (array) $this->input('book_counts', []))) !== 2) {
                $validator->errors()->add('book_counts', 'Распределите обе книги.');
            }
        }];
    }

    /** @return list<KnowledgeDiscipline> */
    public function disciplines(): array
    {
        $disciplines = [];

        foreach (KnowledgeDiscipline::cases() as $discipline) {
            for ($count = (int) $this->validated("book_counts.{$discipline->value}"); $count > 0; $count--) {
                $disciplines[] = $discipline;
            }
        }

        return $disciplines;
    }
}
