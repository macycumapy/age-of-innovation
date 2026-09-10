<script setup lang="ts">
import PlanningBundleController from '@/actions/App/Http/Controllers/PlanningBundleController';
import Form from '@/components/game/GameActionForm.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { factionNames, roundBonusNames, terrainNames } from '@/lib/gameDisplay';
import type { Competency, Faction, GamePlayerSummary, GameResource, RoundBonus, TerrainType } from '@/types';

const props = defineProps<{
    game: GameResource;
    canChoose: boolean;
}>();

const terrainBundleClasses: Record<TerrainType, string> = {
    desert: 'border-yellow-500/60 bg-yellow-400/25 dark:bg-yellow-400/30',
    plains: 'border-amber-800/60 bg-amber-800/20 dark:bg-amber-600/30',
    swamp: 'border-zinc-700/60 bg-zinc-900/40 dark:bg-zinc-900/40',
    lake: 'border-blue-500/60 bg-blue-500/20 dark:bg-blue-500/30',
    forest: 'border-green-600/60 bg-green-600/20 dark:bg-green-700/30',
    mountain: 'border-gray-500/60 bg-gray-500/20 dark:bg-gray-400/30',
    wasteland: 'border-red-500/60 bg-red-500/20 dark:bg-red-500/30',
    water: 'border-cyan-500/60 bg-cyan-500/20 dark:bg-cyan-500/30',
};

const terrainTileImages = import.meta.glob('../../../images/terrain_tiles/*.webp', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

const factionImages = import.meta.glob('../../../images/factions/*.jpg', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

const roundBonusImages = import.meta.glob('../../../images/round_bonus_cards/*_top.png', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

const competencyImages = import.meta.glob('../../../images/competencies/*.png', {
    eager: true,
    import: 'default',
    query: '?url',
}) as Record<string, string>;

function buttonLabel(processing: boolean): string {
    if (processing) {
        return 'Выбор…';
    }

    return props.canChoose ? 'Выбрать комплект' : 'Сейчас выбирает другой игрок';
}

function terrainTileImage(terrain: TerrainType): string {
    return terrainTileImages[`../../../images/terrain_tiles/${terrain}.webp`];
}

function factionImage(faction: Faction): string {
    return factionImages[`../../../images/factions/${faction}.jpg`];
}

function roundBonusImage(roundBonus: RoundBonus): string {
    return roundBonusImages[`../../../images/round_bonus_cards/${roundBonus}_top.png`];
}

function competencyImage(competency: Competency): string {
    return competencyImages[`../../../images/competencies/${competency}.png`];
}

function selectedPlayer(homeland: TerrainType): GamePlayerSummary | undefined {
    const selection = props.game.data.planningSelections.find((item) => item.bundle.homeland === homeland);

    return props.game.data.players.find((player) => player.id === selection?.playerId);
}

function selectedCompetency(homeland: TerrainType): Competency | undefined {
    const player = selectedPlayer(homeland);
    const playerState = props.game.data.playerBoardStates.find((state) => state.playerId === player?.id);

    return playerState?.competencyIds[0];
}
</script>

<template>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        <Form
            v-for="bundle in game.data.planningBundles"
            :id="`planning-bundle-${bundle.homeland}`"
            :key="bundle.homeland"
            v-bind="PlanningBundleController.store.form(game.data.id)"
            #default="{ errors, processing }"
            :class="['flex flex-col gap-4 rounded-xl border p-4 shadow-sm', terrainBundleClasses[bundle.homeland]]"
        >
            <input type="hidden" name="homeland" :value="bundle.homeland" />

            <TooltipProvider :delay-duration="150">
                <div class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <img
                                :src="terrainTileImage(bundle.homeland)"
                                :alt="`Родная местность: ${terrainNames[bundle.homeland]}`"
                                tabindex="0"
                                class="h-48 w-auto cursor-help rounded-md object-contain shadow-sm"
                            />
                        </TooltipTrigger>
                        <TooltipContent class="max-w-xs">
                            <p class="font-semibold">{{ terrainNames[bundle.homeland] }}</p>
                            <p>{{ game.data.planningBundleDescriptions.homelands[bundle.homeland] }}</p>
                        </TooltipContent>
                    </Tooltip>

                    <Tooltip>
                        <TooltipTrigger as-child>
                            <div
                                tabindex="0"
                                class="relative aspect-[592/338] w-full max-w-[21rem] min-w-0 cursor-help justify-self-center"
                            >
                                <img
                                    :src="factionImage(bundle.faction)"
                                    :alt="`Сообщество: ${factionNames[bundle.faction]}`"
                                    class="size-full rounded-md object-cover shadow-sm"
                                />
                                <img
                                    v-if="selectedCompetency(bundle.homeland)"
                                    :src="competencyImage(selectedCompetency(bundle.homeland)!)"
                                    :alt="`Выбранная компетенция ${selectedCompetency(bundle.homeland)}`"
                                    class="absolute top-0 right-0 size-16 rounded-md object-contain p-1 shadow-md"
                                />
                            </div>
                        </TooltipTrigger>
                        <TooltipContent class="max-w-xs">
                            <p class="font-semibold">{{ factionNames[bundle.faction] }}</p>
                            <p>{{ game.data.planningBundleDescriptions.factions[bundle.faction] }}</p>
                        </TooltipContent>
                    </Tooltip>

                    <Tooltip>
                        <TooltipTrigger as-child>
                            <img
                                :src="roundBonusImage(bundle.roundBonus)"
                                :alt="`Бонус раунда: ${roundBonusNames[bundle.roundBonus]}`"
                                tabindex="0"
                                class="h-48 w-auto cursor-help object-contain drop-shadow-sm"
                            />
                        </TooltipTrigger>
                        <TooltipContent class="max-w-xs">
                            <p class="font-semibold">{{ roundBonusNames[bundle.roundBonus] }}</p>
                            <p>{{ game.data.planningBundleDescriptions.roundBonuses[bundle.roundBonus] }}</p>
                        </TooltipContent>
                    </Tooltip>
                </div>
            </TooltipProvider>

            <InputError :message="errors.homeland ?? errors.game" />
            <div
                v-if="selectedPlayer(bundle.homeland)"
                class="mt-auto flex min-h-10 items-center justify-center gap-3 rounded-md bg-background/75 px-4 py-2 text-center text-sm font-medium shadow-xs"
            >
                {{ selectedPlayer(bundle.homeland)?.user.name }}
            </div>
            <Dialog v-else>
                <DialogTrigger as-child>
                    <Button type="button" class="mt-auto w-full" :disabled="processing || !canChoose">
                        {{ buttonLabel(processing) }}
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Подтвердите выбор комплекта</DialogTitle>
                        <DialogDescription
                            >После подтверждения этот комплект будет закреплён за вами.</DialogDescription
                        >
                    </DialogHeader>

                    <div class="grid gap-2 rounded-lg bg-muted p-4 text-sm">
                        <p><span class="text-muted-foreground">Земля:</span> {{ terrainNames[bundle.homeland] }}</p>
                        <p><span class="text-muted-foreground">Раса:</span> {{ factionNames[bundle.faction] }}</p>
                        <p>
                            <span class="text-muted-foreground">Бонус раунда:</span>
                            {{ roundBonusNames[bundle.roundBonus] }}
                        </p>
                    </div>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="outline">Отмена</Button>
                        </DialogClose>
                        <Button type="submit" :form="`planning-bundle-${bundle.homeland}`" :disabled="processing">
                            {{ processing ? 'Выбор…' : 'Подтвердить' }}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Form>
    </div>
</template>
