<?php

/* =========================================================
   ESMS ATTENDANCE MANAGEMENT MODULE
   File: admin/attendance.php
========================================================= */

session_start();

require_once "../config/db_connect.php";

/* =========================================================
   CACHE CONTROL
========================================================= */

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

/* =========================================================
   ADMIN AUTHENTICATION
========================================================= */

if (!isset($_SESSION["admin"])) {
    header("Location: ../auth/login.php");
    exit();
}

/* =========================================================
   HELPER
========================================================= */

function clean($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

/* =========================================================
   VARIABLES
========================================================= */

$mode = $_GET["mode"] ?? "home";
$examId = (int)($_GET["exam_id"] ?? 0);
$date = $_GET["date"] ?? date("Y-m-d");

$message = "";
$error = "";

/* Flash message after redirect */
if (isset($_SESSION["attendance_flash"])) {
    $message = (string)$_SESSION["attendance_flash"];
    unset($_SESSION["attendance_flash"]);
}

$exams = [];
$exam = null;
$students = [];

$presentCount = 0;
$absentCount = 0;
$totalStudents = 0;

/* =========================================================
   DYNAMIC PAGE INFORMATION
========================================================= */

$pageTitle = "Attendance";
$pageDescription = "Manage examination student attendance.";

switch ($mode) {

    case "mark":

        $pageTitle = "Mark Attendance";
        $pageDescription = "Select an examination date and mark attendance.";

        break;

    case "view":

        $pageTitle = "View Marked Attendance";
        $pageDescription = "View examinations with saved attendance.";

        break;

    case "students":

        $pageTitle = "Mark Students";
        $pageDescription = "Mark students as Present or Absent.";

        break;

    case "edit":

        $pageTitle = "Edit Attendance";
        $pageDescription = "Edit student attendance as Present or Absent.";

        break;

    case "details":

        $pageTitle = "Attendance Details";
        $pageDescription = "View saved attendance details.";

        break;

    default:

        $pageTitle = "Attendance";
        $pageDescription = "Manage examination student attendance.";

        break;
}

/* =========================================================
   DELETE MARKED ATTENDANCE
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["delete_attendance"])
) {

    $deleteExamId = (int)($_POST["exam_id"] ?? 0);

    if ($deleteExamId > 0) {

        $delete = mysqli_prepare(
            $conn,
            "DELETE FROM attendance WHERE exam_id = ?"
        );

        if ($delete) {

            mysqli_stmt_bind_param(
                $delete,
                "i",
                $deleteExamId
            );

            if (mysqli_stmt_execute($delete)) {

                $message = "Attendance deleted successfully.";

                $mode = "view";

                $pageTitle = "View Marked Attendance";
                $pageDescription =
                    "View examinations with saved attendance.";

            } else {

                $error = "Unable to delete attendance.";
            }

            mysqli_stmt_close($delete);

        } else {

            $error = "Unable to delete attendance.";
        }

    } else {

        $error = "Invalid examination selected.";
    }
}


/* =========================================================
   SAVE / UPDATE ATTENDANCE
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["save_attendance"])
) {

    $examId = (int)($_POST["exam_id"] ?? 0);
    $statuses = $_POST["status"] ?? [];
    $attendanceMode = $_POST["attendance_mode"] ?? "students";
    $attendanceDate = trim((string)($_POST["attendance_date"] ?? ""));

    if ($examId <= 0) {

        $error = "Invalid examination selected.";

    } else {

        mysqli_begin_transaction($conn);

        try {

            /* ---------------------------------------------
               REPLACE EXISTING ATTENDANCE FOR THIS EXAM
            --------------------------------------------- */

            $delete = mysqli_prepare(
                $conn,
                "DELETE FROM attendance WHERE exam_id = ?"
            );

            if (!$delete) {
                throw new Exception("Delete preparation failed.");
            }

            mysqli_stmt_bind_param(
                $delete,
                "i",
                $examId
            );

            mysqli_stmt_execute($delete);
            mysqli_stmt_close($delete);


            /* ---------------------------------------------
               INSERT CURRENT ATTENDANCE VALUES
            --------------------------------------------- */

            $insert = mysqli_prepare(
                $conn,
                "
                INSERT INTO attendance
                (
                    exam_id,
                    student_id,
                    status,
                    marked_at
                )
                VALUES (?, ?, ?, NOW())
                "
            );

            if (!$insert) {
                throw new Exception("Insert preparation failed.");
            }

            foreach ($statuses as $studentId => $status) {

                $studentId = (int)$studentId;

                if ($studentId <= 0) {
                    continue;
                }

                $status = ($status === "Absent")
                    ? "Absent"
                    : "Present";

                mysqli_stmt_bind_param(
                    $insert,
                    "iis",
                    $examId,
                    $studentId,
                    $status
                );

                mysqli_stmt_execute($insert);
            }

            mysqli_stmt_close($insert);

            mysqli_commit($conn);

            /* ---------------------------------------------
               REDIRECT AFTER SAVE

               Normal Mark Attendance:
               -> return to Mark Attendance for the same exam date.

               Edit Attendance:
               -> return to View Marked Attendance.
            --------------------------------------------- */

            if ($attendanceMode === "edit") {

                $_SESSION["attendance_flash"] =
                    "Attendance updated successfully.";

                header("Location: attendance.php?mode=view");
                exit();

            } else {

                $_SESSION["attendance_flash"] =
                    "Attendance saved successfully.";

                /*
                 * Normal Mark Attendance save:
                 * return to the Mark Attendance page for the same exam date.
                 */
                if ($attendanceDate === "") {
                    $dateStmt = mysqli_prepare(
                        $conn,
                        "SELECT exam_date FROM examinations WHERE id = ?"
                    );

                    if ($dateStmt) {
                        mysqli_stmt_bind_param(
                            $dateStmt,
                            "i",
                            $examId
                        );

                        mysqli_stmt_execute($dateStmt);
                        $dateResult = mysqli_stmt_get_result($dateStmt);
                        $dateRow = mysqli_fetch_assoc($dateResult);
                        $attendanceDate = (string)($dateRow["exam_date"] ?? "");
                        mysqli_stmt_close($dateStmt);
                    }
                }

                $redirectUrl = "attendance.php?mode=mark";

                if ($attendanceDate !== "") {
                    $redirectUrl .=
                        "&date=" . urlencode($attendanceDate);
                }

                header("Location: " . $redirectUrl);
                exit();
            }

        } catch (Throwable $e) {

            mysqli_rollback($conn);

            $error = "Unable to save attendance.";
        }
    }
}

