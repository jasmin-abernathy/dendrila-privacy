'use strict';

/**
 * Chromium smoke test: track attempted external requests and fulfil them locally.
 * npx/npm install of playwright-core is restricted to the browser CI job.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {chromium} = require('playwright-core');
const root = path.join(__dirname, '..');
const captureHost = /(?:youtube\.com|player\.vimeo\.com|google-analytics\.com|connect\.facebook\.net)$/i;
const html = [
    '<!doctype html><html><head><meta charset="utf-8"><script>',
    'window.DendrilaPrivacyConsentEarlyConfig={fingerprint:"browser-test",wpConsentApi:true};',
    'window.DendrilaPrivacyConsent={storageKey:"pixel_trackers_manager_consent_v2",fingerprint:"browser-test",',
    'wpConsentApi:true,style:"default",domains:{external:["youtube.com/embed","player.vimeo.com",',
    '"google.com/maps/embed"],statistics:["google-analytics.com"],marketing:["connect.facebook.net"]}};',
    'window.consentApiCalls=[];',
    'window.wp_set_consent=function(category,value){window.consentApiCalls.push([category,value]);};',
    '</script><script src="/assets/consent-bootstrap.js"></script>',
    '<script src="/assets/consent.js"></script></head><body><main id="host"></main></body></html>'
].join('\n');
const server = http.createServer((req, res) => {
    let data, mime;
    if (req.url === '/') { data = html; mime = 'text/html'; }
    else if (req.url === '/assets/consent-bootstrap.js' || req.url === '/assets/consent.js') {
        data = fs.readFileSync(path.join(root, req.url));
        mime = 'application/javascript';
    } else { res.writeHead(404); res.end(); return; }
    res.writeHead(200, {'Content-Type':mime}); res.end(data);
});
(async () => {
    let browser;
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        const base = 'http://127.0.0.1:' + server.address().port;
        browser = await chromium.launch({
            executablePath:process.env.CHROME_PATH || '/usr/bin/google-chrome',
            headless:true, args:['--no-sandbox','--disable-dev-shm-usage']
        });
        const page = await browser.newPage();
        const requests = [];
        page.on('request', request => {
            if (captureHost.test(new URL(request.url()).hostname)) { requests.push(request.url()); }
        });
        await page.route('**/*', route => {
            const url = new URL(route.request().url());
            if (url.hostname === '127.0.0.1') { return route.continue(); }
            return route.fulfill({status:200,contentType:'text/plain',body:''});
        });
        await page.goto(base, {waitUntil:'load'});
        await page.evaluate(() => {
            const host = document.getElementById('host');
            const video = document.createElement('iframe');
            video.src = 'https://www.youtube.com/embed/privacy-test';
            host.appendChild(video);
            const script = document.createElement('script');
            script.setAttribute('src','https://connect.facebook.net/en_US/fbevents.js');
            host.appendChild(script);
            const pixel = document.createElement('img');
            pixel.src = 'https://google-analytics.com/collect?v=1';
            host.appendChild(pixel);
            const preload = document.createElement('link');
            preload.rel = 'preload';
            preload.as = 'script';
            preload.href = 'https://connect.facebook.net/en_US/fbevents.js';
            document.head.appendChild(preload);
            const nested = document.createElement('div');
            nested.innerHTML = '<iframe src="https://player.vimeo.com/video/123"></iframe>';
            host.appendChild(nested);
            host.insertAdjacentHTML('beforeend','<iframe src="https://www.youtube.com/embed/adjacent"></iframe>');
        });
        await page.waitForTimeout(350);
        assert.deepEqual(requests, [], 'no recognised third-party request before consent');
        const inert = await page.evaluate(() => ({
            blocked:document.querySelectorAll('[data-ptm-blocked="1"]').length,
            liveEmbed:Array.from(document.querySelectorAll('iframe')).some(node => /youtube|vimeo/.test(node.src))
        }));
        assert.ok(inert.blocked >= 6,'nested and dynamically injected resources were blocked');
        assert.equal(inert.liveEmbed,false,'the iframe URLs remain inert');
        await page.evaluate(() => window.DendrilaPrivacyConsentAPI.saveChoice({
            statistics:true,external:false,marketing:false
        }));
        await page.waitForTimeout(350);
        assert.ok(requests.some(url => url.includes('google-analytics.com')),'statistics request after opt-in');
        assert.equal(requests.some(url => /youtube\.com|vimeo\.com|facebook\.net/.test(url)),false);
        await page.evaluate(() => window.DendrilaPrivacyConsentAPI.saveChoice({
            statistics:true,external:true,marketing:true
        }));
        await page.waitForTimeout(450);
        assert.ok(requests.some(url => url.includes('youtube.com/embed/')),'YouTube allowed');
        assert.ok(requests.some(url => url.includes('player.vimeo.com')),'Vimeo allowed');
        assert.ok(requests.some(url => url.includes('connect.facebook.net')),'marketing allowed');
        const categories = await page.evaluate(() => Object.fromEntries(window.consentApiCalls));
        assert.equal(categories.statistics,'allow');
        assert.equal(categories.marketing,'allow');
        assert.equal(categories.preferences,'deny','external consent must not enable preferences');
        console.log('Chromium network checks passed (' + requests.length + ' authorised requests captured)');
    } finally {
        if (browser) { await browser.close(); }
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
