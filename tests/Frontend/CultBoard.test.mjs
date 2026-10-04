import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { compileScript, parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import { createSSRApp } from 'vue';
import ts from 'typescript';

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
