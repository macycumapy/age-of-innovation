<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed } from 'vue';
import PowerActionController from '@/actions/App/Http/Controllers/PowerActionController';
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
import type { GamePlayerBoardState, PowerActionState } from '@/types';
import powerUrl from '../../../images/token_parts/mana.png';

const props = defineProps<{
    gameId: number;
    action: PowerActionState | null;
    playerState?: GamePlayerBoardState;
}>();

const isOpen = defineModel<boolean>('open', { required: true });
const sacrificeAmount = computed(() =>
    Math.max(0, (props.action?.cost ?? 0) - (props.playerState?.power.bowlThree ?? 0)),
);
const canAfford = computed(() =>
    sacrificeAmount.value * 2 <= (props.playerState?.power.bowlTwo ?? 0),
);

function actionSucceeded(): void {
    isOpen.value = false;
}
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent>
            <Form
                v-if="action"
                v-bind="PowerActionController.form(gameId)"
                class="contents"
                #default="{ errors, processing }"
                @success="actionSucceeded"
            >
                <input type="hidden" name="action" :value="action.id" />
                <input type="hidden" name="sacrifice_amount" :value="sacrificeAmount" />

                <DialogHeader>
                    <DialogTitle>Действие за Силу</DialogTitle>
                    <DialogDescription>{{ action.description }}</DialogDescription>
                </DialogHeader>

                <div class="grid gap-3 rounded-lg border p-4 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span>Стоимость действия</span>
                        <strong class="flex items-center gap-1.5">
                            {{ action.cost }}
                            <img :src="powerUrl" alt="Сила" class="size-7 object-contain" />
                        </strong>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span>В чаше III</span>
                        <strong>{{ playerState?.power.bowlThree ?? 0 }}</strong>
                    </div>
                    <p v-if="sacrificeAmount > 0 && canAfford" class="text-amber-700 dark:text-amber-300">
                        Для действия не хватает Силы в чаше III. Перед действием будет пожертвовано
                        {{ sacrificeAmount }} Силы: из чаши II уйдёт {{ sacrificeAmount * 2 }} жетонов,
                        а {{ sacrificeAmount }} жетонов перейдёт в чашу III.
                    </p>
                    <p v-else-if="!canAfford" class="text-destructive">
                        Даже после максимально возможного пожертвования Силы недостаточно.
                    </p>
                </div>

                <InputError :message="errors.action ?? errors.sacrifice_amount ?? errors.game" />

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="processing || !canAfford">
                        {{ processing ? 'Выполнение…' : 'Подтвердить действие' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
