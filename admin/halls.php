<?php

/* =========================================================
   HALL MANAGEMENT MODULE
   File: admin/halls.php
========================================================= */

session_start();

require_once "../config/db_connect.php";


/* =========================================================
   PER-COLUMN BENCH CONFIGURATION
========================================================= */

function ensureHallColumnBenchColumn($conn)
{
    $check = $conn->query("SHOW COLUMNS FROM halls LIKE 'column_benches'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE halls ADD COLUMN column_benches JSON NULL AFTER columns_count");
    }
    if ($check) $check->free();
}

function normalizeHallColumnBenches($rows, $columns, $values = null)
{
    $rows = max(1, (int)$rows);
    $columns = max(1, (int)$columns);
    $result = [];

    if (is_array($values)) {
        for ($i = 0; $i < $columns; $i++) {
            $value = isset($values[$i]) ? (int)$values[$i] : $rows;
            $result[] = max(1, min(100, $value));
        }
    } else {
        for ($i = 0; $i < $columns; $i++) {
            $result[] = $rows;
        }
    }

    return $result;
}

function hallBenchTotal($columnBenches)
{
    if (!is_array($columnBenches)) return 0;
    return array_sum(array_map('intval', $columnBenches));
}

ensureHallColumnBenchColumn($conn);

/* =========================================================
   CACHE / SECURITY
========================================================= */

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

/* =========================================================
   ADMIN LOGIN CHECK
========================================================= */

if (!isset($_SESSION["admin"])) {
    header("Location: ../auth/login.php");
    exit();
}

/* =========================================================
   VARIABLES
========================================================= */

$message = "";
$messageType = "";

/* =========================================================
   HALL CAPACITY
   =========================================================
   Seat Allocation is the single source of truth for the seating
   pattern. Hall Management never stores its own custom pattern.

   Default hall capacity = rows × columns × 2 students.
   After a confirmed allocation, the latest allocation pattern for
   that hall is used to calculate the hall's current capacity.
   ========================================================= */
if (!defined("DEFAULT_BENCH_CAPACITY")) define("DEFAULT_BENCH_CAPACITY", 2);

function calculateAllocationHallCapacity($rows, $columns, $patternType, $patternSettings, $columnBenches = null)
{
    $rows = max(0, (int)$rows);
    $columns = max(0, (int)$columns);
    $columnBenches = normalizeHallColumnBenches($rows, $columns, $columnBenches);
    $benchTotal = hallBenchTotal($columnBenches);

    if ($benchTotal <= 0) {
        return 0;
    }

    $patternType = strtolower(trim((string)$patternType));
    $settings = is_array($patternSettings) ? $patternSettings : [];

    /* Seat Allocation customized pattern:
       odd rows = first bench count
       even rows = second bench count. */
    if ($patternType === "customized") {
        $first = max(0, min(2, (int)(
            $settings["first_bench_students"]
            ?? $settings["students_first_bench"]
            ?? $settings["first_bench"]
            ?? 1
        )));

        $second = max(0, min(2, (int)(
            $settings["second_bench_students"]
            ?? $settings["students_second_bench"]
            ?? $settings["second_bench"]
            ?? 2
        )));

        $total = 0;
        foreach ($columnBenches as $columnRows) {
            for ($row = 1; $row <= (int)$columnRows; $row++) {
                $total += (($row % 2) === 1) ? $first : $second;
            }
        }
        return $total;
    }

    if ($patternType === "single") {
        return $benchTotal;
    }

    if ($patternType === "two_persons") {
        return $benchTotal * 2;
    }

    /* Alternative pattern: one student on every other physical bench. */
    if ($patternType === "alternative") {
        return (int)ceil($benchTotal / 2);
    }

    /* Unknown/no confirmed pattern: use the normal hall capacity. */
    return $benchTotal * DEFAULT_BENCH_CAPACITY;
}

function allocationTablesAvailable($conn)
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    $batches = $conn->query("SHOW TABLES LIKE 'allocation_batches'");
    $seats = $conn->query("SHOW TABLES LIKE 'allocation_seats'");

    $available = ($batches && $batches->num_rows > 0 && $seats && $seats->num_rows > 0);

    if ($batches) $batches->free();
    if ($seats) $seats->free();

    return $available;
}

/* Get the latest confirmed Seat Allocation pattern for every hall. */
function getLatestHallAllocations($conn)
{
    $map = [];

    if (!allocationTablesAvailable($conn)) {
        return $map;
    }

    $sql = "
        SELECT
            ase.hall_id,
            ab.pattern_type,
            ab.pattern_settings,
            COUNT(*) AS allocated_seats
        FROM allocation_seats ase
        INNER JOIN allocation_batches ab
            ON ab.id = ase.allocation_id
        WHERE ab.status = 'confirmed'
          AND ab.id = (
              SELECT ab2.id
              FROM allocation_seats ase2
              INNER JOIN allocation_batches ab2
                  ON ab2.id = ase2.allocation_id
              WHERE ase2.hall_id = ase.hall_id
                AND ab2.status = 'confirmed'
              ORDER BY
                  COALESCE(ab2.confirmed_at, ab2.created_at) DESC,
                  ab2.id DESC
              LIMIT 1
          )
        GROUP BY
            ase.hall_id,
            ab.pattern_type,
            ab.pattern_settings
    ";

    $result = $conn->query($sql);
    if (!$result) {
        return $map;
    }

    while ($row = $result->fetch_assoc()) {
        $settings = json_decode((string)($row["pattern_settings"] ?? ""), true);
        if (!is_array($settings)) {
            $settings = [];
        }

        $map[(int)$row["hall_id"]] = [
            "pattern_type" => (string)$row["pattern_type"],
            "pattern_settings" => $settings,
            "allocated_seats" => (int)$row["allocated_seats"]
        ];
    }

    $result->free();
    return $map;
}

function getDefaultHallCapacity($rows, $columns, $columnBenches = null)
{
    $columnBenches = normalizeHallColumnBenches($rows, $columns, $columnBenches);
    return max(0, hallBenchTotal($columnBenches) * DEFAULT_BENCH_CAPACITY);
}

