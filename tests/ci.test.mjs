import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const workflow = require('js-yaml').load(readFileSync(new URL('../.github/workflows/ci.yml', import.meta.url), 'utf8'));

test('CI uses project containers for dependencies, build and checks', () => {
    const job = workflow.jobs['test-build'];
    assert.equal(job.env.APP_SERVICE, 'app');
    assert.equal(job.services, undefined);
    assert.ok(job.steps.every(step => !/setup-(php|node)/.test(step.uses ?? '')));
    const commands = job.steps.map(step => step.run ?? '').join('\n');
    assert.equal(workflow.permissions['packages'], 'read');
    assert.ok(job.steps.every(step => !step.uses?.startsWith('docker/build-push-action@')));
    assert.match(commands, /docker pull/);
    assert.match(commands, /docker tag .* sail-8.5\/app:latest/);
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

test('image publication is restricted to main and uses GHCR', () => {
    const image = require('js-yaml').load(readFileSync(new URL('../.github/workflows/ci-image.yml', import.meta.url), 'utf8'));
    assert.deepEqual(image.on.push.branches, ['main']);
    assert.ok(image.on.push.paths.includes('docker/php/8.5/**'));
    assert.equal(image.permissions.packages, 'write');
    const build = image.jobs.publish.steps.find(step => step.uses?.startsWith('docker/build-push-action@'));
    assert.equal(build.with.push, true);
    assert.equal(build.with.context, './docker/php/8.5');
    assert.equal(build.with['cache-to'], 'type=gha,scope=sail-php85,mode=max');
});

test('Sail uses runner UID instead of a fixed UID before starting containers', () => {
    const job = workflow.jobs['test-build'];
    assert.equal(job.env.WWWUSER, undefined);
    assert.equal(job.env.WWWGROUP, undefined);
    const userStep = job.steps.findIndex(step => step.name === 'Match Sail user to runner permissions');
    const startStep = job.steps.findIndex(step => step.name === 'Start application services');
    assert.ok(userStep >= 0 && userStep < startStep);
    assert.ok(job.steps[userStep].run.includes('WWWUSER=$(id -u)'));
    assert.ok(job.steps[userStep].run.includes('WWWGROUP=$(id -g)'));
    assert.ok(job.steps[userStep].run.includes('$GITHUB_ENV'));
    const permissions = job.steps.findIndex(step => step.name === 'Verify application write permissions');
    const key = job.steps.findIndex(step => step.name === 'Prepare application');
    assert.ok(permissions > startStep && permissions < key);
    assert.match(job.steps[permissions].run, /--user sail/);
});
