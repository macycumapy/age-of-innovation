import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
import * as Vue from 'vue';

const source = readFileSync(new URL('../../resources/js/composables/useChatMessageSound.ts', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
});
const soundAsset = readFileSync(new URL('../../resources/audio/icq-message.wav', import.meta.url));
const sampleData = soundAsset.buffer.slice(soundAsset.byteOffset, soundAsset.byteOffset + soundAsset.byteLength);
const soundUrl = '/build/assets/icq-message.wav';

function harness(t, options = {}) {
    const previousWindow = globalThis.window;
    const previousAudioContext = globalThis.AudioContext;
    const previousFetch = globalThis.fetch;
    const listeners = new Map();
    const captureListeners = new Map();
    const mounted = [];
    const unmounted = [];
    const contexts = [];
    const requests = [];
    const decodedBuffer = { duration: 0.45 };
    let userActivated = options.userActivated ?? false;
    globalThis.window = {
        addEventListener: (event, callback, capture) => {
            listeners.set(event, callback);
            captureListeners.set(event, capture === true);
        },
        removeEventListener: (event, callback, capture) => {
            if (listeners.get(event) === callback && captureListeners.get(event) === (capture === true)) {
                listeners.delete(event);
                captureListeners.delete(event);
            }
        },
    };
    globalThis.fetch = async (url, init) => {
        requests.push({ url, ...init });

        return options.fetch ? options.fetch(url, init) : { ok: true, arrayBuffer: async () => sampleData };
    };

    function node() {
        return {
            connections: [],
            disconnected: false,
            connect(target) {
                this.connections.push(target);
            },
            disconnect() {
                this.disconnected = true;
            },
        };
    }

    globalThis.AudioContext = class AudioContext {
        state = options.initialState ?? (userActivated ? 'running' : 'suspended');
        currentTime = 10;
        destination = node();
        sources = [];
        gains = [];
        decoded = [];
        resumeCalls = 0;
        closed = false;

        constructor() {
            contexts.push(this);
        }

        async resume() {
            this.resumeCalls++;

            if (!userActivated) {
                throw new Error('NotAllowedError: user interaction required');
            }

            await options.resume?.(this);
            this.state = 'running';
        }

        async close() {
            this.closed = true;
            this.state = 'closed';
        }

        async decodeAudioData(data) {
            this.decoded.push(data);

            return options.decode ? options.decode(data) : decodedBuffer;
        }

        createBufferSource() {
            const source = {
                ...node(),
                start() {
                    this.started = true;
                },
                stop() {
                    this.stopped = true;
                    this.onended?.();
                },
            };
            this.sources.push(source);

            return source;
        }

        createGain() {
            const volume = {
                ...node(),
                gain: {
                    setValueAtTime(value, time) {
                        this.value = value;
                        this.time = time;
                    },
                },
            };
            this.gains.push(volume);

            return volume;
        }
    };

    if (options.unsupported) {
        globalThis.AudioContext = undefined;
    }

    const module = { exports: {} };
    new Function('require', 'module', 'exports', outputText)(
        (name) => {
            if (name === '../../audio/icq-message.wav') {
                return { default: soundUrl };
            }

            assert.equal(name, 'vue');

            return {
                ...Vue,
                onMounted: (callback) => mounted.push(callback),
                onUnmounted: (callback) => unmounted.push(callback),
            };
        },
        module,
        module.exports,
    );
    const scope = Vue.effectScope();
    const enabled = Vue.ref(options.enabled ?? true);
    const sound = scope.run(() => module.exports.useChatMessageSound(enabled));
    mounted.forEach((callback) => callback());
    let disposed = false;
    const unmount = () => {
        if (!disposed) {
            disposed = true;
            unmounted.forEach((callback) => callback());
            scope.stop();
        }
    };
    t.after(() => {
        unmount();
        globalThis.window = previousWindow;
        globalThis.AudioContext = previousAudioContext;
        globalThis.fetch = previousFetch;
    });

    async function interact(type, { stopPropagation = false, pointerType = 'mouse' } = {}) {
        if (
            type === 'keydown' ||
            type === 'click' ||
            (type === 'pointerdown' && pointerType === 'mouse') ||
            (type === 'pointerup' && pointerType !== 'mouse')
        ) {
            userActivated = true;
        }

        if (!stopPropagation || captureListeners.get(type)) {
            await listeners.get(type)?.({ type, pointerType });
        }
    }

    return { sound, contexts, listeners, requests, decodedBuffer, unmount, interact, enabled };
}

