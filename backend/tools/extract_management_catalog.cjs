'use strict';

// Parse a phpMyAdmin dump as data. No SQL from the supplied file is executed.
const fs = require('node:fs');
const crypto = require('node:crypto');
const { TextDecoder } = require('node:util');
const SOURCE_COLUMNS = {
  tblcontibution: ['contributionid','event','petsa','fines','semester','academicyear','status','org','college'],
  tblevent: ['eventid','event','petsa','fines','semester','academicyear','status','org','stat','college','latitude','longitude','attendance_radius'],
  tblevents: ['id','event_name','department','event_date','start_time','end_time','fine_per_minute','grace_minutes','status','potentialfine'],
};
const sha256 = value => crypto.createHash('sha256').update(value).digest('hex').toUpperCase();

function* statements(sql) {
  let start = 0, parts = [], quote = null;
  for (let i = 0; i < sql.length; i++) {
    const ch = sql[i];
    if (quote) {
      if (ch === '\\') i++;
      else if (ch === quote) { if (sql[i + 1] === quote) i++; else quote = null; }
      continue;
    }
    if (ch === "'" || ch === '"' || ch === '`') { quote = ch; continue; }
    if (ch === '#' || (ch === '-' && sql[i + 1] === '-' && /\s/.test(sql[i + 2] || ''))) {
      parts.push(sql.slice(start, i), ' ');
      const end = sql.indexOf('\n', i); i = end < 0 ? sql.length : end; start = i;
    } else if (ch === '/' && sql[i + 1] === '*') {
      parts.push(sql.slice(start, i), ' ');
      const end = sql.indexOf('*/', i + 2);
      if (end < 0) throw Error('Unterminated SQL comment.');
      i = end + 1; start = i + 1;
    } else if (ch === ';') {
      parts.push(sql.slice(start, i)); const statement = parts.join('').trim();
      if (statement) yield statement;
      start = i + 1; parts = [];
    }
  }
  if (quote || parts.concat(sql.slice(start)).join('').trim()) throw Error('Unterminated SQL statement.');
}

function parseValues(valueSql, columns) {
  let i = 0; const rows = [];
  const skip = () => { while (/\s/.test(valueSql[i] || '') && i < valueSql.length) i++; };
  while (i < valueSql.length) {
    skip(); if (valueSql[i++] !== '(') throw Error('Expected a data row.');
    const values = [];
    for (;;) {
      skip(); let value = '';
      if (valueSql[i] === "'") {
        i++; let closed = false;
        while (i < valueSql.length) {
          const ch = valueSql[i++];
          if (ch === '\\') {
            if (i >= valueSql.length) throw Error('Incomplete string escape.');
            const next = valueSql[i++];
            const escapes = { '0':'\0', 'n':'\n', 'r':'\r', 't':'\t', 'b':'\b', 'Z':'\x1a', '%':'\\%', '_':'\\_' };
            value += Object.hasOwn(escapes, next) ? escapes[next] : next;
          } else if (ch === "'") {
            if (valueSql[i] === "'") { value += "'"; i++; }
            else { closed = true; break; }
          } else value += ch;
        }
        if (!closed) throw Error('Unterminated string value.');
      } else {
        const start = i; while (i < valueSql.length && ![',',')'].includes(valueSql[i])) i++;
        value = valueSql.slice(start, i).trim();
        if (/^NULL$/i.test(value)) value = null;
        else if (!/^-?\d+(?:\.\d+)?$/.test(value)) throw Error('Only literal SQL values are accepted.');
      }
      values.push(value); skip();
      if (valueSql[i] === ')') { i++; break; }
      if (valueSql[i++] !== ',') throw Error('Expected a value separator.');
    }
    if (values.length !== columns.length) throw Error('Unexpected column count.');
    rows.push(Object.fromEntries(columns.map((key, index) => [key, values[index]])));
    skip(); if (i === valueSql.length) break;
    if (valueSql[i++] !== ',') throw Error('Expected a row separator.');
    skip(); if (i === valueSql.length) throw Error('Incomplete data row.');
  }
  return rows;
}

function extractRows(sql) {
  const data = Object.fromEntries(Object.keys(SOURCE_COLUMNS).map(table => [table, []]));
  for (const statement of statements(sql.replace(/^\uFEFF/, ''))) {
    const header = /^INSERT\s+INTO\s+`([^`]+)`\s*\(([^)]*)\)\s+VALUES\s+/i.exec(statement);
    if (!header || !Object.hasOwn(SOURCE_COLUMNS, header[1])) continue;
    const table = header[1];
    const columns = header[2].split(',').map(column => {
      const name = /^\s*`([^`]+)`\s*$/.exec(column);
      if (!name) throw Error('Unexpected column syntax: ' + table); return name[1];
    });
    if (JSON.stringify([...columns].sort()) !== JSON.stringify([...SOURCE_COLUMNS[table]].sort())) throw Error('Unexpected source schema: ' + table);
    data[table].push(...parseValues(statement.slice(header[0].length), columns));
  }
  for (const [table, rows] of Object.entries(data)) {
    if (!rows.length) throw Error('No catalog rows found: ' + table);
    const id = SOURCE_COLUMNS[table][0];
    if (new Set(rows.map(row => row[id])).size !== rows.length) throw Error('Duplicate source IDs: ' + table);
  }
  return data;
}

