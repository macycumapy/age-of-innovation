<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Check, RotateCcw } from '@lucide/vue';
import { computed } from 'vue';
import CurrentTurnRestartController from '@/actions/App/Http/Controllers/CurrentTurnRestartController';
import BridgeConfirmationController from '@/actions/App/Http/Controllers/BridgeConfirmationController';
import BridgeController from '@/actions/App/Http/Controllers/BridgeController';
import PalaceGuildConfirmationController from '@/actions/App/Http/Controllers/PalaceGuildConfirmationController';
import PalaceGuildController from '@/actions/App/Http/Controllers/PalaceGuildController';
import PowerOfferController from '@/actions/App/Http/Controllers/PowerOfferController';
import StartingBuildingController from '@/actions/App/Http/Controllers/StartingBuildingController';
import StartingBuildingTurnController from '@/actions/App/Http/Controllers/StartingBuildingTurnController';
import StartingSpadeController from '@/actions/App/Http/Controllers/StartingSpadeController';
import StartingSpadeTurnController from '@/actions/App/Http/Controllers/StartingSpadeTurnController';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import type { GamePlayerSummary, GameResource } from '@/types';

const props = defineProps<{
    game: GameResource;
    activePlayer?: GamePlayerSummary;
    currentPlayer?: GamePlayerSummary;
    currentUserId: number;
    isStartingBuildingStage: boolean;
    isOmarStartingTowerTurn: boolean;
    canSpendStartingSpade: boolean;
    pendingStartingSpadeHexId: string | null;
    pendingPalaceGuildHexId: string | null;
    selectedBridgeFromHexId: string | null;
}>();

const emit = defineEmits<{
    finishTurn: [];
    pass: [];
    resetBridgeSelection: [];
}>();

const isCurrentUsersTurn = computed(() => props.activePlayer?.user.id === props.currentUserId);
const canResolvePowerOffer = computed(
    () => props.game.data.pendingInteraction?.type === 'power_offer'
        && props.game.data.pendingInteraction.playerId === props.currentPlayer?.id
        && isCurrentUsersTurn.value,
);
const powerOfferAmount = computed(() =>
    props.game.data.pendingInteraction?.type === 'power_offer'
        ? props.game.data.pendingInteraction.context.powerAmount
        : 0,
);
const powerOfferVictoryPointCost = computed(() => Math.max(0, powerOfferAmount.value - 1));
const remainingSpades = computed(() => {
    const interaction = props.game.data.pendingInteraction;

    if (interaction?.type !== 'spend_spades') {
        return 0;
    }

    const availableSpades = interaction.context.remainingSpades ?? interaction.context.spadeCount;

    const stagedSpades = props.pendingStartingSpadeHexId === null
        ? 0
        : (interaction.context.spentSpades ?? 1);

    return Math.max(0, availableSpades - stagedSpades);
});

function confirmRestartCurrentTurn(event: SubmitEvent): void {
    if (!window.confirm('Отменить все действия текущего хода и начать его заново?')) {
        event.preventDefault();
    }
}
</script>

