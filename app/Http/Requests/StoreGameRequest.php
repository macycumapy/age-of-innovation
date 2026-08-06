<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\MapVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'map_variant' => ['required', Rule::enum(MapVariant::class)],
        ];
    }

    public function mapVariant(): MapVariant
    {
        return MapVariant::from((string) $this->validated('map_variant'));
    }
}
