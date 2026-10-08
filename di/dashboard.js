(function () {
  'use strict';

  const campus = document.getElementById('campusFilter');
  const college = document.getElementById('collegeFilter');
  if (campus && college) {
    campus.addEventListener('change', function () {
      Array.from(college.options).forEach(function (option) {
        const visible = option.value === '0' || campus.value === '0' || option.dataset.campus === campus.value;
        option.hidden = !visible;
        option.disabled = !visible;
        if (!visible && option.selected) { college.value = '0'; }
      });
    });
  }

  const search = document.getElementById('profileSearch');
  if (search) {
    const rows = Array.from(document.querySelectorAll('.di-profile-row'));
    const previous = document.getElementById('profilePrevious');
    const next = document.getElementById('profileNext');
    let currentPage = 0;
    const pageSize = 10;
    function renderRegister() {
      const query = search.value.trim().toLocaleLowerCase();
      const matches = rows.filter(function (row) { return (row.dataset.search || '').toLocaleLowerCase().includes(query); });
      const pages = Math.max(1, Math.ceil(matches.length / pageSize));
      currentPage = Math.min(currentPage, pages - 1);
      rows.forEach(function (row) { row.hidden = true; });
      matches.slice(currentPage * pageSize, (currentPage + 1) * pageSize).forEach(function (row) { row.hidden = false; });
      document.getElementById('profileEmptyRow').hidden = matches.length > 0;
      const start = matches.length ? currentPage * pageSize + 1 : 0;
      const end = Math.min((currentPage + 1) * pageSize, matches.length);
      document.getElementById('profileTableCount').textContent = 'Showing ' + start + '–' + end + ' of ' + matches.length + ' professor accounts';
      document.getElementById('profilePage').textContent = 'Page ' + (currentPage + 1) + ' of ' + pages;
      previous.disabled = currentPage === 0;
      next.disabled = currentPage + 1 >= pages;
    }
    search.addEventListener('input', function () { currentPage = 0; renderRegister(); });
    previous.addEventListener('click', function () { currentPage--; renderRegister(); });
    next.addEventListener('click', function () { currentPage++; renderRegister(); });
    renderRegister();
  }

  const dataNode = document.getElementById('diChartData');
  if (!dataNode) { return; }
  const data = JSON.parse(dataNode.textContent);
  const colors = ['#696cff', '#03c3ec', '#71dd37', '#ffab00', '#8592a3', '#ff3e1d'];
  function chart(id, values, horizontal) {
    const target = document.getElementById(id);
    if (!target) { return; }
    let labels = Object.keys(values || {});
    let counts = labels.map(function (label) { return Number(values[label]); });
    if (!counts.some(function (count) { return count > 0; })) {
      const text = target.querySelector('.di-chart-empty span');
      if (text) { text.textContent = id === 'completionChart' ? 'No professor accounts with available profile data in this scope.' : 'No saved profiles in this scope.'; }
      return;
    }
    target.setAttribute('aria-label', labels.map(function (label, index) { return label + ': ' + counts[index]; }).join('; '));
    if (typeof ApexCharts === 'undefined') {
      const fallback = document.createElement('div');
      fallback.className = 'di-chart-empty';
      fallback.textContent = labels.map(function (label, index) { return label + ': ' + counts[index]; }).join(' · ');
      target.replaceChildren(fallback);
      return;
    }
    if (horizontal && labels.length > 8) {
      counts = counts.slice(0, 8).concat([counts.slice(8).reduce(function (sum, count) { return sum + count; }, 0)]);
      labels = labels.slice(0, 8).concat(['Other ranks']);
    }
    target.replaceChildren();
    const options = {
      chart: { type: horizontal ? 'bar' : 'donut', height: horizontal ? Math.max(275, labels.length * 35 + 50) : 275, fontFamily: 'Public Sans, sans-serif', toolbar: { show: false } },
      colors: id === 'completionChart' ? ['#71dd37', '#696cff', '#ffab00', '#8592a3', '#ff3e1d'] : colors,
      series: horizontal ? [{ name: 'Profiles', data: counts }] : counts,
      labels: labels,
      stroke: { width: horizontal ? 0 : 3, colors: ['#fff'] },
      legend: { position: 'bottom', fontSize: '12px', labels: { colors: '#566a7f' } },
      dataLabels: { enabled: !horizontal, formatter: function (value) { return Math.round(value) + '%'; } },
      tooltip: { y: { formatter: function (value) { return value + (id === 'completionChart' ? ' accounts' : ' profiles'); } } },
      plotOptions: horizontal ? { bar: { horizontal: true, borderRadius: 4, barHeight: '55%' } } : { pie: { donut: { size: '70%', labels: { show: true, total: { show: true, label: id === 'completionChart' ? 'Accounts' : 'Profiles', formatter: function () { return String(counts.reduce(function (sum, count) { return sum + count; }, 0)); } } } } } },
      xaxis: { categories: labels, labels: { style: { colors: '#8592a3' } }, tickAmount: Math.min(4, Math.max.apply(null, counts)), decimalsInFloat: 0 },
      yaxis: { labels: { maxWidth: 190, style: { colors: '#566a7f', fontSize: '12px' } } },
      grid: { borderColor: '#eef0f4' },
      responsive: [{ breakpoint: 576, options: { chart: { height: horizontal ? 300 : 265 }, legend: { fontSize: '11px' } } }]
    };
    new ApexCharts(target, options).render().catch(function () {
      const fallback = document.createElement('div');
      fallback.className = 'di-chart-empty';
      fallback.textContent = labels.map(function (label, index) { return label + ': ' + counts[index]; }).join(' · ');
      target.replaceChildren(fallback);
    });
  }
  chart('completionChart', data.completion, false);
  chart('educationChart', data.education, false);
  chart('employmentChart', data.employment, false);
  chart('rankChart', data.ranks, true);
})();
