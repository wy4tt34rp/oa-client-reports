const assert = require('node:assert/strict');
const {csvCell, csv} = require('../assets/report-utils.js');
let count = 0;
function check(condition, label) { assert.ok(condition, label); count++; }
for (const value of ['=1+1', ' +SUM(A1)', '-1+2', '@SUM(A1)', '\t=1', '\rtest', '\ntest']) {
    check(csvCell(value).startsWith('"\''), 'Neutralizes formula or control prefix');
}
check(csvCell('Page "one", title') === '"Page ""one"", title"', 'Escapes quotes and commas');
check(csvCell('Servicios en español') === '"Servicios en español"', 'Preserves Unicode');
check(csvCell(1234.5) === '"1234.5"', 'Exports raw numeric precision');
const totals = {views: 100, users: 17, viewsPerUser: 100 / 17, engagementSeconds: 37, events: 300};
const rows = [{title: '=HYPERLINK("https://example.invalid","test")', ...totals}];
const report = {start: '2026-09-12', end: '2026-09-30', timezone: 'America/New_York', fetchedAt: 1720000000, totals, daily: [{date: '2026-09-12', views: 100}], warnings: ['Threshold note'], stale: true, notice: 'Saved report'};
const output = csv(report, 'Example Client', 'HYPERLINK', rows);
check(output.startsWith('\uFEFF'), 'UTF-8 BOM for Excel');
check(output.includes('"Start","2026-09-12"') && output.includes('"End","2026-09-30"'), 'Exports selected dates');
check(output.includes('"Timezone","America/New_York"'), 'Timezone retained');
check(output.includes('"Status","Stale: Saved report"'), 'Stale status travels with export');
check(output.includes('"Source note","Threshold note"'), 'Source limitation retained');
check(output.includes('"Page title search","HYPERLINK"'), 'Search scope retained');
check(output.includes('"Full-period totals"') && output.includes('"Daily views for full date range"'), 'Distinguishes full-period sections from filtered rows');
check(output.includes('engagement time per active user (seconds)'), 'Raw duration unit explicit');
check(output.includes('"\'=HYPERLINK(""https://example.invalid"",""test"")"'), 'Exported page title cannot execute a spreadsheet formula');
check(output.includes('"100","17","5.882352941176471","37","300"'), 'Distinct users and rates preserved without reaggregation');
console.log(count + ' CSV export checks passed.');
