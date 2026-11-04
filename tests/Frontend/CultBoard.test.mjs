import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createSSRApp, ref } from 'vue';

const source = readFileSync(new URL('../../resources/js/components/game/CultBoard.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const compiled = compileScript(descriptor, { id: 'cult-board-test', inlineTemplate: true });
const { outputText } = ts.transpileModule(compiled.content.replaceAll('import.meta.glob', 'loadImages'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
});
const require = createRequire(import.meta.url);
const componentModule = { exports: {} };
const loadImages = () => Object.fromEntries(
    ['red', 'blue', 'green', 'black'].flatMap((color) => ['token', 'scientist'].map((image) => [
        `../../../images/buildings/${color}/${image}.png`, `/images/${color}/${image}.png`,
    ])),
);
new Function('require', 'module', 'exports', 'loadImages', outputText)(
    (name) => name.endsWith('.png') ? { default: '/cult-board.png' } : require(name),
    componentModule, componentModule.exports, loadImages,
);
const CultBoard = componentModule.exports.default;
const knowledge = { banking: 0, law: 0, engineering: 0, medicine: 0 };
const neutralKnowledgeState = {
    color: 'black', knowledge, scholarDisciplineIds: Object.keys(knowledge), scholarSlotIndex: 1,
};

for (const playerCount of [2, 3]) {
    test(`renders valid markers for ${playerCount} players with saved neutral faction data`, async () => {
        const players = ['red', 'blue', 'green'].slice(0, playerCount).map((color, index) => ({ id: index + 1, color }));
        const playerStates = players.map((player) => ({
            playerId: player.id, knowledge, scholarDisciplineIds: [], scholarSlotIndexes: [],
        }));
        const html = await renderToString(createSSRApp(CultBoard, {
            players, playerStates, neutralKnowledgeState, canSendScholar: false,
        }));

        for (const player of players) {
            assert.equal((html.match(new RegExp(`src="/images/${player.color}/token.png"`, 'g')) ?? []).length, 4);
        }

        assert.equal((html.match(/src="\/images\/black\/token.png"/g) ?? []).length, playerCount === 2 ? 4 : 0);
        assert.equal((html.match(/src="\/images\/black\/scientist.png"/g) ?? []).length, playerCount === 2 ? 4 : 0);
        assert.equal((html.match(/<img /g) ?? []).length, playerCount === 2 ? 17 : 13);
    });
}

test('score popups use the scored discipline and an upward fading animation', () => {
    assert.match(source, /popup\.discipline/);
    assert.match(source, /\+\{\{ popup.points \}\}/);
    const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');
    assert.match(css, /translateY\(-75px\)/);
    assert.match(css, /opacity: 0/);
});

function createPopupTracker(initial) {
    const page = readFileSync(new URL('../../resources/js/pages/games/Show.vue', import.meta.url), 'utf8');
    const { descriptor } = parse(page);
    const script = ts.createSourceFile('Show.ts', descriptor.scriptSetup.content, ts.ScriptTarget.Latest, true);
    const start = script.statements.findIndex((statement) => ts.isVariableStatement(statement)
        && statement.declarationList.declarations.some((declaration) => declaration.name.getText(script) === 'pointPopups'));
    assert.ok(start >= 0, 'score popup state must exist');
    const statements = script.statements.slice(start);
    const end = statements.findIndex((statement) => ts.isExpressionStatement(statement)
        && ts.isCallExpression(statement.expression) && statement.expression.expression.getText(script) === 'watch');
    assert.ok(end >= 0, 'score popup watcher must exist');
    const { outputText } = ts.transpileModule(
        statements.slice(0, end + 1).map((statement) => statement.getText(script)).join('\n'),
        { compilerOptions: { target: ts.ScriptTarget.ES2022 } },
    );
    let update;
    const timeouts = [];
    const popups = new Function('props', 'ref', 'watch', 'window', `${outputText}; return pointPopups;`)(
        { game: { data: initial }, history: { data: [], version: initial.version } },
        ref,
        (_source, callback) => {
            update = callback;
        },
        { setTimeout: (callback) => timeouts.push(callback) },
    );

    return { popups, timeouts, update: (game, history = { data: [], version: game.version }) => update([game, history]) };
}

test('score popups retain the hex between staging and confirmation', () => {
    const initial = {
        version: 0,
        board: { hexes: [{ id: '2:3', townTileId: null, terrain: 'plains', building: null }] },
        playerBoardStates: [{ playerId: 1, scholarDisciplineIds: [], victoryPoints: 20 }],
    };
    const { popups, timeouts, update } = createPopupTracker(initial);
    const staged = structuredClone(initial);
    staged.version = 1;
    staged.board.hexes[0].building = { type: 'workshop', playerId: 1 };
    update(staged);
    assert.deepEqual(popups.value, []);
    assert.equal(timeouts.length, 0);

    const confirmed = structuredClone(staged);
    confirmed.version = 2;
    confirmed.playerBoardStates[0].victoryPoints += 2;
    update(confirmed);
    assert.deepEqual(popups.value, [{ id: 1, points: 2, hexId: '2:3', discipline: undefined }]);
    assert.equal(timeouts.length, 1);
    timeouts[0]();
    assert.deepEqual(popups.value, []);

    const unrelatedScore = structuredClone(confirmed);
    unrelatedScore.version = 3;
    unrelatedScore.playerBoardStates[0].victoryPoints += 1;
    update(unrelatedScore);
    assert.deepEqual(popups.value, []);
});

test('remote score updates can locate the hex from shared confirmed history', () => {
    const page = readFileSync(new URL('../../resources/js/pages/games/Show.vue', import.meta.url), 'utf8');
    assert.match(page, /history.data.find/);
    assert.match(page, /action.player\?\.id === state.playerId/);
    assert.match(page, /scoringAction\?\.payload.hex_id/);
});

for (const firstUpdate of ['game', 'history']) {
    test(`score popups wait for matching live snapshots when ${firstUpdate} arrives first`, () => {
        const initial = {
            version: 0,
            board: { hexes: [{ id: '2:3', townTileId: null, terrain: 'plains', building: null }] },
            playerBoardStates: [{ playerId: 1, scholarDisciplineIds: [], victoryPoints: 20 }],
        };
        const { popups, update } = createPopupTracker(initial);
        const confirmed = structuredClone(initial);
        confirmed.version = 1;
        confirmed.playerBoardStates[0].victoryPoints = 22;
        const history = {
            version: 1,
            data: [{ id: 1, type: 'terraform_and_build', player: { id: 1 }, payload: { hex_id: '2:3' } }],
        };

        if (firstUpdate === 'game') {
            update(confirmed, { version: 0, data: [] });
        } else {
            update(initial, history);
        }

        assert.deepEqual(popups.value, []);
        update(confirmed, history);
        assert.deepEqual(popups.value, [{ id: 1, points: 2, hexId: '2:3', discipline: undefined }]);
        update(confirmed, history);
        assert.equal(popups.value.length, 1);
    });
}

test('town scoring uses the town marker, including water towns', () => {
    const page = readFileSync(new URL('../../resources/js/pages/games/Show.vue', import.meta.url), 'utf8');
    assert.match(page, /old.townTileId !== entry.townTileId/);
    assert.match(page, /townMarkerHex \?\?/);
    assert.match(page, /payload.marker_hex_id/);
    const board = readFileSync(new URL('../../resources/js/components/game/BoardMap.vue', import.meta.url), 'utf8');
    assert.match(board, /\[\.\.\.visibleHexes, \.\.\.waterTownTokens\]/);
});
