import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

const source = readFileSync(new URL('../../../resources/js/lib/gameHistoryDisplay.ts', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 },
});
const { finalScoringPlace, shouldNotifyAboutPlayerAction } = await import(`data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`);

test('does not notify about other players finishing their turns', () => {
    assert.equal(shouldNotifyAboutPlayerAction({ user_id: 2 }, 1, 'finish_turn'), false);
    assert.equal(shouldNotifyAboutPlayerAction({ user_id: 2 }, 1, 'finish_starting_building_turn'), false);
    assert.equal(shouldNotifyAboutPlayerAction({ user_id: null }, 1, 'finish_turn'), false);
});

test('continues notifying about other player actions only', () => {
    assert.equal(shouldNotifyAboutPlayerAction({ user_id: 2 }, 1, 'build'), true);
    assert.equal(shouldNotifyAboutPlayerAction({ user_id: null }, 1, 'build'), true);
    assert.equal(shouldNotifyAboutPlayerAction({ user_id: 1 }, 1, 'build'), false);
    assert.equal(shouldNotifyAboutPlayerAction(null, 1, 'build'), false);
});

test('formats single and shared final scoring places', () => {
    assert.equal(finalScoringPlace(1, 1), '1-е место');
    assert.equal(finalScoringPlace(1, 2), '1-е - 2-е место');
    assert.equal(finalScoringPlace(2, 2), '2-е - 3-е место');
    assert.equal(finalScoringPlace(1, 3), '1-е - 2-е - 3-е место');
});
