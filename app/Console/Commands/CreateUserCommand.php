<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Fortify\Actions\CreateNewUser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Signature('user:create {email : Email нового пользователя}')]
#[Description('Создаёт пользователя по email и выводит его ID')]
class CreateUserCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CreateNewUser $createNewUser): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $password = Str::password(64);

        try {
            $user = $createNewUser->create([
                'name' => Str::before($email, '@'),
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->validator->errors()->all() as $message) {
                $this->output->getErrorStyle()->error($message);
            }

            return self::FAILURE;
        }

        $this->line((string) $user->id);

        return self::SUCCESS;
    }
}
