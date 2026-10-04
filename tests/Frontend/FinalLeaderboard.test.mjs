import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h } from 'vue';

const source = readFileSync(new URL('../../resources/js/components/game/FinalLeaderboard.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const compiled = compileScript(descriptor, { id: 'final-leaderboard-test', inlineTemplate: true });
const { outputText } = ts.transpileModule(compiled.content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
});
const require = createRequire(import.meta.url);
const componentModule = { exports: {} };
const loadModule = (name) => {
    if (name === '@/lib/gameDisplay') {
        return { factionNames: {}, playerColorValues: {} };
    }
    if (name.endsWith('.png')) {
        return { default: '/victory-points.png' };
    }
    if (name === '@lucide/vue') {
        const icon = () => h('svg');
        return { Crown: icon, Medal: icon, Trophy: icon };
    }
    return require(name);
};
new Function('require', 'module', 'exports', outputText)(loadModule, componentModule, componentModule.exports);
const FinalLeaderboard = componentModule.exports.default;

test('renders player and bot names and ranks them by final victory points', async () => {
    const players = [
        { id: 1, seat: 1, name: 'Анна', color: null, faction: null },
        { id: 2, seat: 2, name: 'Бот (Лёгкий)', color: null, faction: null },
        { id: 3, seat: 3, name: 'Иван', color: null, faction: null },
    ];
    const playerStates = [
        { playerId: 1, victoryPoints: 80 },
        { playerId: 2, victoryPoints: 100 },
        { playerId: 3, victoryPoints: 100 },
    ];
    const html = await renderToString(createSSRApp(FinalLeaderboard, { players, playerStates }));

    assert.match(html, /Партия завершена/);
    assert.match(html, /Анна/);
    assert.match(html, /Бот \(Лёгкий\)/);
    assert.match(html, /Иван/);
    assert.ok(html.indexOf('Бот (Лёгкий)') < html.indexOf('Иван'));
    assert.ok(html.indexOf('Иван') < html.indexOf('Анна'));
    assert.equal((html.match(/Итоговые победные очки: 100/g) ?? []).length, 2);
    assert.equal((html.match(/aria-label="1-е место"/g) ?? []).length, 2);
    assert.match(html, /aria-label="3-е место"/);
});
