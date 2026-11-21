'use strict';

(() => {
  const report = document.getElementById('sefWordReport');
  if (!report) return;

  const bottomMargin = parseFloat(getComputedStyle(report.firstElementChild).paddingBottom);

  function fits({ sheet, body }) {
    return body.getBoundingClientRect().bottom <= sheet.getBoundingClientRect().bottom - bottomMargin;
  }

  function addSheet(page) {
    const sheet = page.sheet.cloneNode(false);
    sheet.classList.remove('sef-word-overflow');
    const body = page.body.cloneNode(false);
    const stationery = page.sheet.querySelector('.sef-word-stationery').cloneNode(true);
    stationery.setAttribute('aria-hidden', 'true');
    sheet.append(stationery, body);
    page.sheet.after(sheet);
    return { sheet, body };
  }

  function paginateForm(page) {
    const sections = Array.from(page.body.children);
    page.body.replaceChildren();
    for (const section of sections) {
      if (page.sheet.classList.contains('sef-word-overflow')) page = addSheet(page);
      page.body.append(section);
      if (fits(page)) continue;
      if (page.body.childElementCount > 1) {
        page = addSheet(page);
        page.body.append(section);
      }
      if (!fits(page)) page.sheet.classList.add('sef-word-overflow');
    }
  }

  function paginateConsolidated(page) {
    const table = page.body.querySelector('.sef-consolidated-table');
    const heading = page.body.querySelector('.sef-consolidated-heading').cloneNode(true);
    heading.querySelector('[id]').removeAttribute('id');
    const signatures = page.body.querySelector('.sef-consolidated-signatures');
    const groups = Array.from(table.tBodies);
    groups.forEach(group => group.remove());
    signatures.remove();
    const tableHeader = table.cloneNode(true);
    let currentTable = table;

    for (const group of groups) {
      const [label, ...rows] = Array.from(group.rows);
      let currentGroup = currentTable.createTBody();
      currentGroup.append(label.cloneNode(true));
      for (const row of rows) {
        currentGroup.append(row);
        if (fits(page)) continue;
        row.remove();
        if (currentGroup.rows.length === 1) currentGroup.remove();

        page = addSheet(page);
        currentTable = tableHeader.cloneNode(true);
        page.body.append(heading.cloneNode(true), currentTable);
        currentGroup = currentTable.createTBody();
        currentGroup.append(label.cloneNode(true), row);
      }
    }

    page.body.append(signatures);
    if (!fits(page)) addSheet(page).body.append(heading.cloneNode(true), signatures);
  }

  function paginate() {
    if (report.hasAttribute('data-paginated')) return;
    report.setAttribute('data-paginated', '');
    for (const sheet of Array.from(report.children)) {
      const page = { sheet, body: sheet.querySelector('.sef-report-body') };
      if (fits(page)) continue;
      if (sheet.classList.contains('sef-word-consolidated')) {
        paginateConsolidated(page);
      } else {
        paginateForm(page);
      }
    }
  }

  const ready = document.fonts.ready.then(paginate);
  window.addEventListener('beforeprint', paginate);
  document.getElementById('sefPrintReport').addEventListener('click', async () => {
    await ready;
    window.print();
  });
})();
