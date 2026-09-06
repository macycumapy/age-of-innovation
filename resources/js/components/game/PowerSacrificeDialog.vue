<script setup lang="ts">
import Form from '@/components/game/GameActionForm.vue';
import { ref, watch } from 'vue';
import PowerSacrificeController from '@/actions/App/Http/Controllers/PowerSacrificeController';
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

defineProps<{
    gameId: number;
    maximumAmount: number;
}>();

const isOpen = defineModel<boolean>('open', { required: true });
const amount = ref(1);

function sacrificeSucceeded(): void {
    isOpen.value = false;
}

watch(isOpen, (open) => {
    if (open) {
        amount.value = 1;
    }
});
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent>
            <Form
                v-bind="PowerSacrificeController.store.form(gameId)"
                class="contents"
                reset-on-success
                #default="{ errors, processing }"
                @success="sacrificeSucceeded"
            >
                <DialogHeader>
                    <DialogTitle>Пожертвовать Силу</DialogTitle>
                    <DialogDescription>
                        За каждый сброшенный жетон ещё один жетон переместится из чаши II в чашу III.
                        Ход после этого продолжится.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <label for="power-sacrifice-amount" class="text-sm font-medium">
                        Количество сбрасываемой Силы
                    </label>
                    <NumberStepper
                        id="power-sacrifice-amount"
                        v-model="amount"
                        name="amount"
                        :min="1"
                        :max="maximumAmount"
                        required
                    />
                    <p class="text-sm text-muted-foreground">
                        Можно сбросить от 1 до {{ maximumAmount }}.
                    </p>
                    <InputError :message="errors.amount ?? errors.game" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="processing || amount < 1 || amount > maximumAmount"
                    >
                        {{ processing ? 'Подтверждение…' : 'Подтвердить' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