/* =========================================================
   ADD HALL
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["add_hall"])
) {

    $hallName = trim($_POST["hall_name"] ?? "");

    $hallCode = strtoupper(
        trim($_POST["hall_code"] ?? "")
    );

    $columnsCount = (int)(
        $_POST["columns_count"] ?? 0
    );

    $columnBenches = is_array($_POST["column_benches"] ?? null)
        ? array_values(array_map("intval", $_POST["column_benches"]))
        : [];

    if ($columnsCount > 0 && count($columnBenches) === $columnsCount) {
        $columnBenches = array_map(function ($value) {
            return max(1, min(100, (int)$value));
        }, $columnBenches);
    } else {
        $columnBenches = [];
    }

    // Legacy rows_count is kept in the database for compatibility.
    // It is derived automatically and is never entered by the admin.
    $rowsCount = $columnBenches ? max($columnBenches) : 0;

    $capacity = getDefaultHallCapacity(
        $rowsCount,
        $columnsCount,
        $columnBenches
    );

    $floorNo = trim(
        $_POST["floor_no"] ?? ""
    );

    $buildingName = trim(
        $_POST["building_name"] ?? ""
    );

    /* -----------------------------------------------------
       VALIDATION
    ----------------------------------------------------- */

    if (
        $hallName === "" ||
        $hallCode === "" ||
        $columnsCount <= 0 ||
        count($columnBenches) !== $columnsCount ||
        !$columnBenches ||
        $capacity <= 0 ||
        $floorNo === "" ||
        $buildingName === ""
    ) {

        $message = "Please enter all hall details correctly.";
        $messageType = "error";

    } else {

        /* -------------------------------------------------
           CHECK DUPLICATE HALL CODE
        ------------------------------------------------- */

        $check = $conn->prepare("
            SELECT id
            FROM halls
            WHERE hall_code = ?
            LIMIT 1
        ");

        $check->bind_param(
            "s",
            $hallCode
        );

        $check->execute();

        $checkResult = $check->get_result();

        if ($checkResult->num_rows > 0) {

            $message = "This hall code already exists.";
            $messageType = "error";

        } else {

            /* ---------------------------------------------
               INSERT HALL
            --------------------------------------------- */

            $stmt = $conn->prepare("
                INSERT INTO halls
                (
                    hall_name,
                    hall_code,
                    rows_count,
                    columns_count,
                    column_benches,
                    capacity,
                    floor_no,
                    building_name
                )
                VALUES
                (
                    ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $columnBenchesJson = json_encode($columnBenches);

            $stmt->bind_param(
"ssiissss",
$hallName,
$hallCode,
$rowsCount,
$columnsCount,
$columnBenchesJson,
$capacity,
$floorNo,
$buildingName
            );

            if ($stmt->execute()) {
                $newHallId = (int)$conn->insert_id;

                /* -----------------------------------------
                   NOTIFICATION
                ----------------------------------------- */

                $notificationTitle = "Hall Added";

                $notificationMessage =
                    "Hall " .
                    $hallName .
                    " (" .
                    $hallCode .
                    ") was added successfully.";

                $notificationType = "hall_added";

                $notify = $conn->prepare("
                    INSERT INTO notifications
                    (
                        title,
                        message,
                        type,
                        is_read
                    )
                    VALUES
                    (
                        ?, ?, ?, 0
                    )
                ");

                if ($notify) {

                    $notify->bind_param(
                        "sss",
                        $notificationTitle,
                        $notificationMessage,
                        $notificationType
                    );

                    $notify->execute();
                    $notify->close();
                }

                /* -----------------------------------------
                   SUCCESS FLASH
                ----------------------------------------- */

                $_SESSION["flash_message"] =
                    "Hall " .
                    $hallName .
                    " (" .
                    $hallCode .
                    ") added successfully.";

                $_SESSION["flash_message_type"] = "success";

                header("Location: halls.php");
                exit();

            } else {

                $message = "Unable to add hall.";
                $messageType = "error";
            }

            $stmt->close();
        }

        $check->close();
    }
}

/* =========================================================
   EDIT HALL
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["edit_hall"])
) {

    $hallId = (int)(
        $_POST["hall_id"] ?? 0
    );

    $hallName = trim(
        $_POST["hall_name"] ?? ""
    );

    $hallCode = strtoupper(
        trim($_POST["hall_code"] ?? "")
    );

    $columnsCount = (int)(
        $_POST["columns_count"] ?? 0
    );

    $columnBenches = is_array($_POST["column_benches"] ?? null)
        ? array_values(array_map("intval", $_POST["column_benches"]))
        : [];

    if ($columnsCount > 0 && count($columnBenches) === $columnsCount) {
        $columnBenches = array_map(function ($value) {
            return max(1, min(100, (int)$value));
        }, $columnBenches);
    } else {
        $columnBenches = [];
    }

    // Legacy rows_count is kept in the database for compatibility.
    // It is derived automatically and is never entered by the admin.
    $rowsCount = $columnBenches ? max($columnBenches) : 0;

    /* Keep the exact bench count for every column.
       Example: [8, 8, 10] must remain [8, 8, 10]. */
    $columnBenchesJson = json_encode(array_values($columnBenches));

    $capacity = getDefaultHallCapacity(
        $rowsCount,
        $columnsCount,
        $columnBenches
    );

    $floorNo = trim(
        $_POST["floor_no"] ?? ""
    );

    $buildingName = trim(
        $_POST["building_name"] ?? ""
    );

    /* -----------------------------------------------------
       BASIC VALIDATION
    ----------------------------------------------------- */

    if (
        $hallId <= 0 ||
        $hallName === "" ||
        $hallCode === "" ||
        $columnsCount <= 0 ||
        count($columnBenches) !== $columnsCount ||
        !$columnBenches ||
        $capacity <= 0 ||
        $floorNo === "" ||
        $buildingName === ""
    ) {

        $message = "Please enter all hall details correctly.";
        $messageType = "error";

    } else {

        /* -------------------------------------------------
           GET EXISTING HALL
        ------------------------------------------------- */

        $getHall = $conn->prepare("
            SELECT
                id,
                hall_name,
                hall_code,
                rows_count,
                columns_count,
                capacity,
                floor_no,
                building_name
            FROM halls
            WHERE id = ?
            LIMIT 1
        ");

        $getHall->bind_param(
            "i",
            $hallId
        );

        $getHall->execute();

        $hallResult = $getHall->get_result();

        $oldHall = $hallResult->fetch_assoc();

        $getHall->close();

        if (!$oldHall) {

            $message = "Hall not found.";
            $messageType = "error";

        } else {

            /* -------------------------------------------------
               CHECK ALLOCATED SEATS
            ------------------------------------------------- */

            $allocatedSeats = 0;

            $checkAllocation = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM allocation_seats ase
                INNER JOIN allocation_batches ab
                    ON ab.id = ase.allocation_id
                WHERE ase.hall_id = ?
                  AND ab.status = 'confirmed'
            ");

            if ($checkAllocation) {

                $checkAllocation->bind_param(
                    "i",
                    $hallId
                );

                $checkAllocation->execute();

                $allocationResult =
                    $checkAllocation
                        ->get_result()
                        ->fetch_assoc();

                $allocatedSeats =
                    (int)($allocationResult["total"] ?? 0);

                $checkAllocation->close();
            }

            /* -------------------------------------------------
               CAPACITY CANNOT BE LESS THAN ALLOCATED SEATS
            ------------------------------------------------- */

            if ($capacity < $allocatedSeats) {

                $message =
                    "Capacity cannot be reduced below " .
                    $allocatedSeats .
                    " because seats are already allocated in this hall.";

                $messageType = "error";

            } else {

                /* -------------------------------------------------
                   CHECK DUPLICATE HALL CODE
                   EXCLUDING CURRENT HALL
                ------------------------------------------------- */

                $checkCode = $conn->prepare("
                    SELECT id
                    FROM halls
                    WHERE hall_code = ?
                    AND id != ?
                    LIMIT 1
                ");

                $checkCode->bind_param(
                    "si",
                    $hallCode,
                    $hallId
                );

                $checkCode->execute();

                $duplicateResult =
                    $checkCode->get_result();

                if ($duplicateResult->num_rows > 0) {

                    $message =
                        "This hall code already exists.";

                    $messageType = "error";

                } else {

                    /* -------------------------------------------------
                       UPDATE HALL
                    ------------------------------------------------- */

                    $stmt = $conn->prepare("
                        UPDATE halls
                        SET
                            hall_name = ?,
                            hall_code = ?,
                            rows_count = ?,
                            columns_count = ?,
                            column_benches = ?,
                            capacity = ?,
                            floor_no = ?,
                            building_name = ?
                        WHERE id = ?
                    ");

                    $stmt->bind_param(
                        "ssiissssi",
                        $hallName,
                        $hallCode,
                        $rowsCount,
                        $columnsCount,
                        $columnBenchesJson,
                        $capacity,
                        $floorNo,
                        $buildingName,
                        $hallId
                    );

                    if ($stmt->execute()) {

                        /* ---------------------------------------------
                           NOTIFICATION
                        --------------------------------------------- */

                        $notificationTitle =
                            "Hall Updated";

                        $notificationMessage =
                            "Hall " .
                            $hallName .
                            " (" .
                            $hallCode .
                            ") was updated successfully.";

                        $notificationType =
                            "hall_updated";

                        $notify = $conn->prepare("
                            INSERT INTO notifications
                            (
                                title,
                                message,
                                type,
                                is_read
                            )
                            VALUES
                            (
                                ?, ?, ?, 0
                            )
                        ");

                        if ($notify) {

                            $notify->bind_param(
                                "sss",
                                $notificationTitle,
                                $notificationMessage,
                                $notificationType
                            );

                            $notify->execute();
                            $notify->close();
                        }

                        /* ---------------------------------------------
                           SUCCESS FLASH
                        --------------------------------------------- */

                        $_SESSION["flash_message"] =
                            "Hall " .
                            $hallName .
                            " (" .
                            $hallCode .
                            ") updated successfully.";

                        $_SESSION["flash_message_type"] =
                            "success";

                        header("Location: halls.php");
                        exit();

                    } else {

                        $message =
                            "Unable to update hall.";

                        $messageType =
                            "error";
                    }

                    $stmt->close();
                }

                $checkCode->close();
            }
        }
    }
}

/* =========================================================
   DELETE HALL
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["delete_hall"])
) {

    $hallId = (int)(
        $_POST["hall_id"] ?? 0
    );

    if ($hallId > 0) {

        /* -------------------------------------------------
           GET HALL DETAILS
        ------------------------------------------------- */

        $getHall = $conn->prepare("
            SELECT
                hall_name,
                hall_code
            FROM halls
            WHERE id = ?
            LIMIT 1
        ");

        $getHall->bind_param(
            "i",
            $hallId
        );

        $getHall->execute();

        $hallData =
            $getHall
                ->get_result()
                ->fetch_assoc();

        $getHall->close();

        if (!$hallData) {

            $message = "Hall not found.";
            $messageType = "error";

        } else {

            /* -------------------------------------------------
               CHECK HALL USAGE
            ------------------------------------------------- */

            $checkUsage = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM allocation_seats ase
                INNER JOIN allocation_batches ab
                    ON ab.id = ase.allocation_id
                WHERE ase.hall_id = ?
            ");

            if ($checkUsage) {

                $checkUsage->bind_param(
                    "i",
                    $hallId
                );

                $checkUsage->execute();

                $usageData =
                    $checkUsage
                        ->get_result()
                        ->fetch_assoc();

                $usageCount =
                    (int)$usageData["total"];

                $checkUsage->close();

            } else {

                $usageCount = 0;
            }

            /* -------------------------------------------------
               DO NOT DELETE USED HALL
            ------------------------------------------------- */

            if ($usageCount > 0) {

                $message =
                    "This hall cannot be deleted because it is already used in seat allocation.";

                $messageType = "error";

            } else {

                /* ---------------------------------------------
                   DELETE HALL
                --------------------------------------------- */

                $stmt = $conn->prepare("
                    DELETE FROM halls
                    WHERE id = ?
                ");

                $stmt->bind_param(
                    "i",
                    $hallId
                );

                if ($stmt->execute()) {

                    /* -----------------------------------------
                       NOTIFICATION
                    ----------------------------------------- */

                    $notificationTitle =
                        "Hall Deleted";

                    $notificationMessage =
                        "Hall " .
                        $hallData["hall_name"] .
                        " (" .
                        $hallData["hall_code"] .
                        ") was deleted successfully.";

                    $notificationType =
                        "hall_deleted";

                    $notify = $conn->prepare("
                        INSERT INTO notifications
                        (
                            title,
                            message,
                            type,
                            is_read
                        )
                        VALUES
                        (
                            ?, ?, ?, 0
                        )
                    ");

                    if ($notify) {

                        $notify->bind_param(
                            "sss",
                            $notificationTitle,
                            $notificationMessage,
                            $notificationType
                        );

                        $notify->execute();
                        $notify->close();
                    }

                    /* -----------------------------------------
                       SUCCESS FLASH
                    ----------------------------------------- */

                    $_SESSION["flash_message"] =
                        "Hall " .
                        $hallData["hall_name"] .
                        " (" .
                        $hallData["hall_code"] .
                        ") deleted successfully.";

                    $_SESSION["flash_message_type"] =
                        "success";

                    header("Location: halls.php");
                    exit();

                } else {

                    $message =
                        "Unable to delete hall.";

                    $messageType =
                        "error";
                }

                $stmt->close();
            }
        }
    }
}

/* =========================================================
   FLASH MESSAGE
========================================================= */

if (
    isset($_SESSION["flash_message"])
) {

    $message =
        $_SESSION["flash_message"];

    $messageType =
        $_SESSION["flash_message_type"];

    unset(
        $_SESSION["flash_message"]
    );

    unset(
        $_SESSION["flash_message_type"]
    );
}

/* =========================================================
   FETCH HALLS
========================================================= */

/*
 * Hall status must read the NEW allocation structure.
 * Seat Allocation saves students in allocation_seats and links them
 * to allocation_batches.  The old seat_allocation table is not the
 * source of truth for the current allocation module.
 */
/* =========================================================
   HALL LIST
========================================================= */

$latestHallAllocations = getLatestHallAllocations($conn);

$halls = $conn->query("
    SELECT
        h.id,
        h.hall_name,
        h.hall_code,
        h.rows_count,
        h.columns_count,
        h.column_benches,
        h.capacity,
        h.floor_no,
        h.building_name,
        h.created_at
    FROM halls h
    ORDER BY h.id ASC
");

/* ---------------------------------------------------------
   CONVERT RESULT INTO ARRAY
--------------------------------------------------------- */

$hallList = [];

if ($halls) {
    while ($hall = $halls->fetch_assoc()) {
        $hallId = (int)$hall["id"];
        $rows = (int)$hall["rows_count"];
        $columns = (int)$hall["columns_count"];
        $decodedColumnBenches = json_decode((string)($hall["column_benches"] ?? ""), true);
        $hall["column_benches_array"] = normalizeHallColumnBenches($rows, $columns, $decodedColumnBenches);

        $allocation = $latestHallAllocations[$hallId] ?? null;

        if ($allocation) {
            $hall["allocation_pattern_type"] = $allocation["pattern_type"];
            $hall["allocation_pattern_settings"] = $allocation["pattern_settings"];
            $hall["configured_capacity"] = calculateAllocationHallCapacity(
                $rows,
                $columns,
                $allocation["pattern_type"],
                $allocation["pattern_settings"],
                $hall["column_benches_array"]
            );
            $hall["allocated_seats"] = (int)$allocation["allocated_seats"];
        } else {
            $hall["allocation_pattern_type"] = "default";
            $hall["allocation_pattern_settings"] = [];
            $hall["configured_capacity"] = getDefaultHallCapacity($rows, $columns, $hall["column_benches_array"]);
            $hall["allocated_seats"] = 0;
        }

        $hall["seats_left"] = max(
            0,
            (int)$hall["configured_capacity"] - (int)$hall["allocated_seats"]
        );

        $hallList[] = $hall;
    }
}

/* =========================================================
   HALL COUNT
========================================================= */

$hallCountResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM halls
");

$hallCount =
    (int)$hallCountResult
        ->fetch_assoc()["total"];

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
        Examination Halls | ESMS
    </title>

    <!-- GOOGLE FONT -->

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <!-- FONT AWESOME -->

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
    >

    <!-- HALL CSS -->

    <link
        rel="stylesheet"
        href="../assets/css/halls.css"
    >

</head>

<body>

<!-- =====================================================
     SIDEBAR OVERLAY
===================================================== -->

<div
    class="overlay"
    id="overlay"
></div>

<!-- =====================================================
     SIDEBAR
===================================================== -->

<div
    class="sidebar"
    id="sidebar"
>

    <!-- SIDEBAR HEADER -->

    <div class="sidebar-header">

        <h2>
            ESMS
        </h2>

        <button
            type="button"
            id="closeSidebar"
            title="Close Menu"
        >
            <i class="fa-solid fa-xmark"></i>
        </button>

    </div>

    <!-- SIDEBAR MENU -->

    <ul class="menu">

        <li>
            <a href="dashboard.php">

                <i class="fa-solid fa-house"></i>

                <span>
                    Dashboard
                </span>

            </a>
        </li>

        <li>
            <a href="students.php">

                <i class="fa-solid fa-user-graduate"></i>

                <span>
                    Students
                </span>

            </a>
        </li>

        <li class="active">

            <a href="halls.php">

                <i class="fa-solid fa-school"></i>

                <span>
                    Examination Halls
                </span>

            </a>

        </li>

        <li>

            <a href="examinations.php">

                <i class="fa-solid fa-calendar-days"></i>

                <span>
                    Examination Sessions
                </span>

            </a>

        </li>

        <li>

            <a href="seat_allocation.php">

                <i class="fa-solid fa-chair"></i>

                <span>
                    Seat Allocation
                </span>

            </a>

        </li>

        <li>

            <a href="attendance.php">

                <i class="fa-solid fa-clipboard-check"></i>

                <span>
                    Attendance
                </span>

            </a>

        </li>

        <li>

            <a href="reports.php">

                <i class="fa-solid fa-chart-column"></i>

                <span>
                    Reports
                </span>

            </a>

        </li>

        <li>

            <a href="settings.php">

                <i class="fa-solid fa-gear"></i>

                <span>
                    Settings
                </span>

            </a>

        </li>

        <li class="logout">

            <a href="../auth/logout.php">

                <i class="fa-solid fa-right-from-bracket"></i>

                <span>
                    Logout
                </span>

            </a>

        </li>

    </ul>

</div>

<!-- =====================================================
     MAIN PAGE CONTENT
===================================================== -->

<main class="page-content">

    <!-- PAGE HEADER -->

    <section class="page-header">

        <!-- BREADCRUMB -->

        <div class="breadcrumb">

            <button
                type="button"
                class="page-menu-btn"
                id="menuBtn"
                title="Open Menu"
                aria-label="Open Menu"
            >

                <i class="fa-solid fa-bars"></i>

            </button>

            <a
                href="dashboard.php"
                class="breadcrumb-dashboard"
            >

                <i class="fa-solid fa-house"></i>

                Dashboard

            </a>

            <span class="breadcrumb-separator">
                /
            </span>

            <span>
                Examination Halls
            </span>

        </div>

        <!-- PAGE TITLE ROW -->

        <div class="page-header-row">

            <div>

                <span class="section-label">
                    HALL MANAGEMENT
                </span>

                <h1>
                    Examination Halls
                </h1>

                <p>
                    Add and manage halls using rows and columns for seating layout.
                </p>

            </div>

            <div class="header-actions">

                <button
                    type="button"
                    class="view-halls-btn"
                    id="viewHallStatus"
                >

                    <i class="fa-solid fa-table"></i>

                    View Seat Status

                </button>

                <button
                    type="button"
                    class="add-hall-btn"
                    id="openHallModal"
                >

                    <i class="fa-solid fa-plus"></i>

                    Add Hall

                </button>

            </div>

        </div>

    </section>

    <!-- MESSAGE -->

    <?php if ($message !== "") { ?>

        <div
            class="alert <?php echo htmlspecialchars($messageType); ?>"
        >

            <?php if ($messageType === "success") { ?>

                <i class="fa-solid fa-circle-check"></i>

            <?php } else { ?>

                <i class="fa-solid fa-circle-exclamation"></i>

            <?php } ?>

            <span>

                <?php
                echo htmlspecialchars($message);
                ?>

            </span>

        </div>

    <?php } ?>

    <!-- =================================================
         AVAILABLE HALLS
    ================================================= -->

    <section class="hall-summary">

        <div class="summary-title">

            <div>

                <span class="section-label">
                    EXAMINATION FACILITIES
                </span>

                <h2>
                    Available Halls
                </h2>

                <p>
                    Examination rooms registered in the system.
                </p>

            </div>

            <div class="hall-count">

                <i class="fa-solid fa-school"></i>

                <span>
                    <?php echo $hallCount; ?>
                </span>

                Halls

            </div>

        </div>

        <!-- HALL GRID -->

        <div class="hall-grid">

            <?php if (count($hallList) > 0) { ?>

                <?php foreach ($hallList as $hall) { ?>

                    <article class="hall-card">

                        <!-- CARD HEADER -->

                        <div class="hall-card-top">

                            <div class="hall-icon">

                                <i class="fa-solid fa-school"></i>

                            </div>

                            <span class="hall-code">

                                <?php
                                echo htmlspecialchars(
                                    $hall["hall_code"]
                                );
                                ?>

                            </span>

                        </div>

                        <!-- HALL INFORMATION -->

                        <div class="hall-info">

                            <h3>

                                <?php
                                echo htmlspecialchars(
                                    $hall["hall_name"]
                                );
                                ?>

                            </h3>

                            <p>

                                <i class="fa-solid fa-building"></i>

                                <?php
                                echo htmlspecialchars(
                                    $hall["building_name"]
                                );
                                ?>

                            </p>

                        </div>

                        <!-- DETAILS -->

                        <div class="hall-details">

                            <div class="hall-detail">

                                <span>

                                    <i class="fa-solid fa-table-cells"></i>

                                    Columns

                                </span>

                                <strong>

                                    <?php
                                    echo (int)$hall["columns_count"];
                                    ?>

                                </strong>

                            </div>

                            <div class="hall-detail">

                                <span>

                                    <i class="fa-solid fa-chair"></i>

                                    Benches

                                </span>

                                <strong>

                                    <?php
                                    echo (int)hallBenchTotal($hall["column_benches_array"]);
                                    ?>

                                </strong>

                            </div>
                            <div class="hall-detail">
                                <span>
                                    <i class="fa-solid fa-users"></i>
                                    Capacity
                                </span>
                                <strong>
                                    <?php
                                    echo (int)$hall["configured_capacity"];
                                    ?>
                                </strong>
                            </div>

                            <div class="hall-detail">
                                <span>
                                    <i class="fa-solid fa-layer-group"></i>
                                    Floor
                                </span>
                                <strong>
                                    <?php
                                    echo htmlspecialchars(
                                        $hall["floor_no"]
                                    );
                                    ?>
                                </strong>
                            </div>

                        </div>

                        <!-- CARD FOOTER -->

                        <div class="hall-card-footer">

                            <span class="hall-created">

                                <i class="fa-regular fa-calendar"></i>

                                <?php

                                echo date(
                                    "d M Y",
                                    strtotime(
                                        $hall["created_at"]
                                    )
                                );

                                ?>

                            </span>

                            <!-- CARD ACTIONS -->

                            <div class="hall-actions">

                                <!-- EDIT -->

                                <button
                                    type="button"
                                    class="edit-hall-btn"
                                    title="Edit Hall"

                                    data-id="<?php echo (int)$hall["id"]; ?>"

                                    data-name="<?php echo htmlspecialchars(
                                        $hall["hall_name"],
                                        ENT_QUOTES
                                    ); ?>"

                                    data-code="<?php echo htmlspecialchars(
                                        $hall["hall_code"],
                                        ENT_QUOTES
                                    ); ?>"

                                    data-rows="<?php echo (int)$hall["rows_count"]; ?>"

                                    data-columns="<?php echo (int)$hall["columns_count"]; ?>"

                                    data-column-benches="<?php echo htmlspecialchars(json_encode($hall["column_benches_array"]), ENT_QUOTES, "UTF-8"); ?>"

                                    data-capacity="<?php echo (int)$hall["configured_capacity"]; ?>"

                                    data-pattern-type="<?php echo htmlspecialchars(
                                        $hall["allocation_pattern_type"] ?? "default",
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ); ?>"

                                    data-floor="<?php echo htmlspecialchars(
                                        $hall["floor_no"],
                                        ENT_QUOTES
                                    ); ?>"

                                    data-building="<?php echo htmlspecialchars(
                                        $hall["building_name"],
                                        ENT_QUOTES
                                    ); ?>"
                                >

                                    <i class="fa-solid fa-pen"></i>

                                </button>

                                <!-- DELETE -->

                                <form
                                    method="POST"
                                    class="delete-form"
                                    onsubmit="return confirm(
                                        'Are you sure you want to delete <?php echo htmlspecialchars(
                                            $hall["hall_name"],
                                            ENT_QUOTES
                                        ); ?> (<?php echo htmlspecialchars(
                                            $hall["hall_code"],
                                            ENT_QUOTES
                                        ); ?>)?'
                                    );"
                                >

                                    <input
                                        type="hidden"
                                        name="hall_id"
                                        value="<?php echo (int)$hall["id"]; ?>"
                                    >

                                    <button
                                        type="submit"
                                        name="delete_hall"
                                        class="delete-hall-btn"
                                        title="Delete Hall"
                                    >

                                        <i class="fa-solid fa-trash"></i>

                                    </button>

                                </form>

                            </div>

                        </div>

                    </article>

                <?php } ?>

            <?php } else { ?>

                

            <?php } ?>

        </div>

    </section>

    <!-- =====================================================
         HALL SEAT STATUS
    ===================================================== -->

    <section
        class="hall-seat-status"
        id="hallSeatStatus"
    >

        <div class="summary-title">

            <div>

                <span class="section-label">
                    SEAT ALLOCATION
                </span>

                <h2>
                    Hall Seat Status
                </h2>

                <p>
                    View total, allocated and remaining seats for every examination hall.
                </p>

            </div>

            <button
                type="button"
                class="close-status-btn"
                id="closeHallStatus"
            >

                <i class="fa-solid fa-xmark"></i>

                Close

            </button>

        </div>

        <div class="hall-table-wrapper">

            <table class="hall-seat-table">

                <thead>

                    <tr>

                        <th>#</th>

                        <th>Hall</th>

                        <th>Code</th>

                        <th>Floor</th>

                        <th>Columns / Benches</th>

                        <th>Seating Capacity</th>

                        <th>Allocated</th>

                        <th>Seats Left</th>

                        <th>Status</th>

                    </tr>

                </thead>

                <tbody>

                    <?php

                    if (count($hallList) > 0) {

                        $serial = 1;

                        foreach ($hallList as $hall) {

                            $totalSeats =
                                (int)$hall["configured_capacity"];

                            $allocatedSeats =
                                (int)$hall["allocated_seats"];

                            $seatsLeft =
                                (int)$hall["seats_left"];

                            if ($seatsLeft <= 0) {

                                $statusClass = "full";
                                $statusText = "Full";

                            } elseif ($allocatedSeats > 0) {

                                $statusClass = "partial";
                                $statusText = "Partially Filled";

                            } else {

                                $statusClass = "available";
                                $statusText = "Available";
                            }

                    ?>

                            <tr>

                                <td>
                                    <?php echo $serial++; ?>
                                </td>

                                <td class="table-hall-name">

                                    <i class="fa-solid fa-school"></i>

                                    <?php
                                    echo htmlspecialchars(
                                        $hall["hall_name"]
                                    );
                                    ?>

                                </td>

                                <td>

                                    <span class="table-hall-code">

                                        <?php
                                        echo htmlspecialchars(
                                            $hall["hall_code"]
                                        );
                                        ?>

                                    </span>

                                </td>

                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $hall["floor_no"]
                                    );
                                    ?>

                                </td>

                                <td>
                                    <?php
                                    echo (int)$hall["columns_count"] . " / " . hallBenchTotal($hall["column_benches_array"]);
                                    ?>
                                </td>

                                <td>

                                    <strong>
                                        <?php echo $totalSeats; ?>
                                    </strong>

                                </td>

                                <td>

                                    <strong class="allocated-count">
                                        <?php echo $allocatedSeats; ?>
                                    </strong>

                                </td>

                                <td>

                                    <strong class="remaining-count">
                                        <?php echo $seatsLeft; ?>
                                    </strong>

                                </td>

                                <td>

                                    <span
                                        class="seat-status <?php echo $statusClass; ?>"
                                    >

                                        <?php
                                        echo $statusText;
                                        ?>

                                    </span>

                                </td>

                            </tr>

                    <?php

                        }

                    } else {

                    ?>

                        <tr>

                            <td
                                colspan="9"
                                class="no-halls-row"
                            >

                                <i class="fa-solid fa-school"></i>

                                No examination halls available.

                            </td>

                        </tr>

                    <?php } ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

