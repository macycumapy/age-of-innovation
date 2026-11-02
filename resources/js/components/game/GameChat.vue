<script setup lang="ts">
import ArrowDown from '@lucide/vue/dist/esm/icons/arrow-down.mjs';
import GripHorizontal from '@lucide/vue/dist/esm/icons/grip-horizontal.mjs';
import LoaderCircle from '@lucide/vue/dist/esm/icons/loader-circle.mjs';
import MessageCircle from '@lucide/vue/dist/esm/icons/message-circle.mjs';
import Minus from '@lucide/vue/dist/esm/icons/minus.mjs';
import Send from '@lucide/vue/dist/esm/icons/send.mjs';
import Volume2 from '@lucide/vue/dist/esm/icons/volume-2.mjs';
import VolumeX from '@lucide/vue/dist/esm/icons/volume-x.mjs';
import { computed, nextTick, onMounted, onUnmounted, ref, useTemplateRef, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { useSidebar } from '@/components/ui/sidebar';
import { SIDEBAR_WIDTH, SIDEBAR_WIDTH_ICON } from '@/components/ui/sidebar/utils';
import { useChatMessageSound } from '@/composables/useChatMessageSound';
import { useGameChat } from '@/composables/useGameChat';
import { useGameChatControls } from '@/composables/useGameChatControls';
import { useGameChatPreferences } from '@/composables/useGameChatPreferences';

const props = defineProps<{
    gameId: number;
    gamePlayerId: number;
    currentUserId: number;
}>();

const { soundEnabled, windowPosition, toggleSound, savePreferences } = useGameChatPreferences(props.currentUserId);
const { play: playMessageSound } = useChatMessageSound(soundEnabled);
const {
    messages,
    hasMore,
    loaded,
    historyError,
    sendError,
    accessDenied,
    connectionStatus,
    form,
    loading,
    loadHistory,
    refresh,
    send,
    clearDraft,
} = useGameChat(props.gameId, props.gamePlayerId, (message) => {
    if (message.authorId !== props.currentUserId) {
        void playMessageSound();
    }
});

const isOpen = ref(false);
const isNearBottom = ref(true);
const lastReadId = ref<number | null>(null);
const messageList = useTemplateRef<HTMLElement>('messageList');
const composer = useTemplateRef<HTMLTextAreaElement>('composer');
const chatWindow = useTemplateRef<HTMLElement>('chatWindow');
const isDragging = ref(false);
const { isMobile, state: sidebarState } = useSidebar();
const windowStyle = computed(() => {
    if (windowPosition.value) {
        return { left: 'auto', right: `${windowPosition.value.right}px`, bottom: `${windowPosition.value.bottom}px` };
    }

    return {
        left: isMobile.value
            ? '0.75rem'
            : sidebarState.value === 'expanded'
              ? `calc(${SIDEBAR_WIDTH} + 1.25rem)`
              : `calc(${SIDEBAR_WIDTH_ICON} + 1rem + 1.25rem)`,
    };
});
let returnFocus: (() => void | Promise<void>) | undefined;
let drag: {
    pointerId: number;
    x: number;
    y: number;
    right: number;
    bottom: number;
    width: number;
    height: number;
    handle: HTMLElement;
} | null = null;
const panelId = `game-chat-${props.gameId}`;
const latestMessageId = computed(() => messages.value.at(-1)?.id ?? 0);
const unreadCount = computed(() => {
    const lastRead = lastReadId.value;

    return lastRead === null
        ? 0
        : messages.value.filter((message) => message.id > lastRead && message.authorId !== props.currentUserId).length;
});
const connectionLabel = computed(() =>
    connectionStatus.value === 'connected' ? 'Чат подключён' : 'Соединение восстанавливается…',
);
const timeFormat = new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' });
const dateFormat = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });
const datedMessages = computed(() =>
    messages.value.map((message, index, entries) => {
        const day = dateFormat.format(new Date(message.createdAt));
        const previous = entries[index - 1];

        return {
            ...message,
            day: !previous || dateFormat.format(new Date(previous.createdAt)) !== day ? day : null,
        };
    }),
);
const { activeChat } = useGameChatControls();
const chatControls = { panelId, isOpen, soundEnabled, unreadCount, open: openChat, close: closeChat };

function constrainPosition(right: number, bottom: number, width: number, height: number): void {
    const margin = 12;
    windowPosition.value = {
        right: Math.min(Math.max(right, margin), Math.max(margin, window.innerWidth - width - margin)),
        bottom: Math.min(Math.max(bottom, margin), Math.max(margin, window.innerHeight - height - margin)),
    };
}

