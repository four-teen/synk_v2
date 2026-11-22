(function () {
  'use strict';

  const bullet = /^[-*+•][ \t]+/u;
  const label = /^(?:\*\*|__)?(?:\(([a-z]|\d{1,3})\)|([a-z]|\d{1,3})(?:[.):]|[ \t]+[-–—]))(?:\*\*|__)?(?:[ \t]+|$)/i;
  const answerLine = /^(\*\*|__)?(?:correct\s+(?:answer|option|choice)|answer(?:\s+key)?|ans\.?)\s*(\*\*|__)?\s*[:：–—-]/i;
  const fenceLine = /^ {0,3}(`{3,}|~{3,})([^`]*)$/;
  const questionHeader = /^(?:question|q)\s*(?:\d{1,3}\s*[.):–—-]?|[:：])(?:\s+|$)/i;
  const numberedLine = /^\d{1,3}[.)][ \t]+/;
  const review = message => ({ message });

  function unwrapBold(text, allowUnderscores = false) {
    const start = text.slice(0, 2);
    if ((start === '**' || (allowUnderscores && start === '__')) && text.length > 4 && text.indexOf(start, 2) === text.length - 2) {
      return text.slice(2, -2).trim();
    }
    return text;
  }

  function clipboardLine(line) {
    let text = line.trim();
    // Markdown copies may include literal hard breaks and trailing space entities.
    while (/(?:&#x20;|&#32;|&nbsp;)$/i.test(text.slice(-6))) {
      text = text.slice(0, text.lastIndexOf('&')).trimEnd();
    }
    if (text.endsWith('\\') && !/[\\:]/.test(text.match(/\S+$/)[0].slice(0, -1))) text = text.slice(0, -1).trimEnd();
    return text;
  }

  function readChoice(text) {
    const marker = text.match(bullet);
    let body = unwrapBold(marker ? text.slice(marker[0].length) : text);
    const box = body.match(/^\[([ x])\](?:[ \t]+|$)/i);
    if (box) body = unwrapBold(body.slice(box[0].length));
    if (label.test(body)) body = unwrapBold(body, true);
    const prefix = body.match(label);
    if (!marker && !box && !prefix) return null;
    const key = prefix ? (prefix[1] || prefix[2]).toUpperCase() : '';
    return {
      key,
      style: key ? (/^\d/.test(key) ? 'numbers' : 'letters') : 'bullets',
      checked: box ? box[1] !== ' ' : null,
      text: unwrapBold(prefix ? body.slice(prefix[0].length).trim() : body)
    };
  }

  function comparable(text) {
    return unwrapBold(text.trim()).normalize('NFC');
  }

  function sentenceAnswer(text) {
    return /^[\p{L}\p{M}\p{N} ,’'-]+\.$/u.test(text) && /\p{L}\s+\p{L}/u.test(text) ? text.slice(0, -1) : text;
  }

  function correctChoice(answer, choices) {
    const value = comparable(answer);
    const fullText = choices.findIndex(choice => comparable(choice.text) === value);
    const reference = value.replace(/^(?:option|choice)\s+/i, '');
    const prefix = reference.match(label);
    const key = prefix ? prefix[1] || prefix[2] : reference.match(/^([a-z]|\d{1,3})$/i)?.[1];
    if (!key) return fullText;

    const index = choices.findIndex(choice => choice.key === key.toUpperCase());
    const rest = prefix ? comparable(reference.slice(prefix[0].length)) : '';
    if (index < 0) return fullText;
    if (fullText >= 0 && fullText !== index) return -1;
    if (rest && comparable(choices[index].text) !== rest) {
      const matching = choices.map(choice => sentenceAnswer(comparable(choice.text)));
      const prose = sentenceAnswer(rest);
      if (matching[index] !== prose || matching.filter(value => value === prose).length !== 1) return -1;
    }
    return index;
  }

  function readRows(lines) {
    const rows = [];
    let fence = '';
    for (const [index, raw] of lines.entries()) {
      const marker = raw.match(fenceLine);
      // Inspect structure only outside code fences. Keep source lines for the prompt.
      if (fence || marker) {
        rows.push({ index, code:true });
        if (!fence) fence = marker[1];
        else if (marker && marker[1][0] === fence[0] && marker[1].length >= fence.length && !marker[2].trim()) fence = '';
        continue;
      }
      const text = clipboardLine(raw);
      if (!text) continue;
      const content = unwrapBold(text.replace(bullet, '').replace(/^#{1,6}\s+/, ''), true);
      const match = content.match(answerLine);
      let answer = match ? content.slice(match[0].length) : null;
      if (match?.[1] && !match[2] && answer.startsWith(match[1])) answer = answer.slice(2);
      rows.push({ index, text, choice:match ? null : readChoice(text), answer:answer?.trim() ?? null });
    }
    return { rows, unclosedFence:!!fence };
  }

  function containsAnotherQuestion(rows) {
    let sawQuestion = false;
    let sawChoices = false;
    for (const [index, row] of rows.entries()) {
      if (row.code) continue;
      const text = unwrapBold(row.text);
      if (index && (/^#{1,6}\s+\d{1,3}[.)]\s+/.test(text) || questionHeader.test(text))) return true;
      const looksLikeQuestion = text.endsWith('?') && (!row.choice || index === 0);
      if (looksLikeQuestion && sawChoices) return true;
      sawQuestion ||= looksLikeQuestion;

      const next = rows[index + 1]?.choice;
      if (!row.choice || !next) continue;
      if (row.choice.key === 'A' && next.key === 'B') return true;
      if (row.choice.checked !== null && next.checked !== null) return true;
      if (sawQuestion && ((row.choice.key === '1' && next.key === '2') || (row.choice.style === 'bullets' && next.style === 'bullets'))) {
        sawChoices = true;
      }
    }
    return false;
  }

  function parseQuestionPaste(text) {
    if (text.length > 24000) return null;
    const lines = text.replace(/\r\n?/g, '\n').split('\n');
    const { rows } = readRows(lines);
    const keys = rows.filter(row => row.answer != null);
    const choiceRows = rows.filter(row => row.choice);
    const markedChoices = choiceRows.filter(({ choice }) => choice.key || choice.checked !== null);
    if (!choiceRows.length || (!keys.length && markedChoices.length < 2)) return null;
    if (keys.length > 1) return review('Paste one question at a time, with one correct answer.');

    const key = keys[0];
    const end = key ? rows.indexOf(key) : rows.length;
    const afterKey = rows.slice(end + 1);
    const answer = key && (key.answer || (afterKey.length === 1 && !afterKey[0].code ? afterKey[0].text : ''));
    if (key && afterKey.length && (key.answer || !answer)) return review('Paste just one question, with its answer at the end.');

    let start = end;
    const style = rows[end - 1]?.choice?.style;
    while (start > 0 && style && rows[start - 1].choice?.style === style) start--;
    // A numbered question can start at 1 and be followed by a second 1 for its first choice.
    if (start === 0 && style === 'numbers' && rows[0].text.endsWith('?') && rows[1]?.choice?.key === '1') start++;
    const choices = rows.slice(start, end).map(row => row.choice);
    if (choices.length < 2 || choices.length > 6 || choices.some(choice => !choice.text)) {
      return review('Put two to six complete choices on separate lines below the question.');
    }
    if (style !== 'bullets' && choices.some((choice, index) => choice.key !== (style === 'numbers' ? String(index + 1) : 'ABCDEF'[index]))) {
      return review('Check the choice labels. They should start at A or 1 and appear in order.');
    }
    if (new Set(choices.map(choice => comparable(choice.text).toLowerCase())).size !== choices.length) {
      return review('Use a different text for each choice.');
    }

    const promptRows = rows.slice(0, start);
    if (containsAnotherQuestion(promptRows)) return review('Paste one question at a time.');
    const beforeChoices = promptRows[promptRows.length - 1];
    if (/^(?:options|choices|answer choices)\s*[:：]?$/i.test(unwrapBold((beforeChoices?.text || '').replace(/^#{1,6}\s+/, ''), true))) {
      promptRows.pop();
    } else if (beforeChoices?.choice && !(start === 1 && numberedLine.test(beforeChoices.text) && choices[0].key)) {
      return review('Use one consistent format for all choices.');
    }
    if (!promptRows.length) return review('Include the question above the choices.');
    const first = promptRows[0];
    const promptLines = lines.slice(first.index, promptRows[promptRows.length - 1].index + 1);
    if (!first.code) promptLines[0] = unwrapBold(first.text.replace(/^#{1,6}\s+/, '')).replace(questionHeader, '').replace(numberedLine, '');
    const prompt = promptLines.join('\n').trim();
    if (!prompt) return review('Include the question above the choices.');
    if (prompt.length > 10000 || choices.some(choice => choice.text.length > 1000)) {
      return review('Use up to 10,000 characters for the question and 1,000 for each choice.');
    }

    const checked = choices.flatMap((choice, index) => choice.checked ? [index] : []);
    const answerKey = key ? correctChoice(answer || '', choices) : checked[0];
    if ((!key && checked.length === 0) || (key && !answer)) return review('Add an answer line with a choice label or the exact choice text.');
    if (answerKey < 0 || checked.length > 1 || (checked.length === 1 && checked[0] !== answerKey)) {
      return review('The correct answer is unclear. Make sure it matches exactly one choice.');
    }
    return { prompt, options:choices.map(choice => choice.text), answerKey };
  }

  function bulkHeading(row) {
    if (!row || row.code || row.answer !== null) return null;
    const heading = row.text.replace(/^#{1,6}\s+/, '');
    const text = unwrapBold(heading, true);
    const match = text.match(/^(?:\*\*|__)?(?:(?:question\s+|q\s*)(\d{1,4})(?:\s*[.):–—-])?|(\d{1,4})[.)]|\((\d{1,4})\))(?:\*\*|__)?(?:[ \t]+|$)/i);
    if (!match) return null;
    return { number:Number(match[1] || match[2] || match[3]), prompt:text.slice(match[0].length), explicit:!!match[1] || heading !== row.text };
  }

  function bulkHeadings(rows) {
    const headings = [];
    let style = '';
    let nextChoice = 1;
    let hasAnswer = false;
    for (const [index, row] of rows.entries()) {
      const heading = bulkHeading(row);
      const following = rows[index + 1];
      const startsChoices = following?.choice && (following.choice.style !== 'numbers' || following.choice.key === '1');
      // Inside a block, an ordered numeric list can be its choices rather than new questions.
      const numberedChoice = headings.length && !heading?.explicit && !hasAnswer && (!style || style === 'numbers')
        && row.choice?.style === 'numbers' && row.choice.key === String(nextChoice) && !startsChoices;
      if (heading && !numberedChoice) {
        headings.push({ ...heading, row:index });
        style = '';
        nextChoice = 1;
        hasAnswer = false;
      } else {
        if (row.choice) {
          style = style || row.choice.style;
          if (row.choice.style === 'numbers') nextChoice++;
        }
        if (row.answer != null) hasAnswer = true;
      }
    }
    return headings;
  }

  function readBulkDetails(lines) {
    const details = { title:'', instructions:'', suggestedPoints:1, expectedCount:null, declaredTotal:'', notes:[] };
    let inInstructions = false;
    let firstLine = true;
    for (const line of lines) {
      const text = unwrapBold(clipboardLine(line).replace(/^#{1,6}\s+/, ''), true);
      if (!text) {
        if (details.instructions) inInstructions = false;
        continue;
      }
      const match = text.match(/^(?:\*\*|__)?(title|instructions?|(?:number of )?(?:items|questions)|suggested points|points per (?:item|question)|total(?: points)?)(?:\*\*|__)?\s*[:：](?:\*\*|__)?\s*(.*)$/i);
      if (match || /^instructions?$/i.test(text)) {
        const name = match ? match[1].toLowerCase() : 'instructions';
        const value = match?.[2] || '';
        inInstructions = name.startsWith('instruction');
        if (inInstructions) details.instructions = value;
        else if (name === 'title') details.title = value;
        else if (name.startsWith('total')) details.declaredTotal = value;
        else if (name.includes('points')) {
          const amount = value.match(/^(\d{1,5}(?:\.\d{1,2})?)(?:\s|$)/)?.[1];
          if (Number(amount) > 0 && Number(amount) <= 10000) details.suggestedPoints = Number(amount);
          else details.notes.push({ text });
        } else if (/^\d+$/.test(value)) details.expectedCount = Number(value);
        else details.notes.push({ text });
      } else if (inInstructions) details.instructions += (details.instructions ? '\n' : '') + line.trim();
      else if (firstLine) details.title = text;
      else details.notes.push({ text:line.trim() });
      firstLine = false;
    }
    return details;
  }

  function splitBulkPaste(text) {
    if (text.length > 1048576) return review('This paste is too large. Split the quiz into shorter batches.');
    const lines = text.replace(/\r\n?/g, '\n').split('\n');
    const { rows, unclosedFence } = readRows(lines);
    if (unclosedFence) return review('Close the unfinished code block before checking the questions.');
    const headings = bulkHeadings(rows);
    if (!headings.length) return review('Start each question on a new line with a number, such as 1. or Question 1.');
    if (headings.length > 100) return review('Paste up to 100 questions at a time.');

    const details = readBulkDetails(lines.slice(0, rows[headings[0].row].index));
    const items = headings.map((heading, index) => {
      const start = rows[heading.row].index;
      const next = headings[index + 1]?.row ?? rows.length;
      const end = rows[next]?.index ?? lines.length;
      const body = rows.slice(heading.row + 1, next);
      const answers = body.filter(row => row.answer != null);
      let contentEnd = end;
      if (answers.length === 1) {
        const answer = answers[0];
        const answerIndex = body.indexOf(answer);
        const continuation = body[answerIndex + 1];
        // If the answer label has no value, its next plain line can supply it.
        const hasContinuation = !answer.answer && continuation && !continuation.code;
        const trailing = body.slice(answerIndex + (hasContinuation ? 2 : 1));
        if (!trailing.some(row => row.choice?.key || row.choice?.checked != null)) {
          contentEnd = (hasContinuation ? continuation : answer).index + 1;
        }
      } else if (!answers.length && body.filter(row => row.choice?.checked != null).length >= 2) {
        const lastChoice = body.filter(row => row.choice).pop();
        contentEnd = lastChoice.index + 1;
      }
      const note = lines.slice(contentEnd, end).join('\n').trim();
      if (note) details.notes.push({ number:heading.number, text:note });
      return { number:heading.number, source:[heading.prompt, ...lines.slice(start + 1, contentEnd)].join('\n').trim() };
    });
    return { details, items };
  }

  window.synkAssessmentPaste = parseQuestionPaste;
  window.synkSplitAssessmentPaste = splitBulkPaste;
})();
