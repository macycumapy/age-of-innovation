<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Exception\RuntimeException;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_user_and_outputs_only_the_id(): void
    {
        $exitCode = Artisan::call('user:create', ['email' => ' Player@Example.com ', '--no-interaction' => true]);
        $user = User::query()->sole();

        $this->assertSame(0, $exitCode);
        $this->assertSame((string) $user->id, trim(Artisan::output()));
        $this->assertSame('player@example.com', $user->email);
        $this->assertSame('player', $user->name);
        $this->assertNull($user->email_verified_at);
        $this->assertNotEmpty($user->password);
        $this->assertFalse(Hash::needsRehash($user->password));
    }

    public function test_it_rejects_invalid_email_without_creating_a_user(): void
    {
        $this->artisan('user:create', ['email' => 'invalid'])->assertFailed();
        $this->assertSame(0, User::query()->count());
    }

    public function test_it_rejects_duplicate_email_without_changing_the_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'player@example.com']);
        $before = $user->fresh()->getAttributes();

        $this->artisan('user:create', ['email' => 'PLAYER@example.com'])->assertFailed();
        $this->assertSame(1, User::query()->count());
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_it_requires_an_email_argument(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments');

        Artisan::call('user:create');
    }

    public function test_it_rejects_an_email_longer_than_the_database_limit(): void
    {
        $email = str_repeat('a', 64).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 60).'.com';

        $this->artisan('user:create', ['email' => $email])->assertFailed();
        $this->assertSame(0, User::query()->count());
    }
}