function keepInViewport(): void {
    if (!windowPosition.value || !chatWindow.value) {
        return;
    }

    const { width, height } = chatWindow.value.getBoundingClientRect();
    constrainPosition(windowPosition.value.right, windowPosition.value.bottom, width, height);
    savePreferences();
}

function startDragging(event: PointerEvent): void {
    if (event.button !== 0 || !event.isPrimary || drag || !chatWindow.value) {
        return;
    }

    const bounds = chatWindow.value.getBoundingClientRect();
    const handle = event.currentTarget as HTMLElement;
    drag = {
        pointerId: event.pointerId,
        x: event.clientX,
        y: event.clientY,
        right: window.innerWidth - bounds.right,
        bottom: window.innerHeight - bounds.bottom,
        width: bounds.width,
        height: bounds.height,
        handle,
    };
    handle.setPointerCapture(event.pointerId);
    isDragging.value = true;
    event.preventDefault();
}

function moveDragging(event: PointerEvent): void {
    if (!drag || event.pointerId !== drag.pointerId) {
        return;
    }

    constrainPosition(
        drag.right - (event.clientX - drag.x),
        drag.bottom - (event.clientY - drag.y),
        drag.width,
        drag.height,
    );
}

function stopDragging(event?: PointerEvent): void {
    if (!drag || (event && event.pointerId !== drag.pointerId)) {
        return;
    }

    const { handle, pointerId } = drag;
    drag = null;
    isDragging.value = false;

    if (handle.hasPointerCapture(pointerId)) {
        handle.releasePointerCapture(pointerId);
    }

    savePreferences();
}

function moveWithKeyboard(event: KeyboardEvent): void {
    if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key) || !chatWindow.value) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    const bounds = chatWindow.value.getBoundingClientRect();
    const step = event.shiftKey ? 40 : 10;
    const horizontal = event.key === 'ArrowLeft' ? -step : event.key === 'ArrowRight' ? step : 0;
    const vertical = event.key === 'ArrowUp' ? -step : event.key === 'ArrowDown' ? step : 0;
    constrainPosition(
        window.innerWidth - bounds.right - horizontal,
        window.innerHeight - bounds.bottom - vertical,
        bounds.width,
        bounds.height,
    );
    savePreferences();
}

function onViewportResize(): void {
    stopDragging();
    keepInViewport();
}

onMounted(() => {
    activeChat.value = chatControls;
    window.addEventListener('resize', onViewportResize);
});

onUnmounted(() => {
    if (activeChat.value === chatControls) {
        activeChat.value = null;
    }

    stopDragging();
    window.removeEventListener('resize', onViewportResize);
});

watch(
    [loaded, latestMessageId, isOpen],
    ([historyLoaded, latestId, open]) => {
        if (historyLoaded && (lastReadId.value === null || open)) {
            lastReadId.value = latestId;
        }
    },
    { immediate: true },
);

async function scrollToLatest(): Promise<void> {
    await nextTick();
    const element = messageList.value;

    if (element) {
        element.scrollTop = element.scrollHeight;
        isNearBottom.value = true;
    }
}

async function openChat(onClose?: () => void | Promise<void>): Promise<void> {
    if (onClose) {
        returnFocus = onClose;
    }

    isOpen.value = true;
    await scrollToLatest();
    keepInViewport();
    composer.value?.focus({ preventScroll: true });
}

async function closeChat(): Promise<void> {
    stopDragging();
    isOpen.value = false;
    await nextTick();
    keepInViewport();
    await returnFocus?.();
}

function updateScrollPosition(): void {
    const element = messageList.value;

    if (element) {
        isNearBottom.value = element.scrollHeight - element.scrollTop - element.clientHeight < 80;
    }
}

watch(latestMessageId, async () => {
    if (isOpen.value && isNearBottom.value) {
        await scrollToLatest();
    }
});

async function loadOlder(): Promise<void> {
    const element = messageList.value;
    const previousHeight = element?.scrollHeight ?? 0;
    const previousTop = element?.scrollTop ?? 0;
    await loadHistory();
    await nextTick();

    if (element) {
        element.scrollTop = previousTop + element.scrollHeight - previousHeight;
    }
}

