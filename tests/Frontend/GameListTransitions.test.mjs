import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { compile } from '@vue/compiler-dom';
import { parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as Vue from 'vue';

const source = readFileSync(new URL('../../resources/js/pages/games/Index.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const listTemplate = descriptor.template.content.slice(
    descriptor.template.content.indexOf('<TransitionGroup'),
    descriptor.template.content.indexOf('</TransitionGroup>') + '</TransitionGroup>'.length,
);
const { code } = compile(listTemplate, { mode: 'function', prefixIdentifiers: true });
const hooks = ts.transpileModule(
    descriptor.scriptSetup.content.slice(descriptor.scriptSetup.content.indexOf('function prepareListItemLeave')),
    { compilerOptions: { target: ts.ScriptTarget.ES2022 } },
).outputText;
const listHooks = new Function(`${hooks}; return { prepareListItemLeave, resetListItemPosition };`)();

function node(type, text = '') {
    return {
        type, text, children: [], parent: null, style: {},
        offsetLeft: 12, offsetTop: 24,
        getBoundingClientRect: () => ({ width: 320, height: 200 }),
    };
}

function remove(child) {
    if (child.parent) {
        child.parent.children.splice(child.parent.children.indexOf(child), 1);
        child.parent = null;
    }
}

function textContent(element) {
    return element.text + element.children.map(textContent).join('');
}

function renderList(t, games) {
    const previousElement = globalThis.Element;
    globalThis.Element = class {};
    t.after(() => {
        if (previousElement === undefined) {
            delete globalThis.Element;
        } else {
            globalThis.Element = previousElement;
        }
    });
    const entering = [];
    const leaving = [];
    const controlledTransitions = {
        inheritAttrs: false,
        setup(_, { attrs, slots }) {
            return () => Vue.h(Vue.TransitionGroup, {
                ...attrs,
                css: false,
                onEnter: (element, done) => {
                    entering.push(textContent(element));
                    done();
                },
                onLeave: (element, done) => leaving.push({ element, done }),
            }, slots);
        },
    };
    const renderer = Vue.createRenderer({
        createElement: (tag) => node(tag),
        createText: (text) => node('text', text),
        createComment: () => node('comment'),
        insert(child, parent, anchor = null) {
            remove(child);
            child.parent = parent;
            const index = anchor === null ? parent.children.length : parent.children.indexOf(anchor);
            parent.children.splice(index, 0, child);
        },
        remove,
        setText: (element, text) => {
            element.text = text;
        },
        setElementText: (element, text) => {
            element.text = text;
        },
        parentNode: (element) => element.parent,
        nextSibling: (element) => element.parent?.children[element.parent.children.indexOf(element) + 1] ?? null,
        patchProp: () => undefined,
    });
    const state = Vue.reactive({
        games: { data: games.map((id) => ({ id, status: 'lobby' })) },
        highlightedGameIds: new Set(),
        statusNames: { lobby: 'Ожидает игроков' },
        mapVariantNames: {},
        formatDate: () => 'сегодня',
        gameButtonLabel: () => 'Присоединиться',
        show: (id) => `/games/${id}`,
        ...listHooks,
    });
    const root = node('root');
    const app = renderer.createApp({
        render: new Function('Vue', code)({ ...Vue, TransitionGroup: controlledTransitions }),
        setup: () => state,
    });

    for (const name of ['Card', 'CardHeader', 'CardTitle', 'CardDescription', 'CardContent', 'Button', 'Link']) {
        app.component(name, (_, { slots }) => Vue.h('div', slots.default?.()));
    }

    app.mount(root);
    t.after(() => app.unmount());

    return {
        entering, leaving,
        text: () => textContent(root),
        async update(ids) {
            state.games = { data: ids.map((id) => ({ id, status: 'lobby' })) };
            await Vue.nextTick();
        },
        async finishLeaving() {
            leaving.shift().done();
            await Vue.nextTick();
        },
    };
}

test('initial cards render immediately and only added cards enter the list', async (t) => {
    const list = renderList(t, [1]);
    assert.deepEqual(list.entering, []);
    assert.match(list.text(), /Игра №1/);

    await list.update([2, 1]);
    assert.equal(list.entering.length, 1);
    assert.match(list.entering[0], /Игра №2/);

    await list.update([1, 2]);
    assert.equal(list.entering.length, 1);
    assert.deepEqual(list.leaving, []);
});

test('removed cards retain their dimensions and remain visible until the leave transition ends', async (t) => {
    const list = renderList(t, [1, 2]);
    await list.update([2]);

    assert.equal(list.leaving.length, 1);
    assert.match(list.text(), /Игра №1/);
    const removed = list.leaving[0].element;
    assert.deepEqual(removed.style, { width: '320px', height: '200px', left: '12px', top: '24px' });

    await list.finishLeaving();
    assert.doesNotMatch(list.text(), /Игра №1/);
    assert.match(list.text(), /Игра №2/);
    assert.deepEqual(removed.style, { width: '', height: '', left: '', top: '' });
});

test('the first card and the empty state transition without unmounting the list', async (t) => {
    const list = renderList(t, []);
    assert.deepEqual(list.entering, []);
    assert.match(list.text(), /У вас пока нет игр/);

    await list.update([1]);
    assert.match(list.entering[0], /Игра №1/);
    assert.match(textContent(list.leaving[0].element), /У вас пока нет игр/);
    await list.finishLeaving();
    assert.doesNotMatch(list.text(), /У вас пока нет игр/);

    await list.update([]);
    assert.match(list.entering[1], /У вас пока нет игр/);
    assert.match(list.text(), /Игра №1/);
    await list.finishLeaving();
    assert.doesNotMatch(list.text(), /Игра №1/);
    assert.match(list.text(), /У вас пока нет игр/);
});
