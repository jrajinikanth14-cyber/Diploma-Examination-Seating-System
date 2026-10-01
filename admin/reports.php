<?php
/* =========================================================
   EXAMINATION SEATING MANAGEMENT SYSTEM
   REPORTS MODULE

   Flow:
   Reports
      -> Hall Allocation Details
      -> Select confirmed allocation by date/time
      -> Edit report details
      -> View / Print Hall Allocation Details

   Student Seat Allocation follows the same allocation-selection flow.
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

function clean($value): string
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function fetchAllRows(mysqli $conn, string $sql, string $types = "", array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];

    if ($types !== "" && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function fetchOne(mysqli $conn, string $sql, string $types = "", array $params = []): ?array
{
    $rows = fetchAllRows($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

function formatDate(?string $date): string
{
    if (!$date || $date === "0000-00-00") return "—";
    return date("d M Y", strtotime($date));
}

function formatTime(?string $time): string
{
    if (!$time) return "—";
    return date("h:i A", strtotime($time));
}

/* =========================================================
   REQUIRED REPORT TABLES
   ========================================================= */

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS allocation_hall_details (
    id INT NOT NULL AUTO_INCREMENT,
    allocation_id INT NOT NULL,
    hall_id INT NOT NULL,
    invigilator_count INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_allocation_hall (allocation_id, hall_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS allocation_report_details (
    id INT NOT NULL AUTO_INCREMENT,
    allocation_id INT NOT NULL,
    exam_type VARCHAR(100) NOT NULL DEFAULT 'Semester Examination',
    exam_title VARCHAR(255) NULL,
    notice_heading VARCHAR(255) NOT NULL DEFAULT 'NOTICE/DISPLAY',
    additional_matter TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_allocation_report (allocation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* Older databases may not have exam_id. */
$examIdColumn = mysqli_query($conn, "SHOW COLUMNS FROM allocation_batches LIKE 'exam_id'");
if ($examIdColumn && mysqli_num_rows($examIdColumn) === 0) {
    mysqli_query($conn, "ALTER TABLE allocation_batches ADD COLUMN exam_id INT NULL AFTER id");
}

/* =========================================================
   ROUTING
   ========================================================= */

$reportType = trim((string)($_GET["report"] ?? ""));
if (!in_array($reportType, ["", "hall_allocation", "student_seating"], true)) {
    $reportType = "";
}

$allocationId = (int)($_GET["allocation"] ?? 0);
$action = trim((string)($_GET["action"] ?? ""));

/* =========================================================
   SAVE HALL REPORT DETAILS
   ========================================================= */

/* =========================================================
   SAVE STUDENT LEFT / RIGHT SEAT POSITIONS
   This changes only bench_slot for the confirmed allocation.
   Allocation order and student assignments remain unchanged.
   ========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save_seat_positions") {
    $postAllocationId = (int)($_POST["allocation_id"] ?? 0);
    $seatPositions = $_POST["seat_position"] ?? [];
    $swapAllPairs = !empty($_POST["swap_all_pairs"]);

    if ($postAllocationId <= 0 || !is_array($seatPositions)) {
        header("Location: reports.php?report=student_seating");
        exit();
    }

    $allocation = fetchOne(
        $conn,
        "SELECT id FROM allocation_batches WHERE id=? AND status='confirmed' LIMIT 1",
        "i",
        [$postAllocationId]
    );

    if (!$allocation) {
        header("Location: reports.php?report=student_seating");
        exit();
    }

    $normalized = [];
    foreach ($seatPositions as $seatId => $position) {
        $seatId = (int)$seatId;
        $position = (int)$position;
        if ($seatId > 0 && in_array($position, [1, 2], true)) {
            $normalized[$seatId] = $position;
        }
    }

    $conn->begin_transaction();

    try {
        /* Load every seat in this allocation. The current bench_slot is the
           position created by the admin/allocation: 1=LEFT, 2=RIGHT. */
        $allSeats = fetchAllRows(
            $conn,
            "SELECT id, hall_id, row_number, column_number, bench_slot
             FROM allocation_seats
             WHERE allocation_id=?
             ORDER BY hall_id, row_number, column_number, id",
            "i",
            [$postAllocationId]
        );

        if (!$allSeats) {
            throw new Exception("No seating data found for this allocation.");
        }

        $seatById = [];
        $benchGroups = [];
        foreach ($allSeats as $row) {
            $id = (int)$row["id"];
            $seatById[$id] = [
                "id" => $id,
                "hall_id" => (int)$row["hall_id"],
                "row_number" => (int)$row["row_number"],
                "column_number" => (int)$row["column_number"],
                "bench_slot" => (int)$row["bench_slot"]
            ];
            $key = (int)$row["hall_id"] . "|" . (int)$row["row_number"] . "|" . (int)$row["column_number"];
            $benchGroups[$key][] = $id;
        }

        /* Apply the admin's/manual LEFT/RIGHT choices first. */
        foreach ($normalized as $seatId => $position) {
            if (!isset($seatById[$seatId])) {
                throw new Exception("Invalid student seat selected.");
            }
            $seatById[$seatId]["bench_slot"] = $position;
        }

        /* If requested, swap ONLY two-student benches. One-student benches
           keep the admin's LEFT/RIGHT position exactly as it is. */
        if ($swapAllPairs) {
            foreach ($benchGroups as $ids) {
                if (count($ids) !== 2) {
                    continue;
                }
                $a = $ids[0];
                $b = $ids[1];
                $oldA = $seatById[$a]["bench_slot"];
                $oldB = $seatById[$b]["bench_slot"];

                if (!in_array($oldA, [1, 2], true) || !in_array($oldB, [1, 2], true) || $oldA === $oldB) {
                    throw new Exception("Every two-student bench must have one LEFT and one RIGHT before swapping.");
                }
                $seatById[$a]["bench_slot"] = $oldB;
                $seatById[$b]["bench_slot"] = $oldA;
            }
        }

        /* Final validation: one student may use either side; two students
           must use exactly LEFT + RIGHT. */
        foreach ($benchGroups as $ids) {
            if (count($ids) > 2) {
                throw new Exception("A bench cannot contain more than two students.");
            }
            $positions = [];
            foreach ($ids as $id) {
                $positions[] = $seatById[$id]["bench_slot"];
            }
            if (count($positions) === 2 && count(array_unique($positions)) !== 2) {
                throw new Exception("A two-student bench must have one student on LEFT and one on RIGHT.");
            }
        }

        /* Avoid the UNIQUE allocation_seat seat-position key while swapping. */
        if (!mysqli_query($conn, "UPDATE allocation_seats SET bench_slot = -id WHERE allocation_id=" . (int)$postAllocationId)) {
            throw new Exception("Unable to prepare seat positions.");
        }

        $update = $conn->prepare(
            "UPDATE allocation_seats SET bench_slot=? WHERE id=? AND allocation_id=?"
        );
        if (!$update) {
            throw new Exception("Unable to prepare seat position update.");
        }

        foreach ($seatById as $seat) {
            $position = (int)$seat["bench_slot"];
            $id = (int)$seat["id"];
            $update->bind_param("iii", $position, $id, $postAllocationId);
            if (!$update->execute()) {
                $update->close();
                throw new Exception("Unable to save seat positions.");
            }
        }
        $update->close();

        $conn->commit();

        header(
            "Location: reports.php?report=student_seating&allocation=" .
            $postAllocationId . "&action=view&saved=1"
        );
        exit();
    } catch (Throwable $e) {
        $conn->rollback();
        header(
            "Location: reports.php?report=student_seating&allocation=" .
            $postAllocationId . "&action=edit&error=" . rawurlencode($e->getMessage())
        );
        exit();
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save_report_details") {
    $postAllocationId = (int)($_POST["allocation_id"] ?? 0);

    if ($postAllocationId <= 0) {
        header("Location: reports.php?report=hall_allocation");
        exit();
    }

    $allocation = fetchOne(
        $conn,
        "SELECT id FROM allocation_batches WHERE id=? AND status='confirmed' LIMIT 1",
        "i",
        [$postAllocationId]
    );

    if (!$allocation) {
        header("Location: reports.php?report=hall_allocation");
        exit();
    }

    $examType = trim((string)($_POST["exam_type"] ?? "Semester Examination"));
    $examTitle = trim((string)($_POST["exam_title"] ?? ""));
    $noticeHeading = trim((string)($_POST["notice_heading"] ?? "NOTICE/DISPLAY"));
    $additionalMatter = trim((string)($_POST["additional_matter"] ?? ""));
    $hallInvigilators = $_POST["hall_invigilators"] ?? [];
    if (!is_array($hallInvigilators)) $hallInvigilators = [];

    if ($examType === "") $examType = "Semester Examination";
    if ($noticeHeading === "") $noticeHeading = "NOTICE/DISPLAY";

    $stmt = $conn->prepare(
        "INSERT INTO allocation_report_details
            (allocation_id, exam_type, exam_title, notice_heading, additional_matter)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            exam_type=VALUES(exam_type),
            exam_title=VALUES(exam_title),
            notice_heading=VALUES(notice_heading),
            additional_matter=VALUES(additional_matter)"
    );

    if ($stmt) {
        $stmt->bind_param("issss", $postAllocationId, $examType, $examTitle, $noticeHeading, $additionalMatter);
        $stmt->execute();
        $stmt->close();
    }

    $hallIds = fetchAllRows($conn,
        "SELECT DISTINCT hall_id FROM allocation_seats WHERE allocation_id=? ORDER BY hall_id",
        "i", [$postAllocationId]);
    $hallStmt = $conn->prepare(
        "INSERT INTO allocation_hall_details (allocation_id, hall_id, invigilator_count)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE invigilator_count=VALUES(invigilator_count)"
    );
    if ($hallStmt) {
        foreach ($hallIds as $hall) {
            $hallId = (int)$hall["hall_id"];
            $count = max(0, (int)($hallInvigilators[$hallId] ?? 0));
            $hallStmt->bind_param("iii", $postAllocationId, $hallId, $count);
            $hallStmt->execute();
        }
        $hallStmt->close();
    }

    header("Location: reports.php?report=hall_allocation&allocation=" . $postAllocationId . "&action=view");
    exit();
}

/* =========================================================
   LOAD CONFIRMED ALLOCATIONS
   These are the actual seating allocations created by the
   Seat Allocation module, ordered by exam date/time.
   ========================================================= */

$allocations = fetchAllRows(
    $conn,
    "SELECT
        ab.id,
        ab.allocation_code,
        ab.exam_date,
        ab.exam_time,
        ab.exam_time_to,
        ab.total_students,
        ab.total_halls,
        ab.total_capacity,
        ab.pattern_type,
        ab.created_at,
        ab.confirmed_at,
        (
            SELECT COUNT(*)
            FROM allocation_seats ase
            WHERE ase.allocation_id=ab.id
        ) AS actual_seats
     FROM allocation_batches ab
     WHERE ab.status='confirmed'
     ORDER BY ab.exam_date DESC, ab.exam_time DESC, ab.id DESC"
);

/* =========================================================
   SELECTED ALLOCATION
   ========================================================= */

$selectedAllocation = null;
$reportDetails = null;

if ($allocationId > 0) {
    $selectedAllocation = fetchOne(
        $conn,
        "SELECT
            ab.id,
            ab.allocation_code,
            ab.exam_date,
            ab.exam_time,
            ab.exam_time_to,
            ab.total_students,
            ab.total_halls,
            ab.total_capacity,
            ab.pattern_type,
            ab.pattern_settings,
            ab.created_at,
            ab.confirmed_at
         FROM allocation_batches ab
         WHERE ab.id=? AND ab.status='confirmed'
         LIMIT 1",
        "i",
        [$allocationId]
    );

    if ($selectedAllocation) {
        $reportDetails = fetchOne(
            $conn,
            "SELECT exam_type, exam_title, notice_heading, additional_matter
             FROM allocation_report_details
             WHERE allocation_id=? LIMIT 1",
            "i",
            [$allocationId]
        );
    }
}

/* =========================================================
   DEFAULT REPORT DETAILS
   ========================================================= */

$reportDetails = $reportDetails ?? [
    "exam_type" => "Semester Examination",
    "exam_title" => "",
    "notice_heading" => "NOTICE/DISPLAY",
    "additional_matter" => ""
];

/* =========================================================
   HALL ALLOCATION REPORT DATA
   ========================================================= */

$hallRows = [];
$hallStats = ["students" => 0, "rooms" => 0, "groups" => 0];
$editHalls = [];

if ($reportType === "hall_allocation" && $selectedAllocation) {
    $raw = fetchAllRows(
        $conn,
        "SELECT
            ase.hall_id,
            h.hall_name,
            h.hall_code,
            h.capacity,
            s.department_id,
            d.department_name,
            d.department_code,
            s.academic_year,
            ay.year_name,
            s.semester_id,
            sem.semester_name,
            sem.semester_number,
            s.pin_no
         FROM allocation_seats ase
         INNER JOIN allocation_batches ab
             ON ab.id=ase.allocation_id AND ab.status='confirmed'
         INNER JOIN students s ON s.id=ase.student_id
         LEFT JOIN departments d ON d.id=s.department_id
         LEFT JOIN academic_years ay ON ay.id=s.academic_year
         LEFT JOIN semesters sem ON sem.id=s.semester_id
         INNER JOIN halls h ON h.id=ase.hall_id
         WHERE ase.allocation_id=?
         ORDER BY h.id, d.department_name, ay.id, sem.semester_number, s.pin_no",
        "i",
        [$allocationId]
    );

    $grouped = [];

    foreach ($raw as $r) {
        $hallId = (int)$r["hall_id"];
        $groupKey = $hallId . "|" . (int)$r["department_id"] . "|" . (int)$r["academic_year"] . "|" . (int)$r["semester_id"];

        if (!isset($grouped[$hallId])) {
            $grouped[$hallId] = [];
        }

        if (!isset($grouped[$hallId][$groupKey])) {
            $yearSem = trim(
                ($r["year_name"] ? $r["year_name"] : "") .
                ($r["year_name"] && $r["semester_name"] ? " / " : "") .
                ($r["semester_name"] ?? "")
            );

            $grouped[$hallId][$groupKey] = [
                "department" => !empty($r["department_code"]) ? $r["department_code"] : ($r["department_name"] ?? "—"),
                "year_sem" => $yearSem !== "" ? $yearSem : "—",
                "pins" => [],
                "hall_name" => $r["hall_name"] ?? "—",
                "hall_code" => $r["hall_code"] ?? ""
            ];
        }

        $grouped[$hallId][$groupKey]["pins"][] = (string)$r["pin_no"];
    }

    $serial = 0;

    foreach ($grouped as $hallId => $groups) {
        $serial++;
        $roomStrength = 0;
        foreach ($groups as $group) {
            $roomStrength += count($group["pins"]);
        }

        $inv = fetchOne(
            $conn,
            "SELECT invigilator_count
             FROM allocation_hall_details
             WHERE allocation_id=? AND hall_id=? LIMIT 1",
            "ii",
            [$allocationId, (int)$hallId]
        );

        foreach ($groups as $group) {
            $pins = $group["pins"];
            $hallRows[] = [
                "hall_id" => (int)$hallId,
                "s_no" => $serial,
                "department" => $group["department"],
                "year_sem" => $group["year_sem"],
                "from" => $pins[0] ?? "—",
                "to" => $pins[count($pins) - 1] ?? "—",
                "total" => count($pins),
                "room_strength" => $roomStrength,
                "invigilators" => (int)($inv["invigilator_count"] ?? 0),
                "room" => $group["hall_name"] ?? "—",
                "hall_code" => $group["hall_code"] ?? ""
            ];
        }
    }

    $hallStats["students"] = count($raw);
    $hallStats["rooms"] = count($grouped);
    $hallStats["groups"] = count($hallRows);

    foreach ($grouped as $hallId => $groups) {
        $firstGroup = reset($groups);
        $inv = fetchOne($conn,
            "SELECT invigilator_count FROM allocation_hall_details WHERE allocation_id=? AND hall_id=? LIMIT 1",
            "ii", [$allocationId, (int)$hallId]);
        $editHalls[] = [
            "hall_id" => (int)$hallId,
            "hall_name" => $firstGroup["hall_name"] ?? "—",
            "hall_code" => $firstGroup["hall_code"] ?? "",
            "invigilators" => (int)($inv["invigilator_count"] ?? 0)
        ];
    }
}

/* =========================================================
   STUDENT SEAT MAP DATA
   ========================================================= */

$seatHalls = [];
$seatCount = 0;

if ($reportType === "student_seating" && $selectedAllocation) {
    $rawSeats = fetchAllRows(
        $conn,
        "SELECT
            ase.id AS allocation_seat_id,
            ase.hall_id,
            h.hall_name,
            h.hall_code,
            h.rows_count,
            h.columns_count,
            ase.row_number,
            ase.column_number,
            ase.bench_slot,
            ase.seat_number,
            s.id AS student_id,
            s.pin_no
         FROM allocation_seats ase
         INNER JOIN allocation_batches ab
             ON ab.id=ase.allocation_id AND ab.status='confirmed'
         INNER JOIN students s ON s.id=ase.student_id
         INNER JOIN halls h ON h.id=ase.hall_id
         WHERE ase.allocation_id=?
         ORDER BY h.id, ase.column_number, ase.row_number, ase.bench_slot",
        "i",
        [$allocationId]
    );

    foreach ($rawSeats as $r) {
        $hallId = (int)$r["hall_id"];
        if (!isset($seatHalls[$hallId])) {
            $seatHalls[$hallId] = [
                "hall_id" => $hallId,
                "hall_name" => $r["hall_name"],
                "hall_code" => $r["hall_code"],
                "rows" => (int)$r["rows_count"],
                "columns" => (int)$r["columns_count"],
                "seats" => []
            ];
        }

        $row = (int)$r["row_number"];
        $column = (int)$r["column_number"];
        $key = $row . "-" . $column;

        if (!isset($seatHalls[$hallId]["seats"][$key])) {
            $seatHalls[$hallId]["seats"][$key] = [];
        }

        $seatHalls[$hallId]["seats"][$key][] = [
            "id" => (int)$r["allocation_seat_id"],
            "student_id" => (int)$r["student_id"],
            "pin_no" => $r["pin_no"],
            "bench_slot" => (int)$r["bench_slot"],
            "seat_number" => $r["seat_number"]
        ];

        $seatCount++;
    }

    $seatHalls = array_values($seatHalls);
}


/* =========================================================
   PAGE TITLE / SUBTITLE
   ========================================================= */

if ($reportType === "hall_allocation") {
    $pageTitle = "Hall Allocation Details";
    $pageSubtitle = $selectedAllocation
        ? "Review or edit the report information for this confirmed seat allocation."
        : "Select a confirmed seat allocation by examination date and time.";
} elseif ($reportType === "student_seating") {
    $pageTitle = "Student Seat Allocation";
    $pageSubtitle = $selectedAllocation
        ? "View the confirmed physical seating arrangement for this allocation."
        : "Select a confirmed seat allocation by examination date and time.";
} else {
    $pageTitle = "Reports";
    $pageSubtitle = "Select a report to continue.";
}

$isEdit = $reportType === "hall_allocation" && $selectedAllocation && $action === "edit";
$isView = $reportType === "hall_allocation" && $selectedAllocation && $action === "view";
$isSeatEdit = $reportType === "student_seating" && $selectedAllocation && $action === "edit";
$isSeatView = $reportType === "student_seating" && $selectedAllocation && $action === "view";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo clean($pageTitle); ?> | Examination Seating Management System</title>
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/reports.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>
<body>
<div class="overlay" id="overlay"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h2>ESMS</h2>
        <button id="closeSidebar"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <ul class="menu">
        <li><a href="dashboard.php"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
        <li><a href="students.php"><i class="fa-solid fa-user-graduate"></i><span>Students</span></a></li>
        <li><a href="halls.php"><i class="fa-solid fa-school"></i><span>Examination Halls</span></a></li>
        <li><a href="examinations.php"><i class="fa-solid fa-calendar-days"></i><span>Examination Sessions</span></a></li>
        <li><a href="seat_allocation.php"><i class="fa-solid fa-chair"></i><span>Seat Allocation</span></a></li>
        <li><a href="attendance.php"><i class="fa-solid fa-clipboard-check"></i><span>Attendance</span></a></li>
        <li class="active"><a href="reports.php"><i class="fa-solid fa-chart-column"></i><span>Reports</span></a></li>
        <li><a href="settings.php"><i class="fa-solid fa-gear"></i><span>Settings</span></a></li>
        <li class="logout"><a href="../auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a></li>
    </ul>
</div>

<div class="main-content">
<header class="topbar reports-topbar">
    <div class="reports-header">
        <div class="breadcrumb">
            <button type="button" class="menu-button" id="menuBtn" title="Open Menu" aria-label="Open Menu">
                <i class="fa-solid fa-bars"></i>
            </button>
            <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
            <span class="breadcrumb-separator">/</span>
            <a href="reports.php">Reports</a>
            <?php if ($reportType): ?>
                <span class="breadcrumb-separator">/</span>
                <span><?php echo clean($reportType === "hall_allocation" ? "Hall Allocation Details" : "Student Seat Allocation"); ?></span>
            <?php endif; ?>
        </div>

        <div class="report-header-title">
            <h1><?php echo clean($pageTitle); ?></h1>
            <p><?php echo clean($pageSubtitle); ?></p>
        </div>
    </div>
</header>

<main class="reports-page">
    <?php if ($reportType === ""): ?>
        <section class="report-types">
            <a class="report-type-card" href="reports.php?report=hall_allocation">
                <span class="type-icon report-icon-hall"><i class="fa-solid fa-building-columns"></i></span>
                <span class="report-card-content">
                    <small class="report-card-label">HALL ALLOCATION</small>
                    <strong>Hall Allocation Details</strong>
                    <small class="report-card-description"><i class="fa-solid fa-users"></i> View hall-wise allocation details</small>
                </span>
                <i class="fa-solid fa-arrow-right selected-mark"></i>
            </a>

            <a class="report-type-card" href="reports.php?report=student_seating">
                <span class="type-icon report-icon-students"><i class="fa-solid fa-user-group"></i></span>
                <span class="report-card-content">
                    <small class="report-card-label">STUDENTS</small>
                    <strong>Student Seat Allocation</strong>
                    <small class="report-card-description"><i class="fa-solid fa-users"></i> View student seating allocation</small>
                </span>
                <i class="fa-solid fa-arrow-right selected-mark"></i>
            </a>
        </section>

    <?php elseif (!$selectedAllocation): ?>
        <div class="report-flow">
            <a href="reports.php">Reports</a><i class="fa-solid fa-chevron-right"></i>
            <span class="current"><?php echo clean($reportType === "hall_allocation" ? "Hall Allocation Details" : "Student Seat Allocation"); ?></span>
        </div>

        <section class="allocation-list-card">
            <div class="panel-title report-panel-header">
                <a class="back-button report-back-button" href="reports.php">
                    <i class="fa-solid fa-arrow-left"></i> Reports
                </a>
                <div class="report-panel-heading">
                    <h3>Select Seat Allocation</h3>
                    <p>Choose the confirmed allocation using the examination date and session time.</p>
                </div>
                <div></div>
            </div>

            <?php if (!$allocations): ?>
                <div class="empty-report">
                    <i class="fa-solid fa-calendar-xmark"></i>
                    <h3>No confirmed seat allocations</h3>
                    <p>Create and confirm a seat allocation in the Seat Allocation module first.</p>
                </div>
            <?php else: ?>
                <div class="allocation-grid">
                    <?php foreach ($allocations as $allocation): ?>
                        <article class="allocation-card">
                            <div class="allocation-date"><i class="fa-regular fa-calendar"></i> <?php echo clean(formatDate($allocation["exam_date"])); ?></div>
                            <div class="allocation-time"><i class="fa-regular fa-clock"></i> <?php echo clean(formatTime($allocation["exam_time"])); ?> – <?php echo clean(formatTime($allocation["exam_time_to"])); ?></div>
                            <div class="allocation-code"><?php echo clean($allocation["allocation_code"]); ?></div>

                            <div class="allocation-stats">
                                <div class="allocation-stat"><b><?php echo (int)$allocation["total_students"]; ?></b><span>Students</span></div>
                                <div class="allocation-stat"><b><?php echo (int)$allocation["total_halls"]; ?></b><span>Halls</span></div>
                                <div class="allocation-stat"><b><?php echo (int)$allocation["actual_seats"]; ?></b><span>Seats</span></div>
                            </div>

                            <div class="allocation-actions">
                                <?php if ($reportType === "hall_allocation"): ?>
                                    <a class="btn btn-primary" href="reports.php?report=hall_allocation&allocation=<?php echo (int)$allocation["id"]; ?>&action=edit"><i class="fa-solid fa-pen"></i> Edit</a>
                                    <a class="btn btn-light" href="reports.php?report=hall_allocation&allocation=<?php echo (int)$allocation["id"]; ?>&action=view"><i class="fa-solid fa-eye"></i> View</a>
                                <?php else: ?>
                                    <a class="btn btn-primary" href="reports.php?report=student_seating&allocation=<?php echo (int)$allocation["id"]; ?>&action=view"><i class="fa-solid fa-eye"></i> View Seating</a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    <?php elseif ($reportType === "hall_allocation" && $isEdit): ?>
        <div class="report-flow">
            <a href="reports.php">Reports</a><i class="fa-solid fa-chevron-right"></i>
            <a href="reports.php?report=hall_allocation">Hall Allocation Details</a><i class="fa-solid fa-chevron-right"></i>
            <span class="current">Edit Report Details</span>
        </div>

        <section class="report-editor-card">
            <div class="panel-title report-panel-header">
                <a class="back-button report-back-button" href="reports.php?report=hall_allocation">
                    <i class="fa-solid fa-arrow-left"></i> Reports
                </a>
                <div class="report-panel-heading">
                    <h3>Edit Hall Allocation Report</h3>
                    <p>These fields change the printed report only. The confirmed student seating allocation is not changed.</p>
                </div>
                <div class="allocation-code">Allocation: <?php echo clean($selectedAllocation["allocation_code"]); ?></div>
            </div>

            <div class="allocation-info">
                <span><strong>Exam Date:</strong> <?php echo clean(formatDate($selectedAllocation["exam_date"])); ?></span>
                <span><strong>Session:</strong> <?php echo clean(formatTime($selectedAllocation["exam_time"])); ?> – <?php echo clean(formatTime($selectedAllocation["exam_time_to"])); ?></span>
                <span><strong>Students:</strong> <?php echo (int)$selectedAllocation["total_students"]; ?></span>
                <span><strong>Halls:</strong> <?php echo (int)$selectedAllocation["total_halls"]; ?></span>
            </div>

            <form method="post" class="report-editor">
                <input type="hidden" name="action" value="save_report_details">
                <input type="hidden" name="allocation_id" value="<?php echo (int)$selectedAllocation["id"]; ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="exam_type">Examination Type *</label>
                        <select id="exam_type" name="exam_type" required>
                            <?php
                            $types = [
                                "Semester Examination",
                                "Mid Examination",
                                "Unit Test",
                                "Internal Examination",
                                "Practical Examination",
                                "Supplementary Examination",
                                "Other"
                            ];
                            foreach ($types as $type):
                            ?>
                                <option value="<?php echo clean($type); ?>" <?php echo $reportDetails["exam_type"] === $type ? "selected" : ""; ?>><?php echo clean($type); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="exam_title">Exam Title / Name</label>
                        <input id="exam_title" name="exam_title" type="text" maxlength="255" value="<?php echo clean($reportDetails["exam_title"]); ?>" placeholder="Example: Diploma End Examinations">
                        <span class="form-help">Optional. This appears below the college name.</span>
                    </div>

                    <div class="form-group full">
                        <label for="notice_heading">Notice Heading *</label>
                        <input id="notice_heading" name="notice_heading" type="text" maxlength="255" required value="<?php echo clean($reportDetails["notice_heading"]); ?>" placeholder="NOTICE/DISPLAY">
                    </div>

                    <div class="form-group full">
                        <label for="additional_matter">Additional Matter / Instructions</label>
                        <textarea id="additional_matter" name="additional_matter" placeholder="Add any instructions, special notes, reporting instructions, or other matter required by the administrator."><?php echo clean($reportDetails["additional_matter"]); ?></textarea>
                    </div>

                    <div class="form-group full hall-invigilator-section">
                        <label>Invigilators / Hall</label>
                        <span class="form-help">Set the number of invigilators for each hall. This affects the report only and does not change seating.</span>
                        <div class="hall-invigilator-grid">
                            <?php foreach ($editHalls as $editHall): ?>
                                <div class="hall-invigilator-row">
                                    <div><strong><?php echo clean($editHall["hall_name"]); ?></strong><?php if ($editHall["hall_code"] !== ""): ?><small><?php echo clean($editHall["hall_code"]); ?></small><?php endif; ?></div>
                                    <input type="number" min="0" max="99" step="1" name="hall_invigilators[<?php echo (int)$editHall["hall_id"]; ?>]" value="<?php echo (int)$editHall["invigilators"]; ?>" aria-label="Invigilators for <?php echo clean($editHall["hall_name"]); ?>">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="editor-actions">
                    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save & View Report</button>
                </div>
            </form>
        </section>

    <?php elseif ($reportType === "hall_allocation" && $isView): ?>
        <div class="report-flow screen-only">
            <a href="reports.php">Reports</a><i class="fa-solid fa-chevron-right"></i>
            <a href="reports.php?report=hall_allocation">Hall Allocation Details</a><i class="fa-solid fa-chevron-right"></i>
            <span class="current">View Report</span>
        </div>

        <section class="inline-report-panel hall-allocation-panel">
            <div class="panel-title screen-only report-panel-header">
                <a class="back-button report-back-button" href="reports.php?report=hall_allocation">
                    <i class="fa-solid fa-arrow-left"></i> Hall Allocation Details
                </a>
                <div class="report-panel-heading">
                    <h3>Hall Allocation Details</h3>
                    <p><?php echo clean($selectedAllocation["allocation_code"]); ?></p>
                </div>
                <div class="report-actions report-actions-inline">
                    <a class="btn btn-light" href="reports.php?report=hall_allocation&allocation=<?php echo (int)$allocationId; ?>&action=edit"><i class="fa-solid fa-pen"></i> Edit Details</a>
                    <button class="btn btn-primary" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
                </div>
            </div>

            <div class="table-wrap">
                <div class="hall-notice-print">
                    <div class="hall-notice-header">
                        <h1>GOVERNMENT POLYTECHNIC, SIDDIPET (189)</h1>
                        <h2><?php echo clean($reportDetails["exam_type"]); ?></h2>
                        <?php if (!empty($reportDetails["exam_title"])): ?>
                            <h2><?php echo clean($reportDetails["exam_title"]); ?></h2>
                        <?php endif; ?>
                        <h3><?php echo clean($reportDetails["notice_heading"]); ?></h3>
                        <div class="hall-notice-meta">
                            <span><strong>Date:</strong> <?php echo clean(formatDate($selectedAllocation["exam_date"])); ?></span>
                            <span><strong>Session Time:</strong> <?php echo clean(formatTime($selectedAllocation["exam_time"])); ?> to <?php echo clean(formatTime($selectedAllocation["exam_time_to"])); ?></span>
                        </div>
                    </div>

                    <?php if (empty($hallRows)): ?>
                        <div class="empty-report"><i class="fa-solid fa-folder-open"></i><h3>No seating data found</h3><p>This confirmed allocation does not contain any allocated seats.</p></div>
                    <?php else: ?>
                        <table class="hall-allocation-notice-table">
                            <thead>
                                <tr>
                                    <th>S.NO</th>
                                    <th>BRANCH</th>
                                    <th>YEAR/SEM</th>
                                    <th>FROM</th>
                                    <th>TO</th>
                                    <th>TOTAL</th>
                                    <th>TOTAL ROOM<br>STRENGTH</th>
                                    <th>No. of<br>Invigilators</th>
                                    <th>ROOM<br>NUMBER</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $hallSpans = [];
                            foreach ($hallRows as $row) {
                                $hid = (int)$row["hall_id"];
                                $hallSpans[$hid] = ($hallSpans[$hid] ?? 0) + 1;
                            }
                            $lastHall = null;
                            foreach ($hallRows as $row):
                                $hid = (int)$row["hall_id"];
                                $newHall = $hid !== $lastHall;
                                $lastHall = $hid;
                            ?>
                                <tr>
                                    <?php if ($newHall): ?>
                                        <td rowspan="<?php echo (int)$hallSpans[$hid]; ?>"><?php echo (int)$row["s_no"]; ?></td>
                                    <?php endif; ?>
                                    <td><?php echo clean($row["department"]); ?></td>
                                    <td><?php echo clean($row["year_sem"]); ?></td>
                                    <td><?php echo clean($row["from"]); ?></td>
                                    <td><?php echo clean($row["to"]); ?></td>
                                    <td><?php echo (int)$row["total"]; ?></td>
                                    <?php if ($newHall): ?>
                                        <td rowspan="<?php echo (int)$hallSpans[$hid]; ?>"><?php echo (int)$row["room_strength"]; ?></td>
                                        <td rowspan="<?php echo (int)$hallSpans[$hid]; ?>"><?php echo (int)$row["invigilators"]; ?></td>
                                        <td rowspan="<?php echo (int)$hallSpans[$hid]; ?>"><?php echo clean($row["room"]); ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                                <tr class="notice-total">
                                    <td colspan="5">TOTAL</td>
                                    <td><?php echo number_format($hallStats["students"]); ?></td>
                                    <td colspan="3"></td>
                                </tr>
                            </tbody>
                        </table>

                        <?php if (!empty($reportDetails["additional_matter"])): ?>
                            <div class="notice-extra"><strong>Additional Matter / Instructions</strong><?php echo clean($reportDetails["additional_matter"]); ?></div>
                        <?php endif; ?>

                        <div class="notice-signature">PRINCIPAL / CS</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

    <?php elseif ($reportType === "student_seating" && $selectedAllocation && $isSeatEdit): ?>
        <div class="report-flow screen-only">
            <a href="reports.php">Reports</a><i class="fa-solid fa-chevron-right"></i>
            <a href="reports.php?report=student_seating">Student Seat Allocation</a><i class="fa-solid fa-chevron-right"></i>
            <span class="current">Edit Seating</span>
        </div>

        <section class="inline-report-panel student-seat-allocation-panel">
            <div class="panel-title screen-only report-panel-header">
                <a class="back-button report-back-button" href="reports.php?report=student_seating&allocation=<?php echo (int)$allocationId; ?>&action=view">
                    <i class="fa-solid fa-arrow-left"></i> View Seating
                </a>
                <div class="report-panel-heading">
                    <h3>Edit Student Seating</h3>
                </div>
                <div class="report-actions report-actions-inline">
                    <button class="btn btn-light" type="button" onclick="swapAllTwoStudentBenches()">
                        <i class="fa-solid fa-repeat"></i> Swap All 2-Student Benches
                    </button>
                    <button class="btn btn-light" type="button" onclick="swapAllOneStudentBenches()">
                        <i class="fa-solid fa-arrow-right-arrow-left"></i> Swap All 1-Student Benches
                    </button>
                    <button class="btn btn-primary" type="submit" form="seatPositionForm">
                        <i class="fa-solid fa-floppy-disk"></i> Save Positions
                    </button>
                </div>
            </div>

            <?php if (!empty($_GET["error"])): ?>
                <div class="screen-only" style="margin:15px 20px;padding:12px 14px;border-radius:10px;background:#fff1f2;color:#b42318;border:1px solid #fecdd3;">
                    <?php echo clean($_GET["error"]); ?>
                </div>
            <?php endif; ?>

            <form method="post" id="seatPositionForm">
                <input type="hidden" name="action" value="save_seat_positions">
                <input type="hidden" name="allocation_id" value="<?php echo (int)$allocationId; ?>">
                <input type="hidden" name="swap_all_pairs" id="swapAllPairs" value="0">

                <div class="table-wrap">
                    <?php if (!$seatHalls): ?>
                        <div class="empty-report"><i class="fa-solid fa-chair"></i><h3>No seating data found</h3></div>
                    <?php else: ?>
                        <div class="student-seat-map-report">
                        <?php foreach ($seatHalls as $hall): ?>
                            <section class="student-seat-map-hall">
                                <div class="student-seat-map-header">
                                    <h2><?php echo clean($hall["hall_name"]); ?><?php echo $hall["hall_code"] !== "" ? " / " . clean($hall["hall_code"]) : ""; ?></h2>
                                    <p>EDIT LEFT / RIGHT POSITION</p>
                                </div>

                                <table class="student-seat-map-table">
                                    <thead>
                                        <tr>
                                            <th>Row</th>
                                            <?php for ($c = 1; $c <= $hall["columns"]; $c++): ?>
                                                <th>C<?php echo $c; ?></th>
                                            <?php endfor; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php for ($r = 1; $r <= $hall["rows"]; $r++): ?>
                                        <tr>
                                            <th>R<?php echo $r; ?></th>

                                            <?php for ($c = 1; $c <= $hall["columns"]; $c++):
                                                $key = $r . "-" . $c;
                                                $items = $hall["seats"][$key] ?? [];
                                                usort($items, function($a,$b){ return $a["bench_slot"] <=> $b["bench_slot"]; });
                                            ?>
                                                <td class="<?php echo count($items) > 1 ? "multi-seat-bench" : ""; ?>">
                                                    <div class="seat-position-label">R<?php echo $r; ?>-C<?php echo $c; ?></div>
                                                    <?php if ($items): ?>
                                                        <div class="seat-edit-stack" data-bench="<?php echo htmlspecialchars($hall["hall_id"] . "-" . $r . "-" . $c, ENT_QUOTES); ?>">
                                                            <?php foreach ($items as $item): ?>
                                                                <div class="seat-edit-person" data-seat-id="<?php echo (int)$item["id"]; ?>">
                                                                    <span class="seat-edit-pin"><?php echo clean($item["pin_no"]); ?></span>
                                                                    <input type="hidden" class="seat-position-input" name="seat_position[<?php echo (int)$item["id"]; ?>]" value="<?php echo (int)$item["bench_slot"] === 2 ? 2 : 1; ?>">
                                                                    <div class="seat-edit-controls">
                                                                        <button class="seat-move-btn <?php echo (int)$item["bench_slot"] === 1 ? "active" : ""; ?>" type="button" title="Place this student on the left" onclick="moveStudent(this,1)"><i class="fa-solid fa-arrow-left"></i></button>
                                                                        <button class="seat-move-btn <?php echo (int)$item["bench_slot"] === 2 ? "active" : ""; ?>" type="button" title="Place this student on the right" onclick="moveStudent(this,2)"><i class="fa-solid fa-arrow-right"></i></button>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="empty-seat">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endfor; ?>
                                        </tr>
                                    <?php endfor; ?>
                                    </tbody>
                                </table>
                            </section>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </section>

    <?php elseif ($reportType === "student_seating" && $selectedAllocation && $isSeatView): ?>
        <div class="report-flow screen-only">
            <a href="reports.php">Reports</a><i class="fa-solid fa-chevron-right"></i>
            <a href="reports.php?report=student_seating">Student Seat Allocation</a><i class="fa-solid fa-chevron-right"></i>
            <span class="current">View Seating</span>
        </div>

        <section class="inline-report-panel student-seat-allocation-panel">
            <div class="panel-title screen-only report-panel-header">
                <a class="back-button report-back-button" href="reports.php?report=student_seating">
                    <i class="fa-solid fa-arrow-left"></i> Student Seat Allocation
                </a>
                <div class="report-panel-heading">
                    <h3>Student Seat Allocation</h3>
                    <p><?php echo clean(formatDate($selectedAllocation["exam_date"])); ?> · <?php echo clean(formatTime($selectedAllocation["exam_time"])); ?> – <?php echo clean(formatTime($selectedAllocation["exam_time_to"])); ?> · <?php echo clean($selectedAllocation["allocation_code"]); ?></p>
                </div>
                <div class="report-actions report-actions-inline">
                    <a class="btn btn-light" href="reports.php?report=student_seating&allocation=<?php echo (int)$allocationId; ?>&action=edit">
                        <i class="fa-solid fa-pen"></i> Edit Seating
                    </a>
                    <button class="btn btn-primary" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
                </div>
            </div>

            <div class="table-wrap">
                <?php if (!$seatHalls): ?>
                    <div class="empty-report"><i class="fa-solid fa-chair"></i><h3>No seating data found</h3><p>This confirmed allocation does not contain any allocated seats.</p></div>
                <?php else: ?>
                    <div class="student-seat-map-report">
                    <?php foreach ($seatHalls as $hall): ?>
                        <section class="student-seat-map-hall">
                            <div class="student-seat-map-header">
                                <h2><?php echo clean($hall["hall_name"]); ?><?php echo $hall["hall_code"] !== "" ? " / " . clean($hall["hall_code"]) : ""; ?></h2>
                                <p>SEATING</p>
                            </div>
                            <table class="student-seat-map-table">
                                <thead>
                                    <tr>
                                        <th>Row</th>
                                        <?php for ($c = 1; $c <= $hall["columns"]; $c++): ?><th>C<?php echo $c; ?></th><?php endfor; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php for ($r = 1; $r <= $hall["rows"]; $r++): ?>
                                    <tr>
                                        <th>R<?php echo $r; ?></th>
                                        <?php for ($c = 1; $c <= $hall["columns"]; $c++):
                                            $key = $r . "-" . $c;
                                            $items = $hall["seats"][$key] ?? [];
                                            usort($items, function($a,$b){ return $a["bench_slot"] <=> $b["bench_slot"]; });
                                        ?>
                                            <td class="<?php echo count($items) > 1 ? "multi-seat-bench" : ""; ?>">
                                                <div class="seat-position-label">R<?php echo $r; ?>-C<?php echo $c; ?></div>
                                                <?php if ($items): ?>
                                                    <?php $leftPin = ""; $rightPin = ""; foreach ($items as $item) { if ((int)$item["bench_slot"] === 1) { $leftPin = $item["pin_no"]; } elseif ((int)$item["bench_slot"] === 2) { $rightPin = $item["pin_no"]; } } ?>
                                                    <div class="seat-pin-stack">
                                                        <div class="seat-side"><?php echo $leftPin !== "" ? clean($leftPin) : ""; ?></div>
                                                        <div class="seat-side"><?php echo $rightPin !== "" ? clean($rightPin) : ""; ?></div>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="empty-seat">—</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endfor; ?>
                                    </tr>
                                <?php endfor; ?>
                                </tbody>
                            </table>

<div class="student-seat-footer">
    <span>Invigilator(s)/JS</span>
    <span>Principal/CS</span>
</div>

</section>
<?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
</main>
</div>

<script>
function moveStudent(button, position) {
    const person = button.closest('.seat-edit-person');
    const stack = button.closest('.seat-edit-stack');
    if (!person || !stack) return;

    const input = person.querySelector('.seat-position-input');
    if (!input) return;

    const people = Array.from(stack.querySelectorAll('.seat-edit-person'));
    input.value = String(position);

    // If this bench has two students, automatically put the other student
    // on the opposite side so the bench always remains LEFT + RIGHT.
    if (people.length === 2) {
        const other = people.find(p => p !== person);
        const otherInput = other ? other.querySelector('.seat-position-input') : null;
        if (otherInput) otherInput.value = position === 1 ? '2' : '1';
    }

    refreshSeatButtons(stack);
}

function refreshSeatButtons(stack) {
    if (!stack) return;
    stack.querySelectorAll('.seat-edit-person').forEach(person => {
        const input = person.querySelector('.seat-position-input');
        const buttons = person.querySelectorAll('.seat-move-btn');
        const value = input ? Number(input.value) : 0;
        buttons.forEach((btn, index) => btn.classList.toggle('active', value === index + 1));
    });
}

function swapAllOneStudentBenches() {
    if (!confirm("Swap the position of every one-student bench? Two-student benches will remain unchanged.")) return;

    document.querySelectorAll('#seatPositionForm .seat-edit-stack').forEach(stack => {
        const people = Array.from(stack.querySelectorAll('.seat-edit-person'));
        if (people.length !== 1) return;

        const input = people[0].querySelector('.seat-position-input');
        if (!input) return;

        const current = Number(input.value) === 2 ? 2 : 1;
        input.value = current === 1 ? '2' : '1';
        refreshSeatButtons(stack);
    });
}

function swapAllTwoStudentBenches() {
    const form = document.getElementById("seatPositionForm");
    const flag = document.getElementById("swapAllPairs");
    if (!form || !flag) return;

    if (!confirm("Swap the two students on every 2-student bench? One-student benches will remain unchanged.")) return;

    // Swap the currently selected positions in every two-student bench.
    document.querySelectorAll('#seatPositionForm .seat-edit-stack').forEach(stack => {
        const people = Array.from(stack.querySelectorAll('.seat-edit-person'));
        if (people.length !== 2) return;
        const a = people[0].querySelector('.seat-position-input');
        const b = people[1].querySelector('.seat-position-input');
        if (!a || !b) return;
        const av = Number(a.value) === 2 ? 2 : 1;
        a.value = av === 1 ? '2' : '1';
        b.value = av === 1 ? '1' : '2';
        refreshSeatButtons(stack);
    });
}

document.addEventListener("DOMContentLoaded", function () {
    const menuBtn = document.getElementById("menuBtn");
    const closeSidebar = document.getElementById("closeSidebar");
    const sidebar = document.getElementById("sidebar");
    const overlay = document.getElementById("overlay");

    function openMenu() {
        if (sidebar) sidebar.classList.add("show");
        if (overlay) overlay.classList.add("show");
        document.body.classList.add("menu-open");
    }

    function closeMenu() {
        if (sidebar) sidebar.classList.remove("show");
        if (overlay) overlay.classList.remove("show");
        document.body.classList.remove("menu-open");
    }

    if (menuBtn) {
        menuBtn.addEventListener("click", function (event) {
            event.preventDefault();
            if (sidebar && sidebar.classList.contains("show")) {
                closeMenu();
            } else {
                openMenu();
            }
        });
    }

    if (closeSidebar) {
        closeSidebar.addEventListener("click", function (event) {
            event.preventDefault();
            closeMenu();
        });
    }

    if (overlay) {
        overlay.addEventListener("click", closeMenu);
    }

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") closeMenu();
    });
});
</script>
</body>
</html>
