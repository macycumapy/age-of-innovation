import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { setImmediate } from 'node:timers/promises';
import { HttpResponseError } from '@inertiajs/core';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as Vue from 'vue';

const source = readFileSync(new URL('../../resources/js/components/game/GameChat.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const compiled = compileScript(descriptor, { id: 'game-chat-test', inlineTemplate: true });
const { outputText } = ts.transpileModule(compiled.content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
});
const require = createRequire(import.meta.url);
const contextSource = readFileSync(
    new URL('../../resources/js/composables/useGameChatControls.ts', import.meta.url),
    'utf8',
);
const contextModule = { exports: {} };
const contextCode = ts.transpileModule(contextSource, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
new Function('require', 'module', 'exports', contextCode)(require, contextModule, contextModule.exports);
const chatContext = contextModule.exports;

function compileComponent(path, load) {
    const source = readFileSync(new URL(path, import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    const compiled = compileScript(descriptor, { id: path, inlineTemplate: true });
    const code = ts.transpileModule(compiled.content, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    const module = { exports: {} };
    new Function('require', 'module', 'exports', code)(load, module, module.exports);

    return module.exports.default;
}

function loadComposable(path, load) {
    const source = readFileSync(new URL(path, import.meta.url), 'utf8');
    const code = ts.transpileModule(source, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
    const module = { exports: {} };
    new Function('require', 'module', 'exports', code)(load, module, module.exports);

    return module.exports;
}

const previousDocument = globalThis.Document;
const previousShadowRoot = globalThis.ShadowRoot;

test.before(() => {
    globalThis.Document = class Document {};
    globalThis.ShadowRoot = class ShadowRoot {};
});

test.after(() => {
    globalThis.Document = previousDocument;
    globalThis.ShadowRoot = previousShadowRoot;
});

function message(id, overrides = {}) {
    return {
        id,
        gameId: 1,
        authorId: 3,
        authorName: 'Анна',
        body: `Сообщение ${id}`,
        clientMessageId: `uuid-${id}`,
        createdAt: '2026-11-02T10:00:00Z',
        ...overrides,
    };
}

function createStorage(initial = {}) {
    const values = new Map(Object.entries(initial));
    const writes = [];

    return {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => {
            writes.push({ key, value });
            values.set(key, value);
        },
        writes,
    };
}

function mountChat(overrides = {}, options = {}) {
    const sent = [];
    const sounds = [];
    let receiveMessage;
    const controls = { activeChat: Vue.shallowRef(null) };
    const showChat = Vue.ref(options.showChat ?? true);
    const gameId = Vue.ref(1);
    const sidebar = {
        state: Vue.ref(options.sidebarExpanded ? 'expanded' : 'collapsed'),
        isMobile: Vue.ref(options.isMobile ?? false),
        openMobile: Vue.ref(options.isMobile ?? false),
        setOpenMobile: (open) => {
            sidebar.openMobile.value = open;
        },
    };
    const previousWindow = globalThis.window;
    const listeners = new Map();
    const viewport = {
        localStorage: options.storage ?? createStorage(),
        innerWidth: 1280,
        innerHeight: 900,
        addEventListener: (event, callback) => listeners.set(event, callback),
        removeEventListener: (event) => listeners.delete(event),
    };
    globalThis.window = viewport;
    const state = {
        messages: Vue.ref([]),
        hasMore: Vue.ref(false),
        loaded: Vue.ref(true),
        historyError: Vue.ref(''),
        sendError: Vue.ref(''),
        accessDenied: Vue.ref(false),
        connectionStatus: Vue.ref('connected'),
        loading: Vue.ref(false),
        form: Vue.reactive({ body: '', processing: false, errors: {} }),
        loadHistory() {},
        refresh() {},
        send() {
            sent.push(state.form.body);
            state.form.body = '';
        },
        clearDraft() {
            state.form.body = '';
        },
        ...overrides,
    };
    let focused = null;
    const focusEvents = [];

    function pixels(value) {
        return String(value)
            .replace(/^calc\(|\)$/g, '')
            .split('+')
            .reduce((total, part) => total + Number.parseFloat(part) * (part.trim().endsWith('rem') ? 16 : 1), 0);
    }

    function node(tag, value = '') {
        const capturedPointers = new Set();
        const element = Vue.markRaw({
            tag,
            tagName: tag.toUpperCase(),
            text: value,
            children: [],
            props: {},
            parent: null,
            value: '',
            scrollHeight: 600,
            clientHeight: 200,
            scrollTop: 0,
            addEventListener() {},
            removeEventListener() {},
            getRootNode: () => ({ activeElement: focused }),
            get isConnected() {
                let current = element;

                while (current.parent) {
                    current = current.parent;
                }

                return current === root || current === body;
            },
            setPointerCapture: (pointerId) => capturedPointers.add(pointerId),
            hasPointerCapture: (pointerId) => capturedPointers.has(pointerId),
            releasePointerCapture: (pointerId) => capturedPointers.delete(pointerId),
            getBoundingClientRect: () => {
                const open = element.children.some((child) => child.tag === 'section');
                const width = open ? Math.min(384, viewport.innerWidth - 24) : 100;
                const height = open ? Math.min(480, viewport.innerHeight - 32) : 44;
                const style = element.props.style ?? {};
                const right =
                    style.left && style.left !== 'auto'
                        ? pixels(style.left) + width
                        : viewport.innerWidth - pixels(style.right ?? '20');
                const bottom = viewport.innerHeight - Number.parseFloat(element.props.style?.bottom ?? '20');

                return { left: right - width, top: bottom - height, right, bottom, width, height };
            },
            focus: () => {
                focused = element;
                focusEvents.push({ element, tooltipDisabled: Boolean(element.props['tooltip-disabled']) });
            },
        });

        return element;
    }

    const root = node('root');
    const body = node('body');
    const renderer = Vue.createRenderer({
        createElement: (tag) => node(tag),
        createText: (value) => node('#text', value),
        createComment: (value) => node('#comment', value),
        setText: (target, value) => {
            target.text = value;
        },
        setElementText: (target, value) => {
            target.text = value;
            target.children = [];
        },
        patchProp: (target, key, _previous, value) => {
            target.props[key] = value;
        },
        insert: (target, parent, anchor = null) => {
            if (target.parent) {
                target.parent.children.splice(target.parent.children.indexOf(target), 1);
            }

            const index = anchor ? parent.children.indexOf(anchor) : -1;
            parent.children.splice(index < 0 ? parent.children.length : index, 0, target);
            target.parent = parent;
        },
        remove: (target) => {
            target.parent?.children.splice(target.parent.children.indexOf(target), 1);
            target.parent = null;
        },
        parentNode: (target) => target.parent,
        nextSibling: (target) => target.parent?.children[target.parent.children.indexOf(target) + 1] ?? null,
        querySelector: (selector) => (selector === 'body' ? body : null),
    });
    let sidebarButton;
    const load = (name) => {
        if (name === '@/composables/useGameChat') {
            if (options.useGameChat) {
                return { useGameChat: options.useGameChat };
            }

            return {
                useGameChat: (_gameId, _gamePlayerId, onMessageReceived) => {
                    receiveMessage = onMessageReceived;

                    return state;
                },
            };
        }

        if (name === '@/composables/useChatMessageSound') {
            return {
                useChatMessageSound: options.useChatMessageSound ?? ((enabled) => ({
                    play: () => {
                        if (Vue.toValue(enabled)) {
                            sounds.push('message');
                        }
                    },
                })),
            };
        }

        if (name === '@/composables/useGameChatPreferences') {
            return loadComposable('../../resources/js/composables/useGameChatPreferences.ts', require);
        }

        if (name === '@/composables/useGameChatControls') {
            return chatContext;
        }

        if (name === '@/components/ui/sidebar/utils') {
            return { SIDEBAR_WIDTH: '16rem', SIDEBAR_WIDTH_ICON: '2.5rem' };
        }

        if (name === '@/components/ChatSidebarButton.vue') {
            return { default: sidebarButton, __esModule: true };
        }

        if (name === '@/components/ui/sidebar') {
            const passthrough = (_props, { slots, attrs }) => Vue.h('div', attrs, slots.default?.());

            return {
                useSidebar: () => sidebar,
                Sidebar: passthrough,
                SidebarContent: passthrough,
                SidebarFooter: passthrough,
                SidebarHeader: passthrough,
                SidebarMenu: passthrough,
                SidebarMenuItem: passthrough,
                SidebarMenuButton: (_props, { slots, attrs }) => Vue.h('button', attrs, slots.default?.()),
                SidebarTrigger: () => Vue.h('button', { 'aria-label': 'Переключить панель' }),
            };
        }

        if (name === '@inertiajs/vue3') {
            return { usePage: () => ({ props: { auth: { canAccessAdmin: false } } }), Link: () => Vue.h('a') };
        }

        if (name === '@/routes/admin' || name === '@/routes/games') {
            return { index: () => ({ url: '/games', method: 'get' }) };
        }

        if (name === '@/components/FullscreenSidebarButton.vue') {
            return { default: () => Vue.h('button', { 'aria-label': 'На весь экран' }), __esModule: true };
        }

        if (/^@\/components\/(AppLogo|NavFooter|NavMain|NavUser)\.vue$/.test(name)) {
            return { default: () => Vue.h('div'), __esModule: true };
        }

        if (name === '@/components/ui/button') {
            return { Button: (_props, { slots, attrs }) => Vue.h('button', attrs, slots.default?.()) };
        }

        if (name === '@/components/InputError.vue') {
            return { default: (props) => (props.message ? Vue.h('p', props.message) : null), __esModule: true };
        }

        if (name.startsWith('@lucide/vue/')) {
            return {
                default: () => Vue.h('svg', { 'data-icon': name.split('/').at(-1).replace('.mjs', '') }),
                __esModule: true,
            };
        }

        return require(name);
    };
    const module = { exports: {} };
    new Function('require', 'module', 'exports', outputText)(load, module, module.exports);
    sidebarButton = compileComponent('../../resources/js/components/ChatSidebarButton.vue', load);
    const appSidebar = options.sidebar ? compileComponent('../../resources/js/components/AppSidebar.vue', load) : null;
    const app = renderer.createApp({
        setup() {
            chatContext.provideGameChatControls(controls);

            return () => [
                appSidebar ? Vue.h(appSidebar) : null,
                showChat.value
                    ? Vue.h(module.exports.default, {
                          key: gameId.value,
                          gameId: gameId.value,
                          gamePlayerId: 4,
                          currentUserId: options.currentUserId ?? 2,
                      })
                    : null,
            ];
        },
    });
    app.mount(root);
    let disposed = false;

    function all(predicate, target = body) {
        return [target, ...target.children.flatMap((child) => all(() => true, child))].filter(predicate);
    }

    function find(predicate) {
        const found = all(predicate)[0] ?? all(predicate, root)[0];
        assert.ok(found, 'Expected chat control was not rendered');

        return found;
    }

    function text(target = body) {
        return (target.tag !== '#comment' ? target.text : '') + target.children.map((child) => text(child)).join('');
    }

    async function click(element) {
        await element.props.onClick({ currentTarget: element });
        await Vue.nextTick();
    }

    async function open() {
        if (options.sidebar) {
            await Vue.nextTick();
            await click(find((entry) => String(entry.props['aria-label']).startsWith('Открыть чат партии')));
        } else {
            await controls.activeChat.value.open();
            await Vue.nextTick();
        }
    }

    async function close() {
        await find((entry) => entry.props['aria-label'] === 'Свернуть чат').props.onClick();
        await Vue.nextTick();
    }

    return {
        state,
        sent,
        sounds,
        receiveMessage: (message) => receiveMessage(message),
        root,
        body,
        all,
        find,
        text,
        open,
        close,
        click,
        viewport,
        listeners,
        controls,
        showChat,
        gameId,
        sidebar,
        resize: async (width, height) => {
            viewport.innerWidth = width;
            viewport.innerHeight = height;
            listeners.get('resize')?.();
            await Vue.nextTick();
        },
        unmount: () => {
            if (disposed) {
                return;
            }

            disposed = true;
            app.unmount();
            globalThis.window = previousWindow;
        },
        focused: () => focused,
        focusEvents,
    };
}

function keyEvent(key, overrides = {}) {
    return {
        key,
        shiftKey: false,
        isComposing: false,
        prevented: false,
        stopped: false,
        preventDefault() {
            this.prevented = true;
        },
        stopPropagation() {
            this.stopped = true;
        },
        ...overrides,
    };
}

function pointerEvent(handle, overrides = {}) {
    return {
        pointerId: 1,
        pointerType: 'mouse',
        isPrimary: true,
        button: 0,
        clientX: 200,
        clientY: 450,
        currentTarget: handle,
        prevented: false,
        preventDefault() {
            this.prevented = true;
        },
        ...overrides,
    };
}

test('collapsed chat has no floating elements and opens outside the game without a modal overlay', async (t) => {
    const chat = mountChat();
    t.after(chat.unmount);
    assert.equal(chat.controls.activeChat.value.isOpen.value, false);
    assert.equal(chat.all((entry) => ['div', 'button', 'section'].includes(entry.tag)).length, 0);
    assert.equal(chat.all((entry) => entry.tag === 'textarea').length, 0);
    assert.equal(
        chat.root.children.some((entry) => entry.tag === 'div'),
        false,
    );
    await chat.open();
    const panel = chat.find((entry) => entry.props.role === 'dialog');
    assert.equal(panel.props['aria-modal'], 'false');
    assert.equal(panel.props['aria-labelledby'], 'game-chat-1-title');
    assert.match(chat.text(), /Чат партии/);
    assert.match(chat.text(), /Пока тихо/);
    assert.equal(chat.focused().tag, 'textarea');
});

test('incoming messages from other players play a sound in both collapsed and open chat', async (t) => {
    const chat = mountChat();
    t.after(chat.unmount);
    chat.receiveMessage(message(1));
    assert.equal(chat.sounds.length, 1);
    chat.receiveMessage(message(2, { authorId: 2 }));
    assert.equal(chat.sounds.length, 1);
    await chat.open();
    chat.receiveMessage(message(3));
    assert.equal(chat.sounds.length, 2);
    chat.receiveMessage(message(4, { authorId: 2 }));
    assert.equal(chat.sounds.length, 2);
});

test('a never-opened chat receives realtime messages and plays the actual sound composable', async (t) => {
    const previousAudioContext = globalThis.AudioContext;
    const previousFetch = globalThis.fetch;
    const previousDocument = globalThis.document;
    const played = [];
    const volumes = [];
    globalThis.document = { addEventListener() {}, removeEventListener() {} };
    globalThis.fetch = async () => ({ ok: true, arrayBuffer: async () => new ArrayBuffer(8) });
    globalThis.AudioContext = class {
        state = 'running';
        currentTime = 0;

        async decodeAudioData() {
            return { duration: 0.45 };
        }

        createBufferSource() {
            return { connect() {}, disconnect() {}, stop() {}, start: () => played.push('icq') };
        }

        createGain() {
            return {
                gain: { setValueAtTime: (value) => volumes.push(value) },
                connect() {},
                disconnect() {},
            };
        }

        async close() {}
    };
    let receive;
    let requestIndex = 0;
    const dependencies = (name) => {
        if (name === '@inertiajs/core') {
            return { HttpResponseError };
        }

        if (name === '../../audio/icq-message.wav') {
            return { default: '/build/assets/icq-message.wav' };
        }

        if (name === '@inertiajs/vue3') {
            return {
                useHttp: (fields) => {
                    const index = requestIndex++;

                    return Vue.reactive({
                        ...fields,
                        processing: false,
                        errors: {},
                        cancel() {},
                        get: async () => ({ data: index === 1 ? [message(1)] : [], hasMore: false }),
                    });
                },
            };
        }

        if (name === '@laravel/echo-vue') {
            return {
                useConnectionStatus: () => Vue.ref('connected'),
                useEcho: (_channel, _event, callback) => {
                    receive = callback;

                    return { channel: () => ({ subscribed() {} }), leaveChannel() {} };
                },
            };
        }

        if (name === '@/actions/App/Http/Controllers/Chat/ChatMessageController') {
            return { index: { url: () => '/games/1/chat/messages' } };
        }

        return require(name);
    };
    const { useGameChat } = loadComposable('../../resources/js/composables/useGameChat.ts', dependencies);
    const { useChatMessageSound } = loadComposable('../../resources/js/composables/useChatMessageSound.ts', dependencies);
    const chat = mountChat({}, { sidebar: true, useGameChat, useChatMessageSound });
    t.after(() => {
        chat.unmount();
        globalThis.AudioContext = previousAudioContext;
        globalThis.fetch = previousFetch;
        globalThis.document = previousDocument;
    });
    await setImmediate();
    assert.equal(chat.controls.activeChat.value.isOpen.value, false);
    assert.equal(chat.all((entry) => entry.props.role === 'dialog').length, 0);
    assert.deepEqual(played, []);
    receive({ message: message(2) });
    await setImmediate();
    assert.deepEqual(played, ['icq']);
    assert.deepEqual(volumes, [0.5]);
    assert.equal(chat.controls.activeChat.value.unreadCount.value, 1);
    receive({ message: message(2) });
    receive({ message: message(3, { authorId: 2 }) });
    await setImmediate();
    assert.deepEqual(played, ['icq']);
    assert.equal(chat.controls.activeChat.value.isOpen.value, false);
});

test('chat initially opens at the bottom left beside the sidebar', async (t) => {
    const chat = mountChat({}, { sidebar: true });
    t.after(chat.unmount);
    await chat.open();
    const container = chat.find((entry) => entry.props.role === 'dialog').parent;
    assert.equal(container.getBoundingClientRect().left, 76);
    assert.equal(container.getBoundingClientRect().bottom, chat.viewport.innerHeight - 20);
    chat.sidebar.state.value = 'expanded';
    await Vue.nextTick();
    assert.equal(container.getBoundingClientRect().left, 276);
    chat.sidebar.isMobile.value = true;
    await Vue.nextTick();
    assert.equal(container.getBoundingClientRect().left, 12);
});

test('sound preference and dragged position survive a page reload while the chat stays initially collapsed', async (t) => {
    const storage = createStorage();
    const first = mountChat({}, { storage });
    t.after(first.unmount);
    await first.open();
    const mute = first.find((entry) => entry.props['aria-label'] === 'Отключить звук чата');
    assert.equal(mute.props['aria-pressed'], false);
    await first.click(mute);
    assert.equal(mute.props['aria-label'], 'Включить звук чата');
    assert.equal(mute.props['aria-pressed'], true);
    first.receiveMessage(message(1));
    assert.deepEqual(first.sounds, []);
    const handle = first.find((entry) => entry.props['aria-label'] === 'Переместить окно чата');
    handle.props.onPointerdown(pointerEvent(handle));
    handle.props.onPointermove(pointerEvent(handle, { clientX: 800, clientY: 250 }));
    assert.equal(storage.writes.length, 1, 'Dragging should not write to storage on every pointer movement');
    handle.props.onPointerup(pointerEvent(handle));
    assert.deepEqual(JSON.parse(storage.getItem('game-chat:2:preferences')), {
        soundEnabled: false,
        windowPosition: { right: 220, bottom: 220 },
    });
    first.unmount();
    const reloaded = mountChat({}, { storage });
    t.after(reloaded.unmount);
    assert.equal(reloaded.controls.activeChat.value.isOpen.value, false);
    reloaded.receiveMessage(message(2));
    assert.deepEqual(reloaded.sounds, []);
    await reloaded.open();
    const container = reloaded.find((entry) => entry.props.role === 'dialog').parent;
    assert.deepEqual(container.props.style, { left: 'auto', right: '220px', bottom: '220px' });
    await reloaded.click(reloaded.find((entry) => entry.props['aria-label'] === 'Включить звук чата'));
    assert.deepEqual(JSON.parse(storage.getItem('game-chat:2:preferences')), {
        soundEnabled: true,
        windowPosition: { right: 220, bottom: 220 },
    });
    await reloaded.close();
    reloaded.receiveMessage(message(3));
    assert.equal(reloaded.sounds.length, 1);
});

test('saved chat coordinates are clamped to the current viewport and updated in storage', async (t) => {
    const storage = createStorage({
        'game-chat:2:preferences': JSON.stringify({
            soundEnabled: false,
            windowPosition: { right: 9999, bottom: 9999 },
        }),
    });
    const chat = mountChat({}, { storage });
    t.after(chat.unmount);
    await chat.open();
    const bounds = chat.find((entry) => entry.props.role === 'dialog').parent.getBoundingClientRect();
    assert.equal(bounds.left, 12);
    assert.equal(bounds.top, 12);
    assert.deepEqual(JSON.parse(storage.getItem('game-chat:2:preferences')), {
        soundEnabled: false,
        windowPosition: { right: 884, bottom: 408 },
    });
});

test('moving the chat with arrow keys also persists its position', async (t) => {
    const storage = createStorage();
    const chat = mountChat({}, { storage });
    t.after(chat.unmount);
    await chat.open();
    chat.find((entry) => entry.props['aria-label'] === 'Переместить окно чата').props.onKeydown(keyEvent('ArrowLeft'));
    assert.deepEqual(JSON.parse(storage.getItem('game-chat:2:preferences')).windowPosition, { right: 830, bottom: 20 });
});

for (const stored of ['invalid JSON', 'null', '[]', '{"soundEnabled":"false","windowPosition":{"right":"20","bottom":10}}']) {
    test(`invalid saved chat preferences safely use defaults: ${stored}`, async (t) => {
        const chat = mountChat({}, { storage: createStorage({ 'game-chat:2:preferences': stored }) });
        t.after(chat.unmount);
        await chat.open();
        assert.ok(chat.find((entry) => entry.props['aria-label'] === 'Отключить звук чата'));
        assert.equal(chat.find((entry) => entry.props.role === 'dialog').parent.getBoundingClientRect().left, 76);
    });
}

test('a valid saved sound preference survives invalid saved coordinates', async (t) => {
    const storage = createStorage({
        'game-chat:2:preferences': JSON.stringify({ soundEnabled: false, windowPosition: { right: -1, bottom: 20 } }),
    });
    const chat = mountChat({}, { storage });
    t.after(chat.unmount);
    await chat.open();
    assert.ok(chat.find((entry) => entry.props['aria-label'] === 'Включить звук чата'));
    assert.equal(chat.find((entry) => entry.props.role === 'dialog').parent.getBoundingClientRect().left, 76);
});

test('browser storage failures do not prevent toggling sound or moving the chat', async (t) => {
    const chat = mountChat({}, {
        storage: {
            getItem() {
                throw new Error('Storage unavailable');
            },
            setItem() {
                throw new Error('Quota exceeded');
            },
        },
    });
    t.after(chat.unmount);
    await chat.open();
    await chat.click(chat.find((entry) => entry.props['aria-label'] === 'Отключить звук чата'));
    chat.receiveMessage(message(1));
    assert.deepEqual(chat.sounds, []);
    chat.find((entry) => entry.props['aria-label'] === 'Переместить окно чата').props.onKeydown(keyEvent('ArrowLeft'));
    assert.ok(chat.find((entry) => entry.props['aria-label'] === 'Включить звук чата'));
});

test('chat preferences are scoped to the current user', async (t) => {
    const storage = createStorage({
        'game-chat:2:preferences': JSON.stringify({ soundEnabled: false, windowPosition: { right: 220, bottom: 220 } }),
    });
    const chat = mountChat({}, { storage, currentUserId: 9 });
    t.after(chat.unmount);
    await chat.open();
    assert.ok(chat.find((entry) => entry.props['aria-label'] === 'Отключить звук чата'));
    assert.equal(chat.find((entry) => entry.props.role === 'dialog').parent.getBoundingClientRect().left, 76);
});

test('collapsing and reopening preserves both draft and messages and restores focus', async (t) => {
    const chat = mountChat({ messages: Vue.ref([message(1)]) }, { sidebar: true });
    t.after(chat.unmount);
    await chat.open();
    chat.state.form.body = 'Неотправленный черновик';
    await Vue.nextTick();
    await chat.close();
    assert.equal(chat.state.form.body, 'Неотправленный черновик');
    assert.equal(chat.all((entry) => entry.tag === 'textarea').length, 0);
    assert.equal(chat.all((entry) => ['div', 'button', 'section'].includes(entry.tag)).length, 0);
    assert.equal(chat.focused().props['aria-label'], 'Открыть чат партии');
    await chat.open();
    assert.equal(chat.find((entry) => entry.tag === 'textarea').value, 'Неотправленный черновик');
    assert.match(chat.text(), /Сообщение 1/);
    assert.equal(chat.sent.length, 0);
});

test('Escape hides the chat and returns focus to the sidebar button', async (t) => {
    const chat = mountChat({}, { sidebar: true });
    t.after(chat.unmount);
    await chat.open();
    const event = keyEvent('Escape');
    await chat.find((entry) => entry.props.onKeydown && entry.tag === 'div').props.onKeydown(event);
    await Vue.nextTick();
    assert.equal(event.prevented, true);
    assert.equal(event.stopped, true);
    assert.equal(chat.all((entry) => entry.props.role === 'dialog').length, 0);
    assert.equal(chat.focused().props['aria-label'], 'Открыть чат партии');
});

test('returning focus after chat closes suppresses its tooltip only during that focus change', async (t) => {
    const chat = mountChat({}, { sidebar: true });
    t.after(chat.unmount);
    await chat.open();
    await chat.close();
    const button = chat.find((entry) => entry.props['aria-label'] === 'Открыть чат партии');
    const restoredFocus = chat.focusEvents.filter((entry) => entry.element === button).at(-1);
    assert.equal(restoredFocus.tooltipDisabled, true);
    assert.equal(button.props['tooltip-disabled'], false);
    assert.equal(button.props.tooltip, 'Открыть чат партии');
    assert.equal(chat.focused(), button);
});

test('unread badge ignores initial history, older pages, and own messages, then clears on open', async (t) => {
    const chat = mountChat({ loaded: Vue.ref(false) }, { sidebar: true });
    t.after(chat.unmount);
    chat.state.messages.value = [message(3)];
    chat.state.loaded.value = true;
    await Vue.nextTick();
    const sidebarButton = () =>
        chat.find((entry) => String(entry.props['aria-label']).startsWith('Открыть чат партии'));
    assert.equal(sidebarButton().props['aria-label'], 'Открыть чат партии');
    chat.state.messages.value = [message(1), message(3), message(4, { authorId: 2 }), message(5)];
    await Vue.nextTick();
    assert.match(sidebarButton().props['aria-label'], /новых сообщений: 1/);
    await chat.open();
    chat.state.messages.value.push(message(6));
    await Vue.nextTick();
    await chat.close();
    assert.equal(sidebarButton().props['aria-label'], 'Открыть чат партии');
});

test('Enter sends explicitly while Shift+Enter and IME composition keep multiline drafts', async (t) => {
    const chat = mountChat();
    t.after(chat.unmount);
    await chat.open();
    const textarea = chat.find((entry) => entry.tag === 'textarea');
    chat.state.form.body = 'Привет';
    const newline = keyEvent('Enter', { shiftKey: true });
    textarea.props.onKeydown(newline);
    const composing = keyEvent('Enter', { isComposing: true });
    textarea.props.onKeydown(composing);
    assert.equal(newline.prevented, false);
    assert.equal(composing.prevented, false);
    assert.equal(chat.sent.length, 0);
    const enter = keyEvent('Enter');
    textarea.props.onKeydown(enter);
    await Vue.nextTick();
    assert.equal(enter.prevented, true);
    assert.deepEqual(chat.sent, ['Привет']);
    assert.equal(chat.state.form.body, '');
});

test('blank and pending drafts cannot be sent with Enter', async (t) => {
    const chat = mountChat();
    t.after(chat.unmount);
    await chat.open();
    const textarea = chat.find((entry) => entry.tag === 'textarea');
    textarea.props.onKeydown(keyEvent('Enter'));
    chat.state.form.body = 'Привет';
    chat.state.form.processing = true;
    textarea.props.onKeydown(keyEvent('Enter'));
    await Vue.nextTick();
    assert.equal(chat.sent.length, 0);
    assert.equal(chat.find((entry) => entry.props.type === 'submit').props.disabled, true);
});

test('messages render as text, highlight own replies, and group dates', async (t) => {
    const chat = mountChat({
        messages: Vue.ref([
            message(1, { authorName: '<script>evil()</script>', body: '<img src=x onerror=evil()>' }),
            message(2, { authorId: 2 }),
        ]),
    });
    t.after(chat.unmount);
    await chat.open();
    assert.match(chat.text(), /<script>evil\(\)<\/script>/);
    assert.match(chat.text(), /<img src=x onerror=evil\(\)>/);
    assert.equal(chat.all((entry) => ['script', 'img'].includes(entry.tag)).length, 0);
    assert.equal(chat.all((entry) => entry.props.innerHTML !== undefined).length, 0);
    assert.ok(chat.all((entry) => String(entry.props.class).includes('text-primary-foreground')).length > 0);
    assert.equal(chat.all((entry) => entry.tag === 'time').length, 2);
    assert.equal(chat.text().match(/2 ноября 2026/g).length, 1);
});

test('revoked access hides history and sending controls', async (t) => {
    const chat = mountChat({ accessDenied: Vue.ref(true) });
    t.after(chat.unmount);
    await chat.open();
    assert.match(chat.text(), /Чат доступен только участникам/);
    assert.equal(chat.all((entry) => ['form', 'textarea'].includes(entry.tag)).length, 0);
});

test('history failures expose retry and sending locks the draft with a status label', async (t) => {
    const chat = mountChat({
        historyError: Vue.ref('Ошибка загрузки'),
        connectionStatus: Vue.ref('connecting'),
        form: Vue.reactive({ body: 'Привет', processing: true, errors: {} }),
    });
    t.after(chat.unmount);
    await chat.open();
    assert.match(chat.text(), /Повторить/);
    assert.match(
        chat.find((entry) => entry.props.role === 'status').props['aria-label'],
        /Соединение восстанавливается/,
    );
    assert.equal(chat.find((entry) => entry.tag === 'textarea').props.disabled, true);
    assert.equal(chat.find((entry) => entry.props.type === 'submit').props['aria-label'], 'Отправка…');
});

test('incoming messages do not move a reader who is viewing older messages', async (t) => {
    const chat = mountChat({ messages: Vue.ref([message(1)]) });
    t.after(chat.unmount);
    await chat.open();
    const list = chat.find((entry) => entry.props.role === 'log');
    list.scrollTop = 20;
    list.props.onScroll();
    chat.state.messages.value.push(message(2));
    await Vue.nextTick();
    assert.equal(list.scrollTop, 20);
    await chat.find((entry) => entry.props['aria-label'] === 'К последним сообщениям').props.onClick();
    assert.equal(list.scrollTop, list.scrollHeight);
});

test('loading older history preserves the readers position', async (t) => {
    let list;
    const chat = mountChat({
        messages: Vue.ref([message(3)]),
        hasMore: Vue.ref(true),
        loadHistory() {
            chat.state.messages.value.unshift(message(1), message(2));
            list.scrollHeight = 1500;
        },
    });
    t.after(chat.unmount);
    await chat.open();
    list = chat.find((entry) => entry.props.role === 'log');
    list.scrollHeight = 1000;
    list.scrollTop = 120;
    list.props.onScroll();
    await chat.find((entry) => entry.tag === 'button' && chat.text(entry) === 'Ранее').props.onClick();
    await Vue.nextTick();
    assert.equal(list.scrollTop, 620);
    assert.deepEqual(
        chat.state.messages.value.map((entry) => entry.id),
        [1, 2, 3],
    );
});

for (const pointerType of ['mouse', 'touch', 'pen']) {
    test(`chat can be dragged by its title with ${pointerType} and captures the active pointer`, async (t) => {
        const chat = mountChat();
        t.after(chat.unmount);
        await chat.open();
        const handle = chat.find((entry) => entry.props['aria-label'] === 'Переместить окно чата');
        const container = handle.parent.parent.parent;
        const down = pointerEvent(handle, { pointerType });
        handle.props.onPointerdown(down);
        assert.equal(down.prevented, true);
        assert.equal(handle.hasPointerCapture(1), true);
        handle.props.onPointermove(pointerEvent(handle, { pointerType, clientX: 800, clientY: 250 }));
        await Vue.nextTick();
        assert.deepEqual(container.props.style, { left: 'auto', right: '220px', bottom: '220px' });
        assert.match(handle.props.class, /cursor-grabbing/);
        handle.props.onPointerup(pointerEvent(handle, { pointerType }));
        handle.props.onPointermove(pointerEvent(handle, { pointerType, clientX: 700, clientY: 200 }));
        await Vue.nextTick();
        assert.equal(handle.hasPointerCapture(1), false);
        assert.deepEqual(container.props.style, { left: 'auto', right: '220px', bottom: '220px' });
        assert.match(handle.props.class, /cursor-grab\b/);
    });
}

test('dragging is constrained to the viewport and is preserved when collapsed and reopened', async (t) => {
    const chat = mountChat();
    t.after(chat.unmount);
    await chat.open();
    const handle = chat.find((entry) => entry.props['aria-label'] === 'Переместить окно чата');
    const container = handle.parent.parent.parent;
    handle.props.onPointerdown(pointerEvent(handle));
    handle.props.onPointermove(pointerEvent(handle, { clientX: 100000, clientY: 100000 }));
    await Vue.nextTick();
    assert.deepEqual(container.props.style, { left: 'auto', right: '12px', bottom: '12px' });
    handle.props.onPointermove(pointerEvent(handle, { clientX: -100000, clientY: -100000 }));
    await Vue.nextTick();
    assert.deepEqual(container.props.style, { left: 'auto', right: '884px', bottom: '408px' });
    await chat.close();
    assert.equal(handle.hasPointerCapture(1), false);
    assert.deepEqual(container.props.style, { left: 'auto', right: '884px', bottom: '408px' });
    await chat.open();
    const reopenedContainer = chat.find((entry) => entry.props.role === 'dialog').parent;
    assert.deepEqual(reopenedContainer.props.style, { left: 'auto', right: '884px', bottom: '408px' });
    await chat.resize(500, 360);
    const bounds = reopenedContainer.getBoundingClientRect();
    assert.ok(bounds.left >= 12 && bounds.top >= 12);
    assert.ok(bounds.right <= 488 && bounds.bottom <= 348);
});

test('secondary pointers and right clicks do not start or interrupt a drag', async (t) => {
    const chat = mountChat();
    t.after(chat.unmount);
    await chat.open();
    const handle = chat.find((entry) => entry.props['aria-label'] === 'Переместить окно чата');
    const container = handle.parent.parent.parent;
    const initialPosition = { ...container.props.style };
    handle.props.onPointerdown(pointerEvent(handle, { button: 2 }));
    handle.props.onPointerdown(pointerEvent(handle, { isPrimary: false }));
    handle.props.onPointermove(pointerEvent(handle, { clientX: 800, clientY: 250 }));
    await Vue.nextTick();
    assert.deepEqual(container.props.style, initialPosition);
    handle.props.onPointerdown(pointerEvent(handle));
    handle.props.onPointerdown(pointerEvent(handle, { pointerId: 2 }));
    handle.props.onPointermove(pointerEvent(handle, { pointerId: 2, clientX: 800, clientY: 250 }));
    handle.props.onPointercancel(pointerEvent(handle, { pointerId: 2 }));
    handle.props.onPointermove(pointerEvent(handle, { clientX: 800, clientY: 250 }));
    await Vue.nextTick();
    assert.deepEqual(container.props.style, { left: 'auto', right: '220px', bottom: '220px' });
    handle.props.onPointercancel(pointerEvent(handle));
    handle.props.onPointermove(pointerEvent(handle, { clientX: 700, clientY: 200 }));
    await Vue.nextTick();
    assert.equal(handle.hasPointerCapture(1), false);
    assert.deepEqual(container.props.style, { left: 'auto', right: '220px', bottom: '220px' });
});

test('lost pointer capture ends dragging and arrow keys move the window accessibly', async (t) => {
    const chat = mountChat();
    t.after(chat.unmount);
    await chat.open();
    const handle = chat.find((entry) => entry.props['aria-label'] === 'Переместить окно чата');
    const container = handle.parent.parent.parent;
    const initialPosition = { ...container.props.style };
    handle.props.onPointerdown(pointerEvent(handle));
    handle.releasePointerCapture(1);
    handle.props.onLostpointercapture(pointerEvent(handle));
    handle.props.onPointermove(pointerEvent(handle, { clientX: 800, clientY: 250 }));
    await Vue.nextTick();
    assert.deepEqual(container.props.style, initialPosition);
    const left = keyEvent('ArrowLeft');
    handle.props.onKeydown(left);
    await Vue.nextTick();
    assert.equal(left.prevented, true);
    assert.deepEqual(container.props.style, { left: 'auto', right: '830px', bottom: '20px' });
    handle.props.onKeydown(keyEvent('ArrowUp', { shiftKey: true }));
    await Vue.nextTick();
    assert.deepEqual(container.props.style, { left: 'auto', right: '830px', bottom: '60px' });
});

test('resizing and unmounting release pointer capture and remove the resize listener', async (t) => {
    const chat = mountChat();
    await chat.open();
    const handle = chat.find((entry) => entry.props['aria-label'] === 'Переместить окно чата');
    t.after(() => {
        chat.unmount();
        assert.equal(handle.hasPointerCapture(1), false);
        assert.equal(chat.listeners.size, 0);
    });
    handle.props.onPointerdown(pointerEvent(handle));
    handle.props.onPointermove(pointerEvent(handle, { clientX: 800, clientY: 250 }));
    await chat.resize(500, 360);
    assert.equal(handle.hasPointerCapture(1), false);
    handle.props.onPointerdown(pointerEvent(handle));
    assert.equal(handle.hasPointerCapture(1), true);
});

test('sidebar chat button toggles the current game chat while preserving its draft and position', async (t) => {
    const chat = mountChat({}, { sidebar: true });
    t.after(chat.unmount);
    await Vue.nextTick();
    const button = chat.find((entry) => entry.props['aria-label'] === 'Открыть чат партии');
    const fullscreen = chat.find((entry) => entry.props['aria-label'] === 'На весь экран');
    const sidebarControls = chat.all((entry) => entry.tag === 'button', chat.root);
    assert.ok(sidebarControls.indexOf(button) < sidebarControls.indexOf(fullscreen));
    assert.equal(button.props['aria-expanded'], false);
    assert.equal(button.props['aria-controls'], 'game-chat-1');
    await chat.click(button);
    await Vue.nextTick();
    assert.equal(button.props['aria-expanded'], true);
    assert.equal(chat.all((entry) => entry.props.role === 'dialog').length, 1);
    chat.state.form.body = 'Черновик';
    const handle = chat.find((entry) => entry.props['aria-label'] === 'Переместить окно чата');
    handle.props.onPointerdown(pointerEvent(handle));
    handle.props.onPointermove(pointerEvent(handle, { clientX: 800, clientY: 250 }));
    handle.props.onPointerup(pointerEvent(handle));
    await Vue.nextTick();
    await chat.click(button);
    await Vue.nextTick();
    assert.equal(chat.all((entry) => entry.props.role === 'dialog').length, 0);
    assert.equal(button.props['aria-expanded'], false);
    assert.equal(button.props['aria-label'], 'Открыть чат партии');
    assert.equal(chat.state.form.body, 'Черновик');
    await chat.click(button);
    await Vue.nextTick();
    assert.equal(chat.all((entry) => entry.props.role === 'dialog').length, 1);
    assert.equal(chat.state.form.body, 'Черновик');
    const reopened = chat.find((entry) => entry.props.role === 'dialog').parent;
    assert.deepEqual(reopened.props.style, { left: 'auto', right: '220px', bottom: '220px' });
    assert.equal(chat.focused().tag, 'textarea');
});

test('sidebar unread count follows the current chat and resets when opened', async (t) => {
    const chat = mountChat({}, { sidebar: true });
    t.after(chat.unmount);
    await Vue.nextTick();
    chat.state.messages.value.push(message(1));
    await Vue.nextTick();
    const button = chat.find((entry) => String(entry.props['aria-label']).startsWith('Открыть чат партии'));
    assert.match(button.props['aria-label'], /новых сообщений: 1/);
    await chat.click(button);
    await Vue.nextTick();
    assert.equal(button.props['aria-label'], 'Закрыть чат партии');
    await chat.close();
    assert.equal(button.props['aria-expanded'], false);
    assert.equal(button.props['aria-label'], 'Открыть чат партии');
});

for (const soundEnabled of [true, false]) {
    test(`the sidebar icon shows restored sound state before the chat is opened: ${soundEnabled}`, async (t) => {
        const storage = createStorage({
            'game-chat:2:preferences': JSON.stringify({ soundEnabled, windowPosition: null }),
        });
        const chat = mountChat({}, { sidebar: true, storage });
        t.after(chat.unmount);
        await Vue.nextTick();
        const button = chat.find((entry) => entry.props['aria-label'] === 'Открыть чат партии');
        assert.equal(chat.all((entry) => entry.props['data-icon'] === 'message-circle', button).length, 1);
        assert.equal(chat.all((entry) => entry.props['data-icon'] === 'volume-x', button).length, soundEnabled ? 0 : 1);
        assert.equal(chat.all((entry) => entry.props['data-icon'] === 'volume-2', button).length, 0);
        assert.equal(button.props.tooltip, 'Открыть чат партии');
        assert.equal(chat.controls.activeChat.value.isOpen.value, false);
        chat.sidebar.state.value = 'expanded';
        chat.state.messages.value.push(message(1));
        await Vue.nextTick();
        assert.equal(chat.all((entry) => entry.props['data-icon'] === 'volume-x', button).length, soundEnabled ? 0 : 1);
        assert.match(button.props['aria-label'], /новых сообщений: 1/);
    });
}

test('sidebar shows the muted badge only while chat sound is disabled, including when the chat is closed', async (t) => {
    const chat = mountChat({}, { sidebar: true });
    t.after(chat.unmount);
    await chat.open();
    const button = chat.find((entry) => entry.props['aria-label'] === 'Закрыть чат партии');
    await chat.click(chat.find((entry) => entry.props['aria-label'] === 'Отключить звук чата'));
    assert.equal(chat.all((entry) => entry.props['data-icon'] === 'volume-x', button).length, 1);
    assert.equal(chat.all((entry) => entry.props['data-icon'] === 'volume-2', button).length, 0);
    assert.equal(button.props.tooltip, 'Закрыть чат партии');
    await chat.close();
    assert.equal(chat.all((entry) => entry.props['data-icon'] === 'volume-x', button).length, 1);
    assert.equal(button.props.tooltip, 'Открыть чат партии');
    await chat.open();
    await chat.click(chat.find((entry) => entry.props['aria-label'] === 'Включить звук чата'));
    assert.equal(chat.all((entry) => entry.props['data-icon'] === 'volume-x', button).length, 0);
    assert.equal(chat.all((entry) => entry.props['data-icon'] === 'volume-2', button).length, 0);
    assert.equal(button.props.tooltip, 'Закрыть чат партии');
});

test('sidebar has no chat button without a current game and clears it when leaving', async (t) => {
    const chat = mountChat({}, { sidebar: true, showChat: false });
    t.after(chat.unmount);
    assert.equal(chat.controls.activeChat.value, null);
    const buttons = () =>
        chat.all((entry) => String(entry.props['aria-label']).startsWith('Открыть чат партии'), chat.root);
    assert.equal(buttons().length, 0);
    chat.showChat.value = true;
    await Vue.nextTick();
    assert.equal(buttons().length, 1);
    chat.showChat.value = false;
    await Vue.nextTick();
    assert.equal(chat.controls.activeChat.value, null);
    assert.equal(buttons().length, 0);
});

test('sidebar opens the new game after navigation instead of reopening the previous chat', async (t) => {
    const chat = mountChat({}, { sidebar: true });
    t.after(chat.unmount);
    await Vue.nextTick();
    await chat.open();
    assert.equal(chat.find((entry) => entry.props.role === 'dialog').props.id, 'game-chat-1');
    chat.gameId.value = 2;
    await Vue.nextTick();
    const button = chat.find((entry) => entry.props['aria-label'] === 'Открыть чат партии');
    assert.equal(button.props['aria-controls'], 'game-chat-2');
    assert.equal(button.props['aria-expanded'], false);
    await chat.click(button);
    await Vue.nextTick();
    assert.equal(chat.all((entry) => entry.props.role === 'dialog').length, 1);
    assert.equal(chat.find((entry) => entry.props.role === 'dialog').props.id, 'game-chat-2');
});

test('opening chat from the mobile sidebar closes the drawer', async (t) => {
    const chat = mountChat({}, { sidebar: true, isMobile: true });
    t.after(chat.unmount);
    await Vue.nextTick();
    assert.equal(chat.sidebar.openMobile.value, true);
    await chat.open();
    await Vue.nextTick();
    assert.equal(chat.sidebar.openMobile.value, false);
    assert.equal(chat.find((entry) => entry.props.role === 'dialog').props.id, 'game-chat-1');
});
