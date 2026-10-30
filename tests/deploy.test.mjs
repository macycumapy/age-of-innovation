import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, copyFileSync, readFileSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

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
