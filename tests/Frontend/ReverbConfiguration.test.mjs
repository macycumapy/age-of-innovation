import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
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
        new Function('createInertiaApp', 'configureEcho', 'AppLayout', 'AuthLayout', 'SettingsLayout', outputText)(
            (options) => {
                inertiaOptions = options;
            },
            (options) => {
                echoOptions = options;
            },
            {},
            {},
            {},
        );
        assert.equal(echoOptions, undefined);
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
    });
}
