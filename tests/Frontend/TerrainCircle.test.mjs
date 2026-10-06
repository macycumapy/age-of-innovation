import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';
import { compile } from '@vue/compiler-dom';
import { createSSRApp } from 'vue';
import * as Vue from 'vue';
import { renderToString } from '@vue/server-renderer';

const source = readFileSync(new URL('../../resources/js/components/game/TerrainCircle.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const { code } = compile(descriptor.template.content, { mode: 'function', prefixIdentifiers: true });

test('terrain circle follows the bundles inside the same grid', () => {
    const selector = readFileSync(
        new URL('../../resources/js/components/game/PlanningBundleSelector.vue', import.meta.url),
        'utf8',
    );
    const { descriptor: bundles } = parse(selector);
    assert.match(bundles.template.content, /<div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">/);
    assert.match(bundles.template.content, /<\/Form>\s*<TerrainCircle :players="game.data.players" \/>\s*<\/div>/);
});

test('terrain circle shows seven terrains and players on their chosen homeland', async () => {
    const terrains = ['desert', 'plains', 'swamp', 'lake', 'forest', 'mountain', 'wasteland'];
    const app = createSSRApp({
        render: new Function('Vue', code)(Vue),
        data: () => ({
            terrains,
            images: {},
            terrainNames: Object.fromEntries(terrains.map((terrain) => [terrain, terrain])),
            terrainPosition: () => ({ left: '50%', top: '50%' }),
            players: [
                { id: 1, name: 'Лесной игрок', homeland: 'forest' },
                { id: 2, name: 'Не выбрал', homeland: null },
            ],
        }),
    });
    const html = await renderToString(app);
    assert.equal(html.match(/<img /g).length, 7);
    for (const terrain of terrains) {
        assert.match(html, new RegExp(`title="${terrain}"`));
        assert.equal(html.includes(`>${terrain}</span>`), false);
    }
    assert.equal(html.match(/class="size-18 rounded-full object-contain drop-shadow-sm"/g).length, 7);
    assert.match(html, /Лесной игрок/);
    assert.equal(html.includes('Не выбрал'), false);
    assert.match(html, /Один шаг по кругу — одна лопата/);
});