<!-- =====================================================
     FOOTER
===================================================== -->

<footer class="dashboard-footer">

    <p>

        © <?php echo date("Y"); ?>

        Examination Seating Management System

    </p>

    <span>
        Administrator Panel
    </span>

</footer>

<!-- =====================================================
     ADD HALL MODAL
===================================================== -->

<div
    class="modal-overlay"
    id="hallModal"
>

    <div class="hall-modal">

        <div class="modal-header">

            <div>

                <span class="section-label">
                    HALL MANAGEMENT
                </span>

                <h2>
                    Add Examination Hall
                </h2>

            </div>

            <button
                type="button"
                id="closeHallModal"
                class="modal-close"
                title="Close"
            >

                <i class="fa-solid fa-xmark"></i>

            </button>

        </div>

        <!-- ADD FORM -->

        <form
            method="POST"
            class="hall-form"
        >

            <div class="form-group">

                <label>
                    Hall Name
                    <span>*</span>
                </label>

                <div class="input-box">

                    <i class="fa-solid fa-school"></i>

                    <input
                        type="text"
                        name="hall_name"
                        placeholder="Enter Hall name"
                        maxlength="100"
                        autocomplete="off"
                        required
                    >

                </div>

            </div>

            <div class="form-group">

                <label>
                    Hall Code
                    <span>*</span>
                </label>

                <div class="input-box">

                    <i class="fa-solid fa-hashtag"></i>

                    <input
                        type="text"
                        name="hall_code"
                        placeholder="Enter Hall code"
                        maxlength="20"
                        autocomplete="off"
                        required
                    >

                </div>

            </div>

