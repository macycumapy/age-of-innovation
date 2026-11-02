import { onMounted, onUnmounted, toValue, watch } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import messageSoundUrl from '../../audio/icq-message.wav';

// ICQ recording preserved in https://www.adiumxtras.com/index.php?a=xtras&xtra_id=569.

export function useChatMessageSound(enabled: MaybeRefOrGetter<boolean> = true) {
    let audioContext: AudioContext | null = null;
    let soundBuffer: Promise<AudioBuffer> | null = null;
    let loadController: AbortController | null = null;
    let unlocked = false;
    let disposed = false;
    const unlockEvents = ['pointerdown', 'pointerup', 'click', 'keydown'] as const;
    const activeSources = new Set<AudioBufferSourceNode>();

    function stopSounds(): void {
        activeSources.forEach((source) => source.stop());
        activeSources.clear();
    }

    watch(
        () => toValue(enabled),
        (enabled) => {
            if (!enabled) {
                stopSounds();
            }
        },
        { flush: 'sync' },
    );

    function loadSound(context: AudioContext): Promise<AudioBuffer> {
        if (!soundBuffer) {
            loadController = new AbortController();
            soundBuffer = fetch(messageSoundUrl, { signal: loadController.signal })
                .then(async (response) => {
                    if (!response.ok) {
                        throw new Error('Unable to load chat sound');
                    }

                    return context.decodeAudioData(await response.arrayBuffer());
                })
                .catch((error) => {
                    soundBuffer = null;

                    throw error;
                });
        }

        return soundBuffer;
    }

    function removeUnlockListeners(): void {
        unlockEvents.forEach((event) => window.removeEventListener(event, unlockAudio, true));
    }

    async function unlockAudio(): Promise<void> {
        if (disposed) {
            return;
        }

        try {
            audioContext ??= new AudioContext();

            if (audioContext.state === 'suspended') {
                await audioContext.resume();
            }

            if (!disposed && audioContext.state === 'running') {
                unlocked = true;
                removeUnlockListeners();
                await loadSound(audioContext);
            }
        } catch {
            // Browsers can refuse audio until a later user interaction.
        }
    }

    async function play(): Promise<void> {
        const context = audioContext;

        if (!context || !unlocked || disposed || !toValue(enabled)) {
            return;
        }

        try {
            if (context.state === 'suspended') {
                await context.resume();
            }

            if (disposed || context.state !== 'running' || !toValue(enabled)) {
                return;
            }

            const buffer = await loadSound(context);

            if (disposed || context.state !== 'running' || !toValue(enabled)) {
                return;
            }

            const source = context.createBufferSource();
            const volume = context.createGain();
            source.buffer = buffer;
            volume.gain.setValueAtTime(0.5, context.currentTime);
            source.connect(volume);
            volume.connect(context.destination);
            source.onended = () => {
                activeSources.delete(source);
                source.disconnect();
                volume.disconnect();
            };
            source.start();
            activeSources.add(source);
        } catch {
            // Sound must never interrupt receiving messages.
        }
    }

    onMounted(() => {
        unlockEvents.forEach((event) => window.addEventListener(event, unlockAudio, true));
        void unlockAudio();
    });

    onUnmounted(() => {
        disposed = true;
        stopSounds();
        removeUnlockListeners();
        loadController?.abort();
        void audioContext?.close().catch(() => undefined);
    });

    return { play };
}
