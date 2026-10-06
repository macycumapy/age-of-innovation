import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';
import { computed, ref } from 'vue';

const source = readFileSync(new URL('../../resources/js/pages/games/Show.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const script = descriptor.scriptSetup.content;
const warning = script
    .slice(script.indexOf('const availableActionsBeforePass'), script.indexOf('function selectPowerAction'))
    .replace('const actions: string[]', 'const actions');

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
            `${warning}; return availableActionsBeforePass;`,
        )(computed, state, props, ref([]));
        assert.equal(actions.value.includes('действие за книги'), expected);
    });
}
