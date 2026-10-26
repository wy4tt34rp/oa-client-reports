(function () {
    'use strict';
    const number = new Intl.NumberFormat(undefined, {maximumFractionDigits: 0});
    const eventNumber = new Intl.NumberFormat(undefined, {maximumFractionDigits: 2});
    const decimal = new Intl.NumberFormat(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const percent = new Intl.NumberFormat(undefined, {style: 'percent', maximumFractionDigits: 2});
    const dayDate = value => new Date(value + 'T12:00:00Z');
    const dateLabel = value => dayDate(value).toLocaleDateString(undefined, {month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC'});
    const duration = seconds => {
        const total = Math.round(seconds);
        if (total >= 3600) return Math.floor(total / 3600) + 'h ' + Math.floor((total % 3600) / 60) + 'm';
        return total >= 60 ? Math.floor(total / 60) + 'm ' + (total % 60) + 's' : total + 's';
    };
    const utils = window.OAReportUtils;
    const format = (key, value, currency) => {
        if (key === 'engagementSeconds') return duration(value);
        if (key === 'viewsPerUser' || key === 'eventsPerSession') return decimal.format(value);
        if (key === 'engagementRate' || key === 'sessionKeyEventRate') return percent.format(value);
        if (key === 'revenue') return new Intl.NumberFormat(undefined, {style: 'currency', currency, currencyDisplay: 'code'}).format(value);
        if (key === 'keyEvents') return eventNumber.format(value);
        return number.format(value);
    };
    const element = (tag, text, className) => {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (className) node.className = className;
        return node;
    };
    function chart(target, daily, spec) {
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
        svg.setAttribute('aria-label', 'Daily ' + spec.daily + ' from ' + dateLabel(daily[0].date) + ' to ' + dateLabel(daily[daily.length - 1].date) + '. Exact values follow in View daily values.');
        const max = Math.max(4, Math.ceil(Math.max(0, ...daily.map(day => day[spec.daily])) / 4) * 4);
        const x = i => daily.length === 1 ? 520 : 64 + (i / (daily.length - 1)) * 910;
        const y = value => 210 - (value / max) * 184;
        for (let i = 0; i < 5; i++) {
            const value = max * i / 4;
            make('line', {x1: 64, x2: 974, y1: y(value), y2: y(value), class: 'oa-gridline'});
            make('text', {x: 52, y: y(value) + 4, 'text-anchor': 'end', class: 'oa-chart-label'}, number.format(value));
        }
        make('polyline', {points: daily.map((day, i) => x(i) + ',' + y(day[spec.daily])).join(' '), class: 'oa-line', fill: 'none', 'vector-effect': 'non-scaling-stroke'});
        if (daily.length <= 62) {
            daily.forEach((day, i) => {
                const point = make('circle', {cx: x(i), cy: y(day[spec.daily]), r: daily.length === 1 ? 5 : 3, class: 'oa-point'});
                const title = document.createElementNS(ns, 'title');
                title.textContent = dateLabel(day.date) + ': ' + number.format(day[spec.daily]) + ' ' + spec.daily;
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
        let spec = utils.definition('pages');
        let pending = null;
        let requestId = 0;
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
                const cell = element('td', search.value ? 'No ' + spec.rowLabel + ' match your search.' : 'No activity was recorded for these dates.');
                cell.colSpan = spec.fields.length + 1;
                row.append(cell);
                fragment.append(row);
            }
            shown.forEach(row => {
                const tr = element('tr');
                const title = element('th', row.title);
                title.scope = 'row';
                tr.append(title);
                spec.fields.forEach(key => tr.append(element('td', format(key, row[key], report.currency))));
                fragment.append(tr);
            });
            body.replaceChildren(fragment);
            root.querySelector('.oa-row-count').textContent = rows.length
                ? number.format(page * pageSize + 1) + '–' + number.format(Math.min((page + 1) * pageSize, rows.length)) + ' of ' + number.format(rows.length) + ' ' + spec.rowLabel + (search.value ? ' matching “' + search.value + '”' : '')
                : '0 ' + spec.rowLabel;
            root.querySelector('[data-action=previous]').disabled = page === 0;
            root.querySelector('[data-action=next]').disabled = (page + 1) * pageSize >= rows.length;
        }
        function render() {
            spec = utils.definition(report.type);
            const traffic = report.type === 'traffic';
            root.classList.toggle('oa-traffic', traffic);
            root.querySelector('.oa-report-title').textContent = spec.title;
            root.querySelector('.oa-table-title').textContent = report.dimension;
            root.querySelector('.oa-chart-title').textContent = spec.dailyLabel + ' over time';
            root.querySelector('.oa-chart-unit').textContent = 'Daily ' + spec.daily;
            root.querySelector('.oa-search-caption').textContent = traffic ? 'Find a source' : 'Find a page';
            search.placeholder = traffic ? 'Search traffic sources' : 'Search page titles';
            root.querySelector('.oa-page-definitions').hidden = traffic;
            root.querySelector('.oa-traffic-definitions').hidden = !traffic;
            const header = root.querySelector('.oa-pages-table thead tr');
            header.replaceChildren();
            ['title', ...spec.fields].forEach((key, i) => {
                const th = element('th'); th.scope = 'col';
                th.setAttribute('aria-sort', key === sort ? (descending ? 'descending' : 'ascending') : 'none');
                const button = element('button', i === 0 ? report.dimension : spec.labels[i - 1]);
                button.type = 'button'; button.dataset.sort = key; th.append(button); header.append(th);
            });
            const reportTable = root.querySelector('.oa-pages-table');
            reportTable.querySelector('tfoot')?.remove();
            if (traffic) {
                const foot = element('tfoot');
                const row = element('tr');
                const label = element('th', 'Full-period total'); label.scope = 'row'; row.append(label);
                spec.fields.forEach(key => row.append(element('td', format(key, report.totals[key], report.currency))));
                foot.append(row); reportTable.append(foot);
            }
            const metrics = root.querySelector('.oa-metrics');
            metrics.replaceChildren();
            spec.summary.forEach(key => {
                const card = element('div', undefined, 'oa-metric');
                card.append(element('p', spec.labels[spec.fields.indexOf(key)]), element('strong', format(key, report.totals[key], report.currency)));
                metrics.append(card);
            });
            const quarter = utils.quarterLabel(report.start, report.end);
            root.querySelector('.oa-period').textContent = (quarter ? quarter + ' · ' : '') + dateLabel(report.start) + ' to ' + dateLabel(report.end);
            root.querySelector('.oa-freshness').textContent = 'Google Analytics 4 · ' + report.timezone + ' · Updated ' + new Date(report.fetchedAt * 1000).toLocaleString(undefined, {timeZone: report.timezone});
            chart(root.querySelector('.oa-chart'), report.daily, spec);
            const dailyTable = element('table');
            const head = element('thead');
            const headRow = element('tr');
            ['Date', spec.dailyLabel].forEach(label => {
                const th = element('th', label); th.scope = 'col'; headRow.append(th);
            });
            head.append(headRow);
            const dailyBody = element('tbody');
            report.daily.forEach(day => {
                const row = element('tr');
                row.append(element('td', dateLabel(day.date)), element('td', number.format(day[spec.daily])));
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
            if (pending) pending.abort();
            pending = new AbortController();
            const currentRequest = ++requestId;
            const reportType = form.elements.reportType.value;
            const group = form.elements.group.value;
            report = null;
            csv.disabled = print.disabled = apply.disabled = true;
            root.setAttribute('aria-busy', 'true');
            status.classList.remove('oa-notice');
            status.textContent = 'Loading your report…';
            try {
                const response = await fetch(config.endpoint, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    signal: pending.signal,
                    headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce},
                    body: JSON.stringify({start, end, reportType, group}),
                });
                let data;
                try { data = await response.json(); } catch (_) { throw new Error('The report could not be loaded. Reload this page and try again.'); }
                if (currentRequest !== requestId) return;
                if (!response.ok) throw new Error(data.message || 'The report is temporarily unavailable.');
                if (!data.totals || !Array.isArray(data.rows) || !Array.isArray(data.daily)) throw new Error('The report response is incomplete.');
                report = data;
                sort = utils.definition(report.type).daily;
                descending = true;
                page = 0;
                render();
            } catch (error) {
                if (currentRequest !== requestId || error.name === 'AbortError') return;
                status.textContent = error.message || 'The report could not be loaded.';
                status.classList.add('oa-notice');
            } finally {
                if (currentRequest === requestId) {
                    apply.disabled = false;
                    root.removeAttribute('aria-busy');
                }
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
        const lastQuarter = config.quarter;
        const latestYear = Number(lastQuarter.start.slice(0, 4));
        for (let year = latestYear; year >= 2005; year--) {
            const option = element('option', String(year)); option.value = String(year); form.elements.year.append(option);
        }
        form.elements.quarter.value = String(Math.ceil(Number(lastQuarter.start.slice(5, 7)) / 3));
        function quarterDates() {
            const year = form.elements.year.value;
            [...form.elements.quarter.options].forEach(option => {
                option.disabled = utils.quarterDates(year, option.value).end > config.yesterday;
            });
            if (form.elements.quarter.selectedOptions[0]?.disabled) {
                form.elements.quarter.value = [...form.elements.quarter.options].filter(option => !option.disabled).pop().value;
            }
            const dates = utils.quarterDates(year, form.elements.quarter.value);
            form.elements.start.value = dates.start;
            form.elements.end.value = dates.end;
        }
        function dateControlVisibility() {
            const choice = form.elements.preset.value;
            root.querySelector('.oa-month-label').hidden = choice !== 'month';
            root.querySelector('.oa-quarter-label').hidden = choice !== 'quarter';
            root.querySelector('.oa-year-label').hidden = choice !== 'quarter';
            root.querySelector('.oa-quarter-note').hidden = !['quarter', 'last-quarter'].includes(choice);
        }
        form.elements.preset.addEventListener('change', () => {
            const choice = form.elements.preset.value;
            dateControlVisibility();
            if (choice === 'last-month') {
                form.elements.start.value = config.dates.start;
                form.elements.end.value = config.dates.end;
            } else if (choice === 'month') monthDates();
            else if (choice === 'last-quarter') {
                form.elements.start.value = lastQuarter.start;
                form.elements.end.value = lastQuarter.end;
            } else if (choice === 'quarter') quarterDates();
        });
        form.elements.quarter.addEventListener('change', quarterDates);
        form.elements.year.addEventListener('change', quarterDates);
        form.elements.reportType.addEventListener('change', () => {
            root.querySelector('.oa-group-label').hidden = form.elements.reportType.value !== 'traffic';
            search.value = '';
            if (config.configured) load();
        });
        form.elements.group.addEventListener('change', () => { search.value = ''; if (config.configured) load(); });
        form.elements.month.min = '2005-01';
        form.elements.month.max = config.yesterday.slice(0, 7);
        form.elements.month.addEventListener('change', monthDates);
        ['start', 'end'].forEach(name => form.elements[name].addEventListener('input', () => {
            form.elements.preset.value = 'custom';
            dateControlVisibility();
        }));
        form.addEventListener('submit', event => { event.preventDefault(); load(); });
        search.addEventListener('input', () => { page = 0; table(); });
        root.querySelector('.oa-pages-table thead').addEventListener('click', event => {
            const button = event.target.closest('[data-sort]');
            if (!button || !report) return;
            descending = sort === button.dataset.sort ? !descending : button.dataset.sort !== 'title';
            sort = button.dataset.sort;
            root.querySelectorAll('[aria-sort]').forEach(th => th.setAttribute('aria-sort', 'none'));
            button.closest('th').setAttribute('aria-sort', descending ? 'descending' : 'ascending');
            table();
        });
        root.querySelector('[data-action=previous]').addEventListener('click', () => { page--; table(); });
        root.querySelector('[data-action=next]').addEventListener('click', () => { page++; table(); });
        csv.addEventListener('click', () => {
            if (!report) return;
            const blob = new Blob([window.OAReportUtils.csv(report, config.client, search.value, selectedRows())], {type: 'text/csv;charset=utf-8'});
            const url = URL.createObjectURL(blob);
            const link = element('a');
            link.href = url;
            const quarter = utils.quarterLabel(report.start, report.end).toLowerCase().replace(' ', '-');
            link.download = (report.type === 'traffic' ? 'traffic-acquisition-' : 'website-report-') + (quarter ? quarter + '-' : '') + report.start + '-to-' + report.end + '.csv';
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
