<?php

/* =========================================================
   EXAMINATION SEATING MANAGEMENT SYSTEM
   SEAT ALLOCATION MODULE

   Independent Seat Allocation

   Registered Students/PINs
          ↓
   Student Group Selection
          ↓
   Hall Selection
          ↓
   Seating Pattern
          ↓
   Constraint-Based Algorithm
          ↓
   Automatic Allocation
          ↓
   Preview
          ↓
   Confirm

   IMPORTANT:
   Seat allocation uses only date/time to prevent overlapping hall usage.
   Fundamental allocation:

   PIN → Student → Hall → Row → Column
   ========================================================= */

session_start();

/* =========================================================
   AJAX ERROR SAFETY
   =========================================================
   Generate requests must always return JSON, even when PHP throws
   a warning, TypeError, or fatal runtime error.
   ========================================================= */

$isSeatAllocationAjax = isset($_POST["action"]);

if ($isSeatAllocationAjax) {
    @ini_set("display_errors", "0");
    @ini_set("log_errors", "1");
    @ini_set("memory_limit", "512M");
    @set_time_limit(120);
    mysqli_report(MYSQLI_REPORT_OFF);

    set_error_handler(function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(500);
        header("Content-Type: application/json; charset=UTF-8");

        echo json_encode([
            "success" => false,
            "message" => "PHP error while processing the seating allocation.",
            "error" => $message,
            "error_file" => basename($file),
            "error_line" => $line
        ]);
        exit;
    });

    register_shutdown_function(function () {
        $error = error_get_last();

        if (!$error) {
            return;
        }

        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR
        ];

        if (!in_array($error["type"], $fatalTypes, true)) {
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(500);
        header("Content-Type: application/json; charset=UTF-8");

        echo json_encode([
            "success" => false,
            "message" => "PHP runtime error while generating the seating arrangement.",
            "error" => $error["message"],
            "error_file" => basename($error["file"]),
            "error_line" => $error["line"]
        ]);
    });
}

/* =========================================================
   CACHE CONTROL
========================================================= */

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

/* =========================================================
   DATABASE
========================================================= */

require_once "../config/db_connect.php";


/* =========================================================
   PER-COLUMN BENCH CONFIGURATION
========================================================= */

function ensureHallColumnBenchColumnForAllocation($conn)
{
    $check = $conn->query("SHOW COLUMNS FROM halls LIKE 'column_benches'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE halls ADD COLUMN column_benches JSON NULL AFTER columns_count");
    }
    if ($check) $check->free();
}

function normalizeAllocationColumnBenches($rows, $columns, $values = null)
{
    $rows = max(1, (int)$rows);
    $columns = max(1, (int)$columns);
    $result = [];
    if (is_array($values)) {
        for ($i = 0; $i < $columns; $i++) {
            $result[] = max(1, min(100, isset($values[$i]) ? (int)$values[$i] : $rows));
        }
    } else {
        for ($i = 0; $i < $columns; $i++) $result[] = $rows;
    }
    return $result;
}

function allocationHallBenchTotal($columnBenches)
{
    return is_array($columnBenches) ? array_sum(array_map('intval', $columnBenches)) : 0;
}

ensureHallColumnBenchColumnForAllocation($conn);

/* =========================================================
   ADMIN AUTHENTICATION
========================================================= */

if (!isset($_SESSION["admin"])) {
    header("Location: ../auth/login.php");
    exit;
}

/* =========================================================
   HELPERS
========================================================= */

