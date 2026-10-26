<?php
namespace OcularAcumen\Reports;

final class Renderer
{
    public static function html(array $config): string
    {
        $id = wp_unique_id('oa-report-');
        ob_start();
        ?>
        <section class="oa-report" data-oa-config="<?php echo esc_attr(wp_json_encode($config)); ?>" style="--oa-accent:<?php echo esc_attr(sanitize_hex_color($config['accent']) ?: '#126d72'); ?>" aria-labelledby="<?php echo esc_attr($id); ?>title">
            <header class="oa-report-header">
                <div>
                    <?php if ($config['logo']) : ?><img class="oa-client-logo" src="<?php echo esc_url($config['logo']); ?>" alt="<?php echo esc_attr($config['client']); ?>"><?php endif; ?>
                    <p class="oa-client-name"><?php echo esc_html($config['client']); ?></p>
                    <h2 class="oa-report-title" id="<?php echo esc_attr($id); ?>title">Pages and screens</h2>
                    <p class="oa-period">Pages and screens</p>
                </div>
                <div class="oa-export-actions"><button type="button" data-action="csv" disabled>Download CSV</button><button type="button" data-action="print" disabled>Print / Save PDF</button></div>
            </header>
            <form class="oa-date-controls">
                <label for="<?php echo esc_attr($id); ?>report-type">Report<select id="<?php echo esc_attr($id); ?>report-type" name="reportType"><option value="pages">Pages and screens</option><option value="traffic">Traffic acquisition</option></select></label>
                <label class="oa-group-label" hidden for="<?php echo esc_attr($id); ?>group">Group by<select id="<?php echo esc_attr($id); ?>group" name="group"><option value="channel">Channel group</option><option value="sourceMedium">Source / medium</option></select></label>
                <label for="<?php echo esc_attr($id); ?>preset">Date range<select id="<?php echo esc_attr($id); ?>preset" name="preset"><option value="last-month">Last month</option><option value="month">Choose month</option><option value="last-quarter">Last quarter</option><option value="quarter">Choose quarter</option><option value="custom">Custom dates</option></select></label>
                <label class="oa-month-label" hidden for="<?php echo esc_attr($id); ?>month">Month<input type="month" id="<?php echo esc_attr($id); ?>month" name="month" value="<?php echo esc_attr(substr($config['dates']['start'], 0, 7)); ?>"></label>
                <label class="oa-quarter-label" hidden for="<?php echo esc_attr($id); ?>quarter">Quarter<select id="<?php echo esc_attr($id); ?>quarter" name="quarter"><option value="1">Q1 · Jan–Mar</option><option value="2">Q2 · Apr–Jun</option><option value="3">Q3 · Jul–Sep</option><option value="4">Q4 · Oct–Dec</option></select></label>
                <label class="oa-year-label" hidden for="<?php echo esc_attr($id); ?>year">Year<select id="<?php echo esc_attr($id); ?>year" name="year"></select></label>
                <label for="<?php echo esc_attr($id); ?>start">Start date<input type="date" id="<?php echo esc_attr($id); ?>start" name="start" value="<?php echo esc_attr($config['dates']['start']); ?>" max="<?php echo esc_attr($config['yesterday']); ?>" min="2005-01-01" required></label>
                <label for="<?php echo esc_attr($id); ?>end">End date<input type="date" id="<?php echo esc_attr($id); ?>end" name="end" value="<?php echo esc_attr($config['dates']['end']); ?>" max="<?php echo esc_attr($config['yesterday']); ?>" min="2005-01-01" required></label>
                <button class="oa-primary" type="submit">Apply report</button>
                <p class="oa-quarter-note" hidden>Calendar quarters. Only completed quarters are available.</p>
            </form>
            <p class="oa-status" role="status" aria-live="polite"></p>
            <div class="oa-results" hidden>
                <div class="oa-metrics"></div>
                <div class="oa-chart-section">
                    <div class="oa-section-heading"><h3 class="oa-chart-title">Views over time</h3><span class="oa-chart-unit">Daily page views</span></div>
                    <div class="oa-chart"></div>
                    <details class="oa-daily-details"><summary>View daily values</summary><div class="oa-daily-table"></div></details>
                </div>
                <div class="oa-pages-section">
                    <div class="oa-section-heading"><h3 class="oa-table-title">Pages and screens</h3><label class="oa-search-label" for="<?php echo esc_attr($id); ?>search"><span class="oa-search-caption">Find a page</span><input id="<?php echo esc_attr($id); ?>search" type="search" placeholder="Search page titles"></label></div>
                    <p class="oa-table-scope">Search filters the table and CSV rows. Summary metrics and the chart cover the full date range.</p>
                    <div class="oa-table-scroll"><table class="oa-pages-table"><thead><tr>
                        <th scope="col" aria-sort="none"><button type="button" data-sort="title">Page title and screen name</button></th>
                        <th scope="col" aria-sort="descending"><button type="button" data-sort="views">Views</button></th>
                        <th scope="col" aria-sort="none"><button type="button" data-sort="users">Active users</button></th>
                        <th scope="col" aria-sort="none"><button type="button" data-sort="viewsPerUser">Views per active user</button></th>
                        <th scope="col" aria-sort="none"><button type="button" data-sort="engagementSeconds">Average engagement time per active user</button></th>
                        <th scope="col" aria-sort="none"><button type="button" data-sort="events">Event count</button></th>
                    </tr></thead><tbody></tbody></table></div>
                    <div class="oa-pagination"><span class="oa-row-count" role="status"></span><div><button type="button" data-action="previous">Previous</button><button type="button" data-action="next">Next</button></div></div>
                </div>
                <details class="oa-definitions"><summary>About these metrics</summary><dl class="oa-page-definitions">
                    <dt>Views</dt><dd>Recorded page and screen views, including repeat views.</dd>
                    <dt>Active users</dt><dd>GA4 active users in the selected period. One person can appear on several page rows, so these rows do not add up to the report total.</dd>
                    <dt>Views per active user</dt><dd>Recorded views divided by active users for the corresponding page or full period.</dd>
                    <dt>Average engagement time per active user</dt><dd>Total engagement seconds divided by active users. Engagement measures time the site or app was in the foreground.</dd>
                    <dt>Event count</dt><dd>All recorded events, including page views and other interactions. This is not a count of conversions.</dd>
                </dl><dl class="oa-traffic-definitions" hidden>
                    <dt>Traffic acquisition</dt><dd>Session-based sources for new and returning visitors. Channels use Google's default channel grouping. Source / medium shows the publisher and acquisition method for each session.</dd>
                    <dt>Sessions and engaged sessions</dt><dd>Sessions are visits recorded by GA4. Engaged sessions meet the property's engagement timer, include a key event, or include at least two page or screen views.</dd>
                    <dt>Engagement rate</dt><dd>Engaged sessions divided by sessions, displayed as a percentage. Full-period rates come directly from GA4.</dd>
                    <dt>Average engagement time per session</dt><dd>Total engagement duration divided by sessions at the corresponding row or full-period level.</dd>
                    <dt>Event count and events per session</dt><dd>All recorded events and the average number of events per session.</dd>
                    <dt>Key events and session key event rate</dt><dd>All events marked as key events, and the percentage of sessions containing at least one key event. Event counts and session percentages measure different things.</dd>
                    <dt>Total revenue</dt><dd>GA4 revenue after refunds, shown in the property's reporting currency. Zero means no revenue was returned for these dates.</dd>
                </dl></details>
            </div>
            <footer class="oa-report-footer"><span class="oa-freshness">Google Analytics 4</span><span>Reporting by Ocular Acumen</span></footer>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}
