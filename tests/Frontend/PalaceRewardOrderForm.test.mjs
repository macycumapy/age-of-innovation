import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compile } from '@vue/compiler-dom';
import { parse } from '@vue/compiler-sfc';
import { renderToString } from '@vue/server-renderer';
import * as Vue from 'vue';

const source = readFileSync(
    new URL('../../resources/js/components/game/PalaceRewardOrderForm.vue', import.meta.url),
    'utf8',
);
const { descriptor } = parse(source);
const { code } = compile(descriptor.template.content, { mode: 'function', prefixIdentifiers: true });

test('palace reward order is selected locally, can be canceled, and requires confirmation', async () => {
    const state = Vue.reactive({
        gameId: 1,
        firstReward: null,
        PalaceRewardOrderController: { form: () => ({ action: '/palace-order', method: 'post' }) },
    });
    let clicks = [];
    let closeDialog;
    let confirm;
    async function render() {
        clicks = [];
        const app = Vue.createSSRApp({
            render: new Function('Vue', code)(Vue),
            setup: () => state,
        });
        app.component('Button', {
            setup(_props, { attrs, slots }) {
                return () => {
                    clicks.push(attrs.onClick);
                    return Vue.h('button', attrs, slots.default?.());
                };
            },
        });
        app.component('Form', {
            setup(_props, { attrs, slots }) {
                confirm = attrs.onSuccess;
                return () => Vue.h('form', attrs, slots.default?.({ processing: false }));
            },
        });
        app.component('Dialog', {
            props: ['open'],
            setup(props, { attrs, slots }) {
                closeDialog = attrs['onUpdate:open'];
                return () => (props.open ? Vue.h('div', { role: 'dialog' }, slots.default?.()) : null);
            },
        });
        for (const name of ['DialogContent', 'DialogHeader', 'DialogTitle', 'DialogDescription', 'DialogFooter']) {
            app.component(name, {
                setup:
                    (_props, { slots }) =>
                    () =>
                        Vue.h('div', slots.default?.()),
            });
        }
        return renderToString(app);
    }
    const initial = await render();
    assert.match(initial, /Установить мосты/);
    assert.match(initial, /Использовать лопаты/);
    assert.equal(initial.includes('<form'), false);
    clicks[0]();
    assert.equal(state.firstReward, 'bridges');
    const selected = await render();
    assert.match(selected, /name="first_reward" value="bridges"/);
    assert.match(selected, /Подтвердить порядок/);
    assert.match(selected, /role="dialog"/);
    assert.match(selected, /Сначала установить мосты/);
    clicks[2]();
    assert.equal(state.firstReward, null);
    assert.equal((await render()).includes('<form'), false);
    clicks[1]();
    assert.equal(state.firstReward, 'spades');
    assert.match(await render(), /name="first_reward" value="spades"/);
    closeDialog(false);
    assert.equal(state.firstReward, null);
    await render();
    clicks[1]();
    assert.match(await render(), /Сначала использовать лопаты/);
    confirm();
    assert.equal(state.firstReward, null);
});
