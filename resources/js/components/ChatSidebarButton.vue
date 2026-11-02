<script setup lang="ts">
import MessageCircle from '@lucide/vue/dist/esm/icons/message-circle.mjs';
import VolumeX from '@lucide/vue/dist/esm/icons/volume-x.mjs';
import { computed, nextTick, ref } from 'vue';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import { useGameChatControls } from '@/composables/useGameChatControls';

const { activeChat } = useGameChatControls();
const { isMobile, setOpenMobile } = useSidebar();
const isOpen = computed(() => activeChat.value?.isOpen.value ?? false);
const soundEnabled = computed(() => activeChat.value?.soundEnabled.value ?? true);
const unreadCount = computed(() => activeChat.value?.unreadCount.value ?? 0);
const suppressTooltip = ref(false);

async function toggleCurrentGameChat(event: MouseEvent): Promise<void> {
    const chat = activeChat.value;
    const trigger = event.currentTarget as HTMLElement;

    if (!chat) {
        return;
    }

    if (isMobile.value) {
        setOpenMobile(false);
        await nextTick();
    }

    if (chat.isOpen.value) {
        await chat.close();

        return;
    }

    await chat.open(async () => {
        if (trigger.isConnected) {
            suppressTooltip.value = true;
            await nextTick();
            trigger.focus({ preventScroll: true });
            suppressTooltip.value = false;
        }
    });
}
</script>

<template>
    <SidebarMenu v-if="activeChat">
        <SidebarMenuItem>
            <SidebarMenuButton
                type="button"
                :tooltip="`${isOpen ? 'Закрыть чат партии' : 'Открыть чат партии'}`"
                :tooltip-disabled="suppressTooltip"
                class="relative"
                :is-active="isOpen"
                :aria-label="
                    isOpen
                        ? 'Закрыть чат партии'
                        : unreadCount
                          ? `Открыть чат партии, новых сообщений: ${unreadCount}`
                          : 'Открыть чат партии'
                "
                :aria-expanded="isOpen"
                :aria-controls="activeChat.panelId"
                :aria-describedby="`${activeChat.panelId}-sound-status`"
                @click="toggleCurrentGameChat"
            >
                <span class="relative size-4 shrink-0" aria-hidden="true">
                    <MessageCircle class="size-4" />
                    <VolumeX
                        v-if="!soundEnabled"
                        class="absolute -right-1.5 -bottom-1 size-3 rounded-full bg-sidebar"
                    />
                </span>
                <span>Чат партии</span>
                <span :id="`${activeChat.panelId}-sound-status`" class="sr-only"></span>
                <span
                    v-if="unreadCount"
                    class="ml-auto rounded-full bg-primary px-1.5 text-[10px] leading-5 text-primary-foreground tabular-nums group-data-[collapsible=icon]:hidden"
                    aria-hidden="true"
                >
                    {{ unreadCount > 99 ? '99+' : unreadCount }}
                </span>
                <span
                    v-if="unreadCount"
                    class="absolute top-1 right-1 hidden size-1.5 rounded-full bg-primary group-data-[collapsible=icon]:block"
                    aria-hidden="true"
                />
            </SidebarMenuButton>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