function clean($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function jsonResponse($data)
{
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($data);
    exit;
}

/* =========================================================
   ENSURE ALLOCATION TABLES
========================================================= */

function ensureAllocationTables($conn)
{
    $sql1 = "
        CREATE TABLE IF NOT EXISTS allocation_batches (
            id INT NOT NULL AUTO_INCREMENT,
            exam_date DATE NULL,
            exam_time TIME NULL,
            exam_time_to TIME NULL,
            allocation_code VARCHAR(50) NOT NULL,
            pattern_type VARCHAR(50) NOT NULL,
            pattern_settings JSON NULL,
            total_students INT NOT NULL DEFAULT 0,
            total_halls INT NOT NULL DEFAULT 0,
            total_capacity INT NOT NULL DEFAULT 0,
            status ENUM('preview','confirmed','cancelled')
                NOT NULL DEFAULT 'preview',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            confirmed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_allocation_code (allocation_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    if (!mysqli_query($conn, $sql1)) {
        return false;
    }

    $sql2 = "
        CREATE TABLE IF NOT EXISTS allocation_seats (
            id INT NOT NULL AUTO_INCREMENT,
            allocation_id INT NOT NULL,
            student_id INT NOT NULL,
            hall_id INT NOT NULL,
            row_number INT NOT NULL,
            column_number INT NOT NULL,
            bench_slot INT NOT NULL DEFAULT 1,
            seat_number VARCHAR(50) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),

            UNIQUE KEY uq_allocation_student
                (allocation_id, student_id),

            UNIQUE KEY uq_allocation_seat
                (allocation_id, hall_id, row_number, column_number, bench_slot),

            CONSTRAINT fk_allocation_seats_batch
                FOREIGN KEY (allocation_id)
                REFERENCES allocation_batches(id)
                ON DELETE CASCADE
                ON UPDATE CASCADE,

            CONSTRAINT fk_allocation_seats_student
                FOREIGN KEY (student_id)
                REFERENCES students(id)
                ON DELETE CASCADE
                ON UPDATE CASCADE,

            CONSTRAINT fk_allocation_seats_hall
                FOREIGN KEY (hall_id)
                REFERENCES halls(id)
                ON DELETE CASCADE
                ON UPDATE CASCADE

        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    if (!mysqli_query($conn, $sql2)) {
        return false;
    }

    /* ---------------------------------------------------------
       Upgrade older allocation_batches tables with date/time.
    --------------------------------------------------------- */
    $dateColumn = mysqli_query($conn, "SHOW COLUMNS FROM allocation_batches LIKE 'exam_date'");
    if ($dateColumn && mysqli_num_rows($dateColumn) === 0) {
        if (!mysqli_query($conn, "ALTER TABLE allocation_batches ADD COLUMN exam_date DATE NULL AFTER id, ADD COLUMN exam_time TIME NULL AFTER exam_date, ADD COLUMN exam_time_to TIME NULL AFTER exam_time")) {
            return false;
        }
    }

    /* ---------------------------------------------------------
       Upgrade older allocation_seats tables for multi-student
       benches.
    --------------------------------------------------------- */
    $columnCheck = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM allocation_seats LIKE 'bench_slot'"
    );

    if ($columnCheck && mysqli_num_rows($columnCheck) === 0) {
        if (!mysqli_query(
            $conn,
            "ALTER TABLE allocation_seats
             ADD COLUMN bench_slot INT NOT NULL DEFAULT 1 AFTER column_number"
        )) {
            return false;
        }
    }

    /* Replace the old one-student-per-bench unique key. */
    $indexCheck = mysqli_query(
        $conn,
        "SHOW INDEX FROM allocation_seats WHERE Key_name = 'uq_allocation_seat'"
    );

    if ($indexCheck && mysqli_num_rows($indexCheck) > 0) {
        mysqli_query(
            $conn,
            "ALTER TABLE allocation_seats DROP INDEX uq_allocation_seat"
        );
    }

    mysqli_query(
        $conn,
        "ALTER TABLE allocation_seats
         ADD UNIQUE KEY uq_allocation_seat
         (allocation_id, hall_id, row_number, column_number, bench_slot)"
    );

    return true;
}

if (!ensureAllocationTables($conn)) {
    die("Unable to prepare Seat Allocation database tables.");
}

/* =========================================================
   AUTOMATICALLY REMOVE EXPIRED SEAT ALLOCATIONS
   Only changes expired batches to cancelled. Existing seating
   generation and allocation logic remains untouched.
========================================================= */
function cancelExpiredAllocations($conn)
{
    $sql = "
        UPDATE allocation_batches
        SET status = 'cancelled'
        WHERE status = 'confirmed'
          AND exam_date IS NOT NULL
          AND exam_time_to IS NOT NULL
          AND TIMESTAMP(exam_date, exam_time_to) <= NOW()
    ";

    mysqli_query($conn, $sql);
}

cancelExpiredAllocations($conn);

/* =========================================================
   NOTIFICATION
========================================================= */

function addNotification($conn, $title, $message, $type = "seat")
{
    $sql = "
        INSERT INTO notifications
        (title, message, type, is_read)
        VALUES (?, ?, ?, 0)
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt) {

        mysqli_stmt_bind_param(
            $stmt,
            "sss",
            $title,
            $message,
            $type
        );

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

/* =========================================================
   ALLOCATION DATE/TIME + HALL CONFLICT HELPERS
========================================================= */

function validateAllocationDateTime($date, $start, $end)
{
    $date = trim((string)$date);
    $start = trim((string)$start);
    $end = trim((string)$end);
    $d = DateTime::createFromFormat("Y-m-d", $date);
    $s = DateTime::createFromFormat("H:i", substr($start, 0, 5));
    $e = DateTime::createFromFormat("H:i", substr($end, 0, 5));

    if (!$d || $d->format("Y-m-d") !== $date || !$s || !$e) {
        return ["success" => false, "message" => "Please enter a valid allocation date, start time and end time."];
    }
    $start = $s->format("H:i:s");
    $end = $e->format("H:i:s");
    if ($end <= $start) {
        return ["success" => false, "message" => "End time must be later than start time."];
    }
    return ["success" => true, "exam_date" => $date, "exam_time" => $start, "exam_time_to" => $end];
}

function checkHallTimeConflicts($conn, $hallIds, $date, $start, $end)
{
    $hallIds = array_values(array_unique(array_filter(array_map("intval", (array)$hallIds), function ($id) { return $id > 0; })));
    if (!$hallIds) return ["success" => true, "conflicts" => []];

    $placeholders = implode(",", array_fill(0, count($hallIds), "?"));
    $sql = "SELECT DISTINCT h.id AS hall_id, h.hall_name, h.hall_code, ab.exam_date, ab.exam_time, ab.exam_time_to, ab.allocation_code
            FROM allocation_seats als
            INNER JOIN allocation_batches ab ON ab.id = als.allocation_id
            INNER JOIN halls h ON h.id = als.hall_id
            WHERE ab.status = 'confirmed'
              AND ab.exam_date = ?
              AND als.hall_id IN ($placeholders)
              AND ab.exam_time < ?
              AND ab.exam_time_to > ?
            ORDER BY h.hall_name ASC";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return ["success" => false, "message" => "Unable to check hall availability."];
    $types = "s" . str_repeat("i", count($hallIds)) . "ss";
    $params = array_merge([$date], $hallIds, [$end, $start]);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $conflicts = [];
    while ($row = mysqli_fetch_assoc($result)) $conflicts[] = $row;
    mysqli_stmt_close($stmt);
    return ["success" => true, "conflicts" => $conflicts];
}

function hallConflictMessage($conflicts)
{
    $message = "The following hall(s) are already allocated during this time:";
    foreach ($conflicts as $c) {
        $message .= "\n• " . $c["hall_name"] . " (" . $c["hall_code"] . ") — " . date("h:i A", strtotime($c["exam_time"])) . " to " . date("h:i A", strtotime($c["exam_time_to"]));
    }
    return $message . "\nPlease choose another hall or another time.";
}

/* =========================================================
   LOAD STUDENT GROUPS
========================================================= */

$groups = [];

$sql = "
    SELECT
        d.id AS department_id,
        d.department_name,
        d.department_code,

        s.academic_year AS academic_year_id,
        ay.year_name,

        s.semester_id,
        sem.semester_number,
        sem.semester_name,

        COUNT(s.id) AS total_students

    FROM students s

    INNER JOIN departments d
        ON d.id = s.department_id

    LEFT JOIN academic_years ay
        ON ay.id = s.academic_year

    LEFT JOIN semesters sem
        ON sem.id = s.semester_id

    GROUP BY
        d.id,
        d.department_name,
        d.department_code,
        s.academic_year,
        ay.year_name,
        s.semester_id,
        sem.semester_number,
        sem.semester_name

    ORDER BY
        d.department_name ASC,
        s.academic_year ASC,
        sem.semester_number ASC
";

$result = mysqli_query($conn, $sql);

if ($result) {

    while ($row = mysqli_fetch_assoc($result)) {
        $groups[] = $row;
    }
}

/* =========================================================
   LOAD HALLS
========================================================= */

$halls = [];

$sql = "
    SELECT
        id,
        hall_name,
        hall_code,
        rows_count,
        columns_count,
        column_benches,
        capacity,
        floor_no,
        building_name

    FROM halls

    ORDER BY hall_name ASC
";

$result = mysqli_query($conn, $sql);

if ($result) {

    while ($row = mysqli_fetch_assoc($result)) {
        $decoded = json_decode((string)($row["column_benches"] ?? ""), true);
        $row["column_benches_array"] = normalizeAllocationColumnBenches(
            (int)$row["rows_count"],
            (int)$row["columns_count"],
            $decoded
        );
        $halls[] = $row;
    }
}

/* =========================================================
   GET STUDENTS BY GROUPS
========================================================= */

function getStudentsByGroups($conn, $selectedGroups)
{
    if (!is_array($selectedGroups) || !$selectedGroups) {
        return [
            "success" => false,
            "message" => "Please select at least one student group."
        ];
    }

    $where = [];
    $params = [];
    $types = "";

    foreach ($selectedGroups as $key) {

        $parts = explode(":", (string)$key);

        if (count($parts) !== 3) {
            continue;
        }

        $departmentId = (int)$parts[0];
        $academicYearId = (int)$parts[1];
        $semesterId = (int)$parts[2];

        if (
            $departmentId <= 0 ||
            $academicYearId <= 0 ||
            $semesterId <= 0
        ) {
            continue;
        }

        $where[] = "
            (
                s.department_id = ?
                AND s.academic_year = ?
                AND s.semester_id = ?
            )
        ";

        $params[] = $departmentId;
        $params[] = $academicYearId;
        $params[] = $semesterId;

        $types .= "iii";
    }

    if (!$where) {
        return [
            "success" => false,
            "message" => "Invalid student group selection."
        ];
    }

    $sql = "
        SELECT
            s.id,
            s.pin_no,
            s.student_name,

            s.department_id,
            s.academic_year AS academic_year_id,
            s.semester_id,

            d.department_name,
            d.department_code,

            ay.year_name,

            sem.semester_number,
            sem.semester_name

        FROM students s

        INNER JOIN departments d
            ON d.id = s.department_id

        LEFT JOIN academic_years ay
            ON ay.id = s.academic_year

        LEFT JOIN semesters sem
            ON sem.id = s.semester_id

        WHERE " . implode(" OR ", $where) . "

        ORDER BY
            d.department_code ASC,
            s.academic_year ASC,
            sem.semester_number ASC,
            s.pin_no ASC
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return [
            "success" => false,
            "message" => "Unable to prepare student query."
        ];
    }

    mysqli_stmt_bind_param(
        $stmt,
        $types,
        ...$params
    );

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    $students = [];
    $seenIds = [];
    $seenPins = [];
    $duplicatePins = [];

    while ($row = mysqli_fetch_assoc($result)) {

        $studentId = (int)$row["id"];
        $pin = trim((string)$row["pin_no"]);

        if (isset($seenIds[$studentId])) {
            continue;
        }

        if ($pin !== "" && isset($seenPins[$pin])) {

            $duplicatePins[] = $pin;

            /*
             * Do not allocate duplicate PIN records.
             */
            continue;
        }

        $seenIds[$studentId] = true;

        if ($pin !== "") {
            $seenPins[$pin] = true;
        }

        $students[] = [
            "id" => $studentId,
            "pin_no" => $pin,
            "student_name" => (string)$row["student_name"],

            "department_id" => (int)$row["department_id"],
            "academic_year_id" => (int)$row["academic_year_id"],
            "semester_id" => (int)$row["semester_id"],

            "department_name" =>
                (string)$row["department_name"],

            "department_code" =>
                (string)$row["department_code"],

            "year_name" =>
                (string)($row["year_name"] ?? ""),

            "semester_number" =>
                $row["semester_number"],

            "semester_name" =>
                (string)($row["semester_name"] ?? "")
        ];
    }

    mysqli_stmt_close($stmt);

    return [
        "success" => true,
        "students" => $students,
        "total_students" => count($students),
        "duplicate_pins" => array_values(
            array_unique($duplicatePins)
        )
    ];
}

/* =========================================================
   LOAD SELECTED HALLS FROM DATABASE
========================================================= */

function getSelectedHalls($conn, $hallIds)
{
    if (!is_array($hallIds) || !$hallIds) {
        return [];
    }

    $hallIds = array_values(
        array_unique(
            array_filter(
                array_map("intval", $hallIds),
                function ($id) {
                    return $id > 0;
                }
            )
        )
    );

    if (!$hallIds) {
        return [];
    }

    $placeholders = implode(
        ",",
        array_fill(0, count($hallIds), "?")
    );

    $types = str_repeat("i", count($hallIds));

    $sql = "
        SELECT
            id,
            hall_name,
            hall_code,
            rows_count,
            columns_count,
            column_benches,
            capacity,
            floor_no,
            building_name

        FROM halls

        WHERE id IN ($placeholders)

        ORDER BY hall_name ASC
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param(
        $stmt,
        $types,
        ...$hallIds
    );

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    $selectedHalls = [];

    while ($row = mysqli_fetch_assoc($result)) {

        $rows = max(0, (int)$row["rows_count"]);
        $columns = max(0, (int)$row["columns_count"]);
        $decodedColumnBenches = json_decode((string)($row["column_benches"] ?? ""), true);
        $columnBenches = normalizeAllocationColumnBenches($rows, $columns, $decodedColumnBenches);

        $physicalCapacity = allocationHallBenchTotal($columnBenches);

        $dbCapacity =
            max(0, (int)$row["capacity"]);

        /*
         * Physical rows × columns is the real
         * seat structure.
         */
        $capacity = min(
            $physicalCapacity,
            $dbCapacity > 0
                ? $dbCapacity
                : $physicalCapacity
        );

        $selectedHalls[] = [
            "id" => (int)$row["id"],
            "hall_name" => (string)$row["hall_name"],
            "hall_code" => (string)$row["hall_code"],
            "rows_count" => $rows,
            "columns_count" => $columns,
            "column_benches" => $columnBenches,
            "capacity" => $capacity,
            "floor_no" => (string)$row["floor_no"],
            "building_name" => (string)$row["building_name"]
        ];
    }

    mysqli_stmt_close($stmt);

    /*
     * Preserve the exact order selected by admin.
     */
    $ordered = [];

    foreach ($hallIds as $requestedId) {

        foreach ($selectedHalls as $hall) {

            if ($hall["id"] === $requestedId) {
                $ordered[] = $hall;
                break;
            }
        }
    }

    return $ordered;
}

/* =========================================================
   BUILD PHYSICAL SEATS
========================================================= */

function buildPhysicalSeats($hall, $pattern, $settings)
{
    $rows = max(0, (int)($hall["rows_count"] ?? 0));
    $columns = max(0, (int)($hall["columns_count"] ?? 0));
    $columnBenches = normalizeAllocationColumnBenches(
        $rows,
        $columns,
        $hall["column_benches"] ?? null
    );

    $physicalBenches = allocationHallBenchTotal($columnBenches);
    $hallCapacity = max(0, (int)($hall["capacity"] ?? 0));

    if ($physicalBenches <= 0) {
        return [];
    }

    if ($pattern === "customized") {
        $maxCapacity = PHP_INT_MAX;
    } elseif ($pattern === "two_persons") {
        $maxCapacity = $physicalBenches * 2;
        if ($hallCapacity > 0) {
            $maxCapacity = min($maxCapacity, $hallCapacity);
        }
    } else {
        $maxCapacity = $physicalBenches;
        if ($hallCapacity > 0) {
            $maxCapacity = min($maxCapacity, $hallCapacity);
        }
    }

    if ($maxCapacity <= 0 && $pattern !== "customized") {
        return [];
    }

    $seats = [];

    /*
     * Physical bench order is COLUMN-FIRST.
     * Each column uses its own configured number of benches.
     * Example: C1=8, C2=10, C3=9 produces 27 physical benches.
     */
    $benchIndex = 0;

    for ($c = 1; $c <= $columns; $c++) {
        $columnRows = max(0, (int)($columnBenches[$c - 1] ?? $rows));

        for ($r = 1; $r <= $columnRows; $r++) {
            if ($pattern === "single") {
                if (count($seats) >= $maxCapacity) {
                    return $seats;
                }

                $seats[] = ["r" => $r, "c" => $c, "slot" => 1];
            }

            elseif ($pattern === "two_persons") {
                $benchStudents = (($benchIndex % 2) === 0) ? 1 : 2;
                $benchIndex++;

                for ($slot = 1; $slot <= $benchStudents; $slot++) {
                    if (count($seats) >= $maxCapacity) {
                        return $seats;
                    }

                    $seats[] = ["r" => $r, "c" => $c, "slot" => $slot];
                }
            }

            elseif ($pattern === "alternative") {
                $benchIndex++;

                if (($r + $c) % 2 !== 0) {
                    continue;
                }

                if (count($seats) >= $maxCapacity) {
                    return $seats;
                }

                $seats[] = ["r" => $r, "c" => $c, "slot" => 1];
            }

            elseif ($pattern === "customized") {
                $first = max(0, min(100, (int)(
                    $settings["first_bench_students"]
                    ?? $settings["students_first_bench"]
                    ?? $settings["first_bench"]
                    ?? 1
                )));

                $second = max(0, min(100, (int)(
                    $settings["second_bench_students"]
                    ?? $settings["students_second_bench"]
                    ?? $settings["second_bench"]
                    ?? 2
                )));

                $benchStudents = (($benchIndex % 2) === 0) ? $first : $second;
                $benchIndex++;

                for ($slot = 1; $slot <= $benchStudents; $slot++) {
                    $seats[] = ["r" => $r, "c" => $c, "slot" => $slot];
                }
            }
        }
    }

    return $seats;
}

/* =========================================================
   GROUP KEY
========================================================= */

function studentGroupKey($student)
{
    return
        (int)$student["department_id"] .
        ":" .
        (int)$student["academic_year_id"] .
        ":" .
        (int)$student["semester_id"];
}

/* =========================================================
   CONSTRAINT SCORE
========================================================= */

function calculateSeatPenalty(
    $student,
    $hallId,
    $row,
    $column,
    $occupancy
) {
    $penalty = 0;

    for ($dr = -1; $dr <= 1; $dr++) {
        for ($dc = -1; $dc <= 1; $dc++) {

            if ($dr === 0 && $dc === 0) {
                continue;
            }

            $neighborRow = $row + $dr;
            $neighborColumn = $column + $dc;
            $prefix = $hallId . ":" . $neighborRow . ":" . $neighborColumn . ":";

            foreach ($occupancy as $key => $neighbor) {
                if (strpos($key, $prefix) !== 0) {
                    continue;
                }

                if ((int)$neighbor["department_id"] === (int)$student["department_id"]) {
                    $penalty += 100;
                }

                if ((int)$neighbor["academic_year_id"] === (int)$student["academic_year_id"]) {
                    $penalty += 35;
                }

                if ((int)$neighbor["semester_id"] === (int)$student["semester_id"]) {
                    $penalty += 25;
                }

                if (studentGroupKey($neighbor) === studentGroupKey($student)) {
                    $penalty += 50;
                }
            }
        }
    }

    return $penalty;
}

/* =========================================================
   PREVIEW HALL CAPACITY
   Display-only calculation for Automatic Seating Preview.
   Benches = rows × columns.
   Capacity follows the selected pattern:
     single       = 1 student per bench
     two_persons  = alternating 1/2 students per bench
     alternative  = every other bench
     customized   = configured first/second bench counts by row
========================================================= */
function calculatePreviewHallCapacity($rows, $columns, $pattern, $settings, $columnBenches = null)
{
    $rows = max(0, (int)$rows);
    $columns = max(0, (int)$columns);
    $columnBenches = normalizeAllocationColumnBenches($rows, $columns, $columnBenches);
    $benchTotal = allocationHallBenchTotal($columnBenches);

    if ($benchTotal <= 0) {
        return 0;
    }

    if ($pattern === "single") {
        return $benchTotal;
    }

    if ($pattern === "two_persons") {
        return $benchTotal + intdiv($benchTotal, 2);
    }

    if ($pattern === "alternative") {
        return (int)ceil($benchTotal / 2);
    }

    if ($pattern === "customized") {
        $first = max(0, min(100, (int)(
            $settings["first_bench_students"]
            ?? $settings["students_first_bench"]
            ?? $settings["first_bench"]
            ?? 1
        )));

        $second = max(0, min(100, (int)(
            $settings["second_bench_students"]
            ?? $settings["students_second_bench"]
            ?? $settings["second_bench"]
            ?? 2
        )));

        $capacity = 0;
        $benchIndex = 0;

        foreach ($columnBenches as $columnRows) {
            for ($row = 1; $row <= (int)$columnRows; $row++) {
                $capacity += (($benchIndex % 2) === 0) ? $first : $second;
                $benchIndex++;
            }
        }

        return $capacity;
    }

    return $benchTotal;
}

/* =========================================================
   CONSTRAINT-BASED ALLOCATION
========================================================= */

function generateConstraintAllocation(
    $students,
    $halls,
    $pattern,
    $settings,
    $mixGroups = false,
    $separateGroups = false
) {
    if (!$students) {
        return [
            "success" => false,
            "message" => "No students available for allocation."
        ];
    }

    if (!$halls) {
        return [
            "success" => false,
            "message" => "No examination halls selected."
        ];
    }

    /* =========================================================
       BUILD PHYSICAL BENCHES
       ========================================================= */

    $benches = [];
    $totalUsableCapacity = 0;
    $hallOrder = [];

    foreach ($halls as $hallIndex => $hall) {
        $hallOrder[(int)$hall["id"]] = $hallIndex;

        $physicalSeats = buildPhysicalSeats(
            $hall,
            $pattern,
            $settings
        );

        $totalUsableCapacity += count($physicalSeats);

        foreach ($physicalSeats as $seat) {
            $benchKey =
                (int)$hall["id"] . ":" .
                (int)$seat["r"] . ":" .
                (int)$seat["c"];

            if (!isset($benches[$benchKey])) {
                $benches[$benchKey] = [
                    "hall_id" => (int)$hall["id"],
                    "hall_name" => (string)$hall["hall_name"],
                    "hall_code" => (string)$hall["hall_code"],
                    "row" => (int)$seat["r"],
                    "column" => (int)$seat["c"],
                    "slots" => []
                ];
            }

            $benches[$benchKey]["slots"][] =
                (int)($seat["slot"] ?? 1);
        }
    }

    $benches = array_values($benches);

    /*
     * NEVER reorder halls according to hall name here.
     * getSelectedHalls() already returns halls in the exact order
     * selected by the administrator.
     *
     * Within each hall, retain the existing COLUMN-FIRST sequence:
     * C1 R1,R2,R3... then C2 R1,R2,R3...
     */
    usort(
        $benches,
        function ($a, $b) use ($hallOrder) {
            $ha = $hallOrder[(int)$a["hall_id"]] ?? PHP_INT_MAX;
            $hb = $hallOrder[(int)$b["hall_id"]] ?? PHP_INT_MAX;

            if ($ha !== $hb) {
                return $ha <=> $hb;
            }

            if ((int)$a["column"] !== (int)$b["column"]) {
                return (int)$a["column"] <=> (int)$b["column"];
            }

            return (int)$a["row"] <=> (int)$b["row"];
        }
    );

    /* =========================================================
       SINGLE PERSON — STRICT SELECTED BRANCH SEQUENCE
       Example: CSE -> AMT -> EC -> CSE -> AMT -> EC...
       This block is only for Single Person allocation.
    ========================================================= */
    if ($pattern === "single") {

        $singleBranchLanes = [];
        $singleBranchOrder = [];

        /* Build branch order from the administrator's selected groups. */
        foreach ($students as $student) {
            $branchKey = (string)($student["department_id"] ?? 0);

            if (!isset($singleBranchLanes[$branchKey])) {
                $singleBranchLanes[$branchKey] = [];
                $singleBranchOrder[] = $branchKey;
            }

            $singleBranchLanes[$branchKey][] = $student;
        }

        foreach ($singleBranchLanes as &$lane) {
            usort($lane, function ($a, $b) {
                $pinA = (string)($a["pin_no"] ?? "");
                $pinB = (string)($b["pin_no"] ?? "");
                $serialA = 0;
                $serialB = 0;

                if (preg_match('/(\d+)\s*$/', $pinA, $mA)) {
                    $serialA = (int)$mA[1];
                }
                if (preg_match('/(\d+)\s*$/', $pinB, $mB)) {
                    $serialB = (int)$mB[1];
                }

                if ($serialA !== $serialB) {
                    return $serialA <=> $serialB;
                }

                $cmp = strcasecmp($pinA, $pinB);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return (int)($a["id"] ?? 0) <=> (int)($b["id"] ?? 0);
            });
        }
        unset($lane);

        if (!$singleBranchOrder) {
            return [
                "success" => false,
                "message" => "No valid branches were found for the selected students."
            ];
        }

        if ($totalUsableCapacity < count($students)) {
            return [
                "success" => false,
                "message" =>
                    "Not enough usable seats. Students: " .
                    count($students) .
                    ", usable capacity: " .
                    $totalUsableCapacity . "."
            ];
        }

        $singleIndexes = array_fill_keys($singleBranchOrder, 0);
        $singleBranchIndex = 0;
        $singleAllocation = [];

        foreach ($benches as $bench) {
            $selectedStudent = null;
            $selectedBranchIndex = null;

            for ($offset = 0; $offset < count($singleBranchOrder); $offset++) {
                $candidateIndex =
                    ($singleBranchIndex + $offset) % count($singleBranchOrder);
                $candidateBranch = $singleBranchOrder[$candidateIndex];
                $position = (int)($singleIndexes[$candidateBranch] ?? 0);

                if (isset($singleBranchLanes[$candidateBranch][$position])) {
                    $selectedStudent = $singleBranchLanes[$candidateBranch][$position];
                    $selectedBranchIndex = $candidateIndex;
                    $singleIndexes[$candidateBranch] = $position + 1;
                    break;
                }
            }

            if ($selectedStudent === null) {
                break;
            }

            $seatNumber =
                "R" . (int)$bench["row"] .
                "-C" . (int)$bench["column"];

            $singleAllocation[] = [
                "student_id" => (int)$selectedStudent["id"],
                "pin_no" => (string)($selectedStudent["pin_no"] ?? ""),
                "student_name" => (string)($selectedStudent["student_name"] ?? ""),
                "department_id" => (int)($selectedStudent["department_id"] ?? 0),
                "department_name" => (string)($selectedStudent["department_name"] ?? ""),
                "department_code" => (string)($selectedStudent["department_code"] ?? ""),
                "academic_year_id" => (int)($selectedStudent["academic_year_id"] ?? 0),
                "year_name" => (string)($selectedStudent["year_name"] ?? ""),
                "semester_id" => (int)($selectedStudent["semester_id"] ?? 0),
                "semester_number" => $selectedStudent["semester_number"] ?? null,
                "semester_name" => (string)($selectedStudent["semester_name"] ?? ""),
                "hall_id" => (int)$bench["hall_id"],
                "hall_name" => (string)$bench["hall_name"],
                "hall_code" => (string)$bench["hall_code"],
                "row" => (int)$bench["row"],
                "column" => (int)$bench["column"],
                "bench_slot" => 1,
                "seat_number" => $seatNumber,
                "constraint_score" => 0
            ];

            $singleBranchIndex =
                ($selectedBranchIndex + 1) % count($singleBranchOrder);
        }

        if (count($singleAllocation) !== count($students)) {
            return [
                "success" => false,
                "message" =>
                    "Could not allocate all selected students using the selected branch sequence."
            ];
        }

        /* Return the complete response structure expected by AJAX. */
        $singleHallSummary = [];

        foreach ($halls as $hall) {
            $singleHallSummary[$hall["id"]] = [
                "hall_id" => (int)$hall["id"],
                "hall_name" => (string)$hall["hall_name"],
                "hall_code" => (string)$hall["hall_code"],
                "rows" => (int)$hall["rows_count"],
                "columns" => (int)$hall["columns_count"],
                "column_benches" => $hall["column_benches"] ?? [],
                "benches" => allocationHallBenchTotal($hall["column_benches"] ?? []),
                "capacity" => calculatePreviewHallCapacity(
                    (int)$hall["rows_count"],
                    (int)$hall["columns_count"],
                    "single",
                    $settings,
                    $hall["column_benches"] ?? null
                ),
                "allocated" => 0
            ];
        }

        foreach ($singleAllocation as $item) {
            $hallId = (int)$item["hall_id"];
            if (isset($singleHallSummary[$hallId])) {
                $singleHallSummary[$hallId]["allocated"]++;
            }
        }

        return [
            "success" => true,
            "allocation" => $singleAllocation,
            "hall_summary" => array_values($singleHallSummary),
            "total_students" => count($singleAllocation),
            "total_halls" => count($halls),
            "total_usable_capacity" => $totalUsableCapacity
        ];
    }

    if ($totalUsableCapacity < count($students)) {
        return [
            "success" => false,
            "message" =>
                "Not enough usable seats. Students: " .
                count($students) .
                ", usable capacity: " .
                $totalUsableCapacity . "."
        ];
    }

    /* =========================================================
       BUILD BRANCH + YEAR LANES
       =========================================================

       One lane = one Branch + Academic Year.
       PIN order inside every lane is preserved.

       A bench may NEVER contain:
         - the same branch twice;
         - the same academic year twice;
         - the same Branch + Year group twice.

       Therefore a multi-student bench must use different branches
       AND different academic years.
       ========================================================= */

    $branchLanes = [];
    $branchOrder = [];

    foreach ($students as $student) {
        $branchKey =
            (string)($student["department_id"] ?? 0) . "|" .
            (string)($student["academic_year_id"] ?? 0);

        if (!isset($branchLanes[$branchKey])) {
            $branchLanes[$branchKey] = [];
            $branchOrder[] = $branchKey;
        }

        $branchLanes[$branchKey][] = $student;
    }

    foreach ($branchLanes as &$lane) {
        usort(
            $lane,
            function ($a, $b) {
                $pinA = (string)($a["pin_no"] ?? "");
                $pinB = (string)($b["pin_no"] ?? "");

                $serialA = 0;
                $serialB = 0;

                if (preg_match('/(\d+)\s*$/', $pinA, $mA)) {
                    $serialA = (int)$mA[1];
                }

                if (preg_match('/(\d+)\s*$/', $pinB, $mB)) {
                    $serialB = (int)$mB[1];
                }

                if ($serialA !== $serialB) {
                    return $serialA <=> $serialB;
                }

                $cmp = strcasecmp($pinA, $pinB);

                if ($cmp !== 0) {
                    return $cmp;
                }

                return
                    (int)($a["id"] ?? 0) <=>
                    (int)($b["id"] ?? 0);
            }
        );
    }
    unset($lane);

    $branchCount = count($branchOrder);

    if ($branchCount === 0) {
        return [
            "success" => false,
            "message" => "No valid branch lanes were found for the selected students."
        ];
    }

    /*
     * Add convenient constraint keys to every student.
     */
    foreach ($branchLanes as &$lane) {
        foreach ($lane as &$student) {
            $student["_branch_key"] =
                (string)($student["department_id"] ?? 0);

            $student["_year_key"] =
                (string)($student["academic_year_id"] ?? 0);

            $student["_group_key"] =
                (string)$student["department_id"] . "|" .
                (string)$student["academic_year_id"];

            /*
             * Academic year ranking:
             * "1st Year" -> 1
             * "2nd Year" -> 2
             * etc.
             *
             * Fall back to the numeric academic_year_id when the
             * year name does not contain a number.
             */
            $yearRank = (int)($student["academic_year_id"] ?? 0);

            if (
                preg_match(
                    '/(\d+)/',
                    (string)($student["year_name"] ?? ""),
                    $yearMatch
                )
            ) {
                $yearRank = (int)$yearMatch[1];
            }

            $student["_year_rank"] = $yearRank;
        }
        unset($student);
    }
    unset($lane);

    $laneIndexes = [];

    foreach ($branchOrder as $branchKey) {
        $laneIndexes[$branchKey] = 0;
    }

    $studentCount = count($students);
    $statesVisited = 0;

    /*
     * The restriction rules NEVER reduce the physical capacity.
     * They only determine which students can occupy those slots.
     */
    $maxStates = 20000000;
    $memo = [];

    $remainingCount = function ($indexes)
        use (&$branchLanes, &$branchOrder) {

        $count = 0;

        foreach ($branchOrder as $branchKey) {
            $count +=
                count($branchLanes[$branchKey]) -
                (int)($indexes[$branchKey] ?? 0);
        }

        return $count;
    };

    /*
     * =========================================================
     * COMPLETE HALL-FIRST / MAXIMUM-BENCH-FILL SOLVER
     * =========================================================
     *
     * This is the important part:
     *
     * 1. Benches are processed strictly in hall-selection order.
     * 2. A hall is therefore exhausted before the next hall starts.
     * 3. For EVERY bench, the solver first attempts to fill ALL
     *    configured slots.
     * 4. If that cannot produce a complete overall allocation, it
     *    backtracks and tries another valid combination.
     * 5. Only after every full-capacity combination for that bench
     *    fails does it try one fewer student on that bench.
     * 6. Therefore a 2-student bench will be filled with 2 whenever
     *    a globally valid full allocation exists.
     * 7. The same mechanism works for future custom values such as
     *    1,2 / 2,1 / 2,3 / 3,2 / 3,4 etc.
     *
     * For a multi-student bench:
     *   - every student must be from a different branch;
     *   - every student must be from a different academic year;
     *   - academic years must increase from S1 -> S2 -> S3...
     *   - no student may use the same Branch+Year group as the
     *     immediately preceding physical bench in the same column.
     *
     * PIN order is preserved because only the next unallocated
     * student of each Branch+Year lane can be selected.
     * ========================================================= */

    $solve = null;

    $solve = function (
        $benchIndex,
        $indexes,
        $preferredBranchIndex,
        $previousGroups,
        $allocation
    ) use (
        &$solve,
        &$branchLanes,
        &$branchOrder,
        &$benches,
        &$statesVisited,
        $maxStates,
        $branchCount,
        $remainingCount,
        &$memo
    ) {
        if ($statesVisited++ >= $maxStates) {
            return [
                "success" => false,
                "limit" => true
            ];
        }

        $remaining = $remainingCount($indexes);

        if ($remaining === 0) {
            return [
                "success" => true,
                "allocation" => $allocation
            ];
        }

        if ($benchIndex >= count($benches)) {
            return [
                "success" => false
            ];
        }

        /*
         * Quick capacity check.
         */
        $remainingSlots = 0;

        for (
            $i = $benchIndex;
            $i < count($benches);
            $i++
        ) {
            $remainingSlots +=
                count($benches[$i]["slots"] ?? []);
        }

        if ($remaining > $remainingSlots) {
            return [
                "success" => false
            ];
        }

        $bench = $benches[$benchIndex];

        $columnKey =
            (int)$bench["hall_id"] . ":" .
            (int)$bench["column"];

        $previousGroup =
            (string)($previousGroups[$columnKey] ?? "");

        /*
         * Memoization state.
         *
         * The previous-group value is included because it is the
         * only old placement that affects the current column.
         */
        $stateKey =
            $benchIndex . "|" .
            implode(",", array_map("intval", $indexes)) . "|" .
            $columnKey . "=" .
            $previousGroup . "|" .
            (int)$preferredBranchIndex;

        $memoKey = sha1($stateKey);

        if (isset($memo[$memoKey])) {
            return [
                "success" => false
            ];
        }

        $slotCount =
            count($bench["slots"] ?? []);

        if ($slotCount <= 0) {
            $result = $solve(
                $benchIndex + 1,
                $indexes,
                $preferredBranchIndex,
                $previousGroups,
                $allocation
            );

            if (($result["success"] ?? false) === true) {
                return $result;
            }

            if (count($memo) < 500000) {
                $memo[$memoKey] = true;
            }

            return $result;
        }

        /*
         * ---------------------------------------------------------
         * Candidate lanes for this bench.
         * ---------------------------------------------------------
         *
         * The lane's next student is the only student we can take
         * from that lane. This preserves serial PIN order.
         */
        $candidateLanes = [];

        for (
            $offset = 0;
            $offset < $branchCount;
            $offset++
        ) {
            $branchIndex =
                ($preferredBranchIndex + $offset) %
                $branchCount;

            $branchKey =
                $branchOrder[$branchIndex];

            $position =
                (int)($indexes[$branchKey] ?? 0);

            if (
                !isset(
                    $branchLanes[$branchKey][$position]
                )
            ) {
                continue;
            }

            $student =
                $branchLanes[$branchKey][$position];

            $groupKey =
                (string)$student["_group_key"];

            /*
             * Same Branch+Year cannot be directly behind the
             * previous bench in this physical column.
             */
            if (
                $previousGroup !== "" &&
                $groupKey === $previousGroup
            ) {
                continue;
            }

            $candidateLanes[] = [
                "branch_index" => $branchIndex,
                "branch_key" => $branchKey,
                "student" => $student
            ];
        }

        if (!$candidateLanes) {
            if (count($memo) < 500000) {
                $memo[$memoKey] = true;
            }

            return [
                "success" => false
            ];
        }

        /*
         * IMPORTANT SEARCH FIX
         * --------------------
         * Same-bench year ordering is strict: S1 < S2 < S3 ...
         * Therefore candidate combinations MUST be considered in
         * academic-year order. The previous implementation used
         * branch/lane order only, which could skip a valid pair such as:
         *
         *   CSE 2nd-year candidate appearing before ECE 1st-year
         *   candidate in the lane list.
         *
         * Sort by year rank first, then preserve the administrator's
         * preferred branch order as the tie-breaker. This does NOT
         * change PIN order inside a lane; it only changes which lanes
         * are considered first for the current bench.
         */
        usort(
            $candidateLanes,
            function ($a, $b) {
                $yearA = (int)($a["student"]["_year_rank"] ?? 0);
                $yearB = (int)($b["student"]["_year_rank"] ?? 0);

                if ($yearA !== $yearB) {
                    return $yearA <=> $yearB;
                }

                return
                    (int)($a["branch_index"] ?? 0) <=>
                    (int)($b["branch_index"] ?? 0);
            }
        );

        /*
         * =========================================================
         * TRY TARGET OCCUPANCY FROM MAX -> MIN
         * =========================================================
         *
         * This guarantees:
         *
         *   2 configured slots -> try 2 first
         *   3 configured slots -> try 3 first
         *   4 configured slots -> try 4 first
         *
         * Only when no complete allocation can be obtained with
         * that occupancy does the solver try a smaller occupancy.
         *
         * Thus a valid 2-student bench is NOT unnecessarily reduced
         * to one student.
         * =========================================================
         */
        for (
            $targetOccupancy = $slotCount;
            $targetOccupancy >= 1;
            $targetOccupancy--
        ) {
            /*
             * If fewer students remain than the requested number,
             * use only the number that actually remains.
             */
            $target =
                min(
                    $targetOccupancy,
                    $remaining
                );

            /*
             * Build every valid ordered combination for this bench.
             *
             * Because academic years must increase from front to
             * back, the first selected student is automatically the
             * lowest year and later slots must have higher years.
             */
            $chooseBenchStudents = null;

            $chooseBenchStudents = function (
                $candidateIndex,
                $selected,
                $workingIndexes,
                $lastYearRank
            ) use (
                &$chooseBenchStudents,
                &$candidateLanes,
                $target
            ) {
                if (count($selected) === $target) {
                    return [[
                        "selected" => $selected,
                        "indexes" => $workingIndexes
                    ]];
                }

                $needed =
                    $target - count($selected);

                $available =
                    count($candidateLanes) -
                    $candidateIndex;

                if ($available < $needed) {
                    return [];
                }

                $results = [];

                for (
                    $i = $candidateIndex;
                    $i < count($candidateLanes);
                    $i++
                ) {
                    $candidate =
                        $candidateLanes[$i];

                    $student =
                        $candidate["student"];

                    $branchKey =
                        (string)$student["_branch_key"];

                    $yearRank =
                        (int)$student["_year_rank"];

                    /*
                     * SAME BRANCH RULE:
                     * No two students on the same bench may have
                     * the same department/branch.
                     */
                    $sameBranch = false;

                    foreach ($selected as $chosen) {
                        if (
                            (string)$chosen["student"]["_branch_key"] ===
                            $branchKey
                        ) {
                            $sameBranch = true;
                            break;
                        }
                    }

                    if ($sameBranch) {
                        continue;
                    }

                    /*
                     * SAME YEAR RULE:
                     * No two students on the same bench may have
                     * the same academic year.
                     */
                    $sameYear = false;

                    foreach ($selected as $chosen) {
                        if (
                            (int)$chosen["student"]["_year_rank"] ===
                            $yearRank
                        ) {
                            $sameYear = true;
                            break;
                        }
                    }

                    if ($sameYear) {
                        continue;
                    }

                    /*
                     * FRONT -> BACK YEAR ORDER:
                     *
                     * S1 must be the smallest year.
                     * S2 must be higher than S1.
                     * S3 must be higher than S2.
                     * etc.
                     */
                    if (
                        $lastYearRank !== null &&
                        $yearRank <= $lastYearRank
                    ) {
                        continue;
                    }

                    /*
                     * Each lane can only contribute its current
                     * next student once to this bench.
                     */
                    $nextIndexes =
                        $workingIndexes;

                    $nextIndexes[$candidate["branch_key"]] =
                        (int)(
                            $nextIndexes[
                                $candidate["branch_key"]
                            ] ?? 0
                        ) + 1;

                    $nextSelected =
                        $selected;

                    $nextSelected[] =
                        $candidate;

                    $subResults =
                        $chooseBenchStudents(
                            $i + 1,
                            $nextSelected,
                            $nextIndexes,
                            $yearRank
                        );

                    foreach ($subResults as $subResult) {
                        $results[] =
                            $subResult;
                    }
                }

                return $results;
            };

            $benchCombinations =
                $chooseBenchStudents(
                    0,
                    [],
                    $indexes,
                    null
                );

            foreach ($benchCombinations as $combination) {
                $selected =
                    $combination["selected"];

                $nextIndexes =
                    $combination["indexes"];

                /*
                 * Create allocation records in actual bench-slot
                 * order. S1 is front; S2 is behind it; S3 follows,
                 * etc.
                 */
                $benchAllocation = [];

                foreach (
                    $selected as $slotIndex => $candidate
                ) {
                    $student =
                        $candidate["student"];

                    $seatSlot =
                        (int)$bench["slots"][
                            $slotIndex
                        ];

                    $seatNumber =
                        "R" .
                        (int)$bench["row"] .
                        "-C" .
                        (int)$bench["column"];

                    if ($seatSlot > 1) {
                        $seatNumber .=
                            "-S" . $seatSlot;
                    }

                    $benchAllocation[] = [
                        "student_id" =>
                            (int)$student["id"],

                        "pin_no" =>
                            (string)$student["pin_no"],

                        "student_name" =>
                            (string)$student["student_name"],

                        "department_id" =>
                            (int)$student["department_id"],

                        "department_name" =>
                            (string)$student["department_name"],

                        "department_code" =>
                            (string)$student["department_code"],

                        "academic_year_id" =>
                            (int)$student["academic_year_id"],

                        "year_name" =>
                            (string)$student["year_name"],

                        "semester_id" =>
                            (int)$student["semester_id"],

                        "semester_number" =>
                            $student["semester_number"],

                        "semester_name" =>
                            (string)$student["semester_name"],

                        "hall_id" =>
                            (int)$bench["hall_id"],

                        "hall_name" =>
                            (string)$bench["hall_name"],

                        "hall_code" =>
                            (string)$bench["hall_code"],

                        "row" =>
                            (int)$bench["row"],

                        "column" =>
                            (int)$bench["column"],

                        "bench_slot" =>
                            $seatSlot,

                        "seat_number" =>
                            $seatNumber,

                        "constraint_score" =>
                            0
                    ];
                }

                /*
                 * The group remembered for the next physical bench
                 * is the first/front student's Branch+Year group.
                 *
                 * This preserves the existing adjacent front/back
                 * restriction without making previous columns part
                 * of the state.
                 */
                $nextPreviousGroups =
                    array_replace(
                        $previousGroups,
                        [
                            $columnKey =>
                                (string)$selected[0]["student"]["_group_key"]
                        ]
                    );

                /*
                 * Start the next bench from the lane following the
                 * last lane used on this bench. This retains the
                 * administrator's selected lane priority.
                 */
                $lastBranchIndex =
                    (int)$selected[
                        count($selected) - 1
                    ]["branch_index"];

                $nextPreferredBranchIndex =
                    ($lastBranchIndex + 1) %
                    $branchCount;

                $result =
                    $solve(
                        $benchIndex + 1,
                        $nextIndexes,
                        $nextPreferredBranchIndex,
                        $nextPreviousGroups,
                        array_merge(
                            $allocation,
                            $benchAllocation
                        )
                    );

                if (($result["success"] ?? false) === true) {
                    return $result;
                }

                if (($result["limit"] ?? false) === true) {
                    return $result;
                }
            }
        }

        /*
         * No occupancy from this bench can lead to a complete
         * allocation from this state.
         */
        if (count($memo) < 500000) {
            $memo[$memoKey] = true;
        }

        return [
            "success" => false
        ];
    };

    $allocationResult =
        $solve(
            0,
            $laneIndexes,
            0,
            [],
            []
        );

    if (!($allocationResult["success"] ?? false)) {
        $message =
            "Unable to generate a complete seating arrangement with the selected seating pattern and branch/year restrictions. " .
            "Students: " .
            $studentCount .
            ", usable capacity: " .
            $totalUsableCapacity .
            ".";

        if (
            ($allocationResult["limit"] ?? false) === true
        ) {
            $message .=
                " The scheduler reached its search limit; no seats were saved.";
        }

        return [
            "success" => false,
            "message" => $message
        ];
    }

    $allocation =
        $allocationResult["allocation"];

    /* =========================================================
       HALL SUMMARY
       ========================================================= */

    $hallSummary = [];

    foreach ($halls as $hall) {
        $hallSummary[$hall["id"]] = [
            "hall_id" =>
                (int)$hall["id"],

            "hall_name" =>
                (string)$hall["hall_name"],

            "hall_code" =>
                (string)$hall["hall_code"],

            "rows" =>
                (int)$hall["rows_count"],

            "columns" =>
                (int)$hall["columns_count"],

            "column_benches" =>
                $hall["column_benches"] ?? [],

            /* Physical benches are the sum of benches in each column. */
            "benches" =>
                allocationHallBenchTotal($hall["column_benches"] ?? []),

            /*
             * Hall capacity shown in the Automatic Seating Preview
             * follows the selected seating pattern.
             * This is display-only; the allocation algorithm itself
             * is not changed here.
             */
            "capacity" =>
                calculatePreviewHallCapacity(
                    (int)$hall["rows_count"],
                    (int)$hall["columns_count"],
                    $pattern,
                    $settings,
                    $hall["column_benches"] ?? null
                ),

            "allocated" =>
                0
        ];
    }

    foreach ($allocation as $item) {
        $hallId =
            (int)$item["hall_id"];

        if (isset($hallSummary[$hallId])) {
            $hallSummary[$hallId]["allocated"]++;
        }
    }

    return [
        "success" => true,

        "allocation" =>
            $allocation,

        "hall_summary" =>
            array_values($hallSummary),

        "total_students" =>
            count($allocation),

        "total_halls" =>
            count($halls),

        "total_usable_capacity" =>
            $totalUsableCapacity
    ];
}

/* =========================================================
   AJAX - LOAD STUDENTS
========================================================= */

if (
    isset($_POST["action"]) &&
    $_POST["action"] === "load_students"
) {

    $selectedGroups =
        $_POST["groups"] ?? [];

    $data =
        getStudentsByGroups(
            $conn,
            $selectedGroups
        );

    jsonResponse($data);
}

/* =========================================================
   AJAX - GENERATE SEATING
========================================================= */

if (
    isset($_POST["action"]) &&
    $_POST["action"] === "generate"
) {

    $selectedGroups =
        $_POST["groups"] ?? [];

    $selectedHallIds =
        $_POST["halls"] ?? [];

    $examDate = trim((string)($_POST["exam_date"] ?? ""));
    $examTime = trim((string)($_POST["exam_time"] ?? ""));
    $examTimeTo = trim((string)($_POST["exam_time_to"] ?? ""));

    $dateTimeCheck = validateAllocationDateTime($examDate, $examTime, $examTimeTo);
    if (!$dateTimeCheck["success"]) jsonResponse($dateTimeCheck);
    $examDate = $dateTimeCheck["exam_date"];
    $examTime = $dateTimeCheck["exam_time"];
    $examTimeTo = $dateTimeCheck["exam_time_to"];

    $pattern =
        trim(
            (string)(
                $_POST["pattern"] ??
                "single"
            )
        );

    $allowedPatterns = [
        "single",
        "two_persons",
        "alternative",
        "customized"
    ];

    if (!in_array($pattern, $allowedPatterns, true)) {

        jsonResponse([
            "success" => false,
            "message" => "Invalid seating pattern."
        ]);
    }

    /*
     * Get students again directly from DB.
     *
     * Do not trust browser student details.
     */
    $studentData =
        getStudentsByGroups(
            $conn,
            $selectedGroups
        );

    if (!$studentData["success"]) {
        jsonResponse($studentData);
    }

    $students =
        $studentData["students"];

    /* =========================================================
       PRESERVE ADMIN SELECTED GROUP ORDER
       =========================================================

       The allocation lane order follows the order in which the
       Branch + Year + Semester groups were selected by the admin.
       This is what allows a sequence such as:

         CSE 1st -> ECE 1st -> CSE 2nd

       to become:
         25189-CS-001
         25189-EC-001 + 24189-CS-001
         25189-CS-002
         25189-EC-002 + 24189-CS-002

       Academic year is still taken from the Student Module; the
       selection order is used only to decide lane priority.
    ========================================================= */
    $selectedGroupRank = [];

    foreach (array_values($selectedGroups) as $rank => $groupKey) {
        $selectedGroupRank[(string)$groupKey] = $rank;
    }

    usort(
        $students,
        function ($a, $b) use ($selectedGroupRank) {
            $keyA =
                (string)($a["department_id"] ?? 0) . ":" .
                (string)($a["academic_year_id"] ?? 0) . ":" .
                (string)($a["semester_id"] ?? 0);

            $keyB =
                (string)($b["department_id"] ?? 0) . ":" .
                (string)($b["academic_year_id"] ?? 0) . ":" .
                (string)($b["semester_id"] ?? 0);

            $rankA = $selectedGroupRank[$keyA] ?? PHP_INT_MAX;
            $rankB = $selectedGroupRank[$keyB] ?? PHP_INT_MAX;

            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            $pinA = (string)($a["pin_no"] ?? "");
            $pinB = (string)($b["pin_no"] ?? "");

            $serialA = 0;
            $serialB = 0;

            if (preg_match('/(\d+)\s*$/', $pinA, $mA)) {
                $serialA = (int)$mA[1];
            }

            if (preg_match('/(\d+)\s*$/', $pinB, $mB)) {
                $serialB = (int)$mB[1];
            }

            if ($serialA !== $serialB) {
                return $serialA <=> $serialB;
            }

            return strcasecmp($pinA, $pinB);
        }
    );

    if (!$students) {

        jsonResponse([
            "success" => false,
            "message" =>
                "No registered students were found for the selected groups."
        ]);
    }

    /*
     * Get halls directly from DB.
     */
    $selectedHalls =
        getSelectedHalls(
            $conn,
            $selectedHallIds
        );

    if (!$selectedHalls) {

        jsonResponse([
            "success" => false,
            "message" =>
                "Please select at least one valid examination hall."
        ]);
    }

    $availabilityCheck = checkHallTimeConflicts($conn, $selectedHallIds, $examDate, $examTime, $examTimeTo);
    if (!$availabilityCheck["success"]) jsonResponse($availabilityCheck);
    if (!empty($availabilityCheck["conflicts"])) {
        jsonResponse(["success" => false, "message" => hallConflictMessage($availabilityCheck["conflicts"]), "conflicts" => $availabilityCheck["conflicts"]]);
    }

    /*
     * CUSTOMIZED pattern is automatic.
     * Bench 1,3,5... = 1 student.
     * Bench 2,4,6... = 2 students.
     */
    $firstBenchStudents = max(0, min(20, (int)($_POST["first_bench_students"] ?? 1)));
    $secondBenchStudents = max(0, min(20, (int)($_POST["second_bench_students"] ?? 2)));

    if ($firstBenchStudents <= 0 && $secondBenchStudents <= 0) {
        jsonResponse([
            "success" => false,
            "message" => "Customized bench capacity must allow at least one student per bench."
        ]);
    }

    $settings = [
        "first_bench_students" => $firstBenchStudents,
        "second_bench_students" => $secondBenchStudents
    ];

    $mixGroups =
        isset($_POST["mix_groups"]) &&
        (int)$_POST["mix_groups"] === 1;

    $separateGroups =
        isset($_POST["separate_groups"]) &&
        (int)$_POST["separate_groups"] === 1;

    /*
     * Cannot use both.
     */
    if ($mixGroups && $separateGroups) {
        $mixGroups = false;
    }

    /*
     * Generate.
     */
    try {
        $generated =
            generateConstraintAllocation(
                $students,
                $selectedHalls,
                $pattern,
                $settings,
                $mixGroups,
                $separateGroups
            );
    } catch (Throwable $e) {
        jsonResponse([
            "success" => false,
            "message" => "Unable to generate the seating arrangement because of a server-side error.",
            "error" => $e->getMessage(),
            "error_file" => basename($e->getFile()),
            "error_line" => $e->getLine()
        ]);
    }

    if (!$generated["success"]) {
        jsonResponse($generated);
    }

    /*
     * Store complete preview server-side.
     *
     * Confirm will use this data after revalidation.
     */
    $_SESSION["seat_allocation_preview"] = [

        "allocation" =>
            $generated["allocation"],

        "hall_summary" =>
            $generated["hall_summary"],

        "pattern" =>
            $pattern,

        "settings" =>
            $settings,

        "mix_groups" =>
            $mixGroups,

        "separate_groups" =>
            $separateGroups,

        "students" =>
            $students,

        "selected_hall_ids" =>
            array_map("intval", $selectedHallIds),

        "exam_date" => $examDate,
        "exam_time" => $examTime,
        "exam_time_to" => $examTimeTo,

        "created_at" =>
            time()
    ];

    jsonResponse([
        "success" => true,

        "allocation" =>
            $generated["allocation"],

        "hall_summary" =>
            $generated["hall_summary"],

        "total_students" =>
            $generated["total_students"],

        "total_halls" =>
            $generated["total_halls"],

        "total_usable_capacity" =>
            $generated["total_usable_capacity"],

        "pattern" =>
            $pattern
    ]);
}

/* =========================================================
   AJAX - SHUFFLE HALLS IN PREVIEW

   Only the selected hall order is shuffled. The same students,
   groups, seating pattern and allocation restrictions are reused.
========================================================= */

if (
    isset($_POST["action"]) &&
    $_POST["action"] === "shuffle_halls"
) {

    $preview = $_SESSION["seat_allocation_preview"] ?? null;

    if (!$preview || empty($preview["students"]) || empty($preview["selected_hall_ids"])) {
        jsonResponse([
            "success" => false,
            "message" => "Please generate the seating arrangement first."
        ]);
    }

    $selectedHallIds = array_values(array_filter(
        array_map("intval", $preview["selected_hall_ids"]),
        function ($id) { return $id > 0; }
    ));

    if (count($selectedHallIds) < 2) {
        jsonResponse([
            "success" => false,
            "message" => "Select at least two halls to shuffle the hall order."
        ]);
    }

    $selectedHalls = getSelectedHalls($conn, $selectedHallIds);

    if (!$selectedHalls || count($selectedHalls) < 2) {
        jsonResponse([
            "success" => false,
            "message" => "Unable to load the selected examination halls."
        ]);
    }

    shuffle($selectedHalls);

    try {
        $generated = generateConstraintAllocation(
            $preview["students"],
            $selectedHalls,
            (string)$preview["pattern"],
            $preview["settings"],
            !empty($preview["mix_groups"]),
            !empty($preview["separate_groups"])
        );
    } catch (Throwable $e) {
        jsonResponse([
            "success" => false,
            "message" => "Unable to shuffle the hall allocation because of a server-side error.",
            "error" => $e->getMessage(),
            "error_file" => basename($e->getFile()),
            "error_line" => $e->getLine()
        ]);
    }

    if (!$generated["success"]) {
        jsonResponse($generated);
    }

    $_SESSION["seat_allocation_preview"]["allocation"] = $generated["allocation"];
    $_SESSION["seat_allocation_preview"]["hall_summary"] = $generated["hall_summary"];
    $_SESSION["seat_allocation_preview"]["created_at"] = time();

    jsonResponse([
        "success" => true,
        "allocation" => $generated["allocation"],
        "hall_summary" => $generated["hall_summary"],
        "total_students" => $generated["total_students"],
        "total_halls" => $generated["total_halls"],
        "total_usable_capacity" => $generated["total_usable_capacity"],
        "pattern" => $preview["pattern"]
    ]);
}

/* =========================================================
   AJAX - CONFIRM ALLOCATION
========================================================= */

if (
    isset($_POST["action"]) &&
    $_POST["action"] === "confirm"
) {

    if (
        !isset(
            $_SESSION["seat_allocation_preview"]
        )
    ) {

        jsonResponse([
            "success" => false,
            "message" =>
                "No generated seating arrangement is available to confirm."
        ]);
    }

    $preview =
        $_SESSION["seat_allocation_preview"];

    $allocation =
        $preview["allocation"] ?? [];

    $pattern =
        $preview["pattern"] ?? "single";

    $settings =
        $preview["settings"] ?? [];

    $examDate = trim((string)($preview["exam_date"] ?? ""));
    $examTime = trim((string)($preview["exam_time"] ?? ""));
    $examTimeTo = trim((string)($preview["exam_time_to"] ?? ""));
    $dateTimeCheck = validateAllocationDateTime($examDate, $examTime, $examTimeTo);
    if (!$dateTimeCheck["success"]) jsonResponse($dateTimeCheck);
    $examDate = $dateTimeCheck["exam_date"];
    $examTime = $dateTimeCheck["exam_time"];
    $examTimeTo = $dateTimeCheck["exam_time_to"];

    if (!$allocation) {

        jsonResponse([
            "success" => false,
            "message" =>
                "The generated seating arrangement is empty."
        ]);
    }

    /*
     * Revalidate students before confirmation.
     */
    $studentIds = [];

    foreach ($allocation as $item) {
        $studentIds[] =
            (int)$item["student_id"];
    }

    $studentIds =
        array_values(
            array_unique($studentIds)
        );

    if (
        count($studentIds) !==
        count($allocation)
    ) {

        jsonResponse([
            "success" => false,
            "message" =>
                "Duplicate students were detected in the allocation."
        ]);
    }

    /*
     * Verify every student still exists.
     */
    $placeholders =
        implode(
            ",",
            array_fill(
                0,
                count($studentIds),
                "?"
            )
        );

    $types =
        str_repeat(
            "i",
            count($studentIds)
        );

    $sql = "
        SELECT
            id,
            pin_no
        FROM students
        WHERE id IN ($placeholders)
    ";

    $stmt =
        mysqli_prepare(
            $conn,
            $sql
        );

    if (!$stmt) {

        jsonResponse([
            "success" => false,
            "message" =>
                "Unable to validate students."
        ]);
    }

    mysqli_stmt_bind_param(
        $stmt,
        $types,
        ...$studentIds
    );

    mysqli_stmt_execute($stmt);

    $result =
        mysqli_stmt_get_result($stmt);

    $existingStudents = [];

    while ($row = mysqli_fetch_assoc($result)) {

        $existingStudents[
            (int)$row["id"]
        ] = true;
    }

    mysqli_stmt_close($stmt);

    if (
        count($existingStudents) !==
        count($studentIds)
    ) {

        jsonResponse([
            "success" => false,
            "message" =>
                "One or more students no longer exist. Please generate the seating again."
        ]);
    }

    /*
     * Verify selected halls still exist.
     */
    $hallIds = [];

    foreach ($allocation as $item) {
        $hallIds[] =
            (int)$item["hall_id"];
    }

    $hallIds =
        array_values(
            array_unique($hallIds)
        );

    $placeholders =
        implode(
            ",",
            array_fill(
                0,
                count($hallIds),
                "?"
            )
        );

    $types =
        str_repeat(
            "i",
            count($hallIds)
        );

    $sql = "
        SELECT id
        FROM halls
        WHERE id IN ($placeholders)
    ";

    $stmt =
        mysqli_prepare(
            $conn,
            $sql
        );

    if (!$stmt) {

        jsonResponse([
            "success" => false,
            "message" =>
                "Unable to validate halls."
        ]);
    }

    mysqli_stmt_bind_param(
        $stmt,
        $types,
        ...$hallIds
    );

    mysqli_stmt_execute($stmt);

    $result =
        mysqli_stmt_get_result($stmt);

    $existingHalls = [];

    while ($row = mysqli_fetch_assoc($result)) {

        $existingHalls[
            (int)$row["id"]
        ] = true;
    }

    mysqli_stmt_close($stmt);

    if (
        count($existingHalls) !==
        count($hallIds)
    ) {

        jsonResponse([
            "success" => false,
            "message" =>
                "One or more selected halls no longer exist. Please generate the seating again."
        ]);
    }

    /*
     * Validate duplicate physical seats.
     */
    $physicalSeats = [];

    foreach ($allocation as $item) {

        $seatKey =
            (int)$item["hall_id"] .
            ":" .
            (int)$item["row"] .
            ":" .
            (int)$item["column"] .
            ":" .
            (int)($item["bench_slot"] ?? 1);

        if (isset($physicalSeats[$seatKey])) {

            jsonResponse([
                "success" => false,
                "message" =>
                    "Duplicate physical seat detected. Please regenerate the allocation."
            ]);
        }

        $physicalSeats[$seatKey] = true;
    }

    /*
     * Generate unique allocation code.
     */
    $allocationCode =
        "ALLOC-" .
        date("Ymd-His") .
        "-" .
        strtoupper(
            substr(
                bin2hex(
                    random_bytes(3)
                ),
                0,
                6
            )
        );

    /*
     * Start transaction.
     */
    mysqli_begin_transaction($conn);

    try {

        $finalHallIds = [];
        foreach ($allocation as $item) $finalHallIds[] = (int)$item["hall_id"];
        $finalHallIds = array_values(array_unique($finalHallIds));
        $availabilityCheck = checkHallTimeConflicts($conn, $finalHallIds, $examDate, $examTime, $examTimeTo);
        if (!$availabilityCheck["success"]) throw new Exception($availabilityCheck["message"]);
        if (!empty($availabilityCheck["conflicts"])) throw new Exception(hallConflictMessage($availabilityCheck["conflicts"]));

        /*
         * Create allocation batch.
         */
        $patternSettings =
            json_encode(
                $settings,
                JSON_UNESCAPED_UNICODE
            );

        $totalStudents =
            count($allocation);

        $hallSummary =
            $preview["hall_summary"] ?? [];

        $totalHalls =
            count($hallSummary);

        $totalCapacity = 0;

        foreach ($hallSummary as $summary) {
            $totalCapacity +=
                (int)$summary["allocated"];
        }

        $sql = "
            INSERT INTO allocation_batches
            (
                exam_date,
                exam_time,
                exam_time_to,
                allocation_code,
                pattern_type,
                pattern_settings,
                total_students,
                total_halls,
                total_capacity,
                status
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed')
        ";

        $stmt =
            mysqli_prepare(
                $conn,
                $sql
            );

        if (!$stmt) {
            throw new Exception(
                "Unable to create allocation batch."
            );
        }

        mysqli_stmt_bind_param(
            $stmt,
            "ssssssiii",
            $examDate,
            $examTime,
            $examTimeTo,
            $allocationCode,
            $pattern,
            $patternSettings,
            $totalStudents,
            $totalHalls,
            $totalCapacity
        );

        if (!mysqli_stmt_execute($stmt)) {

            mysqli_stmt_close($stmt);

            throw new Exception(
                "Unable to save allocation batch."
            );
        }

        $allocationId =
            mysqli_insert_id($conn);

        mysqli_stmt_close($stmt);

        /*
         * Insert seats.
         */
        $sql = "
            INSERT INTO allocation_seats
            (
                allocation_id,
                student_id,
                hall_id,
                row_number,
                column_number,
                bench_slot,
                seat_number
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ";

        $stmt =
            mysqli_prepare(
                $conn,
                $sql
            );

        if (!$stmt) {

            throw new Exception(
                "Unable to prepare seat allocation records."
            );
        }

        foreach ($allocation as $item) {

            $studentId =
                (int)$item["student_id"];

            $hallId =
                (int)$item["hall_id"];

            $rowNumber =
                (int)$item["row"];

            $columnNumber =
                (int)$item["column"];

            $benchSlot =
                (int)($item["bench_slot"] ?? 1);

            $seatNumber =
                (string)$item["seat_number"];

            /*
             * 5 integers + 1 string
             */
            mysqli_stmt_bind_param(
                $stmt,
                "iiiiiis",
                $allocationId,
                $studentId,
                $hallId,
                $rowNumber,
                $columnNumber,
                $benchSlot,
                $seatNumber
            );

            if (!mysqli_stmt_execute($stmt)) {

                mysqli_stmt_close($stmt);

                throw new Exception(
                    "Unable to save seat allocation."
                );
            }
        }

        mysqli_stmt_close($stmt);

        /*
         * Mark confirmed time.
         */
        $sql = "
            UPDATE allocation_batches
            SET
                confirmed_at = NOW(),
                status = 'confirmed'
            WHERE id = ?
        ";

        $stmt =
            mysqli_prepare(
                $conn,
                $sql
            );

        if ($stmt) {

            mysqli_stmt_bind_param(
                $stmt,
                "i",
                $allocationId
            );

            mysqli_stmt_execute($stmt);

            mysqli_stmt_close($stmt);
        }

        /*
         * Notification.
         */
        addNotification(
            $conn,
            "Seat Allocation Confirmed",
            $totalStudents .
            " students were allocated across " .
            $totalHalls .
            " examination hall(s). Allocation code: " .
            $allocationCode,
            "seat"
        );

        mysqli_commit($conn);

        /*
         * Remove temporary preview.
         */
        unset(
            $_SESSION["seat_allocation_preview"]
        );

        jsonResponse([
            "success" => true,
            "message" =>
                "Seat allocation confirmed successfully.",
            "allocation_code" =>
                $allocationCode,
            "allocation_id" =>
                $allocationId
        ]);

    } catch (Throwable $e) {

        mysqli_rollback($conn);

        jsonResponse([
            "success" => false,
            "message" =>
                $e->getMessage()
        ]);
    }
}

/* =========================================================
   AJAX - CANCEL / REMOVE CONFIRMED ALLOCATION
========================================================= */
if (
    isset($_POST["action"]) &&
    $_POST["action"] === "cancel_allocation"
) {
    $allocationId = isset($_POST["allocation_id"])
        ? (int)$_POST["allocation_id"]
        : 0;

    if ($allocationId <= 0) {
        jsonResponse([
            "success" => false,
            "message" => "Invalid allocation selected."
        ]);
    }

    mysqli_begin_transaction($conn);

    try {
        /* ---------------------------------------------------------
           Lock and verify the allocation.
        --------------------------------------------------------- */
        $sql = "
            SELECT
                id,
                allocation_code,
                total_students,
                total_halls,
                status
            FROM allocation_batches
            WHERE id = ?
            FOR UPDATE
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            throw new Exception("Unable to find the selected allocation.");
        }

        mysqli_stmt_bind_param($stmt, "i", $allocationId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $batch = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if (!$batch) {
            throw new Exception("The selected allocation was not found.");
        }

        if ($batch["status"] !== "confirmed") {
            throw new Exception("Only a confirmed allocation can be removed.");
        }

        /* ---------------------------------------------------------
           Change the batch to cancelled.

           allocation_seats are intentionally kept for history.
           Hall Management counts only status='confirmed', so the
           cancelled allocation immediately stops occupying halls.
        --------------------------------------------------------- */
        $sql = "
            UPDATE allocation_batches
            SET status = 'cancelled'
            WHERE id = ?
              AND status = 'confirmed'
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            throw new Exception("Unable to cancel the allocation.");
        }

        mysqli_stmt_bind_param($stmt, "i", $allocationId);

        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            throw new Exception("Unable to cancel the allocation.");
        }

        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($affected !== 1) {
            throw new Exception("The allocation could not be cancelled because its status changed.");
        }

        /* ---------------------------------------------------------
           Find halls released by this allocation for the response.
        --------------------------------------------------------- */
        $releasedHalls = [];

        $sql = "
            SELECT DISTINCT
                h.id,
                h.hall_name,
                h.hall_code
            FROM allocation_seats a
            INNER JOIN halls h ON h.id = a.hall_id
            WHERE a.allocation_id = ?
            ORDER BY h.hall_name ASC
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $allocationId);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            while ($row = mysqli_fetch_assoc($result)) {
                $releasedHalls[] = [
                    "id" => (int)$row["id"],
                    "hall_name" => (string)$row["hall_name"],
                    "hall_code" => (string)$row["hall_code"]
                ];
            }

            mysqli_stmt_close($stmt);
        }

        /* ---------------------------------------------------------
           Notification.
        --------------------------------------------------------- */
        addNotification(
            $conn,
            "Seat Allocation Cancelled",
            (int)$batch["total_students"] .
            " students from " .
            (int)$batch["total_halls"] .
            " examination hall(s) were released. Allocation code: " .
            (string)$batch["allocation_code"],
            "seat"
        );

        mysqli_commit($conn);

        jsonResponse([
            "success" => true,
            "message" => "Confirmed seat allocation cancelled successfully.",
            "allocation_id" => $allocationId,
            "allocation_code" => (string)$batch["allocation_code"],
            "released_halls" => $releasedHalls
        ]);

    } catch (Throwable $e) {
        mysqli_rollback($conn);

        jsonResponse([
            "success" => false,
            "message" => $e->getMessage()
        ]);
    }
}