// Recover reversible Windows-1252/UTF-8 mojibake before Unicode normalization.
// Explicit mapping also works with Node builds that lack Windows-1252 ICU data.
const windows1252Controls = [0x20ac,0x81,0x201a,0x192,0x201e,0x2026,0x2020,0x2021,
  0x2c6,0x2030,0x160,0x2039,0x152,0x8d,0x17d,0x8f,0x90,0x2018,0x2019,0x201c,
  0x201d,0x2022,0x2013,0x2014,0x2dc,0x2122,0x161,0x203a,0x153,0x9d,0x17e,0x178];
const reverse1252 = new Map(Array.from({ length:256 }, (_, n) => [String.fromCodePoint(n), n]));
windows1252Controls.forEach((codepoint, index) => reverse1252.set(String.fromCodePoint(codepoint), index + 128));
function uppercase(value) {
  let text = String(value == null ? '' : value), repaired = false;
  for (let pass = 0; pass < 2 && /[\u00c3\u00c2\u00f0\u00e2]/u.test(text); pass++) {
    const bytes = [...text].map(ch => reverse1252.get(ch));
    if (bytes.some(byte => byte === undefined)) break;
    try {
      const decoded = new TextDecoder('utf-8', { fatal:true }).decode(Uint8Array.from(bytes));
      if (decoded === text) break; text = decoded; repaired = true;
    } catch (_) { break; }
  }
  text = text.normalize('NFKC').toUpperCase().trim();
  if (!text || /[\x00-\x1f\x7f-\x9f\ufffd]/u.test(text)) throw Error('Empty or invalid catalog text.');
  return { text, repaired };
}

function buildManifest(sql) {
  const data = extractRows(sql), normalizations = [];
  function base(table, row, titleField) {
    const id = Number(row[SOURCE_COLUMNS[table][0]]), title = uppercase(row[titleField]);
    if (title.repaired) normalizations.push({ source_table:table.toUpperCase(), source_record_id:id, action:'REPAIRED TEXT ENCODING BEFORE UPPERCASE NORMALIZATION' });
    return { college_code:uppercase(row.college || row.department).text, organization_code:row.org ? uppercase(row.org).text : null,
      academic_year:row.academicyear || null, semester:row.semester ? ({ FIRST:1, SECOND:2, MIDYEAR:3 })[uppercase(row.semester).text] : null,
      title:title.text, source_table:table.toUpperCase(), source_record_id:id,
      source_checksum:sha256(JSON.stringify(Object.fromEntries(SOURCE_COLUMNS[table].map(key => [key,row[key]])))) };
  }
  const audience = value => ({ ALL:'ALL', OTHERS:'SELECTED' })[uppercase(value).text];
  const contributions = data.tblcontibution.map(row => ({ ...base('tblcontibution', row, 'event'),
    contribution_date:row.petsa, amount:row.fines, audience_mode:audience(row.status), status:'DRAFT',
    source_metadata:{ STATUS:uppercase(row.status).text } }));
  const events = data.tblevent.map(row => ({ ...base('tblevent', row, 'event'), event_date:row.petsa,
    audience_mode:audience(row.status), status:'DRAFT', absence_fine:row.fines,
    latitude:row.latitude, longitude:row.longitude, attendance_radius_m:Number(row.attendance_radius),
    source_metadata:{ STATUS:uppercase(row.status).text, STAT:Number(row.stat) }, session:null }));
  events.push(...data.tblevents.map(row => ({ ...base('tblevents', row, 'event_name'), event_date:row.event_date,
    audience_mode:'UNSPECIFIED', status:uppercase(row.status).text, absence_fine:null,
    latitude:null, longitude:null, attendance_radius_m:null, source_metadata:null,
    session:{ title:uppercase(row.event_name).text, starts_at:row.event_date + ' ' + row.start_time,
      ends_at:row.event_date + ' ' + row.end_time, fine_per_minute:row.fine_per_minute,
      grace_minutes:Number(row.grace_minutes), potential_fine:row.potentialfine, status:uppercase(row.status).text } })));
  return { format_version:1, source_sha256:sha256(sql), source_counts:Object.fromEntries(Object.entries(data).map(([table, rows]) => [table.toUpperCase(), rows.length])),
    normalizations, contributions, events };
}

if (require.main === module) {
  try {
    const [source, output] = process.argv.slice(2);
    if (!source || !output || process.argv.length !== 4) throw Error('Usage: node backend/tools/extract_management_catalog.cjs <source.sql> <output.json>');
    const manifest = buildManifest(fs.readFileSync(source, 'utf8'));
    fs.writeFileSync(output, JSON.stringify(manifest, null, 2) + '\n', { flag:'wx' });
    process.stdout.write(JSON.stringify({ source_counts:manifest.source_counts, events:manifest.events.length,
      sessions:manifest.events.filter(event => event.session).length, normalizations:manifest.normalizations }, null, 2) + '\n');
  } catch (error) { process.stderr.write(error.message + '\n'); process.exitCode = 1; }
}
module.exports = { statements, parseValues, extractRows, uppercase, buildManifest };
