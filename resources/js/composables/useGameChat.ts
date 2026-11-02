import { HttpResponseError } from '@inertiajs/core';
import { useHttp } from '@inertiajs/vue3';
import { useConnectionStatus, useEcho } from '@laravel/echo-vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { index, store } from '@/actions/App/Http/Controllers/Chat/ChatMessageController';
import type { ChatHistoryPage, ChatMessage } from '@/types/chat';

export function useGameChat(gameId: number, gamePlayerId: number, onMessageReceived?: (message: ChatMessage) => void) {
    const messages = ref<ChatMessage[]>([]);
    const hasMore = ref(false);
    const loaded = ref(false);
    const historyError = ref('');
    const sendError = ref('');
    const accessDenied = ref(false);
    const synchronizing = ref(false);
    const connectionStatus = useConnectionStatus();
    const form = useHttp<{ body: string; client_message_id: string }, { data: ChatMessage } | undefined>({
        body: '',
        client_message_id: '',
    });
    const historyRequest = useHttp<Record<string, never>, ChatHistoryPage>({});
    const catchUpRequest = useHttp<Record<string, never>, ChatHistoryPage>({});
    let lastAttempt: { body: string; id: string } | null = null;
    let syncedThroughId = 0;
    let synchronizeAgain = false;
    let disposed = false;

    function merge(incoming: ChatMessage[]): void {
        if (disposed || accessDenied.value) {
            return;
        }

        const byId = new Map(messages.value.map((message) => [message.id, message]));
        incoming.filter((message) => message.gameId === gameId).forEach((message) => byId.set(message.id, message));
        messages.value = [...byId.values()].sort((first, second) => first.id - second.id);
    }

    const subscription = useEcho<{ message: ChatMessage }>(
        `game-chat.${gameId}.${gamePlayerId}`,
        '.chat.message.created',
        ({ message }) => {
            if (disposed || accessDenied.value || message.gameId !== gameId) {
                return;
            }

            const isNewMessage = !messages.value.some((existing) => existing.id === message.id);
            merge([message]);

            if (isNewMessage) {
                onMessageReceived?.(message);
            }
        },
    );

    function handleDenied(error: unknown): boolean {
        if (error instanceof HttpResponseError && [403, 404].includes(error.response.status)) {
            accessDenied.value = true;
            messages.value = [];
            subscription.leaveChannel();

            return true;
        }

        return false;
    }

    async function synchronize(): Promise<void> {
        if (disposed || accessDenied.value) {
            return;
        }

        if (!loaded.value || synchronizing.value) {
            synchronizeAgain = true;

            return;
        }

        synchronizing.value = true;
        historyError.value = '';

        try {
            do {
                synchronizeAgain = false;
                let page: ChatHistoryPage;

                do {
                    page = await catchUpRequest.get(index.url(gameId, { query: { after_id: syncedThroughId } }));
                    merge(page.data);
                    syncedThroughId = Math.max(syncedThroughId, ...page.data.map((message) => message.id));
                } while (page.hasMore && !disposed && !accessDenied.value);
            } while (synchronizeAgain && !disposed && !accessDenied.value);
        } catch (error) {
            if (!handleDenied(error)) {
                historyError.value = 'Не удалось обновить чат. Попробуйте ещё раз.';
            }
        } finally {
            synchronizing.value = false;
        }
    }

    async function loadHistory(): Promise<void> {
        if (disposed || accessDenied.value || historyRequest.processing) {
            return;
        }

        historyError.value = '';

        try {
            const beforeId = loaded.value ? messages.value[0]?.id : undefined;
            const page = await historyRequest.get(index.url(gameId, { query: { before_id: beforeId } }));
            merge(page.data);
            hasMore.value = page.hasMore;

            if (!loaded.value) {
                syncedThroughId = Math.max(0, ...page.data.map((message) => message.id));
                loaded.value = true;
                await synchronize();
            }
        } catch (error) {
            if (!handleDenied(error)) {
                historyError.value = 'Не удалось загрузить сообщения. Попробуйте ещё раз.';
            }
        }
    }

    async function refresh(): Promise<void> {
        if (loaded.value) {
            await synchronize();
        } else {
            await loadHistory();
        }
    }

    async function send(): Promise<void> {
        const body = form.body.trim();

        if (!body || form.processing || accessDenied.value || disposed) {
            return;
        }

        sendError.value = '';
        form.clearErrors();

        if (lastAttempt?.body !== body) {
            lastAttempt = { body, id: crypto.randomUUID() };
        }

        form.client_message_id = lastAttempt.id;
        form.body = body;

        try {
            const response = await form.submit(store(gameId));

            if (!response) {
                return;
            }

            merge([response.data]);
            form.defaults({ body: '', client_message_id: '' });
            form.resetAndClearErrors();
            lastAttempt = null;
        } catch (error) {
            if (!handleDenied(error) && !form.hasErrors) {
                sendError.value = 'Не удалось отправить сообщение. Повторите отправку — текст сохранён.';
            }
        }
    }

    function clearDraft(): void {
        if (form.processing) {
            return;
        }

        form.resetAndClearErrors();
        sendError.value = '';
        lastAttempt = null;
    }

    function onVisibilityChange(): void {
        if (document.visibilityState === 'visible') {
            void refresh();
        }
    }

    onMounted(() => {
        subscription.channel().subscribed(() => void refresh());
        window.addEventListener('focus', refresh);
        document.addEventListener('visibilitychange', onVisibilityChange);
        void loadHistory();
    });

    onUnmounted(() => {
        disposed = true;
        form.cancel();
        historyRequest.cancel();
        catchUpRequest.cancel();
        window.removeEventListener('focus', refresh);
        document.removeEventListener('visibilitychange', onVisibilityChange);
    });

    return {
        messages,
        hasMore,
        loaded,
        historyError,
        sendError,
        accessDenied,
        connectionStatus,
        form,
        loading: computed(() => historyRequest.processing),
        loadHistory,
        refresh,
        send,
        clearDraft,
    };
}
