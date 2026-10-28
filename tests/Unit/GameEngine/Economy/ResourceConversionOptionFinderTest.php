<?php

declare(strict_types=1);

namespace Tests\Unit\GameEngine\Economy;

use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Actions\ApplyResourceExchangeAction;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Economy\Data\ResourceExchangeBatchOptionData;
use App\Domain\GameEngine\Economy\Data\ResourceExchangeOptionData;
use App\Domain\GameEngine\Economy\Data\SacrificePowerOptionData;
use App\Domain\GameEngine\Economy\Enums\ResourceExchange;
use App\Domain\GameEngine\Economy\Services\ResourceConversionOptionFinder;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use Tests\TestCase;

class ResourceConversionOptionFinderTest extends TestCase
{
    public function test_all_affordable_quantities_are_offered_and_can_be_applied(): void
    {
        $player = $this->player();
        $options = app(ResourceConversionOptionFinder::class)->execute($player, includeAllAmounts: true);
        $groups = [];

        foreach ($options as $option) {
            if ($option instanceof SacrificePowerOptionData) {
                $groups['sacrifice'][] = $option->amount;

                continue;
            }

            $groups[$option->exchange->value.':'.$option->discipline?->value][] = $option->amount;
            $copy = unserialize(serialize($player));
            app(ApplyResourceExchangeAction::class)->execute($copy, (new ResourceExchangeBatchOptionData([$option]))->counts());
            $this->assertGreaterThanOrEqual(0, $copy->resources->power->bowlThree);
            $this->assertLessThanOrEqual($copy->scholarPoolSize, $copy->resources->scholars);
        }

        $this->assertSame([
            'power_to_scholar:' => [1],
            'power_to_tool:' => [1, 2, 3],
            'power_to_coin:' => range(1, 11),
            'power_to_book:banking' => [1, 2],
            'power_to_book:law' => [1, 2],
            'power_to_book:engineering' => [1, 2],
            'power_to_book:medicine' => [1, 2],
            'scholar_to_tool:' => [1, 2],
            'tool_to_coin:' => [1, 2, 3, 4],
            'book_to_coin:law' => [1, 2, 3],
            'sacrifice' => [1, 2, 3],
        ], $groups);
        $this->assertSame(11, $player->resources->power->bowlThree);
        $this->assertSame(3, $player->resources->books->law);
    }

    public function test_search_defaults_to_one_unit_per_conversion(): void
    {
        $options = app(ResourceConversionOptionFinder::class)->execute($this->player());
        $this->assertNotEmpty($options);
        foreach ($options as $option) {
            $this->assertSame(1, $option->amount);
        }
    }

    public function test_exhausted_resources_and_scholar_supply_are_not_offered(): void
    {
        $player = $this->player();
        $player->resources = new PlayerResourcesData(power: new PowerBowlsStateData(bowlTwo: 1, bowlThree: 4));
        $player->scholarPoolSize = 0;
        $options = app(ResourceConversionOptionFinder::class)->execute($player, includeAllAmounts: true);
        $this->assertCount(5, $options);
        foreach ($options as $option) {
            $this->assertInstanceOf(ResourceExchangeOptionData::class, $option);
            $this->assertContains($option->exchange, [ResourceExchange::PowerToTool, ResourceExchange::PowerToCoin]);
        }
        $player->resources->power->bowlThree = 0;
        $this->assertSame([], app(ResourceConversionOptionFinder::class)->execute($player, includeAllAmounts: true));
    }

    private function player(): GamePlayerStateData
    {
        $player = new GamePlayerStateData(
            playerId: 1,
            userId: 1,
            color: PlayerColor::Green,
            faction: Faction::Illusionists,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(tools: 4, scholars: 2, power: new PowerBowlsStateData(bowlTwo: 7, bowlThree: 11)),
            scholarPoolSize: 3,
        );
        $player->resources->books->{KnowledgeDiscipline::Law->value} = 3;

        return $player;
    }
}
