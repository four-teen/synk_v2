'use strict';
const assert = require('node:assert/strict');
const { statements, parseValues, extractRows, uppercase, buildManifest } = require('../backend/tools/extract_management_catalog.cjs');
let checks = 0;
function check(work) { work(); checks++; }
const inserts = [
  "INSERT INTO `tblcontibution` (`contributionid`,`event`,`petsa`,`fines`,`semester`,`academicyear`,`status`,`org`,`college`) VALUES (52,'Teacher\\'s day; first, meeting','2025-10-03',5,'First','2025-2026','Others','CSSO','CCS');",
  "INSERT INTO `tblevent` (`eventid`,`event`,`petsa`,`fines`,`semester`,`academicyear`,`status`,`org`,`stat`,`college`,`latitude`,`longitude`,`attendance_radius`) VALUES (97,'Assembly','2026-09-11',25,'First','2026-2027','All','PSITS',0,'CCS',NULL,NULL,100);",
  "INSERT INTO `tblevents` (`id`,`event_name`,`department`,`event_date`,`start_time`,`end_time`,`fine_per_minute`,`grace_minutes`,`status`,`potentialfine`) VALUES (15,'Timed Assembly','CCS','2026-05-14','08:00:00','11:00:00',0.09,30,'Closed',13.50);",
];
const sql = "-- Ignored: INSERT INTO `tblcontibution` (bad) VALUES (999);\n"
  + "/* SQL is data, including DROP TABLE statements. */ DROP TABLE `tbl_useraccount`;\n"
  + "INSERT INTO `unrelated` (`value`) VALUES ('INSERT INTO `tblcontibution` (bad) VALUES (999);');\n"
  + inserts.join('\n');
check(() => assert.equal([...statements(sql)].length, 5));
check(() => assert.deepEqual(parseValues("('O''Brien',NULL,-12.50),('A\\nB','NULL',0)", ['name','optional','amount']), [
  { name:"O'Brien", optional:null, amount:'-12.50' }, { name:'A\nB', optional:'NULL', amount:'0' },
]));
check(() => assert.throws(() => parseValues('(NOW())', ['date'])));
check(() => assert.throws(() => parseValues("('unclosed)", ['name'])));
check(() => assert.throws(() => parseValues('(1,2)', ['id'])));
check(() => assert.throws(() => [...statements("INSERT INTO `x` VALUES ('unclosed);") ]));
check(() => assert.throws(() => [...statements('/* unfinished') ]));
check(() => assert.deepEqual(Object.fromEntries(Object.entries(extractRows(sql)).map(([key, rows]) => [key, rows.length])), { tblcontibution:1, tblevent:1, tblevents:1 }));
check(() => assert.throws(() => extractRows(sql + inserts[0]), /Duplicate source IDs/));
check(() => assert.throws(() => extractRows(inserts[0]), /No catalog rows/));
check(() => assert.equal(uppercase('  café ñ  ').text, 'CAFÉ Ñ'));
check(() => assert.equal(uppercase('𝗠𝗶𝘁𝗶𝗻𝗴 𝗱𝗲 𝗔𝘃𝗮𝗻𝗰𝗲').text, 'MITING DE AVANCE'));
check(() => assert.deepEqual(uppercase('\u00f0\u009d\u2014\u00a0'), { text:'M', repaired:true }));
check(() => assert.deepEqual(uppercase('caf\u00c3\u00a9'), { text:'CAFÉ', repaired:true }));
check(() => assert.throws(() => uppercase('INVALID\ufffd')));
const manifest = buildManifest(sql);
check(() => assert.equal(manifest.contributions[0].title, "TEACHER'S DAY; FIRST, MEETING"));
check(() => assert.equal(manifest.contributions[0].audience_mode, 'SELECTED'));
check(() => assert.deepEqual(manifest.contributions[0].source_metadata, { STATUS:'OTHERS' }));
check(() => assert.equal(manifest.events.length, 2));
check(() => assert.equal(manifest.events[0].status, 'DRAFT'));
check(() => assert.equal(manifest.events[1].status, 'CLOSED'));
check(() => assert.deepEqual([manifest.events[1].organization_code, manifest.events[1].academic_year, manifest.events[1].semester], [null,null,null]));
check(() => assert.equal(manifest.events[1].session.fine_per_minute, '0.09'));
check(() => assert.equal(manifest.events[1].session.starts_at, '2026-05-14 08:00:00'));
check(() => assert.match(manifest.contributions[0].source_checksum, /^[0-9A-F]{64}$/));
check(() => assert.deepEqual(manifest, buildManifest(sql)));
console.log('Management catalog extraction: ' + checks + ' checks passed.');
