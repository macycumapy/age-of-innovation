import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { computed, effectScope, nextTick, reactive, ref, watch } from 'vue';

const source = readFileSync(new URL('../../resources/js/components/game/GameHistory.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const tracking = descriptor.scriptSetup.content.slice(
    descriptor.scriptSetup.content.indexOf('const entries ='),
    descriptor.scriptSetup.content.indexOf('function handleScroll'),
);
const { outputText } = ts.transpileModule(tracking, {
    compilerOptions: { target: ts.ScriptTarget.ES2022 },
});

function entriesFrom(latest, oldest) {
    return Array.from({ length: latest - oldest + 1 }, (_, index) => ({
        id: latest - index,
        sequence: latest - index,
        type: 'send_scholar',
        player: { id: 2, user_id: 2 },
    }));
}

function historyTracker(t, initial, reply = async () => ({ data: [], hasMore: false, version: 1 })) {
    const props = reactive({ gameId: 42, history: initial });
    const notifications = [];
    const requests = [];
    const request = {
        processing: false,
        async submit(route) {
            requests.push(route);
            request.processing = true;

            try {
                return await reply();
            } finally {
                request.processing = false;
            }
        },
    };
    const scope = effectScope();
    const history = scope.run(() => new Function(
        'props', 'ref', 'computed', 'watch', 'useHttp', 'usePage',
        'shouldNotifyAboutPlayerAction', 'showActionNotification', 'GameHistoryController',
        `${outputText}; return { entries, hasMore, loadError, loadMore };`,
    )(
        props, ref, computed, watch, () => request, () => ({ props: { auth: { user: { id: 1 } } } }),
        (player) => player?.user_id !== 1,
        (entry) => notifications.push(entry.id),
        (gameId, options) => ({ gameId, ...options }),
    ));
    t.after(() => scope.stop());

    return {
        ...history,
        notifications,
        requests,
        async update(page) {
            props.history = page;
            await nextTick();
        },
    };
}

test('live history preserves loaded older pages without duplicate entries or notifications', async (t) => {
    const history = historyTracker(t,
        { data: entriesFrom(50, 26), hasMore: true, version: 1 },
        async () => ({ data: entriesFrom(26, 1), hasMore: false, version: 1 }),
    );
    await history.loadMore();
    assert.equal(history.entries.value.length, 50);
    assert.equal(history.hasMore.value, false);
    assert.deepEqual(history.requests, [{ gameId: 42, query: { before_sequence: 26 } }]);

    await history.update({ data: entriesFrom(51, 27), hasMore: true, version: 2 });
    assert.equal(history.entries.value.length, 51);
    assert.equal(history.entries.value.at(-1).sequence, 1);
    assert.equal(history.hasMore.value, false);
    assert.deepEqual(history.notifications, [51]);

    await history.update({ data: entriesFrom(51, 27), hasMore: true, version: 2 });
    assert.equal(history.entries.value.length, 51);
    assert.deepEqual(history.notifications, [51]);
});

test('rollback removes deleted actions and does not notify about old actions', async (t) => {
    const history = historyTracker(t, { data: entriesFrom(50, 26), hasMore: true, version: 1 });

    await history.update({ data: entriesFrom(20, 1), hasMore: false, version: 2 });
    assert.deepEqual(history.entries.value.map((entry) => entry.sequence), entriesFrom(20, 1).map((entry) => entry.sequence));
    assert.equal(history.hasMore.value, false);
    assert.deepEqual(history.notifications, []);

    await history.update({ data: [], hasMore: false, version: 3 });
    assert.deepEqual(history.entries.value, []);
    assert.equal(history.hasMore.value, false);
});

test('an older page requested before rollback cannot reintroduce deleted actions', async (t) => {
    let resolvePage;
    const history = historyTracker(t,
        { data: entriesFrom(50, 26), hasMore: true, version: 1 },
        () => new Promise((resolve) => {
            resolvePage = resolve;
        }),
    );
    const loading = history.loadMore();
    await history.update({ data: entriesFrom(20, 1), hasMore: false, version: 2 });
    resolvePage({ data: entriesFrom(25, 1), hasMore: false, version: 1 });
    await loading;

    assert.equal(history.entries.value.length, 20);
    assert.equal(history.entries.value[0].sequence, 20);
    assert.equal(history.hasMore.value, false);
});

test('a failed page load can be retried', async (t) => {
    let attempts = 0;
    const history = historyTracker(t,
        { data: entriesFrom(50, 26), hasMore: true, version: 1 },
        async () => {
            attempts++;

            if (attempts === 1) {
                throw new Error('Connection lost');
            }

            return { data: entriesFrom(25, 1), hasMore: false, version: 1 };
        },
    );

    await history.loadMore();
    assert.equal(history.loadError.value, true);
    assert.equal(history.entries.value.length, 25);

    await history.loadMore();
    assert.equal(history.loadError.value, false);
    assert.equal(history.entries.value.length, 50);
    assert.equal(history.hasMore.value, false);
});
