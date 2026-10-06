import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import test from 'node:test';

function sourceFiles(directory) {
    return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const path = `${directory}/${entry.name}`;
        return entry.isDirectory() ? sourceFiles(path) : [path];
    });
}

test('runtime Lucide imports use individual icons with equivalent exports', () => {
    const exports = readFileSync('node_modules/@lucide/vue/dist/esm/lucide-vue.mjs', 'utf8');
    let checked = 0;
    for (const path of sourceFiles('resources/js').filter((path) => /\.(vue|ts)$/.test(path))) {
        const source = readFileSync(path, 'utf8');
        assert.doesNotMatch(source, /import\s+\{[^}]+\}\s+from\s+['"]@lucide\/vue['"]/, path);
        for (const match of source.matchAll(/import (\w+) from ['"]@lucide\/vue\/dist\/esm\/(icons\/[^'"]+)['"]/g)) {
            const declaration = exports.split('\n').find((line) => line.includes(`from './${match[2]}'`));
            assert.ok(declaration?.includes(`default as ${match[1]}`), `${path}: ${match[1]}`);
            assert.ok(readFileSync(`node_modules/@lucide/vue/dist/esm/${match[2]}`, 'utf8'));
            checked++;
        }
    }
    assert.ok(checked > 0);
});
