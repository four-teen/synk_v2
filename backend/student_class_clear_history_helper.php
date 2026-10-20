<?php
function synk_class_clear_ready(mysqli $conn): bool
{
    return synk_table_exists($conn,'tbl_student_class_clear_history');
}
