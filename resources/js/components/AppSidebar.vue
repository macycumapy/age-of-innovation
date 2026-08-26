<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Gamepad2, LayoutGrid } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
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
import { dashboard } from '@/routes';
import { index as gamesIndex } from '@/routes/games';
import type { NavItem } from '@/types';

const { isMobile, openMobile } = useSidebar();

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Игры',
        href: gamesIndex(),
        icon: Gamepad2,
    },
];

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader class="group-data-[collapsible=icon]:p-1">
            <SidebarMenu>
                <SidebarMenuItem class="flex items-center gap-1">
                    <SidebarMenuButton
                        size="lg"
                        class="flex-1 group-data-[collapsible=icon]:hidden"
                        as-child
                    >
                        <Link :href="dashboard()">
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
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <SidebarTrigger
        v-if="isMobile && !openMobile"
        class="fixed top-2 left-2 z-50 bg-background shadow-sm"
    />
    <slot />
</template>
