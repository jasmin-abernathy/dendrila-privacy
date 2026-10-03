'use strict';

const fs = require('fs');
const vm = require('vm');
const path = require('path');

function run(localChoice, accountSync) {
  function Element() {}
  Element.prototype.setAttribute = function () {};
  const storage = JSON.stringify(localChoice || null);
  const context = {
    window: {},
    document: { currentScript: null },
    localStorage: { getItem: () => storage },
    Element,
    Date,
    Math,
    Number,
    String,
    JSON,
    Promise,
    TypeError
  };
  context.window.DendrilaPrivacyConsentEarlyConfig = {
    retentionDays: 180,
    fingerprint: 'fp',
    accountSync
  };
  context.window.Element = Element;
  context.window.navigator = {};
  context.navigator = context.window.navigator;
  vm.runInNewContext(
    fs.readFileSync(path.join(__dirname, '..', 'assets', 'consent-bootstrap.js'), 'utf8'),
    context,
    { filename: 'consent-bootstrap.js' }
  );
  return context.window.DendrilaPrivacyConsentEarly.getCurrent();
}

const local = { statistics: true, external: true, marketing: true, savedAt: 2000, fingerprint: 'fp' };
const account = { statistics: true, external: false, marketing: true, savedAt: 1000, fingerprint: 'fp' };

const conflict = run(local, { enabled: true, loggedIn: true, userEnabled: true, strategy: 'ask_user', accountChoice: account });
if (!conflict || conflict.statistics || conflict.external || conflict.marketing) {
  throw new Error('ask_user conflict must block every optional category before resolution');
}

const disabled = run(local, { enabled: true, loggedIn: true, userEnabled: false, strategy: 'ask_user', accountChoice: account });
if (!disabled || disabled.statistics !== true || disabled.external !== true || disabled.marketing !== true) {
  throw new Error('per-user sync opt-out must leave the local browser choice authoritative');
}

const accountWins = run(local, { enabled: true, loggedIn: true, userEnabled: true, strategy: 'account_wins', accountChoice: account });
if (!accountWins || accountWins.external !== false || accountWins.statistics !== true || accountWins.marketing !== true) {
  throw new Error('account_wins must preserve the stored account choice');
}

console.log('Consent conflict regression checks passed.');
