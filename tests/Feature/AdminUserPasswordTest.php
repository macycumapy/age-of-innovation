<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUserPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_change_user_password_without_the_old_password(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $adminPassword = $admin->password;
        $oldToken = $user->remember_token;
        $name = $user->name;

        $this->actingAs($admin)->from(route('admin.users.index', ['page' => 2]))
            ->put(route('admin.users.password.update', $user), [
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
                'name' => 'Ignored',
            ])->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index', ['page' => 2]));

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-123', $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertNotSame($oldToken, $user->remember_token);
        $this->assertSame($name, $user->name);
        $this->assertSame($adminPassword, $admin->refresh()->password);
        $this->assertNotSame('new-password-123', $user->password);
    }

    public function test_guest_and_other_users_cannot_change_a_password(): void
    {
        $this->admin();
        $user = User::factory()->create();
        $password = $user->password;
        $values = ['password' => 'new-password-123', 'password_confirmation' => 'new-password-123'];

        $this->put(route('admin.users.password.update', $user), $values)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->put(route('admin.users.password.update', $user), $values)->assertForbidden();
        $this->actingAs($user)->put(route('admin.users.password.update', $user), $values)->assertForbidden();

        $this->assertSame($password, $user->refresh()->password);
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('invalidPasswords')]
    public function test_invalid_password_does_not_change_user(array $values): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $password = $user->password;
        $token = $user->remember_token;

        $this->actingAs($admin)->put(route('admin.users.password.update', $user), $values)
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');

        $this->assertSame($password, $user->refresh()->password);
        $this->assertSame($token, $user->remember_token);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidPasswords(): array
    {
        return [
            'missing' => [[]],
            'short' => [['password' => 'short', 'password_confirmation' => 'short']],
            'mismatched' => [['password' => 'new-password-123', 'password_confirmation' => 'different-password']],
            'unconfirmed' => [['password' => 'new-password-123']],
        ];
    }

    public function test_admin_can_change_their_own_password(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.users.password.update', $admin), [
            'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('new-password-123', $admin->refresh()->password));
    }

    public function test_missing_user_returns_not_found(): void
    {
        $this->actingAs($this->admin())->put(route('admin.users.password.update', 999999), [
            'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertNotFound();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        config(['auth.admin_user_id' => (string) $admin->id]);

        return $admin;
    }
}
