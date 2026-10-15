<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\User\Actions\IssueTokenAction;
use App\Domain\User\Actions\RevokeTokenAction;
use App\Domain\User\Data\IssueTokenData;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('game:mcp-token {user : ID пользователя} {--revoke : Отозвать текущий токен}')]
#[Description('Выдаёт или отзывает Sanctum-токен игрового HTTP MCP')]
class GameMcpTokenCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(IssueTokenAction $issueToken, RevokeTokenAction $revokeToken): int
    {
        $userId = filter_var($this->argument('user'), FILTER_VALIDATE_INT);
        $user = $userId !== false && $userId > 0 ? User::query()->find($userId) : null;
        if (! $user instanceof User) {
            $this->components->error('Пользователь не найден.');

            return self::FAILURE;
        }
        if ($this->option('revoke')) {
            $revokeToken->execute($user, 'game-mcp');
        } else {
            $this->line($issueToken->execute($user, new IssueTokenData('game-mcp', ['game:mcp'])));
        }

        return self::SUCCESS;
    }
}
