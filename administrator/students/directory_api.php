<?php
require_once __DIR__ . '/bootstrap.php';
require_once '../../backend/academic_term_helper.php';

function student_management_directory_api_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

if ($studentManagementPageError !== '') {
    student_management_directory_api_response([
        'status' => 'error',
        'message' => $studentManagementPageError,
    ], 500);
}

try {
    $action = (string)($_GET['action'] ?? '');

    if ($action === 'list') {
        $isDataTableRequest = isset($_GET['draw']);
        $currentTerm = synk_fetch_current_academic_term($conn);
        $defaultCountAyId = max(0, (int)($currentTerm['ay_id'] ?? 0));
        $filters = [
            'search' => trim((string)($_GET['search'] ?? '')),
            'year_level' => max(0, (int)($_GET['year_level'] ?? 0)),
            'program_id' => max(0, (int)($_GET['program_id'] ?? 0)),
            'ay_id' => max(0, (int)($_GET['ay_id'] ?? 0)),
            'semester' => max(0, (int)($_GET['semester'] ?? 0)),
            'count_ay_id' => max(
                0,
                (int)(
                    $_GET['count_ay_id']
                    ?? ($_GET['ay_id'] ?? $defaultCountAyId)
                )
            ),
            'count_semester' => max(0, (int)($_GET['semester'] ?? 0)),
        ];
        $sort = [];

        if ($isDataTableRequest) {
            $draw = max(0, (int)($_GET['draw'] ?? 0));
            $offset = max(0, (int)($_GET['start'] ?? 0));
            $limit = (int)($_GET['length'] ?? 25);
            if ($limit <= 0) {
                $limit = 25;
            }
            $limit = min(250, $limit);

            $orderColumnIndex = max(0, (int)($_GET['order'][0]['column'] ?? 0));
            $columns = $_GET['columns'] ?? [];
            $columnConfig = (is_array($columns) && isset($columns[$orderColumnIndex]) && is_array($columns[$orderColumnIndex]))
                ? $columns[$orderColumnIndex]
                : [];

            $sort = [
                'column' => (string)($columnConfig['name'] ?? $columnConfig['data'] ?? ''),
                'direction' => (string)($_GET['order'][0]['dir'] ?? 'asc'),
            ];

            $recordsTotal = synk_student_management_count_records($conn, []);
            $recordsFiltered = synk_student_management_count_records($conn, $filters);
            $records = synk_student_management_fetch_records($conn, $filters, $limit, $offset, $sort);
        } else {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = (int)($_GET['limit'] ?? 40);
            if ($limit <= 0) {
                $limit = 40;
            }
            $limit = min(5000, $limit);
            $offset = ($page - 1) * $limit;
            $recordsFiltered = synk_student_management_count_records($conn, $filters);
            $recordsTotal = $recordsFiltered;
            $records = synk_student_management_fetch_records($conn, $filters, $limit, $offset, $sort);
        }

        $items = array_map(static function ($row) {
            return [
                'student_id' => (int)($row['student_id'] ?? 0),
                'student_number' => (string)($row['student_number'] ?? ''),
                'last_name' => (string)($row['last_name'] ?? ''),
                'first_name' => (string)($row['first_name'] ?? ''),
                'middle_name' => (string)($row['middle_name'] ?? ''),
                'suffix_name' => (string)($row['suffix_name'] ?? ''),
                'email_address' => (string)($row['email_address'] ?? ''),
                'subject_count' => (int)($row['subject_count'] ?? 0),
            ];
        }, $records);

        if ($isDataTableRequest) {
            student_management_directory_api_response([
                'draw' => $draw,
                'recordsTotal' => $recordsTotal,
                'recordsFiltered' => $recordsFiltered,
                'data' => $items,
            ]);
        }

        student_management_directory_api_response([
            'status' => 'success',
            'items' => $items,
            'total' => $recordsFiltered,
            'has_more' => ($offset + count($items)) < $recordsFiltered,
        ]);
    }

    if ($action === 'get') {
        $studentId = (int)($_GET['student_id'] ?? 0);
        $record = synk_student_management_fetch_record_by_id($conn, $studentId);
        if (!$record) {
            throw new RuntimeException('Student record not found.');
        }

        student_management_directory_api_response([
            'status' => 'success',
            'record' => $record,
        ]);
    }

    if ($action === 'save') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new RuntimeException('Invalid save request.');
        }

        $studentId = (int)($_POST['student_id'] ?? 0);
        $payload = [
            'ay_id' => (int)($_POST['ay_id'] ?? 0),
            'semester' => (int)($_POST['semester'] ?? 0),
            'year_level' => (int)($_POST['year_level'] ?? 0),
            'student_number' => (string)($_POST['student_number'] ?? ''),
            'last_name' => (string)($_POST['last_name'] ?? ''),
            'first_name' => (string)($_POST['first_name'] ?? ''),
            'middle_name' => (string)($_POST['middle_name'] ?? ''),
            'suffix_name' => (string)($_POST['suffix_name'] ?? ''),
            'email_address' => (string)($_POST['email_address'] ?? ''),
            'program_id' => (int)($_POST['program_id'] ?? 0),
        ];

        if ($studentId > 0) {
            synk_student_management_update_record($conn, $studentId, $payload);
            $message = 'Student record updated.';
        } else {
            $studentId = synk_student_management_create_record(
                $conn,
                $payload,
                (int)($_SESSION['user_id'] ?? 0)
            );
            $message = 'Student record added.';
        }

        $record = synk_student_management_fetch_record_by_id($conn, $studentId);

        student_management_directory_api_response([
            'status' => 'success',
            'message' => $message,
            'record' => $record,
        ]);
    }

    if ($action === 'delete') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new RuntimeException('Invalid delete request.');
        }

        $studentId = (int)($_POST['student_id'] ?? 0);
        synk_student_management_delete_record($conn, $studentId);

        student_management_directory_api_response([
            'status' => 'success',
            'message' => 'Student record deleted.',
        ]);
    }

    throw new RuntimeException('Unsupported student management action.');
} catch (Throwable $e) {
    student_management_directory_api_response([
        'status' => 'error',
        'message' => $e->getMessage(),
    ], 400);
}