test('the bundled ICQ recording is a short uncompressed PCM WAV', () => {
    assert.equal(soundAsset.toString('ascii', 0, 4), 'RIFF');
    assert.equal(soundAsset.toString('ascii', 8, 16), 'WAVEfmt ');
    assert.equal(soundAsset.readUInt32LE(4), soundAsset.length - 8);
    assert.equal(soundAsset.readUInt16LE(20), 1);
    assert.equal(soundAsset.readUInt16LE(22), 1);
    assert.equal(soundAsset.readUInt32LE(24), 22050);
    assert.equal(soundAsset.readUInt16LE(34), 8);
    const duration = soundAsset.readUInt32LE(40) / soundAsset.readUInt32LE(28);
    assert.ok(duration > 0.4 && duration < 1);
    assert.ok(soundAsset.subarray(44).some((sample) => Math.abs(sample - 128) > 50));
});

test('sound waits for interaction, preloads the recording once, and plays it at half gain', async (t) => {
    const { sound, contexts, listeners, requests, decodedBuffer, interact } = harness(t);
    await sound.play();
    assert.equal(contexts.length, 1);
    assert.equal(contexts[0].sources.length, 0);
    assert.equal(requests.length, 0);
    await interact('pointerdown');
    const [context] = contexts;
    assert.equal(context.sources.length, 0);
    assert.equal(requests[0].url, soundUrl);
    assert.equal(listeners.size, 0);
    await sound.play();
    await sound.play();
    assert.equal(requests.length, 1);
    assert.deepEqual(context.decoded, [sampleData]);
    assert.equal(context.sources.length, 2);
    assert.ok(context.sources.every((source) => source.started && source.buffer === decodedBuffer));
    assert.ok(context.sources.every((source, index) => source.connections.includes(context.gains[index])));
    assert.ok(context.gains.every((volume) => volume.gain.value === 0.5 && volume.gain.time === context.currentTime));
    assert.ok(context.gains.every((volume) => volume.connections.includes(context.destination)));
    context.sources.forEach((source) => source.onended());
    assert.ok(context.sources.every((source) => source.disconnected));
    assert.ok(context.gains.every((volume) => volume.disconnected));
});

test('keyboard interaction unlocks audio and leaving the page closes it and removes listeners', async (t) => {
    const { sound, contexts, listeners, unmount, interact } = harness(t);
    await interact('keydown');
    const [context] = contexts;
    assert.equal(context.resumeCalls, 2);
    await sound.play();
    assert.equal(context.sources.length, 1);
    unmount();
    assert.equal(context.closed, true);
    assert.equal(listeners.size, 0);
    await sound.play();
    assert.equal(context.sources.length, 1);
});

test('a browser audio refusal is harmless and can be retried on another gesture', async (t) => {
    let blocked = true;
    const { sound, contexts, listeners, interact } = harness(t, {
        initialState: 'suspended',
        resume: () => {
            if (blocked) {
                throw new Error('NotAllowedError');
            }
        },
    });
    await interact('pointerdown');
    await sound.play();
    assert.equal(contexts[0].sources.length, 0);
    assert.equal(listeners.size, 4);
    blocked = false;
    await interact('keydown');
    await sound.play();
    assert.equal(contexts.length, 1);
    assert.equal(contexts[0].sources.length, 1);
});

test('unsupported audio and leaving before interaction do not break the chat', async (t) => {
    const { sound, contexts, listeners, unmount } = harness(t, { unsupported: true });
    const unlock = listeners.get('pointerdown');
    await unlock();
    await sound.play();
    unmount();
    assert.equal(listeners.size, 0);
    await unlock();
    assert.equal(contexts.length, 0);
});

test('leaving while suspended audio resumes cannot start a late notification', async (t) => {
    let finishResume;
    const { sound, contexts, unmount } = harness(t, {
        userActivated: true,
        resume: () => new Promise((resolve) => {
            finishResume = resolve;
        }),
    });
    const [context] = contexts;
    context.state = 'suspended';
    const playing = sound.play();
    unmount();
    finishResume();
    await playing;
    assert.equal(context.sources.length, 0);
    assert.equal(context.closed, true);
});

