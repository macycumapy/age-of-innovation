import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const workflow = require('js-yaml').load(readFileSync(new URL('../.github/workflows/ci.yml', import.meta.url), 'utf8'));

test('CI uses project containers for dependencies, build and checks', () => {
    const job = workflow.jobs['test-build'];
    assert.equal(job.env.APP_SERVICE, 'app');
    assert.equal(job.services, undefined);
    assert.ok(job.steps.every(step => !/setup-(php|node)/.test(step.uses ?? '')));
    const commands = job.steps.map(step => step.run ?? '').join('\n');
    const build = job.steps.find(step => step.uses?.startsWith('docker/build-push-action@'));
    assert.ok(job.steps.some(step => step.uses?.startsWith('docker/setup-buildx-action@')));
    assert.equal(build.with.context, './docker/php/8.5');
    assert.equal(build.with.load, true);
    assert.equal(build.with.tags, 'sail-8.5/app:latest');
    assert.equal(build.with['cache-from'], 'type=gha,scope=sail-php85');
    assert.equal(build.with['cache-to'], 'type=gha,scope=sail-php85,mode=max');
    assert.equal(build.with['build-args'].trim(), 'WWWGROUP=${{ env.WWWGROUP }}');
    assert.match(commands, /docker compose run --rm --no-deps/);
    assert.match(commands, /vendor\/bin\/sail npm run build/);
    assert.match(commands, /vendor\/bin\/sail artisan test --compact/);
    assert.match(commands, /DB_HOST=mysql/);
    assert.equal(job.steps.at(-1).if, 'always()');
});

test('CD reads connection settings from repository variables', () => {
    const source = readFileSync(new URL('../.github/workflows/cd.yml', import.meta.url), 'utf8');
    const cd = require('js-yaml').load(source);
    assert.ok(cd.jobs.deploy);
    assert.doesNotMatch(source, /\bvariables\./);
    for (const name of ['SSH_HOST', 'SSH_USER', 'PROJECT_FOLDER']) {
        assert.ok(source.includes(`vars.${name}`));
    }
    assert.ok(source.includes('secrets.SSH_PRIVATE_KEY'));
});
