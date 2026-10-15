<?php

declare(strict_types=1);

namespace App\Domain\User\Actions;

use App\Domain\User\Data\IssueTokenData;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class IssueTokenAction
{
    public function execute(User $user, IssueTokenData $data): string
    {
        return DB::transaction(function () use ($user, $data): string {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $lockedUser->tokens()->where('name', $data->name)->delete();

            return $lockedUser->createToken($data->name, $data->abilities)->plainTextToken;
        });
    }
}
