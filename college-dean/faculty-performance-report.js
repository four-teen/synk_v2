'use strict';

(() => {
  const facultyPicker = document.getElementById('sefReportFaculty');
  facultyPicker?.addEventListener('change', () => {
    if (facultyPicker.form.reportValidity()) facultyPicker.form.requestSubmit();
  });

  const report = document.getElementById('sefIndividualReport');
  if (!report) return;

  const firstSheet = report.querySelector('.sef-report-sheet');
  const firstBody = firstSheet.querySelector('.sef-report-body');
  const letterhead = firstSheet.querySelector('.sef-report-letterhead');
  const comments = firstBody.querySelector('.sef-report-comments');
  const firstList = comments.querySelector('ol');
  const signatures = firstBody.querySelector('.sef-report-signatures');
  const sheetStyle = getComputedStyle(firstSheet);
  const topMargin = parseFloat(sheetStyle.paddingTop);
  const bottomMargin = parseFloat(sheetStyle.paddingBottom);

  function fits({ sheet, body }) {
    return body.getBoundingClientRect().bottom <= sheet.getBoundingClientRect().bottom - bottomMargin;
  }

  function addSheet(commentNumber) {
    const sheet = firstSheet.cloneNode(false);
    const body = firstBody.cloneNode(false);
    const header = letterhead.cloneNode(true);
    header.setAttribute('aria-hidden', 'true');
    sheet.append(header, body);
    report.append(sheet);

    let list;
    if (commentNumber) {
      const section = comments.cloneNode(false);
      list = firstList.cloneNode(false);
      list.start = commentNumber;
      section.append(comments.querySelector('h2').cloneNode(true), list);
      body.append(section);
    }
    return { sheet, body, list };
  }

  function splitComment(entry, page) {
    const text = entry.querySelector('.sef-report-comment-text');
    const characters = Array.from(text.textContent);
    let low = 1;
    let high = characters.length - 1;
    while (low < high) {
      const middle = Math.ceil((low + high) / 2);
      text.textContent = characters.slice(0, middle).join('');
      if (fits(page)) {
        low = middle;
      } else {
        high = middle - 1;
      }
    }

    // Prefer a nearby word boundary. An unbroken word can span multiple sheets.
    const earliestBoundary = Math.max(1, low - 32);
    let cut = low;
    while (cut >= earliestBoundary && /\S/u.test(characters[cut - 1]) && /\S/u.test(characters[cut])) cut--;
    if (cut < earliestBoundary) cut = low;
    text.textContent = characters.slice(0, cut).join('');

    const continuation = document.createElement('li');
    continuation.value = entry.value;
    continuation.className = 'sef-report-comment-continuation';
    const remainingText = text.cloneNode(false);
    remainingText.textContent = characters.slice(cut).join('');
    continuation.append(remainingText);
    return continuation;
  }

  function paginate() {
    if (report.hasAttribute('data-paginated')) return;
    report.setAttribute('data-paginated', '');

    let page = { sheet: firstSheet, body: firstBody, list: firstList };
    if (fits(page)) return;

    const entries = Array.from(firstList.children);
    firstList.replaceChildren();
    signatures.remove();

    entries.forEach((entry, index) => {
      const commentNumber = index + 1;
      entry.value = commentNumber;
      page.list.append(entry);
      if (fits(page)) return;

      // Keep a comment together when it fits on a fresh sheet.
      const section = page.list.parentElement;
      const headingHeight = page.list.getBoundingClientRect().top - section.getBoundingClientRect().top
        + parseFloat(getComputedStyle(section).marginTop);
      const sheetRect = page.sheet.getBoundingClientRect();
      const entryRect = entry.getBoundingClientRect();
      const entryStyle = getComputedStyle(entry);
      const freshCapacity = sheetRect.height - topMargin - bottomMargin - headingHeight;
      const keepWhole = entryRect.height + parseFloat(entryStyle.marginBottom) <= freshCapacity;
      const hasTwoLines = sheetRect.bottom - bottomMargin - entryRect.top >= 2 * parseFloat(entryStyle.lineHeight);
      if (keepWhole || !hasTwoLines) {
        entry.remove();
        if (!page.list.childElementCount) page.list.parentElement.remove();
        page = addSheet(commentNumber);
        page.list.append(entry);
      }

      let part = entry;
      while (!fits(page)) {
        part = splitComment(part, page);
        page = addSheet(commentNumber);
        page.list.append(part);
      }
    });

    page.body.append(signatures);
    if (!fits(page)) addSheet().body.append(signatures);
  }

  const ready = document.fonts.ready.then(paginate);
  window.addEventListener('beforeprint', paginate);
  document.getElementById('sefPrintReport').addEventListener('click', async () => {
    await ready;
    window.print();
  });
})();
