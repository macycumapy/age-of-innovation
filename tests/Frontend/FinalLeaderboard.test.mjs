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
    if (name === '@/components/game/VictoryPointSourceIcon.vue') {
        const iconSource = readFileSync(new URL('../../resources/js/components/game/VictoryPointSourceIcon.vue', import.meta.url), 'utf8');
        const { descriptor: iconDescriptor } = parse(iconSource);
        const iconScript = compileScript(iconDescriptor, { id: 'score-source-icon-test', inlineTemplate: true });
        const iconCode = ts.transpileModule(iconScript.content, {
            compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
        }).outputText;
        const iconModule = { exports: {} };
        new Function('require', 'module', 'exports', iconCode)(loadModule, iconModule, iconModule.exports);
        return iconModule.exports;
    }
    if (name === '@/components/ui/tooltip') {
        const passthrough = (_, { slots }) => slots.default?.();
        return {
            Tooltip: passthrough,
            TooltipProvider: passthrough,
            TooltipTrigger: passthrough,
            TooltipContent: (_, { slots }) => h('div', { role: 'tooltip' }, slots.default?.()),
        };
    }
    if (name === '@/lib/gameDisplay') {
        return { factionNames: {}, playerColorValues: {} };
    }
    if (/\.(png|jpg)$/.test(name)) {
        return { default: `/images/${name.split('/').slice(-2).join('/')}` };
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
    const html = (await renderToString(createSSRApp(FinalLeaderboard, { players, playerStates }))).replace(/<!--.*?-->/g, '');

    assert.match(html, /Партия завершена/);
    assert.match(html, /Анна/);
    assert.match(html, /Бот \(Лёгкий\)/);
    assert.match(html, /Иван/);
    assert.ok(html.indexOf('Бот (Лёгкий)') < html.indexOf('Иван'));
    assert.ok(html.indexOf('Иван') < html.indexOf('Анна'));
    assert.equal((html.match(/Итоговые победные очки: 100/g) ?? []).length, 2);
});

test('shows score icons beside each player total including losses and legacy points', async () => {
    const players = [
        { id: 1, seat: 1, name: 'Анна', color: null, faction: null },
        { id: 2, seat: 2, name: 'Бот', color: null, faction: null },
    ];
    const playerStates = [
        { playerId: 1, victoryPoints: 33, victoryPointSources: { starting: 20, town: 7, innovation: 8, power_offers: -2 } },
        { playerId: 2, victoryPoints: 30, victoryPointSources: { starting: 20, untracked: 10 } },
    ];
    const html = (await renderToString(createSSRApp(FinalLeaderboard, { players, playerStates }))).replace(/<!--.*?-->/g, '');

    for (const label of ['Статистика победных очков', 'Цели раундов', 'Федерации', 'Инновации', 'Компетенции', 'Финальные цели игры', 'Бонусы раундов', 'Бонусы расы', 'Бонусы дворца', 'Потери за принятую Силу']) {
        assert.ok(html.includes(label), label);
    }
    const rows = html.match(/<li\b.*?<\/li>/g);
    assert.equal(rows.length, 2);
    assert.match(rows[0], /Анна/);
    assert.match(rows[0], /role="tooltip">Федерации: 7</);
    assert.match(rows[0], /role="tooltip">Инновации: 8</);
    assert.match(rows[0], /text-red-600[^>]*aria-label="Потери за принятую Силу: -2"/);
    assert.match(rows[0], /Итоговые победные очки: 33/);
    assert.match(rows[1], /role="tooltip">Федерации: 0</);
    assert.match(rows[1], /aria-label="Очки до начала учёта статистики: 10"/);
    assert.match(rows[1], /Итоговые победные очки: 30/);
    assert.doesNotMatch(html, /<table/);
    assert.doesNotMatch(html, / title=/);
    assert.equal((html.match(/role="tooltip"/g) ?? []).length, 32);
    assert.equal((rows[0].match(/tabindex="0"/g) ?? []).length, 16);
    assert.equal((rows[1].match(/tabindex="0"/g) ?? []).length, 16);
    assert.match(rows[0], /aria-label="Федерации: 7"[^>]*><span[^>]*data-score-source="town"[^>]*aria-hidden="true"/);
    assert.match(rows[0], /data-score-source="town"/);
    assert.match(rows[0], /data-score-source="power_offers"/);
    assert.equal((rows[0].match(/data-score-category=/g) ?? []).length, 16);
    assert.match(rows[0], /data-score-source="town".*?src="\/images\/token_parts\/score_town.png"/);
    assert.match(rows[0], /data-score-source="power_offers".*?src="\/images\/token_parts\/score_power_offers.png"/);
    assert.match(rows[0], /size-15/);
    assert.match(rows[0], /<polygon[^>]*fill="#bd8d32"/);
    assert.match(rows[1], /data-score-category="town".*?aria-hidden="true">—<\/span>/);
    assert.ok(rows[0].indexOf('Итоговые победные очки: 33') < rows[0].indexOf('<strong>Анна'), 'player caption is below the scoring strip');
});

test('renders distinct illustrated score sources without replacing the player portrait', async () => {
    const html = await renderToString(createSSRApp(FinalLeaderboard, {
        players: [{ id: 1, seat: 1, name: 'Навигатор', color: null, faction: 'navigators' }],
        playerStates: [{ playerId: 1, victoryPoints: 27, victoryPointSources: { starting: 20, faction: 2, untracked: 5 } }],
    }));

    assert.equal((html.match(/src="\/images\/factions\/navigators.jpg"/g) ?? []).length, 1);
    const illustrations = [];
    const categories = [...html.matchAll(/data-score-category="([^"]+)".*?(?=data-score-category=|Итоговые победные очки)/g)];
    assert.equal(categories.length, 16);
    for (const [cell, category] of categories) {
        const illustration = cell.match(/<img[^>]+>/);
        assert.ok(illustration, `${category} has a decorative illustration beside its accessible label`);
        assert.match(illustration[0], /\balt(?:="")?(?=[ >])/);
        illustrations.push(illustration[0].match(/src="([^"]+)"/)[1]);
    }
    assert.doesNotMatch(html, /<image href="\/images\/factions/);
    assert.doesNotMatch(html, /\/images\/competencies\/|\/images\/round_bonus_cards\//);
    assert.equal(new Set(illustrations).size, 16);
    assert.ok(illustrations.every((url) => !url.includes('/factions/')));
    assert.match(html, /aria-label="Бонусы расы: 2"/);
    assert.match(html, /text-3xl font-black/);
});

test('does not show a legacy warning for fully tracked scores', async () => {
    const html = await renderToString(createSSRApp(FinalLeaderboard, {
        players: [{ id: 1, seat: 1, name: 'Анна', color: null, faction: null }],
        playerStates: [{ playerId: 1, victoryPoints: 20, victoryPointSources: { starting: 20 } }],
    }));

    assert.doesNotMatch(html, /Для старых партий/);
    assert.doesNotMatch(html, /Очки до начала учёта статистики/);
});