<div class="form-group">

                <label>
                    Columns
                    <span>*</span>
                </label>

                <div class="input-box">

                    <i class="fa-solid fa-arrows-left-right"></i>

                    <input
                        type="number"
                        name="columns_count"
                        id="columns_count"
                        min="1"
                        max="100"
                        placeholder="Enter number of columns"
                        autocomplete="off"
                        required
                    >

                </div>

            </div>

            <div class="form-group">

                <label>
                    Benches in Each Column
                    <span>*</span>
                </label>

                <div id="columnBenchesContainer" class="column-benches-container"></div>

                <small class="form-help">
                    Example: Column 1 = 8, Column 2 = 10, Column 3 = 9.
                </small>

            </div>

            <div class="form-group">

                <label>
                    Capacity
                </label>

                <div class="input-box">

                    <i class="fa-solid fa-users"></i>

                    <input
                        type="number"
                        id="capacity_display"
                        min="1"
                        readonly
                        placeholder="Calculated from total benches"
                    >

                </div>

            </div>

            <div class="form-group">

                <label>
                    Floor No
                    <span>*</span>
                </label>

                <div class="input-box">

                    <i class="fa-solid fa-layer-group"></i>

                    <input
                        type="text"
                        name="floor_no"
                        placeholder="Enter floor (e.g. Ground Floor, GF, 1st Floor)"
                        autocomplete="off"
                        maxlength="50"
                        required
                    >

                </div>

            </div>

            <div class="form-group">

                <label>
                    Building
                    <span>*</span>
                </label>

                <div class="input-box">

                    <i class="fa-solid fa-building"></i>

                    <input
                        type="text"
                        name="building_name"
                        placeholder="Enter Building name"
                        maxlength="200"
                        autocomplete="off"
                        required
                    >

                </div>

            </div>

            <div class="modal-actions">

                <button
                    type="button"
                    id="cancelHallModal"
                    class="cancel-btn"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    name="add_hall"
                    class="save-hall-btn"
                >

                    <i class="fa-solid fa-plus"></i>

                    Add Hall

                </button>

            </div>

        </form>

    </div>

