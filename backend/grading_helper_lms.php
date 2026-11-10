<?php
require_once __DIR__ . '/schema_helper.php';

function synk_grading_query_lms(mysqli $lms, string $sql, string $types = '', array $params = []): array
{
    $stmt = $lms->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare the grading request.');
    try {
        if ($types !== '' && !synk_bind_dynamic_params($stmt, $types, $params)) throw new RuntimeException('Unable to bind the grading request.');
        if (!$stmt->execute()) throw new mysqli_sql_exception('Unable to complete the grading request.', $stmt->errno);
        $result = $stmt->get_result();
        return $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    } finally { $stmt->close(); }
}

function synk_grading_ready_lms(mysqli $lms): bool
{
    foreach (['tbl_lms_grading_schemes','tbl_lms_grading_components','tbl_lms_grading_logs'] as $table) {
        if (!synk_grading_query_lms($lms, 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', 's', [$table])) return false;
    }
    return true;
}

function synk_grading_period_lms($value): string
{
    if (!is_string($value) || !in_array($value, ['midterm','final'], true)) throw new DomainException('Choose Midterm or Final.', 422);
    return $value;
}

function synk_grading_integer_lms($value): int
{
    if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D', (string)$value) || (float)$value > 2147483647) {
        throw new DomainException('Reload the grading system before continuing.', 422);
    }
    return (int)$value;
}

function synk_grading_text_lms($value, string $label, int $limit, bool $required = false): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) throw new DomainException('Enter valid text for ' . $label . '.', 422);
    $text = trim(str_replace(["\r\n", "\r"], "\n", $value));
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : preg_match_all('/./us', $text);
    if ($length > $limit || ($required && $text === '')) throw new DomainException($label . ($required ? ' is required and' : '') . ' must be at most ' . $limit . ' characters.', 422);
    return $text;
}

function synk_grading_weight_lms($value): int
{
    if ((!is_string($value) && !is_int($value) && !is_float($value)) || !preg_match('/^[0-9]{1,3}(?:\.[0-9]{1,2})?$/D', (string)$value)) {
        throw new DomainException('Use a weight from 0.01% to 100%, with at most two decimal places.', 422);
    }
    $parts = explode('.', (string)$value);
    $bps = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
    if ($bps < 1 || $bps > 10000) throw new DomainException('Each component weight must be greater than 0% and at most 100%.', 422);
    return $bps;
}

function synk_grading_validate_lms(array $input): array
{
    $period = synk_grading_period_lms($input['grading_period'] ?? null);
    $version = synk_grading_integer_lms($input['version'] ?? null);
    $items = $input['components'] ?? null;
    if (!is_array($items) || !$items || count($items) > 30 || array_keys($items) !== range(0, count($items)-1)) {
        throw new DomainException('Add between 1 and 30 grading components for this period.', 422);
    }
    $components = []; $names = []; $ids = []; $total = 0;
    foreach ($items as $item) {
        if (!is_array($item)) throw new DomainException('Enter valid grading components.', 422);
        $id = synk_grading_integer_lms($item['component_id'] ?? 0);
        $name = synk_grading_text_lms($item['name'] ?? '', 'Component name', 160, true);
        $key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if (in_array($key, $names, true)) throw new DomainException('Give each component a different name within this period.', 422);
        if ($id && in_array($id, $ids, true)) throw new DomainException('A component can appear only once in a period.', 422);
        $names[] = $key; $ids[] = $id;
        $weight = synk_grading_weight_lms($item['weight'] ?? null); $total += $weight;
        $components[] = ['component_id'=>$id,'name'=>$name,'description'=>synk_grading_text_lms($item['description'] ?? '', 'Description', 2000),'weight_bps'=>$weight];
    }
    if ($total !== 10000) throw new DomainException('The component weights for ' . ucfirst($period) . ' must total exactly 100%.', 422);
    return ['grading_period'=>$period,'version'=>$version,'components'=>$components];
}

function synk_grading_scope_lms(array $context): array
{
    return [(int)$context['portal']['faculty_id'],(int)$context['class']['offering_id'],(int)$context['class']['ay_id'],(int)$context['class']['semester']];
}

