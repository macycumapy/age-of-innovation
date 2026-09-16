<script setup lang="ts">
import { LogOut, UserMinus } from '@lucide/vue';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import GamePlayerRemovalController from '@/actions/App/Http/Controllers/GamePlayerRemovalController';
import Form from '@/components/game/GameActionForm.vue';
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
import { index } from '@/routes/games';

const props = defineProps<{
    gameId: number;
    playerId: number;
    playerName: string;
    isLeaving?: boolean;
}>();

const isOpen = ref(false);

function handleSuccess(): void {
    isOpen.value = false;

    if (props.isLeaving) {
        router.visit(index().url);
    }
}
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogTrigger as-child>
            <Button type="button" variant="outline" size="sm">
                <LogOut v-if="isLeaving" class="size-4" />
                <UserMinus v-else class="size-4" />
                {{ isLeaving ? 'Покинуть игру' : 'Исключить' }}
            </Button>
        </DialogTrigger>

        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ isLeaving ? 'Покинуть игру?' : `Исключить игрока ${playerName}?` }}</DialogTitle>
                <DialogDescription>
                    <template v-if="isLeaving">
                        Вы покинете лобби. Если вы владелец, права перейдут следующему игроку.
                    </template>
                    <template v-else>Игрок сможет снова присоединиться, пока в лобби есть свободное место.</template>
                </DialogDescription>
            </DialogHeader>

            <Form
                v-bind="GamePlayerRemovalController.form({ game: gameId, gamePlayer: playerId })"
                #default="{ errors, processing }"
                @success="handleSuccess"
            >
                <p v-if="errors.game" class="mb-3 text-sm text-destructive">{{ errors.game }}</p>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button type="button" variant="outline" :disabled="processing">Отмена</Button>
                    </DialogClose>
                    <Button type="submit" variant="destructive" :disabled="processing">
                        {{ processing ? 'Выполнение…' : isLeaving ? 'Покинуть' : 'Исключить' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
