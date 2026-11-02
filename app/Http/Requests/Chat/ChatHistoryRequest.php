<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;

final class ChatHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $game = $this->route('game');

        return $game instanceof Game && ($this->user()?->can('access-game-chat', $game) ?? false);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'before_id' => ['nullable', 'integer', 'min:1', 'prohibits:after_id'],
            'after_id' => ['nullable', 'integer', 'min:0', 'prohibits:before_id'],
        ];
    }

    public function beforeId(): ?int
    {
        $id = $this->validated('before_id');

        return is_numeric($id) ? (int) $id : null;
    }

    public function afterId(): ?int
    {
        $id = $this->validated('after_id');

        return is_numeric($id) ? (int) $id : null;
    }
}
