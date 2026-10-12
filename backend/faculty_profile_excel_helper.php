<?php

require_once __DIR__ . '/faculty_profile_helper.php';
require_once __DIR__ . '/schema_helper.php';

// Populate the original OOXML package directly so Excel's native styles, logos,
// merged cells and printer settings survive without a spreadsheet round-trip.
const SYNK_FACULTY_XLSX_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const SYNK_FACULTY_XLSX_REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

function synk_faculty_excel_xml(string $xml): DOMDocument
{
    $document = new DOMDocument();
    if (!$document->loadXML($xml, LIBXML_NONET)) {
        throw new RuntimeException('The faculty profile template could not be read.');
    }
    return $document;
}

function synk_faculty_excel_xpath(DOMDocument $document): DOMXPath
{
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('s', SYNK_FACULTY_XLSX_NS);
    return $xpath;
}

function synk_faculty_excel_value(DOMElement $cell, $value, ?string $formula = null): void
{
    while ($cell->firstChild) $cell->removeChild($cell->firstChild);
    $cell->removeAttribute('t');
    $document = $cell->ownerDocument;
    if ($formula !== null) {
        $cell->appendChild($document->createElementNS(SYNK_FACULTY_XLSX_NS, 'f', $formula));
    }
    if ($value === '' || $value === null) return;
    if (is_int($value) || is_float($value)) {
        $cell->appendChild($document->createElementNS(SYNK_FACULTY_XLSX_NS, 'v', (string)$value));
        return;
    }
    // Inline strings keep identifiers and leading =, +, -, @ literal in Excel.
    $cell->setAttribute('t', 'inlineStr');
    $inline = $document->createElementNS(SYNK_FACULTY_XLSX_NS, 'is');
    $text = $document->createElementNS(SYNK_FACULTY_XLSX_NS, 't');
    $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
    $value = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string)$value) ?? '';
    $text->appendChild($document->createTextNode($value));
    $inline->appendChild($text);
    $cell->appendChild($inline);
}