/* =========================================================
   LOAD CONFIRMED ALLOCATIONS
========================================================= */
$confirmedAllocations = [];

$sql = "
    SELECT
        ab.id,
        ab.allocation_code,
        ab.pattern_type,
        ab.pattern_settings,
        ab.total_students,
        ab.total_halls,
        ab.created_at,
        ab.confirmed_at,
        COUNT(DISTINCT ase.hall_id) AS used_halls
    FROM allocation_batches ab
    LEFT JOIN allocation_seats ase
        ON ase.allocation_id = ab.id
    WHERE ab.status = 'confirmed'
    GROUP BY
        ab.id,
        ab.allocation_code,
        ab.pattern_type,
        ab.pattern_settings,
        ab.total_students,
        ab.total_halls,
        ab.created_at,
        ab.confirmed_at
    ORDER BY
        COALESCE(ab.confirmed_at, ab.created_at) DESC,
        ab.id DESC
";

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $settings = json_decode(
            (string)($row["pattern_settings"] ?? ""),
            true
        );

        if (!is_array($settings)) {
            $settings = [];
        }

        $patternLabel = ucwords(
            str_replace(
                "_",
                " ",
                (string)$row["pattern_type"]
            )
        );

        $patternDetail = $patternLabel;

        if ($row["pattern_type"] === "customized") {
            $first = (int)($settings["first_bench_students"] ?? 0);
            $second = (int)($settings["second_bench_students"] ?? 0);

            $patternDetail =
                "Customized · First Bench: " .
                $first .
                " · Second Bench: " .
                $second;
        }

        $confirmedAllocations[] = [
            "id" => (int)$row["id"],
            "allocation_code" => (string)$row["allocation_code"],
            "pattern_type" => (string)$row["pattern_type"],
            "pattern_label" => $patternLabel,
            "pattern_detail" => $patternDetail,
            "total_students" => (int)$row["total_students"],
            "total_halls" => (int)$row["total_halls"],
            "used_halls" => (int)$row["used_halls"],
            "created_at" => (string)$row["created_at"],
            "confirmed_at" => (string)($row["confirmed_at"] ?? "")
        ];
    }
}