</div>

<!-- =====================================================
     EDIT HALL MODAL
===================================================== -->

<div
    class="modal-overlay"
    id="editHallModal"
>

    <div class="hall-modal">

        <!-- MODAL HEADER -->

        <div class="modal-header">

            <div>

                <span class="section-label">
                    HALL MANAGEMENT
                </span>

                <h2>
                    Edit Examination Hall
                </h2>

            </div>

            <button
                type="button"
                id="closeEditHallModal"
                class="modal-close"
                title="Close"
            >

                <i class="fa-solid fa-xmark"></i>

            </button>

        </div>

        <!-- EDIT FORM -->

        <form
            method="POST"
            class="hall-form"
            id="editHallForm"
        >

            <input
                type="hidden"
                name="hall_id"
                id="edit_hall_id"
            >

            <!-- HALL NAME -->

            <div class="form-group">

                <label>
                    Hall Name
                    <span>*</span>
                </label>

                <div class="input-box">

                    <i class="fa-solid fa-school"></i>

                    <input
                        type="text"
                        name="hall_name"
                        id="edit_hall_name"
                        placeholder="Enter Hall name"
                        maxlength="100"
                        required
                    >

                </div>

            </div>

            <!-- HALL CODE -->

            <div class="form-group">

                <label>
                    Hall Code
                    <span>*</span>
                </label>

                <div class="input-box">

                    <i class="fa-solid fa-hashtag"></i>

                    <input
                        type="text"
                        name="hall_code"
                        id="edit_hall_code"
                        placeholder="Enter Hall code"
                        maxlength="20"
                        required
                    >

                </div>

            </div>

            <!-- CAPACITY -->

            <!-- ROWS -->

