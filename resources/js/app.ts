import { createInertiaApp } from '@inertiajs/vue3';
import { echo } from '@inertiajs/vue3/echo';
import { configureEcho } from '@laravel/echo-vue';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    live: echo({ pauseWhenHidden: false }),
    title: (title) => (title ? `${title} - ${appName}` : appName),
    withApp: (_app, { page }) => {
        configureEcho({
            broadcaster: 'reverb',
            key: page.props.reverb.key,
            wsHost: page.props.reverb.host,
            wsPort: page.props.reverb.port,
            wssPort: page.props.reverb.port,
            forceTLS: page.props.reverb.scheme === 'https',
        });
    },
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