function synk_faculty_excel_eligibilities(mysqli $conn, array $records): array
{
    $labels = [];
    $ids = array_unique(array_filter(array_map(static function (array $record): int {
        return max(0, (int)($record['user_id'] ?? 0));
    }, $records)));
    if (!synk_table_exists($conn, 'tbl_faculty_eligibilities')) return $labels;
    if ($ids && synk_table_exists($conn, 'tbl_faculty_profile_eligibilities')) {
        $result = $conn->query('SELECT pe.user_id, e.name FROM tbl_faculty_profile_eligibilities pe
        JOIN tbl_faculty_eligibilities e ON e.eligibility_id = pe.eligibility_id
        WHERE pe.user_id IN (' . implode(',', $ids) . ') ORDER BY e.sort_order, e.name, e.eligibility_id');
        while ($row = $result->fetch_assoc()) $labels[(int)$row['user_id']][] = $row['name'];
        $result->close();
    }
    $catalog = synk_faculty_profile_eligibility_catalog($conn);
    foreach ($records as $record) {
        if (($record['profile']['_storage'] ?? '') !== 'faculty') continue;
        $key = 'faculty:' . (int)$record['faculty_id'];
        foreach ($record['profile']['eligibility_ids'] ?? [] as $id) {
            if (isset($catalog[$id])) $labels[$key][] = $catalog[$id]['name'];
        }
    }
    return $labels;
}

function synk_faculty_excel_groups(array $records, array $directory): array
{
    $groups = [];
    foreach ($records as $record) {
        $profile = $record['profile'] ?? [];
        $campus = trim((string)($record['campus_name'] ?? ''));
        $college = trim((string)($record['college_name'] ?? ''));
        if ($campus === '' || $campus === 'Not recorded') $campus = trim((string)($profile['campus_name'] ?? '')) ?: 'Campus not recorded';
        if ($college === '' || $college === 'Not recorded') $college = trim((string)($profile['college_name'] ?? '')) ?: 'College not recorded';
        $key = $campus . "\0" . $college;
        if (!isset($groups[$key])) {
            $campusIds = $record['campus_ids'] ?? [];
            $collegeIds = $record['college_ids'] ?? [];
            $campusCode = count($campusIds) === 1 ? ($directory['campuses'][$campusIds[0]]['campus_code'] ?? '') : '';
            $collegeCode = count($collegeIds) === 1 ? ($directory['colleges'][$collegeIds[0]]['college_code'] ?? '') : '';
            $groups[$key] = ['campus' => $campus, 'college' => $college,
                'title' => ($campusCode ?: $campus) . ' - ' . ($collegeCode ?: $college), 'records' => []];
        }
        $groups[$key]['records'][] = $record;
    }
    uasort($groups, static function (array $left, array $right): int {
        return strnatcasecmp($left['campus'] . ' / ' . $left['college'], $right['campus'] . ' / ' . $right['college']);
    });
    $usedNames = [];
    foreach ($groups as &$group) {
        $base = trim(preg_replace('/[\\\\\/?*\[\]:\x00-\x1F]/u', ' ', $group['title']), " '\t\r\n") ?: 'Faculty profiles';
        $name = mb_substr($base, 0, 31);
        $suffix = 1;
        while (isset($usedNames[mb_strtolower($name)])) {
            $ending = ' (' . ++$suffix . ')';
            $name = mb_substr($base, 0, 31 - mb_strlen($ending)) . $ending;
        }
        $usedNames[mb_strtolower($name)] = true;
        $group['sheet_name'] = $name;
    }
    unset($group);
    return array_values($groups);
}

function synk_faculty_excel_text_height(string $value, float $columnWidth): float
{
    // Conservative wrapping at the template's 12 pt Arial size; leave short rows unchanged.
    $characters = max(1, (int)floor(($columnWidth * 7 - 14) / 10));
    $lines = 0;
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $value)) as $paragraph) {
        $lineLength = 0;
        $lines++;
        foreach (preg_split('/\s+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $length = mb_strlen($word);
            if ($lineLength && $lineLength + 1 + $length > $characters) { $lines++; $lineLength = 0; }
            if ($length > $characters) { $lines += intdiv($length - 1, $characters); $length = (($length - 1) % $characters) + 1; }
            $lineLength += ($lineLength ? 1 : 0) + $length;
        }
    }
    return $lines * 15 + 5;
}

function synk_faculty_excel_wrap_styles(string $stylesXml, string $templateXml): array
{
    $document = synk_faculty_excel_xml($stylesXml);
    $xpath = synk_faculty_excel_xpath($document);
    $styles = $xpath->query('/s:styleSheet/s:cellXfs')->item(0);
    $original = iterator_to_array($styles->childNodes);
    $sheet = synk_faculty_excel_xpath(synk_faculty_excel_xml($templateXml));
    $mapping = [];
    foreach ($sheet->query('/s:worksheet/s:sheetData/s:row/s:c') as $cell) {
        preg_match('/^([A-Z]+)(\d+)$/', $cell->getAttribute('r'), $reference);
        if (!in_array($reference[1], array_merge(range('B', 'K'), ['M', 'N', 'O']), true)
            || !((int)$reference[2] >= 13 && (int)$reference[2] <= 20
                || (int)$reference[2] >= 29 && (int)$reference[2] <= 36
                || (int)$reference[2] >= 43 && (int)$reference[2] <= 50
                || (int)$reference[2] >= 55 && (int)$reference[2] <= 62)) continue;
        $index = (int)$cell->getAttribute('s');
        if (isset($mapping[$index])) continue;
        $style = $original[$index]->cloneNode(true);
        $alignment = $style->getElementsByTagNameNS(SYNK_FACULTY_XLSX_NS, 'alignment')->item(0);
        if ($alignment && $alignment->getAttribute('wrapText') === '1') continue;
        if (!$alignment) {
            $alignment = $document->createElementNS(SYNK_FACULTY_XLSX_NS, 'alignment');
            $style->appendChild($alignment);
        }
        $alignment->setAttribute('wrapText', '1');
        $style->setAttribute('applyAlignment', '1');
        $mapping[$index] = $styles->childNodes->length;
        $styles->appendChild($style);
    }
    $styles->setAttribute('count', (string)$styles->childNodes->length);
    return ['xml' => $document->saveXML(), 'mapping' => $mapping];
}

