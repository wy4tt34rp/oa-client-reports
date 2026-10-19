(function () {
    'use strict';
    const number = new Intl.NumberFormat(undefined, {maximumFractionDigits: 0});
    const decimal = new Intl.NumberFormat(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const dayDate = value => new Date(value + 'T12:00:00Z');
    const dateLabel = value => dayDate(value).toLocaleDateString(undefined, {month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC'});
    const duration = seconds => {
        const total = Math.round(seconds);
        if (total >= 3600) return Math.floor(total / 3600) + 'h ' + Math.floor((total % 3600) / 60) + 'm';
        return total >= 60 ? Math.floor(total / 60) + 'm ' + (total % 60) + 's' : total + 's';
    };
    const fields = ['views', 'users', 'viewsPerUser', 'engagementSeconds', 'events'];
    const labels = ['Views', 'Active users', 'Views per active user', 'Average engagement time per active user', 'Event count'];
    const format = (key, value) => key === 'engagementSeconds' ? duration(value) : key === 'viewsPerUser' ? decimal.format(value) : number.format(value);
    const element = (tag, text, className) => {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (className) node.className = className;
        return node;
    };
    function chart(target, daily) {
        target.replaceChildren();
        if (!daily.length) return;
        const ns = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(ns, 'svg');
        const make = (tag, attributes, text) => {
            const node = document.createElementNS(ns, tag);
            Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, String(value)));
            if (text !== undefined) node.textContent = text;
            svg.append(node);
            return node;
        };
        svg.setAttribute('viewBox', '0 0 1000 250');
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', 'Daily page views from ' + dateLabel(daily[0].date) + ' to ' + dateLabel(daily[daily.length - 1].date) + '. Exact values follow in View daily values.');
        const max = Math.max(1, ...daily.map(day => day.views));
        const x = i => daily.length === 1 ? 520 : 64 + (i / (daily.length - 1)) * 910;
        const y = value => 210 - (value / max) * 184;
        for (let i = 0; i < 5; i++) {
            const value = max * i / 4;
            make('line', {x1: 64, x2: 974, y1: y(value), y2: y(value), class: 'oa-gridline'});
            make('text', {x: 52, y: y(value) + 4, 'text-anchor': 'end', class: 'oa-chart-label'}, number.format(value));
        }
        make('polyline', {points: daily.map((day, i) => x(i) + ',' + y(day.views)).join(' '), class: 'oa-line', fill: 'none', 'vector-effect': 'non-scaling-stroke'});
        if (daily.length <= 62) {
            daily.forEach((day, i) => {
                const point = make('circle', {cx: x(i), cy: y(day.views), r: daily.length === 1 ? 5 : 3, class: 'oa-point'});
                const title = document.createElementNS(ns, 'title');
                title.textContent = dateLabel(day.date) + ': ' + number.format(day.views) + ' views';
                point.append(title);
            });
        }
        const indices = [...new Set([0, Math.round((daily.length - 1) / 4), Math.round((daily.length - 1) / 2), Math.round((daily.length - 1) * 3 / 4), daily.length - 1])];
        indices.forEach(i => make('text', {x: x(i), y: 242, 'text-anchor': i === 0 ? 'start' : i === daily.length - 1 ? 'end' : 'middle', class: 'oa-chart-label'}, dayDate(daily[i].date).toLocaleDateString(undefined, {month: 'short', day: 'numeric', timeZone: 'UTC'})));
        target.append(svg);
    }
    function mount(root) {
        const config = JSON.parse(root.dataset.oaConfig);
        const form = root.querySelector('form');
        const status = root.querySelector('.oa-status');
        const results = root.querySelector('.oa-results');
        const search = root.querySelector('input[type=search]');
        const body = root.querySelector('.oa-pages-table tbody');
        const csv = root.querySelector('[data-action=csv]');
        const print = root.querySelector('[data-action=print]');
        const apply = form.querySelector('[type=submit]');
        let report = null;
        let page = 0;
        let sort = 'views';
        let descending = true;
        const pageSize = 50;
        const selectedRows = () => {
            const term = search.value.trim().toLocaleLowerCase();
            return (report?.rows || []).filter(row => row.title.toLocaleLowerCase().includes(term)).sort((a, b) => {
                const diff = sort === 'title' ? a.title.localeCompare(b.title) : a[sort] - b[sort];
                return (descending ? -diff : diff) || a.title.localeCompare(b.title);
            });
        };
        function table(all = false) {
            const rows = selectedRows();
            page = Math.min(page, Math.max(0, Math.ceil(rows.length / pageSize) - 1));
            const shown = all ? rows : rows.slice(page * pageSize, (page + 1) * pageSize);
            const fragment = document.createDocumentFragment();
            if (!shown.length) {
                const row = element('tr');
                const cell = element('td', search.value ? 'No page titles match your search.' : 'No page activity was recorded for these dates.');
                cell.colSpan = 6;
                row.append(cell);
                fragment.append(row);
            }
            shown.forEach(row => {
                const tr = element('tr');
                const title = element('th', row.title);
                title.scope = 'row';
                tr.append(title);
                fields.forEach(key => tr.append(element('td', format(key, row[key]))));
                fragment.append(tr);
            });
            body.replaceChildren(fragment);
            root.querySelector('.oa-row-count').textContent = rows.length
                ? number.format(page * pageSize + 1) + '–' + number.format(Math.min((page + 1) * pageSize, rows.length)) + ' of ' + number.format(rows.length) + ' page titles' + (search.value ? ' matching “' + search.value + '”' : '')
                : '0 page titles';
            root.querySelector('[data-action=previous]').disabled = page === 0;
            root.querySelector('[data-action=next]').disabled = (page + 1) * pageSize >= rows.length;
        }
        function render() {
            const metrics = root.querySelector('.oa-metrics');
            metrics.replaceChildren();
            fields.forEach((key, i) => {
                const card = element('div', undefined, 'oa-metric');
                card.append(element('p', labels[i]), element('strong', format(key, report.totals[key])));
                metrics.append(card);
            });
            root.querySelector('.oa-period').textContent = dateLabel(report.start) + ' to ' + dateLabel(report.end);
            root.querySelector('.oa-freshness').textContent = 'Google Analytics 4 · ' + report.timezone + ' · Updated ' + new Date(report.fetchedAt * 1000).toLocaleString(undefined, {timeZone: report.timezone});
            chart(root.querySelector('.oa-chart'), report.daily);
            const dailyTable = element('table');
            const head = element('thead');
            const headRow = element('tr');
            ['Date', 'Views'].forEach(label => {
                const th = element('th', label); th.scope = 'col'; headRow.append(th);
            });
            head.append(headRow);
            const dailyBody = element('tbody');
            report.daily.forEach(day => {
                const row = element('tr');
                row.append(element('td', dateLabel(day.date)), element('td', number.format(day.views)));
                dailyBody.append(row);
            });
            dailyTable.append(head, dailyBody);
            root.querySelector('.oa-daily-table').replaceChildren(dailyTable);
            table();
            const notices = [...(report.warnings || [])];
            if (report.stale) notices.unshift(report.notice);
            if (!report.rows.length) notices.push('No recorded activity was returned for this date range.');
            status.textContent = notices.join(' ');
            status.classList.toggle('oa-notice', notices.length > 0);
            results.hidden = false;
            csv.disabled = false;
            print.disabled = false;
        }
        async function load() {
            if (!form.reportValidity()) return;
            const start = form.elements.start.value;
            const end = form.elements.end.value;
            if (start > end || (dayDate(end) - dayDate(start)) / 86400000 > 365) {
                status.textContent = 'Choose a start date on or before the end date, within a range of 366 days.';
                status.classList.add('oa-notice');
                return;
            }
            results.hidden = true;
            report = null;
            csv.disabled = print.disabled = apply.disabled = true;
            root.setAttribute('aria-busy', 'true');
            status.classList.remove('oa-notice');
            status.textContent = 'Loading your report…';
            try {
                const response = await fetch(config.endpoint, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce},
                    body: JSON.stringify({start, end}),
                });
                let data;
                try { data = await response.json(); } catch (_) { throw new Error('The report could not be loaded. Reload this page and try again.'); }
                if (!response.ok) throw new Error(data.message || 'The report is temporarily unavailable.');
                if (!data.totals || !Array.isArray(data.rows) || !Array.isArray(data.daily)) throw new Error('The report response is incomplete.');
                report = data;
                page = 0;
                render();
            } catch (error) {
                status.textContent = error.message || 'The report could not be loaded.';
                status.classList.add('oa-notice');
            } finally {
                apply.disabled = false;
                root.removeAttribute('aria-busy');
            }
        }
        function monthDates() {
            const value = form.elements.month.value;
            if (!/^\d{4}-\d{2}$/.test(value)) return;
            const [year, month] = value.split('-').map(Number);
            const last = new Date(Date.UTC(year, month, 0)).toISOString().slice(0, 10);
            form.elements.start.value = value + '-01';
            form.elements.end.value = last > config.yesterday ? config.yesterday : last;
        }
        form.elements.preset.addEventListener('change', () => {
            const choice = form.elements.preset.value;
            root.querySelector('.oa-month-label').hidden = choice !== 'month';
            if (choice === 'last-month') {
                form.elements.start.value = config.dates.start;
                form.elements.end.value = config.dates.end;
            } else if (choice === 'month') monthDates();
        });
        form.elements.month.min = '2005-01';
        form.elements.month.max = config.yesterday.slice(0, 7);
        form.elements.month.addEventListener('change', monthDates);
        ['start', 'end'].forEach(name => form.elements[name].addEventListener('input', () => {
            form.elements.preset.value = 'custom';
            root.querySelector('.oa-month-label').hidden = true;
        }));
        form.addEventListener('submit', event => { event.preventDefault(); load(); });
        search.addEventListener('input', () => { page = 0; table(); });
        root.querySelectorAll('[data-sort]').forEach(button => button.addEventListener('click', () => {
            descending = sort === button.dataset.sort ? !descending : button.dataset.sort !== 'title';
            sort = button.dataset.sort;
            root.querySelectorAll('[aria-sort]').forEach(th => th.setAttribute('aria-sort', 'none'));
            button.closest('th').setAttribute('aria-sort', descending ? 'descending' : 'ascending');
            table();
        }));
        root.querySelector('[data-action=previous]').addEventListener('click', () => { page--; table(); });
        root.querySelector('[data-action=next]').addEventListener('click', () => { page++; table(); });
        csv.addEventListener('click', () => {
            if (!report) return;
            const blob = new Blob([window.OAReportUtils.csv(report, config.client, search.value, selectedRows())], {type: 'text/csv;charset=utf-8'});
            const url = URL.createObjectURL(blob);
            const link = element('a');
            link.href = url;
            link.download = 'website-report-' + report.start + '-to-' + report.end + '.csv';
            link.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        });
        print.addEventListener('click', () => window.print());
        window.addEventListener('beforeprint', () => { if (report) { table(true); document.body.classList.add('oa-printing'); } });
        window.addEventListener('afterprint', () => { if (report) table(); document.body.classList.remove('oa-printing'); });
        // Preserve readable button text even when clients choose a light accent.
        const rgb = config.accent.match(/[a-f0-9]{2}/gi)?.map(hex => parseInt(hex, 16) / 255) || [0, 0, 0];
        const light = rgb.map(v => v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4));
        root.style.setProperty('--oa-button-text', 0.2126 * light[0] + 0.7152 * light[1] + 0.0722 * light[2] > 0.179 ? '#102127' : '#ffffff');
        if (config.configured) load();
        else {
            status.textContent = 'This report is waiting for its Google Analytics connection. Please contact your website administrator.';
            status.classList.add('oa-notice');
            apply.disabled = true;
        }
    }
    document.querySelectorAll('.oa-report[data-oa-config]').forEach(mount);
})();