/* =========================================================
   MARK ATTENDANCE
   ---------------------------------------------------------
   DATE + SEARCH
========================================================= */

if ($mode === "mark") {

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT
            e.*,
            ay.year_name
        FROM examinations e
        LEFT JOIN academic_years ay
            ON ay.id = e.academic_year_id
        WHERE e.exam_date = ?
        ORDER BY
            e.exam_time,
            e.subject_name
        "
    );

    if ($stmt) {

        mysqli_stmt_bind_param(
            $stmt,
            "s",
            $date
        );

        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        while ($row = mysqli_fetch_assoc($result)) {
            $exams[] = $row;
        }

        mysqli_stmt_close($stmt);

    } else {

        $error = "Unable to load examinations.";
    }
}

/* =========================================================
   VIEW MARKED ATTENDANCE
   ---------------------------------------------------------
   NO DATE
   NO SEARCH
========================================================= */

elseif ($mode === "view") {

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT
            e.*,
            ay.year_name
        FROM examinations e
        LEFT JOIN academic_years ay
            ON ay.id = e.academic_year_id
        WHERE EXISTS (
            SELECT 1
            FROM attendance a
            WHERE a.exam_id = e.id
        )
        ORDER BY
            e.exam_date DESC,
            e.exam_time,
            e.subject_name
        "
    );

    if ($stmt) {

        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        while ($row = mysqli_fetch_assoc($result)) {
            $exams[] = $row;
        }

        mysqli_stmt_close($stmt);

    } else {

        $error = "Unable to load marked attendance.";
    }
}

/* =========================================================
   LOAD EXAMINATION
========================================================= */

