const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const window = {ArcWindInitConfig: {version: '1.7.0'}};
vm.runInNewContext(fs.readFileSync('assets/js/arcwind-init-tracker.js', 'utf8'), {window});
assert.equal(window.ArcWindInit.track('event'), false);
assert.equal(window.ArcWindInit.identify('user'), false);
window.posthog = {capture() { throw new Error('vendor failed'); }, identify() { throw new Error('vendor failed'); }};
assert.equal(window.ArcWindInit.track('event'), false);
assert.equal(window.ArcWindInit.identify('user'), false);
window.posthog = {capture() {}, identify() {}};
assert.equal(window.ArcWindInit.track('event'), true);
assert.equal(window.ArcWindInit.identify('user'), true);
console.log('JavaScript tracker regression tests passed.');