<template>
    <div
        v-if="game.data.status === 'active'"
        class="sticky top-0 z-30 -mx-4 flex items-center justify-center gap-4 border-y border-border/80 bg-background/95 px-4 py-3 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-background/80"
    >
        <span
            v-if="game.data.phase === 'income'"
            class="shrink-0 rounded-full border border-primary/40 bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary"
        >
            Фаза дохода · Раунд {{ game.data.currentRound }}
        </span>

        <span
            v-if="game.data.phase === 'science_bonus'"
            class="shrink-0 rounded-full border border-primary/40 bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary"
        >
            Фаза 3 · Научный бонус
        </span>

        <span
            v-if="isCurrentUsersTurn"
            class="shrink-0 rounded-full bg-primary px-2.5 py-1 text-xs font-semibold text-primary-foreground"
        >
            Ваш ход
        </span>

        <p
            v-if="isCurrentUsersTurn && (isStartingBuildingStage || canSpendStartingSpade || canResolvePowerOffer || game.data.pendingInteraction?.type === 'place_palace_guild' || game.data.pendingInteraction?.type === 'place_bridge' || game.data.pendingInteraction?.type === 'choose_science_bonus_books')"
            class="truncate text-sm font-medium"
            role="status"
            aria-live="polite"
        >
            <template v-if="game.data.pendingInteraction?.type === 'power_offer'">
                Получить {{ powerOfferAmount }} Силы за {{ powerOfferVictoryPointCost }} ПО?
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'choose_science_bonus_books'">
                Выберите книги, полученные за научную цель раунда.
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'choose_competency'">
                Выберите стартовую компетенцию.
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'spend_spades'">
                {{ pendingStartingSpadeHexId
                    ? 'Земля преобразована — отмените действие или подтвердите.'
                    : 'Выберите соседнюю ячейку для преобразования.' }}
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'place_palace_guild'">
                {{ pendingPalaceGuildHexId
                    ? 'Рынок размещён — отмените действие или подтвердите.'
                    : 'Разместите бесплатный рынок на свободной родной местности.' }}
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'place_bridge'">
                {{ game.data.pendingInteraction.context.selectedFromHexId
                    ? 'Мост размещён — отмените действие или подтвердите.'
                    : selectedBridgeFromHexId
                        ? 'Выберите противоположный берег.'
                        : 'Выберите ячейку со своим зданием для начала моста.' }}
            </template>
            <template v-else-if="game.data.pendingStartingBuildingHexId">
                {{ isOmarStartingTowerTurn
                    ? 'Стартовая вышка установлена — отмените действие или завершите ход.'
                    : 'Дом установлен — отмените действие или завершите ход.' }}
            </template>
            <template v-else-if="currentPlayer?.faction === 'monks'">
                Установите стартовый университет на свободной ячейке родной местности.
            </template>
            <template v-else-if="isOmarStartingTowerTurn">
                Установите стартовую вышку на свободной ячейке родной местности.
            </template>
            <template v-else>
                Установите стартовый дом на свободной ячейке родной местности.
            </template>
        </p>

        <span
            v-if="canSpendStartingSpade"
            class="shrink-0 rounded-full border border-border bg-muted px-2.5 py-1 text-xs font-medium"
        >
            Лопат осталось:
            {{ remainingSpades }}
        </span>

        <div v-if="canResolvePowerOffer" class="flex shrink-0 items-center gap-2">
            <Form v-bind="PowerOfferController.form(game.data.id)" #default="{ processing }">
                <input type="hidden" name="accept" value="0" />
                <Button type="submit" variant="outline" :disabled="processing">Отказаться</Button>
            </Form>
            <Form v-bind="PowerOfferController.form(game.data.id)" #default="{ processing }">
                <input type="hidden" name="accept" value="1" />
                <Button type="submit" :disabled="processing">Принять Силу</Button>
            </Form>
        </div>

        <TooltipProvider
            v-else-if="isCurrentUsersTurn && game.data.pendingInteraction?.type === 'place_bridge' && game.data.pendingInteraction.context.selectedFromHexId"
            :delay-duration="150"
        >
            <div class="flex shrink-0 items-center gap-2">
                <Form
                    v-if="game.data.canRestartCurrentTurn"
                    v-bind="CurrentTurnRestartController.form(game.data.id)"
                    #default="{ processing }"
                    @submit="confirmRestartCurrentTurn"
                >
                    <Button type="submit" variant="outline" :disabled="processing">
                        Перезапустить ход
                    </Button>
                </Form>
                <Form v-bind="BridgeController.destroy.form(game.data.id)" #default="{ processing }">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button type="submit" variant="outline" size="icon" :disabled="processing" aria-label="Отменить размещение моста">
                                <RotateCcw class="size-4" :class="processing ? 'animate-spin' : ''" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Отменить размещение моста</TooltipContent>
                    </Tooltip>
                </Form>
                <Form v-bind="BridgeConfirmationController.form(game.data.id)" #default="{ processing }">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button type="submit" size="icon" :disabled="processing" aria-label="Подтвердить строительство моста">
                                <Check class="size-4" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Подтвердить строительство моста</TooltipContent>
                    </Tooltip>
                </Form>
            </div>
        </TooltipProvider>

        <div
            v-else-if="isCurrentUsersTurn && game.data.pendingInteraction?.type === 'place_bridge' && selectedBridgeFromHexId"
            class="flex shrink-0 items-center gap-2"
        >
            <Form
                v-if="game.data.canRestartCurrentTurn"
                v-bind="CurrentTurnRestartController.form(game.data.id)"
                #default="{ processing }"
                @submit="confirmRestartCurrentTurn"
            >
                <Button type="submit" variant="outline" :disabled="processing">Перезапустить ход</Button>
            </Form>
            <Button type="button" variant="outline" size="sm" @click="emit('resetBridgeSelection')">
                Выбрать другой берег
            </Button>
        </div>

        <Form
            v-else-if="isCurrentUsersTurn && game.data.pendingInteraction?.type === 'place_bridge'"
            v-bind="CurrentTurnRestartController.form(game.data.id)"
            #default="{ processing }"
            @submit="confirmRestartCurrentTurn"
        >
            <Button type="submit" variant="outline" :disabled="processing">Перезапустить ход</Button>
        </Form>

        <TooltipProvider
            v-else-if="isCurrentUsersTurn && game.data.pendingInteraction?.type === 'place_palace_guild' && pendingPalaceGuildHexId"
            :delay-duration="150"
        >
            <div class="flex shrink-0 items-center gap-2">
                <Form v-bind="PalaceGuildController.destroy.form(game.data.id)" #default="{ processing }">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button type="submit" variant="outline" size="icon" :disabled="processing" aria-label="Отменить размещение рынка">
                                <RotateCcw class="size-4" :class="processing ? 'animate-spin' : ''" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Отменить размещение рынка</TooltipContent>
                    </Tooltip>
                </Form>
                <Form v-bind="PalaceGuildConfirmationController.form(game.data.id)" #default="{ processing }">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button type="submit" size="icon" :disabled="processing" aria-label="Подтвердить размещение рынка">
                                <Check class="size-4" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Подтвердить размещение рынка</TooltipContent>
                    </Tooltip>
                </Form>
            </div>
        </TooltipProvider>

        <TooltipProvider
            v-else-if="isStartingBuildingStage && isCurrentUsersTurn && game.data.pendingStartingBuildingHexId"
            :delay-duration="150"
        >
            <div class="flex shrink-0 items-center gap-2">
                <Form v-bind="StartingBuildingController.destroy.form(game.data.id)" #default="{ processing }">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                type="submit"
                                variant="outline"
                                size="icon"
                                :disabled="processing"
                                :aria-label="isOmarStartingTowerTurn ? 'Отменить установку стартовой вышки' : 'Отменить установку дома'"
                            >
                                <RotateCcw class="size-4" :class="processing ? 'animate-spin' : ''" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>
                            {{ isOmarStartingTowerTurn ? 'Отменить установку стартовой вышки' : 'Отменить установку дома' }}
                        </TooltipContent>
                    </Tooltip>
                </Form>
                <Form v-bind="StartingBuildingTurnController.form(game.data.id)" #default="{ processing }">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button type="submit" size="icon" :disabled="processing" aria-label="Подтвердить и закончить ход">
                                <Check class="size-4" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Подтвердить и закончить ход</TooltipContent>
                    </Tooltip>
                </Form>
            </div>
        </TooltipProvider>

        <TooltipProvider
            v-else-if="canSpendStartingSpade && pendingStartingSpadeHexId"
            :delay-duration="150"
        >
            <div class="flex shrink-0 items-center gap-2">
                <Form v-bind="StartingSpadeController.destroy.form(game.data.id)" #default="{ processing }">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button type="submit" variant="outline" size="icon" :disabled="processing" aria-label="Отменить преобразование">
                                <RotateCcw class="size-4" :class="processing ? 'animate-spin' : ''" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Отменить преобразование</TooltipContent>
                    </Tooltip>
                </Form>
                <Form v-bind="StartingSpadeTurnController.form(game.data.id)" #default="{ processing }">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button type="submit" size="icon" :disabled="processing" aria-label="Подтвердить преобразование">
                                <Check class="size-4" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Подтвердить преобразование</TooltipContent>
                    </Tooltip>
                </Form>
            </div>
        </TooltipProvider>

        <Form
            v-else-if="canSpendStartingSpade && game.data.canRestartCurrentTurn"
            v-bind="CurrentTurnRestartController.form(game.data.id)"
            #default="{ processing }"
            @submit="confirmRestartCurrentTurn"
        >
            <Button type="submit" variant="outline" :disabled="processing">
                <RotateCcw class="size-4" :class="processing ? 'animate-spin' : ''" />
                Перезапустить ход
            </Button>
        </Form>

        <div
            v-else-if="game.data.phase === 'actions' && isCurrentUsersTurn && game.data.pendingInteraction === null"
            class="flex shrink-0 items-center gap-2"
        >
            <Form
                v-if="game.data.canRestartCurrentTurn"
                v-bind="CurrentTurnRestartController.form(game.data.id)"
                #default="{ processing }"
                @submit="confirmRestartCurrentTurn"
            >
                <TooltipProvider :delay-duration="150">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button type="submit" variant="outline" :disabled="processing">
                                <RotateCcw class="size-4" :class="processing ? 'animate-spin' : ''" />
                                Перезапустить ход
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Отменить все действия текущего хода</TooltipContent>
                    </Tooltip>
                </TooltipProvider>
            </Form>
            <Button v-if="game.data.canFinishCurrentTurn" type="button" @click="emit('finishTurn')">
                Завершить ход
            </Button>
            <Button v-if="game.data.canPass" type="button" variant="secondary" @click="emit('pass')">
                Пас
            </Button>
        </div>

        <div
            v-else-if="!isCurrentUsersTurn"
            class="flex min-w-0 items-center gap-3"
            role="status"
            aria-live="polite"
        >
            <span class="size-2.5 shrink-0 rounded-full bg-primary shadow-sm" aria-hidden="true" />
            <p class="truncate text-sm">
                <span class="mr-2 text-muted-foreground">
                    {{ isStartingBuildingStage
                        ? game.data.pendingInteraction?.type === 'spend_spades'
                            ? 'Стартовую лопату использует:'
                            : isOmarStartingTowerTurn
                                ? 'Стартовую вышку устанавливает:'
                                : 'Стартовый дом устанавливает:'
                        : 'Сейчас ходит:' }}
                </span>
                <span class="font-semibold">{{ activePlayer?.user.name ?? 'ход игрока определяется' }}</span>
            </p>
        </div>
    </div>
</template>
