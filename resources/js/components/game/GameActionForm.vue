<script setup lang="ts">
import { formDataToObject } from '@inertiajs/core';
import type { Method } from '@inertiajs/core';
import { useHttp } from '@inertiajs/vue3';
import { ref } from 'vue';
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

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        action: string;
        method?: Method;
        resetOnSuccess?: boolean;
    }>(),
    {
        method: 'post',
        resetOnSuccess: false,
    },
);

const emit = defineEmits<{
    submit: [event: SubmitEvent];
    success: [];
    error: [];
}>();

type HttpFormValue = string | number | boolean | Blob | null;

const request = useHttp<Record<string, HttpFormValue>>({});
const confirmationOpen = ref(false);
const confirmationMessage = ref('');
let confirmationKey = '';
let acknowledged: string[] = [];
let pendingSubmission: { form: HTMLFormElement; data: Record<string, HttpFormValue>; action: string; method: Method } | null = null;

function submit(event: SubmitEvent): void {
    emit('submit', event);

    if (event.defaultPrevented) {
        return;
    }

    event.preventDefault();

    const form = event.currentTarget as HTMLFormElement;
    const data = formDataToObject(new FormData(form, event.submitter));

    acknowledged = [];
    pendingSubmission = { form, data: data as unknown as Record<string, HttpFormValue>, action: props.action, method: props.method };
    send();
}

function confirmPowerLoss(): void {
    acknowledged.push(confirmationKey);
    confirmationOpen.value = false;
    send();
}

function send(): void {
    if (!pendingSubmission || request.processing) {
        return;
    }

    const { form, data, action, method } = pendingSubmission;
    request.transform(() => data);

    void request.submit(method, action, {
        headers: { 'X-Confirmed-Power-Loss': acknowledged.join(',') },
        onSuccess: () => {
            if (props.resetOnSuccess) {
                form.reset();
            }

            emit('success');
        },
        onError: (errors) => {
            if (typeof errors.power_confirmation === 'string' && typeof errors.power_confirmation_key === 'string') {
                confirmationMessage.value = errors.power_confirmation;
                confirmationKey = errors.power_confirmation_key;
                confirmationOpen.value = true;
                request.clearErrors('power_confirmation', 'power_confirmation_key');
                return;
            }

            emit('error');
        },
    });
}
</script>

<template>
    <form v-bind="$attrs" :action="action" :method="method" @submit="submit">
        <slot :errors="request.errors" :processing="request.processing" />
        <Dialog v-model:open="confirmationOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Часть Силы пропадёт</DialogTitle>
                    <DialogDescription>{{ confirmationMessage }}</DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="outline">Отмена</Button>
                    </DialogClose>
                    <Button type="button" :disabled="request.processing" @click="confirmPowerLoss">
                        Всё равно выполнить
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </form>
</template>
