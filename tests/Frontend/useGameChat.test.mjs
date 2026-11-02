import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { http } from '@inertiajs/core';
import { useHttp as useInertiaHttp } from '@inertiajs/vue3';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as Vue from 'vue';

const source = readFileSync(new URL('../../resources/js/composables/useGameChat.ts', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
});

class ResponseError extends Error {
    constructor(status) {
        super(`HTTP ${status}`);
        this.response = { status };
    }
}

function message(id, overrides = {}) {
    return {
        id,
        gameId: 1,
        authorId: 2,
        authorName: 'Анна',
        body: `Сообщение ${id}`,
        clientMessageId: `uuid-${id}`,
        createdAt: '2026-11-02T10:00:00Z',
        ...overrides,
    };
}

function harness({ useHttpOverride, onMessageReceived } = {}) {
    const requests = [];
    const mounted = [];
    const unmounted = [];
    let receive;
    let subscribed;
    let left = false;
    const subscription = {
        channel: () => ({
            subscribed: (callback) => {
                subscribed = callback;
            },
        }),
        leaveChannel: () => {
            left = true;
        },
    };
    const useHttp = (initial) => {
        let defaults = { ...initial };
        const request = Vue.reactive({
            ...initial,
            processing: false,
            errors: {},
            calls: [],
            replies: [],
            canceled: false,
            get hasErrors() {
                return Object.keys(this.errors).length > 0;
            },
            clearErrors() {
                this.errors = {};
            },
            defaults(fields) {
                defaults = { ...defaults, ...fields };

                return this;
            },
            resetAndClearErrors() {
                Object.assign(this, defaults);
                this.errors = {};
            },
            cancel() {
                this.canceled = true;
            },
            async execute(call) {
                this.calls.push(call);
                this.processing = true;

                try {
                    const reply = this.replies.shift() ?? { data: [], hasMore: false };

                    if (reply instanceof Error) {
                        throw reply;
                    }

                    const result = typeof reply === 'function' ? await reply(this) : await reply;
                    defaults = Object.fromEntries(Object.keys(initial).map((key) => [key, this[key]]));

                    return result;
                } finally {
                    this.processing = false;
                }
            },
            get(url) {
                return this.execute({ url });
            },
            submit(route) {
                return this.execute({ route, body: this.body, clientId: this.client_message_id });
            },
        });
        requests.push(request);

        return request;
    };
    const load = (name) => {
        switch (name) {
            case 'vue':
                return {
                    ...Vue,
                    onMounted: (callback) => mounted.push(callback),
                    onUnmounted: (callback) => unmounted.push(callback),
                };
            case '@inertiajs/core':
                return { HttpResponseError: ResponseError };
            case '@inertiajs/vue3':
                return { useHttp: useHttpOverride ?? useHttp };
            case '@laravel/echo-vue':
                return {
                    useConnectionStatus: () => Vue.ref('connected'),
                    useEcho: (channel, event, callback) => {
                        assert.equal(channel, 'game-chat.1.4');
                        assert.equal(event, '.chat.message.created');
                        receive = callback;

                        return subscription;
                    },
                };
            case '@/actions/App/Http/Controllers/Chat/ChatMessageController':
                return {
                    index: { url: (gameId, options) => ({ gameId, ...options.query }) },
                    store: (gameId) => ({ method: 'post', url: `/games/${gameId}/chat/messages`, gameId }),
                };
            default:
                throw new Error(`Unexpected import ${name}`);
        }
    };
    const module = { exports: {} };
    new Function('require', 'module', 'exports', outputText)(load, module, module.exports);
    const chat = module.exports.useGameChat(1, 4, onMessageReceived);

    return {
        chat,
        requests,
        mounted,
        unmounted,
        receive: (entry) => receive({ message: entry }),
        reconnect: () => subscribed(),
        hasLeft: () => left,
    };
}

test('draft is local, can be cleared, and is published only on explicit send', async () => {
    const { chat, requests } = harness();
    chat.form.body = 'Черновик';
    assert.equal(requests[0].calls.length, 0);
    chat.clearDraft();
    assert.equal(chat.form.body, '');
    assert.equal(requests[0].calls.length, 0);
    await chat.send();
    assert.equal(requests[0].calls.length, 0);
    requests[0].replies.push({ data: message(1, { body: 'Готово' }) });
    chat.form.body = '  Готово  ';
    await chat.send();
    assert.equal(requests[0].calls[0].body, 'Готово');
    assert.equal(chat.messages.value[0].body, 'Готово');
    assert.equal(chat.form.body, '');
});

