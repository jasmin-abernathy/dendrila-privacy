'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const bootstrap = fs.readFileSync(path.join(__dirname, '../assets/consent-bootstrap.js'), 'utf8');
const consent = fs.readFileSync(path.join(__dirname, '../assets/consent.js'), 'utf8');

function environment(testMode = '', wpApiInstalled = true) {
    const events = {};
    const storage = new Map();
    const apiCalls = [];
    class Element {
        constructor(tag = 'div') {
            this.nodeType = 1;
            this.tagName = tag.toUpperCase();
            this.store = new Map();
            this.children = [];
            this.parentNode = null;
            this.textContent = '';
            this.hidden = true;
        }
        setAttribute(name, value) { this.store.set(name, String(value)); }
        getAttribute(name) { return this.store.has(name) ? this.store.get(name) : null; }
        removeAttribute(name) { this.store.delete(name); }
        get attributes() { return Array.from(this.store, ([name, value]) => ({name, value})); }
        appendChild(node) { this.children.push(node); node.parentNode = this; return node; }
        insertBefore(node) { this.children.push(node); node.parentNode = this; return node; }
        replaceChild(newNode, oldNode) {
            const at = this.children.indexOf(oldNode);
            if (at >= 0) { this.children[at] = newNode; newNode.parentNode = this; }
            return oldNode;
        }
        querySelectorAll(selector) {
            const descendants = this.children.flatMap(child => [child, ...child.querySelectorAll('*')]);
            if (selector === '*') { return descendants; }
            if (selector === '[data-ptm-blocked="1"][data-ptm-category]') {
                return descendants.filter(el => el.getAttribute('data-ptm-blocked') === '1' && el.getAttribute('data-ptm-category'));
            }
            if (selector === 'script[src],iframe[src],img[src],link[href]') {
                return descendants.filter(el => ['script','iframe','img'].includes(el.tagName.toLowerCase()) && el.getAttribute('src') ||
                    el.tagName.toLowerCase() === 'link' && el.getAttribute('href'));
            }
            return [];
        }
        addEventListener() {}
    }
    class HTMLScriptElement extends Element { constructor() { super('script'); } }
    class HTMLIFrameElement extends Element { constructor() { super('iframe'); } }
    class HTMLImageElement extends Element { constructor() { super('img'); } }
    class HTMLLinkElement extends Element { constructor() { super('link'); } }
    for (const Klass of [HTMLScriptElement, HTMLIFrameElement, HTMLImageElement]) {
        Object.defineProperty(Klass.prototype, 'src', {
            configurable: true, get() { return this.getAttribute('src') || ''; },
            set(value) { this.setAttribute('src', value); }
        });
    }

    const body = new Element('body');
    const document = {
        body, currentScript: null, readyState: 'loading',
        addEventListener(name, listener) { events[name] = listener; },
        dispatchEvent(event) { if (events[event.type]) { events[event.type](event); } },
        createElement(tag) {
            if (tag === 'script') { return new HTMLScriptElement(); }
            if (tag === 'iframe') { return new HTMLIFrameElement(); }
            if (tag === 'img') { return new HTMLImageElement(); }
            if (tag === 'link') { return new HTMLLinkElement(); }
            return new Element(tag);
        },
        getElementById() { return null; },
        querySelectorAll(selector) { return body.querySelectorAll(selector); },
        querySelector() { return null; }
    };
    const localStorage = {
        getItem(key) { return storage.has(key) ? storage.get(key) : null; },
        setItem(key, value) { storage.set(key, value); }
    };
    const window = {
        Element, HTMLScriptElement, HTMLIFrameElement, HTMLImageElement, HTMLLinkElement,
        DendrilaPrivacyConsentEarlyConfig: { fingerprint:'test-fingerprint', wpConsentApi:wpApiInstalled, testMode },
        DendrilaPrivacyConsent: {
            storageKey:'pixel_trackers_manager_consent_v2',fingerprint:'test-fingerprint',
            wpConsentApi:wpApiInstalled,testMode,
            domains: {
                external:['youtube.com/embed','player.vimeo.com','google.com/maps/embed'],
                statistics:['google-analytics.com'],
                marketing:['connect.facebook.net']
            }
        },
        wp_set_consent: (category, value) => apiCalls.push([category, value]),
        addEventListener(name, listener) { events['window:' + name] = listener; }
    };
    const ctx = vm.createContext({
        window, document, Element, localStorage, sessionStorage:localStorage,
        CustomEvent:class { constructor(type, props = {}) { this.type=type; this.detail=props.detail; } },
        Date, Promise, TypeError
    });
    vm.runInContext(bootstrap, ctx, {filename:'consent-bootstrap.js'});
    return {window,document,body,Element,HTMLIFrameElement,HTMLScriptElement,apiCalls,events,ctx,storage};
}

