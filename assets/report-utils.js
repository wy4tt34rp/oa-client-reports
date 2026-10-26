(function (root) {
    'use strict';
    const fields = ['views', 'users', 'viewsPerUser', 'engagementSeconds', 'events'];
    const labels = ['Views', 'Active users', 'Views per active user', 'Average engagement time per active user (seconds)', 'Event count'];
    function definition(type) {
        if (type === 'traffic') return {
            title: 'Traffic acquisition', daily: 'sessions', dailyLabel: 'Sessions', rowLabel: 'traffic sources',
            fields: ['sessions', 'engagedSessions', 'engagementRate', 'engagementSeconds', 'eventsPerSession', 'events', 'keyEvents', 'sessionKeyEventRate', 'revenue'],
            labels: ['Sessions', 'Engaged sessions', 'Engagement rate', 'Average engagement time per session', 'Events per session', 'Event count', 'Key events', 'Session key event rate', 'Total revenue'],
            summary: ['sessions', 'engagedSessions', 'engagementRate', 'engagementSeconds', 'keyEvents'],
        };
        return {title: 'Pages and screens', daily: 'views', dailyLabel: 'Views', rowLabel: 'page titles', fields, labels: labels.map(label => label.replace(' (seconds)', '')), summary: fields};
    }
    function quarterDates(year, quarter) {
        year = Number(year); quarter = Number(quarter);
        if (!Number.isInteger(year) || year < 2005 || year > 9999 || !Number.isInteger(quarter) || quarter < 1 || quarter > 4) throw new Error('Choose a valid quarter and year.');
        return {start: year + '-' + String(quarter * 3 - 2).padStart(2, '0') + '-01', end: new Date(Date.UTC(year, quarter * 3, 0)).toISOString().slice(0, 10)};
    }
    function quarterLabel(start, end) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(start) || Number(start.slice(0, 4)) < 2005) return '';
        const year = Number(start.slice(0, 4));
        const quarter = Math.ceil(Number(start.slice(5, 7)) / 3);
        if (quarter < 1 || quarter > 4) return '';
        const range = quarterDates(year, quarter);
        return range.start === start && range.end === end ? 'Q' + quarter + ' ' + year : '';
    }
    function csvCell(value) {
        let text = String(value ?? '');
        if (/^[\s]*[=+\-@]/.test(text) || /^[\t\r\n]/.test(text)) text = "'" + text;
        return '"' + text.replaceAll('"', '""') + '"';
    }
    function csv(report, client, search, pageRows) {
        const spec = definition(report.type);
        const exportLabels = spec.labels.map((label, i) => {
            const key = spec.fields[i];
            if (key === 'engagementSeconds') return label + ' (seconds)';
            if (key === 'engagementRate' || key === 'sessionKeyEventRate') return label + ' (fraction)';
            return key === 'revenue' ? label + ' (' + report.currency + ')' : label;
        });
        const rows = [
            ['Client', client], ['Source', 'Google Analytics 4'], ['Start', report.start], ['End', report.end],
            ['Report', spec.title], ['Period', quarterLabel(report.start, report.end) || 'Selected dates'],
            ['Dimension', report.dimension || 'Page title and screen name'],
            ['Timezone', report.timezone], ['Updated UTC', new Date(report.fetchedAt * 1000).toISOString()],
            ['Status', report.stale ? 'Stale: ' + report.notice : 'Current cached report'],
            ...(report.warnings || []).map(warning => ['Source note', warning]),
            [report.type === 'traffic' ? 'Traffic source search' : 'Page title search', search || 'All ' + spec.rowLabel], [],
            ['Full-period totals', ...exportLabels], ['Total', ...spec.fields.map(key => report.totals[key])], [],
            [report.dimension || 'Page title and screen name', ...exportLabels],
            ...pageRows.map(row => [row.title, ...spec.fields.map(key => row[key])]), [],
            ['Daily ' + spec.daily + ' for full date range', spec.dailyLabel], ...report.daily.map(day => [day.date, day[spec.daily]]),
        ];
        return '\uFEFF' + rows.map(row => row.map(csvCell).join(',')).join('\r\n');
    }
    const api = {csvCell, csv, definition, quarterDates, quarterLabel};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.OAReportUtils = api;
})(typeof window !== 'undefined' ? window : globalThis);