test('a failed sound download can be retried without breaking message handling', async (t) => {
    let unavailable = true;
    const { sound, contexts, requests, interact } = harness(t, {
        fetch: () => ({ ok: !unavailable, arrayBuffer: async () => sampleData }),
    });
    await interact('pointerdown');
    await sound.play();
    assert.equal(contexts[0].sources.length, 0);
    unavailable = false;
    await sound.play();
    assert.equal(contexts[0].sources.length, 1);
    assert.equal(requests.length, 3);
});

test('an audio decoding failure is harmless and retries loading on the next notification', async (t) => {
    let unsupported = true;
    const { sound, contexts, interact } = harness(t, {
        decode: () => {
            if (unsupported) {
                throw new Error('EncodingError');
            }

            return { duration: 0.45 };
        },
    });
    await interact('keydown');
    await sound.play();
    assert.equal(contexts[0].sources.length, 0);
    unsupported = false;
    await sound.play();
    assert.equal(contexts[0].sources.length, 1);
});

test('leaving during sample loading cancels the request and prevents late playback', async (t) => {
    let finishFetch;
    const { sound, contexts, requests, unmount } = harness(t, {
        userActivated: true,
        fetch: () => new Promise((resolve) => {
            finishFetch = resolve;
        }),
    });
    const playing = sound.play();
    assert.equal(requests.length, 1);
    unmount();
    assert.equal(requests[0].signal.aborted, true);
    finishFetch({ ok: true, arrayBuffer: async () => sampleData });
    await playing;
    assert.equal(contexts[0].sources.length, 0);
    assert.equal(contexts[0].closed, true);
});

test('prior interaction before navigating to a game enables sound without opening its chat', async (t) => {
    const { sound, contexts, requests, listeners } = harness(t, { userActivated: true });
    await sound.play();
    assert.equal(contexts.length, 1);
    assert.equal(requests.length, 1);
    assert.equal(contexts[0].sources.length, 1);
    assert.equal(listeners.size, 0);
});

for (const type of ['pointerdown', 'click', 'keydown']) {
    test(`a ${type} on the game unlocks sound even when its handler stops propagation`, async (t) => {
        const { sound, contexts, interact } = harness(t);
        await interact(type, { stopPropagation: true });
        assert.equal(contexts[0].sources.length, 0);
        await sound.play();
        assert.equal(contexts[0].sources.length, 1);
    });
}

test('touch release unlocks sound without opening the chat or replaying earlier notifications', async (t) => {
    const { sound, contexts, listeners, interact } = harness(t);
    await interact('pointerdown', { pointerType: 'touch', stopPropagation: true });
    await sound.play();
    assert.equal(contexts[0].sources.length, 0);
    assert.equal(listeners.size, 4);
    await interact('pointerup', { pointerType: 'touch', stopPropagation: true });
    assert.equal(contexts[0].sources.length, 0);
    await sound.play();
    assert.equal(contexts[0].sources.length, 1);
    assert.equal(listeners.size, 0);
});

test('leaving before audio is unlocked removes all capture listeners and closes the context', async (t) => {
    const { contexts, listeners, unmount, interact } = harness(t);
    assert.equal(listeners.size, 4);
    unmount();
    assert.equal(listeners.size, 0);
    assert.equal(contexts[0].closed, true);
    await interact('click');
    assert.equal(contexts[0].sources.length, 0);
});

test('muting stops the current notification and suppresses new sounds until enabled again', async (t) => {
    const { sound, contexts, interact, enabled } = harness(t);
    await interact('click');
    await sound.play();
    const [context] = contexts;
    enabled.value = false;
    assert.equal(context.sources[0].stopped, true);
    await sound.play();
    assert.equal(context.sources.length, 1);
    enabled.value = true;
    await sound.play();
    assert.equal(context.sources.length, 2);
});

test('a notification loading while sound is muted cannot start late', async (t) => {
    let finishFetch;
    const { sound, contexts, enabled } = harness(t, {
        userActivated: true,
        fetch: () => new Promise((resolve) => {
            finishFetch = resolve;
        }),
    });
    const playing = sound.play();
    enabled.value = false;
    finishFetch({ ok: true, arrayBuffer: async () => sampleData });
    await playing;
    assert.equal(contexts[0].sources.length, 0);
    enabled.value = true;
    await sound.play();
    assert.equal(contexts[0].sources.length, 1);
});