test('real Inertia HTTP helper clears successful messages instead of restoring submitted defaults', async (t) => {
    const originalClient = http.getClient();
    const submitted = [];
    t.after(() => http.setClient(originalClient));
    http.setClient({
        async request(config) {
            const payload = JSON.parse(config.data);
            submitted.push(payload);

            return {
                status: 201,
                statusText: 'Created',
                headers: {},
                data: JSON.stringify({
                    data: message(submitted.length, { body: payload.body, clientMessageId: payload.client_message_id }),
                }),
            };
        },
    });
    let chat;
    await renderToString(
        Vue.createSSRApp({
            setup() {
                chat = harness({ useHttpOverride: useInertiaHttp }).chat;

                return () => null;
            },
        }),
    );

    for (const body of ['Первое сообщение', 'Второе сообщение']) {
        chat.form.body = body;
        await chat.send();
        assert.equal(chat.form.body, '');
        assert.equal(chat.form.client_message_id, '');
    }

    assert.deepEqual(
        submitted.map((payload) => payload.body),
        ['Первое сообщение', 'Второе сообщение'],
    );
    assert.notEqual(submitted[0].client_message_id, submitted[1].client_message_id);
    chat.form.body = 'Черновик после отправки';
    chat.clearDraft();
    assert.equal(chat.form.body, '');
});

test('real Inertia validation errors keep the unsent text', async (t) => {
    const originalClient = http.getClient();
    t.after(() => http.setClient(originalClient));
    http.setClient({
        async request() {
            return {
                status: 422,
                statusText: 'Unprocessable Entity',
                headers: {},
                data: JSON.stringify({ errors: { body: ['Слишком длинное сообщение'] } }),
            };
        },
    });
    let chat;
    await renderToString(
        Vue.createSSRApp({
            setup() {
                chat = harness({ useHttpOverride: useInertiaHttp }).chat;

                return () => null;
            },
        }),
    );
    chat.form.body = 'Текст для исправления';
    await chat.send();
    assert.equal(chat.form.body, 'Текст для исправления');
    assert.equal(chat.form.errors.body, 'Слишком длинное сообщение');
    assert.equal(chat.messages.value.length, 0);
});

test('network retry retains both draft and UUID; HTTP and websocket results merge once', async () => {
    const { chat, requests, receive } = harness();
    chat.form.body = 'Привет';
    requests[0].replies.push(new Error('Disconnected'));
    await chat.send();
    const firstId = requests[0].calls[0].clientId;
    assert.equal(chat.form.body, 'Привет');
    assert.match(chat.sendError.value, /Повторите отправку/);
    receive(message(10, { body: 'Привет', clientMessageId: firstId }));
    requests[0].replies.push({ data: message(10, { body: 'Привет', clientMessageId: firstId }) });
    await chat.send();
    assert.equal(requests[0].calls[1].clientId, firstId);
    assert.equal(chat.messages.value.length, 1);
    assert.equal(chat.form.body, '');
    requests[0].replies.push({ data: message(11, { body: 'Привет' }) });
    chat.form.body = 'Привет';
    await chat.send();
    assert.notEqual(requests[0].calls[2].clientId, firstId);
});

test('changing a failed draft creates a new request identity and blocks concurrent sends', async () => {
    const { chat, requests } = harness();
    chat.form.body = 'Первое';
    requests[0].replies.push(new Error('Disconnected'));
    await chat.send();
    chat.form.body = 'Другое';
    let resolve;
    requests[0].replies.push(
        new Promise((done) => {
            resolve = done;
        }),
    );
    const sending = chat.send();
    await chat.send();
    chat.clearDraft();
    assert.equal(chat.form.body, 'Другое');
    assert.equal(requests[0].calls.length, 2);
    assert.notEqual(requests[0].calls[0].clientId, requests[0].calls[1].clientId);
    resolve({ data: message(2) });
    await sending;
});

test('out of order realtime events cannot move the recovery cursor past missing messages', async () => {
    const { chat, requests, receive } = harness();
    requests[1].replies.push({ data: [message(1)], hasMore: false });
    await chat.loadHistory();
    receive(message(100));
    receive(message(1));
    receive(message(101, { gameId: 2 }));
    requests[2].replies.push(
        { data: [message(2), message(3)], hasMore: true },
        { data: [message(4), message(100)], hasMore: false },
    );
    await chat.refresh();
    assert.deepEqual(
        requests[2].calls.map((call) => call.url.after_id),
        [1, 1, 3],
    );
    assert.deepEqual(
        chat.messages.value.map((entry) => entry.id),
        [1, 2, 3, 4, 100],
    );
});

