<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GameHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'before_sequence' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function beforeSequence(): ?int
    {
        $sequence = $this->validated('before_sequence');

        return is_numeric($sequence) ? (int) $sequence : null;
    }
}
