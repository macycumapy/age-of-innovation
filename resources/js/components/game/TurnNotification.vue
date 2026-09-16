<script setup lang="ts">
import { onBeforeUnmount, onMounted, watch } from 'vue';

const props = defineProps<{
    activePlayerId: number | null;
    currentUserId: number;
    enabled: boolean;
}>();

let audioContext: AudioContext | null = null;

function getAudioContext(): AudioContext {
    audioContext ??= new AudioContext();

    return audioContext;
}

async function unlockAudio(): Promise<void> {
    const context = getAudioContext();

    if (context.state === 'suspended') {
        await context.resume();
    }
}

function handleAudioUnlock(): void {
    void unlockAudio().catch(() => undefined);
}

async function playNotification(): Promise<void> {
    const context = getAudioContext();

    if (context.state === 'suspended') {
        await context.resume();
    }

    if (context.state !== 'running') {
        return;
    }

    const startsAt = context.currentTime;
    const masterGain = context.createGain();
    const echo = context.createDelay();
    const echoFeedback = context.createGain();
    const echoVolume = context.createGain();

    masterGain.gain.setValueAtTime(0.16, startsAt);
    echo.delayTime.setValueAtTime(0.24, startsAt);
    echoFeedback.gain.setValueAtTime(0.22, startsAt);
    echoVolume.gain.setValueAtTime(0.3, startsAt);

    masterGain.connect(context.destination);
    masterGain.connect(echo);
    echo.connect(echoVolume);
    echo.connect(echoFeedback);
    echoFeedback.connect(echo);
    echoVolume.connect(context.destination);

    [659.25, 783.99, 1046.5].forEach((frequency, index) => {
        const noteStartsAt = startsAt + index * 0.17;
        const noteEndsAt = noteStartsAt + 1.35;
        const noteGain = context.createGain();

        noteGain.gain.setValueAtTime(0.0001, noteStartsAt);
        noteGain.gain.exponentialRampToValueAtTime(1, noteStartsAt + 0.015);
        noteGain.gain.exponentialRampToValueAtTime(0.0001, noteEndsAt);
        noteGain.connect(masterGain);

        [
            { ratio: 1, volume: 1 },
            { ratio: 2.01, volume: 0.24 },
            { ratio: 3.97, volume: 0.08 },
        ].forEach(({ ratio, volume }) => {
            const oscillator = context.createOscillator();
            const harmonicGain = context.createGain();

            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(frequency * ratio, noteStartsAt);
            harmonicGain.gain.setValueAtTime(volume, noteStartsAt);
            oscillator.connect(harmonicGain);
            harmonicGain.connect(noteGain);
            oscillator.start(noteStartsAt);
            oscillator.stop(noteEndsAt);
        });
    });
}

watch(
    () => props.activePlayerId,
    (activePlayerId, previousActivePlayerId) => {
        if (props.enabled && activePlayerId === props.currentUserId && previousActivePlayerId !== activePlayerId) {
            void playNotification().catch(() => undefined);
        }
    },
);

onMounted(() => {
    window.addEventListener('pointerdown', handleAudioUnlock, { once: true });
    window.addEventListener('keydown', handleAudioUnlock, { once: true });
});

onBeforeUnmount(() => {
    window.removeEventListener('pointerdown', handleAudioUnlock);
    window.removeEventListener('keydown', handleAudioUnlock);
    void audioContext?.close();
});
</script>

<template>
    <span hidden aria-hidden="true" />
</template>
