<script setup lang="ts">
import { Maximize, Minimize } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';

const isFullscreen = ref(false);
const isFullscreenSupported = ref(false);

function updateFullscreenState(): void {
    isFullscreen.value = document.fullscreenElement !== null;
}

async function toggleFullscreen(): Promise<void> {
    if (!isFullscreenSupported.value) {
        return;
    }

    if (document.fullscreenElement === null) {
        await document.documentElement.requestFullscreen();

        return;
    }

    await document.exitFullscreen();
}

onMounted(() => {
    isFullscreenSupported.value = document.fullscreenEnabled;
    updateFullscreenState();
    document.addEventListener('fullscreenchange', updateFullscreenState);
});

onBeforeUnmount(() => {
    document.removeEventListener('fullscreenchange', updateFullscreenState);
});
</script>

<template>
    <SidebarMenu v-if="isFullscreenSupported">
        <SidebarMenuItem>
            <SidebarMenuButton
                :tooltip="isFullscreen ? 'Выйти из полноэкранного режима' : 'Полноэкранный режим'"
                @click="toggleFullscreen"
            >
                <Minimize v-if="isFullscreen" />
                <Maximize v-else />
                <span>{{ isFullscreen ? 'Выйти из полного экрана' : 'На весь экран' }}</span>
            </SidebarMenuButton>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
