<?php
require_once __DIR__.'/assessment_helper_lms.php';

function synk_material_ready_lms(mysqli $lms): bool
{
    return count(synk_assessment_query_lms($lms, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME IN ('tbl_lms_material_collections','tbl_lms_material_files')")) === 2;
}

function synk_material_limit_lms(): int
{
    $bytes = static function(string $value): int {
        $value = trim($value);
        $factor = ['g'=>1073741824,'m'=>1048576,'k'=>1024][strtolower(substr($value,-1))] ?? 1;
        return (int)((float)$value * $factor);
    };
    $limits = [10 * 1048576];
    foreach (['upload_max_filesize','post_max_size'] as $key) {
        $value = $bytes((string)ini_get($key));
        if ($value > 0) $limits[] = $key === 'post_max_size' ? max(1,$value-65536) : $value;
    }
    return min($limits);
}

function synk_material_list_lms(mysqli $lms, array $context): array
{
    // Metadata only: overview and list requests never load file contents.
    return synk_assessment_query_lms($lms, 'SELECT f.material_id,f.original_name,f.mime_type,f.size_bytes,f.uploaded_at
        FROM tbl_lms_material_files f JOIN tbl_lms_material_collections c ON c.collection_id=f.collection_id
        WHERE c.faculty_id=? AND c.offering_id=? AND c.ay_id=? AND c.semester=? ORDER BY f.slot',
        'iiii',synk_assessment_scope_params_lms($context));
}

function synk_material_id_lms($value): int
{
    if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,9}$/D',$value) || (float)$value > 2147483647) {
        throw new DomainException('Choose a valid learning material.',422);
    }
    return (int)$value;
}

