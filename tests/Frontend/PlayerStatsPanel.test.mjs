import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { compile } from '@vue/compiler-dom';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';

const source = readFileSync(
    new URL('../../resources/js/components/game/PlayerStatsPanel.vue', import.meta.url),
    'utf8',
);
const { descriptor } = parse(source);

test('history fills remaining panel height while retaining its previous minimum height', () => {
    const historySource = readFileSync(
        new URL('../../resources/js/components/game/GameHistory.vue', import.meta.url),
        'utf8',
    );
    const { descriptor: history } = parse(historySource);
    assert.match(descriptor.template.content, /class="flex h-full min-w-0 flex-col gap-4 overflow-y-auto p-4"/);
    assert.match(descriptor.template.content, /class="grid max-w-full min-w-0 shrink-0 /);
    assert.match(history.template.content, /class="grid min-h-80 flex-1 shrink-0 basis-80 /);
    assert.match(history.template.content, /grid-rows-\[auto_minmax\(0,1fr\)\]/);
    assert.match(history.template.content, /class="overflow-y-auto overscroll-contain pr-1"/);
});
const toggle = descriptor.scriptSetup.content.slice(
    descriptor.scriptSetup.content.indexOf('const collapsedPlayerIds'),
    descriptor.scriptSetup.content.indexOf('const playersWithStats'),
);
const toggleScript = ts.transpileModule(toggle, {
    compilerOptions: { target: ts.ScriptTarget.ES2022 },
}).outputText;
const article = descriptor.template.content.slice(
    descriptor.template.content.indexOf('<article'),
    descriptor.template.content.indexOf('</article>') + '</article>'.length,
);
const { code: articleCode } = compile(`<div>${article}</div>`, { mode: 'function', prefixIdentifiers: true });

test('player panels collapse independently and can be expanded again', async () => {
    const { collapsedPlayerIds, togglePlayerStats } = new Function(
        'ref',
        `${toggleScript}; return { collapsedPlayerIds, togglePlayerStats };`,
    )(Vue.ref);
    const entries = [1, 2].map((id) => ({
        player: { id, name: `Игрок ${id}` },
        turnOrder: id,
        state: { passOrder: null, victoryPoints: 20 + id },
    }));
    async function render() {
        const app = Vue.createSSRApp({
            render: new Function('Vue', articleCode)(Vue),
            data: () => ({
                playersWithStats: entries,
                collapsedPlayerIds: collapsedPlayerIds.value,
                togglePlayerStats,
                activePlayerId: 1,
                playerBackgroundColor: () => '',
                endOfTurnUrl: '',
                victoryPointsUrl: '',
                handUrl: '',
                balanceCounters: () => [{ label: 'Инструменты', value: 3, image: '' }],
                bookCounters: () => [],
                incomeCounters: () => [],
                levelCounters: () => [],
            }),
        });
        app.component('ChevronRight', { render: () => Vue.h('span') });
        app.component('ChevronDown', { render: () => Vue.h('span') });
        return renderToString(app);
    }
    assert.equal((await render()).match(/aria-label="Инструменты: 3"/g).length, 2);
    togglePlayerStats(1);
    const html = await render();
    assert.equal(html.match(/aria-label="Инструменты: 3"/g).length, 2);
    assert.equal(html.match(/grid-rows-\[0fr\] opacity-0/g).length, 1);
    assert.match(html, /motion-reduce:transition-none/);
    assert.match(html, /aria-hidden="true" inert/);
    assert.match(html, /aria-expanded="false"/);
    assert.match(html, /Развернуть статистику игрока Игрок 1/);
    assert.match(html, /Победные очки: 21/);
    assert.match(html, /Порядок хода в текущем раунде: 1/);
    assert.match(html, /Игрок 1/);
    togglePlayerStats(2);
    assert.equal((await render()).match(/grid-rows-\[0fr\] opacity-0/g).length, 2);
    togglePlayerStats(1);
    assert.equal((await render()).match(/grid-rows-\[0fr\] opacity-0/g).length, 1);
    assert.deepEqual(collapsedPlayerIds.value, [2]);
});
const script = ts.createSourceFile('PlayerStatsPanel.ts', descriptor.scriptSetup.content, ts.ScriptTarget.Latest, true);
const counters = script.statements.find(
    (statement) => ts.isFunctionDeclaration(statement) && statement.name.text === 'levelCounters',
);
const { outputText } = ts.transpileModule(counters.getText(script), {
    compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 },
});
const { levelCounters } = await import(
    `data:text/javascript;base64,${Buffer.from(
        `const shippingUrl = '', shovelUrl = '', networkUrl = '';\n${outputText}\nexport { levelCounters };`,
    ).toString('base64')}`
);

for (const shippingLevel of [0, 1, 3]) {
    test(`navigation statistics include the round bonus at level ${shippingLevel}`, () => {
        const state = { shippingLevel, roundBonus: 'river_workshop', terraformingLevel: 0, largestNetworkSize: 0 };
        assert.equal(levelCounters(state)[0].value, shippingLevel + 1);
        assert.equal(state.shippingLevel, shippingLevel);
    });

    test(`navigation statistics retain level ${shippingLevel} with other round bonuses`, () => {
        const state = { shippingLevel, roundBonus: 'coins', terraformingLevel: 0, largestNetworkSize: 0 };
        assert.equal(levelCounters(state)[0].value, shippingLevel);
    });
}
