<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Builders\SettingBuilder;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property mixed $value
 * @method static SettingBuilder query()
 */
#[Fillable(['key', 'value'])]
#[UseEloquentBuilder(SettingBuilder::class)]
final class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
