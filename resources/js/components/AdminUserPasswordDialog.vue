<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AdminUserPasswordController from '@/actions/App/Http/Controllers/AdminUserPasswordController';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{ userId: number; userName: string; userEmail: string }>();
const open = ref(false);
const form = useForm({ password: '', password_confirmation: '' });

watch(open, () => {
    form.reset();
    form.clearErrors();
});

function save(): void {
    form.submit(AdminUserPasswordController(props.userId), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
        onFinish: () => form.reset(),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button type="button" size="sm" variant="outline">Сменить пароль</Button>
        </DialogTrigger>
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Сменить пароль</DialogTitle>
                <DialogDescription>{{ userName }} — {{ userEmail }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="save">
                <div class="grid gap-2">
                    <Label :for="`password-${userId}`">Новый пароль</Label>
                    <Input
                        :id="`password-${userId}`"
                        v-model="form.password"
                        name="password"
                        type="password"
                        autocomplete="new-password"
                        required
                        :disabled="form.processing"
                    />
                    <InputError :message="form.errors.password" />
                </div>
                <div class="grid gap-2">
                    <Label :for="`password-confirmation-${userId}`">Подтверждение пароля</Label>
                    <Input
                        :id="`password-confirmation-${userId}`"
                        v-model="form.password_confirmation"
                        name="password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                        :disabled="form.processing"
                    />
                    <InputError :message="form.errors.password_confirmation" />
                </div>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button type="button" variant="outline" :disabled="form.processing">Отмена</Button>
                    </DialogClose>
                    <Button type="submit" :disabled="form.processing">{{
                        form.processing ? 'Сохранение…' : 'Сменить пароль'
                    }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
