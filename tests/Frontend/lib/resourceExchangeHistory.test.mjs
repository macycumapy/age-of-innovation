import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

const source = readFileSync(new URL('../../../resources/js/lib/resourceExchangeHistory.ts', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 },
});
const { resourceExchangeDetails } = await import(
    `data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`
);

for (const asStrings of [false, true]) {
    test(`resource exchanges show all rates, including books (strings: ${asStrings})`, () => {
        const count = asStrings ? '2' : 2;
        assert.deepEqual(
            resourceExchangeDetails(
                {
                    power_to_scholar: count,
                    power_to_tool: count,
                    power_to_coin: count,
                    scholar_to_tool: count,
                    tool_to_coin: count,
                    power_to_book: { law: count },
                    book_to_coin: { law: count },
                },
                { law: 'Право' },
            ),
            [
                'потрачено 10 Силы → получено 2 учёных',
                'потрачено 6 Силы → получено 2 инстр.',
                'потрачено 2 Силы → получено 2 золота',
                'потрачено 2 учёных → получено 2 инстр.',
                'потрачено 2 инстр. → получено 2 золота',
                'потрачено 10 Силы → получено 2 книги (Право)',
                'потрачено 2 книги (Право) → получено 2 золота',
            ],
        );
    });
}

test('resource exchange history ignores empty and malformed counts', () => {
    for (const count of [0, '0', -1, '-1', 1.5, '1.5', '', 'abc', true, null, undefined, [], {}, Infinity]) {
        assert.deepEqual(resourceExchangeDetails({ power_to_coin: count, power_to_book: { law: count } }, {}), []);
    }
    for (const value of [null, undefined, [], 'invalid']) {
        assert.deepEqual(resourceExchangeDetails(value, {}), []);
    }
});
