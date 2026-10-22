/* Local ApexCharts assets; both datasets are already scoped to the professor by PHP. */
(() => {
  'use strict';
  const colors = ['#7367f0', '#00bad1', '#ff7976', '#28c76f', '#ffb547', '#cf6bea', '#4287f5', '#f065a9'];
  const byId = id => document.getElementById(id);
  const node = (tag, text, className) => {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    if (className) element.className = className;
    return element;
  };
  const escape = value => { const element = node('span', String(value)); return element.innerHTML; };
  const integer = value => Math.round(Number(value) || 0).toLocaleString();
  const maxAxis = values => {
    const maximum = Math.max(1, ...values);
    return maximum <= 5 ? maximum : Math.ceil(maximum / 5) * 5;
  };
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const base = {
    chart: { toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit', foreColor: '#9599ae',
      animations: { enabled: !reducedMotion, speed: 300, animateGradually: { enabled: false }, dynamicAnimation: { enabled: false } }, parentHeightOffset: 0 },
    grid: { borderColor: '#eeecf7', strokeDashArray: 4, padding: { left: 5, right: 12 } },
    dataLabels: { enabled: false }, legend: { show: false },
    states: { hover: { filter: { type: 'none' } }, active: { filter: { type: 'none' } } },
  };
  let researchChart = null, trend = null;
  const program = byId('dashboardResearchProgram');
  const researchEl = byId('dashboardResearchChart');
  function resetResearch() {
    if (researchChart) { researchChart.destroy(); researchChart = null; }
    if (researchEl) { researchEl.replaceChildren(); delete researchEl.dataset.ready; }
  }
  function researchTable(series) {
    const table = node('table'), caption = node('caption', 'Assigned research counts by academic year and program', 'visually-hidden');
    const header = node('tr'); const yearHeader = node('th', 'Academic year'); yearHeader.scope = 'col'; header.append(yearHeader);
    series.forEach(row => { const cell = node('th', row.name); cell.scope = 'col'; header.append(cell); });
    const head = node('thead'); head.append(header); const body = node('tbody');
    trend.years.forEach((year, index) => {
      const row = node('tr'), heading = node('th', year); heading.scope = 'row'; row.append(heading);
      series.forEach(item => row.append(node('td', integer(item.data[index])))); body.append(row);
    });
    table.append(caption, head, body); byId('dashboardResearchChartTable').replaceChildren(table);
  }
  async function drawResearch() {
    resetResearch();
    const selected = trend.series.map((item, index) => ({ ...item, color: colors[index % colors.length], index }))
      .filter(item => program.value === '' || item.index === Number(program.value));
    const message = byId('dashboardResearchChartMessage'); message.hidden = true;
    const legend = byId('dashboardResearchLegend'); legend.replaceChildren();
    selected.forEach(item => {
      const entry = node('span', undefined, 'dashboard-chart-key'); const swatch = node('i');
      swatch.style.backgroundColor = item.color; swatch.setAttribute('aria-hidden', 'true');
      entry.append(swatch, node('span', item.name)); legend.append(entry);
    });
    researchTable(selected);
    if (!trend.years.length || !selected.length) {
      researchEl.hidden = true; message.hidden = false;
      message.textContent = 'Academic year details have not been recorded for these researches. Your records are still available in My Researches.';
      return;
    }
    researchEl.hidden = false;
    if (typeof window.ApexCharts !== 'function') {
      researchEl.hidden = true; message.hidden = false; message.textContent = 'The graph could not be displayed. View the chart data below.';
      byId('dashboardResearchChartTable').closest('details').open = true; return;
    }
    program.disabled = true;
    try {
      const chart = new ApexCharts(researchEl, {
        ...base, chart: { ...base.chart, id: 'professor-research-trend', type: 'area', height: 310 },
        // Internal series names never contain remote HTML. Visible labels use text nodes below.
        series: selected.map(item => ({ name: `Program ${item.index + 1}`, data: item.data })),
        colors: selected.map(item => item.color),
        stroke: { curve: 'smooth', width: 3, lineCap: 'round' },
        fill: { type: 'gradient', gradient: { shadeIntensity: .4, opacityFrom: .22, opacityTo: .015, stops: [0, 95, 100] } },
        markers: { size: 4, strokeWidth: 2, strokeColors: '#fff', hover: { sizeOffset: 2 } },
        xaxis: { categories: trend.years, title: { text: 'Academic year', style: { fontSize: '10px', fontWeight: 500 } },
          axisBorder: { show: false }, axisTicks: { show: false }, labels: { style: { fontSize: '10px' }, rotate: -35, hideOverlappingLabels: true } },
        yaxis: { min: 0, max: maxAxis(selected.flatMap(item => item.data)), tickAmount: Math.min(5, maxAxis(selected.flatMap(item => item.data))), decimalsInFloat: 0,
          title: { text: 'Researches', style: { fontSize: '10px', fontWeight: 500 } }, labels: { formatter: integer, style: { fontSize: '10px' } } },
        tooltip: { shared: true, intersect: false, custom: ({ dataPointIndex }) => {
          const box = node('div', undefined, 'dashboard-chart-tooltip'); box.append(node('strong', trend.years[dataPointIndex] || 'Academic year'));
          selected.forEach(item => box.append(node('div', `${item.name}: ${integer(item.data[dataPointIndex])} researches`)));
          return box.outerHTML;
        } },
      });
      researchChart = chart; await chart.render();
      researchEl.dataset.ready = 'true';
      researchEl.setAttribute('aria-label', `Assigned researches by academic year for ${selected.map(item => item.name).join(', ')}. View chart data for exact counts.`);
    } catch (_) {
      if (researchChart) { researchChart.destroy(); researchChart = null; }
      researchEl.hidden = true; message.hidden = false; message.textContent = 'View the chart data below for your research counts.';
      byId('dashboardResearchChartTable').closest('details').open = true;
    } finally { program.disabled = trend.series.length < 2; }
  }
  function research(data) {
    if (!researchEl || !program) return;
    trend = data && Array.isArray(data.years) && Array.isArray(data.series) ? data : { years: [], series: [], without_year: 0 };
    program.replaceChildren(); const all = node('option', 'All programs'); all.value = ''; program.append(all);
    trend.series.forEach((item, index) => { const option = node('option', item.name); option.value = String(index); program.append(option); });
    const notes = ['Each research is counted once, even when you hold several academic roles.'];
    if (trend.without_year) notes.push(`${trend.without_year} ${trend.without_year === 1 ? 'record has' : 'records have'} no valid academic year and ${trend.without_year === 1 ? 'is' : 'are'} excluded from the graph.`);
    if (trend.years.length === 1) notes.push('Only one academic year is available so far.');
    byId('dashboardResearchChartNote').textContent = notes.join(' ');
    drawResearch();
  }
  program?.addEventListener('change', drawResearch);
  window.SynkProfessorCharts = Object.freeze({ research, resetResearch });

  async function workload() {
    const element = byId('dashboardWorkloadChart'); if (!element) return;
    let data;
    try { data = JSON.parse(byId('dashboardWorkloadDataSource').textContent); } catch (_) { return; }
    if (!data?.classes?.length) return;
    const message = byId('dashboardWorkloadChartMessage');
    if (typeof window.ApexCharts !== 'function') {
      element.hidden = true; message.hidden = false; message.textContent = 'View the enrollment breakdown below.'; byId('dashboardWorkloadData').open = true; return;
    }
    try {
      const chart = new ApexCharts(element, {
        ...base, chart: { ...base.chart, id: 'professor-workload-enrollments', type: 'bar', height: Math.max(235, data.classes.length * 39 + 80) },
        series: [{ name: 'Student enrollments', data: data.classes.map(item => item.enrollments) }], colors,
        plotOptions: { bar: { horizontal: true, distributed: true, barHeight: '45%', borderRadius: 4 } },
        fill: { opacity: 1 },
        dataLabels: { enabled: true, textAnchor: 'start', formatter: integer, offsetX: 6,
          style: { fontSize: '10px', fontWeight: 600, colors: ['#fff'] } },
        xaxis: { categories: data.classes.map(item => escape(item.label)), min: 0, tickAmount: 5,
          title: { text: 'Student enrollments', style: { fontSize: '10px', fontWeight: 500 } },
          axisBorder: { show: false }, axisTicks: { show: false }, labels: { formatter: integer, style: { fontSize: '10px' } } },
        yaxis: { labels: { maxWidth: 125, style: { fontSize: '10px' } } },
        grid: { ...base.grid, padding: { left: 6, right: 20 } },
        tooltip: { custom: ({ dataPointIndex }) => {
          const item = data.classes[dataPointIndex], box = node('div', undefined, 'dashboard-chart-tooltip'); if (!item) return '';
          box.append(node('strong', item.label), node('div', item.subject), node('div', `${integer(item.enrollments)} student enrollments`)); return box.outerHTML;
        } },
      });
      await chart.render(); element.dataset.ready = 'true';
    } catch (_) {
      element.hidden = true; message.hidden = false; message.textContent = 'View the enrollment breakdown below.'; byId('dashboardWorkloadData').open = true;
    }
  }
  workload();
})();
