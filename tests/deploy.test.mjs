import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, copyFileSync, readFileSync, existsSync, rmSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

const require = createRequire(import.meta.url);
const workflow = require('js-yaml').load(readFileSync(new URL('../.github/workflows/ci-cd.yml', import.meta.url), 'utf8'));

for (const failure of ['none', 'fetch', 'revision', 'deploy']) {
    test(`remote script executes deployment and propagates errors (${failure})`, () => {
        const directory = mkdtempSync(join(tmpdir(), 'remote-deployment-'));
        const project = join(directory, 'project with spaces');
        const bin = join(directory, 'bin');
        const marker = join(project, 'deployed-build');

        try {
            mkdirSync(project);
            mkdirSync(bin);
            writeFileSync(join(bin, 'git'), `#!/usr/bin/env bash
set -eu
if [ "$1" = fetch ] && [ "$DEPLOY_TEST_FAILURE" = fetch ]; then
    exit 23
fi
if [ "$1" = rev-parse ]; then
    if [ "$DEPLOY_TEST_FAILURE" = revision ]; then
        echo unexpected-revision
    else
        echo expected-revision
    fi
fi
`, { mode: 0o755 });
            writeFileSync(join(project, 'deploy.sh'), `#!/usr/bin/env bash
set -eu
if [ "$DEPLOY_TEST_FAILURE" = deploy ]; then
    exit 24
fi
printf '%s' "$1" > deployed-build
`);
            const remote = workflow.jobs.deploy.steps.find(step => step.name === 'Run command on remote server');
            assert.equal(typeof remote.with.script, 'string');
            const script = remote.with.script
                .replaceAll('${{ vars.PROJECT_FOLDER }}', project)
                .replaceAll('${{ github.sha }}', 'expected-revision')
                .replaceAll('${{ github.run_id }}', '123');
            const result = spawnSync('bash', ['-c', script], {
                cwd: directory,
                env: { ...process.env, PATH: `${bin}:${process.env.PATH}`, DEPLOY_TEST_FAILURE: failure },
            });
            assert.equal(result.status, { none: 0, fetch: 23, revision: 1, deploy: 24 }[failure], result.stderr.toString());
            assert.equal(existsSync(marker), failure === 'none');

            if (failure === 'none') {
                assert.equal(readFileSync(marker, 'utf8'), '.deploy/123/public/build');
            }
        } finally {
            rmSync(directory, { recursive: true, force: true });
        }
    });
}

for (const failMigration of [false, true]) {
    test(`deployment ${failMigration ? 'restores availability after migration failure' : 'publishes build and restarts services'}`, () => {
        const directory = mkdtempSync(join(tmpdir(), 'deployment-'));

        try {
            mkdirSync(join(directory, 'vendor/bin'), { recursive: true });
            mkdirSync(join(directory, '.deploy/build'), { recursive: true });
            copyFileSync(new URL('../deploy.sh', import.meta.url), join(directory, 'deploy.sh'));
            writeFileSync(join(directory, '.deploy/build/manifest.json'), '{}');
            writeFileSync(join(directory, 'vendor/bin/sail'), `#!/usr/bin/env bash
set -eu
test "$APP_SERVICE" = app
printf '%s\\n' "$*" >> commands.log
${failMigration ? 'if [[ "$*" == artisan\\ migrate* ]]; then exit 1; fi' : ''}
`, { mode: 0o755 });
            const result = spawnSync('bash', ['deploy.sh', '.deploy/build'], { cwd: directory });
            assert.equal(result.status, failMigration ? 1 : 0, result.stderr.toString());
            const commands = readFileSync(join(directory, 'commands.log'), 'utf8');
            assert.match(commands, /composer install --no-dev --no-interaction/);
            assert.ok(commands.trimEnd().endsWith('artisan up'));
            assert.equal(existsSync(join(directory, 'public/build/manifest.json')), !failMigration);
            assert.equal(commands.includes('restart app horizon reverb'), !failMigration);
            assert.equal(commands.includes('up -d --no-deps --force-recreate mcp nginx'), !failMigration);
            assert.equal(existsSync(join(directory, '.deploy/build')), failMigration);
        } finally {
            rmSync(directory, { recursive: true, force: true });
        }
    });
}

test('Sail is available in production dependencies', () => {
    const composer = JSON.parse(readFileSync(new URL('../composer.json', import.meta.url), 'utf8'));
    const lock = JSON.parse(readFileSync(new URL('../composer.lock', import.meta.url), 'utf8'));
    assert.ok(composer.require['laravel/sail']);
    assert.equal(composer['require-dev']['laravel/sail'], undefined);
    assert.ok(lock.packages.some(item => item.name === 'laravel/sail'));
    assert.ok(!lock['packages-dev'].some(item => item.name === 'laravel/sail'));
});
