<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import ResourceExchangeController from '@/actions/App/Http/Controllers/ResourceExchangeController';
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
} from '@/components/ui/dialog';
import { NumberStepper } from '@/components/ui/number-stepper';
import type { GamePlayerBoardState, KnowledgeDiscipline } from '@/types';
import bankingBookUrl from '../../../images/token_parts/coin_book.png';
import toolResourceUrl from '../../../images/token_parts/cube.png';
import engineeringBookUrl from '../../../images/token_parts/engineering_book.png';
import coinResourceUrl from '../../../images/token_parts/gold_medallion.png';
import lawBookUrl from '../../../images/token_parts/law_book.png';
import powerResourceUrl from '../../../images/token_parts/mana.png';
import medicineBookUrl from '../../../images/token_parts/medicine_book.png';
import scholarResourceUrl from '../../../images/token_parts/scholar.png';

type ScalarResourceExchange =
    | 'power_to_scholar'
    | 'power_to_tool'
    | 'power_to_coin'
    | 'scholar_to_tool'
    | 'tool_to_coin';
type ResourceIcon = 'power' | 'scholar' | 'tool' | 'coin';

defineProps<{
    gameId: number;
    playerState?: GamePlayerBoardState;
}>();

const isOpen = defineModel<boolean>('open', { required: true });
const knowledgeDisciplines: KnowledgeDiscipline[] = ['banking', 'law', 'engineering', 'medicine'];
const resourceExchangeCounts = reactive<Record<ScalarResourceExchange, number>>({
    power_to_scholar: 0,
    power_to_tool: 0,
    power_to_coin: 0,
    scholar_to_tool: 0,
    tool_to_coin: 0,
});
const powerToBookCounts = reactive<Record<KnowledgeDiscipline, number>>({
    banking: 0,
    law: 0,
    engineering: 0,
    medicine: 0,
});
const bookToCoinCounts = reactive<Record<KnowledgeDiscipline, number>>({
    banking: 0,
    law: 0,
    engineering: 0,
    medicine: 0,
});

const resourceIcons: Record<ResourceIcon, string> = {
    power: powerResourceUrl,
    scholar: scholarResourceUrl,
    tool: toolResourceUrl,
    coin: coinResourceUrl,
};
const resourceIconNames: Record<ResourceIcon, string> = {
    power: 'Сила',
    scholar: 'Учёный',
    tool: 'Инструмент',
    coin: 'Золото',
};
const bookImages: Record<KnowledgeDiscipline, string> = {
    banking: bankingBookUrl,
    law: lawBookUrl,
    engineering: engineeringBookUrl,
    medicine: medicineBookUrl,
};
const knowledgeDisciplineNames: Record<KnowledgeDiscipline, string> = {
    banking: 'Банковское дело',
    law: 'Право',
    engineering: 'Инженерное дело',
    medicine: 'Медицина',
};
const scalarResourceExchangeOptions: {
    value: ScalarResourceExchange;
    cost: number;
    source: ResourceIcon;
    result: number;
    target: ResourceIcon;
}[] = [
    { value: 'power_to_scholar', cost: 5, source: 'power', result: 1, target: 'scholar' },
    { value: 'power_to_tool', cost: 3, source: 'power', result: 1, target: 'tool' },
    { value: 'power_to_coin', cost: 1, source: 'power', result: 1, target: 'coin' },
    { value: 'scholar_to_tool', cost: 1, source: 'scholar', result: 1, target: 'tool' },
    { value: 'tool_to_coin', cost: 1, source: 'tool', result: 1, target: 'coin' },
];

const selectedExchangeCount = computed(() =>
    Object.values(resourceExchangeCounts).reduce((total, count) => total + count, 0)
        + Object.values(powerToBookCounts).reduce((total, count) => total + count, 0)
        + Object.values(bookToCoinCounts).reduce((total, count) => total + count, 0),
);

function resetCounts(): void {
    for (const exchange of scalarResourceExchangeOptions) {
        resourceExchangeCounts[exchange.value] = 0;
    }

    for (const discipline of knowledgeDisciplines) {
        powerToBookCounts[discipline] = 0;
        bookToCoinCounts[discipline] = 0;
    }
}

function exchangeSucceeded(): void {
    isOpen.value = false;
}

