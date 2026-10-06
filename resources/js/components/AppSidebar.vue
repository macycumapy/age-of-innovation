<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import Activity from "@lucide/vue/dist/esm/icons/activity.mjs";
import Gamepad2 from "@lucide/vue/dist/esm/icons/gamepad-2.mjs";
import Shield from "@lucide/vue/dist/esm/icons/shield.mjs";
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import FullscreenSidebarButton from '@/components/FullscreenSidebarButton.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarTrigger,
    useSidebar,
} from '@/components/ui/sidebar';
import { index as adminIndex } from '@/routes/admin';
import { index as gamesIndex } from '@/routes/games';
import type { NavItem } from '@/types';

const { isMobile, openMobile } = useSidebar();

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Игры',
        href: gamesIndex(),
        icon: Gamepad2,
    },
    ...(page.props.auth.canAccessAdmin ? [{ title: 'Админка', href: adminIndex(), icon: Shield }] : []),
    ...(page.props.auth.canAccessAdmin && page.props.horizonUrl
        ? [{ title: 'Horizon', href: page.props.horizonUrl, icon: Activity, external: true }]
        : []),
]);

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader class="group-data-[collapsible=icon]:p-1">
            <SidebarMenu>
                <SidebarMenuItem class="flex items-center gap-1">
                    <SidebarMenuButton size="lg" class="flex-1 group-data-[collapsible=icon]:hidden" as-child>
                        <Link :href="gamesIndex()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                    <SidebarTrigger class="shrink-0" />
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter class="group-data-[collapsible=icon]:p-1">
            <NavFooter :items="footerNavItems" />
            <FullscreenSidebarButton />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <SidebarTrigger v-if="isMobile && !openMobile" class="fixed top-2 left-2 z-50 bg-background shadow-sm" />
    <slot />
</template>
