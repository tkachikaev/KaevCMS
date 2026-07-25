import { randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { createServer } from 'node:net';
import process from 'node:process';

const root = resolve(import.meta.dirname, '../..');
const runtimeDirectory = resolve(root, 'storage/framework/testing/browser');
const databasePath = resolve(runtimeDirectory, `kaevcms-browser-${process.pid}.sqlite`);
const avatarDirectory = resolve(root, 'public/uploads/account-avatars');
const browserAvatarPath = resolve(avatarDirectory, 'browser-avatar.png');
const findAvailablePort = async () => new Promise((resolvePromise, rejectPromise) => {
    const probe = createServer();
    probe.unref();
    probe.once('error', rejectPromise);
    probe.listen(0, '127.0.0.1', () => {
        const address = probe.address();
        const port = typeof address === 'object' && address !== null ? address.port : null;
        probe.close((error) => {
            if (error) {
                rejectPromise(error);
                return;
            }
            if (port === null) {
                rejectPromise(new Error('Could not allocate a local port for browser tests.'));
                return;
            }
            resolvePromise(port);
        });
    });
});
const browserPort = process.env.PLAYWRIGHT_BASE_URL
    ? Number(new URL(process.env.PLAYWRIGHT_BASE_URL).port || 80)
    : await findAvailablePort();
const baseUrl = process.env.PLAYWRIGHT_BASE_URL || `http://127.0.0.1:${browserPort}`;
const adminEmail = 'browser-admin@example.test';
const adminPassword = 'BrowserPassword123!';
const playerEmail = 'browser-player@example.test';
const playerPassword = 'BrowserPlayerPassword123!';

mkdirSync(dirname(databasePath), { recursive: true });
mkdirSync(avatarDirectory, { recursive: true });
writeFileSync(databasePath, '');
writeFileSync(browserAvatarPath, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64'));

const environment = {
    ...process.env,
    APP_ENV: 'testing',
    APP_DEBUG: 'false',
    APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
    APP_URL: baseUrl,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: databasePath,
    CACHE_STORE: 'array',
    SESSION_DRIVER: 'file',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
    LOG_CHANNEL: 'stderr',
    BROWSER_TEST_ADMIN_EMAIL: adminEmail,
    BROWSER_TEST_ADMIN_PASSWORD: adminPassword,
    PLAYWRIGHT_BASE_URL: baseUrl,
    PLAYWRIGHT_ADMIN_EMAIL: adminEmail,
    PLAYWRIGHT_ADMIN_PASSWORD: adminPassword,
    BROWSER_TEST_PLAYER_EMAIL: playerEmail,
    BROWSER_TEST_PLAYER_PASSWORD: playerPassword,
    PLAYWRIGHT_PLAYER_EMAIL: playerEmail,
    PLAYWRIGHT_PLAYER_PASSWORD: playerPassword,
};

const run = (command, args) => {
    const result = spawnSync(command, args, {
        cwd: root,
        env: environment,
        stdio: 'inherit',
        shell: false,
    });

    if (result.status !== 0) {
        throw new Error(`${command} ${args.join(' ')} failed with exit code ${result.status ?? 1}.`);
    }
};

const stopProcessTree = (child) => {
    if (!child || child.killed) {
        return;
    }

    if (process.platform === 'win32') {
        spawnSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], { stdio: 'ignore' });
        return;
    }

    child.kill('SIGTERM');
};

const waitForServer = async () => {
    for (let attempt = 0; attempt < 80; attempt += 1) {
        try {
            const response = await fetch(`${baseUrl}/admin/login`, { redirect: 'manual' });
            if (response.status < 500) {
                return;
            }
        } catch {
            // Server is still starting.
        }

        await new Promise((resolvePromise) => setTimeout(resolvePromise, 250));
    }

    throw new Error(`CMS did not start at ${baseUrl}.`);
};

let server = null;

try {
    if (!existsSync(resolve(root, 'vendor/autoload.php'))) {
        throw new Error('Composer dependencies are missing. Run composer install first.');
    }

    run('php', ['artisan', 'migrate:fresh', '--force']);
    run('php', ['artisan', 'db:seed', '--class=Database\\Seeders\\BrowserTestSeeder', '--force']);

    server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${browserPort}`], {
        cwd: root,
        env: environment,
        stdio: ['ignore', 'pipe', 'pipe'],
        shell: false,
    });

    server.stdout.on('data', (chunk) => process.stdout.write(chunk));
    server.stderr.on('data', (chunk) => process.stderr.write(chunk));

    await waitForServer();

    const playwrightCli = resolve(root, 'node_modules/@playwright/test/cli.js');
    run(process.execPath, [playwrightCli, 'test', '--config=playwright.config.mjs']);
} finally {
    stopProcessTree(server);
    rmSync(databasePath, { force: true });
    rmSync(browserAvatarPath, { force: true });
}
