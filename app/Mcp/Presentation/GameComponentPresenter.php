<?php

declare(strict_types=1);

namespace App\Mcp\Presentation;

use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Towns\Enums\TownTile;
use App\Mcp\Data\GameComponentData;
use App\Mcp\Enums\GameComponentType;
use DomainException;

final class GameComponentPresenter
{
    /** @return array{type: string, id: string, description: string} */
    public function present(GameComponentData $data): array
    {
        $component = match ($data->type) {
            GameComponentType::Faction => Faction::tryFrom($data->id),
            GameComponentType::Competency => Competency::tryFrom($data->id),
            GameComponentType::Innovation => Innovation::tryFrom($data->id),
            GameComponentType::Palace => PalaceAbility::tryFrom($data->id),
            GameComponentType::RoundBonus => RoundBonus::tryFrom($data->id),
            GameComponentType::TownTile => TownTile::tryFrom($data->id),
        };

        if ($component === null) {
            throw new DomainException('Unknown component ID for the requested type. Use IDs from the game context.');
        }

        return ['type' => $data->type->value, 'id' => $component->value, 'description' => $component->description()];
    }
}