async function sendMessage(): Promise<void> {
    if (!form.body.trim() || form.processing) {
        return;
    }

    await send();

    if (!form.body) {
        await scrollToLatest();
    }

    composer.value?.focus({ preventScroll: true });
}

function onComposerKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        void sendMessage();
    }
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="isOpen"
            ref="chatWindow"
            class="fixed bottom-[max(0.75rem,env(safe-area-inset-bottom))] z-40 flex flex-col items-end sm:bottom-5"
            :style="windowStyle"
            @keydown.esc.stop.prevent="closeChat"
        >
            <section
                :id="panelId"
                role="dialog"
                aria-modal="false"
                :aria-labelledby="`${panelId}-title`"
                class="flex h-[min(30rem,calc(100dvh-2rem))] w-[calc(100vw-1.5rem)] max-w-sm flex-col overflow-hidden rounded-2xl border border-border/80 bg-background shadow-2xl ring-1 ring-black/5 dark:ring-white/5"
            >
                <header class="flex shrink-0 items-center justify-between gap-3 border-b px-4 py-2.5">
                    <div
                        role="button"
                        tabindex="0"
                        aria-label="Переместить окно чата"
                        title="Перетащите чат или используйте клавиши со стрелками"
                        class="flex min-w-0 flex-1 touch-none items-center gap-2 rounded-sm select-none focus-visible:outline-2 focus-visible:outline-ring"
                        :class="isDragging ? 'cursor-grabbing' : 'cursor-grab'"
                        @pointerdown="startDragging"
                        @pointermove="moveDragging"
                        @pointerup="stopDragging"
                        @pointercancel="stopDragging"
                        @lostpointercapture="stopDragging"
                        @keydown="moveWithKeyboard"
                    >
                        <GripHorizontal class="size-4 text-muted-foreground" aria-hidden="true" />
                        <h2 :id="`${panelId}-title`" class="text-sm font-semibold">Чат партии</h2>
                        <span
                            role="status"
                            :aria-label="connectionLabel"
                            :title="connectionLabel"
                            class="size-1.5 shrink-0 rounded-full"
                            :class="connectionStatus === 'connected' ? 'bg-emerald-500' : 'bg-amber-500'"
                        />
                    </div>
                    <div class="-mr-1 flex shrink-0 items-center gap-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="rounded-full"
                            :aria-label="soundEnabled ? 'Отключить звук чата' : 'Включить звук чата'"
                            :title="soundEnabled ? 'Отключить звук чата' : 'Включить звук чата'"
                            :aria-pressed="!soundEnabled"
                            @click="toggleSound"
                        >
                            <Volume2 v-if="soundEnabled" class="size-4" aria-hidden="true" />
                            <VolumeX v-else class="size-4" aria-hidden="true" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="rounded-full"
                            aria-label="Свернуть чат"
                            title="Свернуть чат · Esc"
                            @click="closeChat"
                        >
                            <Minus class="size-4" aria-hidden="true" />
                        </Button>
                    </div>
                </header>

                <p v-if="accessDenied" class="p-4 text-sm text-muted-foreground" role="alert">
                    Чат доступен только участникам этой партии.
                </p>
                <template v-else>
                    <div class="relative flex min-h-0 flex-1 flex-col">
                        <div
                            ref="messageList"
                            class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto overscroll-contain px-4 py-3"
                            role="log"
                            aria-label="Сообщения чата"
                            aria-live="polite"
                            :aria-busy="loading"
                            @scroll="updateScrollPosition"
                        >
                            <Button
                                v-if="hasMore"
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="shrink-0 self-center text-xs text-muted-foreground"
                                :disabled="loading"
                                @click="loadOlder"
                            >
                                {{ loading ? 'Загрузка…' : 'Ранее' }}
                            </Button>
                            <div v-if="!loaded && loading" class="grid animate-pulse gap-3" role="status">
                                <span class="sr-only">Загрузка сообщений</span>
                                <div class="h-10 w-3/4 rounded-2xl bg-muted" />
                                <div class="h-10 w-2/3 self-end rounded-2xl bg-muted" />
                            </div>
                            <div
                                v-else-if="messages.length === 0"
                                class="flex flex-1 flex-col items-center justify-center gap-2 py-8 text-center text-muted-foreground"
                            >
                                <MessageCircle class="size-7 opacity-40" aria-hidden="true" />
                                <p class="text-sm">Пока тихо</p>
                                <p class="text-xs">Напишите участникам партии</p>
                            </div>
                            <template v-for="message in datedMessages" :key="message.id">
                                <p v-if="message.day" class="py-1 text-center text-[11px] text-muted-foreground">
                                    {{ message.day }}
                                </p>
                                <article
                                    class="grid max-w-[90%] min-w-0 gap-1"
                                    :class="message.authorId === currentUserId ? 'self-end' : 'self-start'"
                                >
                                    <span
                                        v-if="message.authorId !== currentUserId"
                                        class="px-1 text-[11px] font-medium [overflow-wrap:anywhere] text-muted-foreground"
                                    >
                                        {{ message.authorName }}
                                    </span>
                                    <div
                                        class="min-w-0 rounded-2xl px-3 py-2"
                                        :class="
                                            message.authorId === currentUserId
                                                ? 'rounded-br-sm bg-primary text-primary-foreground'
                                                : 'rounded-bl-sm bg-muted'
                                        "
                                    >
                                        <p class="text-sm leading-relaxed [overflow-wrap:anywhere] whitespace-pre-wrap">
                                            {{ message.body }}
                                        </p>
                                    </div>
                                    <time
                                        :datetime="message.createdAt"
                                        :title="dateFormat.format(new Date(message.createdAt))"
                                        class="px-1 text-[10px] text-muted-foreground"
                                        :class="message.authorId === currentUserId ? 'text-right' : ''"
                                    >
                                        {{ timeFormat.format(new Date(message.createdAt)) }}
                                    </time>
                                </article>
                            </template>
                        </div>
                        <Button
                            v-if="!isNearBottom && messages.length > 0"
                            type="button"
                            variant="outline"
                            size="icon-sm"
                            class="absolute right-4 bottom-3 rounded-full bg-background shadow-md"
                            aria-label="К последним сообщениям"
                            @click="scrollToLatest"
                        >
                            <ArrowDown class="size-4" aria-hidden="true" />
                        </Button>
                    </div>
                    <div
                        v-if="historyError"
                        class="flex items-center justify-between gap-2 border-t px-4 py-2"
                        role="alert"
                    >
                        <p class="text-xs text-destructive">{{ historyError }}</p>
                        <Button type="button" variant="ghost" size="sm" :disabled="loading" @click="refresh">
                            Повторить
                        </Button>
                    </div>
                    <form class="shrink-0 border-t p-3" @submit.prevent="sendMessage">
                        <label :for="`chat-draft-${gameId}`" class="sr-only">Сообщение</label>
                        <div
                            class="flex items-end gap-2 rounded-xl border border-input bg-muted/30 p-2 focus-within:ring-2 focus-within:ring-ring/30"
                        >
                            <textarea
                                :id="`chat-draft-${gameId}`"
                                ref="composer"
                                v-model="form.body"
                                rows="2"
                                maxlength="2000"
                                placeholder="Сообщение…"
                                class="max-h-28 min-h-10 min-w-0 flex-1 resize-none border-0 bg-transparent px-1 py-1 text-base leading-5 outline-none placeholder:text-muted-foreground disabled:opacity-50 sm:text-sm"
                                :disabled="form.processing"
                                :aria-invalid="Boolean(form.errors.body)"
                                :aria-describedby="`chat-error-${gameId}`"
                                @keydown="onComposerKeydown"
                            />
                            <Button
                                type="submit"
                                size="icon-sm"
                                class="shrink-0 rounded-lg"
                                :disabled="form.processing || !form.body.trim()"
                                :aria-label="form.processing ? 'Отправка…' : 'Отправить сообщение'"
                                :title="form.processing ? 'Отправка…' : 'Отправить · Enter'"
                            >
                                <LoaderCircle v-if="form.processing" class="size-4 animate-spin" aria-hidden="true" />
                                <Send v-else class="size-4" aria-hidden="true" />
                            </Button>
                        </div>
                        <div :id="`chat-error-${gameId}`" role="alert" class="mt-1">
                            <InputError :message="form.errors.body || form.errors.client_message_id || sendError" />
                        </div>
                        <div
                            v-if="form.body"
                            class="flex items-center justify-between px-1 pt-1 text-[10px] text-muted-foreground"
                        >
                            <button
                                type="button"
                                class="rounded-sm hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-50"
                                :disabled="form.processing"
                                @click="clearDraft"
                            >
                                Очистить
                            </button>
                            <span>{{ form.body.length }} / 2000</span>
                        </div>
                    </form>
                </template>
            </section>
        </div>
    </Teleport>
</template>
