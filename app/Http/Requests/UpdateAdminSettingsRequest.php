<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\Settings\Data\SettingsData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAdminSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('access-admin') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'bots_enabled' => ['required', 'boolean'],
            'bot_difficulties' => ['present', 'array', 'list', 'max:3', Rule::when($this->boolean('bots_enabled'), ['min:1'])],
            'bot_difficulties.*' => ['required', 'string', 'distinct', Rule::enum(GameBotDifficulty::class)],
        ];
    }

    public function settings(): SettingsData
    {
        return new SettingsData(
            botsEnabled: $this->boolean('bots_enabled'),
            botDifficulties: array_values(array_map(
                fn (string $difficulty): GameBotDifficulty => GameBotDifficulty::from($difficulty),
                $this->validated('bot_difficulties'),
            )),
        );
    }
}
