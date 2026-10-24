'use strict';

// Every request is intercepted; these fixtures never enroll an actual server or bot.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const helpers = require('../web/assets/wizard.js');
const root = path.resolve(__dirname, '..');
let browser;
test.before(async () => { browser = await chromium.launch({headless: true, ...(process.env.UI_BROWSER_EXECUTABLE ? {executablePath: process.env.UI_BROWSER_EXECUTABLE} : {})}); });
test.after(async () => { await browser?.close(); });

test('navigation accepts only the own application mount and preserves it', () => {
    assert.equal(helpers.safeHttpsUrl('https://posts.example/GitPosted', 'control/'), 'https://posts.example/GitPosted/control/');
    assert.equal(helpers.safeHttpsUrl('https://posts.example/GitPosted/control/'), 'https://posts.example/GitPosted/control/');
    assert.equal(helpers.safeHttpsUrl('https://posts.example', 'control/'), 'https://posts.example/control/');
    for (const value of ['https://posts.example/GitRePosted', 'https://posts.example/GitPosted/../', 'https://posts.example/%47itPosted', 'https://posts.example/GitPosted//', 'https://posts.example\\evil/GitPosted']) {
        assert.equal(helpers.safeHttpsUrl(value, 'control/'), null, value);
    }
});

async function wizardFixture(prefix, options = {}) {
    const page = await browser.newPage();
    const calls = []; const unexpected = []; const errors = [];
    const base = 'https://posts.example' + prefix;
    const status = {authenticated: false, secure: !options.http, installed: Boolean(options.installed), base_url: base,
        host: 'posts.example', site_url: base, site_host: 'posts.example', public_ip: 'Server IP', tls_ready: true,
        admin_url: base + '/control/', csrf: 'a'.repeat(64), job: {status: 'idle', steps: []}};
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.pathname === prefix + '/install/api.php') {
            const action = url.searchParams.get('action');
            const body = route.request().method() === 'POST' ? route.request().postDataJSON() : null;
            calls.push({action, body, url: route.request().url()});
            if (action === 'login') status.authenticated = true;
            const result = action === 'login' ? {authenticated: true, csrf: status.csrf} : {...status, csrf: status.authenticated ? status.csrf : undefined};
            await route.fulfill({contentType: 'application/json', body: JSON.stringify({ok: true, result})});
            return;
        }
        const files = {[prefix + '/install/']: ['text/html', 'web/index.php'], [prefix + '/install/assets/wizard.js']: ['text/javascript', 'web/assets/wizard.js'], [prefix + '/install/assets/wizard.css']: ['text/css', 'web/assets/wizard.css']};
        if (files[url.pathname]) {
            const [contentType, file] = files[url.pathname];
            await route.fulfill({contentType, body: fs.readFileSync(path.join(root, file))}); return;
        }
        unexpected.push(url.pathname); await route.abort('blockedbyclient');
    });
    await page.goto((options.http ? 'http' : 'https') + '://posts.example' + prefix + '/install/#setup=' + 'x'.repeat(32));
    return {page, calls, unexpected, errors, close: async () => { assert.deepEqual(errors, []); assert.deepEqual(unexpected, []); await page.close(); }};
}

for (const prefix of ['', '/GitPosted']) {
    test(`fragment enrollment retains ${prefix || 'legacy root'} and uses its own API`, async () => {
        const f = await wizardFixture(prefix);
        try {
            await f.page.locator('#wizard-screen').waitFor({state: 'visible'});
            assert.equal(new URL(f.page.url()).pathname, prefix + '/install/');
            assert.equal(new URL(f.page.url()).hash, '');
            assert.equal(f.calls.filter(call => call.action === 'login').length, 1);
            assert.ok(f.calls.every(call => new URL(call.url).pathname === prefix + '/install/api.php'));
        } finally { await f.close(); }
    });
    test(`HTTP ${prefix || 'legacy root'} removes setup secret and keeps mounted HTTPS link`, async () => {
        const f = await wizardFixture(prefix, {http: true});
        try {
            await f.page.locator('#http-screen').waitFor({state: 'visible'});
            assert.equal(await f.page.locator('#https-link').getAttribute('href'), 'https://posts.example' + prefix + '/install/');
            assert.equal(f.calls.filter(call => call.action === 'login').length, 0);
            assert.equal(new URL(f.page.url()).hash, '');
        } finally { await f.close(); }
    });
    test(`completed ${prefix || 'legacy root'} links its own admin panel without enrollment`, async () => {
        const f = await wizardFixture(prefix, {installed: true});
        try {
            await f.page.locator('#complete-screen').waitFor({state: 'visible'});
            assert.equal(await f.page.locator('#control-link').getAttribute('href'), 'https://posts.example' + prefix + '/control/');
            assert.equal(f.calls.filter(call => call.action === 'login').length, 0);
        } finally { await f.close(); }
    });
    test(`passkey list uses ${prefix || 'legacy root'} configured API path`, async () => {
        const page = await browser.newPage(); const calls = []; const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('https://posts.example/**', async route => {
            const url = new URL(route.request().url());
            if (url.pathname === prefix + '/control/passkeys.php/') {
                calls.push(url.pathname);
                await route.fulfill({contentType: 'application/json', body: JSON.stringify({ok: true, supported: true, passkeys: []})});
            } else if (url.pathname === prefix + '/control/') {
                await route.fulfill({contentType: 'text/html', body: `<!doctype html><body data-app-base-path="${prefix}"><input name="csrf" value="fixture"><button id="passkeyAddButton"></button><div id="passkeyList"></div><div id="passkeyError" hidden></div></body>`});
            } else { calls.push('UNEXPECTED ' + url.pathname); await route.abort('blockedbyclient'); }
        });
        try {
            await page.goto('https://posts.example' + prefix + '/control/');
            await page.evaluate(() => { window.PublicKeyCredential = class {}; });
            await page.addScriptTag({content: fs.readFileSync(path.join(root, 'payload/src/control/admin.js'), 'utf8')});
            await page.waitForFunction(() => document.getElementById('passkeyList').innerHTML !== '');
            assert.deepEqual(calls, [prefix + '/control/passkeys.php/']);
            assert.deepEqual(errors, []);
        } finally { await page.close(); }
    });
}
