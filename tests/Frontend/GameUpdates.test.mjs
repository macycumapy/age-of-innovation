import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';
import ts from 'typescript';

const source = readFileSync(new URL('../../resources/js/pages/games/Show.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const subscription = descriptor.scriptSetup.content.slice(
    descriptor.scriptSetup.content.indexOf('onBeforeUnmount('),
    descriptor.scriptSetup.content.indexOf('const currentPlayer'),
);
const { outputText } = ts.transpileModule(subscription, {
    compilerOptions: { target: ts.ScriptTarget.ES2022 },
});

function handleGameUpdate(payload, details = {}) {
    const visits = [];
    const reloads = [];
    const listRoute = { url: '/games', method: 'get' };
    let handleUpdate;
    let cleanup;
    let unsubscriptions = 0;
    new Function('props', 'router', 'index', 'onBeforeUnmount', outputText)(
        { game: { data: { id: 42 } } },
        {
            on: (name, callback) => {
                assert.equal(name, 'live');
                handleUpdate = callback;

                return () => {
                    unsubscriptions++;
                };
            },
            visit: (route) => visits.push(route),
            reload: (options) => reloads.push(options),
        },
        () => listRoute,
        (callback) => {
            cleanup = callback;
        },
    );
    const result = handleUpdate({
        detail: {
            props: ['game'],
            channel: { name: 'games.42', type: 'private' },
            event: 'game.changed',
            payload,
            ...details,
        },
    });

    return { visits, reloads, listRoute, result, cleanup, unsubscribeCount: () => unsubscriptions };
}

test('a deleted game returns open pages to the list without reloading its missing URL', () => {
    const { visits, reloads, listRoute, result } = handleGameUpdate({ gameId: 42, deleted: true });

    assert.deepEqual(visits, [listRoute]);
    assert.deepEqual(reloads, []);
    assert.equal(result, false);
});

test('an existing game lets Live Props refresh it without a manual reload', () => {
    const { visits, reloads, result } = handleGameUpdate({ gameId: 42, deleted: false });

    assert.deepEqual(visits, []);
    assert.deepEqual(reloads, []);
    assert.equal(result, undefined);
});

test('events without the deletion flag are left to Live Props', () => {
    const { visits, reloads, result } = handleGameUpdate({ gameId: 42 });

    assert.deepEqual(visits, []);
    assert.deepEqual(reloads, []);
    assert.equal(result, undefined);
});

for (const { name, payload, details } of [
    { name: 'another game', payload: { gameId: 99, deleted: true } },
    { name: 'missing game ID', payload: { deleted: true } },
    { name: 'invalid deletion flag', payload: { gameId: 42, deleted: 'true' } },
    { name: 'another event', payload: { gameId: 42, deleted: true }, details: { event: 'history.changed' } },
]) {
    test(`unrelated live events do not redirect or cancel: ${name}`, () => {
        const { visits, reloads, result } = handleGameUpdate(payload, details);

        assert.deepEqual(visits, []);
        assert.deepEqual(reloads, []);
        assert.equal(result, undefined);
    });
}

test('live events without a payload are not intercepted', () => {
    const { visits, result } = handleGameUpdate(null);

    assert.deepEqual(visits, []);
    assert.equal(result, undefined);
});

test('the deletion listener is removed when the game page unmounts', () => {
    const subscription = handleGameUpdate({ gameId: 42 });
    assert.equal(subscription.unsubscribeCount(), 0);

    subscription.cleanup();

    assert.equal(subscription.unsubscribeCount(), 1);
});

test('resource exchange closes its dialog and lets Live Props update the game', () => {
    const exchangeSource = readFileSync(
        new URL('../../resources/js/components/game/ResourceExchangeDialog.vue', import.meta.url), 'utf8',
    );
    const { descriptor: exchange } = parse(exchangeSource);
    const successSource = exchange.scriptSetup.content.slice(
        exchange.scriptSetup.content.indexOf('function exchangeSucceeded'),
        exchange.scriptSetup.content.indexOf('watch(isOpen'),
    );
    const successScript = ts.transpileModule(successSource, {
        compilerOptions: { target: ts.ScriptTarget.ES2022 },
    }).outputText;
    const isOpen = { value: true };
    const reloads = [];
    const success = new Function('isOpen', 'router', `${successScript}; return exchangeSucceeded;`)(
        isOpen, { reload: (options) => reloads.push(options) },
    );

    success();

    assert.equal(isOpen.value, false);
    assert.deepEqual(reloads, []);
});
