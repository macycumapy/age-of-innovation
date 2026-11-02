import { createContext } from 'reka-ui';
import type { ComputedRef, Ref, ShallowRef } from 'vue';

export type GameChatControls = {
    panelId: string;
    isOpen: Readonly<Ref<boolean>>;
    soundEnabled: Readonly<Ref<boolean>>;
    unreadCount: ComputedRef<number>;
    open: (onClose?: () => void | Promise<void>) => Promise<void>;
    close: () => Promise<void>;
};

export const [useGameChatControls, provideGameChatControls] = createContext<{
    activeChat: ShallowRef<GameChatControls | null>;
}>('GameChat');
