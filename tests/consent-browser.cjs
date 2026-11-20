'use strict';
// Real browser + unmodified WP Consent API 2.1.0 script regression test.
// Third-party requests are intercepted locally and never sent to providers.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {chromium} = require('playwright-core');
const root = path.join(__dirname, '..');
const apiSource = process.env.WP_CONSENT_API_JS;
assert.ok(apiSource && fs.existsSync(apiSource), 'Provide the WP Consent API 2.1.0 JS source');
const fingerprint = 'a'.repeat(64);
const capture = /(?:youtube\.com|player\.vimeo\.com|google-analytics\.com|connect\.facebook\.net)$/i;
const html = [
    '<!doctype html><html><head><meta charset="utf-8"><script>',
    'window.DendrilaPrivacyConsentEarlyConfig={fingerprint:"' + fingerprint + '",wpConsentApi:true};',
    'window.DendrilaPrivacyConsent={storageKey:"pixel_trackers_manager_consent_v2",fingerprint:"' + fingerprint + '",',
    'wpConsentApi:true,retentionDays:180,style:"default",domains:{',
    'external:["youtube.com/embed","player.vimeo.com","google.com/maps/embed"],',
    'statistics:["google-analytics.com"],marketing:["connect.facebook.net"]}};',
    'window.consent_api={consent_type:"optin",waitfor_consent_hook:false,',
    'cookie_expiration:180,cookie_prefix:"wp_consent",',
    'services:[{name:"facebook-pixel",category:"marketing"},{name:"google-analytics",category:"statistics"}]};',
    '</script><script src="/assets/consent-bootstrap.js"></script>',
    '<script src="/assets/wp-consent-api.js"></script>',
    '<script>window.consentApiCalls=[];window.rawWPSetConsent=window.wp_set_consent;',
    'window.wp_set_consent=function(category,value){window.consentApiCalls.push([category,value]);',
    'return window.rawWPSetConsent(category,value);};',
    'window.wp_set_service_consent("facebook-pixel",true);</script>',
    '<script src="/assets/consent.js"></script></head><body><main id="host"></main></body></html>'
].join('\n');

const server = http.createServer((req, res) => {
    let data, mime;
    if (req.url === '/') { data = html; mime = 'text/html'; }
    else if (req.url === '/assets/wp-consent-api.js') {
        data = fs.readFileSync(apiSource); mime = 'application/javascript';
    } else if (req.url === '/assets/consent-bootstrap.js' || req.url === '/assets/consent.js') {
        data = fs.readFileSync(path.join(root, req.url)); mime = 'application/javascript';
    } else { res.writeHead(404); res.end(); return; }
    res.writeHead(200, {'Content-Type':mime}); res.end(data);
});
(async () => {
    let browser;
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        browser = await chromium.launch({
            executablePath:process.env.CHROME_PATH || '/usr/bin/google-chrome',
            headless:true,args:['--no-sandbox','--disable-dev-shm-usage']
        });
        const page = await browser.newPage();
        const requests = [];
        const jsErrors = [];
        page.on('pageerror', e => jsErrors.push(e.message));
        page.on('request', req => {
            if (capture.test(new URL(req.url()).hostname)) { requests.push(req.url()); }
        });
        await page.route('**/*', route => {
            const url = new URL(route.request().url());
            if (url.hostname === '127.0.0.1') { return route.continue(); }
            return route.fulfill({status:200,contentType:'text/plain',body:''});
        });
        const base = 'http://127.0.0.1:' + server.address().port;
        await page.goto(base, {waitUntil:'load'});
        const initial = await page.evaluate(() => ({
            marketing:window.wp_has_consent('marketing'),
            service:window.wp_has_service_consent('facebook-pixel'),
            functional:window.wp_has_consent('functional')
        }));
        assert.equal(initial.marketing,false,'no consent before choice');
        assert.equal(initial.service,false,'previously allowed service must not bypass category');
        assert.equal(initial.functional,true,'necessary functional category should remain allowed');

        await page.evaluate(() => {
            const host = document.getElementById('host');
            const youtube = document.createElement('iframe');
            youtube.src = 'https://www.youtube.com/embed/test'; host.appendChild(youtube);
            const marketing = document.createElement('script');
            marketing.src = 'https://connect.facebook.net/en_US/fbevents.js'; host.appendChild(marketing);
            const analytics = document.createElement('img');
            analytics.src = 'https://google-analytics.com/collect'; host.appendChild(analytics);
            const preload = document.createElement('link');
            preload.rel = 'preload'; preload.as = 'script';
            preload.href = 'https://connect.facebook.net/en_US/fbevents.js';
            document.head.appendChild(preload);
            const nested = document.createElement('div');
            nested.innerHTML = '<iframe src="https://player.vimeo.com/video/123"></iframe>';
            host.appendChild(nested);
            host.insertAdjacentHTML('beforeend','<iframe src="https://www.youtube.com/embed/insert"></iframe>');
        });
        await page.waitForTimeout(350);
        assert.deepEqual(requests,[], 'no third-party request before category consent');
        const blockedCount = await page.evaluate(() => document.querySelectorAll('[data-ptm-blocked="1"]').length);
        assert.ok(blockedCount >= 6, 'injected scripts, frames and preloads neutralised');

        await page.evaluate(() => window.DendrilaPrivacyConsentAPI.saveChoice({
            statistics:true,external:false,marketing:false
        }));
        await page.waitForTimeout(350);
        assert.ok(requests.some(url=>url.includes('google-analytics.com')), 'allowed analytics request');
        assert.equal(requests.some(url=>/youtube\.com|vimeo\.com|facebook\.net/.test(url)),false);
        const narrow = await page.evaluate(() => ({
            stats:window.wp_has_consent('statistics'),
            service:window.wp_has_service_consent('facebook-pixel')
        }));
        assert.equal(narrow.stats,true,'WP Consent API reads allowed statistics');
        assert.equal(narrow.service,false,'WP Consent API 2.1 service override still denied');

        await page.evaluate(() => window.DendrilaPrivacyConsentAPI.saveChoice({
            statistics:true,external:true,marketing:true
        }));
        await page.waitForTimeout(450);
        assert.ok(requests.some(url=>url.includes('youtube.com/embed/')),'YouTube allowed after consent');
        assert.ok(requests.some(url=>url.includes('player.vimeo.com')),'Vimeo allowed after consent');
        assert.ok(requests.some(url=>url.includes('connect.facebook.net')),'marketing allowed after consent');
        const granted = await page.evaluate(() => ({
            api: Object.fromEntries(window.consentApiCalls),
            marker:document.cookie.includes('dendrila_privacy_consent_v1=1.'),
            service:window.wp_has_service_consent('facebook-pixel')
        }));
        assert.equal(granted.api.marketing,'allow');
        assert.equal(granted.api.preferences,'deny');
        assert.equal(granted.marker,true,'first-party PHP marker persisted');
        assert.equal(granted.service,true,'service allowed once category explicitly accepted');

        await page.evaluate(() => window.DendrilaPrivacyConsentAPI.saveChoice({
            statistics:false,external:false,marketing:false
        }));
        assert.equal(await page.evaluate(() => window.wp_has_service_consent('facebook-pixel')),
            false,'old explicit service grant must not survive category revocation');
        assert.deepEqual(jsErrors, [], 'no JavaScript runtime errors');
        console.log('PASS: WP Consent API 2.1.0 + Chromium network, service overrides, marker, and revocation');
    } finally {
        if (browser) { await browser.close(); }
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