function synk_faculty_excel_sheet(string $templateXml, array $sharedStrings, array $group, array $term, array $eligibilities, DateTimeImmutable $today, array $wrapStyles): array
{
    $document = synk_faculty_excel_xml($templateXml);
    $xpath = synk_faculty_excel_xpath($document);
    $data = $xpath->query('/s:worksheet/s:sheetData')->item(0);
    $sourceRows = [];
    foreach ($xpath->query('s:row', $data) as $row) $sourceRows[(int)$row->getAttribute('r')] = $row->cloneNode(true);
    $sourceMerges = [];
    $merges = $xpath->query('/s:worksheet/s:mergeCells')->item(0);
    foreach ($xpath->query('s:mergeCell', $merges) as $merge) $sourceMerges[] = $merge->getAttribute('ref');
    while ($data->firstChild) $data->removeChild($data->firstChild);
    while ($merges->firstChild) $merges->removeChild($merges->firstChild);
    $widths = [];
    foreach ($xpath->query('/s:worksheet/s:cols/s:col') as $column) {
        for ($i = (int)$column->getAttribute('min'); $i <= (int)$column->getAttribute('max'); $i++) $widths[$i] = (float)$column->getAttribute('width');
    }
    $cells = [];
    $nextRow = 1;
    $append = static function (int $first, int $last) use ($document, $data, $merges, $sourceRows, $sourceMerges, $sharedStrings, &$cells, &$nextRow): int {
        $start = $nextRow;
        $offset = $start - $first;
        for ($number = $first; $number <= $last; $number++, $nextRow++) {
            $row = isset($sourceRows[$number]) ? $sourceRows[$number]->cloneNode(true) : $document->createElementNS(SYNK_FACULTY_XLSX_NS, 'row');
            $row->setAttribute('r', (string)$nextRow);
            foreach ($row->getElementsByTagNameNS(SYNK_FACULTY_XLSX_NS, 'c') as $cell) {
                $column = preg_replace('/\d+$/', '', $cell->getAttribute('r'));
                $cell->setAttribute('r', $column . $nextRow);
                if ($cell->getAttribute('t') === 's') {
                    $value = $cell->getElementsByTagNameNS(SYNK_FACULTY_XLSX_NS, 'v')->item(0);
                    $string = $sharedStrings[(int)$value->textContent] ?? null;
                    while ($cell->firstChild) $cell->removeChild($cell->firstChild);
                    $cell->setAttribute('t', 'inlineStr');
                    $inline = $document->createElementNS(SYNK_FACULTY_XLSX_NS, 'is');
                    if ($string) foreach ($string->childNodes as $child) $inline->appendChild($document->importNode($child, true));
                    $cell->appendChild($inline);
                }
                $cells[$column . $nextRow] = $cell;
            }
            $data->appendChild($row);
        }
        foreach ($sourceMerges as $reference) {
            if (preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $reference, $match)
                && (int)$match[2] >= $first && (int)$match[4] <= $last) {
                $merge = $document->createElementNS(SYNK_FACULTY_XLSX_NS, 'mergeCell');
                $merge->setAttribute('ref', $match[1] . ((int)$match[2] + $offset) . ':' . $match[3] . ((int)$match[4] + $offset));
                $merges->appendChild($merge);
            }
        }
        return $start;
    };
    $append(1, 11);
    // The ACCESS address belongs to the supplied template; other campuses use their own saved name.
    $campusHeading = preg_match('/^ACCESS(?: Campus)?$/i', $group['campus'])
        ? 'ACCESS CAMPUS, EJC MONTILLA TACURONG CITY' : mb_strtoupper($group['campus']);
    synk_faculty_excel_value($cells['A2'], $campusHeading);
    synk_faculty_excel_value($cells['A3'], mb_strtoupper($group['college']));
    $termHeading = trim(($term['semester_label'] ?? '') . ' A.Y ' . ($term['ay_label'] ?? ''));
    synk_faculty_excel_value($cells['A6'], !empty($term['ay_label']) ? mb_strtoupper($termHeading) : mb_strtoupper($term['term_text'] ?? 'Current academic term'));

    $sections = [
        'Permanent' => ['heading' => [12, 12], 'blocks' => [13, 17], 'spacer' => [21, 24]],
        'Temporary' => ['heading' => [25, 28], 'blocks' => [29, 33], 'spacer' => [37, 38]],
        'Contract of Service' => ['heading' => [39, 42], 'blocks' => [43, 47], 'spacer' => null],
        'Part Time' => ['heading' => [51, 54], 'blocks' => [55, 59], 'spacer' => null],
    ];
    $byClassification = [];
    $classificationLabels = ['permanent' => 'Permanent', 'temporary' => 'Temporary', 'contract_of_service' => 'Contract of Service', 'part_time' => 'Part Time', 'job_order' => 'Job Order'];
    foreach ($group['records'] as $record) {
        $classification = trim((string)($record['profile']['employment_classification'] ?? ''));
        if ($classification === '') $classification = $classificationLabels[$record['employment_classification'] ?? ''] ?? 'Not recorded';
        $byClassification[$classification][] = $record;
        if (!isset($sections[$classification])) $sections[$classification] = ['heading' => [51, 54], 'blocks' => [55, 59], 'spacer' => null];
    }
    $genderCells = [];
    foreach ($sections as $classification => $section) {
        $headingRow = $append(...$section['heading']);
        $headingCell = $classification === 'Permanent' ? 'B' : 'A';
        synk_faculty_excel_value($cells[$headingCell . $headingRow], $classification === 'Contract of Service' ? 'COS FACULTY' : mb_strtoupper($classification));
        $faculty = $byClassification[$classification] ?? [];
        // Keep the template's two example slots, but clear all example values.
        for ($index = 0; $index < max(2, count($faculty)); $index++) {
            $sourceRow = $section['blocks'][$index === 0 ? 0 : 1];
            $firstRow = $append($sourceRow, $sourceRow + 3);
            for ($row = $firstRow; $row <= $firstRow + 3; $row++) {
                foreach (array_merge(range('A', 'K'), ['M', 'N', 'O']) as $column) synk_faculty_excel_value($cells[$column . $row], '');
            }
            if (!isset($faculty[$index])) continue;
            $record = $faculty[$index];
            $profile = $record['profile'] ?? [];
            $birth = synk_faculty_profile_date((string)($profile['date_of_birth'] ?? ''));
            if ($birth && ($birth > $today || $birth < new DateTimeImmutable('1900-01-01'))) $birth = null;
            $dateNumber = $birth ? (int)(new DateTimeImmutable('1899-12-30'))->diff($birth)->days : '';
            if ($birth && $birth < new DateTimeImmutable('1900-03-01')) $dateNumber--;
            $credentialKey = ($profile['_storage'] ?? '') === 'faculty' ? 'faculty:' . (int)$record['faculty_id'] : (int)($record['user_id'] ?? 0);
            $credentials = $eligibilities[$credentialKey] ?? [];
            $otherEligibility = trim((string)($profile['eligibility'] ?? ''));
            if ($otherEligibility !== '') $credentials[] = $otherEligibility;
            $service = trim((string)($profile['length_of_service'] ?? ''));
            if (!empty($profile['service_start_date'])) $service = synk_faculty_profile_service((string)$profile['service_start_date'], $today) ?: $service;
            $values = [
                'A' => $index + 1, 'B' => trim((string)($profile['full_name'] ?? '')) ?: (string)$record['name'],
                'C' => (string)($profile['gender'] ?? ''), 'D' => $dateNumber, 'E' => $birth ? $birth->diff($today)->y : '',
                'F' => (string)($profile['civil_status'] ?? ''), 'G' => (string)($profile['faculty_rank'] ?? ''),
                'H' => (string)($profile['salary_grade'] ?? ''), 'I' => $classification === 'Not recorded' ? '' : $classification,
                'J' => implode("\n", array_unique($credentials)), 'K' => $service,
            ];
            $mergedHeight = 0;
            foreach ($values as $column => $value) {
                $formula = $column === 'E' && $birth ? 'IF(D' . $firstRow . '="","",DATEDIF(D' . $firstRow . ',TODAY(),"Y"))' : null;
                synk_faculty_excel_value($cells[$column . $firstRow], $value, $formula);
                $style = (int)$cells[$column . $firstRow]->getAttribute('s');
                if (isset($wrapStyles[$style])) $cells[$column . $firstRow]->setAttribute('s', (string)$wrapStyles[$style]);
                if (is_string($value)) $mergedHeight = max($mergedHeight, synk_faculty_excel_text_height($value, $widths[ord($column) - 64]));
            }
            $genderCells[] = 'C' . $firstRow;
            $rowHeights = array_fill(0, 4, max(20.1, $mergedHeight / 4));
            foreach (['M' => 'bachelor', 'N' => 'master', 'O' => 'doctoral'] as $column => $level) {
                foreach (['degree', 'institution', 'address', 'specialization'] as $offset => $field) {
                    $value = ($profile[$level . '_status'] ?? '') === 'Not applicable' ? 'N/A' : (string)($profile[$level . '_' . $field] ?? '');
                    if ($field === 'degree' && $value !== '' && ($profile[$level . '_status'] ?? '') === 'Ongoing'
                        && !preg_match('/on[ -]?going/i', $value)) $value .= ' (ON-GOING)';
                    synk_faculty_excel_value($cells[$column . ($firstRow + $offset)], $value);
                    $style = (int)$cells[$column . ($firstRow + $offset)]->getAttribute('s');
                    if (isset($wrapStyles[$style])) $cells[$column . ($firstRow + $offset)]->setAttribute('s', (string)$wrapStyles[$style]);
                    $rowHeights[$offset] = max($rowHeights[$offset], synk_faculty_excel_text_height($value, $widths[ord($column) - 64]));
                }
            }
            for ($offset = 0; $offset < 4; $offset++) {
                $row = $cells['L' . ($firstRow + $offset)]->parentNode;
                $row->setAttribute('ht', (string)min(409.5, max((float)$row->getAttribute('ht'), $rowHeights[$offset])));
                $row->setAttribute('customHeight', '1');
            }
        }
        if ($section['spacer']) $append(...$section['spacer']);
    }
    $append(63, 74);
    $lastRow = $nextRow - 1;
    $merges->setAttribute('count', (string)$merges->childNodes->length);
    $xpath->query('/s:worksheet/s:dimension')->item(0)->setAttribute('ref', 'A1:AA' . $lastRow);
    $view = $xpath->query('/s:worksheet/s:sheetViews/s:sheetView')->item(0);
    $view->setAttribute('tabSelected', '0');
    // Freeze the table headers instead of the template's example faculty rows.
    $pane = $xpath->query('s:pane', $view)->item(0);
    $pane->setAttribute('ySplit', '11');
    $pane->setAttribute('topLeftCell', 'J12');
    foreach ($xpath->query('s:selection', $view) as $selection) {
        $active = ['topRight' => 'J1', 'bottomLeft' => 'A12', 'bottomRight' => 'J12'][$selection->getAttribute('pane')] ?? 'A1';
        $selection->setAttribute('activeCell', $active);
        $selection->setAttribute('sqref', $active);
    }
    $validation = $xpath->query('/s:worksheet/s:dataValidations')->item(0);
    if ($genderCells) {
        $rule = $xpath->query('s:dataValidation', $validation)->item(0);
        $rule->setAttribute('sqref', implode(' ', $genderCells));
        $xpath->query('s:formula1', $rule)->item(0)->nodeValue = '"Male,Female,Other,Prefer not to say"';
    } else {
        $validation->parentNode->removeChild($validation);
    }
    return ['xml' => $document->saveXML(), 'last_row' => $lastRow];
}

