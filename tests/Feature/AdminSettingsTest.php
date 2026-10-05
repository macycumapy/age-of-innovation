<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\Settings\Actions\UpdateSettingsAction;
use App\Domain\Settings\Data\SettingsData;
use App\Domain\Settings\Enums\SettingKey;
use App\Domain\Settings\Services\SettingsService;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingSeeder::class);
    }

    public function test_defaults_are_stored_as_json_and_displayed_in_admin(): void
    {
        $admin = $this->admin();

        $this->assertFalse(Setting::query()->where('key', SettingKey::BotsEnabled->value)->firstOrFail()->value);
        $this->assertSame(['fast', 'balanced'], Setting::query()->where('key', SettingKey::BotDifficulties->value)->firstOrFail()->value);

        $this->actingAs($admin)->get(route('admin.settings.edit'))->assertInertia(fn (Assert $page) => $page
            ->component('admin/Settings')
            ->where('settings.bots.enabled', false)
            ->where('settings.bots.available_difficulties', ['fast', 'balanced'])
            ->has('difficultyOptions', 3));
    }

    public function test_admin_can_save_and_replace_settings_without_duplicate_keys(): void
    {
        $this->actingAs($this->admin());

        $this->put(route('admin.settings.update'), [
            'bots_enabled' => true,
            'bot_difficulties' => ['strong'],
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.settings.edit'));

        $this->assertTrue(Setting::query()->where('key', SettingKey::BotsEnabled->value)->firstOrFail()->value);
        $this->assertSame(['strong'], Setting::query()->where('key', SettingKey::BotDifficulties->value)->firstOrFail()->value);

        $this->put(route('admin.settings.update'), [
            'bots_enabled' => false,
            'bot_difficulties' => [],
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.settings.edit'));

        $this->assertFalse(Setting::query()->where('key', SettingKey::BotsEnabled->value)->firstOrFail()->value);
        $this->assertSame([], Setting::query()->where('key', SettingKey::BotDifficulties->value)->firstOrFail()->value);
        $this->assertSame(count(SettingKey::cases()), Setting::query()->count());
    }

    #[DataProvider('settingsPresence')]
    public function test_settings_are_written_with_one_database_query(bool $alreadyExist): void
    {
        if (! $alreadyExist) {
            Setting::query()->delete();
        }

        $this->expectsDatabaseQueryCount(1);

        $this->app->make(UpdateSettingsAction::class)->execute(
            new SettingsData(true, [GameBotDifficulty::Strong]),
        );
    }

    /** @return array<string, array{bool}> */
    public static function settingsPresence(): array
    {
        return ['existing settings' => [true], 'missing settings' => [false]];
    }

    public function test_missing_settings_are_created_with_json_values_and_timestamps(): void
    {
        Setting::query()->delete();

        $this->actingAs($this->admin())->put(route('admin.settings.update'), [
            'bots_enabled' => true,
            'bot_difficulties' => ['fast', 'strong'],
        ])->assertSessionHasNoErrors();

        $enabled = Setting::query()->where('key', SettingKey::BotsEnabled->value)->sole();
        $difficulties = Setting::query()->where('key', SettingKey::BotDifficulties->value)->sole();

        $this->assertTrue($enabled->value);
        $this->assertSame(['fast', 'strong'], $difficulties->value);
        $this->assertNotNull($enabled->created_at);
        $this->assertNotNull($enabled->updated_at);
        $this->assertNotNull($difficulties->created_at);
        $this->assertNotNull($difficulties->updated_at);
    }

    public function test_guest_and_other_user_cannot_change_settings(): void
    {
        $this->admin();
        $values = ['bots_enabled' => true, 'bot_difficulties' => ['strong']];

        $this->put(route('admin.settings.update'), $values)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->put(route('admin.settings.update'), $values)->assertForbidden();

        $this->assertFalse(Setting::query()->where('key', SettingKey::BotsEnabled->value)->firstOrFail()->value);
        $this->assertSame(['fast', 'balanced'], Setting::query()->where('key', SettingKey::BotDifficulties->value)->firstOrFail()->value);
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('invalidSettings')]
    public function test_invalid_settings_are_rejected_without_partial_updates(array $values, string $error): void
    {
        $this->actingAs($this->admin())->put(route('admin.settings.update'), $values)
            ->assertSessionHasErrors($error);

        $this->assertFalse(Setting::query()->where('key', SettingKey::BotsEnabled->value)->firstOrFail()->value);
        $this->assertSame(['fast', 'balanced'], Setting::query()->where('key', SettingKey::BotDifficulties->value)->firstOrFail()->value);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidSettings(): array
    {
        return [
            'missing enabled' => [['bot_difficulties' => ['fast']], 'bots_enabled'],
            'invalid enabled' => [['bots_enabled' => 'invalid', 'bot_difficulties' => ['fast']], 'bots_enabled'],
            'missing difficulties' => [['bots_enabled' => true], 'bot_difficulties'],
            'empty enabled difficulties' => [['bots_enabled' => true, 'bot_difficulties' => []], 'bot_difficulties'],
            'non-array' => [['bots_enabled' => true, 'bot_difficulties' => 'fast'], 'bot_difficulties'],
            'unknown difficulty' => [['bots_enabled' => true, 'bot_difficulties' => ['impossible']], 'bot_difficulties.0'],
            'duplicate difficulties' => [['bots_enabled' => true, 'bot_difficulties' => ['fast', 'fast']], 'bot_difficulties.0'],
            'object instead of list' => [['bots_enabled' => true, 'bot_difficulties' => ['key' => 'fast']], 'bot_difficulties'],
        ];
    }

    public function test_seeder_preserves_saved_settings(): void
    {
        Setting::query()->where('key', SettingKey::BotsEnabled->value)->firstOrFail()->update(['value' => true]);
        $this->seed(SettingSeeder::class);

        $this->assertTrue(Setting::query()->where('key', SettingKey::BotsEnabled->value)->firstOrFail()->value);
        $this->assertSame(2, Setting::query()->count());
    }

    public function test_missing_settings_have_safe_defaults(): void
    {
        Setting::query()->delete();

        $this->assertSame(['bots' => ['enabled' => false, 'available_difficulties' => ['fast', 'balanced']]], $this->app->make(SettingsService::class)->get()->toArray());
    }

    public function test_settings_list_is_cached_between_builder_instances(): void
    {
        Setting::factory()->create(['key' => 'other.setting', 'value' => ['limit' => 5]]);
        $this->expectsDatabaseQueryCount(1);

        $first = Setting::query()->allCached();
        $second = Setting::query()->allCached();

        $this->assertSame($first, $second);
        $this->assertFalse($first[SettingKey::BotsEnabled->value]);
        $this->assertSame(['limit' => 5], $first['other.setting']);
    }

    public function test_saving_settings_invalidates_cached_values(): void
    {
        $this->actingAs($this->admin())->get(route('admin.settings.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('settings.bots.enabled', false));

        $this->put(route('admin.settings.update'), [
            'bots_enabled' => true,
            'bot_difficulties' => ['strong'],
        ])->assertSessionHasNoErrors();

        $this->get(route('admin.settings.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('settings.bots.enabled', true)
            ->where('settings.bots.available_difficulties', ['strong']));
    }

    public function test_seeding_invalidates_cached_empty_settings(): void
    {
        Setting::query()->delete();
        $this->assertSame([], Setting::query()->allCached());

        $this->seed(SettingSeeder::class);

        $this->assertSame([
            SettingKey::BotsEnabled->value => false,
            SettingKey::BotDifficulties->value => ['fast', 'balanced'],
        ], Setting::query()->allCached());
    }

    public function test_cached_settings_list_is_not_limited_by_builder_filters(): void
    {
        $settings = Setting::query()->where('key', SettingKey::BotsEnabled->value)->allCached();

        $this->assertCount(2, $settings);
        $this->assertSame(['fast', 'balanced'], $settings[SettingKey::BotDifficulties->value]);
        $this->assertSame($settings, Setting::query()->allCached());
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        config(['auth.admin_user_id' => (string) $admin->id]);

        return $admin;
    }
}
