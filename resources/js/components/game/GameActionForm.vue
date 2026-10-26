<script setup lang="ts">
import { formDataToObject } from '@inertiajs/core';
import type { Method } from '@inertiajs/core';
import { useHttp } from '@inertiajs/vue3';

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

function submit(event: SubmitEvent): void {
    emit('submit', event);

    if (event.defaultPrevented) {
        return;
    }

    event.preventDefault();

    const form = event.currentTarget as HTMLFormElement;
    const data = formDataToObject(new FormData(form, event.submitter));

    request.transform(() => data as unknown as Record<string, HttpFormValue>);

    void request.submit(props.method, props.action, {
        onSuccess: () => {
            if (props.resetOnSuccess) {
                form.reset();
            }

            emit('success');
        },
        onError: () => emit('error'),
    });
}
</script>

<template>
    <form v-bind="$attrs" :action="action" :method="method" @submit="submit">
        <slot :errors="request.errors" :processing="request.processing" />
    </form>
</template>
