import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';
import { computed, ref } from 'vue';

const source = readFileSync(new URL('../../resources/js/pages/games/Show.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const script = descriptor.scriptSetup.content;
const bridgeSelection = script.slice(
    script.indexOf('const pendingBridgeInteraction'),
    script.indexOf('const pendingBridge ='),
);

for (const [label, playerId, userId, activeUserId, visible] of [
    ['building player', 1, 10, 10, true],
    ['other player', 2, 20, 10, false],
    ['spectator', null, 30, 10, false],
    ['inactive building player', 1, 10, 20, false],
]) {
    test(`bridge placement options are private (${label})`, () => {
        const interaction = {
            type: 'place_bridge',
            playerId: 1,
            context: { pairs: [{ fromHexId: '0:0', toHexId: '1:1' }] },
        };
        const props = { game: { data: { pendingInteraction: interaction, activePlayerId: activeUserId } } };
        const { pendingBridgeInteraction, eligibleBridgePairs } = new Function(
            'computed',
            'props',
            'currentPlayer',
            'page',
            `${bridgeSelection}; return { pendingBridgeInteraction, eligibleBridgePairs };`,
        )(computed, props, ref(playerId === null ? undefined : { id: playerId }), {
            props: { auth: { user: { id: userId } } },
        });
        assert.equal(pendingBridgeInteraction.value, visible ? interaction : null);
        assert.deepEqual(eligibleBridgePairs.value, visible ? interaction.context.pairs : []);
    });
}
