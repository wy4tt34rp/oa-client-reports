const assert = require('node:assert/strict');
const {csvCell, csv, quarterDates, quarterLabel} = require('../assets/report-utils.js');
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
const trafficTotals = {sessions: 200, engagedSessions: 130, engagementRate: .65, engagementSeconds: 20, eventsPerSession: 3.5, events: 700, keyEvents: 12, sessionKeyEventRate: .045, revenue: 12.5};
const traffic = {...report, type: 'traffic', dimension: 'Session source / medium', currency: 'USD', start: '2026-07-01', end: '2026-09-30', totals: trafficTotals, daily: [{date: '2026-07-01', sessions: 20}]};
const trafficCsv = csv(traffic, 'Example Client', 'google', [{title: 'google / organic', ...trafficTotals}]);
check(trafficCsv.includes('"Report","Traffic acquisition"') && trafficCsv.includes('"Period","Q3 2026"'), 'Quarter and report type travel with export');
check(trafficCsv.includes('"Dimension","Session source / medium"') && trafficCsv.includes('"Traffic source search","google"'), 'Traffic grouping and search retained');
check(trafficCsv.includes('"Engagement rate (fraction)"') && trafficCsv.includes('"0.65"'), 'Rate fractions are not mislabeled as percentages');
check(trafficCsv.includes('Average engagement time per session (seconds)') && !trafficCsv.includes('per active user'), 'Traffic duration has the correct denominator');
check(trafficCsv.includes('Total revenue (USD)') && trafficCsv.includes('Daily sessions for full date range'), 'Currency and daily sessions retained');
check(!trafficCsv.includes('Daily views') && !trafficCsv.includes('Page title and screen name'), 'Traffic export does not retain page-report labels');
assert.deepEqual(quarterDates(2024, 1), {start: '2024-01-01', end: '2024-03-31'}); count++;
assert.deepEqual(quarterDates(2025, 4), {start: '2025-10-01', end: '2025-12-31'}); count++;
assert.deepEqual(quarterDates(2026, 2), {start: '2026-04-01', end: '2026-06-30'}); count++;
for (const args of [[2026, 0], [2026, 5], [2026, 1.5], [2004, 1], ['bad', 3]]) { assert.throws(() => quarterDates(...args)); count++; }
check(quarterLabel('2026-07-01', '2026-09-29') === '' && quarterLabel('2026-07-02', '2026-09-30') === '', 'Partial quarters are not labeled complete quarters');
console.log(count + ' CSV and quarter checks passed.');