if (
    $mode === "students"
    || $mode === "edit"
    || $mode === "details"
) {

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT
            e.*,
            ay.year_name
        FROM examinations e
        LEFT JOIN academic_years ay
            ON ay.id = e.academic_year_id
        WHERE e.id = ?
        "
    );

    if ($stmt) {

        mysqli_stmt_bind_param(
            $stmt,
            "i",
            $examId
        );

        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        $exam = mysqli_fetch_assoc($result);

        mysqli_stmt_close($stmt);

    } else {

        $error = "Unable to load examination.";
    }


    /* =====================================================
       LOAD STUDENTS
    ===================================================== */

    if ($exam) {

        $departmentId =
            (int)$exam["department_id"];

        $academicYear =
            (int)$exam["academic_year_id"];

        $semesterId =
            (int)$exam["semester_id"];


        $stmt = mysqli_prepare(
            $conn,
            "
            SELECT
                s.id,
                s.pin_no,
                s.student_name,
                COALESCE(a.status, '') AS attendance_status
            FROM students s
            LEFT JOIN attendance a
                ON a.student_id = s.id
                AND a.exam_id = ?
            WHERE
                s.department_id = ?
                AND s.academic_year = ?
                AND s.semester_id = ?
            ORDER BY
                s.pin_no
            "
        );

        if ($stmt) {

            mysqli_stmt_bind_param(
                $stmt,
                "iiii",
                $examId,
                $departmentId,
                $academicYear,
                $semesterId
            );

            mysqli_stmt_execute($stmt);

            $result = mysqli_stmt_get_result($stmt);

            while ($row = mysqli_fetch_assoc($result)) {
                $students[] = $row;
            }

            mysqli_stmt_close($stmt);

        } else {

            $error = "Unable to load students.";
        }
    }


    /* =====================================================
       COUNTS
    ===================================================== */

    foreach ($students as $student) {

        if ($student["attendance_status"] === "Present") {
            $presentCount++;
        }

        if ($student["attendance_status"] === "Absent") {
            $absentCount++;
        }
    }

    $totalStudents = count($students);
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
        <?= clean($pageTitle) ?> | ESMS
    </title>


    <link
        rel="stylesheet"
        href="../assets/css/dashboard.css"
    >

    <link
        rel="stylesheet"
        href="../assets/css/attendance.css"
    >


    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"
    >


    <style>
        .mark-all-present-wrapper {
            display: flex;
            justify-content: flex-end;
            margin: 0 0 15px;
        }

        .mark-all-present-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
    </style>

</head>


<body>


<!-- =======================================================
     OVERLAY
======================================================= -->

<div
    class="overlay"
    id="overlay"
></div>


<!-- =======================================================
     SIDEBAR
======================================================= -->

<div
    class="sidebar"
    id="sidebar"
>


    <div class="sidebar-header">

        <h2>ESMS</h2>

        <button
            type="button"
            id="closeSidebar"
            aria-label="Close Menu"
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


        <li>

            <a href="seat_allocation.php">

                <i class="fa-solid fa-chair"></i>

                <span>Seat Allocation</span>

            </a>

        </li>


        <li class="active">

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

</div>


<!-- =======================================================
     MAIN CONTENT
======================================================= -->

