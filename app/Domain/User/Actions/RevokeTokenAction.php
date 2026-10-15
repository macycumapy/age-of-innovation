<?php

declare(strict_types=1);

namespace App\Domain\User\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RevokeTokenAction
{
    public function execute(User $user, string $name): void
    {
        DB::transaction(function () use ($user, $name): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $lockedUser->tokens()->where('name', $name)->delete();
        });
    }
}
