const facultyCharts = document.querySelector('.hris-faculty-charts');

if (facultyCharts) {
    const tabList = facultyCharts.querySelector('[data-chart-tabs]');
    const tabs = [...tabList.querySelectorAll('[data-chart-tab]')];
    const panels = new Map([...facultyCharts.querySelectorAll('[data-chart-panel]')].map((panel) => [panel.dataset.chartPanel, panel]));
    const title = facultyCharts.querySelector('#faculty-chart-title');
    const rankScroll = facultyCharts.querySelector('[data-rank-chart-scroll]');
    const rankHint = facultyCharts.querySelector('#rank-chart-scroll');
    const nonce = document.currentScript.nonce;
    let activeTab;
    let activeChart;

    function updateScrollHint() {
        if (!rankScroll || rankScroll.hidden || activeTab?.dataset.chartTab !== 'rank') return;
        const canScroll = rankScroll.scrollWidth > rankScroll.clientWidth + 1;
        rankHint.hidden = !canScroll;
        if (canScroll) {
            rankScroll.tabIndex = 0;
            rankScroll.setAttribute('aria-describedby', rankHint.id);
        } else {
            rankScroll.removeAttribute('tabindex');
            rankScroll.removeAttribute('aria-describedby');
        }
    }

    function drawChart(panel, isRank) {
        const target = panel.querySelector('[data-rank-counts], [data-employment-counts]');
        if (!target || typeof ApexCharts === 'undefined') return;

        const values = JSON.parse(isRank ? target.dataset.rankCounts : target.dataset.employmentCounts);
        const labels = Object.keys(values);
        const styles = getComputedStyle(facultyCharts);
        let series = [{ name: 'Faculty count', data: Object.values(values) }];
        let colors = [styles.getPropertyValue('--hris-rank-faculty').trim()];
        if (isRank) {
            const legend = Object.entries(JSON.parse(target.dataset.rankSeries));
            series = legend.map(([key, name]) => ({ name, data: labels.map((rank) => values[rank][key]) }));
            colors = legend.map(([key]) => styles.getPropertyValue('--hris-rank-' + key).trim());
        }

        const peak = Math.max(...series[0].data);
        const step = Math.max(1, Math.ceil(peak / 5));
        const ticks = Math.max(1, Math.ceil(peak / step));
        const countAxis = { min: 0, max: ticks * step, tickAmount: ticks, decimalsInFloat: 0, title: { text: 'Faculty count' } };
        const categoryAxis = {
            categories: isRank ? labels.map((rank) => rank.split(' ')) : labels,
            labels: { rotate: 0, hideOverlappingLabels: false, trim: false, style: { fontSize: '11px' } },
            axisBorder: { color: '#d8d2cd' },
            axisTicks: { color: '#d8d2cd' },
            tooltip: { enabled: false },
        };
        const chartWrapper = isRank ? rankScroll : target;
        chartWrapper.hidden = false;

        const chart = new ApexCharts(target, {
            chart: {
                type: isRank ? 'line' : 'bar',
                height: isRank ? 304 : 338,
                parentHeightOffset: 0,
                nonce,
                fontFamily: 'inherit',
                foreColor: '#625b55',
                toolbar: { show: false },
                zoom: { enabled: false },
                animations: {
                    enabled: !matchMedia('(prefers-reduced-motion: reduce)').matches,
                    speed: 500,
                    animateGradually: { enabled: false },
                },
            },
            series,
            legend: { show: false },
            colors,
            stroke: { curve: 'smooth', width: isRank ? 3 : 0 },
            markers: { size: isRank ? 4 : 0, strokeWidth: 2, strokeColors: '#fff', hover: { sizeOffset: 2 } },
            plotOptions: { bar: { horizontal: true, barHeight: '53%', borderRadius: 5, dataLabels: { position: 'top' } } },
            dataLabels: {
                enabled: !isRank,
                offsetX: 10,
                formatter: (count) => count > 0 ? String(count) : '',
                style: { colors: ['#205525'] },
                background: { enabled: false },
            },
            grid: { borderColor: '#e3ded9', strokeDashArray: 3, padding: { left: 6, right: isRank ? 16 : 26 } },
            xaxis: isRank ? categoryAxis : { ...categoryAxis, ...countAxis },
            yaxis: isRank ? countAxis : { labels: { maxWidth: 140, style: { fontSize: '11px' } } },
            tooltip: {
                shared: isRank,
                intersect: !isRank,
                x: { formatter: (value, { dataPointIndex }) => labels[dataPointIndex] || value },
            },
        });
        activeChart = chart;
        chart.render().then(() => {
            if (chart !== activeChart || !isRank) return;
            panel.querySelector('.hris-rank-legend').hidden = false;
            updateScrollHint();
        }).catch(() => {
            if (chart !== activeChart) return;
            chartWrapper.hidden = true;
            if (isRank) rankHint.hidden = true;
            panel.querySelector('details').open = true;
        });
    }

    function selectTab(tab, updateUrl) {
        if (tab === activeTab) return;
        if (activeChart) {
            const previousChart = activeChart;
            activeChart = null;
            previousChart.destroy();
        }
        activeTab = tab;
        for (const item of tabs) {
            const selected = item === tab;
            item.setAttribute('aria-selected', String(selected));
            item.tabIndex = selected ? 0 : -1;
            panels.get(item.dataset.chartTab).hidden = !selected;
        }
        title.textContent = tab.dataset.chartTitle;
        if (updateUrl) history.replaceState(history.state, '', tab.href);
        drawChart(panels.get(tab.dataset.chartTab), tab.dataset.chartTab === 'rank');
    }

    const initialTab = tabs.find((tab) => tab.hasAttribute('aria-current')) || tabs[0];
    tabList.setAttribute('role', 'tablist');
    tabs.forEach((tab) => {
        const panel = panels.get(tab.dataset.chartTab);
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-controls', panel.id);
        tab.removeAttribute('aria-current');
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', tab.id);
        tab.addEventListener('click', (event) => {
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            selectTab(tab, true);
        });
        tab.addEventListener('keydown', (event) => {
            const index = tabs.indexOf(tab);
            const nextIndex = {
                ArrowLeft: (index + tabs.length - 1) % tabs.length,
                ArrowRight: (index + 1) % tabs.length,
                Home: 0,
                End: tabs.length - 1,
            }[event.key];
            if (nextIndex === undefined) return;
            event.preventDefault();
            selectTab(tabs[nextIndex], true);
            tabs[nextIndex].focus();
        });
    });
    selectTab(initialTab, false);
    window.addEventListener('resize', updateScrollHint);
}
