<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
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
import TownChoiceUndoController from '@/actions/App/Http/Controllers/TownChoiceUndoController';
import Form from '@/components/game/GameActionForm.vue';
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
    isPalaceBuildingSelectionActive: boolean;
    palaceBuildingSelectionSource: 'workshop' | 'school' | null;
}>();

const emit = defineEmits<{
    finishTurn: [];
    pass: [];
    resetBridgeSelection: [];
    cancelPalaceBuildingSelection: [];
}>();

const isCurrentUsersTurn = computed(() => props.activePlayer?.user.id === props.currentUserId);
const isChoosingStartingBundle = computed(
    () =>
        props.game.data.phase === 'setup' &&
        props.activePlayer !== undefined &&
        props.activePlayer.faction === null &&
        props.game.data.pendingInteraction?.type !== 'choose_starting_resources',
);
const otherPlayerStatusMessage = computed(() => {
    const playerName = props.activePlayer?.user.name;

    if (playerName === undefined) {
        return 'Ход игрока определяется.';
    }

    if (props.game.data.pendingInteraction?.type === 'choose_starting_resources') {
        return props.game.data.phase === 'income'
            ? `${playerName} распределяет получаемый доход.`
            : `${playerName} распределяет стартовые ресурсы.`;
    }

    if (props.isStartingBuildingStage) {
        if (props.game.data.pendingInteraction?.type === 'spend_spades') {
            return `${playerName} использует стартовую лопату.`;
        }

        if (props.isOmarStartingTowerTurn) {
            return `${playerName} устанавливает стартовую вышку.`;
        }

        return `${playerName} устанавливает стартовый дом.`;
    }

    return `${playerName} ходит.`;
});
const undoStartingBuildingRequest = useHttp({});
const finishStartingBuildingRequest = useHttp({});
const canResolvePowerOffer = computed(
    () =>
        props.game.data.pendingInteraction?.type === 'power_offer' &&
        props.game.data.pendingInteraction.playerId === props.currentPlayer?.id &&
        isCurrentUsersTurn.value,
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

    const stagedSpades = props.pendingStartingSpadeHexId === null ? 0 : (interaction.context.spentSpades ?? 1);

    return Math.max(0, availableSpades - stagedSpades);
});
function confirmRestartCurrentTurn(event: SubmitEvent): void {
    if (!window.confirm('Отменить все действия текущего хода и начать его заново?')) {
        event.preventDefault();
    }
}

function undoStartingBuilding(): void {
    void undoStartingBuildingRequest.delete(StartingBuildingController.destroy.url(props.game.data.id));
}

function finishStartingBuildingTurn(): void {
    void finishStartingBuildingRequest.post(StartingBuildingTurnController.url(props.game.data.id));
}

