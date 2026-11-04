import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';
import ts from 'typescript';

const source = readFileSync(new URL('../../resources/js/pages/games/Show.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const subscription = descriptor.scriptSetup.content.slice(
    descriptor.scriptSetup.content.indexOf('useEcho('),
    descriptor.scriptSetup.content.indexOf('const currentPlayer'),
);
const { outputText } = ts.transpileModule(subscription, {
    compilerOptions: { target: ts.ScriptTarget.ES2022 },
});

function handleGameUpdate(event) {
    const visits = [];
    const reloads = [];
    const listRoute = { url: '/games', method: 'get' };
    let handleUpdate;
    new Function('props', 'useEcho', 'router', 'index', outputText)(
        { game: { data: { id: 42 } } },
        (channel, name, callback) => {
            assert.equal(channel, 'games.42');
            assert.equal(name, '.game.changed');
            handleUpdate = callback;
        },
        {
            visit: (route) => visits.push(route),
            reload: (options) => reloads.push(options),
        },
        () => listRoute,
    );
    handleUpdate(event);

    return { visits, reloads, listRoute };
}

test('a deleted game returns open pages to the list without reloading its missing URL', () => {
    const { visits, reloads, listRoute } = handleGameUpdate({ gameId: 42, deleted: true });

    assert.deepEqual(visits, [listRoute]);
    assert.deepEqual(reloads, []);
});

test('an existing game refreshes its state without leaving the page', () => {
    const { visits, reloads } = handleGameUpdate({ gameId: 42, deleted: false });

    assert.deepEqual(visits, []);
    assert.deepEqual(reloads, [{ only: ['game'] }]);
});

test('events without the deletion flag still refresh existing game pages', () => {
    const { visits, reloads } = handleGameUpdate({ gameId: 42 });

    assert.deepEqual(visits, []);
    assert.deepEqual(reloads, [{ only: ['game'] }]);
});
