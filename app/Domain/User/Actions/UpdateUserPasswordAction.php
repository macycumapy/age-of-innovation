<?php

declare(strict_types=1);

namespace App\Domain\User\Actions;

use App\Models\User;
use Illuminate\Support\Str;

final class UpdateUserPasswordAction
{
    public function execute(User $user, string $password): void
    {
        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();
    }
}
