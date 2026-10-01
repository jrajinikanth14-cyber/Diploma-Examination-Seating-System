<?php
/* =========================================================
   EXAMINATIONS MANAGEMENT MODULE
   File: admin/examinations.php

   IMPORT FORMAT:
   Semester | Date | Time | Subject Code | Subject Name

   Example:
   3SEM | 21-07-2026 | 10:00 AM To 11:00 AM | SC-301 | Applied Engineering Mathematics
   3SEM | 21-07-2026 | 02:00 PM To 03:00 PM | EC-302 | Digital Electronics

   PCode and Remark columns, if present in the Excel file, are ignored.
   ========================================================= */

session_start();

require_once "../config/db_connect.php";

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (!isset($_SESSION["admin"])) {
    header("Location: ../auth/login.php");
    exit();
}

/* =========================================================
   PHPSPREADSHEET
========================================================= */

require_once "../vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/* =========================================================
   HELPERS
========================================================= */

function clean($value): string
{
    return htmlspecialchars(
        (string)($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

function setFlash(string $message, string $type = "success"): void
{
    $_SESSION["flash_message"] = $message;
    $_SESSION["flash_message_type"] = $type;
}

function redirectToExaminations(
    int $departmentId = 0,
    int $semesterId = 0,
    string $search = ""
): void {
    $url = "examinations.php";
    $params = [];

    if ($departmentId > 0) {
        $params["department"] = $departmentId;
    }

    if ($semesterId > 0) {
        $params["semester"] = $semesterId;
    }

    if ($search !== "") {
        $params["search"] = $search;
    }

    if ($params) {
        $url .= "?" . http_build_query($params);
    }

    header("Location: " . $url);
    exit();
}

function createNotification(
    mysqli $conn,
    string $title,
    string $message,
    string $type = "exam"
): bool {
    $notificationMap = [
        "exam_added" => [
            "icon" => "fa-plus-circle",
            "color" => "success"
        ],
        "exam_updated" => [
            "icon" => "fa-edit",
            "color" => "primary"
        ],
        "exam_deleted" => [
            "icon" => "fa-trash-alt",
            "color" => "danger"
        ],
        "exam_imported" => [
            "icon" => "fa-file-import",
            "color" => "success"
        ],
        "exam_exported" => [
            "icon" => "fa-file-export",
            "color" => "primary"
        ]
    ];

    $icon = $notificationMap[$type]["icon"] ?? "fa-calendar-days";
    $color = $notificationMap[$type]["color"] ?? "primary";

    $stmt = $conn->prepare(
        "INSERT INTO notifications
        (title, message, icon, color, is_read, type)
        VALUES (?, ?, ?, ?, 0, ?)"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "sssss",
        $title,
        $message,
        $icon,
        $color,
        $type
    );

    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function getYearLabel(int $yearNumber): string
{
    return [
        1 => "1st Year",
        2 => "2nd Year",
        3 => "3rd Year",
        4 => "4th Year"
    ][$yearNumber] ?? ($yearNumber . "th Year");
}

/* =========================================================
   TIME HELPERS
========================================================= */

function normalizeExamTime($value): string
{
    if ($value === null || $value === "") {
        return "";
    }

    if (is_numeric($value)) {
        $number = (float)$value;

        if ($number >= 0 && $number < 1) {
            $seconds = (int)round($number * 86400);
            if ($seconds >= 86400) {
                $seconds = 86399;
            }

            return gmdate("H:i:s", $seconds);
        }
    }

    $value = trim((string)$value);

    if ($value === "") {
        return "";
    }

    $formats = [
        "H:i:s",
        "H:i",
        "h:i A",
        "h:i a",
        "g:i A",
        "g:i a",
        "h A",
        "h a",
        "g A",
        "g a"
    ];

    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat("!" . $format, $value);

        if ($dt instanceof DateTime) {
            return $dt->format("H:i:s");
        }
    }

    $timestamp = strtotime($value);

    return $timestamp !== false
        ? date("H:i:s", $timestamp)
        : "";
}

function timeToMinutes($value): int
{
    $normalized = normalizeExamTime($value);

    if ($normalized === "") {
        return -1;
    }

    $parts = explode(":", $normalized);

    if (count($parts) < 2) {
        return -1;
    }

    return ((int)$parts[0] * 60) + (int)$parts[1];
}

function calculateDurationMinutes(
    string $from,
    string $to
): int {
    $fromMinutes = timeToMinutes($from);
    $toMinutes = timeToMinutes($to);

    if ($fromMinutes < 0 || $toMinutes < 0) {
        return 0;
    }

    if ($toMinutes <= $fromMinutes) {
        return 0;
    }

    return $toMinutes - $fromMinutes;
}

function formatDuration($minutes): string
{
    $minutes = (int)$minutes;

    if ($minutes <= 0) {
        return "—";
    }

    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;

    if ($hours > 0 && $mins > 0) {
        return $hours . " Hr " . $mins . " Min";
    }

    if ($hours > 0) {
        return $hours . ($hours === 1 ? " Hour" : " Hours");
    }

    return $minutes . " Minutes";
}

function formatTimeRange($from, $to): string
{
    $fromNormalized = normalizeExamTime($from);
    $toNormalized = normalizeExamTime($to);

    if ($fromNormalized === "") {
        return "—";
    }

    $fromText = date("h:i A", strtotime($fromNormalized));

    if ($toNormalized === "") {
        return $fromText;
    }

    return $fromText . " To " . date("h:i A", strtotime($toNormalized));
}

/*
 * Reads an Excel time cell.
 * Supports:
 * 10:00 AM To 11:00 AM
 * 10:00 AM - 11:00 AM
 * 10:00 AM – 11:00 AM
 * 10:00 AM to 11:00 AM
 * 10:00 AM
 */
function parseImportedTimeRange($value): array
{
    if ($value === null || $value === "") {
        return ["", ""];
    }

    /*
     * Excel can store a single time as a fraction.
     * A single time is deliberately NOT accepted as a complete
     * timetable row because this import requires From + To.
     */
    if (is_numeric($value)) {
        $single = normalizeExamTime($value);
        return [$single, ""];
    }

    $text = trim((string)$value);

    if ($text === "") {
        return ["", ""];
    }

    $text = preg_replace('/\s+/', ' ', $text);

    $parts = preg_split(
        '/\s+(?:TO|to|To|-|–|—)\s+/u',
        $text,
        2
    );

    if (count($parts) === 2) {
        $from = normalizeExamTime(trim($parts[0]));
        $to = normalizeExamTime(trim($parts[1]));

        return [$from, $to];
    }

    /*
     * Also support compact separators where spacing is inconsistent.
     */
    $parts = preg_split(
        '/\s*(?:\-|–|—)\s*/u',
        $text,
        2
    );

    if (count($parts) === 2) {
        $from = normalizeExamTime(trim($parts[0]));
        $to = normalizeExamTime(trim($parts[1]));

        return [$from, $to];
    }

    return [normalizeExamTime($text), ""];
}

/* =========================================================
   DATE HELPERS
========================================================= */

function normalizeExamDate($value): string
{
    if ($value === null || $value === "") {
        return "";
    }

    if (is_numeric($value)) {
        try {
            return ExcelDate::excelToDateTimeObject(
                (float)$value
            )->format("Y-m-d");
        } catch (Throwable $e) {
            return "";
        }
    }

    $text = trim((string)$value);

    if ($text === "") {
        return "";
    }

    $formats = [
        "d-m-Y",
        "d/m/Y",
        "d.m.Y",
        "Y-m-d",
        "Y/m/d",
        "m-d-Y",
        "m/d/Y",
        "d M Y",
        "d F Y"
    ];

    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat(
            "!" . $format,
            $text
        );

        if ($dt instanceof DateTime) {
            return $dt->format("Y-m-d");
        }
    }

    $timestamp = strtotime($text);

    return $timestamp !== false
        ? date("Y-m-d", $timestamp)
        : "";
}
/* =========================================================
   IMPORT SEMESTER MATCHING
   Accepts:
   3SEM
   3 SEM
   3SEMESTER
   3 SEMESTER
   SEM 3
   SEMESTER 3
   SEM-3
   SEMESTER-3
========================================================= */

function normalizeImportedSemester($value)
{
    $value = trim((string)$value);

    if ($value === '') {
        return 0;
    }

    /* Convert to uppercase */
    $value = strtoupper($value);

    /* Support Roman-numeral semester values such as I SEM, III SEM, V SEM */
    $romanMap = [
        'FIRST' => '1', 'SECOND' => '2', 'THIRD' => '3',
        'FOURTH' => '4', 'FIFTH' => '5', 'SIXTH' => '6',
        'I' => '1', 'II' => '2', 'III' => '3',
        'IV' => '4', 'V' => '5', 'VI' => '6'
    ];

    foreach ($romanMap as $word => $number) {
        $value = preg_replace('/\b' . preg_quote($word, '/') . '\b/', $number, $value);
    }

    /* Remove unnecessary spaces */
    $value = preg_replace('/\s+/', ' ', $value);

    /*
     * First try:
     * 3SEM
     * 3 SEM
     * 3SEMESTER
     * 3 SEMESTER
     */
    if (preg_match('/^\s*(\d+)\s*(?:SEM|SEMESTER)\s*$/i', $value, $matches)) {
        return (int)$matches[1];
    }

    /*
     * Second try:
     * SEM3
     * SEM 3
     * SEM-3
     * SEMESTER3
     * SEMESTER 3
     * SEMESTER-3
     */
    if (preg_match('/^\s*(?:SEM|SEMESTER)\s*[-:]?\s*(\d+)\s*$/i', $value, $matches)) {
        return (int)$matches[1];
    }

    /*
     * If the cell contains additional text,
     * extract a semester number only when SEM/SEMESTER
     * appears next to the number.
     *
     * Examples:
     * "3 SEMESTER"
     * "SEMESTER 3"
     */
    if (
        preg_match(
            '/(?:^|\s)(\d+)\s*(?:SEM|SEMESTER)(?:\s|$)/i',
            $value,
            $matches
        )
    ) {
        return (int)$matches[1];
    }

    if (
        preg_match(
            '/(?:^|\s)(?:SEM|SEMESTER)\s*[-:]?\s*(\d+)(?:\s|$)/i',
            $value,
            $matches
        )
    ) {
        return (int)$matches[1];
    }

    return 0;
}


/* =========================================================
   CHECK IMPORTED SEMESTER
========================================================= */

function importedSemesterMatches(
    $importedSemester,
    $selectedSemesterNumber
) {
    $importedNumber =
        normalizeImportedSemester($importedSemester);

    $selectedNumber =
        (int)$selectedSemesterNumber;

    if (
        $importedNumber <= 0 ||
        $selectedNumber <= 0
    ) {
        return false;
    }

    return $importedNumber === $selectedNumber;
}
/* =========================================================
   FLASH MESSAGE
========================================================= */

$message = "";
$messageType = "";

if (isset($_SESSION["flash_message"])) {
    $message = $_SESSION["flash_message"];
    $messageType = $_SESSION["flash_message_type"] ?? "success";

    unset($_SESSION["flash_message"]);
    unset($_SESSION["flash_message_type"]);
}

/* =========================================================
   CSRF
========================================================= */

if (empty($_SESSION["exam_csrf_token"])) {
    $_SESSION["exam_csrf_token"] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION["exam_csrf_token"];

/* =========================================================
   CURRENT SELECTION
========================================================= */

$departmentId = isset($_GET["department"])
    ? (int)$_GET["department"]
    : 0;

$semesterId = isset($_GET["semester"])
    ? (int)$_GET["semester"]
    : 0;

$search = isset($_GET["search"])
    ? trim((string)$_GET["search"])
    : "";

/* =========================================================
   POST ACTIONS
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $postedToken = $_POST["csrf_token"] ?? "";

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        setFlash(
            "Invalid security token. Please refresh the page and try again.",
            "error"
        );

        redirectToExaminations(
            (int)($_POST["department_id"] ?? 0),
            (int)($_POST["semester_id"] ?? 0)
        );
    }

    $action = $_POST["action"] ?? "";

    /* =====================================================
       ADD
    ===================================================== */

    if ($action === "add_examination") {

        $selectedDepartmentId = (int)(
            $_POST["department_id"] ?? 0
        );

        $selectedSemesterId = (int)(
            $_POST["semester_id"] ?? 0
        );

        $subjectName = trim(
            (string)($_POST["subject_name"] ?? "")
        );

        $subjectCode = strtoupper(
            trim((string)($_POST["subject_code"] ?? ""))
        );

        $examDate = normalizeExamDate(
            $_POST["exam_date"] ?? ""
        );

        $examTime = normalizeExamTime(
            $_POST["exam_time"] ?? ""
        );

        $examTimeTo = normalizeExamTime(
            $_POST["exam_time_to"] ?? ""
        );

        $durationMinutes = calculateDurationMinutes(
            $examTime,
            $examTimeTo
        );

        if (
            $selectedDepartmentId <= 0 ||
            $selectedSemesterId <= 0 ||
            $subjectName === "" ||
            $subjectCode === "" ||
            mb_strlen($subjectName) > 150 ||
            mb_strlen($subjectCode) > 50 ||
            $examDate === "" ||
            $examTime === "" ||
            $examTimeTo === "" ||
            $durationMinutes <= 0
        ) {
            setFlash(
                "Please enter a valid subject, date, and complete From-To examination time.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        /* Verify semester belongs to department. */
        $semesterStmt = $conn->prepare(
            "SELECT
                sem.id,
                sem.semester_name,
                sem.semester_number,
                sem.year_number
             FROM semesters sem
             INNER JOIN students s
                ON s.semester_id = sem.id
             WHERE sem.id = ?
               AND s.department_id = ?
               AND sem.status = 1
             GROUP BY
                sem.id,
                sem.semester_name,
                sem.semester_number,
                sem.year_number
             LIMIT 1"
        );

        if (!$semesterStmt) {
            setFlash(
                "Unable to verify the selected semester.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $semesterStmt->bind_param(
            "ii",
            $selectedSemesterId,
            $selectedDepartmentId
        );

        $semesterStmt->execute();

        $semesterRow = $semesterStmt
            ->get_result()
            ->fetch_assoc();

        $semesterStmt->close();

        if (!$semesterRow) {
            setFlash(
                "Invalid department or semester selection.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        /*
         * Find academic year used by students for this
         * department + semester.
         */
        $yearStmt = $conn->prepare(
            "SELECT DISTINCT ay.id
             FROM students s
             INNER JOIN academic_years ay
                ON ay.id = s.academic_year
             WHERE s.department_id = ?
               AND s.semester_id = ?
             LIMIT 1"
        );

        if (!$yearStmt) {
            setFlash(
                "Unable to identify the academic year.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $yearStmt->bind_param(
    "ii",
    $selectedDepartmentId,
    $selectedSemesterId
);

        $yearStmt->execute();

        $yearRow = $yearStmt
            ->get_result()
            ->fetch_assoc();

        $yearStmt->close();

        if (!$yearRow) {
            setFlash(
                "No academic year is assigned to this semester.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $academicYearId = (int)$yearRow["id"];

        /* Duplicate code check. */
        $duplicateStmt = $conn->prepare(
            "SELECT id
             FROM examinations
             WHERE department_id = ?
               AND academic_year_id = ?
               AND semester_id = ?
               AND subject_code = ?
             LIMIT 1"
        );

        if (!$duplicateStmt) {
            setFlash(
                "Unable to check duplicate subject code.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $duplicateStmt->bind_param(
            "iiis",
            $selectedDepartmentId,
            $academicYearId,
            $selectedSemesterId,
            $subjectCode
        );

        $duplicateStmt->execute();

        $duplicateExists =
            $duplicateStmt->get_result()->num_rows > 0;

        $duplicateStmt->close();

        if ($duplicateExists) {
            setFlash(
                "This subject code already exists for the selected semester.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $insertStmt = $conn->prepare(
            "INSERT INTO examinations
            (
                department_id,
                academic_year_id,
                semester_id,
                subject_name,
                subject_code,
                exam_date,
                exam_time,
                exam_time_to,
                duration_minutes
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if (!$insertStmt) {
            setFlash(
                "Unable to prepare examination insertion.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $insertStmt->bind_param(
            "iiisssssi",
            $selectedDepartmentId,
            $academicYearId,
            $selectedSemesterId,
            $subjectName,
            $subjectCode,
            $examDate,
            $examTime,
            $examTimeTo,
            $durationMinutes
        );

        if ($insertStmt->execute()) {
            createNotification(
                $conn,
                "Examination Added",
                $subjectName . " was added to the examination timetable.",
                "exam_added"
            );

            setFlash(
                "Examination added successfully.",
                "success"
            );
        } else {
            setFlash(
                "Unable to add the examination: " . $insertStmt->error,
                "error"
            );
        }

        $insertStmt->close();

        redirectToExaminations(
            $selectedDepartmentId,
            $selectedSemesterId
        );
    }

    /* =====================================================
       UPDATE
    ===================================================== */

    if ($action === "update_examination") {

        $examId = (int)(
            $_POST["exam_id"] ?? 0
        );

        $selectedDepartmentId = (int)(
            $_POST["department_id"] ?? 0
        );

        $selectedSemesterId = (int)(
            $_POST["semester_id"] ?? 0
        );

        $subjectName = trim(
            (string)($_POST["subject_name"] ?? "")
        );

        $subjectCode = strtoupper(
            trim((string)($_POST["subject_code"] ?? ""))
        );

        $examDate = normalizeExamDate(
            $_POST["exam_date"] ?? ""
        );

        $examTime = normalizeExamTime(
            $_POST["exam_time"] ?? ""
        );

        $examTimeTo = normalizeExamTime(
            $_POST["exam_time_to"] ?? ""
        );

        $durationMinutes = calculateDurationMinutes(
            $examTime,
            $examTimeTo
        );

        if (
            $examId <= 0 ||
            $selectedDepartmentId <= 0 ||
            $selectedSemesterId <= 0 ||
            $subjectName === "" ||
            $subjectCode === "" ||
            mb_strlen($subjectName) > 150 ||
            mb_strlen($subjectCode) > 50 ||
            $examDate === "" ||
            $examTime === "" ||
            $examTimeTo === "" ||
            $durationMinutes <= 0
        ) {
            setFlash(
                "Please enter valid examination details.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        /* Make sure the record really belongs to this page. */
        $existsStmt = $conn->prepare(
            "SELECT id
             FROM examinations
             WHERE id = ?
               AND department_id = ?
               AND semester_id = ?
             LIMIT 1"
        );

        if (!$existsStmt) {
            setFlash(
                "Unable to verify the examination.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $existsStmt->bind_param(
            "iii",
            $examId,
            $selectedDepartmentId,
            $selectedSemesterId
        );

        $existsStmt->execute();

        $recordExists =
            $existsStmt->get_result()->num_rows > 0;

        $existsStmt->close();

        if (!$recordExists) {
            setFlash(
                "The selected examination record was not found.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        /* Identify the academic year used by this department + semester. */
        $yearStmt = $conn->prepare(
            "SELECT academic_year_id
             FROM examinations
             WHERE id = ?
               AND department_id = ?
               AND semester_id = ?
             LIMIT 1"
        );

        if (!$yearStmt) {
            setFlash(
                "Unable to identify the academic year: " . $conn->error,
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $yearStmt->bind_param(
            "iii",
            $examId,
            $selectedDepartmentId,
            $selectedSemesterId
        );

        if (!$yearStmt->execute()) {
            $error = $yearStmt->error;
            $yearStmt->close();

            setFlash(
                "Unable to identify the academic year: " . $error,
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $yearRow = $yearStmt
            ->get_result()
            ->fetch_assoc();

        $yearStmt->close();

        if (!$yearRow) {
            setFlash(
                "No academic year is assigned to this semester.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $academicYearId = (int)$yearRow["academic_year_id"];

        /* Duplicate code check matching the database unique key. */
        $duplicateStmt = $conn->prepare(
            "SELECT id
             FROM examinations
             WHERE department_id = ?
               AND academic_year_id = ?
               AND semester_id = ?
               AND subject_code = ?
               AND id <> ?
             LIMIT 1"
        );

        if (!$duplicateStmt) {
            setFlash(
                "Unable to check duplicate subject code.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $duplicateStmt->bind_param(
            "iiisi",
            $selectedDepartmentId,
            $academicYearId,
            $selectedSemesterId,
            $subjectCode,
            $examId
        );

        $duplicateStmt->execute();

        $duplicateExists =
            $duplicateStmt->get_result()->num_rows > 0;

        $duplicateStmt->close();

        if ($duplicateExists) {
            setFlash(
                "Another examination already uses this subject code.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $updateStmt = $conn->prepare(
            "UPDATE examinations
             SET
                subject_name = ?,
                subject_code = ?,
                exam_date = ?,
                exam_time = ?,
                exam_time_to = ?,
                duration_minutes = ?
             WHERE id = ?
               AND department_id = ?
               AND semester_id = ?
             LIMIT 1"
        );

        if (!$updateStmt) {
            setFlash(
                "Unable to prepare examination update.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $updateStmt->bind_param(
            "sssssiiii",
            $subjectName,
            $subjectCode,
            $examDate,
            $examTime,
            $examTimeTo,
            $durationMinutes,
            $examId,
            $selectedDepartmentId,
            $selectedSemesterId
        );

        if ($updateStmt->execute()) {
            if ($updateStmt->affected_rows > 0) {
                createNotification(
                    $conn,
                    "Examination Edited",
                    $subjectName .
                    " was updated in the examination timetable.",
                    "exam_updated"
                );

                setFlash(
                    "Examination updated successfully.",
                    "success"
                );
            } else {
                setFlash(
                    "No changes were made to the examination.",
                    "success"
                );
            }
        } else {
            setFlash(
                "Unable to update the examination: " . $updateStmt->error,
                "error"
            );
        }

        $updateStmt->close();

        redirectToExaminations(
            $selectedDepartmentId,
            $selectedSemesterId
        );
    }

    /* =====================================================
       DELETE ONE RECORD
       attendance and current allocation_batches reference examinations
       without ON DELETE CASCADE in the supplied database.
       They are therefore removed first in one transaction.
    ===================================================== */

    if ($action === "delete_examination") {

        $examId = (int)(
            $_POST["exam_id"] ?? 0
        );

        $selectedDepartmentId = (int)(
            $_POST["department_id"] ?? 0
        );

        $selectedSemesterId = (int)(
            $_POST["semester_id"] ?? 0
        );

        if (
            $examId <= 0 ||
            $selectedDepartmentId <= 0 ||
            $selectedSemesterId <= 0
        ) {
            setFlash(
                "Invalid examination selected.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $conn->begin_transaction();

        try {
            $verifyStmt = $conn->prepare(
                "SELECT id, subject_name
                 FROM examinations
                 WHERE id = ?
                   AND department_id = ?
                   AND semester_id = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$verifyStmt) {
                throw new RuntimeException(
                    "Unable to verify the examination: " . $conn->error
                );
            }

            $verifyStmt->bind_param(
                "iii",
                $examId,
                $selectedDepartmentId,
                $selectedSemesterId
            );

            if (!$verifyStmt->execute()) {
                $error = $verifyStmt->error;
                $verifyStmt->close();
                throw new RuntimeException(
                    "Unable to verify the examination: " . $error
                );
            }

            $examRow = $verifyStmt
                ->get_result()
                ->fetch_assoc();

            $verifyStmt->close();

            if (!$examRow) {
                throw new RuntimeException(
                    "Examination was not found in the selected timetable."
                );
            }

            $subjectName = (string)$examRow["subject_name"];

            $attendanceStmt = $conn->prepare(
                "DELETE FROM attendance
                 WHERE exam_id = ?"
            );

            if (!$attendanceStmt) {
                throw new RuntimeException(
                    "Unable to prepare attendance cleanup: " . $conn->error
                );
            }

            $attendanceStmt->bind_param(
                "i",
                $examId
            );

            if (!$attendanceStmt->execute()) {
                $error = $attendanceStmt->error;
                $attendanceStmt->close();

                throw new RuntimeException(
                    "Unable to remove attendance records: " . $error
                );
            }

            $attendanceStmt->close();

            $allocationStmt = $conn->prepare(
                "DELETE FROM allocation_batches
                 WHERE exam_id = ?"
            );

            if (!$allocationStmt) {
                throw new RuntimeException(
                    "Unable to prepare allocation cleanup: " . $conn->error
                );
            }

            $allocationStmt->bind_param(
                "i",
                $examId
            );

            if (!$allocationStmt->execute()) {
                $error = $allocationStmt->error;
                $allocationStmt->close();

                throw new RuntimeException(
                    "Unable to remove allocation records: " . $error
                );
            }

            $allocationStmt->close();

            $deleteStmt = $conn->prepare(
                "DELETE FROM examinations
                 WHERE id = ?
                   AND department_id = ?
                   AND semester_id = ?
                 LIMIT 1"
            );

            if (!$deleteStmt) {
                throw new RuntimeException(
                    "Unable to prepare examination deletion: " . $conn->error
                );
            }

            $deleteStmt->bind_param(
                "iii",
                $examId,
                $selectedDepartmentId,
                $selectedSemesterId
            );

            if (!$deleteStmt->execute()) {
                $error = $deleteStmt->error;
                $deleteStmt->close();

                throw new RuntimeException(
                    "Unable to delete the examination: " . $error
                );
            }

            if ($deleteStmt->affected_rows !== 1) {
                $deleteStmt->close();

                throw new RuntimeException(
                    "The examination was not deleted."
                );
            }

            $deleteStmt->close();

            $conn->commit();

            createNotification(
                $conn,
                "Examination Deleted",
                $subjectName .
                " was removed from the examination timetable.",
                "exam_deleted"
            );

            setFlash(
                "Examination deleted successfully.",
                "success"
            );

        } catch (Throwable $e) {
            $conn->rollback();

            setFlash(
                $e->getMessage(),
                "error"
            );
        }

        redirectToExaminations(
            $selectedDepartmentId,
            $selectedSemesterId
        );
    }

    /* =====================================================
       DELETE ALL
       Removes dependent attendance and seat allocation rows
       before deleting the selected timetable examinations.
    ===================================================== */

    if ($action === "delete_all_examinations") {

        $selectedDepartmentId = (int)(
            $_POST["department_id"] ?? 0
        );

        $selectedSemesterId = (int)(
            $_POST["semester_id"] ?? 0
        );

        if (
            $selectedDepartmentId <= 0 ||
            $selectedSemesterId <= 0
        ) {
            setFlash(
                "Invalid examination selection.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $conn->begin_transaction();

        try {
            $examIds = [];

            $idsStmt = $conn->prepare(
                "SELECT id
                 FROM examinations
                 WHERE department_id = ?
                   AND semester_id = ?"
            );

            if (!$idsStmt) {
                throw new RuntimeException(
                    "Unable to find examinations: " . $conn->error
                );
            }

            $idsStmt->bind_param(
                "ii",
                $selectedDepartmentId,
                $selectedSemesterId
            );

            if (!$idsStmt->execute()) {
                $error = $idsStmt->error;
                $idsStmt->close();

                throw new RuntimeException(
                    "Unable to find examinations: " . $error
                );
            }

            $idsResult = $idsStmt->get_result();

            while ($row = $idsResult->fetch_assoc()) {
                $examIds[] = (int)$row["id"];
            }

            $idsStmt->close();

            $deletedCount = count($examIds);

            if ($deletedCount === 0) {
                $conn->rollback();

                setFlash(
                    "There are no examinations to delete.",
                    "error"
                );

                redirectToExaminations(
                    $selectedDepartmentId,
                    $selectedSemesterId
                );
            }

            foreach ($examIds as $examId) {

                $attendanceStmt = $conn->prepare(
                    "DELETE FROM attendance
                     WHERE exam_id = ?"
                );

                if (!$attendanceStmt) {
                    throw new RuntimeException(
                        "Unable to prepare attendance cleanup: " . $conn->error
                    );
                }

                $attendanceStmt->bind_param(
                    "i",
                    $examId
                );

                if (!$attendanceStmt->execute()) {
                    $error = $attendanceStmt->error;
                    $attendanceStmt->close();

                    throw new RuntimeException(
                        "Unable to remove attendance records: " . $error
                    );
                }

                $attendanceStmt->close();

                $allocationStmt = $conn->prepare(
                    "DELETE FROM allocation_batches
                     WHERE exam_id = ?"
                );

                if (!$allocationStmt) {
                    throw new RuntimeException(
                        "Unable to prepare allocation cleanup: " . $conn->error
                    );
                }

                $allocationStmt->bind_param(
                    "i",
                    $examId
                );

                if (!$allocationStmt->execute()) {
                    $error = $allocationStmt->error;
                    $allocationStmt->close();

                    throw new RuntimeException(
                        "Unable to remove allocation records: " . $error
                    );
                }

                $allocationStmt->close();
            }

            $deleteAllStmt = $conn->prepare(
                "DELETE FROM examinations
                 WHERE department_id = ?
                   AND semester_id = ?"
            );

            if (!$deleteAllStmt) {
                throw new RuntimeException(
                    "Unable to prepare Delete All: " . $conn->error
                );
            }

            $deleteAllStmt->bind_param(
                "ii",
                $selectedDepartmentId,
                $selectedSemesterId
            );

            if (!$deleteAllStmt->execute()) {
                $error = $deleteAllStmt->error;
                $deleteAllStmt->close();

                throw new RuntimeException(
                    "Unable to delete the examinations: " . $error
                );
            }

            $actualDeleted = (int)$deleteAllStmt->affected_rows;
            $deleteAllStmt->close();

            if ($actualDeleted !== $deletedCount) {
                throw new RuntimeException(
                    "The examination deletion was incomplete."
                );
            }

            $conn->commit();

            createNotification(
                $conn,
                "Examinations Deleted",
                $actualDeleted .
                " examination(s) were deleted from the timetable.",
                "exam_deleted"
            );

            setFlash(
                $actualDeleted .
                " examination(s) deleted successfully.",
                "success"
            );

        } catch (Throwable $e) {
            $conn->rollback();

            setFlash(
                $e->getMessage(),
                "error"
            );
        }

        redirectToExaminations(
            $selectedDepartmentId,
            $selectedSemesterId
        );
    }

    /* =====================================================
       IMPORT EXCEL

       REQUIRED IMPORT COLUMNS:
       A = Semester
       B = Date
       C = Time
       D = Subject Code
       E = Subject Name

       PCode / Remark columns are intentionally ignored.
    ===================================================== */

    if ($action === "import_examinations") {

        $selectedDepartmentId = (int)(
            $_POST["department_id"] ?? 0
        );

        $selectedSemesterId = (int)(
            $_POST["semester_id"] ?? 0
        );

        if (
            $selectedDepartmentId <= 0 ||
            $selectedSemesterId <= 0
        ) {
            setFlash(
                "Invalid department or semester.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        if (
            !isset($_FILES["excel_file"]) ||
            $_FILES["excel_file"]["error"] !== UPLOAD_ERR_OK
        ) {
            setFlash(
                "Please select a valid Excel file.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $extension = strtolower(
            pathinfo(
                $_FILES["excel_file"]["name"],
                PATHINFO_EXTENSION
            )
        );

        if (!in_array($extension, ["xlsx", "xls"], true)) {
            setFlash(
                "Only .xlsx and .xls files are supported.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        /* Get selected semester details. */
        $semesterStmt = $conn->prepare(
            "SELECT
                sem.id,
                sem.semester_name,
                sem.semester_number,
                sem.year_number
             FROM semesters sem
             INNER JOIN students s
                ON s.semester_id = sem.id
             WHERE sem.id = ?
               AND s.department_id = ?
               AND sem.status = 1
             GROUP BY
                sem.id,
                sem.semester_name,
                sem.semester_number,
                sem.year_number
             LIMIT 1"
        );

        if (!$semesterStmt) {
            setFlash(
                "Unable to verify the selected semester.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $semesterStmt->bind_param(
            "ii",
            $selectedSemesterId,
            $selectedDepartmentId
        );

        $semesterStmt->execute();

        $semesterRow = $semesterStmt
            ->get_result()
            ->fetch_assoc();

        $semesterStmt->close();

        if (!$semesterRow) {
            setFlash(
                "Invalid department or semester.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $selectedSemesterNumber =
            (int)$semesterRow["semester_number"];

        /* Academic year used by students. */
        $yearStmt = $conn->prepare(
            "SELECT DISTINCT ay.id
             FROM students s
             INNER JOIN academic_years ay
                ON ay.id = s.academic_year
             WHERE s.department_id = ?
               AND s.semester_id = ?
             LIMIT 1"
        );

        if (!$yearStmt) {
            setFlash(
                "Unable to identify the academic year.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $yearStmt->bind_param(
            "ii",
            $selectedDepartmentId,
            $selectedSemesterId
        );

        $yearStmt->execute();

        $yearRow = $yearStmt
            ->get_result()
            ->fetch_assoc();

        $yearStmt->close();

        if (!$yearRow) {
            setFlash(
                "No academic year is assigned to this semester.",
                "error"
            );

            redirectToExaminations(
                $selectedDepartmentId,
                $selectedSemesterId
            );
        }

        $academicYearId = (int)$yearRow["id"];

        try {

            $spreadsheet = IOFactory::load(
                $_FILES["excel_file"]["tmp_name"]
            );

            $sheet = $spreadsheet->getActiveSheet();

            $rows = $sheet->toArray(
                null,
                true,
                true,
                true
            );

            $imported = 0;
            $skipped = 0;

            $skipReasons = [
                "semester" => 0,
                "subject" => 0,
                "date" => 0,
                "time" => 0,
                "duplicate" => 0,
                "database" => 0
            ];

            /*
             * Excel exports can contain a title, department and semester
             * information before the actual table header. Those rows are
             * metadata, not failed examinations, so they are ignored.
             */
            $headerFound = false;
            $lastImportedSemester = "";

            foreach ($rows as $rowNumber => $row) {

                $a = trim((string)($row["A"] ?? ""));
                $b = trim((string)($row["B"] ?? ""));
                $c = trim((string)($row["C"] ?? ""));
                $d = trim((string)($row["D"] ?? ""));
                $e = trim((string)($row["E"] ?? ""));

                /* Ignore completely blank rows. */
                if (
                    $a === "" &&
                    $b === "" &&
                    $c === "" &&
                    $d === "" &&
                    $e === ""
                ) {
                    continue;
                }

                /* Find the real table header. */
                $isHeader =
                    preg_match('/^\s*semester\s*$/i', $a) &&
                    preg_match('/^\s*date\s*$/i', $b) &&
                    preg_match('/^\s*time\s*$/i', $c) &&
                    preg_match('/subject\s*code/i', $d) &&
                    preg_match('/subject\s*name/i', $e);

                if (!$headerFound) {
                    if ($isHeader) {
                        $headerFound = true;
                    }
                    continue;
                }

                if ($isHeader) {
                    continue;
                }

                /*
                 * Preserve the last semester when Excel leaves the cell
                 * blank on following rows (normal or merged-cell layout).
                 */
                if ($a !== "") {
                    $lastImportedSemester = $a;
                }

                $importedSemester = $lastImportedSemester;
                $examDateRaw = $b;
                $timeRaw = $c;
                $subjectCode = strtoupper($d);
                $subjectName = $e;

                if (!importedSemesterMatches(
                    $importedSemester,
                    $selectedSemesterNumber
                )) {
                    $skipped++;
                    $skipReasons["semester"]++;
                    continue;
                }

                /* PCode and Remark columns are intentionally ignored. */
                if (
                    $subjectCode === "" ||
                    $subjectName === "" ||
                    mb_strlen($subjectCode) > 50 ||
                    mb_strlen($subjectName) > 150
                ) {
                    $skipped++;
                    $skipReasons["subject"]++;
                    continue;
                }

                $examDate = normalizeExamDate($examDateRaw);

                if ($examDate === "") {
                    $skipped++;
                    $skipReasons["date"]++;
                    continue;
                }

                /* Complete From-To time is required. */
                [$examTime, $examTimeTo] =
                    parseImportedTimeRange($timeRaw);

                if ($examTime === "" || $examTimeTo === "") {
                    $skipped++;
                    $skipReasons["time"]++;
                    continue;
                }

                $durationMinutes = calculateDurationMinutes(
                    $examTime,
                    $examTimeTo
                );

                if ($durationMinutes <= 0) {
                    $skipped++;
                    $skipReasons["time"]++;
                    continue;
                }

                /* Prevent duplicate subject codes. */
                $duplicateStmt = $conn->prepare(
                    "SELECT id
                     FROM examinations
                     WHERE department_id = ?
                       AND academic_year_id = ?
                       AND semester_id = ?
                       AND subject_code = ?
                     LIMIT 1"
                );

                if (!$duplicateStmt) {
                    $skipped++;
                    $skipReasons["database"]++;
                    continue;
                }

                $duplicateStmt->bind_param(
                    "iiis",
                    $selectedDepartmentId,
                    $academicYearId,
                    $selectedSemesterId,
                    $subjectCode
                );

                if (!$duplicateStmt->execute()) {
                    $duplicateStmt->close();
                    $skipped++;
                    $skipReasons["database"]++;
                    continue;
                }

                $exists =
                    $duplicateStmt
                        ->get_result()
                        ->num_rows > 0;

                $duplicateStmt->close();

                if ($exists) {
                    $skipped++;
                    $skipReasons["duplicate"]++;
                    continue;
                }

                $insertStmt = $conn->prepare(
                    "INSERT INTO examinations
                    (
                        department_id,
                        academic_year_id,
                        semester_id,
                        subject_name,
                        subject_code,
                        exam_date,
                        exam_time,
                        exam_time_to,
                        duration_minutes
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );

                if (!$insertStmt) {
                    $skipped++;
                    $skipReasons["database"]++;
                    continue;
                }

                $insertStmt->bind_param(
                    "iiisssssi",
                    $selectedDepartmentId,
                    $academicYearId,
                    $selectedSemesterId,
                    $subjectName,
                    $subjectCode,
                    $examDate,
                    $examTime,
                    $examTimeTo,
                    $durationMinutes
                );

                if ($insertStmt->execute()) {
                    $imported++;
                } else {
                    $skipped++;
                    $skipReasons["database"]++;
                }

                $insertStmt->close();
            }

            if (!$headerFound) {
                setFlash(
                    "Import failed. The Excel file must contain the columns: Semester, Date, Time, Subject Code, Subject Name.",
                    "error"
                );

                redirectToExaminations(
                    $selectedDepartmentId,
                    $selectedSemesterId
                );
            }

            if ($imported > 0) {
                createNotification(
                    $conn,
                    "Examinations Imported",
                    $imported .
                    " examination(s) imported successfully.",
                    "exam_imported"
                );
            }

            $details = [];

            if ($skipReasons["semester"] > 0) {
                $details[] = $skipReasons["semester"] . " wrong semester";
            }

            if ($skipReasons["subject"] > 0) {
                $details[] = $skipReasons["subject"] . " invalid subject";
            }

            if ($skipReasons["date"] > 0) {
                $details[] = $skipReasons["date"] . " invalid date";
            }

            if ($skipReasons["time"] > 0) {
                $details[] = $skipReasons["time"] . " invalid time";
            }

            if ($skipReasons["duplicate"] > 0) {
                $details[] = $skipReasons["duplicate"] . " duplicate";
            }

            if ($skipReasons["database"] > 0) {
                $details[] = $skipReasons["database"] . " database error";
            }

            $messageText =
                "Import completed. " .
                $imported .
                " imported.";

            if ($skipped > 0) {
                $messageText .=
                    " " .
                    $skipped .
                    " skipped";

                if (!empty($details)) {
                    $messageText .=
                        " (" .
                        implode(", ", $details) .
                        ")";
                }

                $messageText .= ".";
            }

            setFlash(
                $messageText,
                $imported > 0 ? "success" : "error"
            );

        } catch (Throwable $e) {

            setFlash(
                "Unable to read the Excel file. Please check the file format.",
                "error"
            );
        }

        redirectToExaminations(
            $selectedDepartmentId,
            $selectedSemesterId
        );
    }
}

/* =========================================================
   EXPORT EXCEL
========================================================= */

if (
    isset($_GET["export"]) &&
    $_GET["export"] === "excel" &&
    $departmentId > 0 &&
    $semesterId > 0
) {

    $stmt = $conn->prepare(
        "SELECT
            d.department_name,
            d.department_code,
            ay.year_name,
            sem.semester_name,
            sem.semester_number,
            e.subject_name,
            e.subject_code,
            e.exam_date,
            e.exam_time,
            e.exam_time_to,
            e.duration_minutes
         FROM examinations e
         INNER JOIN departments d
            ON d.id = e.department_id
         INNER JOIN academic_years ay
            ON ay.id = e.academic_year_id
         INNER JOIN semesters sem
            ON sem.id = e.semester_id
         WHERE e.department_id = ?
           AND e.semester_id = ?
         ORDER BY
            e.exam_date ASC,
            e.exam_time ASC,
            e.subject_name ASC"
    );

    if (!$stmt) {
        die("Export query failed: " . $conn->error);
    }

    $stmt->bind_param(
        "ii",
        $departmentId,
        $semesterId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle("Examinations");

    $sheet->setCellValue(
        "A1",
        "EXAMINATION TIMETABLE"
    );

    $sheet->mergeCells("A1:E1");

    $firstExam = null;

    if ($result->num_rows > 0) {
        $firstExam = $result->fetch_assoc();
        $result->data_seek(0);
    }

    if ($firstExam) {
        $sheet->setCellValue(
            "A2",
            $firstExam["department_name"] .
            " (" .
            $firstExam["department_code"] .
            ")"
        );

        $sheet->setCellValue(
            "A3",
            $firstExam["year_name"] .
            " - " .
            $firstExam["semester_name"]
        );
    }

    $sheet->setCellValue("A5", "Semester");
    $sheet->setCellValue("B5", "Date");
    $sheet->setCellValue("C5", "Time");
    $sheet->setCellValue("D5", "Subject Code");
    $sheet->setCellValue("E5", "Subject Name");

    $rowNumber = 6;

    while ($exam = $result->fetch_assoc()) {

        $semesterLabel =
            $exam["semester_number"] . "SEM";

        $sheet->setCellValue(
            "A" . $rowNumber,
            $semesterLabel
        );

        $sheet->setCellValue(
            "B" . $rowNumber,
            date(
                "d-m-Y",
                strtotime($exam["exam_date"])
            )
        );

        $sheet->setCellValue(
            "C" . $rowNumber,
            formatTimeRange(
                $exam["exam_time"],
                $exam["exam_time_to"]
            )
        );

        $sheet->setCellValue(
            "D" . $rowNumber,
            $exam["subject_code"]
        );

        $sheet->setCellValue(
            "E" . $rowNumber,
            $exam["subject_name"]
        );

        $rowNumber++;
    }

    foreach (
        ["A", "B", "C", "D", "E"]
        as $column
    ) {
        $sheet
            ->getColumnDimension($column)
            ->setAutoSize(true);
    }

    $sheet
        ->getStyle("A1:E1")
        ->getFont()
        ->setBold(true);

    $sheet
        ->getStyle("A5:E5")
        ->getFont()
        ->setBold(true);

    $stmt->close();

    createNotification(
        $conn,
        "Examinations Exported",
        "The examination timetable was exported successfully.",
        "exam_exported"
    );

    $fileName =
        "examination_timetable_" .
        date("Y-m-d_H-i-s") .
        ".xlsx";

    header(
        "Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
    );

    header(
        "Content-Disposition: attachment; filename=\"" .
        $fileName .
        "\""
    );

    header("Cache-Control: max-age=0");

    $writer = new Xlsx($spreadsheet);
    $writer->save("php://output");

    exit();
}

/* =========================================================
   LOAD DEPARTMENTS
========================================================= */

$departments = [];

$departmentStmt = $conn->prepare(
    "SELECT
        d.id,
        d.department_name,
        d.department_code,
        COUNT(s.id) AS total_students
     FROM departments d
     INNER JOIN students s
        ON s.department_id = d.id
     GROUP BY
        d.id,
        d.department_name,
        d.department_code
     ORDER BY d.department_name ASC"
);

if ($departmentStmt) {
    $departmentStmt->execute();

    $result = $departmentStmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $departments[] = $row;
    }

    $departmentStmt->close();
}

/* =========================================================
   SELECTED DEPARTMENT
========================================================= */

$selectedDepartment = null;

if ($departmentId > 0) {

    $stmt = $conn->prepare(
        "SELECT
            d.id,
            d.department_name,
            d.department_code,
            COUNT(s.id) AS total_students
         FROM departments d
         INNER JOIN students s
            ON s.department_id = d.id
         WHERE d.id = ?
         GROUP BY
            d.id,
            d.department_name,
            d.department_code
         LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param(
            "i",
            $departmentId
        );

        $stmt->execute();

        $selectedDepartment =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();
    }

    if (!$selectedDepartment) {
        $departmentId = 0;
        $semesterId = 0;
    }
}

/* =========================================================
   ASSIGNED YEARS / SEMESTERS
========================================================= */

$yearGroups = [];

if ($selectedDepartment) {

    $stmt = $conn->prepare(
        "SELECT
            ay.id AS academic_year_id,
            ay.year_name,
            sem.id AS semester_id,
            sem.semester_name,
            sem.semester_number,
            sem.year_number,
            COUNT(s.id) AS total_students
         FROM students s
         INNER JOIN academic_years ay
            ON ay.id = s.academic_year
         INNER JOIN semesters sem
            ON sem.id = s.semester_id
         WHERE s.department_id = ?
           AND sem.status = 1
         GROUP BY
            ay.id,
            ay.year_name,
            sem.id,
            sem.semester_name,
            sem.semester_number,
            sem.year_number
         ORDER BY
            sem.year_number ASC,
            sem.semester_number ASC"
    );

    if ($stmt) {

        $stmt->bind_param(
            "i",
            $departmentId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {

            $yearNumber =
                (int)$row["year_number"];

            if (!isset($yearGroups[$yearNumber])) {
                $yearGroups[$yearNumber] = [
                    "year_name" =>
                        getYearLabel($yearNumber),
                    "semesters" => []
                ];
            }

            $yearGroups[$yearNumber]["semesters"][] =
                $row;
        }

        $stmt->close();
    }
}

ksort($yearGroups);

/* =========================================================
   SELECTED SEMESTER
========================================================= */

$selectedSemester = null;

if ($selectedDepartment && $semesterId > 0) {

    $stmt = $conn->prepare(
        "SELECT
            ay.id AS academic_year_id,
            ay.year_name,
            sem.id AS semester_id,
            sem.semester_name,
            sem.semester_number,
            sem.year_number,
            COUNT(s.id) AS total_students
         FROM students s
         INNER JOIN academic_years ay
            ON ay.id = s.academic_year
         INNER JOIN semesters sem
            ON sem.id = s.semester_id
         WHERE s.department_id = ?
           AND s.semester_id = ?
           AND sem.status = 1
         GROUP BY
            ay.id,
            ay.year_name,
            sem.id,
            sem.semester_name,
            sem.semester_number,
            sem.year_number
         LIMIT 1"
    );

    if ($stmt) {

        $stmt->bind_param(
            "ii",
            $departmentId,
            $semesterId
        );

        $stmt->execute();

        $selectedSemester =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();
    }

    if (!$selectedSemester) {
        $semesterId = 0;
    }
}

/* =========================================================
   EXAMINATION LIST
========================================================= */

$examinations = [];

if ($selectedDepartment && $selectedSemester) {

    $sql =
        "SELECT
            e.id,
            e.subject_name,
            e.subject_code,
            e.exam_date,
            e.exam_time,
            e.exam_time_to,
            e.duration_minutes
         FROM examinations e
         WHERE e.department_id = ?
           AND e.semester_id = ?";

    if ($search !== "") {
        $sql .=
            " AND (
                e.subject_name LIKE ?
                OR e.subject_code LIKE ?
            )";
    }

    $sql .=
        " ORDER BY
            e.exam_date ASC,
            e.exam_time ASC,
            e.subject_name ASC";

    $stmt = $conn->prepare($sql);

    if ($stmt) {

        if ($search !== "") {

            $searchValue =
                "%" . $search . "%";

            $stmt->bind_param(
                "iiss",
                $departmentId,
                $semesterId,
                $searchValue,
                $searchValue
            );

        } else {

            $stmt->bind_param(
                "ii",
                $departmentId,
                $semesterId
            );
        }

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $examinations[] = $row;
        }

        $stmt->close();
    }
}

/* =========================================================
   PAGE MODE
========================================================= */

$pageMode = "departments";

if ($selectedDepartment && !$selectedSemester) {
    $pageMode = "semesters";
}

if ($selectedDepartment && $selectedSemester) {
    $pageMode = "timetable";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0">
<title>Examinations | Examination Seating Management System</title>

<link
    rel="stylesheet"
    href="../assets/css/examinations.css">

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

<style>
/* =========================================================
   TIME RANGE FORM
========================================================= */

.time-range-wrapper {
    width: 100%;
    display: flex;
    align-items: flex-end;
    gap: 14px;
}

.time-field {
    flex: 1;
    min-width: 0;
}

.time-field-label {
    display: block;
    margin-bottom: 8px;
    color: #64748b;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}

.time-selects {
    display: flex;
    align-items: center;
    gap: 5px;
}

.time-selects select {
    width: auto;
    min-width: 0;
    height: 43px;
    padding: 0 8px;
    border: 1px solid #dbe3ee;
    border-radius: 9px;
    background: #f8fafc;
    color: #0f172a;
    font: inherit;
    font-size: 13px;
    font-weight: 600;
    outline: none;
    cursor: pointer;
}

.time-selects select:first-child {
    width: 58px;
}

.time-selects select:nth-of-type(2) {
    width: 58px;
}

.time-selects select:last-child {
    width: 68px;
}

.time-selects select:focus {
    background: #fff;
    border-color: #2563a6;
    box-shadow: 0 0 0 3px rgba(37, 99, 166, 0.08);
}

.time-range-arrow {
    width: 34px;
    min-width: 34px;
    height: 43px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #2563a6;
    font-size: 19px;
    font-weight: 700;
}

.time-duration-preview {
    margin-top: 8px;
    color: #64748b;
    font-size: 10px;
}

.import-modal {
    width: min(580px, 100%);
    max-height: calc(100vh - 50px);
    overflow-y: auto;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 21px;
    box-shadow: 0 25px 70px rgba(15, 50, 90, 0.22);
}

.import-modal .modal-header {
    padding: 22px 24px;
}

.import-modal .modal-header > div:first-child {
    flex: 1;
    min-width: 0;
}

.import-modal-icon {
    width: 46px;
    height: 46px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 13px;
    background: #ecfdf3;
    color: #087f5b;
    border: 1px solid #bbf7d0;
    font-size: 20px;
}

.import-modal .modal-header h2 {
    margin: 0;
    color: #1e293b;
    font-size: 17px;
    font-weight: 650;
}

.import-modal .modal-header p {
    margin: 3px 0 0;
    color: #64748b;
    font-size: 10px;
}

.import-modal-close {
    width: 36px;
    height: 36px;
    min-width: 36px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
}

.import-modal-close:hover {
    background: #fff1f2;
    border-color: #fecdd3;
    color: #dc2626;
}

.import-modal form {
    padding: 22px;
}

.import-selected {
    margin-bottom: 18px;
    padding: 12px 14px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    background: #f8fbff;
    border: 1px solid #e4eef7;
    border-radius: 13px;
}

.import-selected span {
    display: block;
    color: #94a3b8;
    font-size: 9px;
}

.import-selected strong {
    display: block;
    margin-top: 1px;
    color: #334155;
    font-size: 11px;
}

.import-format-note {
    margin-bottom: 16px;
    padding: 12px 14px;
    background: #f8fbff;
    border: 1px solid #e1edf7;
    border-radius: 13px;
    color: #475569;
    font-size: 10px;
    line-height: 1.7;
}

.import-format-note strong {
    color: #1e293b;
}

.import-format-note code {
    padding: 2px 4px;
    border-radius: 4px;
    background: #edf5fc;
    color: #2563a6;
}

.import-file-area {
    padding: 18px;
    border: 1.5px dashed #b8ccdf;
    border-radius: 16px;
    background: #fbfdff;
}

.import-file-area > label {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-bottom: 9px;
    color: #334155;
    font-size: 12px;
    font-weight: 650;
}

.import-file-area input[type="file"] {
    width: 100%;
    padding: 8px;
    border: 1px solid #dbe7f0;
    border-radius: 8px;
    background: #fff;
    color: #475569;
    font-size: 11px;
}

.import-file-area input[type="file"]::file-selector-button {
    padding: 7px 12px;
    margin-right: 8px;
    border: none;
    border-radius: 7px;
    background: #2563a6;
    color: #fff;
    font-size: 11px;
    cursor: pointer;
}

.selected-file-name {
    display: block;
    margin-top: 9px;
    color: #64748b;
    font-size: 10px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.import-modal-actions {
    margin-top: 20px;
    padding-top: 17px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    border-top: 1px solid #edf1f6;
}

.modal-open {
    overflow: hidden;
}

button:disabled {
    opacity: .55;
    cursor: not-allowed;
}

@media (max-width: 650px) {
    .time-range-wrapper {
        flex-direction: column;
        align-items: stretch;
        gap: 9px;
    }

    .time-range-arrow {
        width: 100%;
        height: 22px;
        min-width: 0;
        transform: rotate(90deg);
    }

    .time-selects select:first-child,
    .time-selects select:nth-of-type(2),
    .time-selects select:last-child {
        flex: 1;
        width: auto;
    }

    .import-modal {
        width: 95%;
        max-height: calc(100vh - 24px);
        border-radius: 17px;
    }

    .import-modal .modal-header {
        padding: 18px;
    }

    .import-modal form {
        padding: 18px;
    }

    .import-selected {
        grid-template-columns: 1fr;
    }

    .import-modal-actions {
        flex-direction: column-reverse;
    }

    .import-modal-actions button {
        width: 100%;
    }
}
</style>
</head>

<body>

<div class="overlay" id="overlay"></div>

<aside class="sidebar" id="sidebar">

    <div class="sidebar-header">
        <h2>ESMS</h2>

        <button
            type="button"
            id="closeSidebar"
            aria-label="Close menu">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <ul class="menu">

        <li>
            <a href="dashboard.php">
                <i class="fa-solid fa-house"></i>
                <span>Dashboard</span>
            </a>
        </li>

        <li>
            <a href="students.php">
                <i class="fa-solid fa-user-graduate"></i>
                <span>Students</span>
            </a>
        </li>

        <li>
            <a href="halls.php">
                <i class="fa-solid fa-school"></i>
                <span>Examination Halls</span>
            </a>
        </li>

        <li class="active">
            <a href="examinations.php">
                <i class="fa-solid fa-calendar-days"></i>
                <span>Examination Sessions</span>
            </a>
        </li>

        <li>
            <a href="seat_allocation.php">
                <i class="fa-solid fa-chair"></i>
                <span>Seat Allocation</span>
            </a>
        </li>

        <li>
            <a href="attendance.php">
                <i class="fa-solid fa-clipboard-check"></i>
                <span>Attendance</span>
            </a>
        </li>

        <li>
            <a href="reports.php">
                <i class="fa-solid fa-chart-column"></i>
                <span>Reports</span>
            </a>
        </li>

        <li>
            <a href="settings.php">
                <i class="fa-solid fa-gear"></i>
                <span>Settings</span>
            </a>
        </li>

        <li class="logout">
            <a href="../auth/logout.php">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Logout</span>
            </a>
        </li>

    </ul>
</aside>

<main class="main-content">

<section class="page-container">

<?php if ($message !== ""): ?>

    <div class="flash-message <?= $messageType === "error" ? "error" : "success" ?>">

        <div class="flash-icon">
            <i class="fa-solid <?= $messageType === "error" ? "fa-circle-exclamation" : "fa-circle-check" ?>"></i>
        </div>

        <div class="flash-content">
            <?= clean($message) ?>
        </div>

        <button
            type="button"
            class="flash-close"
            aria-label="Close message"
            onclick="this.parentElement.remove()">
            <i class="fa-solid fa-xmark"></i>
        </button>

    </div>

<?php endif; ?>

<?php if ($pageMode === "departments"): ?>

<div class="page-header">

    <div class="header-left">

        <div class="breadcrumb">

            <button
                type="button"
                class="menu-button"
                id="openSidebar"
                title="Open Menu">
                <i class="fa-solid fa-bars"></i>
            </button>

            <a href="dashboard.php">
                <i class="fa-solid fa-house"></i>
                Dashboard
            </a>

            <span>/</span>
            <span>Examinations</span>

        </div>

        <div class="page-title-row">
            <div>
                <h1>Examinations</h1>
                <p>
                    Select a department to view its assigned semesters and examination timetable.
                </p>
            </div>
        </div>

    </div>

</div>

<div class="section-heading">

    <div>
        <h2>Select Department</h2>
        <p>
            Departments are displayed from students currently assigned to them.
        </p>
    </div>

    <span class="count-badge">
        <?= count($departments) ?> Departments
    </span>

</div>

<?php if (empty($departments)): ?>

<div class="empty-state large">

    <div class="empty-icon">
        <i class="fa-solid fa-building-columns"></i>
    </div>

    <h3>No Departments Available</h3>

    <p>
        Add students with a department assignment before managing examinations.
    </p>

    <a href="students.php" class="primary-button">
        <i class="fa-solid fa-user-graduate"></i>
        Go to Students
    </a>

</div>

<?php else: ?>

<div class="department-grid">

<?php foreach ($departments as $department): ?>

<article class="department-card">

    <div class="department-card-top">

        <div class="department-icon">
            <i class="fa-solid fa-building-columns"></i>
        </div>

        <span class="department-code">
            <?= clean($department["department_code"]) ?>
        </span>

    </div>

    <div class="department-card-body">

        <h3>
            <?= clean($department["department_name"]) ?>
        </h3>

        <div class="student-count">
            <i class="fa-solid fa-user-graduate"></i>
            <span>
                <?= (int)$department["total_students"] ?>
                Students
            </span>
        </div>

    </div>

    <a
        href="examinations.php?department=<?= (int)$department["id"] ?>"
        class="view-button">

        <span>View Department</span>
        <i class="fa-solid fa-arrow-right"></i>

    </a>

</article>

<?php endforeach; ?>

</div>

<?php endif; ?>

<?php elseif ($pageMode === "semesters"): ?>

<div class="page-header">

    <div class="header-left">

        <div class="breadcrumb">

            <button
                type="button"
                class="menu-button"
                id="openSidebar"
                title="Open Menu">
                <i class="fa-solid fa-bars"></i>
            </button>

            <a href="dashboard.php">
                <i class="fa-solid fa-house"></i>
                Dashboard
            </a>

            <span>/</span>

            <a href="examinations.php">
                Examinations
            </a>

            <span>/</span>

            <span>
                <?= clean($selectedDepartment["department_code"]) ?>
            </span>

        </div>

        <div class="page-title-row">
            <div>

                <h1>
                    <?= clean($selectedDepartment["department_name"]) ?>
                </h1>

                <p>
                    Select the semester currently assigned to students for each year.
                </p>

            </div>
        </div>

    </div>

    <a
        href="examinations.php"
        class="secondary-button">

        <i class="fa-solid fa-arrow-left"></i>
        Departments

    </a>

</div>

<div class="section-heading">

    <div>
        <h2>Assigned Semesters</h2>
        <p>
            Only semesters that currently contain students are displayed.
        </p>
    </div>

</div>

<?php if (empty($yearGroups)): ?>

<div class="empty-state large">

    <div class="empty-icon">
        <i class="fa-solid fa-layer-group"></i>
    </div>

    <h3>No Assigned Semesters</h3>

    <p>
        There are no students with a valid academic year and semester assignment in this department.
    </p>

</div>

<?php else: ?>

<div class="year-list">

<?php foreach ($yearGroups as $yearGroup): ?>

<section class="year-card">

    <div class="year-card-header">

        <div class="year-heading">

            <div class="year-icon">
                <i class="fa-solid fa-graduation-cap"></i>
            </div>

            <div>

                <h3>
                    <?= clean($yearGroup["year_name"]) ?>
                </h3>

                <p>
                    Currently assigned semester<?= count($yearGroup["semesters"]) > 1 ? "s" : "" ?>
                </p>

            </div>

        </div>

        <span class="semester-count">
            <?= count($yearGroup["semesters"]) ?>
            <?= count($yearGroup["semesters"]) === 1 ? "Semester" : "Semesters" ?>
        </span>

    </div>

    <div class="semester-grid">

    <?php foreach ($yearGroup["semesters"] as $semester): ?>

    <article class="semester-card">

        <div class="semester-number">
            <?= (int)$semester["semester_number"] ?>
        </div>

        <div class="semester-content">

            <span class="semester-label">
                <?= clean($semester["semester_name"]) ?>
            </span>

            <strong>
                <?= (int)$semester["total_students"] ?>
                Students
            </strong>

        </div>

        <a
            href="examinations.php?department=<?= (int)$departmentId ?>&semester=<?= (int)$semester["semester_id"] ?>"
            class="semester-view-button">

            <span>View</span>
            <i class="fa-solid fa-arrow-right"></i>

        </a>

    </article>

    <?php endforeach; ?>

    </div>

</section>

<?php endforeach; ?>

</div>

<?php endif; ?>

<?php else: ?>

<div class="page-header timetable-header">

    <div class="header-left">

        <div class="breadcrumb">

            <button
                type="button"
                class="menu-button"
                id="openSidebar"
                title="Open Menu">

                <i class="fa-solid fa-bars"></i>

            </button>

            <a href="dashboard.php">
                <i class="fa-solid fa-house"></i>
                Dashboard
            </a>

            <span>/</span>

            <a href="examinations.php">
                Examinations
            </a>

            <span>/</span>

            <a href="examinations.php?department=<?= (int)$departmentId ?>">
                <?= clean($selectedDepartment["department_code"]) ?>
            </a>

            <span>/</span>

            <span>
                <?= clean($selectedSemester["semester_name"]) ?>
            </span>

        </div>

        <div class="page-title-row">

            <div>

                <h1>Examination Timetable</h1>

                <p>
                    <?= clean($selectedDepartment["department_name"]) ?>
                    &nbsp;•&nbsp;
                    <?= clean($selectedSemester["year_name"]) ?>
                    &nbsp;•&nbsp;
                    <?= clean($selectedSemester["semester_name"]) ?>
                </p>

            </div>

        </div>

    </div>

    <a
        href="examinations.php?department=<?= (int)$departmentId ?>"
        class="secondary-button">

        <i class="fa-solid fa-arrow-left"></i>
        Semesters

    </a>

</div>

<div class="action-toolbar">

    <div class="toolbar-title">

        <h2>Examination Papers</h2>

        <p>
            Manage the timetable for the selected semester.
        </p>

    </div>

    <div class="toolbar-actions">

        <button
            type="button"
            class="primary-button"
            id="openAddModal">

            <i class="fa-solid fa-plus"></i>
            Add Examination

        </button>

        <button
            type="button"
            class="secondary-button import-button"
            id="openImportModal">

            <i class="fa-solid fa-file-import"></i>
            Import Excel

        </button>

        <a
            href="examinations.php?department=<?= (int)$departmentId ?>&semester=<?= (int)$semesterId ?>&export=excel"
            class="secondary-button export-button">

            <i class="fa-solid fa-file-excel"></i>
            Export Excel

        </a>

        <button
            type="button"
            class="secondary-button print-button"
            id="printTimetable">

            <i class="fa-solid fa-print"></i>
            Print

        </button>

        <form
            method="POST"
            class="delete-all-form"
            onsubmit="return confirm('Are you sure you want to delete ALL examinations from this timetable? This action cannot be undone.');">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= clean($csrfToken) ?>">

            <input
                type="hidden"
                name="action"
                value="delete_all_examinations">

            <input
                type="hidden"
                name="department_id"
                value="<?= (int)$departmentId ?>">

            <input
                type="hidden"
                name="semester_id"
                value="<?= (int)$semesterId ?>">

            <button
                type="submit"
                class="secondary-button delete-all-button"
                <?= empty($examinations) ? "disabled" : "" ?>>

                <i class="fa-solid fa-trash-can"></i>
                Delete All

            </button>

        </form>

    </div>

</div>

<div class="search-card">

    <form
        method="GET"
        action="examinations.php">

        <input
            type="hidden"
            name="department"
            value="<?= (int)$departmentId ?>">

        <input
            type="hidden"
            name="semester"
            value="<?= (int)$semesterId ?>">

        <div class="search-input-wrapper">

            <i class="fa-solid fa-magnifying-glass"></i>

            <input
                type="search"
                name="search"
                value="<?= clean($search) ?>"
                placeholder="Search subject name or subject code...">

            <?php if ($search !== ""): ?>

                <a
                    href="examinations.php?department=<?= (int)$departmentId ?>&semester=<?= (int)$semesterId ?>"
                    class="clear-search">

                    <i class="fa-solid fa-xmark"></i>

                </a>

            <?php endif; ?>

        </div>

        <button
            type="submit"
            class="search-button">

            Search

        </button>

    </form>

</div>

<div class="print-header">

    <div class="print-title">
        EXAMINATION TIME TABLE
    </div>

    <div class="print-meta">

        <strong>
            <?= clean($selectedDepartment["department_name"]) ?>
        </strong>

        <span>
            <?= clean($selectedSemester["year_name"]) ?>
            &nbsp;•&nbsp;
            <?= clean($selectedSemester["semester_name"]) ?>
        </span>

    </div>

</div>

<div class="table-card">

<div class="table-responsive">

<table class="examination-table">

<thead>

<tr>
    <th>Semester</th>
    <th>Date</th>
    <th>Time</th>
    <th>Subject Code</th>
    <th>Subject Name</th>
    <th>Duration</th>
    <th class="action-column">Actions</th>
</tr>

</thead>

<tbody>

<?php if (empty($examinations)): ?>

<tr>

<td
    colspan="7"
    class="table-empty">

    <div class="empty-state">

        <div class="empty-icon">
            <i class="fa-regular fa-calendar-xmark"></i>
        </div>

        <h3>
            <?= $search !== ""
                ? "No Matching Examinations"
                : "No Examinations Added" ?>
        </h3>

        <p>
            <?= $search !== ""
                ? "Try a different subject name or subject code."
                : "Add an examination or import the timetable from Excel." ?>
        </p>

        <?php if ($search === ""): ?>

        <button
            type="button"
            class="primary-button"
            id="openAddModalEmpty">

            <i class="fa-solid fa-plus"></i>
            Add Examination

        </button>

        <?php endif; ?>

    </div>

</td>

</tr>

<?php else: ?>

<?php foreach ($examinations as $exam): ?>

<tr>

<td>

    <span class="semester-value">
        <?= clean($selectedSemester["semester_name"] ?? "—") ?>
    </span>

</td>

<td>

    <span class="date-value">

    <?php
    $dateTimestamp = !empty($exam["exam_date"])
        ? strtotime($exam["exam_date"])
        : false;

    echo $dateTimestamp !== false
        ? clean(date("d-m-Y", $dateTimestamp))
        : "—";
    ?>

    </span>

</td>

<td>

    <span class="time-value">

        <i class="fa-regular fa-clock"></i>

        <?= clean(
            formatTimeRange(
                $exam["exam_time"] ?? "",
                $exam["exam_time_to"] ?? ""
            )
        ) ?>

    </span>

</td>

<td>

    <span class="subject-code">
        <?= clean($exam["subject_code"] ?? "—") ?>
    </span>

</td>

<td>

    <div class="paper-name">

        <span class="paper-icon">
            <i class="fa-solid fa-book-open"></i>
        </span>

        <strong>
            <?= clean($exam["subject_name"] ?? "—") ?>
        </strong>

    </div>

</td>

<td>

    <span class="duration-value">
        <?= clean(
            formatDuration(
                $exam["duration_minutes"] ?? 0
            )
        ) ?>
    </span>

</td>

<td class="action-column">

<div class="row-actions">

<button
    type="button"
    class="icon-button edit-button edit-examination"
    title="Edit Examination"

    data-id="<?= (int)$exam["id"] ?>"

    data-name="<?= clean(
        $exam["subject_name"] ?? ""
    ) ?>"

    data-code="<?= clean(
        $exam["subject_code"] ?? ""
    ) ?>"

    data-date="<?= clean(
        $exam["exam_date"] ?? ""
    ) ?>"

    data-time-from="<?= clean(
        $exam["exam_time"] ?? ""
    ) ?>"

    data-time-to="<?= clean(
        $exam["exam_time_to"] ?? ""
    ) ?>">

    <i class="fa-solid fa-pen"></i>
    <span>Edit</span>

</button>

<!-- =====================================================
     DELETE ONE RECORD
     This form is intentionally separate from import.
====================================================== -->

<form
    method="POST"
    class="delete-form"
    onsubmit="return confirm('Are you sure you want to delete this examination?');">

    <input
        type="hidden"
        name="csrf_token"
        value="<?= clean($csrfToken) ?>">

    <input
        type="hidden"
        name="action"
        value="delete_examination">

    <input
        type="hidden"
        name="exam_id"
        value="<?= (int)$exam["id"] ?>">

    <input
        type="hidden"
        name="department_id"
        value="<?= (int)$departmentId ?>">

    <input
        type="hidden"
        name="semester_id"
        value="<?= (int)$semesterId ?>">

    <button
        type="submit"
        class="icon-button danger"
        title="Delete Examination">

        <i class="fa-solid fa-trash"></i>
        <span>Delete</span>

    </button>

</form>

</div>

</td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>

</table>

</div>

<?php if (!empty($examinations)): ?>

<div class="table-footer">

    <span>
        Showing
        <strong><?= count($examinations) ?></strong>
        examination<?= count($examinations) === 1 ? "" : "s" ?>
    </span>

    <?php if ($search !== ""): ?>

    <span>
        Filtered by:
        <strong><?= clean($search) ?></strong>
    </span>

    <?php endif; ?>

</div>

<?php endif; ?>

</div>

<?php endif; ?>

</section>

</main>

<!-- =========================================================
     ADD / EDIT MODAL
========================================================= -->

<div
    class="modal-overlay"
    id="examinationModal"
    aria-hidden="true">

<div class="modal-card">

<div class="modal-header">

<div>

<h3 id="modalTitle">
    Add Examination
</h3>

<p>
    Add a paper to the selected semester timetable.
</p>

</div>

<button
    type="button"
    class="modal-close"
    id="closeExaminationModal"
    aria-label="Close">

    <i class="fa-solid fa-xmark"></i>

</button>

</div>

<form
    method="POST"
    id="examinationForm"
    autocomplete="off">

<input
    type="hidden"
    name="csrf_token"
    value="<?= clean($csrfToken) ?>">

<input
    type="hidden"
    name="action"
    id="formAction"
    value="add_examination">

<input
    type="hidden"
    name="exam_id"
    id="examId"
    value="0">

<input
    type="hidden"
    name="department_id"
    value="<?= (int)$departmentId ?>">

<input
    type="hidden"
    name="semester_id"
    value="<?= (int)$semesterId ?>">

<div class="modal-selection">

<div>

<span>Department</span>

<strong>
    <?= clean(
        $selectedDepartment["department_code"] ?? ""
    ) ?>
</strong>

</div>

<div>

<span>Semester</span>

<strong>
    <?= clean(
        $selectedSemester["semester_name"] ?? ""
    ) ?>
</strong>

</div>

</div>

<div class="form-grid">

<div class="form-group full-width">

<label for="subjectName">
    Paper Name <span>*</span>
</label>

<div class="input-wrapper">

<i class="fa-solid fa-book-open"></i>

<input
    type="text"
    id="subjectName"
    name="subject_name"
    maxlength="150"
    required
    placeholder="Enter examination paper name">

</div>

</div>

<div class="form-group">

<label for="subjectCode">
    Subject Code <span>*</span>
</label>

<div class="input-wrapper">

<i class="fa-solid fa-hashtag"></i>

<input
    type="text"
    id="subjectCode"
    name="subject_code"
    maxlength="50"
    required
    placeholder="Enter subject code">

</div>

</div>

<div class="form-group">

<label for="examDate">
    Exam Date <span>*</span>
</label>

<div class="input-wrapper">

<i class="fa-regular fa-calendar"></i>

<input
    type="date"
    id="examDate"
    name="exam_date"
    required>

</div>

</div>

<div class="form-group full-width">

<label>
    Exam Time <span>*</span>
</label>

<div class="exam-time-range">

<div class="exam-time-field">

<label>
    <i class="fa-regular fa-clock"></i>
    From
</label>

<div class="exam-time-controls">

<select
    id="examTimeFromHour"
    class="time-hour"
    required
    aria-label="From hour">

<option value="">HH</option>

<?php for ($h = 1; $h <= 12; $h++): ?>

<option value="<?= sprintf("%02d", $h) ?>">
    <?= sprintf("%02d", $h) ?>
</option>

<?php endfor; ?>

</select>

<span class="time-colon">:</span>

<select
    id="examTimeFromMinute"
    class="time-minute"
    required
    aria-label="From minute">

<option value="">MM</option>

<?php for ($m = 0; $m < 60; $m += 5): ?>

<option value="<?= sprintf("%02d", $m) ?>">
    <?= sprintf("%02d", $m) ?>
</option>

<?php endfor; ?>

</select>

<select
    id="examTimeFromPeriod"
    class="time-period"
    required
    aria-label="From AM or PM">

<option value="">AM/PM</option>
<option value="AM">AM</option>
<option value="PM">PM</option>

</select>

</div>

</div>

<div class="time-range-arrow">
    <i class="fa-solid fa-arrow-right"></i>
</div>

<div class="exam-time-field">

<label>
    <i class="fa-regular fa-clock"></i>
    To
</label>

<div class="exam-time-controls">

<select
    id="examTimeToHour"
    class="time-hour"
    required
    aria-label="To hour">

<option value="">HH</option>

<?php for ($h = 1; $h <= 12; $h++): ?>

<option value="<?= sprintf("%02d", $h) ?>">
    <?= sprintf("%02d", $h) ?>
</option>

<?php endfor; ?>

</select>

<span class="time-colon">:</span>

<select
    id="examTimeToMinute"
    class="time-minute"
    required
    aria-label="To minute">

<option value="">MM</option>

<?php for ($m = 0; $m < 60; $m += 5): ?>

<option value="<?= sprintf("%02d", $m) ?>">
    <?= sprintf("%02d", $m) ?>
</option>

<?php endfor; ?>

</select>

<select
    id="examTimeToPeriod"
    class="time-period"
    required
    aria-label="To AM or PM">

<option value="">AM/PM</option>
<option value="AM">AM</option>
<option value="PM">PM</option>

</select>

</div>

</div>

</div>

<input
    type="hidden"
    id="examTime"
    name="exam_time">

<input
    type="hidden"
    id="examTimeTo"
    name="exam_time_to">

<div
    class="time-duration-preview"
    id="timeDurationPreview">
    Select From and To times.
</div>

</div>

</div>

<div class="modal-footer">

<button
    type="button"
    class="secondary-button"
    id="cancelExaminationModal">

    <i class="fa-solid fa-xmark"></i>
    Cancel

</button>

<button
    type="submit"
    class="primary-button">

    <i class="fa-solid fa-floppy-disk"></i>

    <span id="submitText">
        Save Examination
    </span>

</button>

</div>

</form>

</div>

</div>

<!-- =========================================================
     IMPORT MODAL
========================================================= -->

<div
    class="modal-overlay"
    id="importModal"
    aria-hidden="true">

<div class="import-modal">

<div class="modal-header">

<div>

<div class="import-modal-icon">
    <i class="fa-solid fa-file-excel"></i>
</div>

<h2>
    Import Examination Timetable
</h2>

<p>
    Import the timetable for the selected department and semester.
</p>

</div>

<button
    type="button"
    class="import-modal-close"
    id="closeImportModal"
    aria-label="Close import modal">

    <i class="fa-solid fa-xmark"></i>

</button>

</div>

<form
    method="POST"
    action="examinations.php"
    enctype="multipart/form-data"
    id="importForm">

<input
    type="hidden"
    name="csrf_token"
    value="<?= clean($csrfToken) ?>">

<input
    type="hidden"
    name="action"
    value="import_examinations">

<input
    type="hidden"
    name="department_id"
    value="<?= (int)$departmentId ?>">

<input
    type="hidden"
    name="semester_id"
    value="<?= (int)$semesterId ?>">

<div class="import-selected">

<div>

<span>Department</span>

<strong>
    <?= clean(
        $selectedDepartment["department_code"] ?? ""
    ) ?>
</strong>

</div>

<div>

<span>Semester</span>

<strong>
    <?= clean(
        $selectedSemester["semester_name"] ?? ""
    ) ?>
</strong>

</div>

</div>




<div class="import-file-area">

<label for="excelFile">

<i class="fa-solid fa-file-arrow-up"></i>
Select Excel File

</label>

<input
    type="file"
    id="excelFile"
    name="excel_file"
    accept=".xlsx,.xls"
    required>

<span
    class="selected-file-name"
    id="selectedFileName">
    No file selected
</span>

</div>

<div class="import-modal-actions">

<button
    type="button"
    class="secondary-button"
    id="cancelImportModal">

    <i class="fa-solid fa-xmark"></i>
    Cancel

</button>

<button
    type="submit"
    class="primary-button import-submit-button">

    <i class="fa-solid fa-upload"></i>
    Import Examinations

</button>

</div>

</form>

</div>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    /* =====================================================
       SIDEBAR
    ===================================================== */

    const sidebar = document.getElementById("sidebar");
    const overlay = document.getElementById("overlay");

    function showSidebar() {
        if (sidebar) sidebar.classList.add("show");
        if (overlay) overlay.classList.add("show");
        document.body.classList.add("sidebar-open");
    }

    function hideSidebar() {
        if (sidebar) sidebar.classList.remove("show");
        if (overlay) overlay.classList.remove("show");
        document.body.classList.remove("sidebar-open");
    }

    document.querySelectorAll("#openSidebar").forEach(function (button) {
        button.addEventListener("click", showSidebar);
    });

    const closeSidebar = document.getElementById("closeSidebar");

    if (closeSidebar) {
        closeSidebar.addEventListener("click", hideSidebar);
    }

    if (overlay) {
        overlay.addEventListener("click", hideSidebar);
    }

    /* =====================================================
       MODALS
    ===================================================== */

    const examinationModal =
        document.getElementById("examinationModal");

    const importModal =
        document.getElementById("importModal");

    const examinationForm =
        document.getElementById("examinationForm");

    const importForm =
        document.getElementById("importForm");

    const formAction =
        document.getElementById("formAction");

    const examId =
        document.getElementById("examId");

    const modalTitle =
        document.getElementById("modalTitle");

    const submitText =
        document.getElementById("submitText");

    const subjectName =
        document.getElementById("subjectName");

    const subjectCode =
        document.getElementById("subjectCode");

    const examDate =
        document.getElementById("examDate");

    const examTime =
        document.getElementById("examTime");

    const examTimeTo =
        document.getElementById("examTimeTo");

    const durationPreview =
        document.getElementById("timeDurationPreview");

    /* =====================================================
       TIME SELECTS
    ===================================================== */

    const fromHour =
        document.getElementById("examTimeFromHour");

    const fromMinute =
        document.getElementById("examTimeFromMinute");

    const fromPeriod =
        document.getElementById("examTimeFromPeriod");

    const toHour =
        document.getElementById("examTimeToHour");

    const toMinute =
        document.getElementById("examTimeToMinute");

    const toPeriod =
        document.getElementById("examTimeToPeriod");

    function convert12To24(hour, minute, period) {

        let h = parseInt(hour, 10);

        if (isNaN(h)) {
            return "";
        }

        if (period === "AM") {
            if (h === 12) h = 0;
        } else {
            if (h !== 12) h += 12;
        }

        return String(h).padStart(2, "0") +
            ":" +
            String(minute).padStart(2, "0") +
            ":00";
    }

    function timeMinutes(value) {

        if (!value) {
            return -1;
        }

        const parts = value.split(":");

        if (parts.length < 2) {
            return -1;
        }

        return (
            parseInt(parts[0], 10) * 60
        ) + parseInt(parts[1], 10);
    }

    function formatDurationClient(minutes) {

        if (minutes <= 0) {
            return "";
        }

        const hours =
            Math.floor(minutes / 60);

        const mins =
            minutes % 60;

        if (hours > 0 && mins > 0) {
            return hours + " Hr " + mins + " Min";
        }

        if (hours > 0) {
            return hours +
                (hours === 1 ? " Hour" : " Hours");
        }

        return minutes + " Minutes";
    }

    function updateTimeHiddenFields() {

        let from = "";
        let to = "";

        if (
            fromHour &&
            fromMinute &&
            fromPeriod &&
            fromHour.value &&
            fromMinute.value &&
            fromPeriod.value
        ) {
            from = convert12To24(
                fromHour.value,
                fromMinute.value,
                fromPeriod.value
            );
        }

        if (
            toHour &&
            toMinute &&
            toPeriod &&
            toHour.value &&
            toMinute.value &&
            toPeriod.value
        ) {
            to = convert12To24(
                toHour.value,
                toMinute.value,
                toPeriod.value
            );
        }

        if (examTime) {
            examTime.value = from;
        }

        if (examTimeTo) {
            examTimeTo.value = to;
        }

        if (durationPreview) {

            const fromMinutes =
                timeMinutes(from);

            const toMinutes =
                timeMinutes(to);

            if (
                fromMinutes >= 0 &&
                toMinutes > fromMinutes
            ) {
                durationPreview.textContent =
                    "Duration: " +
                    formatDurationClient(
                        toMinutes - fromMinutes
                    );
            } else if (
                fromMinutes >= 0 &&
                toMinutes >= 0
            ) {
                durationPreview.textContent =
                    "The To time must be later than the From time.";
            } else {
                durationPreview.textContent =
                    "Select From and To times.";
            }
        }
    }

    [
        fromHour,
        fromMinute,
        fromPeriod,
        toHour,
        toMinute,
        toPeriod
    ].forEach(function (element) {

        if (element) {
            element.addEventListener(
                "change",
                updateTimeHiddenFields
            );
        }

    });

    function setTimeSelects(
        value,
        hourSelect,
        minuteSelect,
        periodSelect
    ) {

        if (
            !value ||
            !hourSelect ||
            !minuteSelect ||
            !periodSelect
        ) {
            return;
        }

        const parts =
            String(value).split(":");

        let hour =
            parseInt(parts[0], 10);

        let minute =
            parseInt(parts[1] || "0", 10);

        if (isNaN(hour)) {
            return;
        }

        if (isNaN(minute)) {
            minute = 0;
        }

        const period =
            hour >= 12 ? "PM" : "AM";

        hour = hour % 12;

        if (hour === 0) {
            hour = 12;
        }

        /*
         * The form uses 5-minute increments.
         * Round imported DB values to the nearest available
         * minute option.
         */
        minute =
            Math.round(minute / 5) * 5;

        if (minute >= 60) {
            minute = 55;
        }

        hourSelect.value =
            String(hour).padStart(2, "0");

        minuteSelect.value =
            String(minute).padStart(2, "0");

        periodSelect.value =
            period;
    }

    function clearTimeSelects() {

        [
            fromHour,
            fromMinute,
            fromPeriod,
            toHour,
            toMinute,
            toPeriod
        ].forEach(function (element) {

            if (element) {
                element.value = "";
            }

        });

        updateTimeHiddenFields();
    }

    /* =====================================================
       EXAMINATION MODAL OPEN / CLOSE
    ===================================================== */

    function openExaminationModal() {

        if (!examinationModal) {
            return;
        }

        if (examinationForm) {
            examinationForm.reset();
        }

        if (formAction) {
            formAction.value =
                "add_examination";
        }

        if (examId) {
            examId.value = "0";
        }

        if (modalTitle) {
            modalTitle.textContent =
                "Add Examination";
        }

        if (submitText) {
            submitText.textContent =
                "Save Examination";
        }

        clearTimeSelects();

        examinationModal.classList.add("show");
        examinationModal.setAttribute(
            "aria-hidden",
            "false"
        );

        document.body.classList.add("modal-open");

        setTimeout(function () {

            if (subjectName) {
                subjectName.focus();
            }

        }, 100);
    }

    function closeExaminationModal() {

        if (!examinationModal) {
            return;
        }

        examinationModal.classList.remove("show");
        examinationModal.setAttribute(
            "aria-hidden",
            "true"
        );

        document.body.classList.remove("modal-open");
    }

    const openAddModal =
        document.getElementById("openAddModal");

    const openAddModalEmpty =
        document.getElementById("openAddModalEmpty");

    const closeExamButton =
        document.getElementById("closeExaminationModal");

    const cancelExamButton =
        document.getElementById("cancelExaminationModal");

    if (openAddModal) {
        openAddModal.addEventListener(
            "click",
            openExaminationModal
        );
    }

    if (openAddModalEmpty) {
        openAddModalEmpty.addEventListener(
            "click",
            openExaminationModal
        );
    }

    if (closeExamButton) {
        closeExamButton.addEventListener(
            "click",
            closeExaminationModal
        );
    }

    if (cancelExamButton) {
        cancelExamButton.addEventListener(
            "click",
            closeExaminationModal
        );
    }

    /* =====================================================
       EDIT
    ===================================================== */

    document
        .querySelectorAll(".edit-examination")
        .forEach(function (button) {

            button.addEventListener(
                "click",
                function () {

                    if (formAction) {
                        formAction.value =
                            "update_examination";
                    }

                    if (examId) {
                        examId.value =
                            button.dataset.id || "0";
                    }

                    if (subjectName) {
                        subjectName.value =
                            button.dataset.name || "";
                    }

                    if (subjectCode) {
                        subjectCode.value =
                            button.dataset.code || "";
                    }

                    if (examDate) {
                        examDate.value =
                            button.dataset.date || "";
                    }

                    setTimeSelects(
                        button.dataset.timeFrom || "",
                        fromHour,
                        fromMinute,
                        fromPeriod
                    );

                    setTimeSelects(
                        button.dataset.timeTo || "",
                        toHour,
                        toMinute,
                        toPeriod
                    );

                    updateTimeHiddenFields();

                    if (modalTitle) {
                        modalTitle.textContent =
                            "Edit Examination";
                    }

                    if (submitText) {
                        submitText.textContent =
                            "Update Examination";
                    }

                    if (examinationModal) {
                        examinationModal.classList.add("show");
                        examinationModal.setAttribute(
                            "aria-hidden",
                            "false"
                        );
                    }

                    document.body.classList.add(
                        "modal-open"
                    );

                    setTimeout(function () {

                        if (subjectName) {
                            subjectName.focus();
                        }

                    }, 100);
                }
            );

        });

    /* =====================================================
       EXAMINATION FORM VALIDATION
    ===================================================== */

    if (examinationForm) {

        examinationForm.addEventListener(
            "submit",
            function (event) {

                updateTimeHiddenFields();

                const from =
                    examTime ? examTime.value : "";

                const to =
                    examTimeTo ? examTimeTo.value : "";

                const fromMinutes =
                    timeMinutes(from);

                const toMinutes =
                    timeMinutes(to);

                if (
                    !from ||
                    !to ||
                    fromMinutes < 0 ||
                    toMinutes <= fromMinutes
                ) {
                    event.preventDefault();

                    alert(
                        "The To time must be later than the From time."
                    );

                    return;
                }
            }
        );
    }

    /* =====================================================
       IMPORT MODAL OPEN / CLOSE
    ===================================================== */

    function openImportModal() {

        if (!importModal) {
            return;
        }

        importModal.classList.add("show");
        importModal.setAttribute(
            "aria-hidden",
            "false"
        );

        document.body.classList.add("modal-open");

        setTimeout(function () {

            const file =
                document.getElementById("excelFile");

            if (file) {
                file.focus();
            }

        }, 100);
    }

    function closeImportModal() {

        if (!importModal) {
            return;
        }

        importModal.classList.remove("show");
        importModal.setAttribute(
            "aria-hidden",
            "true"
        );

        document.body.classList.remove("modal-open");
    }

    const openImportButton =
        document.getElementById("openImportModal");

    const closeImportButton =
        document.getElementById("closeImportModal");

    const cancelImportButton =
        document.getElementById("cancelImportModal");

    if (openImportButton) {
        openImportButton.addEventListener(
            "click",
            openImportModal
        );
    }

    if (closeImportButton) {
        closeImportButton.addEventListener(
            "click",
            closeImportModal
        );
    }

    if (cancelImportButton) {
        cancelImportButton.addEventListener(
            "click",
            closeImportModal
        );
    }

    /* =====================================================
       MODAL BACKDROP CLICK
    ===================================================== */

    if (examinationModal) {

        examinationModal.addEventListener(
            "click",
            function (event) {

                if (
                    event.target === examinationModal
                ) {
                    closeExaminationModal();
                }

            }
        );
    }

    if (importModal) {

        importModal.addEventListener(
            "click",
            function (event) {

                if (
                    event.target === importModal
                ) {
                    closeImportModal();
                }

            }
        );
    }

    /* =====================================================
       ESC KEY
    ===================================================== */

    document.addEventListener(
        "keydown",
        function (event) {

            if (event.key === "Escape") {
                closeExaminationModal();
                closeImportModal();
                hideSidebar();
            }

        }
    );

    /* =====================================================
       FILE NAME
    ===================================================== */

    const excelFile =
        document.getElementById("excelFile");

    const selectedFileName =
        document.getElementById("selectedFileName");

    if (excelFile) {

        excelFile.addEventListener(
            "change",
            function () {

                if (!excelFile.files.length) {

                    if (selectedFileName) {
                        selectedFileName.textContent =
                            "No file selected";
                    }

                    return;
                }

                const file =
                    excelFile.files[0];

                const name =
                    file.name.toLowerCase();

                if (
                    !name.endsWith(".xlsx") &&
                    !name.endsWith(".xls")
                ) {

                    alert(
                        "Please select a valid Excel file (.xlsx or .xls)."
                    );

                    excelFile.value = "";

                    if (selectedFileName) {
                        selectedFileName.textContent =
                            "No file selected";
                    }

                    return;
                }

                if (selectedFileName) {
                    selectedFileName.textContent =
                        file.name;
                }
            }
        );
    }

    /* =====================================================
       IMPORT FORM
    ===================================================== */

    if (importForm) {

        importForm.addEventListener(
            "submit",
            function (event) {

                if (
                    !excelFile ||
                    !excelFile.files.length
                ) {
                    event.preventDefault();

                    alert(
                        "Please select an Excel file."
                    );

                    return;
                }

                const fileName =
                    excelFile.files[0]
                        .name
                        .toLowerCase();

                if (
                    !fileName.endsWith(".xlsx") &&
                    !fileName.endsWith(".xls")
                ) {
                    event.preventDefault();

                    alert(
                        "Only .xlsx and .xls files are supported."
                    );
                }

            }
        );
    }

    /* =====================================================
       PRINT
    ===================================================== */

    const printTimetable =
        document.getElementById("printTimetable");

    if (printTimetable) {

        printTimetable.addEventListener(
            "click",
            function () {
                window.print();
            }
        );
    }

});
</script>

</body>
</html>