<!-- COLUMNS -->

            <div class="form-group">

                <label>
                    Columns
                    <span>*</span>
                </label>

                <div class="input-box">

                    <i class="fa-solid fa-arrows-left-right"></i>

                    <input
                        type="number"
                        name="columns_count"
                        id="edit_columns_count"
                        min="1"
                        max="100"
                        placeholder="Enter number of columns"
                        required
                    >

                </div>

            </div>

            <!-- BENCHES IN EACH COLUMN -->

            <div class="form-group">

                <label>
                    Benches in Each Column
                    <span>*</span>
                </label>

                <div id="editColumnBenchesContainer" class="column-benches-container"></div>

                <small class="form-help">
                    Each column can have a different number of benches.
                </small>

            </div>

            <!-- CAPACITY -->

            <div class="form-group">

                <label>
                    Capacity
                </label>

                <div class="input-box">

                    <i class="fa-solid fa-users"></i>

                    <input
                        type="number"
                        id="edit_capacity"
                        min="1"
                        readonly
                        placeholder="Calculated from total benches"
                    >

                </div>

            </div>

            <!-- FLOOR -->

            <div class="form-group">

                <label>
                    Floor No
                    <span>*</span>
                </label>

                <div class="input-box">

                    <i class="fa-solid fa-layer-group"></i>

                    <input
                        type="text"
                        name="floor_no"
                        id="edit_floor_no"
                        placeholder="Enter floor (e.g. Ground Floor, GF, 1st Floor)"
                        maxlength="50"
                        required
                    >

                </div>

            </div>

            <!-- BUILDING -->

            <div class="form-group">

                <label>
                    Building
                    <span>*</span>
                </label>

                <div class="input-box">

                    <i class="fa-solid fa-building"></i>

                    <input
                        type="text"
                        name="building_name"
                        id="edit_building_name"
                        placeholder="Enter Building name"
                        maxlength="200"
                        required
                    >

                </div>

            </div>

            <!-- ACTIONS -->

            <div class="modal-actions">

                <button
                    type="button"
                    id="cancelEditHallModal"
                    class="cancel-btn"
                >

                    Cancel

                </button>

                <button
                    type="submit"
                    name="edit_hall"
                    class="save-hall-btn"
                >

                    <i class="fa-solid fa-pen"></i>

                    Update Hall

                </button>

            </div>

        </form>

    </div>

