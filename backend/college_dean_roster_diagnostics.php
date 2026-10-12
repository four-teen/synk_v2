<?php

class SynkDeanRosterValidationException extends DomainException
{
    public $issues;
    public function __construct(array $issues)
    {
        $this->issues = $issues;
        $first = $issues[0];
        $where = !empty($first['row']) ? 'CSV row ' . $first['row'] . ', ' : '';
        parent::__construct($where . $first['field'] . ': ' . $first['reason']
            . (count($issues) > 1 ? ' (' . count($issues) . ' issues found.)' : ''));
    }
}

function synk_dean_roster_issue($row, string $column, string $field, string $found, string $expected, string $reason, string $fix): array
{
    // Only CSV values and scoped comparison values belong in client diagnostics.
    return ['row'=>$row, 'column'=>$column, 'field'=>$field, 'found'=>$found,
        'expected'=>$expected, 'reason'=>$reason, 'fix'=>$fix];
}

function synk_dean_roster_repair_csv_text(string $text): string
{
    // Reverse common UTF-8 bytes that were incorrectly decoded as Windows-1252.
    // Correct Unicode stays intact; literal ? and replacement characters cannot be recovered.
    static $map = null;
    if ($map === null) {
        $map = [];
        if (function_exists('iconv')) {
            foreach (array_merge(range(0xA0,0x17F), [0x2013,0x2014,0x2018,0x2019,0x201C,0x201D,0xFEFF]) as $code) {
                $character = html_entity_decode('&#' . $code . ';', ENT_QUOTES, 'UTF-8');
                $broken = @iconv('Windows-1252','UTF-8',$character);
                if (is_string($broken) && $broken !== $character) $map[$broken] = $character;
            }
        }
    }
    for ($pass=0; $pass<2; $pass++) {
        $fixed = strtr($text, $map);
        if ($fixed === $text) break;
        $text = $fixed;
    }
    return synk_student_identity_utf8($text);
}

function synk_dean_roster_damaged_name(string $name): bool
{
    return strpos($name, '?') !== false || strpos($name, "\u{FFFD}") !== false;
}

function synk_dean_roster_faculty_key(string $name, bool $stripCredentials = true): string
{
    // Credentials after a comma are metadata, not part of the faculty identity.
    $credentials = ['MAT','MA','MS','MSC','MST','MIT','MBA','MED','MPA','MPH','MM','MSIT','MSCS','MAED',
        'MSED','PHD','EDD','DPA','DBA','DSC','CPA','LPT','RN','RGC','RMT','PTRP'];
    $parts = explode(',', $name);
    while ($stripCredentials && count($parts) > 1) {
        $last = trim((string)end($parts));
        $suffix = strtoupper(preg_replace('/[.\s]+/u','',$last));
        if (!in_array($suffix,$credentials,true)) break;
        array_pop($parts);
    }
    $key = synk_dean_roster_key(implode(',', $parts));
    // Old faculty headers use PENA for PEÑA. Stored names and student identity
    // comparisons continue to preserve their original accented characters.
    return strtr($key, ['Ñ'=>'N','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U']);
}
