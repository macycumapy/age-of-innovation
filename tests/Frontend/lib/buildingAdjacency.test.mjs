import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

const source = readFileSync(new URL('../../../resources/js/lib/buildingAdjacency.ts', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 },
});
const { hasAdjacentOpponent } = await import(`data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`);

function board(ownerPlayerId, adjacentHexIds = ['1:0'], bridges = []) {
    return {
        hexes: [
            { id: '0:0', adjacentHexIds, building: null },
            { id: '1:0', adjacentHexIds: [], building: ownerPlayerId === null ? null : { ownerPlayerId } },
        ],
        bridges,
    };
}

test('detects adjacent opponents, but ignores own buildings and empty hexes', () => {
    assert.equal(hasAdjacentOpponent(board(2), '0:0', 1), true);
    assert.equal(hasAdjacentOpponent(board(1), '0:0', 1), false);
    assert.equal(hasAdjacentOpponent(board(null), '0:0', 1), false);
    assert.equal(hasAdjacentOpponent(board(2, []), '0:0', 1), false);
});

test('bridges connect opponents in either direction regardless of bridge owner', () => {
    for (const ownerPlayerId of [1, 2, 3]) {
        for (const [fromHexId, toHexId] of [['0:0', '1:0'], ['1:0', '0:0']]) {
            assert.equal(hasAdjacentOpponent(board(2, [], [{ fromHexId, toHexId, ownerPlayerId }]), '0:0', 1), true);
        }
    }
});

test('does not warn without a selected hex or active player', () => {
    assert.equal(hasAdjacentOpponent(board(2), null, 1), false);
    assert.equal(hasAdjacentOpponent(board(2), 'missing', 1), false);
    assert.equal(hasAdjacentOpponent(board(2), '0:0', null), false);
});