/* =========================================================
   AJAX - VIEW CONFIRMED SEATING ALLOCATION
========================================================= */
if (
    isset($_POST["action"]) &&
    $_POST["action"] === "view_allocation"
) {
    $allocationId = isset($_POST["allocation_id"])
        ? (int)$_POST["allocation_id"]
        : 0;

    if ($allocationId <= 0) {
        jsonResponse([
            "success" => false,
            "message" => "Invalid allocation selected."
        ]);
    }

    $sql = "
        SELECT
            ab.id,
            ab.pattern_type,
            ab.pattern_settings,
            ab.total_students,
            ab.total_halls,
            ab.confirmed_at,
            ab.created_at
        FROM allocation_batches ab
        WHERE ab.id = ?
          AND ab.status = 'confirmed'
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        jsonResponse([
            "success" => false,
            "message" => "Unable to load the seating allocation."
        ]);
    }

    mysqli_stmt_bind_param($stmt, "i", $allocationId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $batch = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$batch) {
        jsonResponse([
            "success" => false,
            "message" => "The confirmed seating allocation was not found."
        ]);
    }

    $settings = json_decode(
        (string)($batch["pattern_settings"] ?? ""),
        true
    );

    if (!is_array($settings)) {
        $settings = [];
    }

    $sql = "
        SELECT
            ase.id,
            ase.student_id,
            ase.hall_id,
            ase.row_number,
            ase.column_number,
            ase.bench_slot,
            ase.seat_number,

            s.pin_no,
            s.student_name,

            d.department_name,
            d.department_code,

            ay.year_name,

            sem.semester_number,
            sem.semester_name,

            h.hall_name,
            h.hall_code,
            h.rows_count,
            h.columns_count,
            h.column_benches,
            h.capacity

        FROM allocation_seats ase

        INNER JOIN students s
            ON s.id = ase.student_id

        LEFT JOIN departments d
            ON d.id = s.department_id

        LEFT JOIN academic_years ay
            ON ay.id = s.academic_year

        LEFT JOIN semesters sem
            ON sem.id = s.semester_id

        INNER JOIN halls h
            ON h.id = ase.hall_id

        WHERE ase.allocation_id = ?

        ORDER BY
            h.hall_name ASC,
            ase.column_number ASC,
            ase.row_number ASC,
            ase.bench_slot ASC
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        jsonResponse([
            "success" => false,
            "message" => "Unable to load allocated students."
        ]);
    }

    mysqli_stmt_bind_param($stmt, "i", $allocationId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $allocationRows = [];
    $hallSummary = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $hallId = (int)$row["hall_id"];

        if (!isset($hallSummary[$hallId])) {
            $rows = max(0, (int)$row["rows_count"]);
            $columns = max(0, (int)$row["columns_count"]);
            $decodedColumnBenches = json_decode((string)($row["column_benches"] ?? ""), true);
            $columnBenches = normalizeAllocationColumnBenches($rows, $columns, $decodedColumnBenches);
            $benchTotal = allocationHallBenchTotal($columnBenches);
            $roomCapacity = max(0, (int)$row["capacity"]);

            $totalSeats = min(
                $benchTotal,
                $roomCapacity > 0
                    ? $roomCapacity
                    : $benchTotal
            );

            if ($batch["pattern_type"] === "customized") {
                $first = max(
                    0,
                    (int)(
                        $settings["first_bench_students"]
                        ?? $settings["students_first_bench"]
                        ?? $settings["first_bench"]
                        ?? 1
                    )
                );

                $second = max(
                    0,
                    (int)(
                        $settings["second_bench_students"]
                        ?? $settings["students_second_bench"]
                        ?? $settings["second_bench"]
                        ?? 2
                    )
                );

                $totalSeats = 0;

                for ($rowNumber = 1; $rowNumber <= $rows; $rowNumber++) {
                    $perBench = ($rowNumber % 2 === 1)
                        ? $first
                        : $second;

                    $totalSeats += $columns * $perBench;
                }
            }

            $hallSummary[$hallId] = [
                "hall_id" => $hallId,
                "hall_name" => (string)$row["hall_name"],
                "hall_code" => (string)$row["hall_code"],
                "rows" => $rows,
                "columns" => $columns,
                "room_capacity" => $roomCapacity,
                "total_seats" => $totalSeats,
                "allocated" => 0
            ];
        }

        $hallSummary[$hallId]["allocated"]++;

        $allocationRows[] = [
            "student_id" => (int)$row["student_id"],
            "pin_no" => (string)$row["pin_no"],
            "student_name" => (string)$row["student_name"],
            "department_name" => (string)($row["department_name"] ?? ""),
            "department_code" => (string)($row["department_code"] ?? ""),
            "year_name" => (string)($row["year_name"] ?? ""),
            "semester_number" => $row["semester_number"],
            "semester_name" => (string)($row["semester_name"] ?? ""),
            "hall_id" => $hallId,
            "hall_name" => (string)$row["hall_name"],
            "hall_code" => (string)$row["hall_code"],
            "row" => (int)$row["row_number"],
            "column" => (int)$row["column_number"],
            "bench_slot" => (int)$row["bench_slot"],
            "seat_number" => (string)$row["seat_number"]
        ];
    }

    mysqli_stmt_close($stmt);

    $patternLabel = ucwords(
        str_replace(
            "_",
            " ",
            (string)$batch["pattern_type"]
        )
    );

    jsonResponse([
        "success" => true,
        "allocation_id" => $allocationId,
        "pattern_type" => (string)$batch["pattern_type"],
        "pattern_label" => $patternLabel,
        "pattern_settings" => $settings,
        "total_students" => (int)$batch["total_students"],
        "total_halls" => (int)$batch["total_halls"],
        "confirmed_at" => (string)($batch["confirmed_at"] ?? ""),
        "created_at" => (string)$batch["created_at"],
        "allocation" => $allocationRows,
        "hall_summary" => array_values($hallSummary)
    ]);
}

/* =========================================================
   AJAX - CLEAR PREVIEW
========================================================= */

if (
    isset($_POST["action"]) &&
    $_POST["action"] === "clear_preview"
) {

    unset(
        $_SESSION["seat_allocation_preview"]
    );

    jsonResponse([
        "success" => true
    ]);
}

/* =========================================================
   PAGE COUNTS
========================================================= */

$totalGroups =
    count($groups);

$totalHalls =
    count($halls);


/* =========================================================
   CUSTOM / PHYSICAL CAPACITY
   First/Second Bench counts are applied by ROW:
   odd row  = first-bench count
   even row = second-bench count
   ========================================================= */
function calculateCustomHallCapacity(int $rows, int $columns, int $firstBenchStudents, int $secondBenchStudents): int
{
    $rows = max(0, $rows);
    $columns = max(0, $columns);
    $firstBenchStudents = max(0, $firstBenchStudents);
    $secondBenchStudents = max(0, $secondBenchStudents);

    $perColumn = 0;

    for ($row = 1; $row <= $rows; $row++) {
        $perColumn += (($row % 2) === 1)
            ? $firstBenchStudents
            : $secondBenchStudents;
    }

    return $perColumn * $columns;
}

function calculateHallUsableCapacity(
    array $hall,
    string $pattern,
    int $firstBenchStudents = 1,
    int $secondBenchStudents = 2
): int {
    $rows = (int)($hall['rows_count'] ?? 0);
    $columns = (int)($hall['columns_count'] ?? 0);

    if ($pattern === 'customized') {
        return calculateCustomHallCapacity(
            $rows,
            $columns,
            $firstBenchStudents,
            $secondBenchStudents
        );
    }

    return max(0, $rows * $columns);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    Seat Allocation | Examination Seating System
</title>

<link
    rel="stylesheet"
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
>

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
>

<link
    rel="stylesheet"
    href="../assets/css/seat_allocation.css"
>

<style>
/* =========================================================
   CONFIRMED ALLOCATION MANAGEMENT
========================================================= */
.confirmed-allocations-section {
    margin-top: 30px;
    padding: 34px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 22px;
    box-shadow: 0 12px 35px rgba(15, 23, 42, 0.07);
}

.section-number-history {
    display: flex;
    align-items: center;
    justify-content: center;
}

.confirmed-table-wrapper {
    width: 100%;
    overflow-x: auto;
    margin-top: 24px;
}

.confirmed-allocation-table {
    min-width: 980px;
}

.confirmed-allocation-table td {
    vertical-align: middle;
}

.allocation-code-badge {
    display: inline-flex;
    align-items: center;
    padding: 7px 11px;
    border-radius: 9px;
    background: #eff6ff;
    color: #0f3b82;
    border: 1px solid #bfdbfe;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .2px;
    white-space: nowrap;
}

.allocation-pattern-detail {
    display: block;
    margin-top: 5px;
    color: #64748b;
    font-size: 11px;
}

.btn-danger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 0;
    cursor: pointer;
    background: #b91c1c;
    color: #ffffff;
}

