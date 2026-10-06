<script setup lang="ts">
import { ref } from 'vue';
import PalaceRewardOrderController from '@/actions/App/Http/Controllers/Game/PalaceRewardOrderController';
import Form from '@/components/game/GameActionForm.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

defineProps<{ gameId: number }>();
const firstReward = ref<'spades' | 'bridges' | null>(null);
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <Button
            type="button"
            :variant="firstReward === 'bridges' ? 'default' : 'outline'"
            @click="firstReward = 'bridges'"
        >
            Установить мосты
        </Button>
        <Button
            type="button"
            :variant="firstReward === 'spades' ? 'default' : 'outline'"
            @click="firstReward = 'spades'"
        >
            Использовать лопаты
        </Button>
        <Dialog
            :open="firstReward !== null"
            @update:open="
                (open) => {
                    if (!open) firstReward = null;
                }
            "
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{
                        firstReward === 'bridges' ? 'Сначала установить мосты?' : 'Сначала использовать лопаты?'
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            firstReward === 'bridges'
                                ? 'Сначала вы установите два моста, затем используете две лопаты.'
                                : 'Сначала вы используете две лопаты, затем установите два моста.'
                        }}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    v-bind="PalaceRewardOrderController.form(gameId)"
                    #default="{ processing }"
                    @success="firstReward = null"
                >
                    <input type="hidden" name="first_reward" :value="firstReward" />
                    <DialogFooter>
                        <Button type="button" variant="outline" :disabled="processing" @click="firstReward = null"
                            >Отмена</Button
                        >
                        <Button type="submit" :disabled="processing">Подтвердить порядок</Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
