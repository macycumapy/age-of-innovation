<?php

declare(strict_types=1);

namespace Tests\Unit\Skills;

use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Enums\Innovation;
use BackedEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class AgeOfInnovationPlayerSkillTest extends TestCase
{
    private function skillPath(): string
    {
        return dirname(__DIR__, 3).'/.agents/skills/age-of-innovation-player';
    }

    public function test_skill_metadata_is_valid_and_matches_its_directory(): void
    {
        $content = file_get_contents($this->skillPath().'/SKILL.md');
        $this->assertIsString($content);
        $this->assertSame(1, preg_match('/\A---\n(.*?)\n---\n/s', $content, $matches));
        $metadata = Yaml::parse($matches[1]);

        $this->assertIsArray($metadata);
        $this->assertSame(basename($this->skillPath()), $metadata['name']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $metadata['name']);
        $this->assertLessThanOrEqual(64, strlen($metadata['name']));
        $this->assertIsString($metadata['description']);
        $this->assertNotEmpty($metadata['description']);
        $this->assertLessThanOrEqual(1024, strlen($metadata['description']));
    }

    public function test_linked_references_and_rule_sources_exist(): void
    {
        $content = file_get_contents($this->skillPath().'/SKILL.md');
        $this->assertIsString($content);
        preg_match_all('/\]\((references\/[^)]+)\)/', $content, $links);
        $this->assertNotEmpty($links[1]);

        foreach ($links[1] as $link) {
            $path = $this->skillPath().'/'.$link;
            $this->assertFileExists($path);
            $reference = file_get_contents($path);
            $this->assertIsString($reference);
            preg_match_all('/`(app\/[^`]+\.php)`/', $reference, $sources);
            $this->assertNotEmpty($sources[1]);
            foreach ($sources[1] as $source) {
                $this->assertFileExists(dirname(__DIR__, 3).'/'.$source);
            }
        }
    }

    /** @param class-string<BackedEnum> $enum */
    #[DataProvider('componentGroups')]
    public function test_component_catalog_covers_current_engine_ids(string $heading, string $enum): void
    {
        $content = file_get_contents($this->skillPath().'/references/components.md');
        $this->assertIsString($content);
        $this->assertSame(1, preg_match('/^## '.preg_quote($heading, '/').'\n(.*?)(?=^## |\z)/ms', $content, $section));
        preg_match_all('/^\| ([a-z][a-z0-9_]*) \|/m', $section[1], $rows);

        $this->assertEqualsCanonicalizing(
            array_map(static fn (BackedEnum $case): string => $case->value, $enum::cases()),
            $rows[1],
        );
    }

    /** @return array<string, array{string, class-string<BackedEnum>}> */
    public static function componentGroups(): array
    {
        return [
            'factions' => ['Factions', Faction::class],
            'competencies' => ['Competencies', Competency::class],
            'innovations' => ['Innovations', Innovation::class],
            'palaces' => ['Palaces', PalaceAbility::class],
            'round bonuses' => ['Round bonuses and town tiles', RoundBonus::class],
        ];
    }
}
