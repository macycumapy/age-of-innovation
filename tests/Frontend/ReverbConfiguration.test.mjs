import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { echoLive } from '@inertiajs/core/echo';
import ts from 'typescript';

const source = readFileSync(new URL('../../resources/js/app.ts', import.meta.url), 'utf8');
const bootstrap = source.slice(source.indexOf('createInertiaApp({'), source.indexOf('// This will set'));
const { outputText } = ts.transpileModule(`const appName = 'build-time-name';\n${bootstrap}`, {
    compilerOptions: { target: ts.ScriptTarget.ES2022 },
});

for (const { port, scheme } of [
    { port: 443, scheme: 'https' },
    { port: 8080, scheme: 'http' },
]) {
    test(`Echo uses the server connection before mounting the page (${scheme})`, () => {
        let inertiaOptions;
        let echoOptions;
        const subscriptions = [];
        const stoppedSubscriptions = [];
        const leftChannels = [];
        const liveOptions = echoLive({
            echoIsConfigured: () => echoOptions !== undefined,
            echo: () => ({
                private: (name) => ({
                    listen: (event, handler) => subscriptions.push({ name, event, handler }),
                    stopListening: (event, handler) => stoppedSubscriptions.push({ name, event, handler }),
                    error: () => undefined,
                }),
                leaveChannel: (name) => leftChannels.push(name),
            }),
        });
        new Function('createInertiaApp', 'configureEcho', 'echo', 'AppLayout', 'AuthLayout', 'SettingsLayout', outputText)(
            (options) => {
                inertiaOptions = options;
            },
            (options) => {
                echoOptions = options;
            },
            () => liveOptions,
            {},
            {},
            {},
        );
        assert.equal(echoOptions, undefined);
        assert.equal(inertiaOptions.live, liveOptions);
        inertiaOptions.withApp({}, {
            page: {
                props: {
                    reverb: { key: 'server-public-key', host: 'websocket.example.test', port, scheme },
                },
            },
        });
        assert.deepEqual(echoOptions, {
            broadcaster: 'reverb',
            key: 'server-public-key',
            wsHost: 'websocket.example.test',
            wsPort: port,
            wssPort: port,
            forceTLS: scheme === 'https',
        });

        const handler = () => undefined;
        const unsubscribe = inertiaOptions.live.transport.subscribe(
            { name: 'games', type: 'private' }, 'games.changed', handler,
        );
        assert.deepEqual(subscriptions, [{ name: 'games', event: '.games.changed', handler }]);

        unsubscribe();

        assert.deepEqual(stoppedSubscriptions, subscriptions);
        assert.deepEqual(leftChannels, ['private-games']);
    });
}