function synk_grading_detail_lms(mysqli $lms, array $context, string $period, bool $lock = false): array
{
    synk_grading_period_lms($period);
    $rows = synk_grading_query_lms($lms, 'SELECT scheme_id,version,updated_at FROM tbl_lms_grading_schemes
        WHERE faculty_id=? AND offering_id=? AND ay_id=? AND semester=? AND grading_period=?' . ($lock ? ' FOR UPDATE' : ''),
        'iiiis', array_merge(synk_grading_scope_lms($context), [$period]));
    if (!$rows) return ['scheme_id'=>0,'grading_period'=>$period,'version'=>0,'updated_at'=>null,'components'=>[],'total_weight'=>0];
    $row = $rows[0];
    $components = synk_grading_query_lms($lms, "SELECT component_id,name,description,weight_bps FROM tbl_lms_grading_components
        WHERE scheme_id=? AND status='active' ORDER BY sort_order,component_id", 'i', [(int)$row['scheme_id']]);
    $items = []; $total = 0;
    foreach ($components as $component) {
        $weight = (int)$component['weight_bps']; $total += $weight;
        $items[] = ['component_id'=>(int)$component['component_id'],'name'=>$component['name'],'description'=>$component['description'],'weight'=>$weight / 100];
    }
    return ['scheme_id'=>(int)$row['scheme_id'],'grading_period'=>$period,'version'=>(int)$row['version'],
        'updated_at'=>$row['updated_at'],'components'=>$items,'total_weight'=>$total / 100];
}

// The API owns the transaction. Stable component IDs and full revision snapshots
// allow the future gradebook to reference components without losing their history.
function synk_grading_save_lms(mysqli $lms, array $context, array $input, int $actor): array
{
    $data = synk_grading_validate_lms($input);
    $before = synk_grading_detail_lms($lms, $context, $data['grading_period'], true);
    if ($data['version'] !== $before['version']) throw new DomainException('This grading system changed in another tab. Reload this period before saving.', 409);
    $existing = array_column($before['components'], null, 'component_id');
    foreach ($data['components'] as $item) {
        if ($item['component_id'] && !isset($existing[$item['component_id']])) throw new DomainException('A component is no longer available in this grading period. Reload this period.', 409);
    }
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    $id = $before['scheme_id']; $version = $before['version'] + 1;
    if (!$id) {
        try {
            synk_grading_query_lms($lms, 'INSERT INTO tbl_lms_grading_schemes
                (faculty_id,offering_id,ay_id,semester,grading_period,version,created_by_user_id,updated_by_user_id,created_at,updated_at)
                VALUES (?,?,?,?,?,1,?,?,?,?)', 'iiiisiiss', array_merge(synk_grading_scope_lms($context), [$data['grading_period'],$actor,$actor,$now,$now]));
        } catch (mysqli_sql_exception $error) {
            if ((int)$error->getCode() === 1062) throw new DomainException('This grading system changed in another tab. Reload this period before saving.', 409);
            throw $error;
        }
        $id = (int)$lms->insert_id;
    } else {
        synk_grading_query_lms($lms, 'UPDATE tbl_lms_grading_schemes SET version=?,updated_by_user_id=?,updated_at=? WHERE scheme_id=?', 'iisi', [$version,$actor,$now,$id]);
    }
    $retained = [];
    foreach ($data['components'] as $index=>$item) {
        $componentId = $item['component_id'];
        if ($componentId) {
            synk_grading_query_lms($lms, 'UPDATE tbl_lms_grading_components SET name=?,description=?,weight_bps=?,sort_order=?,updated_at=? WHERE component_id=? AND scheme_id=?',
                'ssiisii', [$item['name'],$item['description'],$item['weight_bps'],$index+1,$now,$componentId,$id]);
            $retained[] = $componentId;
        } else {
            synk_grading_query_lms($lms, 'INSERT INTO tbl_lms_grading_components (scheme_id,name,description,weight_bps,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',
                'issiiss', [$id,$item['name'],$item['description'],$item['weight_bps'],$index+1,$now,$now]);
        }
    }
    foreach ($existing as $componentId=>$item) {
        if (!in_array((int)$componentId, $retained, true)) synk_grading_query_lms($lms,
            "UPDATE tbl_lms_grading_components SET status='archived',updated_at=? WHERE component_id=? AND scheme_id=?", 'sii', [$now,(int)$componentId,$id]);
    }
    $after = synk_grading_detail_lms($lms, $context, $data['grading_period']);
    synk_grading_query_lms($lms, 'INSERT INTO tbl_lms_grading_logs (scheme_id,actor_user_id,version,details_json,recorded_at) VALUES (?,?,?,?,?)',
        'iiiss', [$id,$actor,$version,json_encode(['before'=>$before,'after'=>$after], JSON_UNESCAPED_UNICODE),$now]);
    return $after;
}
