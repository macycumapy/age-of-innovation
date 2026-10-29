<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Validators\ExecuteGameActionValidator;
use App\Mcp\Validators\GameQueryValidator;
use App\Mcp\Validators\JoinGameValidator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GameToolValidationTest extends TestCase
{
    public function test_validators_return_typed_data_from_validated_parameters(): void
    {
        $query = new GameQueryValidator();
        $this->assertNull($query->validate(new Request())->gameId);
        $this->assertSame(12, $query->validate(new Request(['game_id' => '12']))->gameId);
        $this->assertSame(12, (new JoinGameValidator())->validate(new Request(['game_id' => '12']))->gameId);

        $action = (new ExecuteGameActionValidator())->validate(new Request(['version' => '0', 'action_id' => 'finish_turn']));
        $this->assertNull($action->gameId);
        $this->assertSame(0, $action->version);

        $stage = (new ExecuteGameActionValidator())->validate(new Request([
            'game_id' => '12', 'version' => '3', 'action_id' => str_repeat('a', 64), 'user_id' => 999,
        ]));
        $this->assertSame(12, $stage->gameId);
        $this->assertSame(3, $stage->version);
        $this->assertSame(str_repeat('a', 64), $stage->actionId);
        $this->assertFalse(property_exists($stage, 'user_id'));
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidArguments')]
    public function test_validators_reject_invalid_parameters(string $validatorClass, array $arguments, string $field): void
    {
        try {
            (new $validatorClass())->validate(new Request($arguments));
            $this->fail('Expected parameter validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    /** @return array<string, array{class-string, array<string, mixed>, string}> */
    public static function invalidArguments(): array
    {
        return [
            'query zero game' => [GameQueryValidator::class, ['game_id' => 0], 'game_id'],
            'query null game' => [GameQueryValidator::class, ['game_id' => null], 'game_id'],
            'query noninteger game' => [GameQueryValidator::class, ['game_id' => 'invalid'], 'game_id'],
            'join missing game' => [JoinGameValidator::class, [], 'game_id'],
            'join negative game' => [JoinGameValidator::class, ['game_id' => -1], 'game_id'],
            'action missing version' => [ExecuteGameActionValidator::class, [], 'version'],
            'action negative version' => [ExecuteGameActionValidator::class, ['version' => -1], 'version'],
            'action fractional version' => [ExecuteGameActionValidator::class, ['version' => 1.5], 'version'],
            'action invalid game' => [ExecuteGameActionValidator::class, ['version' => 0, 'game_id' => []], 'game_id'],
            'stage missing action' => [ExecuteGameActionValidator::class, ['version' => 0], 'action_id'],
            'stage empty action' => [ExecuteGameActionValidator::class, ['version' => 0, 'action_id' => ''], 'action_id'],
            'stage oversized action' => [ExecuteGameActionValidator::class, ['version' => 0, 'action_id' => str_repeat('a', 65)], 'action_id'],
            'stage nonstring action' => [ExecuteGameActionValidator::class, ['version' => 0, 'action_id' => []], 'action_id'],
            'stage missing version' => [ExecuteGameActionValidator::class, ['action_id' => 'example'], 'version'],
        ];
    }
}