function synk_material_validate_lms(array $file): array
{
    $error = $file['error'] ?? null;
    if (!is_int($error) || $error !== UPLOAD_ERR_OK) {
        if (in_array($error,[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true)) throw new DomainException('The file exceeds the upload size limit.',413);
        throw new DomainException('Choose a complete PDF or PowerPoint file and try again.',422);
    }
    $name = $file['name'] ?? null; $path = $file['tmp_name'] ?? null;
    if (!is_string($name) || preg_match('//u',$name) !== 1 || preg_match('/[\x00-\x1f\x7f\/\\\\]/u',$name)
        || $name !== trim($name) || $name === '' || strlen($name)>200 || !is_string($path) || !is_file($path)) {
        throw new DomainException('Use a file name of at most 200 bytes without special path characters.',422);
    }
    $extension = strtolower(pathinfo($name,PATHINFO_EXTENSION));
    if (!in_array($extension,['pdf','ppt','pptx'],true)) throw new DomainException('Only PDF and PowerPoint files (.pdf, .ppt, .pptx) are allowed.',422);
    $size = filesize($path);
    if (!$size) throw new DomainException('Empty files cannot be uploaded.',422);
    if ($size>synk_material_limit_lms()) throw new DomainException('The file exceeds the upload size limit.',413);
    $data = file_get_contents($path);
    if ($data === false) throw new RuntimeException('Unable to read the uploaded material.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $valid = false;
    if ($extension === 'pdf') {
        $valid = $mime === 'application/pdf' && strncmp($data,'%PDF-',5) === 0 && strpos(substr($data,-2048),'%%EOF') !== false;
        $mime = 'application/pdf';
    } elseif ($extension === 'ppt') {
        // Legacy PowerPoint uses the compound binary format and its named document stream.
        $valid = substr($data,0,8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"
            && strpos($data,implode("\0",str_split('PowerPoint Document'))."\0") !== false;
        $mime = 'application/vnd.ms-powerpoint';
    } elseif (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            try {
                $types = $zip->statName('[Content_Types].xml');
                $presentation = $zip->statName('ppt/presentation.xml');
                if ($types && $presentation && $types['size']<=1048576 && $presentation['size']>0
                    && $presentation['size']<=4194304 && !($presentation['encryption_method'] ?? 0)
                    && $zip->locateName('ppt/vbaProject.bin',ZipArchive::FL_NOCASE) === false) {
                    $typesXml = $zip->getFromName('[Content_Types].xml');
                    $presentationXml = $zip->getFromName('ppt/presentation.xml');
                    if (is_string($typesXml) && is_string($presentationXml) && stripos($typesXml,'<!DOCTYPE') === false
                        && stripos($presentationXml,'<!DOCTYPE') === false && stripos($typesXml,'macroEnabled') === false) {
                        $previousErrors = libxml_use_internal_errors(true);
                        try {
                            $typeDocument = new DOMDocument(); $presentationDocument = new DOMDocument();
                            if ($typeDocument->loadXML($typesXml,LIBXML_NONET) && $presentationDocument->loadXML($presentationXml,LIBXML_NONET)) {
                                $xpath = new DOMXPath($typeDocument);
                                $xpath->registerNamespace('ct','http://schemas.openxmlformats.org/package/2006/content-types');
                                $main = $xpath->query('/ct:Types/ct:Override[@PartName="/ppt/presentation.xml" and @ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"]');
                                $root = $presentationDocument->documentElement;
                                $valid = $main->length === 1 && $root && $root->localName === 'presentation'
                                    && in_array($root->namespaceURI,['http://schemas.openxmlformats.org/presentationml/2006/main','http://purl.oclc.org/ooxml/presentationml/main'],true);
                            }
                        } finally { libxml_clear_errors(); libxml_use_internal_errors($previousErrors); }
                    }
                }
            } finally { $zip->close(); }
        }
        $mime = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    }
    if (!$valid) throw new DomainException('The file contents do not match a valid PDF or PowerPoint file. Export the document again and retry.',422);
    return ['name'=>$name,'mime'=>$mime,'size'=>$size,'data'=>$data];
}

// Caller owns the transaction. Lock the collection before allocating either of its two slots.
function synk_material_upload_lms(mysqli $lms, array $context, array $files, int $actor): array
{
    if (!$files || count($files)>2) throw new DomainException('Each subject allows a maximum of two learning material files.',422);
    $validated = array_map('synk_material_validate_lms',$files);
    $scope = synk_assessment_scope_params_lms($context);
    synk_assessment_query_lms($lms, 'INSERT INTO tbl_lms_material_collections (faculty_id,offering_id,ay_id,semester)
        VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE collection_id=collection_id','iiii',$scope);
    $collection = synk_assessment_query_lms($lms, 'SELECT collection_id FROM tbl_lms_material_collections
        WHERE faculty_id=? AND offering_id=? AND ay_id=? AND semester=? FOR UPDATE','iiii',$scope);
    $collectionId = (int)$collection[0]['collection_id'];
    $used = array_column(synk_assessment_query_lms($lms,'SELECT slot FROM tbl_lms_material_files WHERE collection_id=?','i',[$collectionId]),'slot');
    $available = array_values(array_diff(['1','2'],$used));
    if (count($validated)>count($available)) throw new DomainException('This subject allows only two files. Remove an existing material before uploading another.',409);
    $now = (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    foreach ($validated as $index=>$file) {
        synk_assessment_query_lms($lms, 'INSERT INTO tbl_lms_material_files
            (collection_id,slot,original_name,mime_type,size_bytes,file_data,uploaded_by_user_id,uploaded_at) VALUES (?,?,?,?,?,?,?,?)',
            'isssisis',[$collectionId,$available[$index],$file['name'],$file['mime'],$file['size'],$file['data'],$actor,$now]);
    }
    return synk_material_list_lms($lms,$context);
}

function synk_material_file_lms(mysqli $lms, array $context, int $id, bool $contents = false): array
{
    $rows = synk_assessment_query_lms($lms,'SELECT f.material_id,f.original_name,f.mime_type,f.size_bytes'.($contents?',f.file_data':'').'
        FROM tbl_lms_material_files f JOIN tbl_lms_material_collections c ON c.collection_id=f.collection_id
        WHERE f.material_id=? AND c.faculty_id=? AND c.offering_id=? AND c.ay_id=? AND c.semester=?',
        'iiiii',array_merge([$id],synk_assessment_scope_params_lms($context)));
    if (!$rows) throw new DomainException('This learning material is unavailable in this class.',404);
    return $rows[0];
}

function synk_material_remove_lms(mysqli $lms, array $context, int $id): array
{
    $scope = synk_assessment_scope_params_lms($context);
    $collections = synk_assessment_query_lms($lms,'SELECT collection_id FROM tbl_lms_material_collections
        WHERE faculty_id=? AND offering_id=? AND ay_id=? AND semester=? FOR UPDATE','iiii',$scope);
    if (!$collections) throw new DomainException('This learning material is unavailable in this class.',404);
    synk_material_file_lms($lms,$context,$id);
    synk_assessment_query_lms($lms,'DELETE FROM tbl_lms_material_files WHERE material_id=? AND collection_id=?',
        'ii',[$id,(int)$collections[0]['collection_id']]);
    return synk_material_list_lms($lms,$context);
}
