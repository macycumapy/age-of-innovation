import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compile } from '@vue/compiler-dom';
import { parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as Vue from 'vue';

const source = readFileSync(new URL('../../resources/js/pages/games/Index.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const trackerSource = descriptor.scriptSetup.content.slice(
    descriptor.scriptSetup.content.indexOf('const highlightedGameIds'),
    descriptor.scriptSetup.content.indexOf('const statusNames'),
);
const trackerScript = ts.transpileModule(trackerSource, {
    compilerOptions: { target: ts.ScriptTarget.ES2022 },
}).outputText;
const cardTemplate = descriptor.template.content.slice(
    descriptor.template.content.indexOf('<Card\n'),
    descriptor.template.content.indexOf('</Card>') + '</Card>'.length,
);
const { code } = compile(`<div>${cardTemplate}</div>`, { mode: 'function', prefixIdentifiers: true });

function trackGames(t, initialGames) {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const props = Vue.reactive({ games: { data: initialGames } });
    const scope = Vue.effectScope();
    let beforeUnmount;
    const tracker = scope.run(() => new Function(
        'props', 'ref', 'watch', 'onBeforeUnmount',
        `${trackerScript}; return { highlightedGameIds };`,
    )(props, Vue.ref, Vue.watch, (callback) => {
        beforeUnmount = callback;
    }));
    t.after(() => {
        beforeUnmount();
        scope.stop();
    });

    return {
        highlighted: () => [...tracker.highlightedGameIds.value],
        async update(games) {
            props.games = { data: games };
            await Vue.nextTick();
        },
        async renderCards() {
            const app = Vue.createSSRApp({
                render: new Function('Vue', code)(Vue),
                data: () => ({
                    games: props.games,
                    highlightedGameIds: tracker.highlightedGameIds.value,
                    statusNames: { lobby: 'Ожидает игроков', active: 'Идёт игра' },
                    mapVariantNames: { three_to_five_players: '3–5 игроков' },
                    formatDate: () => 'сегодня',
                    gameButtonLabel: () => 'Присоединиться',
                    show: (id) => `/games/${id}`,
                }),
            });

            app.component('Card', (_, { attrs, slots }) => Vue.h('article', attrs, slots.default?.()));

            for (const name of ['CardHeader', 'CardTitle', 'CardDescription', 'CardContent', 'Button', 'Link']) {
                app.component(name, (_, { slots }) => Vue.h('div', slots.default?.()));
            }

            const html = await renderToString(app);

            return [...html.matchAll(/<article\b.*?<\/article>/g)].map(([card]) => card);
        },
    };
}

function game(id, overrides = {}) {
    return {
        id, status: 'lobby', mapVariant: 'three_to_five_players', currentRound: null,
        playersCount: 1, isJoined: false, createdAt: null, ...overrides,
    };
}

test('initial games and updates to existing cards are not highlighted', async (t) => {
    const list = trackGames(t, [game(1), game(2)]);
    assert.deepEqual(list.highlighted(), []);

    await list.update([game(2, { playersCount: 3 }), game(1, { status: 'active' })]);

    assert.deepEqual(list.highlighted(), []);
    const cards = await list.renderCards();
    assert.equal(cards.length, 2);
    assert.ok(cards.every((card) => !card.includes('border-amber-400')));
});

test('new cards are highlighted for three seconds without restarting on another live update', async (t) => {
    const list = trackGames(t, [game(1)]);
    await list.update([game(2), game(1)]);

    assert.deepEqual(list.highlighted(), [2]);
    const cards = await list.renderCards();
    assert.match(cards[0], /Игра №2/);
    assert.match(cards[0], /border-amber-400 bg-amber-50\/90 dark:bg-amber-950\/70/);
    assert.match(cards[0], /motion-reduce:transition-none/);
    assert.doesNotMatch(cards[1], /border-amber-400/);

    t.mock.timers.tick(1000);
    await list.update([game(2, { playersCount: 2 }), game(1)]);
    t.mock.timers.tick(1999);
    assert.deepEqual(list.highlighted(), [2]);
    t.mock.timers.tick(1);
    assert.deepEqual(list.highlighted(), []);
    assert.ok((await list.renderCards()).every((card) => !card.includes('border-amber-400')));
});

test('games added to an empty list and separate live updates have independent highlights', async (t) => {
    const list = trackGames(t, []);
    await list.update([game(1)]);
    assert.deepEqual(list.highlighted(), [1]);

    t.mock.timers.tick(1000);
    await list.update([game(2), game(1)]);
    assert.deepEqual(list.highlighted(), [1, 2]);

    t.mock.timers.tick(2000);
    assert.deepEqual(list.highlighted(), [2]);
    t.mock.timers.tick(1000);
    assert.deepEqual(list.highlighted(), []);
});

test('removing a highlighted game cancels its timer before it can appear again', async (t) => {
    const list = trackGames(t, []);
    await list.update([game(1)]);
    t.mock.timers.tick(1000);
    await list.update([]);
    assert.deepEqual(list.highlighted(), []);

    await list.update([game(1)]);
    t.mock.timers.tick(2000);
    assert.deepEqual(list.highlighted(), [1]);
    t.mock.timers.tick(1000);
    assert.deepEqual(list.highlighted(), []);
});
