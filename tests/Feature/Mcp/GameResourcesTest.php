<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Resources\GameComponentsResource;
use App\Mcp\Resources\GamePlayGuideResource;
use App\Mcp\Resources\GameRulesResource;
use App\Mcp\Servers\GameServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Server\Resource;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GameResourcesTest extends TestCase
{
    use RefreshDatabase;

    /** @param class-string<Resource> $resource */
    #[DataProvider('resources')]
    public function test_local_resources_share_the_skill_content(string $resource, string $uri, string $file): void
    {
        GameServer::resource($resource)->assertOk()->assertSee($this->content($file));
        $this->assertSame($uri, app($resource)->uri());
        $this->assertSame('text/markdown', app($resource)->mimeType());
    }

    public function test_http_lists_all_three_resources_and_instructs_clients_to_read_them(): void
    {
        $token = User::factory()->create()->createToken('game-mcp', ['game:mcp'])->plainTextToken;
        $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list',
        ])->assertOk()->assertJsonCount(3, 'result.resources')
            ->assertJsonPath('result.resources.0.uri', 'game://rules')
            ->assertJsonPath('result.resources.1.uri', 'game://components')
            ->assertJsonPath('result.resources.2.uri', 'game://play-guide')
            ->assertJsonPath('result.resources.0.mimeType', 'text/markdown');

        $response = $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => [],
        ])->assertOk();
        foreach (self::resources() as [, $uri]) {
            $this->assertStringContainsString($uri, $response->json('result.instructions'));
        }
    }

    /** @param class-string<Resource> $resource */
    #[DataProvider('resources')]
    public function test_http_reads_the_same_source_files(string $resource, string $uri, string $file): void
    {
        $token = User::factory()->create()->createToken('game-mcp', ['game:mcp'])->plainTextToken;
        $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => ['uri' => $uri],
        ])->assertOk()
            ->assertJsonPath('result.contents.0.uri', $uri)
            ->assertJsonPath('result.contents.0.mimeType', 'text/markdown')
            ->assertJsonPath('result.contents.0.text', $this->content($file));
    }

    public function test_http_resources_require_authentication_and_game_ability(): void
    {
        $messages = [
            ['method' => 'resources/list'],
            ['method' => 'resources/read', 'params' => ['uri' => 'game://rules']],
        ];
        foreach ($messages as $message) {
            $this->postJson('/mcp/game', ['jsonrpc' => '2.0', 'id' => 1, ...$message])->assertUnauthorized();
        }
        $token = User::factory()->create()->createToken('other', ['profile:read'])->plainTextToken;
        foreach ($messages as $message) {
            $this->withToken($token)->postJson('/mcp/game', ['jsonrpc' => '2.0', 'id' => 1, ...$message])->assertForbidden();
        }
    }

    public function test_unknown_resource_does_not_allow_arbitrary_file_reads(): void
    {
        $token = User::factory()->create()->createToken('game-mcp', ['game:mcp'])->plainTextToken;
        $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read',
            'params' => ['uri' => 'game://../../.env'],
        ])->assertOk()->assertJsonPath('error.code', -32002);
    }

    /** @return array<string, array{class-string<Resource>, string, string}> */
    public static function resources(): array
    {
        return [
            'rules' => [GameRulesResource::class, 'game://rules', 'references/rules-and-strategy.md'],
            'components' => [GameComponentsResource::class, 'game://components', 'references/components.md'],
            'guide' => [GamePlayGuideResource::class, 'game://play-guide', 'SKILL.md'],
        ];
    }

    private function content(string $file): string
    {
        return File::get(base_path('.agents/skills/age-of-innovation-player/'.$file));
    }
}