<div class="main-content">


    <!-- ===================================================
         DYNAMIC TOPBAR
    =================================================== -->

    <header class="topbar attendance-topbar">


        <div class="attendance-header">


            <!-- BREADCRUMB -->

            <div class="breadcrumb">


                <button
                    type="button"
                    class="menu-button"
                    id="menuBtn"
                    title="Open Menu"
                    aria-label="Open Menu"
                >

                    <i class="fa-solid fa-bars"></i>

                </button>


                <a href="dashboard.php">

                    <i class="fa-solid fa-house"></i>

                    Dashboard

                </a>


                <span class="breadcrumb-separator">
                    /
                </span>


                <a href="attendance.php">

                    Attendance

                </a>


                <?php if ($mode !== "home"): ?>

                    <span class="breadcrumb-separator">
                        /
                    </span>

                    <span class="current-page">

                        <?= clean($pageTitle) ?>

                    </span>

                <?php endif; ?>


            </div>


            <!-- =================================================
                 TITLE + RIGHT BACK BUTTON
            ================================================= -->

            <div class="attendance-title-box">


                <div class="dynamic-title-left">

                    <div>

                        <h1>
                            <?= clean($pageTitle) ?>
                        </h1>

                        <p>
                            <?= clean($pageDescription) ?>
                        </p>

                    </div>

                </div>


                <?php
                /*
                 * Use a real page URL instead of javascript:history.back().
                 * This makes Back work correctly even when the page was opened
                 * directly or refreshed.
                 */
                if ($mode === "mark") {

                    $backUrl = "attendance.php";

                } elseif ($mode === "view") {

                    $backUrl = "attendance.php";

                } elseif (
                    ($mode === "students" || $mode === "edit" || $mode === "details")
                    && $exam
                ) {

                    $backUrl =
                        "attendance.php?mode="
                        . (
                            $mode === "students"
                            ? "mark"
                            : "view"
                        );

                    if ($mode === "students") {

                        $backUrl .=
                            "&date="
                            . urlencode(
                                $exam["exam_date"]
                            );
                    }

                } else {

                    $backUrl = "attendance.php";
                }
                ?>

                <?php if ($mode !== "home"): ?>

                    <a
                        href="<?= clean($backUrl) ?>"
                        class="back-button"
                        title="Go Back"
                    >

                        <i class="fa-solid fa-arrow-left"></i>

                        Back

                    </a>

                <?php endif; ?>


            </div>


        </div>


    </header>


    <!-- ===================================================
         CONTENT
    =================================================== -->

    <main class="attendance-content">


        <?php if ($message): ?>

            <div class="alert alert-success">

                <?= clean($message) ?>

            </div>

        <?php endif; ?>


        <?php if ($error): ?>

            <div class="alert alert-error">

                <?= clean($error) ?>

            </div>

        <?php endif; ?>


        <!-- =================================================
             HOME
        ================================================= -->

        <?php if ($mode === "home"): ?>


            <div class="attendance-options">


                <a
                    href="attendance.php?mode=mark"
                    class="attendance-card"
                >

                    <div class="attendance-card-icon">

                        <i class="fa-solid fa-clipboard-check"></i>

                    </div>


                    <div class="attendance-card-content">

                        <span class="attendance-card-label">
                            MARK ATTENDANCE
                        </span>

                        <h3>
                            Mark Attendance
                        </h3>

                        <p>

                            <i class="fa-solid fa-users"></i>

                            Select an examination and mark
                            students as Present or Absent.

                        </p>

                    </div>


                    <i class="fa-solid fa-arrow-right attendance-card-arrow"></i>

                </a>


                <a
                    href="attendance.php?mode=view"
                    class="attendance-card"
                >

                    <div class="attendance-card-icon">

                        <i class="fa-solid fa-eye"></i>

                    </div>


                    <div class="attendance-card-content">

                        <span class="attendance-card-label">
                            VIEW MARKED ATTENDANCE
                        </span>

                        <h3>
                            View Marked Attendance
                        </h3>

                        <p>

                            <i class="fa-solid fa-users"></i>

                            View examinations for which
                            attendance has already been marked.

                        </p>

                    </div>


                    <i class="fa-solid fa-arrow-right attendance-card-arrow"></i>

                </a>


            </div>


        <!-- =================================================
             MARK ATTENDANCE
        ================================================= -->

        <?php elseif ($mode === "mark"): ?>


            <div class="page-heading">

                <h1>
                    Mark Attendance
                </h1>

                <p>
                    Select an examination date.
                </p>

            </div>


            <!-- DATE + SEARCH -->

            <form
                class="date-form"
                method="get"
            >

                <input
                    type="hidden"
                    name="mode"
                    value="mark"
                >


                <div class="date-form-group">

                    <label for="date">
                        Select Date
                    </label>

                    <input
                        type="date"
                        id="date"
                        name="date"
                        value="<?= clean($date) ?>"
                        required
                    >

                </div>


                <button
                    type="submit"
                    class="primary-btn search-attendance-btn"
                >

                    <i class="fa-solid fa-search"></i>

                    Search

                </button>


            </form>


            <div class="table-card">

                <div class="table-responsive">

                    <table class="attendance-table">

                        <thead>

                            <tr>

                                <th>
                                    Subject
                                </th>

                                <th>
                                    Date
                                </th>

                                <th>
                                    Year
                                </th>

                                <th>
                                    Status
                                </th>

                                <th>
                                    Action
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                        <?php if (!$exams): ?>

                            <tr>

                                <td
                                    colspan="5"
                                    class="empty-state"
                                >

                                    No examinations found
                                    for this date.

                                </td>

                            </tr>


                        <?php else: ?>


                            <?php foreach ($exams as $item): ?>

                                <?php

                                $itemId =
                                    (int)$item["id"];

                                $check = mysqli_prepare(
                                    $conn,
                                    "
                                    SELECT COUNT(*) AS total
                                    FROM attendance
                                    WHERE exam_id = ?
                                    "
                                );

                                $marked = false;

                                if ($check) {

                                    mysqli_stmt_bind_param(
                                        $check,
                                        "i",
                                        $itemId
                                    );

                                    mysqli_stmt_execute($check);

                                    $checkResult =
                                        mysqli_stmt_get_result($check);

                                    $checkRow =
                                        mysqli_fetch_assoc(
                                            $checkResult
                                        );

                                    $marked =
                                        ((int)$checkRow["total"] > 0);

                                    mysqli_stmt_close($check);
                                }

                                ?>


                                <tr>


                                    <td>
                                        <?= clean(
                                            $item["subject_name"]
                                        ) ?>
                                    </td>


                                    <td>
                                        <?= clean(
                                            $item["exam_date"]
                                        ) ?>
                                    </td>


                                    <td>
                                        <?= clean(
                                            $item["year_name"]
                                            ?? $item["academic_year_id"]
                                        ) ?>
                                    </td>


                                    <td>

                                        <?php if ($marked): ?>

                                            <span class="attendance-status marked">
                                                Marked
                                            </span>

                                        <?php else: ?>

                                            <span class="attendance-status unmarked">
                                                Unmarked
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <td>

                                        <a
                                            href="attendance.php?mode=students&exam_id=<?= $itemId ?>"
                                            class="view-button"
                                        >

                                            <i class="fa-solid fa-eye"></i>

                                            View

                                        </a>

                                    </td>


                                </tr>


                            <?php endforeach; ?>


                        <?php endif; ?>


                        </tbody>

                    </table>

                </div>

            </div>


        <!-- =================================================
             VIEW MARKED ATTENDANCE
        ================================================= -->

        <?php elseif ($mode === "view"): ?>


            <div class="page-heading">

                <h1>
                    View Marked Attendance
                </h1>

                <p>
                    Attendance records that have already been saved.
                </p>

            </div>


            <div class="table-card">

                <div class="table-responsive">

                    <table class="attendance-table">

                        <thead>

                            <tr>

                                <th>
                                    Subject
                                </th>

                                <th>
                                    Date
                                </th>

                                <th>
                                    Year
                                </th>

                                <th>
                                    Status
                                </th>

                                <th>
                                    Action
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                        <?php if (!$exams): ?>

                            <tr>

                                <td
                                    colspan="5"
                                    class="empty-state"
                                >

                                    No marked attendance records found.

                                </td>

                            </tr>


                        <?php else: ?>


                            <?php foreach ($exams as $item): ?>

                                <?php
                                $itemId =
                                    (int)$item["id"];
                                ?>


                                <tr>


                                    <td>
                                        <?= clean(
                                            $item["subject_name"]
                                        ) ?>
                                    </td>


                                    <td>
                                        <?= clean(
                                            $item["exam_date"]
                                        ) ?>
                                    </td>


                                    <td>
                                        <?= clean(
                                            $item["year_name"]
                                            ?? $item["academic_year_id"]
                                        ) ?>
                                    </td>


                                    <td>

                                        <span class="attendance-status marked">
                                            Marked
                                        </span>

                                    </td>


                                    <td class="attendance-actions">

                                        <a
                                            href="attendance.php?mode=details&exam_id=<?= $itemId ?>"
                                            class="view-button"
                                        >

                                            <i class="fa-solid fa-eye"></i>

                                            View

                                        </a>

                                        <a
                                            href="attendance.php?mode=edit&exam_id=<?= $itemId ?>"
                                            class="edit-attendance-btn"
                                            title="Edit Attendance"
                                        >

                                            <i class="fa-solid fa-pen-to-square"></i>

                                            Edit

                                        </a>

                                        <form
                                            method="post"
                                            class="delete-attendance-form"
                                            onsubmit="return confirm('Are you sure you want to delete the marked attendance for this examination?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="exam_id"
                                                value="<?= $itemId ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="delete_attendance"
                                                value="1"
                                            >

                                            <button
                                                type="submit"
                                                class="delete-attendance-btn"
                                                title="Delete Attendance"
                                                aria-label="Delete Attendance"
                                            >

                                                <i class="fa-solid fa-trash"></i>

                                            </button>

                                        </form>

                                    </td>


                                </tr>


                            <?php endforeach; ?>


                        <?php endif; ?>


                        </tbody>

                    </table>

                </div>

            </div>


        <!-- =================================================
             MARK STUDENTS
        ================================================= -->

        <?php elseif (($mode === "students" || $mode === "edit") && $exam): ?>


            <div class="page-heading">

                <h1>
                    <?= $mode === "edit"
                        ? "Edit Attendance - "
                        : "" ?>
                    <?= clean($exam["subject_name"]) ?>
                </h1>

                <p>
                    <?= $mode === "edit"
                        ? "Edit each student's attendance as Present or Absent."
                        : "Mark each student as Present or Absent." ?>
                </p>

            </div>

            <!-- MARK ALL PRESENT BUTTON -->
            <div class="mark-all-present-wrapper">
                <button
                    type="button"
                    class="primary-btn mark-all-present-btn"
                    id="markAllPresentBtn"
                >
                    <i class="fa-solid fa-user-check"></i>
                    Mark All Present
                </button>
            </div>


            <form method="post">


                <input
                    type="hidden"
                    name="exam_id"
                    value="<?= $examId ?>"
                >

                <input
                    type="hidden"
                    name="attendance_mode"
                    value="<?= $mode === "edit" ? "edit" : "students" ?>"
                >

                <input
                    type="hidden"
                    name="attendance_date"
                    value="<?= clean($exam["exam_date"] ?? "") ?>"
                >

                <input
                    type="hidden"
                    name="save_attendance"
                    value="1"
                >


                <div class="table-card">

                    <div class="table-responsive">

                        <table class="attendance-table">

                            <thead>

                                <tr>

                                    <th>
                                        PIN Number
                                    </th>

                                    <th>
                                        Student Name
                                    </th>

                                    <th>
                                        Attendance
                                    </th>

                                </tr>

                            </thead>


                            <tbody>


                            <?php if (!$students): ?>

                                <tr>

                                    <td
                                        colspan="3"
                                        class="empty-state"
                                    >

                                        No students found for
                                        this examination.

                                    </td>

                                </tr>


                            <?php else: ?>


                                <?php foreach ($students as $student): ?>


                                    <tr>


                                        <td>

                                            <?= clean(
                                                $student["pin_no"]
                                            ) ?>

                                        </td>


                                        <td>

                                            <?= clean(
                                                $student["student_name"]
                                            ) ?>

                                        </td>


                                        <td class="attendance-controls">


                                            <label class="present-option">

                                                <input
                                                    type="radio"
                                                    name="status[<?= (int)$student["id"] ?>]"
                                                    value="Present"

                                                    <?= $student["attendance_status"] === "Present"
                                                        ? "checked"
                                                        : "" ?>
                                                >

                                                <span>
                                                    Present
                                                </span>

                                            </label>


                                            <label class="absent-option">

                                                <input
                                                    type="radio"
                                                    name="status[<?= (int)$student["id"] ?>]"
                                                    value="Absent"

                                                    <?= $student["attendance_status"] === "Absent"
                                                        ? "checked"
                                                        : "" ?>
                                                >

                                                <span>
                                                    Absent
                                                </span>

                                            </label>


                                        </td>


                                    </tr>


                                <?php endforeach; ?>


                            <?php endif; ?>


                            </tbody>

                        </table>

                    </div>

                </div>


                <button
                    type="submit"
                    class="primary-btn save-btn"
                >

                    <i class="fa-solid fa-save"></i>

                    <?= $mode === "edit"
                        ? "Update Attendance"
                        : "Save Attendance" ?>

                </button>


            </form>


        <!-- =================================================
             ATTENDANCE DETAILS
        ================================================= -->

        <?php elseif ($mode === "details" && $exam): ?>


            <div class="page-heading">

                <h1>
                    <?= clean($exam["subject_name"]) ?>
                </h1>

                <p>
                    Saved attendance summary and student details.
                </p>

            </div>


            <!-- =================================================
                 SUMMARY BOXES
            ================================================= -->

            <div class="attendance-summary">


                <!-- PRESENT -->

                <div class="attendance-summary-box present-summary">

                    <div class="summary-icon">

                        <i class="fa-solid fa-user-check"></i>

                    </div>


                    <div class="summary-content">

                        <span>
                            Present
                        </span>

                        <strong>
                            <?= $presentCount ?>
                        </strong>

                    </div>

                </div>


                <!-- ABSENT -->

                <div class="attendance-summary-box absent-summary">

                    <div class="summary-icon">

                        <i class="fa-solid fa-user-xmark"></i>

                    </div>


                    <div class="summary-content">

                        <span>
                            Absent
                        </span>

                        <strong>
                            <?= $absentCount ?>
                        </strong>

                    </div>

                </div>


                <!-- TOTAL -->

                <div class="attendance-summary-box total-summary">

                    <div class="summary-icon">

                        <i class="fa-solid fa-users"></i>

                    </div>


                    <div class="summary-content">

                        <span>
                            Total Students
                        </span>

                        <strong>
                            <?= $totalStudents ?>
                        </strong>

                    </div>

                </div>


            </div>


            <!-- =================================================
                 STUDENT TABLE
            ================================================= -->

            <div class="table-card">

                <div class="table-responsive">

                    <table class="attendance-table">

                        <thead>

                            <tr>

                                <th>
                                    PIN Number
                                </th>

                                <th>
                                    Student Name
                                </th>

                                <th>
                                    Status
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                        <?php if (!$students): ?>

                            <tr>

                                <td
                                    colspan="3"
                                    class="empty-state"
                                >

                                    No attendance details found.

                                </td>

                            </tr>


                        <?php else: ?>


                            <?php foreach ($students as $student): ?>


                                <tr>


                                    <td>

                                        <?= clean(
                                            $student["pin_no"]
                                        ) ?>

                                    </td>


                                    <td>

                                        <?= clean(
                                            $student["student_name"]
                                        ) ?>

                                    </td>


                                    <td>


                                        <?php if (
                                            $student["attendance_status"]
                                            === "Present"
                                        ): ?>

                                            <span class="status-badge present">

                                                Present

                                            </span>


                                        <?php elseif (
                                            $student["attendance_status"]
                                            === "Absent"
                                        ): ?>

                                            <span class="status-badge absent">

                                                Absent

                                            </span>


                                        <?php else: ?>

                                            <span class="attendance-status unmarked">

                                                Unmarked

                                            </span>

                                        <?php endif; ?>


                                    </td>


                                </tr>


                            <?php endforeach; ?>


                        <?php endif; ?>


                        </tbody>

                    </table>

                </div>

            </div>


        <?php else: ?>


            <div class="alert alert-error">

                The requested examination was not found.

            </div>


        <?php endif; ?>


    </main>

