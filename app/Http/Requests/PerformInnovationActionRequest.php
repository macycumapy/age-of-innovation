<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Game\Enums\Innovation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PerformInnovationActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['innovation' => ['required', Rule::enum(Innovation::class)]];
    }

    public function innovation(): Innovation
    {
        return Innovation::from((string) $this->validated('innovation'));
    }

}
