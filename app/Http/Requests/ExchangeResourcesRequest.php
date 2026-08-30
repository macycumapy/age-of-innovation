<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\GamePhase;
use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;

final class ExchangeResourcesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game && $game->phase === GamePhase::Actions
            && $game->active_player_id === $this->user()?->id;
    }

    public function rules(): array
    {
        return [
            'exchanges' => ['required', 'array'],
            'exchanges.power_to_scholar' => ['required', 'integer', 'min:0', 'max:99'],
            'exchanges.power_to_tool' => ['required', 'integer', 'min:0', 'max:99'],
            'exchanges.power_to_coin' => ['required', 'integer', 'min:0', 'max:99'],
            'exchanges.scholar_to_tool' => ['required', 'integer', 'min:0', 'max:99'],
            'exchanges.tool_to_coin' => ['required', 'integer', 'min:0', 'max:99'],
            'exchanges.power_to_book' => ['required', 'array'],
            'exchanges.power_to_book.banking' => ['required', 'integer', 'min:0', 'max:99'],
            'exchanges.power_to_book.law' => ['required', 'integer', 'min:0', 'max:99'],
            'exchanges.power_to_book.engineering' => ['required', 'integer', 'min:0', 'max:99'],
            'exchanges.power_to_book.medicine' => ['required', 'integer', 'min:0', 'max:99'],
            'exchanges.book_to_coin' => ['required', 'array'],
            'exchanges.book_to_coin.banking' => ['required', 'integer', 'min:0', 'max:99'],
            'exchanges.book_to_coin.law' => ['required', 'integer', 'min:0', 'max:99'],
            'exchanges.book_to_coin.engineering' => ['required', 'integer', 'min:0', 'max:99'],
            'exchanges.book_to_coin.medicine' => ['required', 'integer', 'min:0', 'max:99'],
        ];
    }

    /** @return array<string, int|array<string, int>> */
    public function exchanges(): array
    {
        return $this->validated('exchanges');
    }
}