test('only new realtime messages trigger notifications, without history, recovery, or duplicate events', async () => {
    const received = [];
    const { chat, requests, receive } = harness({ onMessageReceived: (message) => received.push(message.id) });
    requests[1].replies.push({ data: [message(2)], hasMore: true });
    requests[2].replies.push({ data: [message(3)], hasMore: false });
    await chat.loadHistory();
    requests[1].replies.push({ data: [message(1)], hasMore: false });
    await chat.loadHistory();
    assert.deepEqual(received, []);
    receive(message(4));
    receive(message(4, { authorName: 'Новое имя' }));
    assert.equal(chat.messages.value.at(-1).authorName, 'Новое имя');
    receive(message(2));
    receive(message(5, { gameId: 2 }));
    assert.deepEqual(received, [4]);
    requests[0].replies.push({ data: message(5) });
    chat.form.body = 'Сообщение';
    await chat.send();
    receive(message(5));
    requests[2].replies.push({ data: [message(6)], hasMore: false });
    await chat.refresh();
    receive(message(6));
    assert.deepEqual(received, [4]);
});

test('initial subscription race and older history preserve the complete ordered message list', async () => {
    const { chat, requests, receive } = harness();
    let resolve;
    requests[1].replies.push(
        new Promise((done) => {
            resolve = done;
        }),
    );
    requests[2].replies.push({ data: [message(6)], hasMore: false });
    const loading = chat.loadHistory();
    receive(message(6));
    resolve({ data: [message(5), message(4)], hasMore: true });
    await loading;
    assert.deepEqual(
        chat.messages.value.map((entry) => entry.id),
        [4, 5, 6],
    );
    assert.equal(requests[2].calls[0].url.after_id, 5);
    requests[1].replies.push({ data: [message(3), message(2)], hasMore: false });
    await chat.loadHistory();
    assert.equal(requests[1].calls[1].url.before_id, 4);
    assert.deepEqual(
        chat.messages.value.map((entry) => entry.id),
        [2, 3, 4, 5, 6],
    );
    assert.equal(chat.hasMore.value, false);
});

test('revoked access clears messages, leaves the channel, and prevents further requests', async () => {
    const received = [];
    const { chat, requests, receive, hasLeft } = harness({ onMessageReceived: (message) => received.push(message.id) });
    requests[1].replies.push({ data: [message(1)], hasMore: false });
    await chat.loadHistory();
    requests[2].replies.push(new ResponseError(403));
    await chat.refresh();
    assert.equal(chat.accessDenied.value, true);
    assert.equal(hasLeft(), true);
    receive(message(2));
    assert.deepEqual(chat.messages.value, []);
    assert.deepEqual(received, []);
    chat.form.body = 'Текст';
    await chat.send();
    assert.equal(requests[0].calls.length, 0);
});

test('validation failures retain the draft and expose field errors', async () => {
    const { chat, requests } = harness();
    chat.form.body = 'Текст';
    requests[0].replies.push((request) => {
        request.errors.body = 'Слишком длинное сообщение';

        throw new ResponseError(422);
    });
    await chat.send();
    assert.equal(chat.form.body, 'Текст');
    assert.equal(chat.form.errors.body, 'Слишком длинное сообщение');
    assert.equal(chat.sendError.value, '');
});

test('failed history can be retried and disposal cancels all outstanding requests', async () => {
    const received = [];
    const { chat, requests, receive, unmounted } = harness({ onMessageReceived: (message) => received.push(message.id) });
    requests[1].replies.push(new Error('Offline'));
    await chat.loadHistory();
    assert.equal(chat.loaded.value, false);
    assert.match(chat.historyError.value, /Не удалось загрузить/);
    requests[1].replies.push({ data: [message(1)], hasMore: false });
    await chat.refresh();
    assert.equal(chat.historyError.value, '');
    const previousWindow = globalThis.window;
    const previousDocument = globalThis.document;
    globalThis.window = { removeEventListener() {} };
    globalThis.document = { removeEventListener() {} };

    try {
        unmounted[0]();
        assert.ok(requests.every((request) => request.canceled));
        receive(message(2));
        assert.equal(chat.messages.value.length, 1);
        assert.deepEqual(received, []);
    } finally {
        globalThis.window = previousWindow;
        globalThis.document = previousDocument;
    }
});