.btn-danger:hover {
    background: #991b1b;
}

.confirmed-action-buttons {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.view-allocation-button {
    white-space: nowrap;
}

.view-allocation-button:disabled {
    opacity: .65;
    cursor: not-allowed;
}

.seating-view-modal {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(15, 23, 42, .68);
    backdrop-filter: blur(5px);
}

.seating-view-modal[hidden] {
    display: none;
}

.seating-view-dialog {
    width: min(1400px, 100%);
    max-height: 92vh;
    overflow: hidden;
    background: #ffffff;
    border-radius: 22px;
    box-shadow: 0 30px 80px rgba(15, 23, 42, .25);
    display: flex;
    flex-direction: column;
}

.seating-view-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 22px 26px;
    border-bottom: 1px solid #e2e8f0;
}

.seating-view-header h2 {
    margin: 0;
    color: #0f172a;
    font-size: 20px;
}

.seating-view-header p {
    margin: 5px 0 0;
    color: #64748b;
    font-size: 12px;
}

.seating-view-close {
    width: 40px;
    height: 40px;
    border: 0;
    border-radius: 10px;
    background: #f1f5f9;
    color: #334155;
    cursor: pointer;
    font-size: 18px;
}

.seating-view-close:hover {
    background: #e2e8f0;
}

.seating-view-body {
    overflow: auto;
    padding: 26px;
}

.seating-view-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}

.seating-view-stat {
    padding: 16px;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    background: #f8fafc;
}

.seating-view-stat span {
    display: block;
    color: #64748b;
    font-size: 11px;
    margin-bottom: 5px;
}

.seating-view-stat strong {
    display: block;
    color: #0f172a;
    font-size: 17px;
}

.seating-view-hall {
    margin-bottom: 28px;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    overflow: hidden;
}

.seating-view-hall:last-child {
    margin-bottom: 0;
}

.seating-view-hall-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 17px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}

.seating-view-hall-header strong {
    color: #0f172a;
}

.seating-view-hall-header span {
    display: block;
    margin-top: 4px;
    color: #64748b;
    font-size: 12px;
}