</div>

<style>
.column-benches-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 10px;
    margin-top: 8px;
}
.column-bench-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    border: 1px solid rgba(148, 163, 184, .35);
    border-radius: 10px;
    background: rgba(248, 250, 252, .7);
}
.column-bench-label {
    font-weight: 600;
    white-space: nowrap;
}
.column-bench-item input {
    width: 70px;
    padding: 8px;
    border: 1px solid rgba(148, 163, 184, .5);
    border-radius: 7px;
}
.column-bench-unit {
    font-size: 12px;
    opacity: .75;
}
.form-help {
    display: block;
    margin-top: 7px;
    font-size: 12px;
    opacity: .75;
}
</style>

<!-- =====================================================
     JAVASCRIPT
===================================================== -->

<script>

/* =========================================================
   SIDEBAR
========================================================= */

const menuBtn = document.getElementById("menuBtn");
const sidebar = document.getElementById("sidebar");
const overlay = document.getElementById("overlay");
const closeSidebar = document.getElementById("closeSidebar");

function openSidebar() {

    if (sidebar) {
        sidebar.classList.add("show");
    }

    if (overlay) {
        overlay.classList.add("show");
    }

    document.body.classList.add("sidebar-open");
}

function hideSidebar() {

    if (sidebar) {
        sidebar.classList.remove("show");
    }

    if (overlay) {
        overlay.classList.remove("show");
    }

    document.body.classList.remove("sidebar-open");
}

if (menuBtn) {
    menuBtn.addEventListener("click", openSidebar);
}

if (closeSidebar) {
    closeSidebar.addEventListener("click", hideSidebar);
}


if (overlay) {
    overlay.addEventListener("click", hideSidebar);
}

/* =========================================================
   CLOSE SIDEBAR WHEN CLICKING MAIN CONTENT
========================================================= */

document.addEventListener("click", function(event) {

    if (!sidebar || !sidebar.classList.contains("show")) {
        return;
    }

    /* Keep sidebar open when clicking inside sidebar */
    if (sidebar.contains(event.target)) {
        return;
    }

    /* Keep sidebar open when clicking menu button */
    if (menuBtn && menuBtn.contains(event.target)) {
        return;
    }

    /* Close when clicking main content */
    hideSidebar();

});
/* =========================================================
   ADD HALL MODAL
========================================================= */

const hallModal = document.getElementById("hallModal");
const openHallModal = document.getElementById("openHallModal");
const emptyAddHall = document.getElementById("emptyAddHall");
const closeHallModal = document.getElementById("closeHallModal");
const cancelHallModal = document.getElementById("cancelHallModal");

const addHallForm = document.querySelector(
    "#hallModal .hall-form"
);


/* =========================================================
   CLEAR ADD HALL FORM
   This prevents previous entered text from appearing.
========================================================= */

function clearAddHallForm() {

    if (!addHallForm) {
        return;
    }

    addHallForm.reset();

    const addColumnContainer = document.getElementById("columnBenchesContainer");
    if (addColumnContainer) addColumnContainer.innerHTML = "";

    const inputs = addHallForm.querySelectorAll(
        "input[type='text'], input[type='number']"
    );

    inputs.forEach(function(input) {
        input.value = "";
    });
}


/* =========================================================
   SHOW ADD HALL MODAL
========================================================= */

function showHallModal() {

    if (!hallModal) {
        return;
    }

    /* Always start with empty fields */
    clearAddHallForm();

    hallModal.classList.add("show");

    document.body.classList.add("modal-open");

    /* Focus Hall Name */
    setTimeout(function() {

        const hallNameInput = hallModal.querySelector(
            'input[name="hall_name"]'
        );

        if (hallNameInput) {
            hallNameInput.focus();
        }

    }, 100);
}


/* =========================================================
   HIDE ADD HALL MODAL
========================================================= */

function hideHallModal() {

    if (!hallModal) {
        return;
    }

    /* Clear fields when closing */
    clearAddHallForm();

    hallModal.classList.remove("show");

    document.body.classList.remove("modal-open");
}


/* =========================================================
   OPEN ADD HALL
========================================================= */

if (openHallModal) {

    openHallModal.addEventListener(
        "click",
        showHallModal
    );
}


/* =========================================================
   OPEN FROM EMPTY STATE
========================================================= */

if (emptyAddHall) {

    emptyAddHall.addEventListener(
        "click",
        showHallModal
    );
}


/* =========================================================
   CLOSE ADD HALL
========================================================= */

if (closeHallModal) {

    closeHallModal.addEventListener(
        "click",
        hideHallModal
    );
}

if (cancelHallModal) {

    cancelHallModal.addEventListener(
        "click",
        hideHallModal
    );
}


/* =========================================================
   CLICK OUTSIDE ADD MODAL
========================================================= */