test('opt-in is set early; recognised script, video iframe and tracking pixel never receive a live src', () => {
    const env = environment();
    assert.equal(env.window.wp_consent_type, 'optin');
    const iframe = new env.HTMLIFrameElement();
    iframe.setAttribute('src', 'https://www.youtube.com/embed/demo');
    assert.equal(iframe.getAttribute('src'), 'about:blank');
    assert.equal(iframe.getAttribute('data-ptm-category'), 'external');
    const script = new env.HTMLScriptElement();
    script.src = 'https://connect.facebook.net/en_US/fbevents.js';
    assert.equal(script.getAttribute('src'), null);
    assert.equal(script.getAttribute('type'), 'text/plain');
    const pixel = env.document.createElement('img');
    pixel.setAttribute('src', 'https://google-analytics.com/collect');
    assert.equal(pixel.getAttribute('src'), null);
    assert.equal(pixel.getAttribute('data-ptm-category'), 'statistics');
});

test('nested resources are neutralised before appendChild, and unrelated resources remain unchanged', () => {
    const env = environment();
    const container = new env.Element('div');
    const frame = new env.HTMLIFrameElement();
    // Bypass the patched setter to model a detached subtree parsed by a builder.
    frame.store.set('src', 'https://player.vimeo.com/video/1');
    const firstParty = new env.HTMLScriptElement();
    firstParty.store.set('src', '/assets/site.js');
    container.children.push(frame,firstParty);
    env.body.appendChild(container);
    assert.equal(frame.getAttribute('src'), 'about:blank');
    assert.equal(frame.getAttribute('data-ptm-src'), 'https://player.vimeo.com/video/1');
    assert.equal(firstParty.getAttribute('src'), '/assets/site.js');
});

test('preload links remain inert until their category is authorised', () => {
    const env = environment();
    const link = env.document.createElement('link');
    link.setAttribute('rel', 'preload');
    link.setAttribute('href', 'https://connect.facebook.net/pixel.js');
    assert.equal(link.getAttribute('href'), null);
    assert.equal(link.getAttribute('data-ptm-href'), 'https://connect.facebook.net/pixel.js');
});

test('WP Consent API receives denials on init and the exact categories on explicit choice', () => {
    const env = environment();
    vm.runInContext(consent, env.ctx, {filename:'consent.js'});
    env.events.DOMContentLoaded();
    const initial = Object.fromEntries(env.apiCalls);
    assert.equal(initial.functional, 'allow');
    assert.equal(initial.statistics, 'deny');
    assert.equal(initial['statistics-anonymous'], 'deny');
    assert.equal(initial.marketing, 'deny');
    assert.equal(initial.preferences, 'deny');
    env.apiCalls.length = 0;
    env.window.DendrilaPrivacyConsentAPI.saveChoice({statistics:true, external:true, marketing:false});
    const granted = Object.fromEntries(env.apiCalls);
    assert.equal(granted.statistics, 'allow');
    assert.equal(granted['statistics-anonymous'], 'allow');
    assert.equal(granted.preferences, 'deny', 'external content must not grant unrelated preferences');
    assert.equal(granted.marketing, 'deny');
});

test('preview mode never writes WP Consent API cookies', () => {
    const env = environment('accept');
    vm.runInContext(consent, env.ctx, {filename:'consent.js'});
    env.events.DOMContentLoaded();
    assert.equal(env.apiCalls.length, 0);
});

test('without WP Consent API native consent runs independently and writes no API cookies', () => {
    const env = environment('', false);
    vm.runInContext(consent, env.ctx, {filename:'consent.js'});
    env.events.DOMContentLoaded();
    env.window.DendrilaPrivacyConsentAPI.saveChoice({statistics:true, external:false, marketing:false});
    assert.equal(env.window.wp_consent_type, undefined);
    assert.equal(env.apiCalls.length, 0);
});