.seating-view-hall-stats {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.seating-view-pill {
    padding: 6px 10px;
    border-radius: 999px;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 11px;
    font-weight: 700;
}

.seating-view-table-wrap {
    overflow-x: auto;
}

.seating-view-table {
    width: 100%;
    min-width: 900px;
    border-collapse: collapse;
}

.seating-view-table th,
.seating-view-table td {
    padding: 11px 13px;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
    font-size: 12px;
}

.seating-view-table th {
    background: #ffffff;
    color: #475569;
    font-weight: 700;
    white-space: nowrap;
}

.seating-view-table td {
    color: #334155;
}

.seating-view-table tbody tr:hover {
    background: #f8fafc;
}

.seating-view-loading {
    min-height: 220px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    color: #475569;
}

.seating-view-empty {
    padding: 35px;
    text-align: center;
    color: #64748b;
}

@media (max-width: 900px) {
    .seating-view-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 600px) {
    .seating-view-modal {
        padding: 10px;
    }

    .seating-view-dialog {
        max-height: 96vh;
        border-radius: 16px;
    }

    .seating-view-header,
    .seating-view-body {
        padding: 17px;
    }

    .seating-view-summary {
        grid-template-columns: 1fr 1fr;
    }

    .confirmed-action-buttons {
        flex-direction: column;
        align-items: stretch;
    }
}

.cancel-allocation-button {
    white-space: nowrap;
}

.cancel-allocation-button:disabled {
    opacity: .65;
    cursor: not-allowed;
}

.confirmed-empty-state {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-top: 22px;
    padding: 20px;
    border: 1px dashed #cbd5e1;
    border-radius: 16px;
    background: #f8fafc;
    color: #475569;
}

.confirmed-empty-state > i {
    font-size: 20px;
    color: #2563eb;
}

.confirmed-empty-state strong,
.confirmed-empty-state span {
    display: block;
}

.confirmed-empty-state strong {
    color: #0f172a;
    margin-bottom: 4px;
}

.confirmed-empty-state span {
    font-size: 13px;
}

@media (max-width: 768px) {
    .confirmed-allocations-section {
        padding: 22px 16px;
        border-radius: 18px;
    }
}


.hall-column-rows-stat {
    min-width: 150px;
}

.hall-column-rows-list {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 5px;
}

.hall-column-rows-list span {
    display: inline-flex;
    align-items: center;
    padding: 3px 6px;
    border-radius: 6px;
    background: #f1f5f9;
    color: #334155;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}

</style>


<style>
.confirmed-allocations-section { margin-top: 28px; }
.confirmed-allocation-table { width:100%; border-collapse:separate; border-spacing:0; overflow:hidden; }
.confirmed-allocation-table thead th { white-space:nowrap; }
.confirmed-allocation-table tbody tr { transition:background .2s ease; }
.confirmed-allocation-table tbody tr:hover { background:#f8fafc; }
.confirmed-arrangement-cell { display:flex; flex-direction:column; gap:4px; }
.confirmed-arrangement-cell strong { color:#0f172a; font-size:14px; }
.confirmed-arrangement-cell small { color:#64748b; font-size:12px; line-height:1.45; }
.confirmed-allocation-meta { display:flex; flex-wrap:wrap; gap:8px; margin-top:5px; }
.confirmed-meta-pill { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px; background:#f1f5f9; color:#334155; font-size:12px; font-weight:600; }
.confirmed-status-pill { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px; background:#ecfdf5; color:#047857; font-size:12px; font-weight:700; }
.cancel-allocation-button { min-width:132px; }
@media (max-width: 760px) { .confirmed-table-wrapper { overflow-x:auto; } .confirmed-allocation-table { min-width:760px; } }
</style>

<style id="row-column-seat-map-fix">
/* =========================================================
   OLD SEAT-MAP UI — EXACT PHYSICAL COLUMN-FIRST ORDER

   DOM/visual order: R1-C1, R1-C2, R1-C3, R2-C1, R2-C2, R2-C3...
   The allocator itself remains COLUMN-FIRST.
   Every R×C position is always rendered.
========================================================= */
.seat-map-grid {
    display: grid;
    grid-template-rows: repeat(var(--seat-rows), minmax(78px, auto));
    grid-template-columns: repeat(var(--seat-columns), minmax(150px, 1fr));
    grid-auto-flow: row;
    gap: 14px;
    margin-top: 20px;
}

.seat-map-seat {
    min-height: 92px;
    padding: 16px;
    border-radius: 14px;
    border: 1px solid #dbe3ef;
    background: #ffffff;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    box-sizing: border-box;
}

.seat-map-seat.occupied {
    background: #f8fbff;
    border-color: #bfdbfe;
}

.seat-map-seat.empty {
    background: #f8fafc;
    border-style: dashed;
}

.seat-map-seat > span {
    font-size: 11px;
    font-weight: 700;
    color: #123b6d !important;
    background: #ffffff !important;
    background-color: #ffffff !important;
    color: #123b6d !important;
    margin-bottom: 7px;
    padding: 0 !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
}

.seat-map-seat > strong {
    font-size: 13px;
    line-height: 1.55;
    color: #123b6d !important;
}

@media (max-width: 900px) {
    .seat-map-grid {
        grid-template-columns: repeat(var(--seat-columns), minmax(130px, 1fr));
    }
}
</style>

</head>

<body>

<!-- =====================================================
     OVERLAY
====================================================== -->

<div
    class="overlay"
    id="overlay"
></div>


<!-- =====================================================
     SIDEBAR
====================================================== -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-header">

        <h2>ESMS</h2>

        <button
            type="button"
            id="closeSidebar"
        >
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


        <li>
            <a href="examinations.php">
                <i class="fa-solid fa-calendar-days"></i>
                <span>Examination Sessions</span>
            </a>
        </li>


        <li class="active">
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


<!-- =====================================================
     MAIN PAGE
====================================================== -->

<main class="seat-page">


<!-- =====================================================
     HEADER
====================================================== -->

<header class="page-header">

    <div class="header-left">

        <div class="breadcrumb">

            <button
                type="button"
                class="menu-button"
                id="openSidebar"
                title="Open Menu"
            >
                <i class="fa-solid fa-bars"></i>
            </button>


            <a href="dashboard.php">

                <i class="fa-solid fa-house"></i>

                Dashboard

            </a>


            <span>/</span>


            <span>
                Seat Allocation
            </span>

        </div>


        <h1>
            Seat Allocation
        </h1>


        <p>
            Automatically allocate registered students to examination
            halls using a constraint-based seating algorithm.
        </p>

    </div>


    <div class="seat-header-stats">

        <div class="header-stat">

            <i class="fa-solid fa-users"></i>

            <div>

                <strong>
                    <?= $totalGroups ?>
                </strong>

                <span>
                    Student Groups
                </span>

            </div>

        </div>


        <div class="header-stat">

            <i class="fa-solid fa-school"></i>

            <div>

                <strong>
                    <?= $totalHalls ?>
                </strong>

                <span>
                    Halls
                </span>

            </div>

        </div>

    </div>

</header>


<!-- =====================================================
     MAIN CARD
====================================================== -->

<div class="allocation-card">


<!-- =====================================================
     ALLOCATION DATE & TIME
====================================================== -->

<section class="section-block allocation-date-time-section">
    <div class="section-title">
        <div class="section-number"><i class="fa-solid fa-calendar-days"></i></div>
        <div>
            <h2>Allocation Date &amp; Time</h2>
            <p>Select the date, start time and end time for this seating allocation.</p>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;">
        <div>
            <label for="examDate" style="display:block;font-weight:600;margin-bottom:8px;">Exam Date</label>
            <input type="date" id="examDate" name="exam_date" required style="width:100%;padding:12px;border:1px solid #d7deea;border-radius:10px;">
        </div>
        <div>
            <label style="display:block;font-weight:600;margin-bottom:8px;">Start Time</label>
            <div style="display:grid;grid-template-columns:2fr 1fr;gap:8px;">
                <select id="examTimeCombined" aria-label="Start time hour and minute" style="width:100%;padding:12px;border:1px solid #d7deea;border-radius:10px;">
                    <option value="">Hour : Minute</option><option value="01:00">01 : 00</option><option value="01:05">01 : 05</option><option value="01:10">01 : 10</option><option value="01:15">01 : 15</option><option value="01:20">01 : 20</option><option value="01:25">01 : 25</option><option value="01:30">01 : 30</option><option value="01:35">01 : 35</option><option value="01:40">01 : 40</option><option value="01:45">01 : 45</option><option value="01:50">01 : 50</option><option value="01:55">01 : 55</option><option value="02:00">02 : 00</option><option value="02:05">02 : 05</option><option value="02:10">02 : 10</option><option value="02:15">02 : 15</option><option value="02:20">02 : 20</option><option value="02:25">02 : 25</option><option value="02:30">02 : 30</option><option value="02:35">02 : 35</option><option value="02:40">02 : 40</option><option value="02:45">02 : 45</option><option value="02:50">02 : 50</option><option value="02:55">02 : 55</option><option value="03:00">03 : 00</option><option value="03:05">03 : 05</option><option value="03:10">03 : 10</option><option value="03:15">03 : 15</option><option value="03:20">03 : 20</option><option value="03:25">03 : 25</option><option value="03:30">03 : 30</option><option value="03:35">03 : 35</option><option value="03:40">03 : 40</option><option value="03:45">03 : 45</option><option value="03:50">03 : 50</option><option value="03:55">03 : 55</option><option value="04:00">04 : 00</option><option value="04:05">04 : 05</option><option value="04:10">04 : 10</option><option value="04:15">04 : 15</option><option value="04:20">04 : 20</option><option value="04:25">04 : 25</option><option value="04:30">04 : 30</option><option value="04:35">04 : 35</option><option value="04:40">04 : 40</option><option value="04:45">04 : 45</option><option value="04:50">04 : 50</option><option value="04:55">04 : 55</option><option value="05:00">05 : 00</option><option value="05:05">05 : 05</option><option value="05:10">05 : 10</option><option value="05:15">05 : 15</option><option value="05:20">05 : 20</option><option value="05:25">05 : 25</option><option value="05:30">05 : 30</option><option value="05:35">05 : 35</option><option value="05:40">05 : 40</option><option value="05:45">05 : 45</option><option value="05:50">05 : 50</option><option value="05:55">05 : 55</option><option value="06:00">06 : 00</option><option value="06:05">06 : 05</option><option value="06:10">06 : 10</option><option value="06:15">06 : 15</option><option value="06:20">06 : 20</option><option value="06:25">06 : 25</option><option value="06:30">06 : 30</option><option value="06:35">06 : 35</option><option value="06:40">06 : 40</option><option value="06:45">06 : 45</option><option value="06:50">06 : 50</option><option value="06:55">06 : 55</option><option value="07:00">07 : 00</option><option value="07:05">07 : 05</option><option value="07:10">07 : 10</option><option value="07:15">07 : 15</option><option value="07:20">07 : 20</option><option value="07:25">07 : 25</option><option value="07:30">07 : 30</option><option value="07:35">07 : 35</option><option value="07:40">07 : 40</option><option value="07:45">07 : 45</option><option value="07:50">07 : 50</option><option value="07:55">07 : 55</option><option value="08:00">08 : 00</option><option value="08:05">08 : 05</option><option value="08:10">08 : 10</option><option value="08:15">08 : 15</option><option value="08:20">08 : 20</option><option value="08:25">08 : 25</option><option value="08:30">08 : 30</option><option value="08:35">08 : 35</option><option value="08:40">08 : 40</option><option value="08:45">08 : 45</option><option value="08:50">08 : 50</option><option value="08:55">08 : 55</option><option value="09:00">09 : 00</option><option value="09:05">09 : 05</option><option value="09:10">09 : 10</option><option value="09:15">09 : 15</option><option value="09:20">09 : 20</option><option value="09:25">09 : 25</option><option value="09:30">09 : 30</option><option value="09:35">09 : 35</option><option value="09:40">09 : 40</option><option value="09:45">09 : 45</option><option value="09:50">09 : 50</option><option value="09:55">09 : 55</option><option value="10:00">10 : 00</option><option value="10:05">10 : 05</option><option value="10:10">10 : 10</option><option value="10:15">10 : 15</option><option value="10:20">10 : 20</option><option value="10:25">10 : 25</option><option value="10:30">10 : 30</option><option value="10:35">10 : 35</option><option value="10:40">10 : 40</option><option value="10:45">10 : 45</option><option value="10:50">10 : 50</option><option value="10:55">10 : 55</option><option value="11:00">11 : 00</option><option value="11:05">11 : 05</option><option value="11:10">11 : 10</option><option value="11:15">11 : 15</option><option value="11:20">11 : 20</option><option value="11:25">11 : 25</option><option value="11:30">11 : 30</option><option value="11:35">11 : 35</option><option value="11:40">11 : 40</option><option value="11:45">11 : 45</option><option value="11:50">11 : 50</option><option value="11:55">11 : 55</option><option value="12:00">12 : 00</option><option value="12:05">12 : 05</option><option value="12:10">12 : 10</option><option value="12:15">12 : 15</option><option value="12:20">12 : 20</option><option value="12:25">12 : 25</option><option value="12:30">12 : 30</option><option value="12:35">12 : 35</option><option value="12:40">12 : 40</option><option value="12:45">12 : 45</option><option value="12:50">12 : 50</option><option value="12:55">12 : 55</option>
                </select>
                <select id="examTimeHour" hidden> aria-label="Start time hour" style="width:100%;padding:12px;border:1px solid #d7deea;border-radius:10px;">
                    <option value="">Hour</option><option value="01">01</option><option value="02">02</option><option value="03">03</option><option value="04">04</option><option value="05">05</option><option value="06">06</option><option value="07">07</option><option value="08">08</option><option value="09">09</option><option value="10">10</option><option value="11">11</option><option value="12">12</option>
                </select>
                <select id="examTimeMinute" hidden>
                    <option value="">Minute</option><option value="00">00</option><option value="05">05</option><option value="10">10</option><option value="15">15</option><option value="20">20</option><option value="25">25</option><option value="30">30</option><option value="35">35</option><option value="40">40</option><option value="45">45</option><option value="50">50</option><option value="55">55</option>
                </select>
                <select id="examTimePeriod" aria-label="Start time AM or PM" style="width:100%;padding:12px;border:1px solid #d7deea;border-radius:10px;">
                    <option value="">AM/PM</option><option value="AM">AM</option><option value="PM">PM</option>
                </select>
            </div>
            <input type="hidden" id="examTime" name="exam_time">
        </div>
        <div>
            <label style="display:block;font-weight:600;margin-bottom:8px;">End Time</label>
            <div style="display:grid;grid-template-columns:2fr 1fr;gap:8px;">
                <select id="examTimeToCombined" aria-label="End time hour and minute" style="width:100%;padding:12px;border:1px solid #d7deea;border-radius:10px;">
                    <option value="">Hour : Minute</option><option value="01:00">01 : 00</option><option value="01:05">01 : 05</option><option value="01:10">01 : 10</option><option value="01:15">01 : 15</option><option value="01:20">01 : 20</option><option value="01:25">01 : 25</option><option value="01:30">01 : 30</option><option value="01:35">01 : 35</option><option value="01:40">01 : 40</option><option value="01:45">01 : 45</option><option value="01:50">01 : 50</option><option value="01:55">01 : 55</option><option value="02:00">02 : 00</option><option value="02:05">02 : 05</option><option value="02:10">02 : 10</option><option value="02:15">02 : 15</option><option value="02:20">02 : 20</option><option value="02:25">02 : 25</option><option value="02:30">02 : 30</option><option value="02:35">02 : 35</option><option value="02:40">02 : 40</option><option value="02:45">02 : 45</option><option value="02:50">02 : 50</option><option value="02:55">02 : 55</option><option value="03:00">03 : 00</option><option value="03:05">03 : 05</option><option value="03:10">03 : 10</option><option value="03:15">03 : 15</option><option value="03:20">03 : 20</option><option value="03:25">03 : 25</option><option value="03:30">03 : 30</option><option value="03:35">03 : 35</option><option value="03:40">03 : 40</option><option value="03:45">03 : 45</option><option value="03:50">03 : 50</option><option value="03:55">03 : 55</option><option value="04:00">04 : 00</option><option value="04:05">04 : 05</option><option value="04:10">04 : 10</option><option value="04:15">04 : 15</option><option value="04:20">04 : 20</option><option value="04:25">04 : 25</option><option value="04:30">04 : 30</option><option value="04:35">04 : 35</option><option value="04:40">04 : 40</option><option value="04:45">04 : 45</option><option value="04:50">04 : 50</option><option value="04:55">04 : 55</option><option value="05:00">05 : 00</option><option value="05:05">05 : 05</option><option value="05:10">05 : 10</option><option value="05:15">05 : 15</option><option value="05:20">05 : 20</option><option value="05:25">05 : 25</option><option value="05:30">05 : 30</option><option value="05:35">05 : 35</option><option value="05:40">05 : 40</option><option value="05:45">05 : 45</option><option value="05:50">05 : 50</option><option value="05:55">05 : 55</option><option value="06:00">06 : 00</option><option value="06:05">06 : 05</option><option value="06:10">06 : 10</option><option value="06:15">06 : 15</option><option value="06:20">06 : 20</option><option value="06:25">06 : 25</option><option value="06:30">06 : 30</option><option value="06:35">06 : 35</option><option value="06:40">06 : 40</option><option value="06:45">06 : 45</option><option value="06:50">06 : 50</option><option value="06:55">06 : 55</option><option value="07:00">07 : 00</option><option value="07:05">07 : 05</option><option value="07:10">07 : 10</option><option value="07:15">07 : 15</option><option value="07:20">07 : 20</option><option value="07:25">07 : 25</option><option value="07:30">07 : 30</option><option value="07:35">07 : 35</option><option value="07:40">07 : 40</option><option value="07:45">07 : 45</option><option value="07:50">07 : 50</option><option value="07:55">07 : 55</option><option value="08:00">08 : 00</option><option value="08:05">08 : 05</option><option value="08:10">08 : 10</option><option value="08:15">08 : 15</option><option value="08:20">08 : 20</option><option value="08:25">08 : 25</option><option value="08:30">08 : 30</option><option value="08:35">08 : 35</option><option value="08:40">08 : 40</option><option value="08:45">08 : 45</option><option value="08:50">08 : 50</option><option value="08:55">08 : 55</option><option value="09:00">09 : 00</option><option value="09:05">09 : 05</option><option value="09:10">09 : 10</option><option value="09:15">09 : 15</option><option value="09:20">09 : 20</option><option value="09:25">09 : 25</option><option value="09:30">09 : 30</option><option value="09:35">09 : 35</option><option value="09:40">09 : 40</option><option value="09:45">09 : 45</option><option value="09:50">09 : 50</option><option value="09:55">09 : 55</option><option value="10:00">10 : 00</option><option value="10:05">10 : 05</option><option value="10:10">10 : 10</option><option value="10:15">10 : 15</option><option value="10:20">10 : 20</option><option value="10:25">10 : 25</option><option value="10:30">10 : 30</option><option value="10:35">10 : 35</option><option value="10:40">10 : 40</option><option value="10:45">10 : 45</option><option value="10:50">10 : 50</option><option value="10:55">10 : 55</option><option value="11:00">11 : 00</option><option value="11:05">11 : 05</option><option value="11:10">11 : 10</option><option value="11:15">11 : 15</option><option value="11:20">11 : 20</option><option value="11:25">11 : 25</option><option value="11:30">11 : 30</option><option value="11:35">11 : 35</option><option value="11:40">11 : 40</option><option value="11:45">11 : 45</option><option value="11:50">11 : 50</option><option value="11:55">11 : 55</option><option value="12:00">12 : 00</option><option value="12:05">12 : 05</option><option value="12:10">12 : 10</option><option value="12:15">12 : 15</option><option value="12:20">12 : 20</option><option value="12:25">12 : 25</option><option value="12:30">12 : 30</option><option value="12:35">12 : 35</option><option value="12:40">12 : 40</option><option value="12:45">12 : 45</option><option value="12:50">12 : 50</option><option value="12:55">12 : 55</option>
                </select>
                <select id="examTimeToHour" hidden> aria-label="End time hour" style="width:100%;padding:12px;border:1px solid #d7deea;border-radius:10px;">
                    <option value="">Hour</option><option value="01">01</option><option value="02">02</option><option value="03">03</option><option value="04">04</option><option value="05">05</option><option value="06">06</option><option value="07">07</option><option value="08">08</option><option value="09">09</option><option value="10">10</option><option value="11">11</option><option value="12">12</option>
                </select>
                <select id="examTimeToMinute" hidden>
                    <option value="">Minute</option><option value="00">00</option><option value="05">05</option><option value="10">10</option><option value="15">15</option><option value="20">20</option><option value="25">25</option><option value="30">30</option><option value="35">35</option><option value="40">40</option><option value="45">45</option><option value="50">50</option><option value="55">55</option>
                </select>
                <select id="examTimeToPeriod" aria-label="End time AM or PM" style="width:100%;padding:12px;border:1px solid #d7deea;border-radius:10px;">
                    <option value="">AM/PM</option><option value="AM">AM</option><option value="PM">PM</option>
                </select>
            </div>
            <input type="hidden" id="examTimeTo" name="exam_time_to">
        </div>
    </div>
</section>

<!-- =====================================================
     SECTION 1
====================================================== -->

<section class="section-block">

    <div class="section-title">

        <div class="section-number">
            1
        </div>

        <div>

            <h2>
                Select Student Groups
            </h2>

            <p>
                Select one or more Branch + Year + Semester groups.
            </p>

        </div>

    </div>


    <?php if (!$groups): ?>

        <div class="empty-state">

            <i class="fa-solid fa-users-slash"></i>

            <h3>
                No Student Groups Found
            </h3>

            <p>
                Add students with branch, academic year and semester information.
            </p>

        </div>

    <?php else: ?>

        <div class="group-grid">

            <?php foreach ($groups as $group): ?>

                <?php

                $groupKey =
                    $group["department_id"] .
                    ":" .
                    $group["academic_year_id"] .
                    ":" .
                    $group["semester_id"];

                ?>

                <label
                    class="group-card"
                    for="group_<?= clean($groupKey) ?>"
                >

                    <input
                        type="checkbox"
                        class="group-checkbox"
                        id="group_<?= clean($groupKey) ?>"
                        value="<?= clean($groupKey) ?>"
                    >


                    <span class="group-checkmark">

                        <i class="fa-solid fa-check"></i>

                    </span>


                    <div class="group-content">

                        <div class="group-code">

                            <?= clean(
                                $group["department_code"]
                            ) ?>

                        </div>


                        <h3>

                            <?= clean(
                                $group["department_name"]
                            ) ?>

                        </h3>


                        <div class="group-details">

                            <span>

                                <i class="fa-solid fa-calendar"></i>

                                <?= clean(
                                    $group["year_name"] ??
                                    "Year Not Set"
                                ) ?>

                            </span>


                            <span>

                                <i class="fa-solid fa-layer-group"></i>

                                <?= clean(
                                    $group["semester_name"] ??
                                    (
                                        "Semester " .
                                        $group["semester_number"]
                                    )
                                ) ?>

                            </span>

                        </div>


                        <div class="student-count">

                            <i class="fa-solid fa-user-graduate"></i>

                            <?= intval(
                                $group["total_students"]
                            ) ?>

                            Students

                        </div>

                    </div>

                </label>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>


<!-- =====================================================
     SECTION 2
====================================================== -->

<section class="section-block">

    <div class="section-title">

        <div class="section-number">
            2
        </div>

        <div>

            <h2>
                Select Examination Halls
            </h2>

            <p>
                Select one or more halls for automatic allocation.
            </p>

        </div>

    </div>


    <?php if (!$halls): ?>

        <div class="empty-state">

            <i class="fa-solid fa-building-circle-exclamation"></i>

            <h3>
                No Examination Halls Found
            </h3>

            <p>
                Add examination halls from the Hall Management module.
            </p>

        </div>

    <?php else: ?>

        <div class="hall-grid">

            <?php foreach ($halls as $hall): ?>

                <label
                    class="hall-card"
                    for="hall_<?= intval($hall["id"]) ?>"
                >

                    <input
                        type="checkbox"
                        class="hall-checkbox"
                        id="hall_<?= intval($hall["id"]) ?>"
                        value="<?= intval($hall["id"]) ?>"
                        data-capacity="<?= intval($hall["capacity"]) ?>"
                        data-rows="<?= intval($hall["rows_count"]) ?>"
                        data-columns="<?= intval($hall["columns_count"]) ?>"
                        data-column-benches="<?= clean(json_encode($hall["column_benches_array"] ?? [])) ?>"
                    >


                    <span class="hall-checkmark">

                        <i class="fa-solid fa-check"></i>

                    </span>


                    <div class="hall-content">

                        <div class="hall-top">

                            <div class="hall-icon">

                                <i class="fa-solid fa-school"></i>

                            </div>


                            <div>

                                <h3>
                                    <?= clean(
                                        $hall["hall_name"]
                                    ) ?>
                                </h3>

                                <span>

                                    Hall Code:
                                    <?= clean(
                                        $hall["hall_code"]
                                    ) ?>

                                </span>

                            </div>

                        </div>


                        <div class="hall-stats">

                            <div>

                                <i class="fa-solid fa-chair"></i>

                                <strong>
                                    <?= intval($hall["capacity"]) ?>
                                </strong>

                                <small>
                                    Capacity
                                </small>

                            </div>

                            <div>

                                <i class="fa-solid fa-arrows-left-right"></i>

                                <strong>
                                    <?= intval($hall["columns_count"]) ?>
                                </strong>

                                <small>
                                    Columns
                                </small>

                            </div>

                            <div class="hall-column-rows-stat">

                                <i class="fa-solid fa-table-list"></i>

                                <strong>
                                    Column Benches
                                </strong>

                                <div class="hall-column-rows-list">
                                    <?php foreach (($hall["column_benches_array"] ?? []) as $columnIndex => $benchCount): ?>
                                        <span>
                                            C<?= intval($columnIndex + 1) ?>: <?= intval($benchCount) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>

                            </div>

                        </div>

                    </div>

                </label>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>


<!-- =====================================================
     SECTION 3
====================================================== -->

<section class="section-block">

    <div class="section-title">

        <div class="section-number">
            3
        </div>

        <div>

            <h2>
                Allocation Pattern
            </h2>

            <p>
                Select how physical seats should be used.
            </p>

        </div>

    </div>


    <div class="pattern-grid">


        <label class="pattern-card">

            <input
                type="radio"
                name="allocation_pattern"
                value="single"
                checked
            >

            <div class="pattern-content">

                <div class="pattern-icon">

                    <i class="fa-solid fa-user"></i>

                </div>

                <h3>
                    Single Person
                </h3>

                <p>
                    One student per available physical seat.
                </p>

            </div>

        </label>


        


        <label class="pattern-card">

            <input
                type="radio"
                name="allocation_pattern"
                value="alternative"
            >

            <div class="pattern-content">

                <div class="pattern-icon">

                    <i class="fa-solid fa-chess-board"></i>

                </div>

                <h3>
                    Alternative
                </h3>

                <p>
                    Uses alternating physical seats for spacing.
                </p>

            </div>

        </label>


        <label class="pattern-card">

            <input
                type="radio"
                name="allocation_pattern"
                value="customized"
            >

            <div class="pattern-content">

                <div class="pattern-icon">

                    <i class="fa-solid fa-sliders"></i>

                </div>

                <h3>
                    Customized
                </h3>

                <p>
                    Configure only students per row and students per column.
                </p>

            </div>

        </label>

    </div>


    <!-- =================================================
     CUSTOM SETTINGS
================================================== -->

<div
    class="custom-settings"
    id="customSettings"
    hidden
>

    <div class="custom-settings-header">

        <div class="custom-settings-title">

            <i class="fa-solid fa-sliders"></i>

            <div>

                <h3>
                    Customized Allocation
                </h3>

                <p>
                    Set the number of students allowed on each bench.
                </p>

            </div>

        </div>


        <div class="custom-capacity-badge">

            Usable Capacity:

            <strong id="customUsableCapacity">
                0
            </strong>

        </div>

    </div>


    <!-- =================================================
         BENCH STUDENT SETTINGS
    ================================================== -->

    <div class="custom-settings-grid">


        <!-- FIRST BENCH -->

        <div class="setting-field">

            <label for="firstBenchStudents">

                <i class="fa-solid fa-chair"></i>

                Students on First Bench

            </label>


            <input
                type="number"
                id="firstBenchStudents"
                min="0"
                step="1"
                value="1"
            >


            <small>
                Number of students allowed on the first bench.
            </small>

        </div>



        <!-- SECOND BENCH -->

        <div class="setting-field">

            <label for="secondBenchStudents">

                <i class="fa-solid fa-chair"></i>

                Students on Second Bench

            </label>


            <input
                type="number"
                id="secondBenchStudents"
                min="0"
                step="1"
                value="2"
            >


            <small>
                Number of students allowed on the second bench.
            </small>

        </div>

    </div>



    <!-- =================================================
         GROUP OPTIONS
    ================================================== -->

    

</div>

<!-- =====================================================
     SECTION 4 - LOADED STUDENTS
====================================================== -->

<section
    class="loaded-students-section"
    id="loadedStudentsSection"
    hidden
>

    <div class="section-title">

        <div class="section-number">
            4
        </div>

        <div>

            <h2>
                Selected Student PIN Numbers
            </h2>

            <p>
                These are the actual registered students from the Student Module.
            </p>

        </div>

    </div>


    <div class="loaded-students-card">

        <div class="loaded-toolbar">

            <div class="loaded-total">

                <i class="fa-solid fa-user-graduate"></i>

                <div>

                    <span>
                        Total Selected Students
                    </span>

                    <strong id="loadedStudentTotal">
                        0
                    </strong>

                </div>

            </div>


            <div
                class="loaded-status"
                id="loadedStatus"
            >
                Ready for allocation
            </div>

        </div>


        <div class="student-table-wrapper">

            <table class="student-table">

                <thead>

                    <tr>

                        <th>
                            #
                        </th>

                        <th>
                            PIN Number
                        </th>

                        <th>
                            Student Name
                        </th>

                        <th>
                            Branch
                        </th>

                        <th>
                            Year
                        </th>

                        <th>
                            Semester
                        </th>

                    </tr>

                </thead>


                <tbody id="loadedStudentsBody">
                </tbody>

            </table>

        </div>

    </div>

</section>


<!-- =====================================================
     SECTION 5 - ALLOCATION PREVIEW
====================================================== -->

<section
    class="allocation-preview-section"
    id="allocationPreviewSection"
    hidden
>

    <div class="section-title">

        <div class="section-number">
            5
        </div>

        <div>

            <h2>
                Automatic Seating Preview
            </h2>

            <p>
                Review the constraint-based seat allocation before confirming it.
            </p>

        </div>

    </div>


    <div class="preview-toolbar">

        <div>

            <strong>
                Generated Seating
            </strong>

            <span id="previewStatus">
                Ready
            </span>

        </div>


        <div class="preview-actions">

            <button
                type="button"
                class="btn btn-secondary"
                id="regenerateAllocation"
            >

                <i class="fa-solid fa-arrows-rotate"></i>

                Regenerate

            </button>


            <button
                type="button"
                class="btn btn-secondary"
                id="shuffleHalls"
            >

                <i class="fa-solid fa-shuffle"></i>

                Shuffle Halls

            </button>


            <button
                type="button"
                class="btn btn-success"
                id="confirmAllocation"
            >

                <i class="fa-solid fa-circle-check"></i>

                Confirm Allocation

            </button>

        </div>

    </div>


    <!-- HALL SUMMARY -->

    <div
        class="hall-summary-grid"
        id="hallSummaryGrid"
    >
    </div>


    <!-- SEAT TABLE -->

    <div class="student-table-wrapper">

        <table class="student-table allocation-preview-table">

            <thead>

                <tr>

                    <th>
                        #
                    </th>

                    <th>
                        PIN
                    </th>

                    <th>
                        Student Name
                    </th>

                    <th>
                        Branch
                    </th>

                    <th>
                        Year
                    </th>

                    <th>
                        Semester
                    </th>

                    <th>
                        Hall
                    </th>

                    <th>
                        Row
                    </th>

                    <th>
                        Column
                    </th>

                    <th>
                        Seat
                    </th>

                </tr>

            </thead>


            <tbody id="allocationPreviewBody">
            </tbody>

        </table>

    </div>


    <!-- HALL SEAT MAPS -->

    <div
        class="seat-map-container"
        id="seatMapContainer"
    >
    </div>

</section>


<!-- =====================================================
     CONFIRMED ALLOCATIONS
====================================================== -->
<section class="confirmed-allocations-section" id="confirmedAllocationsSection">

    <div class="section-title">
        <div class="section-number section-number-history">
            <i class="fa-solid fa-clock-rotate-left"></i>
        </div>
        <div>
            <h2>Confirmed Seating Allocations</h2>
            <p>View and manage active seating arrangements. Cancel an allocation whenever it is no longer required.</p>
        </div>
    </div>

    <?php if (!$confirmedAllocations): ?>
        <div class="confirmed-empty-state" id="confirmedEmptyState">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                <strong>No confirmed allocations</strong>
                <span>Confirmed seating allocations will appear here after you save one.</span>
            </div>
        </div>
    <?php else: ?>
        <div class="confirmed-table-wrapper">
            <table class="student-table confirmed-allocation-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Seating Arrangement</th>
                        <th>Students</th>
                        <th>Halls</th>
                        <th>Confirmed</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="confirmedAllocationsBody">
                    <?php foreach ($confirmedAllocations as $index => $allocation): ?>
                        <tr id="allocation-row-<?= intval($allocation["id"]) ?>">
                            <td><?= $index + 1 ?></td>
                            <td>
                                <div class="confirmed-arrangement-cell">
                                    <strong><?= clean($allocation["pattern_label"]) ?></strong>
                                <?php if ($allocation["pattern_type"] === "customized"): ?>
                                    <small class="allocation-pattern-detail">
                                        <?= clean($allocation["pattern_detail"]) ?>
                                    </small>
                                <?php endif; ?>
                                </div>
                            </td>
                            <td><strong><?= intval($allocation["total_students"]) ?></strong></td>
                            <td><strong><?= intval($allocation["used_halls"]) ?></strong></td>
                            <td><?= clean($allocation["confirmed_at"] ?: $allocation["created_at"]) ?></td>
                            <td>
                                <div class="confirmed-action-buttons">
                                    <button
                                        type="button"
                                        class="btn btn-primary view-allocation-button"
                                        data-allocation-id="<?= intval($allocation["id"]) ?>"
                                    >
                                        <i class="fa-solid fa-eye"></i>
                                        View Seating
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-danger cancel-allocation-button"
                                        data-allocation-id="<?= intval($allocation["id"]) ?>"
                                        data-students="<?= intval($allocation["total_students"]) ?>"
                                        data-halls="<?= intval($allocation["used_halls"]) ?>"
                                    >
                                        <i class="fa-solid fa-trash-can"></i>
                                        Cancel Allocation
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>


<!-- =====================================================
     CONFIRMED SEATING VIEW MODAL
====================================================== -->
<div class="seating-view-modal" id="seatingViewModal" hidden>
    <div class="seating-view-dialog" role="dialog" aria-modal="true" aria-labelledby="seatingViewTitle">
        <div class="seating-view-header">
            <div>
                <h2 id="seatingViewTitle">Confirmed Seating Arrangement</h2>
                <p id="seatingViewSubtitle">Loading seating allocation...</p>
            </div>

            <button
                type="button"
                class="seating-view-close"
                id="closeSeatingView"
                aria-label="Close seating view"
            >
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="seating-view-body" id="seatingViewBody">
            <div class="seating-view-loading">
                <i class="fa-solid fa-spinner fa-spin"></i>
                Loading seating arrangement...
            </div>
        </div>
    </div>
</div>


<!-- =====================================================
     SUMMARY
====================================================== -->

<section class="allocation-summary-section">

    <div class="summary-label">
        Allocation Summary
    </div>


    <div class="allocation-summary">


        <div class="summary-item">

            <i class="fa-solid fa-users"></i>

            <div>

                <span>
                    Selected Students
                </span>

                <strong id="totalStudents">
                    0
                </strong>

            </div>

        </div>


        <div class="summary-item">

            <i class="fa-solid fa-building"></i>

            <div>

                <span>
                    Selected Halls
                </span>

                <strong id="totalHallsSelected">
                    0
                </strong>

            </div>

        </div>


        <div class="summary-item">

            <i class="fa-solid fa-chair"></i>

            <div>

                <span>
                    Physical Hall Capacity
                </span>

                <strong id="totalCapacity">
                    0
                </strong>

            </div>

        </div>


        <div class="summary-item summary-usable">

            <i class="fa-solid fa-check-double"></i>

            <div>

                <span>
                    Usable Capacity
                </span>

                <strong id="usableCapacity">
                    0
                </strong>

            </div>

        </div>

    </div>

</section>


<!-- =====================================================
     ACTIONS
====================================================== -->

<div class="form-actions">

    <button
        type="button"
        class="btn btn-secondary"
        id="resetAllocation"
    >

        <i class="fa-solid fa-rotate-left"></i>

        Reset

    </button>


    <button
        type="button"
        class="btn btn-primary"
        id="loadPins"
    >

        <i class="fa-solid fa-list-check"></i>

        Load PIN Numbers

    </button>


    <button
        type="button"
        class="btn btn-primary"
        id="generateSeating"
        disabled
    >

        <i class="fa-solid fa-wand-magic-sparkles"></i>

        Generate Seating

    </button>

</div>


</div>

</main>


<!-- =====================================================
     JAVASCRIPT
====================================================== -->

<script>

document.addEventListener(
    "DOMContentLoaded",
    function () {

        /* =================================================
           HELPERS
        ================================================= */

        const $ = function (id) {
            return document.getElementById(id);
        };


        const groups = [
            ...document.querySelectorAll(
                ".group-checkbox"
            )
        ];


        const halls = [
            ...document.querySelectorAll(
                ".hall-checkbox"
            )
        ];


        /*
         * Preserve the order in which the administrator selects groups.
         * This order determines the branch sequence during Single Person
         * allocation, instead of the default order in the HTML.
         */
        let selectedGroupOrder = [];

        groups.forEach(function (checkbox) {
            checkbox.addEventListener("change", function () {
                const value = checkbox.value;

                if (checkbox.checked) {
                    selectedGroupOrder = selectedGroupOrder.filter(
                        function (item) { return item !== value; }
                    );
                    selectedGroupOrder.push(value);
                } else {
                    selectedGroupOrder = selectedGroupOrder.filter(
                        function (item) { return item !== value; }
                    );
                }
            });

            // Support any groups that may already be checked on page load.
            if (checkbox.checked && !selectedGroupOrder.includes(checkbox.value)) {
                selectedGroupOrder.push(checkbox.value);
            }
        });

        function getSelectedGroupsInOrder() {
            const checkedValues = groups
                .filter(function (checkbox) { return checkbox.checked; })
                .map(function (checkbox) { return checkbox.value; });

            // Keep click order, while safely including any checked boxes
            // that were checked programmatically and did not fire change.
            const ordered = selectedGroupOrder.filter(function (value) {
                return checkedValues.includes(value);
            });

            checkedValues.forEach(function (value) {
                if (!ordered.includes(value)) ordered.push(value);
            });

            return ordered;
        }


        let students = [];

        let generatedAllocation = [];

        let lastGenerationRequest = null;


        /* =================================================
           SIDEBAR
        ================================================= */

        function openMenu() {

            $("sidebar").classList.add("show");

            $("overlay").classList.add("show");
        }


        function closeMenu() {

            $("sidebar").classList.remove("show");

            $("overlay").classList.remove("show");
        }


        $("openSidebar").addEventListener(
            "click",
            openMenu
        );


        $("closeSidebar").addEventListener(
            "click",
            closeMenu
        );


        $("overlay").addEventListener(
            "click",
            closeMenu
        );


        /* =================================================
           SELECTED HALLS
        ================================================= */

        function selectedHalls() {

            return halls
                .filter(function (checkbox) {

                    return checkbox.checked;

                })
                .map(function (checkbox) {

                    return {

                        id:
                            Number(
                                checkbox.value
                            ),

                        rows:
                            Number(
                                checkbox.dataset.rows
                            ) || 0,

                        columns:
                            Number(
                                checkbox.dataset.columns
                            ) || 0,

                        columnBenches:
                            (function () {
                                try {
                                    return JSON.parse(checkbox.dataset.columnBenches || "[]");
                                } catch (e) {
                                    return [];
                                }
                            })(),

                        capacity:
                            Number(
                                checkbox.dataset.capacity
                            ) || 0
                    };

                });
        }


        /* =================================================
           GROUP TOTAL
        ================================================= */

        function groupTotal() {

            let total = 0;

            groups.forEach(
                function (checkbox) {

                    if (!checkbox.checked) {
                        return;
                    }

                    const card =
                        checkbox.closest(
                            ".group-card"
                        );

                    const countElement =
                        card.querySelector(
                            ".student-count"
                        );

                    const match =
                        countElement
                            .textContent
                            .match(/\d+/);

                    if (match) {
                        total +=
                            Number(match[0]);
                    }

                }
            );

            return total;
        }

/* =================================================
   CUSTOM SETTINGS
================================================= */

/* =================================================
   12-HOUR AM/PM TIME CONTROLS
   Visible controls use 12-hour time. Hidden inputs use HH:MM
   so the existing PHP/database logic remains unchanged.
================================================= */
function update12HourTime(hourId, minuteId, periodId, hiddenId) {
    const hour = $(hourId).value;
    const minute = $(minuteId).value;
    const period = $(periodId).value;

    if (!hour || !minute || !period) {
        $(hiddenId).value = "";
        return;
    }

    let h = parseInt(hour, 10);
    if (period === "AM") {
        if (h === 12) h = 0;
    } else if (h !== 12) {
        h += 12;
    }

    $(hiddenId).value = String(h).padStart(2, "0") + ":" + minute;
}

function setup12HourTimeControls() {
    [
        ["examTimeHour", "examTimeMinute", "examTimePeriod", "examTime", "examTimeCombined"],
        ["examTimeToHour", "examTimeToMinute", "examTimeToPeriod", "examTimeTo", "examTimeToCombined"]
    ].forEach(function (ids) {
        [ids[0], ids[1], ids[2], ids[4]].forEach(function (id) {
            $(id).addEventListener("change", function () {
                if (id === ids[4]) {
                    const parts = $(id).value.split(":");
                    $(ids[0]).value = parts[0] || "";
                    $(ids[1]).value = parts[1] || "";
                }
                update12HourTime(ids[0], ids[1], ids[2], ids[3]);
            });
        });
    });
}

setup12HourTimeControls();

function customSettings() {

    return {

        first_bench_students:
            Math.max(
                0,
                parseInt(
                    $("firstBenchStudents").value,
                    10
                ) || 0
            ),

        second_bench_students:
            Math.max(
                0,
                parseInt(
                    $("secondBenchStudents").value,
                    10
                ) || 0
            )

    };
}


/* =================================================
   CUSTOM HALL CAPACITY
================================================= */

function hallBenchTotalClient(hall) {
    const values = Array.isArray(hall.columnBenches) && hall.columnBenches.length
        ? hall.columnBenches
        : Array(Math.max(0, hall.columns || 0)).fill(Math.max(0, hall.rows || 0));
    return values.reduce(function(total, value) {
        return total + Math.max(0, Number(value) || 0);
    }, 0);
}

function customHallCapacity(hall) {
    const settings = customSettings();
    const values = Array.isArray(hall.columnBenches) && hall.columnBenches.length
        ? hall.columnBenches
        : Array(Math.max(0, hall.columns || 0)).fill(Math.max(0, hall.rows || 0));

    let totalCapacity = 0;
    values.forEach(function(columnRows) {
        for (let row = 1; row <= Number(columnRows || 0); row++) {
            totalCapacity += (row % 2 === 1)
                ? settings.first_bench_students
                : settings.second_bench_students;
        }
    });
    return totalCapacity;
}

/* =================================================
   USABLE CAPACITY
================================================= */

function usableCapacity() {
    const selected = selectedHalls();
    const radio = document.querySelector('[name="allocation_pattern"]:checked');
    if (!radio) return 0;

    const pattern = radio.value;

    return selected.reduce(function(total, hall) {
        const physical = hallBenchTotalClient(hall);

        if (pattern === "single") {
            return total + Math.min(physical, hall.capacity || physical);
        }

        if (pattern === "two_persons") {
            return total + Math.min(physical * 2, hall.capacity || physical * 2);
        }

        if (pattern === "alternative") {
            return total + Math.ceil(physical / 2);
        }

        if (pattern === "customized") {
            return total + customHallCapacity(hall);
        }

        return total;
    }, 0);
}

/* =================================================
   UPDATE SUMMARY
================================================= */

function updateSummary() {

    const studentCount =
        students.length ||
        groupTotal();


    $("totalStudents")
        .textContent =
        studentCount;


    $("totalHallsSelected")
        .textContent =
        selectedHalls().length;


    $("totalCapacity")
        .textContent =
        selectedHalls().reduce(
            function (
                total,
                hall
            ) {

                return total + hallBenchTotalClient(hall);

            },
            0
        );


    const usable =
        usableCapacity();


    $("usableCapacity")
        .textContent =
        usable;


    $("customUsableCapacity")
        .textContent =
        usable;


    updateGenerateButton();
}


/* =================================================
   GENERATE BUTTON
================================================= */

function updateGenerateButton() {

    const hasStudents =
        students.length > 0;

    const hasHalls =
        selectedHalls().length > 0;

    const capacity =
        usableCapacity();


    $("generateSeating").disabled =
        !hasStudents ||
        !hasHalls ||
        capacity < students.length;
}

        /* =================================================
           HTML ESCAPE
        ================================================= */

        function escapeHtml(
            value
        ) {

            return String(
                value ?? ""
            )
            .replace(
                /[&<>"']/g,
                function (character) {

                    return {

                        "&":
                            "&amp;",

                        "<":
                            "&lt;",

                        ">":
                            "&gt;",

                        '"':
                            "&quot;",

                        "'":
                            "&#039;"

                    }[character];

                }
            );
        }


        /* =================================================
           DISPLAY STUDENTS
        ================================================= */

        function showStudents(
            list
        ) {

            $("loadedStudentsBody")
                .innerHTML =

                list.map(
                    function (
                        student,
                        index
                    ) {

                        const semester =
                            student.semester_name ||
                            (
                                "Semester " +
                                (
                                    student.semester_number ||
                                    "Not Set"
                                )
                            );


                        const firstLetter =
                            (
                                student.student_name ||
                                "?"
                            )[0]
                            .toUpperCase();


                        return `

                            <tr>

                                <td>
                                    ${index + 1}
                                </td>

                                <td>

                                    <span class="pin-badge">

                                        ${escapeHtml(
                                            student.pin_no
                                        )}

                                    </span>

                                </td>

                                <td>

                                    <div class="loaded-student-name">

                                        <span class="student-avatar">

                                            ${escapeHtml(
                                                firstLetter
                                            )}

                                        </span>

                                        <strong>

                                            ${escapeHtml(
                                                student.student_name
                                            )}

                                        </strong>

                                    </div>

                                </td>

                                <td>

                                    <span class="year-badge">

                                        ${escapeHtml(
                                            student.department_code
                                        )}

                                    </span>

                                </td>

                                <td>

                                    ${escapeHtml(
                                        student.year_name ||
                                        "Not Set"
                                    )}

                                </td>

                                <td>

                                    ${escapeHtml(
                                        semester
                                    )}

                                </td>

                            </tr>

                        `;

                    }
                )
                .join("");


            $("loadedStudentTotal")
                .textContent =
                list.length;


            $("loadedStudentsSection")
                .hidden = false;


            $("loadedStatus")
                .textContent =
                list.length +
                " registered student PINs loaded.";


            updateSummary();
        }


        /* =================================================
           PATTERN CHANGE
           Show Customized Allocation immediately when the
           Customized card is clicked.
        ================================================= */

        function toggleCustomSettings() {

            const customBox = $("customSettings");

            if (!customBox) {
                return;
            }

            const selectedRadio =
                document.querySelector(
                    '[name="allocation_pattern"]:checked'
                );

            const isCustomized =
                selectedRadio &&
                selectedRadio.value === "customized";

            customBox.hidden = !isCustomized;

            /*
             * Explicit display prevents an older CSS rule such as
             * .custom-settings { display:none; } from keeping it hidden.
             */
            customBox.style.display =
                isCustomized ? "block" : "none";

            customBox.classList.toggle(
                "is-visible",
                isCustomized
            );

            if (isCustomized) {
                updateSummary();
            }
        }

        document
            .querySelectorAll(
                '[name="allocation_pattern"]'
            )
            .forEach(
                function (radio) {

                    radio.addEventListener(
                        "change",
                        function () {
                            toggleCustomSettings();
                            updateSummary();
                        }
                    );

                }
            );

        /*
         * The radio input may be visually hidden inside the card.
         * Listening to the whole card makes the Customized panel
         * open reliably when the card itself is clicked.
         */
        document
            .querySelectorAll(".pattern-card")
            .forEach(
                function (card) {

                    card.addEventListener(
                        "click",
                        function () {
                            window.setTimeout(
                                toggleCustomSettings,
                                0
                            );
                        }
                    );

                }
            );

        /*
         * Set the correct state immediately on page load.
         */
        toggleCustomSettings();
        

        /* =================================================
           GROUP / HALL CHANGE
        ================================================= */

        [
            ...groups,
            ...halls
        ]
        .forEach(
            function (checkbox) {

                checkbox.addEventListener(
                    "change",
                    function () {

                        /*
                         * Group changed.
                         */
                        if (
                            checkbox.classList.contains(
                                "group-checkbox"
                            )
                        ) {

                            students = [];

                            generatedAllocation = [];

                            lastGenerationRequest = null;

                            $("loadedStudentsSection")
                                .hidden = true;

                            $("allocationPreviewSection")
                                .hidden = true;

                            $("loadedStatus")
                                .textContent =
                                "Selection changed. Load PIN Numbers again.";
                        }


                        /*
                         * Hall changed.
                         */
                        if (
                            checkbox.classList.contains(
                                "hall-checkbox"
                            )
                        ) {

                            $("allocationPreviewSection")
                                .hidden = true;
                        }


                        updateSummary();

                    }
                );

            }
        );


        /* =================================================
           CUSTOM SETTINGS EVENTS
        ================================================= */

        [
            "firstBenchStudents",
            "secondBenchStudents"
        ]
        .forEach(
            function (id) {

                [
                    "input",
                    "change"
                ]
                .forEach(
                    function (eventName) {

                        $(id)
                            .addEventListener(
                                eventName,
                                updateSummary
                            );

                    }
                );

            }
        );


        /* =================================================
           LOAD PIN NUMBERS
        ================================================= */

        $("loadPins")
            .addEventListener(
                "click",
                async function () {

                    const selectedGroups =
                        getSelectedGroupsInOrder();


                    if (
                        !selectedGroups.length
                    ) {

                        alert(
                            "Please select at least one student group."
                        );

                        return;
                    }


                    if (
                        !halls.some(
                            function (
                                checkbox
                            ) {

                                return checkbox.checked;

                            }
                        )
                    ) {

                        alert(
                            "Please select at least one examination hall."
                        );

                        return;
                    }


                    if (
                        usableCapacity() <= 0
                    ) {

                        alert(
                            "The selected halls do not have usable physical seats."
                        );

                        return;
                    }


                    const button =
                        $("loadPins");


                    const oldHtml =
                        button.innerHTML;


                    button.disabled =
                        true;


                    button.innerHTML =
                        '<i class="fa-solid fa-spinner fa-spin"></i> Loading PIN Numbers...';


                    try {

                        const formData =
                            new FormData();


                        formData.append(
                            "action",
                            "load_students"
                        );


                        selectedGroups.forEach(
                            function (
                                group
                            ) {

                                formData.append(
                                    "groups[]",
                                    group
                                );

                            }
                        );


                        const response =
                            await fetch(
                                "seat_allocation.php",
                                {
                                    method:
                                        "POST",

                                    body:
                                        formData,

                                    cache:
                                        "no-store"
                                }
                            );


                        if (!response.ok) {

                            throw new Error(
                                "Server returned HTTP " +
                                response.status
                            );
                        }


                        const data =
                            await response.json();


                        if (!data.success) {

                            throw new Error(
                                data.message ||
                                "Unable to load students."
                            );
                        }


                        if (
                            data.duplicate_pins &&
                            data.duplicate_pins.length
                        ) {

                            alert(
                                "Duplicate PIN numbers were found and excluded:\n\n" +
                                data.duplicate_pins.join(
                                    "\n"
                                )
                            );
                        }


                        students =
                            data.students ||
                            [];


                        generatedAllocation =
                            [];


                        lastGenerationRequest =
                            null;


                        showStudents(
                            students
                        );


                        if (!students.length) {

                            alert(
                                "No registered students were found for the selected groups."
                            );

                            return;
                        }


                        if (
                            students.length >
                            usableCapacity()
                        ) {

                            alert(
                                "Selected students: " +
                                students.length +
                                "\nUsable capacity: " +
                                usableCapacity() +
                                "\n\nPlease select additional halls or use a pattern with more seats."
                            );
                        }

                    }
                    catch (error) {

                        console.error(
                            error
                        );


                        alert(
                            "Unable to load student PIN numbers.\n\n" +
                            error.message
                        );

                    }
                    finally {

                        button.disabled =
                            false;

                        button.innerHTML =
                            oldHtml;

                        updateSummary();
                    }

                }
            );


        /* =================================================
           BUILD GENERATION REQUEST
        ================================================= */

        function buildGenerationRequest() {

            const selectedGroups =
                getSelectedGroupsInOrder();


            const selectedHallIds =
                halls
                    .filter(
                        function (
                            checkbox
                        ) {

                            return checkbox.checked;

                        }
                    )
                    .map(
                        function (
                            checkbox
                        ) {

                            return checkbox.value;

                        }
                    );


            const pattern =
                document.querySelector(
                    '[name="allocation_pattern"]:checked'
                ).value;


            const settings =
                customSettings();


            return {

                groups:
                    selectedGroups,

                halls:
                    selectedHallIds,

                exam_date:
                    $("examDate").value,

                exam_time:
                    $("examTime").value,

                exam_time_to:
                    $("examTimeTo").value,

                pattern:
                    pattern,

                first_bench_students:
                    settings.first_bench_students,

                second_bench_students:
                    settings.second_bench_students,

                mix_groups:
                    0,

                separate_groups:
                    0
            };
        }


        /* =================================================
           GENERATE SEATING
        ================================================= */

        $("generateSeating")
            .addEventListener(
                "click",
                function () {

                    generateSeating();

                }
            );


        async function generateSeating() {

            if (!students.length) {

                alert(
                    "Please load the registered student PINs first."
                );

                return;
            }


            if (!selectedHalls().length) {

                alert(
                    "Please select at least one examination hall."
                );

                return;
            }

            const examDate = $("examDate").value;
            const examTime = $("examTime").value;
            const examTimeTo = $("examTimeTo").value;

            if (!examDate || !examTime || !examTimeTo) {
                alert("Please select the allocation date, start time and end time.");
                return;
            }

            if (examTimeTo <= examTime) {
                alert("End time must be later than start time.");
                return;
            }


            if (
                usableCapacity() <
                students.length
            ) {

                alert(
                    "There are not enough usable seats for all selected students."
                );

                return;
            }


            const request =
                buildGenerationRequest();


            lastGenerationRequest =
                request;


            const button =
                $("generateSeating");


            const oldHtml =
                button.innerHTML;


            button.disabled =
                true;


            button.innerHTML =
                '<i class="fa-solid fa-spinner fa-spin"></i> Generating Seating...';


            $("loadedStatus")
                .textContent =
                "Constraint-based algorithm is generating the seating arrangement...";


            try {

                const formData =
                    new FormData();


                formData.append(
                    "action",
                    "generate"
                );


                request.groups.forEach(
                    function (group) {

                        formData.append(
                            "groups[]",
                            group
                        );

                    }
                );


                request.halls.forEach(
                    function (hall) {

                        formData.append(
                            "halls[]",
                            hall
                        );

                    }
                );


                formData.append(
                    "exam_date",
                    request.exam_date
                );

                formData.append(
                    "exam_time",
                    request.exam_time
                );

                formData.append(
                    "exam_time_to",
                    request.exam_time_to
                );

                formData.append(
                    "pattern",
                    request.pattern
                );


                formData.append(
                    "first_bench_students",
                    request.first_bench_students
                );


                formData.append(
                    "second_bench_students",
                    request.second_bench_students
                );




                const response =
                    await fetch(
                        "seat_allocation.php",
                        {
                            method:
                                "POST",

                            body:
                                formData,

                            cache:
                                "no-store"
                        }
                    );


                const responseText = await response.text();

                let data;

                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    throw new Error(
                        "Server returned HTTP " +
                        response.status +
                        ". Response was not valid JSON: " +
                        responseText.substring(0, 500)
                    );
                }

                if (!response.ok) {
                    throw new Error(
                        data.message ||
                        data.error ||
                        ("Server returned HTTP " + response.status)
                    );
                }


                if (!data.success) {

                    throw new Error(
                        data.message ||
                        "Unable to generate seating."
                    );
                }


                generatedAllocation =
                    data.allocation ||
                    [];


                showAllocationPreview(
                    data
                );


                $("loadedStatus")
                    .textContent =
                    generatedAllocation.length +
                    " students automatically allocated. Review the preview before confirming.";


                window.scrollTo(
                    {
                        top:
                            $("allocationPreviewSection")
                                .offsetTop - 30,

                        behavior:
                            "smooth"
                    }
                );

            }
            catch (error) {

                console.error(
                    error
                );


                alert(
                    "Unable to generate seating.\n\n" +
                    error.message
                );

            }
            finally {

                button.disabled =
                    false;

                button.innerHTML =
                    oldHtml;

                updateGenerateButton();
            }
        }


        /* =================================================
           SHOW ALLOCATION PREVIEW
        ================================================= */

        function showAllocationPreview(
            data
        ) {

            const allocation =
                data.allocation ||
                [];


            generatedAllocation =
                allocation;


            /*
             * Table.
             */
            $("allocationPreviewBody")
                .innerHTML =

                allocation.map(
                    function (
                        item,
                        index
                    ) {

                        const semester =
                            item.semester_name ||
                            (
                                "Semester " +
                                (
                                    item.semester_number ||
                                    ""
                                )
                            );


                        return `

                            <tr>

                                <td>
                                    ${index + 1}
                                </td>

                                <td>

                                    <span class="pin-badge">

                                        ${escapeHtml(
                                            item.pin_no
                                        )}

                                    </span>

                                </td>

                                <td>

                                    <strong>

                                        ${escapeHtml(
                                            item.student_name
                                        )}

                                    </strong>

                                </td>

                                <td>

                                    <span class="year-badge">

                                        ${escapeHtml(
                                            item.department_code
                                        )}

                                    </span>

                                </td>

                                <td>

                                    ${escapeHtml(
                                        item.year_name
                                    )}

                                </td>

                                <td>

                                    ${escapeHtml(
                                        semester
                                    )}

                                </td>

                                <td>

                                    <strong>

                                        ${escapeHtml(
                                            item.hall_name
                                        )}

                                    </strong>

                                    <small>

                                        ${escapeHtml(
                                            item.hall_code
                                        )}

                                    </small>

                                </td>

                                <td>

                                    ${item.row}

                                </td>

                                <td>

                                    ${item.column}

                                </td>

                                <td>

                                    <span class="pin-badge">

                                        ${escapeHtml(
                                            item.seat_number
                                        )}

                                    </span>

                                </td>

                            </tr>

                        `;

                    }
                )
                .join("");


            /*
             * Hall summary.
             */
            $("hallSummaryGrid")
                .innerHTML =

                (
                    data.hall_summary ||
                    []
                )
                .map(
                    function (
                        hall
                    ) {

                        return `

                            <div class="hall-summary-card">

                                <div>

                                    <strong>

                                        ${escapeHtml(
                                            hall.hall_name
                                        )}

                                    </strong>

                                    <span>

                                        Hall
                                        ${escapeHtml(
                                            hall.hall_code
                                        )}

                                    </span>

                                </div>

                                <div>

                                    <strong>

                                        ${hall.allocated}

                                    </strong>

                                    <span>
                                        Students
                                    </span>

                                </div>

                                <div>

                                    <strong>

                                        ${hall.benches}

                                    </strong>

                                    <span>
                                        Benches
                                    </span>

                                </div>

                                <div>

                                    <strong>

                                        ${hall.capacity}

                                    </strong>

                                    <span>
                                        Hall Capacity
                                    </span>

                                </div>

                            </div>

                        `;

                    }
                )
                .join("");


            /*
             * Hall maps.
             */
            buildSeatMaps(
                allocation,
                data.hall_summary || []
            );


            $("previewStatus")
                .textContent =
                allocation.length +
                " students allocated successfully.";


            $("allocationPreviewSection")
                .hidden = false;
        }


        /* =================================================
           HALL SEAT MAPS
        ================================================= */

        function buildSeatMaps(allocation, hallSummary) {

            const hallMap = {};

            /* Build every configured physical position, including empty ones. */
            (hallSummary || []).forEach(function (summary) {
                hallMap[String(summary.hall_id)] = {
                    hall_id: Number(summary.hall_id),
                    hall_name: summary.hall_name || "Hall",
                    hall_code: summary.hall_code || "",
                    rows: Number(summary.rows) || 0,
                    columns: Number(summary.columns) || 0,
                    columnBenches: Array.isArray(summary.column_benches)
                        ? summary.column_benches.map(Number)
                        : [],
                    seats: {}
                };
            });

            allocation.forEach(function (item) {
                const hallId = String(item.hall_id);

                if (!hallMap[hallId]) {
                    hallMap[hallId] = {
                        hall_id: Number(item.hall_id),
                        hall_name: item.hall_name || "Hall",
                        hall_code: item.hall_code || "",
                        rows: Number(item.rows) || Number(item.rows_count) || 0,
                        columns: Number(item.columns) || Number(item.columns_count) || 0,
                        columnBenches: [],
                        seats: {}
                    };
                }

                const row = Number(item.row) || 0;
                const column = Number(item.column) || 0;
                const key = row + "-" + column;

                if (!hallMap[hallId].seats[key]) {
                    hallMap[hallId].seats[key] = [];
                }

                hallMap[hallId].seats[key].push(item);
            });

            const hallsToDisplay = Object.values(hallMap).filter(function (hall) {
                return hall.rows > 0 && hall.columns > 0;
            });

            $("seatMapContainer").innerHTML = hallsToDisplay.map(function (hall) {

                const rows = hall.rows;
                const columns = hall.columns;

                let html = `
                    <div class="seat-map-card">
                        <div class="seat-map-header">
                            <div>
                                <h3>${escapeHtml(hall.hall_name)}</h3>
                                <span>Hall Code: ${escapeHtml(hall.hall_code)}</span>
                            </div>
                            <div class="seat-map-dimensions">
                                ${rows} Rows × ${columns} Columns
                            </div>
                        </div>

                        <div class="seat-map-grid"
                             style="--seat-rows:${rows}; --seat-columns:${columns};">
                `;

                /*
                 * OLD UI IS PRESERVED.
                 *
                 * The DOM is ROW-FIRST:
                 *   R1-C1, R1-C2, R1-C3,
                 *   R2-C1, R2-C2, R2-C3, ...
                 *
                 * The CSS grid uses grid-auto-flow: column, so visually
                 * the students are positioned COLUMN-FIRST:
                 *
                 *   C1: R1, R2, R3...
                 *   C2: R1, R2, R3...
                 *
                 * Every physical cell is rendered, including empty cells.
                 */
                for (let row = 1; row <= rows; row++) {
                    for (let column = 1; column <= columns; column++) {

                        const columnRowLimit = Number(hall.columnBenches[column - 1] || 0);
                        if (row > columnRowLimit) {
                            html += `
                                <div class="seat-map-seat empty unavailable">
                                    <span>R${row}-C${column}</span>
                                    <strong>No Bench</strong>
                                </div>
                            `;
                            continue;
                        }

                        const key = row + "-" + column;
                        const benchStudents = hall.seats[key] || [];

                        benchStudents.sort(function (a, b) {
                            return (Number(a.bench_slot) || 1) -
                                   (Number(b.bench_slot) || 1);
                        });

                        if (benchStudents.length) {
                            const title = benchStudents.map(function (seat) {
                                return seat.student_name + " (" + seat.pin_no + ")";
                            }).join("\n");

                            html += `
                                <div class="seat-map-seat occupied"
                                     title="${escapeHtml(title)}">
                                    <span>R${row}-C${column}</span>
                                    <strong>
                                        ${benchStudents.map(function (seat) {
                                            return escapeHtml(seat.pin_no || seat.student_name || "-");
                                        }).join("<br>")}
                                    </strong>
                                </div>
                            `;
                        } else {
                            html += `
                                <div class="seat-map-seat empty">
                                    <span>R${row}-C${column}</span>
                                    <strong>Empty</strong>
                                </div>
                            `;
                        }
                    }
                }

                html += `
                        </div>
                    </div>
                `;

                return html;
            }).join("");
        }

        /* =================================================
           REGENERATE
        ================================================= */

        $("regenerateAllocation")
            .addEventListener(
                "click",
                function () {

                    if (!lastGenerationRequest) {

                        alert(
                            "Please generate the seating first."
                        );

                        return;
                    }


                    generateSeating();

                }
            );


        /* =================================================
           SHUFFLE HALLS
        ================================================= */

        $("shuffleHalls")
            .addEventListener(
                "click",
                async function () {

                    if (!generatedAllocation.length) {
                        alert("Please generate the seating first.");
                        return;
                    }

                    const button = $("shuffleHalls");
                    const oldHtml = button.innerHTML;

                    button.disabled = true;
                    $("confirmAllocation").disabled = true;
                    button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Shuffling...';

                    try {
                        const formData = new FormData();
                        formData.append("action", "shuffle_halls");

                        const response = await fetch("seat_allocation.php", {
                            method: "POST",
                            body: formData,
                            cache: "no-store"
                        });

                        if (!response.ok) {
                            throw new Error("Server returned HTTP " + response.status);
                        }

                        const data = await response.json();

                        if (!data.success) {
                            throw new Error(data.message || "Unable to shuffle halls.");
                        }

                        generatedAllocation = data.allocation || [];
                        showAllocationPreview(data);

                        $("previewStatus").textContent =
                            generatedAllocation.length +
                            " students allocated successfully. Hall order shuffled.";

                    } catch (error) {
                        console.error(error);
                        alert("Unable to shuffle halls.\n\n" + error.message);
                    } finally {
                        button.disabled = false;
                        $("confirmAllocation").disabled = !generatedAllocation.length;
                        button.innerHTML = oldHtml;
                    }
                }
            );


        /* =================================================
           CONFIRM
        ================================================= */

        $("confirmAllocation")
            .addEventListener(
                "click",
                async function () {

                    if (
                        !generatedAllocation.length
                    ) {

                        alert(
                            "There is no generated seating arrangement to confirm."
                        );

                        return;
                    }


                    const confirmed =
                        confirm(
                            "Confirm this seat allocation?\n\n" +
                            generatedAllocation.length +
                            " students will be assigned to physical hall seats."
                        );


                    if (!confirmed) {
                        return;
                    }


                    const button =
                        $("confirmAllocation");


                    const oldHtml =
                        button.innerHTML;


                    button.disabled =
                        true;


                    button.innerHTML =
                        '<i class="fa-solid fa-spinner fa-spin"></i> Confirming...';


                    try {

                        const formData =
                            new FormData();


                        formData.append(
                            "action",
                            "confirm"
                        );


                        const response =
                            await fetch(
                                "seat_allocation.php",
                                {
                                    method:
                                        "POST",

                                    body:
                                        formData,

                                    cache:
                                        "no-store"
                                }
                            );


                        if (!response.ok) {

                            throw new Error(
                                "Server returned HTTP " +
                                response.status
                            );
                        }


                        const data =
                            await response.json();


                        if (!data.success) {

                            throw new Error(
                                data.message ||
                                "Unable to confirm allocation."
                            );
                        }


                        alert(
                            "Seat allocation confirmed successfully.\n\n" +
                            "The seating arrangement has been saved and the selected halls are now updated."
                        );

                        /* Automatically refresh so the confirmed allocation
                           appears immediately without a manual refresh. */
                        window.location.reload();


                        generatedAllocation =
                            [];


                        lastGenerationRequest =
                            null;


                        $("allocationPreviewSection")
                            .hidden = true;


                        $("loadedStatus")
                            .textContent =
                            "Seating allocation confirmed successfully.\nHall availability has been updated.";


                    }
                    catch (error) {

                        console.error(
                            error
                        );


                        alert(
                            "Unable to confirm seat allocation.\n\n" +
                            error.message
                        );

                    }
                    finally {

                        button.disabled =
                            false;

                        button.innerHTML =
                            oldHtml;
                    }

                }
            );


        /* =================================================
           VIEW CONFIRMED SEATING ALLOCATION
        ================================================= */

        function openSeatingViewModal() {
            const modal = $("seatingViewModal");

            if (!modal) {
                return;
            }

            modal.hidden = false;
            document.body.style.overflow = "hidden";
        }

        function closeSeatingViewModal() {
            const modal = $("seatingViewModal");

            if (!modal) {
                return;
            }

            modal.hidden = true;
            document.body.style.overflow = "";
        }

        function renderConfirmedSeatingView(data) {
            const body = $("seatingViewBody");
            const subtitle = $("seatingViewSubtitle");

            if (!body) {
                return;
            }

            const allocation = data.allocation || [];
            const halls = data.hall_summary || [];

            if (subtitle) {
                subtitle.textContent =
                    (data.pattern_label || "Seating Arrangement") +
                    " • " +
                    Number(data.total_students || allocation.length) +
                    " students • " +
                    Number(data.total_halls || halls.length) +
                    " halls";
            }

            if (!allocation.length) {
                body.innerHTML =
                    '<div class="seating-view-empty">' +
                    '<i class="fa-solid fa-circle-info"></i><br>' +
                    'No seating records were found for this allocation.' +
                    '</div>';
                return;
            }

            let html =
                '<div class="seating-view-summary">' +
                    '<div class="seating-view-stat">' +
                        '<span>Students</span>' +
                        '<strong>' +
                            Number(data.total_students || allocation.length) +
                        '</strong>' +
                    '</div>' +
                    '<div class="seating-view-stat">' +
                        '<span>Halls</span>' +
                        '<strong>' +
                            Number(data.total_halls || halls.length) +
                        '</strong>' +
                    '</div>' +
                    '<div class="seating-view-stat">' +
                        '<span>Pattern</span>' +
                        '<strong>' +
                            escapeHtml(data.pattern_label || "Seating") +
                        '</strong>' +
                    '</div>' +
                    '<div class="seating-view-stat">' +
                        '<span>Confirmed</span>' +
                        '<strong>' +
                            escapeHtml(
                                data.confirmed_at ||
                                data.created_at ||
                                "-"
                            ) +
                        '</strong>' +
                    '</div>' +
                '</div>';

            halls.forEach(function (hall) {
                const rows = Number(hall.rows || 0);
                const columns = Number(hall.columns || 0);
                const columnBenches = Array.isArray(hall.column_benches) && hall.column_benches.length
                    ? hall.column_benches.map(Number)
                    : Array(columns).fill(rows);

                const hallStudents = allocation.filter(function (item) {
                    return Number(item.hall_id) === Number(hall.hall_id);
                });

                const seatMap = {};
                hallStudents.forEach(function (item) {
                    const key =
                        Number(item.row || 0) + "-" +
                        Number(item.column || 0);

                    if (!seatMap[key]) {
                        seatMap[key] = [];
                    }

                    seatMap[key].push(item);
                });

                Object.keys(seatMap).forEach(function (key) {
                    seatMap[key].sort(function (a, b) {
                        return (
                            Number(a.bench_slot || 1) -
                            Number(b.bench_slot || 1)
                        );
                    });
                });

                html +=
                    '<div class="seating-view-hall">' +
                        '<div class="seating-view-hall-header">' +
                            '<div>' +
                                '<strong>' +
                                    escapeHtml(hall.hall_name) +
                                '</strong>' +
                                '<span>Hall Code: ' +
                                    escapeHtml(hall.hall_code || "") +
                                '</span>' +
                            '</div>' +
                            '<div class="seating-view-hall-stats">' +
                                '<span class="seating-view-pill">' +
                                    rows + ' × ' + columns +
                                '</span>' +
                                '<span class="seating-view-pill">' +
                                    Number(hall.allocated || 0) +
                                    ' Allocated' +
                                '</span>' +
                                '<span class="seating-view-pill">' +
                                    Number(hall.benches || 0) +
                                    ' Benches' +
                                '</span>' +
                            '</div>' +
                        '</div>' +

                        '<div class="confirmed-seat-layout-wrap">' +
                            '<table class="confirmed-seat-layout">' +
                                '<thead><tr>' +
                                    '<th class="confirmed-row-heading">Row</th>';

                for (let column = 1; column <= columns; column++) {
                    html +=
                        '<th>C' + column + '</th>';
                }

                html += '</tr></thead><tbody>';

                for (let row = 1; row <= rows; row++) {
                    html +=
                        '<tr>' +
                            '<th class="confirmed-row-heading">R' + row + '</th>';

                    for (let column = 1; column <= columns; column++) {
                        const columnRowLimit = Number(columnBenches[column - 1] || 0);
                        if (row > columnRowLimit) {
                            html += '<td class="confirmed-seat-cell unavailable"><div class="confirmed-seat-empty">No Bench</div></td>';
                            continue;
                        }

                        const key = row + "-" + column;
                        const benchStudents = seatMap[key] || [];

                        if (benchStudents.length) {
                            html +=
                                '<td class="confirmed-seat-cell occupied">' +
                                    '<div class="confirmed-seat-code">R' +
                                        row + '-C' + column +
                                    '</div>' +
                                    '<div class="confirmed-seat-students">' +
                                        benchStudents.map(function (item) {
                                            return (
                                                '<div class="confirmed-seat-student">' +
                                                    '<strong>' +
                                                        escapeHtml(item.student_name || "-") +
                                                    '</strong>' +
                                                    '<span>' +
                                                        escapeHtml(item.pin_no || "-") +
                                                        ' • ' +
                                                        escapeHtml(
                                                            item.seat_number ||
                                                            ("R" + row + "-C" + column)
                                                        ) +
                                                    '</span>' +
                                                '</div>'
                                            );
                                        }).join("") +
                                    '</div>' +

                                '</td>';
                        } else {
                            html +=
                                '<td class="confirmed-seat-cell empty">' +
                                    '<div class="confirmed-seat-code">R' +
                                        row + '-C' + column +
                                    '</div>' +
                                    '<div class="confirmed-seat-empty">Empty</div>' +
                                '</td>';
                        }
                    }

                    html += '</tr>';
                }

                html +=
                                '</tbody>' +
                            '</table>' +
                        '</div>' +
                    '</div>';
            });

            body.innerHTML = html;
        }

        function bindViewAllocationButtons() {
            document
                .querySelectorAll(".view-allocation-button")
                .forEach(function (button) {
                    if (button.dataset.bound === "1") {
                        return;
                    }

                    button.dataset.bound = "1";

                    button.addEventListener(
                        "click",
                        async function () {
                            const allocationId =
                                Number(
                                    this.dataset.allocationId || 0
                                );

                            if (!allocationId) {
                                alert("Invalid allocation selected.");
                                return;
                            }

                            const oldHtml = this.innerHTML;

                            this.disabled = true;
                            this.innerHTML =
                                '<i class="fa-solid fa-spinner fa-spin"></i> Loading...';

                            const body = $("seatingViewBody");

                            if (body) {
                                body.innerHTML =
                                    '<div class="seating-view-loading">' +
                                    '<i class="fa-solid fa-spinner fa-spin"></i>' +
                                    'Loading seating arrangement...' +
                                    '</div>';
                            }

                            openSeatingViewModal();

                            try {
                                const formData = new FormData();

                                formData.append(
                                    "action",
                                    "view_allocation"
                                );

                                formData.append(
                                    "allocation_id",
                                    allocationId
                                );

                                const response = await fetch(
                                    "seat_allocation.php",
                                    {
                                        method: "POST",
                                        body: formData,
                                        cache: "no-store"
                                    }
                                );

                                if (!response.ok) {
                                    throw new Error(
                                        "Server returned HTTP " +
                                        response.status
                                    );
                                }

                                const data = await response.json();

                                if (!data.success) {
                                    throw new Error(
                                        data.message ||
                                        "Unable to load seating allocation."
                                    );
                                }

                                renderConfirmedSeatingView(data);
                            }
                            catch (error) {
                                console.error(error);

                                if (body) {
                                    body.innerHTML =
                                        '<div class="seating-view-empty">' +
                                        '<i class="fa-solid fa-triangle-exclamation"></i><br>' +
                                        escapeHtml(
                                            error.message ||
                                            "Unable to load seating arrangement."
                                        ) +
                                        '</div>';
                                }
                            }
                            finally {
                                this.disabled = false;
                                this.innerHTML = oldHtml;
                            }
                        }
                    );
                });
        }

        if ($("closeSeatingView")) {
            $("closeSeatingView").addEventListener(
                "click",
                closeSeatingViewModal
            );
        }

        if ($("seatingViewModal")) {
            $("seatingViewModal").addEventListener(
                "click",
                function (event) {
                    if (event.target === this) {
                        closeSeatingViewModal();
                    }
                }
            );
        }

        document.addEventListener(
            "keydown",
            function (event) {
                if (event.key === "Escape") {
                    closeSeatingViewModal();
                }
            }
        );

        bindViewAllocationButtons();

        /* =================================================
           CANCEL / REMOVE CONFIRMED ALLOCATION
        ================================================= */
        function bindCancelAllocationButtons() {
            document
                .querySelectorAll(".cancel-allocation-button")
                .forEach(function (button) {
                    if (button.dataset.bound === "1") {
                        return;
                    }

                    button.dataset.bound = "1";

                    button.addEventListener(
                        "click",
                        async function () {
                            const allocationId =
                                Number(this.dataset.allocationId || 0);

                            const totalStudents =
                                Number(this.dataset.students || 0);

                            const totalHalls =
                                Number(this.dataset.halls || 0);

                            if (!allocationId) {
                                alert("Invalid allocation selected.");
                                return;
                            }

                            const confirmed = confirm(
                                "Cancel this confirmed seat allocation?\n\n" +
                                "Students: " + totalStudents + "\n" +
                                "Halls: " + totalHalls + "\n\n" +
                                "The students will NOT be deleted. The allocation will be released from the halls."
                            );

                            if (!confirmed) {
                                return;
                            }

                            const oldHtml = this.innerHTML;
                            this.disabled = true;
                            this.innerHTML =
                                '<i class="fa-solid fa-spinner fa-spin"></i> Cancelling...';

                            try {
                                const formData = new FormData();

                                formData.append(
                                    "action",
                                    "cancel_allocation"
                                );

                                formData.append(
                                    "allocation_id",
                                    allocationId
                                );

                                const response = await fetch(
                                    "seat_allocation.php",
                                    {
                                        method: "POST",
                                        body: formData,
                                        cache: "no-store"
                                    }
                                );

                                if (!response.ok) {
                                    throw new Error(
                                        "Server returned HTTP " +
                                        response.status
                                    );
                                }

                                const data = await response.json();

                                if (!data.success) {
                                    throw new Error(
                                        data.message ||
                                        "Unable to cancel allocation."
                                    );
                                }

                                const row = document.getElementById(
                                    "allocation-row-" + allocationId
                                );

                                if (row) {
                                    row.remove();
                                }

                                const body = $("confirmedAllocationsBody");

                                if (body && !body.querySelector("tr")) {
                                    const wrapper =
                                        document.querySelector(
                                            ".confirmed-table-wrapper"
                                        );

                                    if (wrapper) {
                                        wrapper.innerHTML =
                                            '<div class="confirmed-empty-state" id="confirmedEmptyState">' +
                                            '<i class="fa-solid fa-circle-info"></i>' +
                                            '<div>' +
                                            '<strong>No confirmed allocations</strong>' +
                                            '<span>Confirmed seating allocations will appear here after you save one.</span>' +
                                            '</div>' +
                                            '</div>';
                                    }
                                }

                                alert(
                                    "Seat allocation cancelled successfully.\n\n" +
                                    "The selected halls are now released. Hall Management will show the updated seat availability."
                                );
                            }
                            catch (error) {
                                console.error(error);
                                this.disabled = false;
                                this.innerHTML = oldHtml;

                                alert(
                                    "Unable to cancel seat allocation.\n\n" +
                                    error.message
                                );
                            }
                        }
                    );
                });
        }

        bindCancelAllocationButtons();

        /* =================================================
           RESET
        ================================================= */

        $("resetAllocation")
            .addEventListener(
                "click",
                async function () {

                    try {

                        const formData =
                            new FormData();

                        formData.append(
                            "action",
                            "clear_preview"
                        );

                        await fetch(
                            "seat_allocation.php",
                            {
                                method:
                                    "POST",

                                body:
                                    formData,

                                cache:
                                    "no-store"
                            }
                        );

                    }
                    catch (error) {

                        console.error(
                            error
                        );
                    }


                    groups.forEach(
                        function (
                            checkbox
                        ) {

                            checkbox.checked =
                                false;

                        }
                    );

                    selectedGroupOrder = [];


                    halls.forEach(
                        function (
                            checkbox
                        ) {

                            checkbox.checked =
                                false;

                        }
                    );


                    document.querySelector(
                        '[name="allocation_pattern"][value="single"]'
                    ).checked = true;


                    $("firstBenchStudents")
                        .value = 1;


                    $("secondBenchStudents")
                        .value = 2;


                    students = [];

                    generatedAllocation = [];

                    lastGenerationRequest = null;


                    $("loadedStudentsBody")
                        .innerHTML = "";


                    $("loadedStudentsSection")
                        .hidden = true;


                    $("allocationPreviewSection")
                        .hidden = true;


                    $("customSettings")
                        .hidden = true;


                    $("loadedStatus")
                        .textContent =
                        "Ready for allocation.";


                    $("allocationPreviewBody")
                        .innerHTML = "";


                    $("hallSummaryGrid")
                        .innerHTML = "";


                    $("seatMapContainer")
                        .innerHTML = "";


                    updateSummary();

                }
            );


        /* =================================================
           INITIAL SUMMARY
        ================================================= */

        updateSummary();

    }
);

</script>

</body>

</html>