function scrollToPageTop(event: MouseEvent): void {
    const target = event.target;

    if (target instanceof Element && target.closest('button, a, input, select, textarea')) {
        return;
    }

    window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>

<template>
    <div
        v-if="game.data.status === 'active'"
        class="sticky top-0 z-40 -mx-4 flex cursor-pointer items-center justify-center gap-4 border-y border-border/80 bg-background/95 px-4 py-3 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-background/80"
        @click="scrollToPageTop"
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
            v-if="
                !isCurrentUsersTurn ||
                isChoosingStartingBundle ||
                (isCurrentUsersTurn &&
                    (isStartingBuildingStage ||
                        canSpendStartingSpade ||
                        canResolvePowerOffer ||
                        isPalaceBuildingSelectionActive ||
                        game.data.pendingInteraction?.type === 'choose_starting_resources' ||
                        game.data.pendingInteraction?.type === 'place_palace_guild' ||
                        game.data.pendingInteraction?.type === 'place_neutral_building' ||
                        game.data.pendingInteraction?.type === 'place_bridge' ||
                        game.data.pendingInteraction?.type === 'build_workshop_after_terraforming' ||
                        game.data.pendingInteraction?.type === 'choose_round_bonus' ||
                        game.data.pendingInteraction?.type === 'choose_town' ||
                        game.data.pendingInteraction?.type === 'choose_palace' ||
                        game.data.pendingInteraction?.type === 'choose_science_bonus_books' ||
                        game.data.pendingInteraction?.type === 'choose_innovation_books' ||
                        game.data.pendingInteraction?.type === 'choose_shipping_books' ||
                        game.data.pendingInteraction?.type === 'choose_terraforming_books' ||
                        game.data.pendingInteraction?.type === 'choose_palace_books'))
            "
            class="truncate text-sm font-medium"
            role="status"
            aria-live="polite"
        >
            <template v-if="isChoosingStartingBundle">
                {{
                    isCurrentUsersTurn
                        ? 'Выберите стартовый комплект.'
                        : `${activePlayer?.user.name ?? 'Игрок'} выбирает стартовый комплект.`
                }}
            </template>
            <template v-else-if="!isCurrentUsersTurn">
                {{ otherPlayerStatusMessage }}
            </template>
            <template v-else-if="isPalaceBuildingSelectionActive">
                {{
                    palaceBuildingSelectionSource === 'school'
                        ? 'Выберите школу для замены рынком или отмените действие.'
                        : 'Выберите дом для бесплатного улучшения до рынка или отмените действие.'
                }}
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'power_offer'">
                Получить {{ powerOfferAmount }} Силы за {{ powerOfferVictoryPointCost }} ПО?
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'choose_starting_resources'">
                {{ game.data.phase === 'income' ? 'Распределите получаемый доход' : 'Распределите стартовые ресурсы' }}
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'choose_round_bonus'">
                Выберите жетон бонуса
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'choose_town'">
                Выберите жетон города
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'choose_palace'">
                Выберите жетон Дворца
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'choose_science_bonus_books'">
                Выберите книги, полученные за научную цель раунда.
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'choose_innovation_books'">
                Выберите книги, полученные за инновацию.
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'choose_shipping_books'">
                Выберите книги, полученные за продвижение по навигации.
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'choose_terraforming_books'">
                Выберите книги, полученные за продвижение по терраформингу.
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'choose_palace_books'">
                Выберите книги, полученные за строительство Крепости.
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'choose_competency'">
                Выберите стартовую компетенцию.
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'spend_spades'">
                {{
                    pendingStartingSpadeHexId
                        ? 'Земля преобразована — отмените действие или подтвердите.'
                        : 'Выберите соседнюю ячейку для преобразования.'
                }}
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'place_palace_guild'">
                {{
                    pendingPalaceGuildHexId
                        ? 'Рынок размещён — отмените действие или подтвердите.'
                        : 'Разместите бесплатный рынок на свободной родной местности.'
                }}
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'place_neutral_building'">
                Выберите подсвеченную ячейку для нейтрального здания.
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'place_bridge'">
                {{
                    game.data.pendingInteraction.context.selectedFromHexId
                        ? 'Мост размещён — отмените действие или подтвердите.'
                        : selectedBridgeFromHexId
                          ? 'Выберите противоположный берег.'
                          : 'Выберите ячейку со своим зданием для начала моста.'
                }}
            </template>
            <template v-else-if="game.data.pendingInteraction?.type === 'build_workshop_after_terraforming'">
                Выберите перекопанную клетку для строительства дома или завершите ход.
            </template>
            <template v-else-if="game.data.pendingStartingBuildingHexId">
                {{
                    isOmarStartingTowerTurn
                        ? 'Стартовая вышка установлена — отмените действие или завершите ход.'
                        : 'Дом установлен — отмените действие или завершите ход.'
                }}
            </template>
            <template v-else-if="currentPlayer?.faction === 'monks'">
                Установите стартовый университет на свободной ячейке родной местности.
            </template>
            <template v-else-if="isOmarStartingTowerTurn">
                Установите стартовую вышку на свободной ячейке родной местности.
            </template>
            <template v-else> Установите стартовый дом на свободной ячейке родной местности. </template>
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
            v-else-if="
                isCurrentUsersTurn &&
                game.data.pendingInteraction?.type === 'place_bridge' &&
                game.data.pendingInteraction.context.selectedFromHexId
            "
            :delay-duration="150"
        >
            <div class="flex shrink-0 items-center gap-2">
                <Form
                    v-if="game.data.canRestartCurrentTurn"
                    v-bind="CurrentTurnRestartController.form(game.data.id)"
                    #default="{ processing }"
                    @submit="confirmRestartCurrentTurn"
                >
                    <Button type="submit" variant="outline" :disabled="processing"> Перезапустить ход </Button>
                </Form>
                <Form v-bind="BridgeController.destroy.form(game.data.id)" #default="{ processing }">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                type="submit"
                                variant="outline"
                                size="icon"
                                :disabled="processing"
                                aria-label="Отменить размещение моста"
                            >
                                <RotateCcw class="size-4" :class="processing ? 'animate-spin' : ''" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Отменить размещение моста</TooltipContent>
                    </Tooltip>
                </Form>
                <Form v-bind="BridgeConfirmationController.form(game.data.id)" #default="{ processing }">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                type="submit"
                                size="icon"
                                :disabled="processing"
                                aria-label="Подтвердить строительство моста"
                            >
                                <Check class="size-4" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Подтвердить строительство моста</TooltipContent>
                    </Tooltip>
                </Form>
            </div>
        </TooltipProvider>

        <div
            v-else-if="
                isCurrentUsersTurn && game.data.pendingInteraction?.type === 'place_bridge' && selectedBridgeFromHexId
            "
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
            v-else-if="
                isCurrentUsersTurn &&
                game.data.pendingInteraction?.type === 'place_palace_guild' &&
                pendingPalaceGuildHexId
            "
            :delay-duration="150"
        >
            <div class="flex shrink-0 items-center gap-2">
                <Form v-bind="PalaceGuildController.destroy.form(game.data.id)" #default="{ processing }">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                type="submit"
                                variant="outline"
                                size="icon"
                                :disabled="processing"
                                aria-label="Отменить размещение рынка"
                            >
                                <RotateCcw class="size-4" :class="processing ? 'animate-spin' : ''" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Отменить размещение рынка</TooltipContent>
                    </Tooltip>
                </Form>
                <Form v-bind="PalaceGuildConfirmationController.form(game.data.id)" #default="{ processing }">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                type="submit"
                                size="icon"
                                :disabled="processing"
                                aria-label="Подтвердить размещение рынка"
                            >
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
                <div>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                :disabled="
                                    undoStartingBuildingRequest.processing || finishStartingBuildingRequest.processing
                                "
                                :aria-label="
                                    isOmarStartingTowerTurn
                                        ? 'Отменить установку стартовой вышки'
                                        : 'Отменить установку дома'
                                "
                                @click="undoStartingBuilding"
                            >
                                <RotateCcw
                                    class="size-4"
                                    :class="undoStartingBuildingRequest.processing ? 'animate-spin' : ''"
                                />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>
                            {{
                                isOmarStartingTowerTurn
                                    ? 'Отменить установку стартовой вышки'
                                    : 'Отменить установку дома'
                            }}
                        </TooltipContent>
                    </Tooltip>
                </div>
                <div>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                type="button"
                                size="icon"
                                :disabled="
                                    finishStartingBuildingRequest.processing || undoStartingBuildingRequest.processing
                                "
                                aria-label="Подтвердить и закончить ход"
                                @click="finishStartingBuildingTurn"
                            >
                                <Check class="size-4" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Подтвердить и закончить ход</TooltipContent>
                    </Tooltip>
                </div>
            </div>
        </TooltipProvider>

        <TooltipProvider v-else-if="canSpendStartingSpade && pendingStartingSpadeHexId" :delay-duration="150">
            <div class="flex shrink-0 items-center gap-2">
                <Form v-bind="StartingSpadeController.destroy.form(game.data.id)" #default="{ processing }">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                type="submit"
                                variant="outline"
                                size="icon"
                                :disabled="processing"
                                aria-label="Отменить преобразование"
                            >
                                <RotateCcw class="size-4" :class="processing ? 'animate-spin' : ''" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Отменить преобразование</TooltipContent>
                    </Tooltip>
                </Form>
                <Form v-bind="StartingSpadeTurnController.form(game.data.id)" #default="{ processing }">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                type="submit"
                                size="icon"
                                :disabled="processing"
                                aria-label="Подтвердить преобразование"
                            >
                                <Check class="size-4" />
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>Подтвердить преобразование</TooltipContent>
                    </Tooltip>
                </Form>
            </div>
        </TooltipProvider>

        <Form
            v-else-if="game.data.canUndoTownChoice && game.data.pendingInteraction !== null"
            v-bind="TownChoiceUndoController.form(game.data.id)"
            #default="{ processing }"
        >
            <Button type="submit" variant="outline" :disabled="processing">
                <RotateCcw class="size-4" :class="processing ? 'animate-spin' : ''" />
                Изменить жетон города
            </Button>
        </Form>

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

        <Button
            v-else-if="isCurrentUsersTurn && isPalaceBuildingSelectionActive"
            type="button"
            variant="outline"
            @click="emit('cancelPalaceBuildingSelection')"
        >
            <RotateCcw class="size-4" />
            Отменить действие
        </Button>

        <div
            v-else-if="
                game.data.phase === 'actions' &&
                isCurrentUsersTurn &&
                (game.data.pendingInteraction === null ||
                    game.data.pendingInteraction.type === 'build_workshop_after_terraforming')
            "
            class="flex shrink-0 items-center gap-2"
        >
            <Form
                v-if="game.data.canUndoTownChoice"
                v-bind="TownChoiceUndoController.form(game.data.id)"
                #default="{ processing }"
            >
                <Button type="submit" variant="outline" :disabled="processing">
                    <RotateCcw class="size-4" :class="processing ? 'animate-spin' : ''" />
                    Изменить жетон города
                </Button>
            </Form>
            <Form
                v-else-if="game.data.canRestartCurrentTurn"
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
            <Button v-if="game.data.canPass" type="button" variant="secondary" @click="emit('pass')"> Пас </Button>
        </div>
    </div>
</template>