</div>


<!-- =======================================================
     JAVASCRIPT
======================================================= -->

<script>

document.addEventListener("DOMContentLoaded", function () {

    const sidebar =
        document.getElementById("sidebar");

    const overlay =
        document.getElementById("overlay");

    const menuBtn =
        document.getElementById("menuBtn");

    const closeSidebar =
        document.getElementById("closeSidebar");


    /* =====================================================
       OPEN SIDEBAR
    ===================================================== */

    if (menuBtn) {

        menuBtn.addEventListener(
            "click",
            function () {

                sidebar.classList.add("active");

                overlay.classList.add("active");

            }
        );

    }


    /* =====================================================
       CLOSE SIDEBAR
    ===================================================== */

    if (closeSidebar) {

        closeSidebar.addEventListener(
            "click",
            function () {

                sidebar.classList.remove("active");

                overlay.classList.remove("active");

            }
        );

    }


    /* =====================================================
       OVERLAY
    ===================================================== */

    if (overlay) {

        overlay.addEventListener(
            "click",
            function () {

                sidebar.classList.remove("active");

                overlay.classList.remove("active");

            }
        );

    }


    /* =====================================================
       MARK ALL PRESENT
    ===================================================== */

    const markAllPresentBtn =
        document.getElementById("markAllPresentBtn");

    if (markAllPresentBtn) {

        markAllPresentBtn.addEventListener(
            "click",
            function () {

                const presentRadios =
                    document.querySelectorAll(
                        'input[type="radio"][value="Present"]'
                    );

                presentRadios.forEach(function (radio) {
                    radio.checked = true;
                });

            }
        );

    }


    /* =====================================================
       ESCAPE
    ===================================================== */

    document.addEventListener(
        "keydown",
        function (event) {

            if (event.key === "Escape") {

                sidebar.classList.remove("active");

                overlay.classList.remove("active");

            }

        }
    );

});

</script>


</body>

</html>