watch(isOpen, (open) => {
    if (open) {
        resetCounts();
    }
});
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent class="sm:max-w-4xl">
            <Form
                v-bind="ResourceExchangeController.form(gameId)"
                class="contents"
                #default="{ errors, processing }"
                @success="exchangeSucceeded"
            >
                <DialogHeader>
                    <DialogTitle>Обмен ресурсов</DialogTitle>
                    <DialogDescription>
                        Укажите количество обменов. Все выбранные операции применятся одновременно.
                    </DialogDescription>
                </DialogHeader>

                <div class="flex flex-wrap gap-x-5 gap-y-1 rounded-lg bg-muted/60 px-4 py-3 text-sm">
                    <span class="flex items-center gap-1.5">
                        <img :src="resourceIcons.power" alt="Сила" class="size-7 object-contain" />
                        <strong>{{ playerState?.power.bowlThree ?? 0 }}</strong>
                    </span>
                    <span class="flex items-center gap-1.5">
                        <img :src="resourceIcons.scholar" alt="Учёные" class="size-7 object-contain" />
                        <strong>{{ playerState?.scholars ?? 0 }}</strong>
                    </span>
                    <span class="flex items-center gap-1.5">
                        <img :src="resourceIcons.tool" alt="Инструменты" class="size-7 object-contain" />
                        <strong>{{ playerState?.tools ?? 0 }}</strong>
                    </span>
                    <span class="flex items-center gap-1.5">
                        <img :src="resourceIcons.coin" alt="Золото" class="size-7 object-contain" />
                        <strong>{{ playerState?.coins ?? 0 }}</strong>
                    </span>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label
                        v-for="exchange in scalarResourceExchangeOptions"
                        :key="exchange.value"
                        class="grid grid-cols-[1fr_8rem] items-center gap-3 rounded-lg border p-3 text-sm"
                    >
                        <span class="flex items-center gap-2 font-medium">
                            <span>{{ exchange.cost }}</span>
                            <img
                                :src="resourceIcons[exchange.source]"
                                :alt="resourceIconNames[exchange.source]"
                                class="size-8 object-contain"
                            />
                            <span aria-hidden="true">→</span>
                            <span>{{ exchange.result }}</span>
                            <img
                                :src="resourceIcons[exchange.target]"
                                :alt="resourceIconNames[exchange.target]"
                                class="size-8 object-contain"
                            />
                        </span>
                        <NumberStepper
                            v-model="resourceExchangeCounts[exchange.value]"
                            :min="0"
                            :max="99"
                            :name="`exchanges[${exchange.value}]`"
                        />
                    </label>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <section class="grid gap-2 rounded-lg border p-3">
                        <h3 class="flex items-center gap-2 font-medium">
                            <span>5</span>
                            <img :src="resourceIcons.power" alt="Сила" class="size-8 object-contain" />
                            <span aria-hidden="true">→</span>
                            <span>1</span>
                            <span>книга</span>
                        </h3>
                        <label
                            v-for="discipline in knowledgeDisciplines"
                            :key="`power-book-${discipline}`"
                            class="grid grid-cols-[2rem_1fr_8rem] items-center gap-2 text-sm"
                        >
                            <img :src="bookImages[discipline]" alt="" class="size-8 object-contain" />
                            <span>{{ knowledgeDisciplineNames[discipline] }}</span>
                            <NumberStepper
                                v-model="powerToBookCounts[discipline]"
                                :min="0"
                                :max="99"
                                :name="`exchanges[power_to_book][${discipline}]`"
                            />
                        </label>
                    </section>

                    <section class="grid gap-2 rounded-lg border p-3">
                        <h3 class="flex items-center gap-2 font-medium">
                            <span>1 книга</span>
                            <span aria-hidden="true">→</span>
                            <span>1</span>
                            <img :src="resourceIcons.coin" alt="Золото" class="size-8 object-contain" />
                        </h3>
                        <label
                            v-for="discipline in knowledgeDisciplines"
                            :key="`book-coin-${discipline}`"
                            class="grid grid-cols-[2rem_1fr_8rem] items-center gap-2 text-sm"
                        >
                            <img :src="bookImages[discipline]" alt="" class="size-8 object-contain" />
                            <span>
                                {{ knowledgeDisciplineNames[discipline] }}
                                ({{ playerState?.books[discipline] ?? 0 }})
                            </span>
                            <NumberStepper
                                v-model="bookToCoinCounts[discipline]"
                                :min="0"
                                :max="99"
                                :name="`exchanges[book_to_coin][${discipline}]`"
                            />
                        </label>
                    </section>
                </div>
                <InputError :message="errors.exchanges ?? Object.values(errors)[0] ?? errors.game" />

                <DialogFooter class="gap-2">
                    <DialogClose as-child><Button type="button" variant="outline">Отмена</Button></DialogClose>
                    <Button type="submit" :disabled="processing || selectedExchangeCount === 0">
                        {{ processing ? 'Обмен…' : 'Подтвердить обмен' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
