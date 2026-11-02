<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Models\Game;
use Illuminate\Foundation\Http\FormRequest;

final class StoreChatMessageRequest extends FormRequest
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
            'client_message_id' => ['required', 'uuid'],
            'body' => ['required', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'body.required' => 'Введите сообщение.',
            'body.max' => 'Сообщение не должно превышать 2000 символов.',
        ];
    }
}
