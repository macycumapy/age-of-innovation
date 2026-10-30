import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const workflow = require('js-yaml').load(readFileSync(new URL('../.github/workflows/ci-cd.yml', import.meta.url), 'utf8'));

test('CI uses project containers for dependencies, build and checks', () => {
    const job = workflow.jobs['test-build'];
    assert.equal(job.env.APP_SERVICE, 'app');
    assert.equal(job.services, undefined);
    assert.ok(job.steps.every(step => !/setup-(php|node)/.test(step.uses ?? '')));
    const commands = job.steps.map(step => step.run ?? '').join('\n');
    assert.equal(workflow.permissions['packages'], 'read');
    assert.match(commands, /docker pull/);
    assert.match(commands, /docker tag .* sail-8.5\/app:latest/);
    assert.match(commands, /docker compose run --rm --no-deps/);
    assert.match(commands, /vendor\/bin\/sail npm run build/);
    assert.match(commands, /vendor\/bin\/sail artisan test --compact/);
    assert.match(commands, /DB_HOST=mysql/);
    assert.equal(job.steps.at(-1).if, 'always()');
});

test('CD reads connection settings from repository variables', () => {
    const source = readFileSync(new URL('../.github/workflows/ci-cd.yml', import.meta.url), 'utf8');
    const cd = require('js-yaml').load(source);
    assert.ok(cd.jobs.deploy);
    assert.doesNotMatch(source, /\bvariables\./);

    for (const name of ['SSH_HOST', 'SSH_USER', 'PROJECT_FOLDER']) {
        assert.ok(source.includes(`vars.${name}`));
    }

    assert.ok(source.includes('secrets.SSH_PRIVATE_KEY'));
});

test('tests wait for image preparation and build locally only when no registry image is available', () => {
    const prepare = workflow.jobs['prepare-image'];
    const checks = workflow.jobs['test-build'];
    assert.equal(checks.needs, 'prepare-image');
    assert.equal(prepare.permissions.packages, 'write');
    assert.ok(prepare.outputs.available.includes("steps.publish.outcome == 'success'"));
    const image = prepare.steps.find(step => step.id === 'image');
    assert.equal(image.env.IMAGE_HASH, "${{ hashFiles('docker/php/8.5/**') }}");
    const publish = prepare.steps.find(step => step.id === 'publish');
    assert.ok(publish.if.includes("github.event_name != 'pull_request'"));
    assert.ok(publish.if.includes("github.ref == 'refs/heads/main'"));
    assert.ok(publish.if.includes("steps.cached-image.outputs.found != 'true'"));
    assert.equal(publish.with.push, true);
    const local = checks.steps.find(step => step.uses?.startsWith('docker/build-push-action@'));
    assert.equal(local.if, "needs.prepare-image.outputs.available != 'true'");
    assert.equal(local.with.push, false);
    assert.equal(local.with.load, true);
    assert.equal(local.with.tags, 'sail-8.5/app:latest');
    assert.equal(local.with.context, publish.with.context);
    assert.equal(local.with['build-args'], publish.with['build-args']);
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

test('CI does not pass Artisan interaction flags to PHPUnit', () => {
    const step = workflow.jobs['test-build'].steps.find(step => step.name === 'Run PHP tests');
    assert.equal(step.run, 'vendor/bin/sail artisan test --compact');
});

test('first-party actions use Node 24 versions', () => {
    for (const file of ['ci-cd.yml']) {
        const config = require('js-yaml').load(readFileSync(new URL(`../.github/workflows/${file}`, import.meta.url), 'utf8'));

        for (const job of Object.values(config.jobs)) {
            for (const step of job.steps) {
                if (step.uses?.startsWith('actions/checkout@')) {
                    assert.equal(step.uses, 'actions/checkout@v5');
                }

                if (step.uses?.startsWith('actions/upload-artifact@')) {
                    assert.equal(step.uses, 'actions/upload-artifact@v6');
                }

                if (step.uses?.startsWith('actions/download-artifact@')) {
                    assert.equal(step.uses, 'actions/download-artifact@v7');
                }
            }
        }
    }
});

test('deployment waits for successful tests and uses artifacts from the same run', () => {
    const deploy = workflow.jobs.deploy;
    assert.equal(deploy.needs, 'test-build');
    assert.equal(deploy.if, "github.event_name == 'push' && github.ref == 'refs/heads/main'");
    const artifact = deploy.steps.find(step => step.uses?.startsWith('actions/download-artifact@'));
    assert.equal(artifact.with.name, 'frontend-build');
    assert.equal(artifact.with['run-id'], undefined);
    assert.equal(workflow.on.workflow_run, undefined);
    assert.equal(deploy.concurrency['cancel-in-progress'], false);
    assert.equal(workflow.concurrency['cancel-in-progress'], "${{ github.ref != 'refs/heads/main' }}");
    const source = JSON.stringify(deploy);
    assert.ok(source.includes('github.sha'));
    assert.ok(source.includes('github.run_id'));
    assert.ok(!source.includes('workflow_run'));
});

test('Docker actions use Node 24 versions', () => {
    for (const job of Object.values(workflow.jobs)) {
        for (const step of job.steps) {
            if (step.uses?.startsWith('docker/login-action@')) {
                assert.equal(step.uses, 'docker/login-action@v4');
            }

            if (step.uses?.startsWith('docker/setup-buildx-action@')) {
                assert.equal(step.uses, 'docker/setup-buildx-action@v4');
            }
        }
    }
});
