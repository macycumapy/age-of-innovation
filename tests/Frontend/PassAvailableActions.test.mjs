import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compile } from '@vue/compiler-dom';
import { parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import * as Vue from 'vue';

const { computed, ref } = Vue;
const actionModule = ts.transpileModule(
    readFileSync(new URL('../../resources/js/lib/gameActions.ts', import.meta.url), 'utf8'),
    { compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 } },
).outputText;
const { GameAction, gameActionTitles } = await import(
    `data:text/javascript;base64,${Buffer.from(actionModule).toString('base64')}`
);

const source = readFileSync(new URL('../../resources/js/pages/games/Show.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const script = descriptor.scriptSetup.content;
const transpile = (source) =>
    ts.transpileModule(source, {
        compilerOptions: { target: ts.ScriptTarget.ES2022 },
    }).outputText;
const warning = transpile(
    script.slice(script.indexOf('const availableActionsBeforePass'), script.indexOf('function selectPowerAction')),
);
const navigation = transpile(
    script.slice(
        script.indexOf('function navigateToAvailableAction'),
        script.indexOf('const availableActionsBeforePass'),
    ),
);

function createActionNavigation(overrides = {}) {
    const references = {
        boardMapRef: ref(null),
        playerBoardsRef: ref(null),
        cultBoardRef: ref(null),
        innovationBoardRef: ref(null),
        isShippingAdvancementDialogOpen: ref(false),
        isTerraformingAdvancementDialogOpen: ref(false),
        ...overrides,
    };
    const navigate = new Function(
        'GameAction',
        ...Object.keys(references),
        `${navigation}; return navigateToAvailableAction;`,
    )(GameAction, ...Object.values(references));

    return { navigate, ...references };
}

for (const [label, books, cost, isUsed, expected] of [
    ['mixed books', { banking: 2, law: 2, engineering: 0, medicine: 0 }, 4, false, true],
    ['single book type', { banking: 0, law: 0, engineering: 4, medicine: 0 }, 4, false, true],
    ['insufficient books', { banking: 1, law: 1, engineering: 1, medicine: 0 }, 4, false, false],
    ['already used action', { banking: 2, law: 2, engineering: 0, medicine: 0 }, 4, true, false],
    [
        'unassigned books cannot be spent',
        { banking: 0, law: 0, engineering: 0, medicine: 0, unassigned: 4 },
        4,
        false,
        false,
    ],
]) {
    test(`pass warning includes book actions correctly (${label})`, () => {
        const state = ref({ books, power: { bowlThree: 0, bowlTwo: 0 }, availableInnovationActionIds: [] });
        const props = {
            game: {
                data: {
                    powerActions: [],
                    bookActionStates: [{ cost, isUsed }],
                    buildingUpgrades: [],
                    canSendScholar: false,
                    canMakeInnovation: false,
                },
            },
        };
        const actions = new Function(
            'computed',
            'currentPlayerState',
            'props',
            'buildableWorkshopHexIds',
            'GameAction',
            `${warning}; return availableActionsBeforePass;`,
        )(computed, state, props, ref([]), GameAction);
        assert.equal(actions.value.includes(GameAction.BookAction), expected);
    });
}

test('available action navigation opens the matching board without submitting an action', () => {
    for (const [action, expected] of [
        [GameAction.SendScholar, 'cultBoardRef'],
        [GameAction.PurchaseInnovation, 'innovationBoardRef'],
        [GameAction.BuildWorkshop, 'boardMapRef'],
        [GameAction.PowerAction, 'boardMapRef'],
        [GameAction.BookAction, 'boardMapRef'],
        [GameAction.UpgradeBuilding, 'boardMapRef'],
        [GameAction.FactionAction, 'playerBoardsRef'],
        [GameAction.CompetencyAction, 'playerBoardsRef'],
        [GameAction.RoundBonusAction, 'playerBoardsRef'],
        [GameAction.PalaceAction, 'playerBoardsRef'],
        [GameAction.InnovationAction, 'playerBoardsRef'],
    ]) {
        const calls = [];
        const references = Object.fromEntries(
            ['boardMapRef', 'playerBoardsRef', 'cultBoardRef', 'innovationBoardRef'].map((name) => [
                name,
                ref({
                    $el: { scrollIntoView: (options) => calls.push({ target: name, options }) },
                }),
            ]),
        );
        const { navigate } = createActionNavigation(references);

        navigate(action);
        assert.deepEqual(calls, [{ target: expected, options: { behavior: 'smooth', block: 'center' } }]);
    }
});

test('navigation tolerates unmounted boards and follows the current component reference', () => {
    const { navigate, innovationBoardRef } = createActionNavigation();
    const calls = [];

    for (const action of Object.values(GameAction)) {
        assert.doesNotThrow(() => navigate(action));
    }

    innovationBoardRef.value = { $el: { scrollIntoView: () => calls.push('first') } };
    navigate(GameAction.PurchaseInnovation);
    innovationBoardRef.value = { $el: { scrollIntoView: () => calls.push('replacement') } };
    navigate(GameAction.PurchaseInnovation);
    innovationBoardRef.value = null;
    navigate(GameAction.PurchaseInnovation);
    assert.deepEqual(calls, ['first', 'replacement']);
});

for (const canAdvanceShipping of [false, true]) {
    for (const canAdvanceTerraforming of [false, true]) {
        test(`advancement availability follows player permissions (shipping: ${canAdvanceShipping}, terraforming: ${canAdvanceTerraforming})`, () => {
            const state = ref({
                books: { banking: 0, law: 0, engineering: 0, medicine: 0 },
                power: { bowlThree: 0, bowlTwo: 0 },
                availableInnovationActionIds: [],
                canAdvanceShipping,
                canAdvanceTerraforming,
            });
            const props = { game: { data: { powerActions: [], bookActionStates: [], buildingUpgrades: [] } } };
            const actions = new Function(
                'computed',
                'currentPlayerState',
                'props',
                'buildableWorkshopHexIds',
                'GameAction',
                `${warning}; return availableActionsBeforePass;`,
            )(computed, state, props, ref([]), GameAction);

            assert.equal(actions.value.includes(GameAction.AdvanceShipping), canAdvanceShipping);
            assert.equal(actions.value.includes(GameAction.AdvanceTerraforming), canAdvanceTerraforming);

            state.value.canAdvanceShipping = false;
            state.value.canAdvanceTerraforming = false;
            assert.deepEqual(actions.value, []);
        });
    }
}

test('pass warning displays shared action titles instead of enum identifiers', async () => {
    const passSource = readFileSync(
        new URL('../../resources/js/components/game/PassDialog.vue', import.meta.url),
        'utf8',
    );
    const { descriptor: passDialog } = parse(passSource);
    const template = passDialog.template.content;
    const start = template.lastIndexOf('<div', template.indexOf('v-if="availableActions.length > 0"'));
    const { code } = compile(template.slice(start, template.indexOf('</div>', start) + '</div>'.length), {
        mode: 'function',
        prefixIdentifiers: true,
    });
    const render = new Function('Vue', code)(Vue);
    const actions = Object.values(GameAction);
    const renderWarning = (availableActions) =>
        renderToString(
            Vue.createSSRApp({
                render,
                data: () => ({ availableActions, gameActionTitles }),
            }),
        );
    const html = await renderWarning(actions);

    for (const action of actions) {
        assert.ok(gameActionTitles[action]?.trim(), `Missing title for ${action}`);
        assert.ok(html.includes(gameActionTitles[action]));
        assert.equal(html.includes(action), false);
    }

    assert.doesNotMatch(await renderWarning([]), /У вас ещё есть доступные действия/);
});

for (const [action, shippingOpen, terraformingOpen] of [
    [GameAction.AdvanceShipping, true, false],
    [GameAction.AdvanceTerraforming, false, true],
]) {
    test(`${action} opens its confirmation dialog without navigating or performing the action`, () => {
        const boardRef = ref({
            $el: { scrollIntoView: () => assert.fail('Advancement should open a dialog without scrolling') },
        });
        const { navigate, isShippingAdvancementDialogOpen, isTerraformingAdvancementDialogOpen } =
            createActionNavigation({
                boardMapRef: boardRef,
                playerBoardsRef: boardRef,
                cultBoardRef: boardRef,
                innovationBoardRef: boardRef,
            });

        navigate(action);
        assert.equal(isShippingAdvancementDialogOpen.value, shippingOpen);
        assert.equal(isTerraformingAdvancementDialogOpen.value, terraformingOpen);
    });
}
