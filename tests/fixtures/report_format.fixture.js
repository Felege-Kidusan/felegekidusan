'use strict';

const assert = require('node:assert/strict');
const formatter = require(process.argv[2]);
const fixed = new Date('2026-08-24T10:11:12.000Z');

assert.equal(typeof formatter.timestamp, 'function');
assert.equal(typeof formatter.longDate, 'function');

global.WBWSCalendar = {
    formatDateTime: () => '2018-12-18 13:11:12',
    formatDate: () => '18 Nehase 2018'
};
assert.equal(formatter.timestamp(fixed), '2018-12-18 13:11:12');
assert.equal(formatter.longDate(fixed), '18 Nehase 2018');

global.WBWSCalendar = {
    formatDateTime: () => { throw new Error('calendar unavailable'); },
    formatDate: () => '   '
};
assert.ok(formatter.timestamp(fixed).length > 0);
assert.ok(formatter.longDate(fixed).length > 0);

delete global.WBWSCalendar;
const originalLocaleString = Date.prototype.toLocaleString;
const originalLocaleDateString = Date.prototype.toLocaleDateString;
Date.prototype.toLocaleString = () => { throw new Error('locale unavailable'); };
Date.prototype.toLocaleDateString = () => { throw new Error('locale unavailable'); };
try {
    assert.equal(formatter.timestamp(fixed), '2026-08-24 10:11:12 UTC');
    assert.equal(formatter.longDate(fixed), '2026-08-24');
} finally {
    Date.prototype.toLocaleString = originalLocaleString;
    Date.prototype.toLocaleDateString = originalLocaleDateString;
}

process.stdout.write(JSON.stringify({ ok: true }));
