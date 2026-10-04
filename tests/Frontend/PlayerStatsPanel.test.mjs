import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';
import ts from 'typescript';

const source = readFileSync(new URL('../../resources/js/components/game/PlayerStatsPanel.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const script = ts.createSourceFile('PlayerStatsPanel.ts', descriptor.scriptSetup.content, ts.ScriptTarget.Latest, true);
const counters = script.statements.find((statement) => ts.isFunctionDeclaration(statement) && statement.name.text === 'levelCounters');
const { outputText } = ts.transpileModule(counters.getText(script), {
    compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 },
});
const { levelCounters } = await import(`data:text/javascript;base64,${Buffer.from(
    `const shippingUrl = '', shovelUrl = '', networkUrl = '';\n${outputText}\nexport { levelCounters };`,
).toString('base64')}`);

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
