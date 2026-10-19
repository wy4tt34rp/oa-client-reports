(function (root) {
    'use strict';
    const fields = ['views', 'users', 'viewsPerUser', 'engagementSeconds', 'events'];
    const labels = ['Views', 'Active users', 'Views per active user', 'Average engagement time per active user (seconds)', 'Event count'];
    function csvCell(value) {
        let text = String(value ?? '');
        if (/^[\s]*[=+\-@]/.test(text) || /^[\t\r\n]/.test(text)) text = "'" + text;
        return '"' + text.replaceAll('"', '""') + '"';
    }
    function csv(report, client, search, pageRows) {
        const rows = [
            ['Client', client], ['Source', 'Google Analytics 4'], ['Start', report.start], ['End', report.end],
            ['Timezone', report.timezone], ['Updated UTC', new Date(report.fetchedAt * 1000).toISOString()],
            ['Status', report.stale ? 'Stale: ' + report.notice : 'Current cached report'],
            ...(report.warnings || []).map(warning => ['Source note', warning]),
            ['Page title search', search || 'All page titles'], [],
            ['Full-period totals', ...labels], ['Total', ...fields.map(key => report.totals[key])], [],
            ['Page title and screen name', ...labels],
            ...pageRows.map(row => [row.title, ...fields.map(key => row[key])]), [],
            ['Daily views for full date range', 'Views'], ...report.daily.map(day => [day.date, day.views]),
        ];
        return '\uFEFF' + rows.map(row => row.map(csvCell).join(',')).join('\r\n');
    }
    const api = {csvCell, csv};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.OAReportUtils = api;
})(typeof window !== 'undefined' ? window : globalThis);
