// Run: node tests/account-loading-progress.js
// Exercise the real account loader with controlled network responses and timers.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../administrator/manage-accounts.php'), 'utf8');
const script = source.match(/<script>\s*([\s\S]*?)<\/script>/)[1];
const elements = new Map();
const requests = [];
const intervals = new Map();
const timeouts = new Map();
let nextTimer = 1;
const document = {};
const window = {
  setInterval(callback) { const id = nextTimer++; intervals.set(id, callback); return id; },
  clearInterval(id) { intervals.delete(id); },
  setTimeout(callback, delay) { const id = nextTimer++; timeouts.set(id, { callback, delay }); return id; },
  clearTimeout(id) { timeouts.delete(id); },
  location: ''
};
const element = target => {
  if (!elements.has(target)) elements.set(target, { classes: new Set(), attrs: {}, styles: {}, value: '', html: '' });
  return elements.get(target);
};
const $ = target => {
  const item = element(target);
  return {
    on() { return this; },
    each() { return this; },
    data(key) { return item[key]; },
    is() { return Boolean(item.checked); },
    prop(key, value) { item[key] = value; return this; },
    attr(key, value) { item.attrs[key] = value; return this; },
    css(key, value) { item.styles[key] = value; return this; },
    text(value) { if (value === undefined) return item.text; item.text = value; return this; },
    val() { return item.value; },
    empty() { item.html = ''; return this; },
    append(value) { item.html += value; return this; },
    html(value) { item.html = value; return this; },
    addClass(value) { value.split(' ').forEach(name => item.classes.add(name)); return this; },
    removeClass(value) { value.split(' ').forEach(name => item.classes.delete(name)); return this; }
  };
};
$.trim = value => String(value).trim();
$.each = (items, callback) => items.forEach((item, index) => callback(index, item));
$.ajax = options => requests.push(options);
const context = vm.createContext({ $, document, window });
vm.runInContext(script.replace(/\/\/ INITIAL LOAD[\s\S]*?(?=\$\(window\)\.on)/, ''), context);
const state = vm.runInContext('accountState', context);
const progress = () => Number(element('#accountsLoadingProgress').attrs['aria-valuenow']);
const hidden = () => element('#accountsLoader').classes.has('d-none');
const ready = { status: 'ok', accounts: [{ id: 1, username: 'Sample Faculty', email: 'faculty@sksu.edu.ph', role: 'admin', professor_eligible: true }] };
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };

context.loadAccounts();
check(state.loading && !hidden() && progress() < 100, 'The loader appears before the response is available.');
check(element('#accountsTable, #accountsMobileList').attrs['aria-busy'] === 'true', 'Both table layouts announce that they are busy.');
for (let i = 0; i < 400; i++) [...intervals.values()].forEach(callback => callback());
check(progress() > 5 && progress() < 100, 'Waiting progress advances but cannot claim completion before data arrives.');
requests[0].success(ready);
check(element('#accountsTable tbody').html.includes('SAMPLE FACULTY'), 'The actual table renders the returned account.');
check(progress() === 100 && !state.loading && !hidden(), 'Completion reaches 100% once the table is ready.');
check(intervals.size === 0 && element('#accountsTable, #accountsMobileList').attrs['aria-busy'] === 'false', 'Completion clears the progress timer and busy state.');
check(!element('.role-all-toggle[data-role="professor"]').disabled, 'Professor assignment becomes available after loading.');
const hideTimer = [...timeouts.entries()].find(([, timer]) => timer.delay > 0);
check(Boolean(hideTimer), 'Completion remains briefly visible before the loader hides.');

context.loadAccounts();
check(!timeouts.has(hideTimer[0]) && !hidden() && progress() < 100, 'Reloading cancels the previous hide timer and restarts progress.');
requests[1].error({ status: 500 });
check(hidden() && !state.loading && intervals.size === 0, 'A network failure clears the loader and timer.');
check(element('#accountsTable tbody').html.includes('Unable to load'), 'A network failure displays the table error.');

context.loadAccounts();
requests[2].success({ status: 'ok', accounts: null });
check(hidden() && !state.loading && intervals.size === 0, 'An invalid response cannot leave a spinning loader.');

context.loadAccounts();
requests[3].success({ status: 'unauthorized' });
check(window.location === '../index.php' && hidden() && intervals.size === 0, 'An unauthorized response clears progress before redirecting.');
window.location = '';
context.loadAccounts();
requests[4].error({ status: 403 });
check(window.location === '../index.php' && !state.loading && intervals.size === 0, 'HTTP 403 also clears progress before redirecting.');

context.loadAccounts();
context.loadAccounts();
requests[5].success(ready);
requests[5].error({ status: 500 });
check(state.loading && !hidden() && progress() < 100 && intervals.size === 1, 'A stale response cannot complete or fail a newer load.');
requests[6].success({ status: 'ok', accounts: [] });
check(progress() === 100 && element('#accountsTable tbody').html.includes('No accounts found.'), 'An empty account list still completes successfully.');
const finalHide = [...timeouts.entries()].find(([, timer]) => timer.delay > 0);
finalHide[1].callback();
check(hidden() && !state.loading, 'The successful loader hides after its completion display.');
console.log(`Account loading progress: ${checks} checks passed.`);
