<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Towns\Actions;

use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Towns\Data\PalaceWaterTownResultData;
use Illuminate\Validation\ValidationException;

final class ApplyPalaceWaterTownDecisionAction
{
    public function execute(
        GameStateData $state,
        int $playerId,
        bool $accept,
        ?string $waterHexId,
    ): PalaceWaterTownResultData {
        $interaction = $state->pendingInteraction;
        $townsByWaterHexId = $interaction?->context['townsByWaterHexId'] ?? [];

        if ($interaction?->type !== PendingInteractionType::OfferPalaceWaterTown
            || $interaction->playerId !== $playerId
            || ! is_array($townsByWaterHexId)
            || ($accept && (! is_string($waterHexId) || ! isset($townsByWaterHexId[$waterHexId])))) {
            throw ValidationException::withMessages(['town' => 'Сейчас нельзя подтвердить создание города через воду.']);
        }

        $player = collect($state->players)->firstWhere('playerId', $playerId);

        if (! $player instanceof GamePlayerStateData) {
            throw ValidationException::withMessages(['town' => 'Не найдено состояние игрока.']);
        }

        $builtHexId = (string) ($interaction->context['builtHexId'] ?? '');
        $queuedBuiltHexIds = $this->stringList($interaction->context['queuedBuiltHexIds'] ?? []);
        $townHexIds = $accept ? $this->stringList($townsByWaterHexId[$waterHexId]) : [];

        if ($accept) {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::ChooseTown,
                $playerId,
                array_values(array_unique($state->availableTownTileIds)),
                [
                    'townHexIds' => [...$townHexIds, $waterHexId],
                    'builtHexId' => $builtHexId,
                    'markerHexId' => $waterHexId,
                    'queuedBuiltHexIds' => $queuedBuiltHexIds,
                ],
            );
        } else {
            $state->pendingInteraction = null;
        }

        return new PalaceWaterTownResultData(
            $player->playerId,
            $builtHexId,
            $townHexIds,
            $queuedBuiltHexIds,
        );
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
