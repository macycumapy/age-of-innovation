import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compile } from '@vue/compiler-dom';
import { parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';

const warning = 'Если хотя бы один сосед примет Силу, отменить ход будет невозможно.';
const passthrough = {
    render() {
        return Vue.h('div', this.$slots.default?.({ errors: {}, processing: false }));
    },
};

async function renderDialog(filename, state) {
    const source = readFileSync(new URL(`../../resources/js/components/game/${filename}.vue`, import.meta.url), 'utf8');
    const { descriptor } = parse(source);
    const { code } = compile(descriptor.template.content, { mode: 'function', prefixIdentifiers: true });
    const app = Vue.createSSRApp({
        render: new Function('Vue', code)(Vue),
        data: () => state,
    });
    for (const name of ['Dialog', 'DialogContent', 'DialogHeader', 'DialogTitle', 'DialogDescription', 'DialogFooter', 'DialogClose', 'Form', 'Button', 'InputError', 'Alert', 'AlertDescription', 'ArrowRight', 'TriangleAlert']) {
        app.component(name, passthrough);
    }
    return renderToString(app);
}

for (const afterTerraforming of [false, true]) {
    test(`building dialog warns before confirmation (after terraforming: ${afterTerraforming})`, async () => {
        const html = await renderDialog('BuildWorkshopDialog', {
            dialogOpen: true, afterTerraforming, selectedHexes: [], selectedHexId: '0:0', hasNeighboringOpponent: true,
            gameId: 1, workshopImage: () => '', toolUrl: '', coinUrl: '', toolCost: 1, coinCost: 2,
            buildSucceeded: () => {},
            WorkshopController: { form: () => ({}) }, TerraformWorkshopController: { form: () => ({}) },
        });
        assert.ok(html.includes(warning));
        assert.match(html, /bg-amber-50/);
        assert.match(html, /font-medium text-amber-950 dark:text-amber-100/);
        assert.ok(html.indexOf(warning) < html.indexOf('Подтвердить строительство'));
    });
}

for (const selectedAction of ['upgrade', 'annex']) {
    test(`upgrade dialog shows the warning only for upgrades (${selectedAction})`, async () => {
        const html = await renderDialog('BuildingUpgradeDialog', {
            isOpen: true, selectedAction, options: [], canPlaceAnnex: true, annexUrl: '', hasNeighboringOpponent: true,
            gameId: 1, hexId: '0:0', selectedTarget: 'school', selectedOption: {}, actionSucceeded: () => {},
            BuildingUpgradeController: { form: () => ({}) }, AnnexPlacementController: { create: { form: () => ({}) } },
        });
        assert.equal(html.includes(warning), selectedAction === 'upgrade');
        if (selectedAction === 'upgrade') {
            assert.match(html, /bg-amber-50/);
            assert.match(html, /font-medium text-amber-950 dark:text-amber-100/);
            assert.ok(html.indexOf(warning) < html.indexOf('Подтвердить улучшение'));
        }
    });
}

test('building and upgrade dialogs hide warnings without neighboring opponents', async () => {
    const common = {
        gameId: 1, hasNeighboringOpponent: false, actionSucceeded: () => {},
    };
    const building = await renderDialog('BuildWorkshopDialog', {
        ...common, dialogOpen: true, afterTerraforming: false, selectedHexes: [], selectedHexId: '0:0',
        workshopImage: () => '', toolUrl: '', coinUrl: '', toolCost: 1, coinCost: 2,
        buildSucceeded: () => {}, WorkshopController: { form: () => ({}) },
    });
    const upgrade = await renderDialog('BuildingUpgradeDialog', {
        ...common, isOpen: true, selectedAction: 'upgrade', options: [], canPlaceAnnex: false,
        hexId: '0:0', selectedTarget: 'school', selectedOption: {},
        BuildingUpgradeController: { form: () => ({}) },
    });
    assert.equal(building.includes(warning), false);
    assert.equal(upgrade.includes(warning), false);
});
