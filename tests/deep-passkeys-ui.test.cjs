'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const source = fs.readFileSync(path.join(__dirname, '../payload/src/control/admin.js'), 'utf8');
let browser;
test.before(async () => { browser = await chromium.launch({headless:true, ...(process.env.UI_BROWSER_EXECUTABLE ? {executablePath:process.env.UI_BROWSER_EXECUTABLE} : {})}); });
test.after(async () => { await browser?.close(); });
for (const [name,supported,count] of [['domain permits registration',true,0],['IP explains domain and disables registration',false,0],['three keys enforce limit',true,3]]) {
    test(name, async () => {
        const page = await browser.newPage();
        await page.route('https://fixture.example/**', async route => {
            if (route.request().url().includes('/control/passkeys.php/')) {
                await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,supported,reason:supported?'':'Для входа нужен HTTPS-домен.',passkeys:Array.from({length:count},(_,i)=>({id:i+1,name:'Fixture',created_at:'2026-01-01',last_used_at:null}))})});
            } else await route.fulfill({contentType:'text/html',body:'<!doctype html><body><input name="csrf" value="fixture"><button id="passkeyAddButton"></button><div id="passkeyList"></div><div id="passkeyError" hidden></div></body>'});
        });
        await page.goto('https://fixture.example/control/');
        await page.evaluate(() => {window.PublicKeyCredential = class {};});
        await page.addScriptTag({content:source});
        await page.waitForFunction(() => document.getElementById('passkeyList').innerHTML !== '');
        assert.equal(await page.locator('#passkeyAddButton').isDisabled(), !supported || count===3);
        if (!supported) assert.match(await page.locator('#passkeyError').innerText(), /HTTPS-домен/);
        await page.close();
    });
}
