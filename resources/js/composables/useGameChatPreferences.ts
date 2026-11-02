import { onMounted, ref } from 'vue';

type ChatWindowPosition = { right: number; bottom: number };

export function useGameChatPreferences(userId: number) {
    const storageKey = `game-chat:${userId}:preferences`;
    const soundEnabled = ref(true);
    const windowPosition = ref<ChatWindowPosition | null>(null);

    function savePreferences(): void {
        try {
            window.localStorage.setItem(
                storageKey,
                JSON.stringify({ soundEnabled: soundEnabled.value, windowPosition: windowPosition.value }),
            );
        } catch {
            // Chat remains usable when browser storage is unavailable.
        }
    }

    function toggleSound(): void {
        soundEnabled.value = !soundEnabled.value;
        savePreferences();
    }

    onMounted(() => {
        try {
            const stored: unknown = JSON.parse(window.localStorage.getItem(storageKey) ?? 'null');

            if (!stored || typeof stored !== 'object' || Array.isArray(stored)) {
                return;
            }

            if ('soundEnabled' in stored && typeof stored.soundEnabled === 'boolean') {
                soundEnabled.value = stored.soundEnabled;
            }

            if (!('windowPosition' in stored) || !stored.windowPosition || typeof stored.windowPosition !== 'object') {
                return;
            }

            const position = stored.windowPosition as Partial<ChatWindowPosition>;

            if (
                typeof position.right === 'number' &&
                Number.isFinite(position.right) &&
                position.right >= 0 &&
                typeof position.bottom === 'number' &&
                Number.isFinite(position.bottom) &&
                position.bottom >= 0
            ) {
                windowPosition.value = { right: position.right, bottom: position.bottom };
            }
        } catch {
            // Ignore invalid or inaccessible saved preferences.
        }
    });

    return { soundEnabled, windowPosition, toggleSound, savePreferences };
}