if (hallModal) {

    hallModal.addEventListener(
        "click",
        function(event) {

            if (event.target === hallModal) {
                hideHallModal();
            }

        }
    );
}


/* =========================================================
   EDIT HALL MODAL
========================================================= */

const editHallModal =
    document.getElementById("editHallModal");

const closeEditHallModal =
    document.getElementById("closeEditHallModal");

const cancelEditHallModal =
    document.getElementById("cancelEditHallModal");

const editHallButtons =
    document.querySelectorAll(".edit-hall-btn");

const editHallId =
    document.getElementById("edit_hall_id");

const editHallName =
    document.getElementById("edit_hall_name");

const editHallCode =
    document.getElementById("edit_hall_code");

const editColumnsCount =
    document.getElementById("edit_columns_count");

const editCapacity =
    document.getElementById("edit_capacity");

const editFloorNo =
    document.getElementById("edit_floor_no");

const editBuildingName =
    document.getElementById("edit_building_name");


/* =========================================================
   SHOW EDIT HALL MODAL
========================================================= */

function renderColumnBenchInputs(container, columns, values) {
    if (!container) return;
    columns = Math.max(0, parseInt(columns || 0));
    values = Array.isArray(values) ? values : [];
    container.innerHTML = "";

    for (let i = 0; i < columns; i++) {
        const group = document.createElement("div");
        group.className = "column-bench-item";
        group.innerHTML = `
            <span class="column-bench-label">Column ${i + 1}</span>
            <input type="number" name="column_benches[]" min="1" max="100" value="${parseInt(values[i] || 1)}" required>
            <span class="column-bench-unit">benches</span>
        `;
        container.appendChild(group);
    }

    container.querySelectorAll("input").forEach(function(input) {
        input.addEventListener("input", updateCapacity);
        input.addEventListener("input", updateEditCapacity);
    });
}

function getColumnBenchValues(container) {
    if (!container) return [];
    return Array.from(container.querySelectorAll("input")).map(function(input) {
        return Math.max(1, parseInt(input.value || 1));
    });
}

function updateCapacity() {
    const container = document.getElementById("columnBenchesContainer");
    const values = getColumnBenchValues(container);
    const capacityDisplay = document.getElementById("capacity_display");
    const total = values.reduce((sum, value) => sum + value, 0);
    if (capacityDisplay) capacityDisplay.value = total > 0 ? total * 2 : "";
}

function updateEditCapacity() {
    const container = document.getElementById("editColumnBenchesContainer");
    const values = getColumnBenchValues(container);
    if (editCapacity) {
        const total = values.reduce((sum, value) => sum + value, 0);
        editCapacity.value = total > 0 ? total * 2 : "";
    }
}

const columnsInput =
    document.getElementById("columns_count");

if (columnsInput) {
    columnsInput.addEventListener("input", function() {
        renderColumnBenchInputs(document.getElementById("columnBenchesContainer"), columnsInput.value, getColumnBenchValues(document.getElementById("columnBenchesContainer")));
        updateCapacity();
    });
}

if (editColumnsCount) {
    editColumnsCount.addEventListener("input", function() {
        renderColumnBenchInputs(
            document.getElementById("editColumnBenchesContainer"),
            editColumnsCount.value,
            getColumnBenchValues(document.getElementById("editColumnBenchesContainer"))
        );
        updateEditCapacity();
    });
}

function showEditHallModal(button) {

    if (!editHallModal || !button) {
        return;
    }

    editHallId.value =
        button.dataset.id || "";

    editHallName.value =
        button.dataset.name || "";

    editHallCode.value =
        button.dataset.code || "";


    editColumnsCount.value =
        button.dataset.columns || "";

    let savedColumnBenches = [];
    try {
        savedColumnBenches = JSON.parse(button.dataset.columnBenches || "[]");
    } catch (e) {
        savedColumnBenches = [];
    }
    renderColumnBenchInputs(
        document.getElementById("editColumnBenchesContainer"),
        editColumnsCount.value,
        savedColumnBenches
    );

    /* Seat Allocation controls the active seating pattern. */
    if (editCapacity) {
        editCapacity.value = button.dataset.capacity || "";
    }

    editFloorNo.value =
        button.dataset.floor || "";

    editBuildingName.value =
        button.dataset.building || "";

    editHallModal.classList.add("show");

    document.body.classList.add("modal-open");

    setTimeout(function() {

        if (editHallName) {
            editHallName.focus();
        }

    }, 100);
}


/* =========================================================
   HIDE EDIT HALL MODAL
========================================================= */

function hideEditHallModal() {

    if (!editHallModal) {
        return;
    }

    editHallModal.classList.remove("show");

    document.body.classList.remove("modal-open");
}


/* =========================================================
   EDIT BUTTONS
========================================================= */

editHallButtons.forEach(function(button) {

    button.addEventListener(
        "click",
        function() {

            showEditHallModal(button);

        }
    );

});


/* =========================================================
   CLOSE EDIT MODAL
========================================================= */

if (closeEditHallModal) {

    closeEditHallModal.addEventListener(
        "click",
        hideEditHallModal
    );

}

if (cancelEditHallModal) {

    cancelEditHallModal.addEventListener(
        "click",
        hideEditHallModal
    );

}


/* =========================================================
   CLICK OUTSIDE EDIT MODAL
========================================================= */

if (editHallModal) {

    editHallModal.addEventListener(
        "click",
        function(event) {

            if (event.target === editHallModal) {
                hideEditHallModal();
            }

        }
    );

}


/* =========================================================
   VIEW SEAT STATUS
========================================================= */

const viewHallStatus =
    document.getElementById("viewHallStatus");

const hallSeatStatus =
    document.getElementById("hallSeatStatus");

const closeHallStatus =
    document.getElementById("closeHallStatus");


if (viewHallStatus) {

    viewHallStatus.addEventListener(
        "click",
        function() {

            if (hallSeatStatus) {

                hallSeatStatus.classList.add("show");

                hallSeatStatus.scrollIntoView({
                    behavior: "smooth",
                    block: "start"
                });

            }

        }
    );

}


/* =========================================================
   CLOSE SEAT STATUS
========================================================= */

if (closeHallStatus) {

    closeHallStatus.addEventListener(
        "click",
        function() {

            if (hallSeatStatus) {

                hallSeatStatus.classList.remove("show");

            }

        }
    );

}


/* =========================================================
   ESCAPE KEY
========================================================= */

document.addEventListener(
    "keydown",
    function(event) {

        if (event.key === "Escape") {

            hideSidebar();
            hideHallModal();
            hideEditHallModal();

        }

    }
);


/* =========================================================
   PREVENT OLD BROWSER AUTOFILL
========================================================= */

if (addHallForm) {

    addHallForm.setAttribute(
        "autocomplete",
        "off"
    );

    const addInputs =
        addHallForm.querySelectorAll("input");

    addInputs.forEach(function(input) {

        input.setAttribute(
            "autocomplete",
            "off"
        );

    });

}

</script>

</body>

</html>