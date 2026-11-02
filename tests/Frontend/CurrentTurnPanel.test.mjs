import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, h } from 'vue';

const actionModule = ts.transpileModule(
    readFileSync(new URL('../../resources/js/lib/gameActions.ts', import.meta.url), 'utf8'),
    { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } },
).outputText;
const gameActions = await import(`data:text/javascript;base64,${Buffer.from(actionModule).toString('base64')}`);
const { GameAction } = gameActions;

const source = readFileSync(
    new URL('../../resources/js/components/game/CurrentTurnPanel.vue', import.meta.url),
    'utf8',
);
const { descriptor } = parse(source);
const compiled = compileScript(descriptor, { id: 'current-turn-panel-test', inlineTemplate: true });
const { outputText } = ts.transpileModule(compiled.content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
});
const require = createRequire(import.meta.url);

async function renderPanel({ overrides = {}, gameOverrides = {}, showMenu = false } = {}) {
    const selections = [];
    const emitted = [];
    const passthrough = (_, { slots }) => slots.default?.();
    const loadModule = (name) => {
        if (name === '@inertiajs/vue3') {
            return { useHttp: () => ({}) };
        }

        if (name === '@/lib/gameActions') {
            return gameActions;
        }

        if (name === '@/components/ui/button') {
            return { Button: (_, { attrs, slots }) => h('button', attrs, slots.default?.()) };
        }

        if (name === '@/components/ui/dropdown-menu') {
            return {
                DropdownMenu: passthrough,
                DropdownMenuTrigger: passthrough,
                DropdownMenuContent: (_, { slots }) =>
                    showMenu ? h('div', { role: 'menu' }, slots.default?.()) : null,
                DropdownMenuItem: (_, { attrs, slots }) => {
                    selections.push(attrs.onSelect);

                    return h('div', { role: 'menuitem' }, slots.default?.());
                },
            };
        }

        if (name.startsWith('@/') || name.startsWith('@lucide/')) {
            return { default: () => null };
        }

        return require(name);
    };
    const componentModule = { exports: {} };
    new Function('require', 'module', 'exports', outputText)(loadModule, componentModule, componentModule.exports);
    const html = await renderToString(
        createSSRApp(componentModule.exports.default, {
            game: {
                data: {
                    id: 1,
                    status: 'active',
                    phase: 'actions',
                    pendingInteraction: null,
                    canPass: true,
                    ...gameOverrides,
                },
            },
            activePlayer: { id: 1, user_id: 10, name: 'Игрок', faction: 'alchemists' },
            currentPlayer: { id: 1, user_id: 10, faction: 'alchemists' },
            currentUserId: 10,
            isStartingBuildingStage: false,
            isOmarStartingTowerTurn: false,
            canSpendStartingSpade: false,
            pendingStartingSpadeHexId: null,
            pendingPalaceGuildHexId: null,
            selectedBridgeFromHexId: null,
            isPalaceBuildingSelectionActive: false,
            isBookBuildingSelectionActive: false,
            palaceBuildingSelectionSource: null,
            selectedPalaceWaterHexId: null,
            availableActions: [GameAction.BuildWorkshop, GameAction.SendScholar],
            onSelectAction: (action) => emitted.push(['selectAction', action]),
            onPass: () => emitted.push(['pass']),
            onFinishTurn: () => emitted.push(['finishTurn']),
            ...overrides,
        }),
    );

    return { html, selections, emitted };
}

test('available actions occupy one compact trigger beside the pass button', async () => {
    const { html, emitted } = await renderPanel();

    assert.match(html, /<button[^>]*aria-label="Доступные действия"[^>]*> Действия/);
    assert.match(html, /tabular-nums">2<\/span>/);
    assert.match(html, /> Пас <\/button>/);
    assert.doesNotMatch(html, /строительство дома|отправка учёного|role="menu"/);
    assert.deepEqual(emitted, []);
});

test('choosing a menu item emits navigation without passing or finishing the turn', async () => {
    const { html, selections, emitted } = await renderPanel({ showMenu: true });

    assert.equal((html.match(/role="menuitem"/g) ?? []).length, 2);
    assert.match(html, /строительство дома/);
    assert.match(html, /отправка учёного/);
    assert.doesNotMatch(html, /build_workshop|send_scholar/);
    assert.deepEqual(emitted, []);
    selections[1]();
    assert.deepEqual(emitted, [['selectAction', GameAction.SendScholar]]);
});

for (const [label, options] of [
    ['empty list', { overrides: { availableActions: [] } }],
    ['another player’s turn', { overrides: { activePlayer: { id: 2, user_id: 20, name: 'Другой игрок' } } }],
    ['unavailable actions', { gameOverrides: { canPass: false } }],
    ['finished game', { gameOverrides: { status: 'finished' } }],
    ['income phase', { gameOverrides: { phase: 'income' } }],
    ['pending choice', { gameOverrides: { pendingInteraction: { type: 'choose_town', playerId: 1 } } }],
    ['palace target selection', { overrides: { isPalaceBuildingSelectionActive: true } }],
    ['book target selection', { overrides: { isBookBuildingSelectionActive: true } }],
]) {
    test(`available actions menu stays hidden during ${label}`, async () => {
        const { html } = await renderPanel({ ...options, showMenu: true });

        assert.doesNotMatch(html, /aria-label="Доступные действия"|role="menu"/);
    });
}
