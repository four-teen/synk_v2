<?php
require_once __DIR__.'/db_lms.php';
require_once __DIR__.'/professor_class_helper_lms.php';
require_once __DIR__.'/learning_material_helper_lms.php';

function synk_material_context_lms(mysqli $core, array $input): array
{
    if (!is_array($input['context'] ?? null) || ($input['context']['view'] ?? '') !== 'materials') {
        throw new DomainException('Open Learning materials from your class workspace.',403);
    }
    return synk_professor_class_context_lms($core,$input['context']);
}

function synk_material_request_lms(mysqli $core, array $input, array $uploads): array
{
    if (empty($_SESSION['lms_material_csrf']) || !is_string($input['csrf_token'] ?? null)
        || !hash_equals($_SESSION['lms_material_csrf'],$input['csrf_token'])) throw new DomainException('Reload Learning materials before continuing.',403);
    if (array_diff(array_keys($input),['csrf_token','context','action','material_id'])) throw new DomainException('Unsupported learning material fields.',400);
    $action = $input['action'] ?? null;
    if (!is_string($action) || !in_array($action,['list','upload','remove'],true)) throw new DomainException('Choose a valid learning material action.',400);
    if (($action !== 'remove' && isset($input['material_id'])) || ($action !== 'upload' && $uploads)) throw new DomainException('Unexpected learning material fields.',400);
    $context = synk_material_context_lms($core,$input);
    $files = [];
    if ($action === 'upload') {
        $upload = $uploads['materials'] ?? null;
        if (array_diff(array_keys($uploads),['materials']) || !is_array($upload) || !is_array($upload['name'] ?? null)
            || count($upload['name'])<1 || count($upload['name'])>2) throw new DomainException('Choose one or two PDF or PowerPoint files.',422);
        foreach (['tmp_name','error','size'] as $field) {
            if (!is_array($upload[$field] ?? null) || array_keys($upload[$field]) !== array_keys($upload['name'])) throw new DomainException('Choose valid uploaded files.',422);
        }
        foreach ($upload['name'] as $index=>$name) {
            $file = ['name'=>$name];
            foreach (['tmp_name','error','size'] as $field) $file[$field] = $upload[$field][$index] ?? null;
            if (($file['error'] ?? null) === UPLOAD_ERR_OK && (!is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name']))) {
                throw new DomainException('Choose a valid uploaded file.',422);
            }
            $files[] = $file;
        }
    }
    $id = $action === 'remove' ? synk_material_id_lms($input['material_id'] ?? null) : 0;
    $lms = synk_database_connection_lms($core);
    if (!synk_material_ready_lms($lms)) throw new DomainException('Learning materials are temporarily unavailable. Please contact your administrator.',503);
    if ($action === 'list') return ['materials'=>synk_material_list_lms($lms,$context)];
    if (!$lms->begin_transaction()) throw new RuntimeException('Unable to begin the learning material update.');
    try {
        $materials = $action === 'upload' ? synk_material_upload_lms($lms,$context,$files,(int)$_SESSION['user_id']) : synk_material_remove_lms($lms,$context,$id);
        if (!$lms->commit()) throw new RuntimeException('Unable to save learning materials.');
        return ['materials'=>$materials];
    } catch (Throwable $error) { $lms->rollback(); throw $error; }
}