// Returns a private temporary file; caller owns its deletion after streaming.
function synk_faculty_profile_excel_file(array $records, array $directory, array $term, array $eligibilities = [], ?DateTimeImmutable $today = null): string
{
    if (!$records) throw new InvalidArgumentException('No professors match the selected filters.');
    if (!class_exists('ZipArchive')) throw new RuntimeException('Excel export requires the PHP ZIP extension.');
    $today = $today ?? new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    $path = tempnam(sys_get_temp_dir(), 'synk-faculty-');
    if ($path === false) throw new RuntimeException('The Excel export could not be created.');
    $archive = new ZipArchive();
    $opened = false;
    try {
        if (!copy(__DIR__ . '/templates/faculty-profile.xlsx', $path) || $archive->open($path) !== true) {
            throw new RuntimeException('The faculty profile template could not be opened.');
        }
        $opened = true;
        $templateXml = $archive->getFromName('xl/worksheets/sheet1.xml');
        $wrapStyles = synk_faculty_excel_wrap_styles($archive->getFromName('xl/styles.xml'), $templateXml);
        $archive->addFromString('xl/styles.xml', $wrapStyles['xml']);
        $templateSheetRels = $archive->getFromName('xl/worksheets/_rels/sheet1.xml.rels');
        $drawing = $archive->getFromName('xl/drawings/drawing1.xml');
        $drawingRels = $archive->getFromName('xl/drawings/_rels/drawing1.xml.rels');
        $printerSettings = $archive->getFromName('xl/printerSettings/printerSettings1.bin');
        $stringsDocument = synk_faculty_excel_xml($archive->getFromName('xl/sharedStrings.xml'));
        $sharedStrings = iterator_to_array($stringsDocument->getElementsByTagNameNS(SYNK_FACULTY_XLSX_NS, 'si'));
        $workbook = synk_faculty_excel_xml($archive->getFromName('xl/workbook.xml'));
        $workbookXpath = synk_faculty_excel_xpath($workbook);
        $sheets = $workbookXpath->query('/s:workbook/s:sheets')->item(0);
        while ($sheets->firstChild) $sheets->removeChild($sheets->firstChild);
        $definedNames = $workbook->createElementNS(SYNK_FACULTY_XLSX_NS, 'definedNames');
        $calc = $workbookXpath->query('/s:workbook/s:calcPr')->item(0);
        $calc->setAttribute('fullCalcOnLoad', '1');
        $workbook->documentElement->insertBefore($definedNames, $calc);
        $relationships = synk_faculty_excel_xml($archive->getFromName('xl/_rels/workbook.xml.rels'));
        foreach (iterator_to_array($relationships->documentElement->childNodes) as $relationship) {
            if ($relationship instanceof DOMElement && preg_match('~/(worksheet|sharedStrings)$~', $relationship->getAttribute('Type'))) $relationship->parentNode->removeChild($relationship);
        }
        $contentTypes = synk_faculty_excel_xml($archive->getFromName('[Content_Types].xml'));
        foreach (iterator_to_array($contentTypes->documentElement->childNodes) as $type) {
            if ($type instanceof DOMElement && preg_match('~^/xl/(worksheets/|drawings/|sharedStrings\.xml)~', $type->getAttribute('PartName'))) $type->parentNode->removeChild($type);
        }
        $groups = synk_faculty_excel_groups($records, $directory);
        foreach ($groups as $index => $group) {
            $number = $index + 1;
            $sheet = synk_faculty_excel_sheet($templateXml, $sharedStrings, $group, $term, $eligibilities, $today, $wrapStyles['mapping']);
            // Every worksheet owns its drawing and printer part; images remain shared.
            $sheetRels = str_replace(['../drawings/drawing1.xml', '../printerSettings/printerSettings1.bin'],
                ['../drawings/drawing' . $number . '.xml', '../printerSettings/printerSettings' . $number . '.bin'], $templateSheetRels);
            $parts = [
                'xl/worksheets/sheet' . $number . '.xml' => $sheet['xml'],
                'xl/worksheets/_rels/sheet' . $number . '.xml.rels' => $sheetRels,
                'xl/drawings/drawing' . $number . '.xml' => $drawing,
                'xl/drawings/_rels/drawing' . $number . '.xml.rels' => $drawingRels,
                'xl/printerSettings/printerSettings' . $number . '.bin' => $printerSettings,
            ];
            foreach ($parts as $name => $contents) if (!$archive->addFromString($name, $contents)) throw new RuntimeException('Excel content could not be written.');
            $sheetNode = $workbook->createElementNS(SYNK_FACULTY_XLSX_NS, 'sheet');
            $sheetNode->setAttribute('name', $group['sheet_name']);
            $sheetNode->setAttribute('sheetId', (string)$number);
            $sheetNode->setAttributeNS(SYNK_FACULTY_XLSX_REL_NS, 'r:id', 'rIdFacultySheet' . $number);
            $sheets->appendChild($sheetNode);
            foreach (['_xlnm.Print_Area' => "'" . str_replace("'", "''", $group['sheet_name']) . "'!\$A\$1:\$O\$" . $sheet['last_row'],
                '_xlnm.Print_Titles' => "'" . str_replace("'", "''", $group['sheet_name']) . "'!\$1:\$11"] as $name => $value) {
                $definedName = $workbook->createElementNS(SYNK_FACULTY_XLSX_NS, 'definedName');
                $definedName->setAttribute('name', $name);
                $definedName->setAttribute('localSheetId', (string)$index);
                $definedName->appendChild($workbook->createTextNode($value));
                $definedNames->appendChild($definedName);
            }
            $relationship = $relationships->createElementNS($relationships->documentElement->namespaceURI, 'Relationship');
            $relationship->setAttribute('Id', 'rIdFacultySheet' . $number);
            $relationship->setAttribute('Type', SYNK_FACULTY_XLSX_REL_NS . '/worksheet');
            $relationship->setAttribute('Target', 'worksheets/sheet' . $number . '.xml');
            $relationships->documentElement->appendChild($relationship);
            foreach (['/xl/worksheets/sheet' . $number . '.xml' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml',
                '/xl/drawings/drawing' . $number . '.xml' => 'application/vnd.openxmlformats-officedocument.drawing+xml'] as $part => $type) {
                $override = $contentTypes->createElementNS($contentTypes->documentElement->namespaceURI, 'Override');
                $override->setAttribute('PartName', $part);
                $override->setAttribute('ContentType', $type);
                $contentTypes->documentElement->appendChild($override);
            }
        }
        // Remove unused example strings from the exported package, not just visible cells.
        $archive->deleteName('xl/sharedStrings.xml');
        $app = synk_faculty_excel_xml($archive->getFromName('docProps/app.xml'));
        $app->getElementsByTagName('i4')->item(0)->nodeValue = (string)count($groups);
        $titles = $app->getElementsByTagName('TitlesOfParts')->item(0)->firstChild;
        while ($titles->firstChild) $titles->removeChild($titles->firstChild);
        $titles->setAttribute('size', (string)count($groups));
        foreach ($groups as $group) {
            $title = $app->createElementNS('http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes', 'vt:lpstr');
            $title->appendChild($app->createTextNode($group['sheet_name']));
            $titles->appendChild($title);
        }
        foreach (['xl/workbook.xml' => $workbook, 'xl/_rels/workbook.xml.rels' => $relationships,
            '[Content_Types].xml' => $contentTypes, 'docProps/app.xml' => $app] as $part => $document) {
            if (!$archive->addFromString($part, $document->saveXML())) throw new RuntimeException('Excel metadata could not be written.');
        }
        if (!$archive->close()) throw new RuntimeException('The Excel export could not be completed.');
        $opened = false;
        return $path;
    } catch (Throwable $exception) {
        if ($opened) $archive->close();
        if (is_file($path)) unlink($path);
        throw $exception;
    }
}
