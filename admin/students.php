<?php

/* =========================================================
   STUDENTS MANAGEMENT MODULE
   File: admin/students.php
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
   FLASH MESSAGE
========================================================= */

$message = "";
$messageType = "";

if (isset($_SESSION["flash_message"])) {

    $message = $_SESSION["flash_message"];

    $messageType =
        $_SESSION["flash_message_type"] ?? "success";

    unset($_SESSION["flash_message"]);
    unset($_SESSION["flash_message_type"]);
}

/* =========================================================
   READ NOTIFICATIONS
========================================================= */

if (isset($_GET["read_notifications"])) {

    $readNotificationStmt = mysqli_prepare(
        $conn,
        "
        UPDATE notifications
        SET is_read = 1
        WHERE is_read = 0
        "
    );

    if ($readNotificationStmt) {
        mysqli_stmt_execute($readNotificationStmt);
        mysqli_stmt_close($readNotificationStmt);
    }

    exit();
}

/* =========================================================
   PHPSPREADSHEET
========================================================= */

require_once "../vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

/* =========================================================
   HELPERS
========================================================= */

/**
 * Escape HTML output.
 */
function clean($value)
{
    return htmlspecialchars(
        $value ?? "",
        ENT_QUOTES,
        "UTF-8"
    );
}

/**
 * Normalize PIN / Register Number.
 *
 * Example:
 *  123abc1
 *  becomes
 *  123-ABC-001
 */
function normalizePin($pin)
{
    $pin = strtoupper(trim((string)$pin));

    $pin = preg_replace(
        "/[^A-Z0-9]/",
        "",
        $pin
    );

    if (
        preg_match(
            "/^(\d+)([A-Z]+)(\d+)$/",
            $pin,
            $matches
        )
    ) {

        return
            $matches[1] .
            "-" .
            $matches[2] .
            "-" .
            str_pad(
                $matches[3],
                3,
                "0",
                STR_PAD_LEFT
            );
    }

    return $pin;
}

/**
 * Convert semester number to academic year number.
 *
 * 1,2 = 1st Year
 * 3,4 = 2nd Year
 * 5,6 = 3rd Year
 * 7,8 = 4th Year
 */
function getYearNumberFromSemester($semesterNumber)
{
    $semesterNumber = (int)$semesterNumber;

    if ($semesterNumber <= 2) {
        return 1;
    }

    if ($semesterNumber <= 4) {
        return 2;
    }

    if ($semesterNumber <= 6) {
        return 3;
    }

    return 4;
}

/**
 * Convert academic year name to number.
 */
function getAcademicYearNumber($yearName)
{
    $yearName = strtolower(
        trim((string)$yearName)
    );

    $yearName = preg_replace(
        "/\s+/",
        " ",
        $yearName
    );

    $yearMap = [

        "1st year"  => 1,
        "1styear"   => 1,

        "2nd year"  => 2,
        "2ndyear"   => 2,

        "3rd year"  => 3,
        "3rdyear"   => 3,

        "4th year"  => 4,
        "4thyear"   => 4
    ];

    return $yearMap[$yearName] ?? 0;
}

/**
 * Create notification.
 */
function createNotification(
    $conn,
    $title,
    $message,
    $type
) {

    $stmt = $conn->prepare("
        INSERT INTO notifications
        (
            title,
            message,
            type,
            is_read
        )
        VALUES
        (
            ?,
            ?,
            ?,
            0
        )
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "sss",
        $title,
        $message,
        $type
    );

    $success = $stmt->execute();

    $stmt->close();

    return $success;
}

/**
 * Redirect back to student page.
 */
function redirectToStudents(
    $departmentId = 0,
    $yearId = 0,
    $semesterId = 0
) {

    $url = "students.php";

    $params = [];

    if ((int)$departmentId > 0) {
        $params["department"] =
            (int)$departmentId;
    }

    if ((int)$yearId > 0) {
        $params["year"] =
            (int)$yearId;
    }

    if ((int)$semesterId > 0) {
        $params["semester"] =
            (int)$semesterId;
    }

    if (!empty($params)) {
        $url .= "?" . http_build_query($params);
    }

    header(
        "Location: " . $url
    );

    exit();
}

/* =========================================================
   GET FILTER VALUES
========================================================= */

$departmentId = isset($_GET["department"])
    ? (int)$_GET["department"]
    : 0;

$yearId = isset($_GET["year"])
    ? (int)$_GET["year"]
    : 0;

$semesterId = isset($_GET["semester"])
    ? (int)$_GET["semester"]
    : 0;

$search = isset($_GET["search"])
    ? trim($_GET["search"])
    : "";


/* =========================================================
   EXPORT STUDENTS TO EXCEL
========================================================= */

if (
    isset($_GET["export"]) &&
    $_GET["export"] === "excel" &&
    $departmentId > 0 &&
    $yearId > 0
) {

    /* =====================================================
       GET DEPARTMENT
    ===================================================== */

    $departmentStmt = $conn->prepare("
        SELECT
            department_name,
            department_code
        FROM departments
        WHERE id = ?
        LIMIT 1
    ");

    if (!$departmentStmt) {
        die(
            "Department query failed: " .
            $conn->error
        );
    }

    $departmentStmt->bind_param(
        "i",
        $departmentId
    );

    $departmentStmt->execute();

    $department =
        $departmentStmt
            ->get_result()
            ->fetch_assoc();

    $departmentStmt->close();

    if (!$department) {
        die("Invalid department selected.");
    }


    /* =====================================================
       GET ACADEMIC YEAR
    ===================================================== */

    $yearStmt = $conn->prepare("
        SELECT
            id,
            year_name
        FROM academic_years
        WHERE id = ?
        LIMIT 1
    ");

    if (!$yearStmt) {
        die(
            "Academic year query failed: " .
            $conn->error
        );
    }

    $yearStmt->bind_param(
        "i",
        $yearId
    );

    $yearStmt->execute();

    $selectedYear =
        $yearStmt
            ->get_result()
            ->fetch_assoc();

    $yearStmt->close();

    if (!$selectedYear) {
        die("Invalid academic year selected.");
    }


    /* =====================================================
       VERIFY SEMESTER IF SELECTED
    ===================================================== */

    $selectedSemester = null;

    if ($semesterId > 0) {

        $semesterStmt = $conn->prepare("
            SELECT
                id,
                semester_name,
                semester_number
            FROM semesters
            WHERE id = ?
              AND status = 1
            LIMIT 1
        ");

        if (!$semesterStmt) {
            die(
                "Semester query failed: " .
                $conn->error
            );
        }

        $semesterStmt->bind_param(
            "i",
            $semesterId
        );

        $semesterStmt->execute();

        $selectedSemester =
            $semesterStmt
                ->get_result()
                ->fetch_assoc();

        $semesterStmt->close();

        if (!$selectedSemester) {
            die("Invalid semester selected.");
        }
    }


    /* =====================================================
       GET STUDENTS
       Department + Academic Year
       + Optional Semester
    ===================================================== */

    $sql = "
        SELECT

            s.pin_no,
            s.student_name,

            ay.year_name,

            sem.semester_name,
            sem.semester_number,

            s.contact_no

        FROM students s

        INNER JOIN academic_years ay
            ON ay.id = s.academic_year

        LEFT JOIN semesters sem
            ON sem.id = s.semester_id

        WHERE
            s.department_id = ?
            AND s.academic_year = ?
    ";

    if ($semesterId > 0) {

        $sql .= "
            AND s.semester_id = ?
        ";
    }

    $sql .= "
        ORDER BY
            sem.semester_number ASC,
            s.pin_no ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die(
            "Student export query failed: " .
            $conn->error
        );
    }

    if ($semesterId > 0) {

        $stmt->bind_param(
            "iii",
            $departmentId,
            $yearId,
            $semesterId
        );

    } else {

        $stmt->bind_param(
            "ii",
            $departmentId,
            $yearId
        );
    }

    $stmt->execute();

    $result =
        $stmt->get_result();


    /* =====================================================
       CREATE EXCEL
    ===================================================== */

    $spreadsheet =
        new Spreadsheet();

    $sheet =
        $spreadsheet
            ->getActiveSheet();

    $sheet->setTitle("Students");


    /* =====================================================
       HEADERS
    ===================================================== */

    $sheet->setCellValue(
        "A1",
        "S.No"
    );

    $sheet->setCellValue(
        "B1",
        "PIN No"
    );

    $sheet->setCellValue(
        "C1",
        "Name"
    );

    $sheet->setCellValue(
        "D1",
        "Academic Year"
    );

    $sheet->setCellValue(
        "E1",
        "Semester"
    );

    $sheet->setCellValue(
        "F1",
        "Contact No"
    );


    /* =====================================================
       DATA
    ===================================================== */

    $rowNumber = 2;
    $serial = 1;

    while (
        $row =
        $result->fetch_assoc()
    ) {

        $sheet->setCellValue(
            "A" . $rowNumber,
            $serial++
        );

        $sheet->setCellValue(
            "B" . $rowNumber,
            normalizePin(
                $row["pin_no"]
            )
        );

        $sheet->setCellValue(
            "C" . $rowNumber,
            $row["student_name"]
        );

        $sheet->setCellValue(
            "D" . $rowNumber,
            $row["year_name"]
        );

        $semesterName =
            !empty($row["semester_name"])
            ? $row["semester_name"]
            : "Not Assigned";

        $sheet->setCellValue(
            "E" . $rowNumber,
            $semesterName
        );

        $sheet->setCellValue(
            "F" . $rowNumber,
            $row["contact_no"] ?? ""
        );

        $rowNumber++;
    }

    $stmt->close();


    /* =====================================================
       STYLE HEADER
    ===================================================== */

    $sheet
        ->getStyle("A1:F1")
        ->getFont()
        ->setBold(true);


    /* =====================================================
       AUTO SIZE
    ===================================================== */

    foreach (
        range("A", "F") as $column
    ) {

        $sheet
            ->getColumnDimension($column)
            ->setAutoSize(true);
    }


    /* =====================================================
       FREEZE HEADER
    ===================================================== */

    $sheet->freezePane("A2");


    /* =====================================================
       NOTIFICATION
    ===================================================== */

    $notificationMessage =
        $department["department_name"] .
        " " .
        $selectedYear["year_name"];

    if ($selectedSemester) {

        $notificationMessage .=
            " " .
            $selectedSemester["semester_name"];
    }

    $notificationMessage .=
        " students were exported.";

    createNotification(
        $conn,
        "Students Exported",
        $notificationMessage,
        "student_exported"
    );


    /* =====================================================
       FILE NAME
    ===================================================== */

    $departmentFileName =
        preg_replace(
            "/[^A-Za-z0-9_-]/",
            "_",
            $department["department_name"]
        );

    $yearFileName =
        preg_replace(
            "/[^A-Za-z0-9_-]/",
            "_",
            $selectedYear["year_name"]
        );

    $fileName =
        $departmentFileName .
        "_" .
        $yearFileName;

    if ($selectedSemester) {

        $semesterFileName =
            preg_replace(
                "/[^A-Za-z0-9_-]/",
                "_",
                $selectedSemester["semester_name"]
            );

        $fileName .=
            "_" .
            $semesterFileName;
    }

    $fileName .=
        "_Students.xlsx";


    /* =====================================================
       CLEAN OUTPUT BUFFER
    ===================================================== */

    while (
        ob_get_level() > 0
    ) {

        ob_end_clean();
    }


    /* =====================================================
       DOWNLOAD HEADERS
    ===================================================== */

    header(
        "Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
    );

    header(
        "Content-Disposition: attachment; filename=\"" .
        $fileName .
        "\""
    );

    header(
        "Cache-Control: max-age=0"
    );

    header(
        "Pragma: public"
    );


    /* =====================================================
       WRITE EXCEL
    ===================================================== */

    $writer =
        new Xlsx(
            $spreadsheet
        );

    $writer->save(
        "php://output"
    );

    exit();
}


/* =========================================================
   DELETE STUDENT
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["delete_student"])
) {

    $studentId =
        (int)($_POST["student_id"] ?? 0);

    if ($studentId > 0) {

        /* =================================================
           GET STUDENT DETAILS
        ================================================= */

        $studentStmt = $conn->prepare("
            SELECT
                s.student_name,
                s.pin_no,
                s.department_id,
                s.academic_year,
                s.semester_id,

                d.department_name,

                ay.year_name,

                sem.semester_name

            FROM students s

            INNER JOIN departments d
                ON d.id = s.department_id

            INNER JOIN academic_years ay
                ON ay.id = s.academic_year

            LEFT JOIN semesters sem
                ON sem.id = s.semester_id

            WHERE s.id = ?

            LIMIT 1
        ");

        $studentStmt->bind_param(
            "i",
            $studentId
        );

        $studentStmt->execute();

        $studentData =
            $studentStmt
                ->get_result()
                ->fetch_assoc();

        $studentStmt->close();


        /* =================================================
           DELETE
        ================================================= */

        $stmt = $conn->prepare("
            DELETE FROM students
            WHERE id = ?
        ");

        $stmt->bind_param(
            "i",
            $studentId
        );


        if ($stmt->execute()) {

            $notificationMessage =
                "A student record was deleted successfully.";

            if ($studentData) {

                $notificationMessage =
                    $studentData["student_name"] .
                    " was deleted from " .
                    $studentData["department_name"] .
                    " - " .
                    $studentData["year_name"];

                if (
                    !empty(
                        $studentData["semester_name"]
                    )
                ) {

                    $notificationMessage .=
                        " - " .
                        $studentData["semester_name"];
                }

                $notificationMessage .= ".";
            }

            createNotification(
                $conn,
                "Student Deleted",
                $notificationMessage,
                "student_deleted"
            );


            $_SESSION["flash_message"] =
                "Student deleted successfully.";

            $_SESSION["flash_message_type"] =
                "success";


            redirectToStudents(
                $departmentId,
                $yearId,
                $semesterId
            );

        } else {

            $_SESSION["flash_message"] =
                "Unable to delete student.";

            $_SESSION["flash_message_type"] =
                "error";

            redirectToStudents(
                $departmentId,
                $yearId,
                $semesterId
            );
        }

        $stmt->close();
    }
}


/* =========================================================
   DELETE ALL STUDENTS
   Branch + Academic Year

   NOTE:
   This intentionally deletes ALL semesters belonging to
   the selected branch + academic year, matching your
   original functionality.
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["delete_all_students"])
) {

    $deleteAllDepartmentId =
        (int)(
            $_POST["department_id"]
            ?? $departmentId
        );

    $deleteAllYearId =
        (int)(
            $_POST["academic_year"]
            ?? $yearId
        );

    if (
        $deleteAllDepartmentId > 0 &&
        $deleteAllYearId > 0
    ) {

        /* =================================================
           GET DEPARTMENT
        ================================================= */

        $departmentName =
            "Selected Branch";

        $deptStmt = $conn->prepare("
            SELECT
                department_name
            FROM departments
            WHERE id = ?
            LIMIT 1
        ");

        $deptStmt->bind_param(
            "i",
            $deleteAllDepartmentId
        );

        $deptStmt->execute();

        $deptRow =
            $deptStmt
                ->get_result()
                ->fetch_assoc();

        if ($deptRow) {

            $departmentName =
                $deptRow["department_name"];
        }

        $deptStmt->close();


        /* =================================================
           GET ACADEMIC YEAR
        ================================================= */

        $yearName =
            "Selected Academic Year";

        $yearStmt = $conn->prepare("
            SELECT
                year_name
            FROM academic_years
            WHERE id = ?
            LIMIT 1
        ");

        $yearStmt->bind_param(
            "i",
            $deleteAllYearId
        );

        $yearStmt->execute();

        $yearRow =
            $yearStmt
                ->get_result()
                ->fetch_assoc();

        if ($yearRow) {

            $yearName =
                $yearRow["year_name"];
        }

        $yearStmt->close();


        /* =================================================
           DELETE
        ================================================= */

        $stmt = $conn->prepare("
            DELETE FROM students
            WHERE
                department_id = ?
                AND academic_year = ?
        ");

        $stmt->bind_param(
            "ii",
            $deleteAllDepartmentId,
            $deleteAllYearId
        );

        $stmt->execute();

        $deletedRows =
            $stmt->affected_rows;

        $stmt->close();


        /* =================================================
           SUCCESS / ERROR
        ================================================= */

        if ($deletedRows > 0) {

            $notificationMessage =
                $deletedRows .
                " student(s) were deleted from " .
                $departmentName .
                " - " .
                $yearName .
                ".";

            createNotification(
                $conn,
                "Students Deleted",
                $notificationMessage,
                "students_deleted"
            );

            $_SESSION["flash_message"] =
                $deletedRows .
                " student(s) deleted successfully.";

            $_SESSION["flash_message_type"] =
                "success";

        } else {

            $_SESSION["flash_message"] =
                "No students were found to delete.";

            $_SESSION["flash_message_type"] =
                "error";
        }


        redirectToStudents(
            $deleteAllDepartmentId,
            $deleteAllYearId,
            $semesterId
        );
    }
}


/* =========================================================
   EDIT STUDENT
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["edit_student"])
) {

    $editStudentId =
        (int)(
            $_POST["edit_student_id"]
            ?? 0
        );

    $editRegisterNumber =
        normalizePin(
            $_POST["edit_pin_no"] ?? ""
        );

    $editStudentName =
        trim(
            $_POST["edit_student_name"] ?? ""
        );

    $editContactNo =
        trim(
            $_POST["edit_contact_no"] ?? ""
        );


    /* =====================================================
       VALIDATION
    ===================================================== */

    if (
        $editStudentId <= 0 ||
        $editRegisterNumber === "" ||
        $editStudentName === ""
    ) {

        $_SESSION["flash_message"] =
            "Please fill all required student details.";

        $_SESSION["flash_message_type"] =
            "error";

        redirectToStudents(
            $departmentId,
            $yearId,
            $semesterId
        );
    }


    /* =====================================================
       GET EXISTING STUDENT
    ===================================================== */

    $studentCheck = $conn->prepare("
        SELECT
            id,
            department_id,
            academic_year,
            semester_id
        FROM students
        WHERE id = ?
        LIMIT 1
    ");

    $studentCheck->bind_param(
        "i",
        $editStudentId
    );

    $studentCheck->execute();

    $existingStudent =
        $studentCheck
            ->get_result()
            ->fetch_assoc();

    $studentCheck->close();


    if (!$existingStudent) {

        $_SESSION["flash_message"] =
            "Student not found.";

        $_SESSION["flash_message_type"] =
            "error";

        redirectToStudents(
            $departmentId,
            $yearId,
            $semesterId
        );
    }


    /* =====================================================
       CHECK DUPLICATE PIN
       PIN IS GLOBALLY UNIQUE
    ===================================================== */

    $check = $conn->prepare("
        SELECT
            id
        FROM students
        WHERE
            pin_no = ?
            AND id != ?
        LIMIT 1
    ");

    $check->bind_param(
        "si",
        $editRegisterNumber,
        $editStudentId
    );

    $check->execute();

    $duplicate =
        $check
            ->get_result()
            ->num_rows > 0;

    $check->close();


    if ($duplicate) {

        $_SESSION["flash_message"] =
            "This PIN number already exists.";

        $_SESSION["flash_message_type"] =
            "error";

        redirectToStudents(
            $existingStudent["department_id"],
            $existingStudent["academic_year"],
            $existingStudent["semester_id"]
        );
    }


    /* =====================================================
   UPDATE STUDENT
===================================================== */

$editSemesterId = (int)($_POST["edit_semester_id"] ?? 0);

if ($editSemesterId <= 0) {

    $_SESSION["flash_message"] =
        "Please select a semester.";

    $_SESSION["flash_message_type"] =
        "error";

    redirectToStudents(
        $existingStudent["department_id"],
        $existingStudent["academic_year"],
        $existingStudent["semester_id"]
    );
}

$stmt = $conn->prepare("
    UPDATE students
    SET
        pin_no = ?,
        student_name = ?,
        semester_id = ?,
        contact_no = ?
    WHERE id = ?
");

if (!$stmt) {

    $_SESSION["flash_message"] =
        "Unable to prepare student update: " .
        $conn->error;

    $_SESSION["flash_message_type"] =
        "error";

    redirectToStudents(
        $existingStudent["department_id"],
        $existingStudent["academic_year"],
        $existingStudent["semester_id"]
    );
}

$stmt->bind_param(
    "ssisi",
    $editRegisterNumber,
    $editStudentName,
    $editSemesterId,
    $editContactNo,
    $editStudentId
);

if ($stmt->execute()) {

    createNotification(
        $conn,
        "Student Updated",
        $editStudentName .
        " (" .
        $editRegisterNumber .
        ") was updated successfully.",
        "student_updated"
    );

    $_SESSION["flash_message"] =
        "Student updated successfully.";

    $_SESSION["flash_message_type"] =
        "success";

} else {

    $_SESSION["flash_message"] =
        "Unable to update student: " .
        $stmt->error;

    $_SESSION["flash_message_type"] =
        "error";
}

$stmt->close();

redirectToStudents(
    $existingStudent["department_id"],
    $existingStudent["academic_year"],
    $editSemesterId
);
}


/* =========================================================
   ADD BRANCH
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["add_department"])
) {

    $departmentName =
        trim(
            $_POST["department_name"] ?? ""
        );

    $departmentCode =
        strtoupper(
            trim(
                $_POST["department_code"] ?? ""
            )
        );


    if (
        $departmentName === "" ||
        $departmentCode === ""
    ) {

        $message =
            "Please enter branch name and branch code.";

        $messageType =
            "error";

    } else {

        /* =================================================
           DUPLICATE CODE
        ================================================= */

        $check = $conn->prepare("
            SELECT id
            FROM departments
            WHERE department_code = ?
            LIMIT 1
        ");

        $check->bind_param(
            "s",
            $departmentCode
        );

        $check->execute();

        $checkResult =
            $check->get_result();


        if ($checkResult->num_rows > 0) {

            $message =
                "This branch code already exists.";

            $messageType =
                "error";

        } else {

            /* =============================================
               INSERT
            ============================================= */

            $stmt = $conn->prepare("
                INSERT INTO departments
                (
                    department_name,
                    department_code
                )
                VALUES
                (
                    ?,
                    ?
                )
            ");

            $stmt->bind_param(
                "ss",
                $departmentName,
                $departmentCode
            );


            if ($stmt->execute()) {

                createNotification(
                    $conn,
                    "Department Added",
                    $departmentName .
                    " (" .
                    $departmentCode .
                    ") was added successfully.",
                    "department_added"
                );

                $message =
                    "Branch added successfully.";

                $messageType =
                    "success";

            } else {

                $message =
                    "Unable to add branch.";

                $messageType =
                    "error";
            }

            $stmt->close();
        }

        $check->close();
    }
}


/* =========================================================
   DELETE BRANCH
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["delete_department"])
) {

    $deleteDepartmentId =
        (int)(
            $_POST["department_id"]
            ?? 0
        );

    if ($deleteDepartmentId > 0) {

        /* =================================================
           GET DETAILS
        ================================================= */

        $departmentDetails =
            $conn->prepare("
                SELECT
                    department_name,
                    department_code
                FROM departments
                WHERE id = ?
                LIMIT 1
            ");

        $departmentDetails->bind_param(
            "i",
            $deleteDepartmentId
        );

        $departmentDetails->execute();

        $department =
            $departmentDetails
                ->get_result()
                ->fetch_assoc();

        $departmentDetails->close();


        $departmentName =
            $department["department_name"] ?? "";

        $departmentCode =
            $department["department_code"] ?? "";


        /* =================================================
           CHECK STUDENTS
        ================================================= */

        $check = $conn->prepare("
            SELECT
                COUNT(*) AS total
            FROM students
            WHERE department_id = ?
        ");

        $check->bind_param(
            "i",
            $deleteDepartmentId
        );

        $check->execute();

        $studentCount =
            $check
                ->get_result()
                ->fetch_assoc()["total"];

        $check->close();


        if ($studentCount > 0) {

            $message =
                "This branch cannot be deleted because students are assigned to it.";

            $messageType =
                "error";

        } else {

            $stmt = $conn->prepare("
                DELETE FROM departments
                WHERE id = ?
            ");

            $stmt->bind_param(
                "i",
                $deleteDepartmentId
            );


            if ($stmt->execute()) {

                createNotification(
                    $conn,
                    "Department Deleted",
                    $departmentName .
                    " (" .
                    $departmentCode .
                    ") was deleted successfully.",
                    "department_deleted"
                );

                $message =
                    "Branch deleted successfully.";

                $messageType =
                    "success";

            } else {

                $message =
                    "Unable to delete branch.";

                $messageType =
                    "error";
            }

            $stmt->close();
        }
    }
}


/* =========================================================
   ADD ACADEMIC YEAR
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["add_year"])
) {

    $yearName =
        trim(
            $_POST["year_name"] ?? ""
        );


    if ($yearName === "") {

        $message =
            "Please enter an academic year.";

        $messageType =
            "error";

    } else {

        $check = $conn->prepare("
            SELECT
                id
            FROM academic_years
            WHERE year_name = ?
            LIMIT 1
        ");

        $check->bind_param(
            "s",
            $yearName
        );

        $check->execute();

        $checkResult =
            $check->get_result();


        if ($checkResult->num_rows > 0) {

            $message =
                "This academic year already exists.";

            $messageType =
                "error";

        } else {

            $stmt = $conn->prepare("
                INSERT INTO academic_years
                (
                    year_name
                )
                VALUES
                (?)
            ");

            $stmt->bind_param(
                "s",
                $yearName
            );


            if ($stmt->execute()) {

                createNotification(
                    $conn,
                    "Academic Year Added",
                    $yearName .
                    " was added successfully.",
                    "academic_year_added"
                );

                $message =
                    "Academic year added successfully.";

                $messageType =
                    "success";

            } else {

                $message =
                    "Unable to add academic year.";

                $messageType =
                    "error";
            }

            $stmt->close();
        }

        $check->close();
    }
}


/* =========================================================
   DELETE ACADEMIC YEAR
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["delete_year"])
) {

    $deleteYearId =
        (int)(
            $_POST["academic_year"]
            ?? 0
        );


    if ($deleteYearId > 0) {

        /* =================================================
           GET YEAR NAME
        ================================================= */

        $yearDetails =
            $conn->prepare("
                SELECT
                    year_name
                FROM academic_years
                WHERE id = ?
                LIMIT 1
            ");

        $yearDetails->bind_param(
            "i",
            $deleteYearId
        );

        $yearDetails->execute();

        $yearData =
            $yearDetails
                ->get_result()
                ->fetch_assoc();

        $yearDetails->close();


        /* =================================================
           CHECK STUDENTS
        ================================================= */

        $check = $conn->prepare("
            SELECT
                COUNT(*) AS total
            FROM students
            WHERE academic_year = ?
        ");

        $check->bind_param(
            "i",
            $deleteYearId
        );

        $check->execute();

        $studentCount =
            $check
                ->get_result()
                ->fetch_assoc()["total"];

        $check->close();


        if ($studentCount > 0) {

            $message =
                "This academic year cannot be deleted because students are assigned to it in one or more branches.";

            $messageType =
                "error";

        } else {

            /*
             * Delete only if this academic year is still unused anywhere
             * in the students table. This is a second safety check after
             * the COUNT above, so a used year can never be removed.
             */
            $stmt = $conn->prepare("
                DELETE ay
                FROM academic_years ay
                WHERE ay.id = ?
                  AND NOT EXISTS (
                      SELECT 1
                      FROM students s
                      WHERE s.academic_year = ay.id
                  )
            ");

            $stmt->bind_param(
                "i",
                $deleteYearId
            );


            if ($stmt->execute() && $stmt->affected_rows === 1) {

                createNotification(
                    $conn,
                    "Academic Year Deleted",
                    ($yearData["year_name"] ?? "Academic year") .
                    " was deleted successfully.",
                    "academic_year_deleted"
                );

                $message =
                    "Academic year deleted successfully.";

                $messageType =
                    "success";

            } else {

                $message =
                    "Unable to delete academic year. It may already be in use by students.";

                $messageType =
                    "error";
            }

            $stmt->close();
        }
    }
}

/* =========================================================
   ADD STUDENT
   ========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_student"])) {

    /* ---------------------------------------------------------
       GET FORM VALUES
    --------------------------------------------------------- */

    $departmentId = (int)($_POST["department_id"] ?? 0);
    $yearId       = (int)($_POST["academic_year"] ?? 0);
    $semesterId   = (int)($_POST["semester_id"] ?? 0);

    $pinNo       = strtoupper(trim($_POST["pin_no"] ?? ""));
    $studentName = trim($_POST["student_name"] ?? "");
    $contactNo   = trim($_POST["contact_no"] ?? "");


    /* ---------------------------------------------------------
       VALIDATION
    --------------------------------------------------------- */

    if (
        $departmentId <= 0 ||
        $yearId <= 0 ||
        $semesterId <= 0 ||
        $pinNo === "" ||
        $studentName === ""
    ) {

        $_SESSION["student_error"] =
            "Please fill all required student details.";

    } else {

        /* -----------------------------------------------------
           CHECK DUPLICATE PIN
        ----------------------------------------------------- */

        $checkStmt = $conn->prepare("
            SELECT id
            FROM students
            WHERE pin_no = ?
            LIMIT 1
        ");

        if (!$checkStmt) {

            $_SESSION["student_error"] =
                "Database error: " . $conn->error;

        } else {

            $checkStmt->bind_param(
                "s",
                $pinNo
            );

            $checkStmt->execute();

            $checkResult = $checkStmt->get_result();

            /* -------------------------------------------------
               DUPLICATE PIN FOUND
            ------------------------------------------------- */

            if ($checkResult->num_rows > 0) {

                $_SESSION["student_error"] =
                    "This PIN number already exists.";

                $checkStmt->close();

            } else {

                $checkStmt->close();


                /* ---------------------------------------------
                   INSERT STUDENT
                --------------------------------------------- */

                $insertStmt = $conn->prepare("
                    INSERT INTO students
                    (
                        pin_no,
                        student_name,
                        department_id,
                        academic_year,
                        semester_id,
                        contact_no
                    )
                    VALUES
                    (?, ?, ?, ?, ?, ?)
                ");

                if (!$insertStmt) {

                    $_SESSION["student_error"] =
                        "Failed to prepare student insertion: " .
                        $conn->error;

                } else {

                    $insertStmt->bind_param(
                        "ssiiis",
                        $pinNo,
                        $studentName,
                        $departmentId,
                        $yearId,
                        $semesterId,
                        $contactNo
                    );


                    /* -----------------------------------------
                       STUDENT INSERT SUCCESS
                    ----------------------------------------- */

                    if ($insertStmt->execute()) {

    /* =========================================
       STUDENT SUCCESS MESSAGE
    ========================================= */

    $_SESSION["student_success"] =
        "Student added successfully.";


    /* =========================================
       ADD STUDENT NOTIFICATION
    ========================================= */

    $notificationTitle = "Student Added";

    $notificationMessage =
        "Student " .
        $studentName .
        " (" .
        $pinNo .
        ") was added successfully.";

    $notificationIcon = "fa-user-plus";

    $notificationColor = "primary";

    $notificationType = "student_added";


    $notificationStmt = $conn->prepare("
        INSERT INTO notifications
        (
            title,
            message,
            icon,
            color,
            is_read,
            type,
            created_at
        )
        VALUES
        (?, ?, ?, ?, 0, ?, NOW())
    ");


    if ($notificationStmt) {

        $notificationStmt->bind_param(
            "sssss",
            $notificationTitle,
            $notificationMessage,
            $notificationIcon,
            $notificationColor,
            $notificationType
        );


        if (!$notificationStmt->execute()) {

            error_log(
                "Student notification failed: " .
                $notificationStmt->error
            );

        }


        $notificationStmt->close();

    } else {

        error_log(
            "Student notification prepare failed: " .
            $conn->error
        );
    }


} else {

    $_SESSION["student_error"] =
        "Failed to add student: " .
        $insertStmt->error;
}


$insertStmt->close();
                }
            }
        }
    }


    /* ---------------------------------------------------------
       REDIRECT
       Prevent form resubmission
    --------------------------------------------------------- */

    header(
        "Location: students.php?department=" .
        $departmentId .
        "&year=" .
        $yearId
    );

    exit;
}
/* =========================================================
   IMPORT STUDENTS - EXCEL
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["import_students"])
) {

    $importDepartmentId =
        (int)(
            $_POST["import_department_id"]
            ?? 0
        );

    $importYearId =
        (int)(
            $_POST["import_academic_year"]
            ?? 0
        );

    $importSemesterId =
        (int)(
            $_POST["import_semester_id"]
            ?? 0
        );


    /* =====================================================
       VALIDATION
    ===================================================== */

    if (
        $importDepartmentId <= 0 ||
        $importYearId <= 0 ||
        $importSemesterId <= 0
    ) {

        $_SESSION["flash_message"] =
            "Please select a branch, academic year and semester.";

        $_SESSION["flash_message_type"] =
            "error";

        redirectToStudents(
            $importDepartmentId,
            $importYearId,
            $importSemesterId
        );
    }


    if (
        !isset($_FILES["student_file"]) ||
        $_FILES["student_file"]["error"]
        !== UPLOAD_ERR_OK
    ) {

        $_SESSION["flash_message"] =
            "Please select an Excel file.";

        $_SESSION["flash_message_type"] =
            "error";

        redirectToStudents(
            $importDepartmentId,
            $importYearId,
            $importSemesterId
        );
    }


    try {

        /* =================================================
           VERIFY DEPARTMENT
        ================================================= */

        $departmentCheck =
            $conn->prepare("
                SELECT
                    id,
                    department_code,
                    department_name
                FROM departments
                WHERE id = ?
                LIMIT 1
            ");

        $departmentCheck->bind_param(
            "i",
            $importDepartmentId
        );

        $departmentCheck->execute();

        $departmentData =
            $departmentCheck
                ->get_result()
                ->fetch_assoc();

        $departmentCheck->close();


        if (!$departmentData) {

            throw new Exception(
                "Invalid branch selected."
            );
        }


        /* =================================================
           VERIFY ACADEMIC YEAR
        ================================================= */

        $yearCheck =
            $conn->prepare("
                SELECT
                    id,
                    year_name
                FROM academic_years
                WHERE id = ?
                LIMIT 1
            ");

        $yearCheck->bind_param(
            "i",
            $importYearId
        );

        $yearCheck->execute();

        $yearData =
            $yearCheck
                ->get_result()
                ->fetch_assoc();

        $yearCheck->close();


        if (!$yearData) {

            throw new Exception(
                "Invalid academic year selected."
            );
        }


        /* =================================================
           VERIFY SEMESTER
        ================================================= */

        $semesterCheck =
            $conn->prepare("
                SELECT
                    id,
                    semester_name,
                    semester_number
                FROM semesters
                WHERE id = ?
                  AND status = 1
                LIMIT 1
            ");

        $semesterCheck->bind_param(
            "i",
            $importSemesterId
        );

        $semesterCheck->execute();

        $semesterData =
            $semesterCheck
                ->get_result()
                ->fetch_assoc();

        $semesterCheck->close();


        if (!$semesterData) {

            throw new Exception(
                "Invalid semester selected."
            );
        }


        /* =================================================
           VERIFY YEAR + SEMESTER
        ================================================= */

        $requiredYearNumber =
            getYearNumberFromSemester(
                $semesterData["semester_number"]
            );

        $actualYearNumber =
            getAcademicYearNumber(
                $yearData["year_name"]
            );

        if (
            $requiredYearNumber > 0 &&
            $actualYearNumber > 0 &&
            $requiredYearNumber !== $actualYearNumber
        ) {

            throw new Exception(
                "The selected semester does not belong to the selected academic year."
            );
        }


        /* =================================================
           LOAD EXCEL
        ================================================= */

        $spreadsheet =
            IOFactory::load(
                $_FILES["student_file"]["tmp_name"]
            );

        $worksheet =
            $spreadsheet
                ->getActiveSheet();

        $rows =
            $worksheet->toArray();


        if (count($rows) < 2) {

            throw new Exception(
                "The Excel file is empty."
            );
        }

/* =====================================================
   READ HEADER
===================================================== */

$header = array_map(
    function ($value) {

        return trim(
            (string)$value
        );

    },
    $rows[0]
);


/* =====================================================
   NORMALIZE HEADERS
===================================================== */

$normalizedHeaders = [];

foreach ($header as $index => $value) {

    $value = trim(
        strtolower(
            (string)$value
        )
    );

    /*
     * Remove BOM
     */
    $value = preg_replace(
        '/^\xEF\xBB\xBF/',
        '',
        $value
    );

    /*
     * Remove spaces, underscores,
     * hyphens and special characters
     */
    $value = preg_replace(
        '/[\s_\-]+/',
        '',
        $value
    );

    $normalizedHeaders[$index] = $value;
}


/* =====================================================
   FIND PIN COLUMN
===================================================== */

$pinColumn = false;

foreach ($normalizedHeaders as $index => $value) {

    if (
        in_array(
            $value,
            [
                "pin",
                "pinno",
                "pinnumber",
                "studentpin",
                "studentpinno"
            ],
            true
        )
    ) {

        $pinColumn = $index;

        break;
    }
}


/* =====================================================
   FIND NAME COLUMN
===================================================== */

$nameColumn = false;

foreach ($normalizedHeaders as $index => $value) {

    if (
        in_array(
            $value,
            [
                "name",
                "studentname",
                "studentfullname",
                "fullname",
                "student",
                "nameofstudent"
            ],
            true
        )
    ) {

        $nameColumn = $index;

        break;
    }
}


/* =====================================================
   FIND CONTACT COLUMN
===================================================== */

$contactColumn = false;

foreach ($normalizedHeaders as $index => $value) {

    if (
        in_array(
            $value,
            [
                "contact",
                "contactno",
                "contactnumber",
                "mobileno",
                "mobilenumber",
                "mobile",
                "phone",
                "phonenumber",
                "studentcontact"
            ],
            true
        )
    ) {

        $contactColumn = $index;

        break;
    }
}


/* =====================================================
   REQUIRED COLUMNS
===================================================== */

if (
    $pinColumn === false ||
    $nameColumn === false
) {

    throw new Exception(
        "Invalid Excel format. Required columns: PIN No and Name."
    );
}


        /* =================================================
           COUNTERS
        ================================================= */

        $imported = 0;
        $skipped = 0;


        /* =================================================
           PREPARE DUPLICATE CHECK
           PIN IS GLOBALLY UNIQUE
        ================================================= */

        $duplicateStmt =
            $conn->prepare("
                SELECT
                    id
                FROM students
                WHERE pin_no = ?
                LIMIT 1
            ");


        /* =================================================
           PREPARE INSERT
        ================================================= */

        $insertStmt =
            $conn->prepare("
                INSERT INTO students
                (
                    pin_no,
                    student_name,
                    department_id,
                    academic_year,
                    semester_id,
                    contact_no
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");


        if (
            !$duplicateStmt ||
            !$insertStmt
        ) {

            throw new Exception(
                "Unable to prepare import queries."
            );
        }


        /* =================================================
           PROCESS ROWS
        ================================================= */

        for (
            $i = 1;
            $i < count($rows);
            $i++
        ) {

            $row =
                $rows[$i];


            /* ---------------------------------------------
               PIN
            --------------------------------------------- */

            $pin =
                normalizePin(
                    trim(
                        (string)(
                            $row[$pinColumn]
                            ?? ""
                        )
                    )
                );


            /* ---------------------------------------------
               NAME
            --------------------------------------------- */

            /* ---------------------------------------------
   NAME
--------------------------------------------- */

$name = "";

if ($nameColumn !== false && isset($row[$nameColumn])) {

    $name = (string)$row[$nameColumn];

    /*
     * Remove Excel whitespace
     */
    $name = trim($name);

    /*
     * Convert multiple spaces into one
     */
    $name = preg_replace(
        '/\s+/u',
        ' ',
        $name
    );

    $name = trim($name);
}


            /* ---------------------------------------------
               CONTACT
            --------------------------------------------- */

            $contact = "";

            if ($contactColumn !== false) {

                $contact =
                    trim(
                        (string)(
                            $row[$contactColumn]
                            ?? ""
                        )
                    );
            }


            /* ---------------------------------------------
               EMPTY ROW
            --------------------------------------------- */

            if (
                $pin === "" ||
                $name === ""
            ) {

                $skipped++;

                continue;
            }


            /* ---------------------------------------------
               DUPLICATE
            --------------------------------------------- */

            $duplicateStmt->bind_param(
                "s",
                $pin
            );

            $duplicateStmt->execute();

            $exists =
                $duplicateStmt
                    ->get_result()
                    ->num_rows > 0;


            if ($exists) {

                $skipped++;

                continue;
            }


            /* ---------------------------------------------
               INSERT
            --------------------------------------------- */

            $insertStmt->bind_param(
    "ssiiis",
    $pin,
    $name,
    $importDepartmentId,
    $importYearId,
    $importSemesterId,
    $contact
);


            if ($insertStmt->execute()) {

                $imported++;

            } else {

                $skipped++;
            }
        }


        $duplicateStmt->close();
        $insertStmt->close();


        /* =================================================
           FLASH MESSAGE
        ================================================= */

        $flashMessage =
            $imported .
            " student(s) imported successfully.";

        if ($skipped > 0) {

            $flashMessage .=
                " " .
                $skipped .
                " row(s) skipped.";
        }

        $_SESSION["flash_message"] =
            $flashMessage;

        $_SESSION["flash_message_type"] =
            $imported > 0
            ? "success"
            : "error";


        /* =================================================
           NOTIFICATION
        ================================================= */

        if ($imported > 0) {

            $notificationMessage =
                $imported .
                " student(s) imported successfully into " .
                $departmentData["department_name"] .
                " - " .
                $yearData["year_name"] .
                " - " .
                $semesterData["semester_name"] .
                ".";

            if ($skipped > 0) {

                $notificationMessage .=
                    " " .
                    $skipped .
                    " row(s) skipped.";
            }

            createNotification(
                $conn,
                "Students Imported",
                $notificationMessage,
                "student_imported"
            );
        }


        /* =================================================
           REDIRECT
        ================================================= */

        redirectToStudents(
            $importDepartmentId,
            $importYearId,
            $importSemesterId
        );


    } catch (Exception $e) {

        $_SESSION["flash_message"] =
            "Import failed: " .
            $e->getMessage();

        $_SESSION["flash_message_type"] =
            "error";

        redirectToStudents(
            $importDepartmentId,
            $importYearId,
            $importSemesterId
        );
    }
}


/* =========================================================
   PROMOTE STUDENTS
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["promote_students"])
) {

    $promotionDepartmentId =
        (int)(
            $_POST["department_id"]
            ?? 0
        );

    $fromYear =
        (int)(
            $_POST["from_year"]
            ?? 0
        );

    $toYear =
        (int)(
            $_POST["to_year"]
            ?? 0
        );

    $fromSemester =
        (int)(
            $_POST["from_semester_id"]
            ?? 0
        );

    $toSemester =
        (int)(
            $_POST["to_semester_id"]
            ?? 0
        );


    /* =====================================================
       BASIC VALIDATION
    ===================================================== */

    if (
        $promotionDepartmentId <= 0 ||
        $fromYear <= 0 ||
        $toYear <= 0 ||
        $fromSemester <= 0 ||
        $toSemester <= 0
    ) {

        $_SESSION["flash_message"] =
            "Please select department, academic year and semester.";

        $_SESSION["flash_message_type"] =
            "error";

        redirectToStudents(
            $promotionDepartmentId
        );
    }


    /* =====================================================
       GET SEMESTERS
    ===================================================== */

    $semesterStmt =
        $conn->prepare("
            SELECT
                id,
                semester_name,
                semester_number
            FROM semesters
            WHERE id IN (?, ?)
        ");

    $semesterStmt->bind_param(
        "ii",
        $fromSemester,
        $toSemester
    );

    $semesterStmt->execute();

    $semesterResult =
        $semesterStmt
            ->get_result();

    $semesters = [];

    while (
        $semesterRow =
        $semesterResult->fetch_assoc()
    ) {

        $semesters[
            (int)$semesterRow["id"]
        ] = $semesterRow;
    }

    $semesterStmt->close();


    /* =====================================================
       CHECK SEMESTERS
    ===================================================== */

    if (
        !isset(
            $semesters[$fromSemester]
        ) ||
        !isset(
            $semesters[$toSemester]
        )
    ) {

        $_SESSION["flash_message"] =
            "Invalid semester selected.";

        $_SESSION["flash_message_type"] =
            "error";

        redirectToStudents(
            $promotionDepartmentId
        );
    }


    $fromSemesterNumber =
        (int)$semesters[
            $fromSemester
        ]["semester_number"];

    $toSemesterNumber =
        (int)$semesters[
            $toSemester
        ]["semester_number"];


    /* =====================================================
       GET YEARS
    ===================================================== */

    $yearStmt =
        $conn->prepare("
            SELECT
                id,
                year_name
            FROM academic_years
            WHERE id IN (?, ?)
        ");

    $yearStmt->bind_param(
        "ii",
        $fromYear,
        $toYear
    );

    $yearStmt->execute();

    $yearResult =
        $yearStmt
            ->get_result();

    $selectedYears = [];

    while (
        $yearRow =
        $yearResult->fetch_assoc()
    ) {

        $selectedYears[
            (int)$yearRow["id"]
        ] =
            trim(
                $yearRow["year_name"]
            );
    }

    $yearStmt->close();


    /* =====================================================
       CHECK YEARS
    ===================================================== */

    if (
        !isset($selectedYears[$fromYear]) ||
        !isset($selectedYears[$toYear])
    ) {

        $_SESSION["flash_message"] =
            "Invalid academic year selected.";

        $_SESSION["flash_message_type"] =
            "error";

        redirectToStudents(
            $promotionDepartmentId
        );
    }


    /* =====================================================
       SEMESTER 8
    ===================================================== */

    if ($fromSemesterNumber >= 8) {

        $_SESSION["flash_message"] =
            "Students in Semester 8 cannot be promoted.";

        $_SESSION["flash_message_type"] =
            "error";

        redirectToStudents(
            $promotionDepartmentId
        );
    }


    /* =====================================================
       ONLY NEXT SEMESTER
    ===================================================== */

    if (
        $toSemesterNumber !==
        ($fromSemesterNumber + 1)
    ) {

        $_SESSION["flash_message"] =
            "Students can only be promoted to the next semester.";

        $_SESSION["flash_message_type"] =
            "error";

        redirectToStudents(
            $promotionDepartmentId
        );
    }


    /* =====================================================
       VALIDATE YEAR + SEMESTER
    ===================================================== */

    $requiredFromYearNumber =
        getYearNumberFromSemester(
            $fromSemesterNumber
        );

    $requiredToYearNumber =
        getYearNumberFromSemester(
            $toSemesterNumber
        );

    $actualFromYearNumber =
        getAcademicYearNumber(
            $selectedYears[$fromYear]
        );

    $actualToYearNumber =
        getAcademicYearNumber(
            $selectedYears[$toYear]
        );


    if (
        $actualFromYearNumber !==
        $requiredFromYearNumber
    ) {

        $_SESSION["flash_message"] =
            "The selected starting year does not match the selected semester.";

        $_SESSION["flash_message_type"] =
            "error";

        redirectToStudents(
            $promotionDepartmentId
        );
    }


    if (
        $actualToYearNumber !==
        $requiredToYearNumber
    ) {

        $_SESSION["flash_message"] =
            "The selected destination year does not match the selected semester.";

        $_SESSION["flash_message_type"] =
            "error";

        redirectToStudents(
            $promotionDepartmentId
        );
    }


    /* =====================================================
       PROMOTE
    ===================================================== */

    $stmt =
        $conn->prepare("
            UPDATE students
            SET
                academic_year = ?,
                semester_id = ?
            WHERE
                department_id = ?
                AND academic_year = ?
                AND semester_id = ?
        ");

    $stmt->bind_param(
        "iiiii",
        $toYear,
        $toSemester,
        $promotionDepartmentId,
        $fromYear,
        $fromSemester
    );

    $stmt->execute();

    $affected =
        $stmt->affected_rows;

    $stmt->close();


    /* =====================================================
       RESULT
    ===================================================== */

    if ($affected > 0) {

        $promotionMessage =
            $affected .
            " student(s) promoted from " .
            $selectedYears[$fromYear] .
            " / " .
            $semesters[$fromSemester]["semester_name"] .
            " to " .
            $selectedYears[$toYear] .
            " / " .
            $semesters[$toSemester]["semester_name"] .
            ".";

        $_SESSION["flash_message"] =
            $promotionMessage;

        $_SESSION["flash_message_type"] =
            "success";


        createNotification(
            $conn,
            "Students Promoted",
            $promotionMessage,
            "students_promoted"
        );

    } else {

        $_SESSION["flash_message"] =
            "No students were found in the selected year and semester.";

        $_SESSION["flash_message_type"] =
            "error";
    }


    /* =====================================================
       REDIRECT
    ===================================================== */

    redirectToStudents(
        $promotionDepartmentId,
        $toYear,
        $toSemester
    );
}


/* =========================================================
   FETCH DEPARTMENTS
========================================================= */

$departments = [];

$result =
    mysqli_query(
        $conn,
        "
        SELECT
            d.id,
            d.department_name,
            d.department_code,
            COUNT(s.id) AS student_count

        FROM departments d

        LEFT JOIN students s
            ON s.department_id = d.id

        GROUP BY
            d.id,
            d.department_name,
            d.department_code

        ORDER BY
            d.department_name ASC
        "
    );

if ($result) {

    while (
        $row =
        mysqli_fetch_assoc($result)
    ) {

        $departments[] =
            $row;
    }
}


/* =========================================================
   SELECTED DEPARTMENT
========================================================= */

$selectedDepartment = null;

if ($departmentId > 0) {

    $stmt =
        $conn->prepare("
            SELECT
                id,
                department_name,
                department_code
            FROM departments
            WHERE id = ?
            LIMIT 1
        ");

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


/* =========================================================
   FETCH ACADEMIC YEARS
   Show ALL years, including zero students.
========================================================= */

$academicYears = [];

if ($selectedDepartment) {

    $stmt =
        $conn->prepare("
            SELECT
                ay.id,
                ay.year_name,
                COUNT(s.id) AS student_count

            FROM academic_years ay

            LEFT JOIN students s
                ON s.academic_year = ay.id
                AND s.department_id = ?

            GROUP BY
                ay.id,
                ay.year_name

            ORDER BY
                ay.id ASC
        ");

    if (!$stmt) {

        die(
            "Academic Year Query Error: " .
            $conn->error
        );
    }

    $stmt->bind_param(
        "i",
        $departmentId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    while (
        $row =
        $result->fetch_assoc()
    ) {

        $academicYears[] =
            $row;
    }

    $stmt->close();
}


/* =========================================================
   FETCH SELECTED ACADEMIC YEAR
========================================================= */

$selectedYear = null;

if (
    $yearId > 0 &&
    $selectedDepartment
) {

    $stmt =
        $conn->prepare("
            SELECT
                id,
                year_name
            FROM academic_years
            WHERE id = ?
            LIMIT 1
        ");

    if (!$stmt) {

        die(
            "Selected Year Query Error: " .
            $conn->error
        );
    }

    $stmt->bind_param(
        "i",
        $yearId
    );

    $stmt->execute();

    $selectedYear =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();
}


/* =========================================================
   FETCH SEMESTERS
========================================================= */

$semesters = [];

if ($selectedYear) {

    /*
     * All active semesters are loaded.
     *
     * Your HTML/JavaScript can filter them according
     * to the academic year.
     */

    $semesterStmt =
        $conn->prepare("
            SELECT
                id,
                semester_name,
                semester_number,
                year_number,
                status
            FROM semesters
            WHERE status = 1
            ORDER BY semester_number ASC
        ");

    if ($semesterStmt) {

        $semesterStmt->execute();

        $semesterResult =
            $semesterStmt
                ->get_result();

        while (
            $semesterRow =
            $semesterResult->fetch_assoc()
        ) {

            $semesters[] =
                $semesterRow;
        }

        $semesterStmt->close();
    }
}


/* =========================================================
   IMPORT SEMESTERS FOR SELECTED ACADEMIC YEAR
   1st Year -> Semester 1, Semester 2
   2nd Year -> Semester 3, Semester 4
   3rd Year -> Semester 5, Semester 6
   4th Year -> Semester 7, Semester 8
========================================================= */
$availableSemesters = [];

$availableYearNumber = getAcademicYearNumber(
    $selectedYear["year_name"] ?? ""
);

if ($availableYearNumber > 0) {

    $availableSemesterStmt = $conn->prepare("
        SELECT
            id,
            semester_name,
            semester_number,
            year_number
        FROM semesters
        WHERE year_number = ?
          AND status = 1
        ORDER BY semester_number ASC
    ");

    if ($availableSemesterStmt) {

        $availableSemesterStmt->bind_param(
            "i",
            $availableYearNumber
        );

        $availableSemesterStmt->execute();

        $availableSemesterResult =
            $availableSemesterStmt->get_result();

        while (
            $availableSemesterRow =
            $availableSemesterResult->fetch_assoc()
        ) {
            $availableSemesters[] =
                $availableSemesterRow;
        }

        $availableSemesterStmt->close();
    }
}


/* =========================================================
   SELECTED SEMESTER
========================================================= */

$selectedSemester = null;

if (
    $semesterId > 0 &&
    $selectedYear
) {

    $stmt =
        $conn->prepare("
            SELECT
                id,
                semester_name,
                semester_number,
                year_number,
                status
            FROM semesters
            WHERE id = ?
              AND status = 1
            LIMIT 1
        ");

    if ($stmt) {

        $stmt->bind_param(
            "i",
            $semesterId
        );

        $stmt->execute();

        $selectedSemester =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();
    }
}


/* =========================================================
   FETCH STUDENTS
   Department + Academic Year
   + Optional Semester
   + Optional Search
========================================================= */

$students = [];

/*
 * IMPORTANT:
 * The students table uses academic_year
 * consistently throughout this module.
 */

$sql = "
    SELECT
        s.id,
        s.pin_no,
        s.student_name,
        s.contact_no,
        s.department_id,
        s.academic_year,
        s.semester_id,

        ay.year_name,

        sem.semester_name,
        sem.semester_number

    FROM students s

    LEFT JOIN academic_years ay
        ON ay.id = s.academic_year

    LEFT JOIN semesters sem
        ON sem.id = s.semester_id

    WHERE
        s.department_id = ?
        AND s.academic_year = ?
";


/* =====================================================
   SEMESTER FILTER
===================================================== */

if ($semesterId > 0) {

    $sql .= "
        AND s.semester_id = ?
    ";
}


/* =====================================================
   SEARCH FILTER
===================================================== */

if ($search !== "") {

    $sql .= "
        AND (
            s.pin_no LIKE ?
            OR s.student_name LIKE ?
            OR s.contact_no LIKE ?
        )
    ";
}


/* =====================================================
   ORDER
===================================================== */

$sql .= "
    ORDER BY
        sem.semester_number ASC,
        s.pin_no ASC
";


/* =====================================================
   PREPARE
===================================================== */

$stmt = $conn->prepare($sql);

if (!$stmt) {

    die(
        "Student Query Error: " .
        $conn->error
    );
}


/* =====================================================
   BIND PARAMETERS
===================================================== */

if (
    $semesterId > 0 &&
    $search !== ""
) {

    $searchValue =
        "%" .
        $search .
        "%";

    $stmt->bind_param(
        "iiisss",
        $departmentId,
        $yearId,
        $semesterId,
        $searchValue,
        $searchValue,
        $searchValue
    );

} elseif ($semesterId > 0) {

    $stmt->bind_param(
        "iii",
        $departmentId,
        $yearId,
        $semesterId
    );

} elseif ($search !== "") {

    $searchValue =
        "%" .
        $search .
        "%";

    $stmt->bind_param(
        "iisss",
        $departmentId,
        $yearId,
        $searchValue,
        $searchValue,
        $searchValue
    );

} else {

    $stmt->bind_param(
        "ii",
        $departmentId,
        $yearId
    );
}


/* =====================================================
   EXECUTE
===================================================== */

if (!$stmt->execute()) {

    die(
        "Student Query Execute Error: " .
        $stmt->error
    );
}


$result =
    $stmt->get_result();


/* =====================================================
   STORE STUDENTS
===================================================== */

while (
    $row =
    $result->fetch_assoc()
) {

    $students[] =
        $row;
}


$stmt->close();

/* =========================================================
   STUDENT COUNTS
========================================================= */

$totalStudents =
    count($students);


/* =========================================================
   AVAILABLE SEMESTER COUNTS
   Useful for displaying counts in the UI.
========================================================= */

$semesterStudentCounts = [];

if (
    $selectedDepartment &&
    $selectedYear
) {

    $countStmt =
        $conn->prepare("
            SELECT
                semester_id,
                COUNT(*) AS total
            FROM students
            WHERE
                department_id = ?
                AND academic_year = ?
            GROUP BY semester_id
        ");

    if ($countStmt) {

        $countStmt->bind_param(
            "ii",
            $departmentId,
            $yearId
        );

        $countStmt->execute();

        $countResult =
            $countStmt
                ->get_result();

        while (
            $countRow =
            $countResult->fetch_assoc()
        ) {

            $semesterStudentCounts[
                (int)$countRow["semester_id"]
            ] =
                (int)$countRow["total"];
        }

        $countStmt->close();
    }
}


/* =========================================================
   SELECTED SEMESTER STUDENT COUNT
========================================================= */

$selectedSemesterStudentCount = 0;

if ($semesterId > 0) {

    $selectedSemesterStudentCount =
        $semesterStudentCounts[
            $semesterId
        ] ?? 0;
}


/* =========================================================
   END PHP
========================================================= */

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0">

<title>
    Students | Examination Seating Management System
</title>

<link
    rel="stylesheet"
    href="../assets/css/students.css">

<link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

</head>

<body>
<!-- SIDEBAR OVERLAY -->
<div class="overlay" id="overlay"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">

    <div class="sidebar-header">
        <h2>ESMS</h2>

        <button type="button" id="closeSidebar">
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

        <li class="active">
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

<div class="students-page">


<!-- =====================================================
     HEADER
====================================================== -->

<header class="page-header">

    <div class="header-left">

        <div class="breadcrumb">

    <!-- MENU BUTTON -->
    <button
        type="button"
        class="menu-button"
        id="openSidebar"
        title="Open Menu"
    >
        <i class="fa-solid fa-bars"></i>
    </button>

    <!-- DASHBOARD -->
    <a href="dashboard.php">
        <i class="fa-solid fa-house"></i>
        Dashboard
    </a>

    <span>/</span>

    <span>Students</span>

</div>

        <h1>
            Student Management
        </h1>

        <p>
            Manage students by branch and academic year.
        </p>

    </div>


    <?php if (!$selectedDepartment): ?>

        <button
            type="button"
            class="btn btn-primary"
            onclick="openDepartmentModal()">

            <i class="fa-solid fa-plus"></i>

            Add Branch

        </button>

    <?php elseif (!$selectedYear): ?>

        <button
            type="button"
            class="btn btn-primary"
            onclick="openYearModal()">

            <i class="fa-solid fa-plus"></i>

            Add Academic Year

        </button>

    <?php endif; ?>

</header>


<!-- =====================================================
     MESSAGE
====================================================== -->

<?php if ($message !== ""): ?>

<div class="alert
    <?php
    echo $messageType === "success"
        ? "alert-success"
        : "alert-error";
    ?>">

    <i class="fa-solid
        <?php
        echo $messageType === "success"
            ? "fa-circle-check"
            : "fa-circle-exclamation";
        ?>">
    </i>

    <span>
        <?php echo clean($message); ?>
    </span>

</div>

<?php endif; ?>

<!-- =====================================================
     BRANCH PAGE
====================================================== -->

<?php if (!$selectedDepartment): ?>

<section class="module-section">

    <div class="section-heading">

        <div>

            <span class="section-label">
                STUDENTS
            </span>

            <h2>
                Branches
            </h2>

            <p>
                Select a branch to view academic years.
            </p>

        </div>

        <div class="count-badge">

            <i class="fa-solid fa-building-columns"></i>

            <?php echo count($departments); ?>

            Branches

        </div>

    </div>


    <div class="branch-grid">

        <?php foreach ($departments as $department): ?>

        <div class="branch-card">

            <!-- BRANCH LINK -->

            <a
                href="students.php?department=<?php
                echo (int)$department['id'];
                ?>"
                class="branch-main">

                <div class="branch-icon">

                    <i class="fa-solid fa-building-columns"></i>

                </div>


                <div class="branch-content">

                    <span class="branch-code">

                        <?php
                        echo clean(
                            strtoupper(
                                $department['department_code']
                            )
                        );
                        ?>

                    </span>


                    <h3>

                        <?php
                        echo clean(
                            $department['department_name']
                        );
                        ?>

                    </h3>


                    <p>

                        <i class="fa-solid fa-users"></i>

                        <?php
                        echo (int)$department['student_count'];
                        ?>

                        Students

                    </p>

                </div>


                <i class="fa-solid fa-arrow-right branch-arrow"></i>

            </a>


            <!-- DELETE BRANCH -->

            <form
                method="POST"
                class="branch-delete-form"
                onsubmit="
                    return confirm(
                        'Are you sure you want to delete this branch?'
                    );
                ">

                <input
                    type="hidden"
                    name="department_id"
                    value="<?php
                    echo (int)$department['id'];
                    ?>">

                <button
                    type="submit"
                    name="delete_department"
                    class="delete-card-btn"
                    title="Delete Branch">

                    <i class="fa-solid fa-trash"></i>

                </button>

            </form>

        </div>

        <?php endforeach; ?>

    </div>

</section>


<!-- =====================================================
     ACADEMIC YEAR PAGE
====================================================== -->

<?php elseif (!$selectedYear): ?>

<section class="module-section">


    <!-- SELECTION HEADER -->

    <div class="selection-header">

        <a
            href="students.php"
            class="back-button">

            <i class="fa-solid fa-arrow-left"></i>

            Branches

        </a>


        <div class="selected-info">

            <div class="selected-icon">

                <i class="fa-solid fa-building-columns"></i>

            </div>


            <div>

                <span>
                    Branch
                </span>


                <h2>

                    <?php
                    echo clean(
                        $selectedDepartment['department_name']
                    );
                    ?>

                </h2>


                <strong>

                    <?php
                    echo clean(
                        strtoupper(
                            $selectedDepartment['department_code']
                        )
                    );
                    ?>

                </strong>

            </div>

        </div>

    </div>


    <!-- SECTION HEADING -->

    <div class="section-heading">

        <div>

            <span class="section-label">
                ACADEMIC YEARS
            </span>

            <h2>
                Academic Years
            </h2>

            <p>
                Select an academic year to view students.
            </p>

        </div>


        <div class="count-badge">

            <i class="fa-solid fa-graduation-cap"></i>

            <?php echo count($academicYears); ?>

            Years

        </div>


        <button
            type="button"
            class="btn btn-primary"
            onclick="openPromotionModal()">

            <i class="fa-solid fa-arrow-up-right-dots"></i>

            Promote

        </button>

    </div>


    <!-- ACADEMIC YEAR CARDS -->

    <div class="year-grid">

        <?php foreach ($academicYears as $year): ?>

        <div class="year-card">


            <!-- YEAR LINK -->

            <a
                href="students.php?department=<?php
                echo (int)$departmentId;
                ?>&year=<?php
                echo (int)$year['id'];
                ?>"
                class="year-main">


                <div class="year-icon">

                    <i class="fa-solid fa-graduation-cap"></i>

                </div>


                <div class="year-content">

                    <span>
                        ACADEMIC YEAR
                    </span>


                    <h3>

                        <?php
                        echo clean(
                            $year['year_name']
                        );
                        ?>

                    </h3>


                    <p>

                        <i class="fa-solid fa-users"></i>

                        <?php
                        echo (int)$year['student_count'];
                        ?>

                        Students

                    </p>

                </div>


                <i class="fa-solid fa-arrow-right year-arrow"></i>

            </a>


            <!-- DELETE YEAR -->

            <form
                method="POST"
                class="year-delete-form"
                onsubmit="
                    return confirm(
                        'Are you sure you want to delete this academic year?'
                    );
                ">

                <input
                    type="hidden"
                    name="academic_year"
                    value="<?php
                    echo (int)$year['id'];
                    ?>">

                <button
                    type="submit"
                    name="delete_year"
                    class="delete-card-btn"
                    title="<?php echo ((int)($year['global_student_count'] ?? 0) > 0) ? 'Cannot delete: students are assigned to this academic year' : 'Delete Academic Year'; ?>"
                    <?php if ((int)($year['global_student_count'] ?? 0) > 0): ?>
                        disabled
                        aria-disabled="true"
                    <?php endif; ?>>

                    <i class="fa-solid fa-trash"></i>

                </button>

            </form>

        </div>

        <?php endforeach; ?>

    </div>

</section>


<!-- =====================================================
     STUDENT PAGE
====================================================== -->

<?php else: ?>

<section class="module-section">


    <!-- =================================================
         SELECTION HEADER
    ================================================== -->

    <div class="selection-header">

        <a
            href="students.php?department=<?php
            echo (int)$departmentId;
            ?>"
            class="back-button">

            <i class="fa-solid fa-arrow-left"></i>

            Academic Years

        </a>


        <div class="selected-info">

            <div class="selected-icon">

                <i class="fa-solid fa-users"></i>

            </div>


            <div>

                <span>

                    <?php
                    echo clean(
                        $selectedDepartment['department_code']
                    );
                    ?>

                </span>


                <h2>

                    <?php
                    echo clean(
                        $selectedDepartment['department_name']
                    );
                    ?>

                </h2>


                <strong>

                    <?php
                    echo clean(
                        $selectedYear['year_name']
                    );
                    ?>

                </strong>

            </div>

        </div>

    </div>


    <!-- =================================================
         STUDENT DETAILS
    ================================================== -->

    <div class="student-details">


        <!-- =================================================
             STUDENT TOOLBAR
        ================================================== -->

        <div class="student-toolbar">


            <div>

                <div class="student-title">

                    <div class="student-title-icon">

                        <i class="fa-solid fa-users"></i>

                    </div>


                    <div>

                        <h2>
                            Student Details
                        </h2>


                        <p>

                            <?php
                            echo count($students);
                            ?>

                            students found

                        </p>

                    </div>

                </div>

            </div>


            <div class="toolbar-actions">


                <!-- SEARCH -->

                <form
                    method="GET"
                    class="search-form">

                    <input
                        type="hidden"
                        name="department"
                        value="<?php
                        echo (int)$departmentId;
                        ?>">

                    <input
                        type="hidden"
                        name="year"
                        value="<?php
                        echo (int)$yearId;
                        ?>">

                    <div class="search-box">

                        <i class="fa-solid fa-magnifying-glass"></i>

                        <input
                            type="text"
                            name="search"
                            value="<?php
                            echo clean($search);
                            ?>"
                            placeholder="Search students...">

                    </div>

                </form>


                <!-- IMPORT -->
<?php if (
    $departmentId > 0 &&
    $yearId > 0
): ?>

<button
    type="button"
    class="student-action-btn import-btn"
    onclick="openImportModal()"
>
    <i class="fa-solid fa-file-import"></i>
    Import
</button>

<?php else: ?>

<button
    type="button"
    class="student-action-btn import-btn"
    disabled
    title="Select branch and academic year first"
>
    <i class="fa-solid fa-file-import"></i>
    Import
</button>

<?php endif; ?>

                <!-- EXPORT -->

                <a
    href="students.php?department=<?php echo (int)$departmentId; ?>&year=<?php echo (int)$yearId; ?>&semester=<?php echo (int)$semesterId; ?>&export=excel"
    class="student-action-btn export-btn">

    <i class="fa-solid fa-file-excel"></i>
    Export
</a>


                <!-- PRINT -->

                <button
                    type="button"
                    class="student-action-btn print-btn"
                    onclick="window.print()">

                    <i class="fa-solid fa-print"></i>

                    Print

                </button>


                <!-- ADD STUDENT -->

<button
    type="button"
    class="student-action-btn add-btn"
    onclick="openStudentModal()">

    <i class="fa-solid fa-user-plus"></i>

    Add Student

</button>


                <!-- DELETE ALL -->

                <form
                    method="POST"
                    style="display:inline;"
                    onsubmit="
                        return confirm(
                            'Are you sure you want to delete all students in this branch and academic year?'
                        );
                    ">

                    <button
                        type="submit"
                        name="delete_all_students"
                        class="student-action-btn delete-all-btn">

                        <i class="fa-solid fa-trash"></i>

                        Delete All

                    </button>

                </form>

            </div>

        </div>


        <!-- =================================================
             STUDENT TABLE
        ================================================== -->

        <div class="student-table-wrapper">

            <table class="student-table">


                <!-- TABLE HEADER -->

                <thead>

                    <tr>

                        <th>
                            S.No
                        </th>

                        <th>
                            PIN No
                        </th>

                        <th>
                            Name
                        </th>

                        <th>
                            Academic Year
                        </th>

                        <th>
                            Semester
                        </th>

                        <th>
                            Contact No
                        </th>

                        <th class="actions-column">
                            Actions
                        </th>

                    </tr>

                </thead>


                <!-- TABLE BODY -->

                <tbody>


                <?php if (count($students) > 0): ?>


                    <?php foreach ($students as $index => $student): ?>


                    <tr>


                        <!-- S.NO -->

                        <td>

                            <?php
                            echo $index + 1;
                            ?>

                        </td>


                        <!-- PIN -->

                        <td>

                            <span class="pin-badge">

                                <?php
                                echo clean(
                                    normalizePin(
                                        $student['pin_no']
                                    )
                                );
                                ?>

                            </span>

                        </td>


                        <!-- NAME -->

                        <td>

                            <div class="student-name">

                                <strong>

                                    <?php
                                    echo clean(
                                        $student['student_name']
                                    );
                                    ?>

                                </strong>

                            </div>

                        </td>


                        <!-- ACADEMIC YEAR -->

                        <td>

                            <span class="year-badge">

                                <?php
                                echo clean(
                                    $student['year_name']
                                );
                                ?>

                            </span>

                        </td>


                        <!-- SEMESTER -->

                        <td>

                            <span class="semester-badge">

                                <?php

                                if (
                                    !empty(
                                        $student['semester_name']
                                    )
                                ) {

                                    echo clean(
                                        $student['semester_name']
                                    );

                                } else {

                                    echo '—';

                                }

                                ?>

                            </span>

                        </td>


                        <!-- CONTACT -->

                        <td>

                            <?php if (!empty($student['contact_no'])): ?>

                                <span class="contact-number">

                                    <i class="fa-solid fa-phone"></i>

                                    <?php
                                    echo clean(
                                        $student['contact_no']
                                    );
                                    ?>

                                </span>

                            <?php else: ?>

                                <span class="no-value">
                                    —
                                </span>

                            <?php endif; ?>

                        </td>


                        <!-- ACTIONS -->

                        <td class="actions-column">


                            <button
    type="button"
    class="table-edit-btn"
    title="Edit Student"
    onclick='openEditStudentModal(
        <?php echo (int)$student["id"]; ?>,
        <?php echo json_encode($student["pin_no"] ?? ""); ?>,
        <?php echo json_encode($student["student_name"] ?? ""); ?>,
        <?php echo json_encode($student["contact_no"] ?? ""); ?>,
        <?php echo (int)$departmentId; ?>,
        <?php echo (int)$yearId; ?>,
        <?php echo (int)($student["semester_id"] ?? 0); ?>
    )'
>
    <i class="fa-solid fa-pen-to-square"></i>
</button>

                            <!-- DELETE STUDENT -->

                            <form
                                method="POST"
                                style="display:inline;"
                                onsubmit="
                                    return confirm(
                                        'Are you sure you want to delete this student?'
                                    );
                                ">

                                <input
                                    type="hidden"
                                    name="student_id"
                                    value="<?php
                                    echo (int)$student['id'];
                                    ?>">

                                <button
                                    type="submit"
                                    name="delete_student"
                                    class="table-delete-btn"
                                    title="Delete Student">

                                    <i class="fa-solid fa-trash"></i>

                                </button>

                            </form>


                        </td>


                    </tr>


                    <?php endforeach; ?>


                <?php else: ?>


                    <!-- NO STUDENTS -->

                    <tr>

                        <td
                            colspan="7"
                            class="no-data">

                            <div class="empty-icon">

                                <i class="fa-solid fa-user-slash"></i>

                            </div>


                            <h3>
                                No Students Found
                            </h3>


                            <p>
                                No students are registered
                                for this branch and academic year.
                            </p>

                        </td>

                    </tr>


                <?php endif; ?>


                </tbody>

            </table>

        </div>

    </div>

</section>


<?php endif; ?>


</div>

<!-- =====================================================
     ADD BRANCH MODAL
====================================================== -->

<div
    class="modal-overlay"
    id="departmentModal">

    <div class="modal">

        <div class="modal-header">

            <div>

                <div class="modal-icon">

                    <i
                        class="fa-solid fa-building-columns">
                    </i>

                </div>

                <h2>
                    Add Branch
                </h2>

                <p>
                    Create a new branch.
                </p>

            </div>

            <button
                type="button"
                onclick="closeDepartmentModal()">

                <i class="fa-solid fa-xmark"></i>

            </button>

        </div>


        <form method="POST" autocomplete="off" id="departmentForm">

            <div class="form-group">

                <label>
                    Branch Name
                </label>

                <div class="input-field">

                    <i class="fa-solid fa-building"></i>

                    <input
                        type="text"
                        name="department_name"
                        placeholder="Enter Branch name"
                        required>

                </div>

            </div>


            <div class="form-group">

                <label>
                    Branch Code
                </label>

                <div class="input-field">

                    <i class="fa-solid fa-code"></i>

                    <input
                        type="text"
                        name="department_code"
                        placeholder="Branch Code"
                        maxlength="20"
                        required
                        oninput="this.value=this.value.toUpperCase()">

                </div>

            </div>


            <div class="modal-actions">

                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeDepartmentModal()">

                    Cancel

                </button>

                <button
                    type="submit"
                    name="add_department"
                    class="btn btn-primary">

                    <i class="fa-solid fa-plus"></i>

                    Add Branch

                </button>

            </div>

        </form>

    </div>

</div>


<!-- =====================================================
     ADD ACADEMIC YEAR MODAL
====================================================== -->

<div
    class="modal-overlay"
    id="yearModal">

    <div class="modal">

        <div class="modal-header">

            <div>

                <div class="modal-icon">

                    <i
                        class="fa-solid fa-graduation-cap">
                    </i>

                </div>

                <h2>
                    Add Academic Year
                </h2>

                <p>
                    Add a year such as 1st Year or 2nd Year.
                </p>

            </div>

            <button
                type="button"
                onclick="closeYearModal()">

                <i class="fa-solid fa-xmark"></i>

            </button>

        </div>


        <form method="POST">

            <div class="form-group">

                <label>
                    Academic Year
                </label>

                <div class="input-field">

                    <i
                        class="fa-solid fa-calendar-days">
                    </i>

                    <select
                        name="year_name"
                        required>

                        <option value="">
                            Select Academic Year
                        </option>

                        <option value="1st Year">
                            1st Year
                        </option>

                        <option value="2nd Year">
                            2nd Year
                        </option>

                        <option value="3rd Year">
                            3rd Year
                        </option>

                        <option value="4th Year">
                            4th Year
                        </option>

                    </select>

                </div>

            </div>


            <div class="modal-actions">

                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeYearModal()">

                    Cancel

                </button>

                <button
                    type="submit"
                    name="add_year"
                    class="btn btn-primary">

                    <i class="fa-solid fa-plus"></i>

                    Add Year

                </button>

            </div>

        </form>

    </div>

</div>

<!-- =====================================================
     EDIT STUDENT MODAL
====================================================== -->

<div
    class="modal-overlay"
    id="editStudentModal">

    <div class="modal">

        <div class="modal-header">

            <div>

                <div class="modal-icon">

                    <i class="fa-solid fa-user-pen"></i>

                </div>

                <h2>
                    Edit Student
                </h2>

                <p>
                    Update the selected student's details.
                </p>

            </div>

            <button
                type="button"
                onclick="closeEditStudentModal()">

                <i class="fa-solid fa-xmark"></i>

            </button>

        </div>


        <form
            method="POST"
            autocomplete="off">


            <!-- =========================================
                 STUDENT ID
            ========================================== -->

            <input
                type="hidden"
                name="edit_student_id"
                id="edit_student_id">


            <!-- =========================================
                 DEPARTMENT
            ========================================== -->

            <input
                type="hidden"
                name="edit_department_id"
                value="<?php echo (int)$departmentId; ?>">


            <!-- =========================================
                 ACADEMIC YEAR
            ========================================== -->

            <input
                type="hidden"
                name="edit_academic_year"
                value="<?php echo (int)$yearId; ?>">


            <!-- =========================================
                 PIN
            ========================================== -->

            <div class="form-group">

                <label>
                    PIN No
                </label>

                <div class="input-field">

                    <i class="fa-solid fa-id-card"></i>

                    <input
                        type="text"
                        name="edit_pin_no"
                        id="edit_pin_no"
                        placeholder="24189-CS-001"
                        maxlength="15"
                        pattern="[0-9]{5}-[A-Z]{2,5}-[0-9]{3}"
                        title="Examples: 24189-CS-001, 24189-AMT-025"
                        required
                        oninput="
                            this.value = this.value.toUpperCase();
                        ">

                </div>

            </div>


            <!-- =========================================
                 STUDENT NAME
            ========================================== -->

            <div class="form-group">

                <label>
                    Student Name
                </label>

                <div class="input-field">

                    <i class="fa-solid fa-user"></i>

                    <input
                        type="text"
                        name="edit_student_name"
                        id="edit_student_name"
                        maxlength="150"
                        required>

                </div>

            </div>


            <!-- =========================================
                 SEMESTER
            ========================================== -->

            <div class="form-group">

                <label>
                    Semester
                </label>

                <div class="input-field">

                    <i class="fa-solid fa-layer-group"></i>

                    <select
                        name="edit_semester_id"
                        id="edit_semester_id"
                        required>

                        <option value="">
                            Select Semester
                        </option>

                        <?php

                        /*
                         * Get semesters.
                         *
                         * JavaScript will select the
                         * student's current semester.
                         *
                         * Since your database uses
                         * year_number:
                         *
                         * 1st Year -> 1,2
                         * 2nd Year -> 3,4
                         * 3rd Year -> 5,6
                         * 4th Year -> 7,8
                         */

                        $editYearNumber =
                            getAcademicYearNumber(
                                $selectedYear["year_name"] ?? ""
                            );

                        $editSemesterResult = false;

                        if ($editYearNumber > 0) {

                            $editSemesterStmt = $conn->prepare("
                                SELECT
                                    id,
                                    semester_name,
                                    semester_number,
                                    year_number
                                FROM semesters
                                WHERE status = 1
                                  AND year_number = ?
                                ORDER BY semester_number ASC
                            ");

                            if ($editSemesterStmt) {

                                $editSemesterStmt->bind_param(
                                    "i",
                                    $editYearNumber
                                );

                                $editSemesterStmt->execute();

                                $editSemesterResult =
                                    $editSemesterStmt->get_result();
                            }
                        }

                        if ($editSemesterResult):

                            while (
                                $semester =
                                mysqli_fetch_assoc(
                                    $editSemesterResult
                                )
                            ):

                        ?>

                            <option
                                value="<?php echo (int)$semester["id"]; ?>"
                                data-year="<?php echo (int)$semester["year_number"]; ?>">

                                <?php
                                echo clean(
                                    $semester["semester_name"]
                                );
                                ?>

                            </option>

                        <?php

                            endwhile;

                        endif;

                        ?>

                    </select>

                </div>

            </div>


            <!-- =========================================
                 CONTACT
            ========================================== -->

            <div class="form-group">

                <label>
                    Contact No
                </label>

                <div class="input-field">

                    <i class="fa-solid fa-phone"></i>

                    <input
                        type="tel"
                        name="edit_contact_no"
                        id="edit_contact_no"
                        maxlength="15">

                </div>

            </div>


            <!-- =========================================
                 BRANCH / YEAR
            ========================================== -->

            <div class="import-selected">

                <div>

                    <span>
                        Branch
                    </span>

                    <strong>

                        <?php
                        echo clean(
                            $selectedDepartment[
                                "department_code"
                            ]
                        );
                        ?>

                    </strong>

                </div>


                <div>

                    <span>
                        Academic Year
                    </span>

                    <strong>

                        <?php
                        echo clean(
                            $selectedYear[
                                "year_name"
                            ]
                        );
                        ?>

                    </strong>

                </div>

            </div>


            <!-- =========================================
                 BUTTONS
            ========================================== -->

            <div class="modal-actions">

                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeEditStudentModal()">

                    Cancel

                </button>


                <button
                    type="submit"
                    name="edit_student"
                    class="btn btn-primary">

                    <i class="fa-solid fa-floppy-disk"></i>

                    Update Student

                </button>

            </div>

        </form>

    </div>

</div>
<!-- =====================================================
     IMPORT STUDENTS MODAL
====================================================== -->

<div
    class="modal-overlay"
    id="importModal">

    <div class="modal import-modal">

        <div class="modal-header">

            <div>

                <div class="modal-icon">

                    <i
                        class="fa-solid fa-file-import">
                    </i>

                </div>

                <h2>
                    Import Students
                </h2>

                <p>
                    Import students into the selected branch and academic year.
                </p>

            </div>

            <button
                type="button"
                onclick="closeImportModal()">

                <i class="fa-solid fa-xmark"></i>

            </button>

        </div>


        <form
            method="POST"
            enctype="multipart/form-data">

            <input
                type="hidden"
                name="import_department_id"
                value="<?php
                echo $departmentId;
                ?>">

            <input
                type="hidden"
                name="import_academic_year"
                value="<?php
                echo $yearId;
                ?>">


            <div class="import-selected">

                <div>

                    <span>
                        Branch
                    </span>

                    <strong>
                        <?php
                        echo clean(
                            $selectedDepartment[
                                "department_code"
                            ]
                        );
                        ?>
                    </strong>

                </div>


                <div>

                    <span>
                        Academic Year
                    </span>

                    <strong>
                        <?php
                        echo clean(
                            $selectedYear[
                                "year_name"
                            ]
                        );
                        ?>
                    </strong>

                </div>

            </div>


            <!-- =================================================
                 SEMESTER
                 Only the two semesters for the selected year are shown.
            ================================================== -->
            <div class="form-group">
                <label for="import_semester_id">Semester</label>
                <div class="input-field">
                    <i class="fa-solid fa-layer-group"></i>
                    <select
                        name="import_semester_id"
                        id="import_semester_id"
                        required>
                        <option value="">Select Semester</option>
                        <?php foreach ($availableSemesters as $sem): ?>
                            <option
                                value="<?php echo (int)$sem["id"]; ?>"
                                <?php echo $semesterId === (int)$sem["id"] ? "selected" : ""; ?>>
                                <?php echo clean($sem["semester_name"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="file-upload">

    <i class="fa-solid fa-file-excel"></i>

    <h3>
        Select Excel File
    </h3>

    <p>
        Upload an .xlsx, .xls or .csv file
    </p>

    <input
        type="file"
        name="student_file"
        accept=".xlsx,.xls,.csv"
        required>

</div>



            <div class="modal-actions">

                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeImportModal()">

                    Cancel

                </button>

                <button
                    type="submit"
                    name="import_students"
                    class="btn btn-primary">

                    <i class="fa-solid fa-upload"></i>

                    Import Students

                </button>

            </div>

        </form>

    </div>

</div>


<!-- =====================================================
     ADD STUDENT MODAL
====================================================== -->

<div
    class="modal-overlay"
    id="studentModal">

    <div class="modal">

        <!-- HEADER -->

        <div class="modal-header">

            <div>

                <div class="modal-icon">
                    <i class="fa-solid fa-user-plus"></i>
                </div>

                <h2>
                    Add Student
                </h2>

                <p>
                    Add a student to the selected branch and academic year.
                </p>

            </div>

            <button
                type="button"
                onclick="closeStudentModal()">

                <i class="fa-solid fa-xmark"></i>

            </button>

        </div>


        <!-- FORM -->

        <form
            method="POST"
            action="students.php?department=<?php echo (int)$departmentId; ?>&year=<?php echo (int)$yearId; ?>"
            id="addStudentForm"
            autocomplete="off">

            <!-- IMPORTANT -->
            <input
                type="hidden"
                name="add_student"
                value="1">


            <!-- DEPARTMENT -->

            <input
                type="hidden"
                name="department_id"
                value="<?php echo (int)$departmentId; ?>">


            <!-- ACADEMIC YEAR -->

            <input
                type="hidden"
                name="academic_year"
                value="<?php echo (int)$yearId; ?>">


            <!-- PIN -->

            <div class="form-group">

                <label for="add_pin_no">
                    PIN No
                </label>

                <div class="input-field">

                    <i class="fa-solid fa-id-card"></i>

                    <input
                        type="text"
                        name="pin_no"
                        id="add_pin_no"
                        placeholder="24189-CS-001"
                        maxlength="15"
                        required
                        oninput="
                            this.value = this.value.toUpperCase();
                        ">

                </div>

            </div>


         <!-- STUDENT NAME -->

<div class="form-group">

    <label for="student_name">
        Student Name
    </label>

    <div class="input-field">

        <i class="fa-solid fa-user"></i>

        <input
            type="text"
            id="student_name"
            name="student_name"
            placeholder="Enter student name"
            required
            autocomplete="off"
        >

    </div>

</div>


            <!-- SEMESTER -->

            <div class="form-group">

                <label for="add_semester_id">
                    Semester
                </label>

                <div class="input-field">

                    <i class="fa-solid fa-layer-group"></i>

                    <select
                        name="semester_id"
                        id="add_semester_id"
                        required>

                        <option value="">
                            Select Semester
                        </option>

                        <?php

                        $selectedYearNumber = 0;

                        if (!empty($selectedYear)) {

                            $yearName = strtolower(
                                trim(
                                    $selectedYear["year_name"]
                                )
                            );


                            if (
                                strpos(
                                    $yearName,
                                    "1st"
                                ) !== false
                            ) {

                                $selectedYearNumber = 1;

                            } elseif (
                                strpos(
                                    $yearName,
                                    "2nd"
                                ) !== false
                            ) {

                                $selectedYearNumber = 2;

                            } elseif (
                                strpos(
                                    $yearName,
                                    "3rd"
                                ) !== false
                            ) {

                                $selectedYearNumber = 3;

                            } elseif (
                                strpos(
                                    $yearName,
                                    "4th"
                                ) !== false
                            ) {

                                $selectedYearNumber = 4;
                            }
                        }


                        if ($selectedYearNumber > 0) {

                            $semesterStmt = $conn->prepare("
                                SELECT
                                    id,
                                    semester_name,
                                    semester_number
                                FROM semesters
                                WHERE year_number = ?
                                  AND status = 1
                                ORDER BY semester_number ASC
                            ");


                            if ($semesterStmt) {

                                $semesterStmt->bind_param(
                                    "i",
                                    $selectedYearNumber
                                );

                                $semesterStmt->execute();

                                $semesterResult =
                                    $semesterStmt->get_result();


                                while (
                                    $semester =
                                    $semesterResult->fetch_assoc()
                                ) {

                                    ?>

                                    <option
                                        value="<?php echo (int)$semester["id"]; ?>">

                                        <?php
                                        echo clean(
                                            $semester["semester_name"]
                                        );
                                        ?>

                                    </option>

                                    <?php
                                }


                                $semesterStmt->close();
                            }
                        }

                        ?>

                    </select>

                </div>

            </div>


            <!-- CONTACT -->

            <div class="form-group">

                <label for="add_contact_no">
                    Contact No
                </label>

                <div class="input-field">

                    <i class="fa-solid fa-phone"></i>

                    <input
                        type="tel"
                        name="contact_no"
                        id="add_contact_no"
                        placeholder="Enter contact number"
                        maxlength="15"
                        autocomplete="off">

                </div>

            </div>


            <!-- SELECTED INFORMATION -->

            <div class="import-selected">

                <div>

                    <span>
                        Branch
                    </span>

                    <strong>

                        <?php
                        echo clean(
                            $selectedDepartment[
                                "department_code"
                            ] ?? ""
                        );
                        ?>

                    </strong>

                </div>


                <div>

                    <span>
                        Academic Year
                    </span>

                    <strong>

                        <?php
                        echo clean(
                            $selectedYear[
                                "year_name"
                            ] ?? ""
                        );
                        ?>

                    </strong>

                </div>

            </div>


            <!-- BUTTONS -->

            <div class="modal-actions">

                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeStudentModal()">

                    Cancel

                </button>


                <button
                    type="submit"
                    name="add_student"
                    value="1"
                    class="btn btn-primary">

                    <i class="fa-solid fa-plus"></i>

                    Add Student

                </button>

            </div>

        </form>

    </div>

</div>
<!-- =====================================================
     PROMOTION MODAL
====================================================== -->

<div
    class="modal-overlay"
    id="promotionModal">

    <div class="modal promotion-modal">

        <div class="modal-header">

            <div>

                <div class="modal-icon">

                    <i class="fa-solid fa-arrow-up-right-dots"></i>

                </div>

                <h2>
                    Promote Students
                </h2>

                <p>
                    Promote students from one academic year and semester
                    to the next.
                </p>

            </div>

            <button
                type="button"
                onclick="closePromotionModal()">

                <i class="fa-solid fa-xmark"></i>

            </button>

        </div>


        <form
            method="POST"
            autocomplete="off">


            <!-- =================================================
                 DEPARTMENT
            ================================================== -->

            <input
                type="hidden"
                name="department_id"
                value="<?php echo (int)$departmentId; ?>">


            <!-- =================================================
                 FROM YEAR
            ================================================== -->

            <div class="form-group">

                <label>
                    From Academic Year
                </label>

                <div class="input-field">

                    <i class="fa-solid fa-calendar-days"></i>

                    <select
                        name="from_year"
                        id="from_year"
                        required
                        onchange="updatePromotionSemesters()">

                        <option value="">
                            Select Academic Year
                        </option>

                        <?php foreach ($academicYears as $year): ?>

                            <option
                                value="<?php echo (int)$year["id"]; ?>"
                                data-year-name="<?php echo htmlspecialchars(
                                    $year["year_name"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ); ?>">

                                <?php
                                echo clean(
                                    $year["year_name"]
                                );
                                ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

            </div>


            <!-- =================================================
                 FROM SEMESTER
            ================================================== -->

            <div class="form-group">

                <label>
                    From Semester
                </label>

                <div class="input-field">

                    <i class="fa-solid fa-layer-group"></i>

                    <select
                        name="from_semester_id"
                        id="from_semester_id"
                        required>

                        <option value="">
                            Select From Semester
                        </option>

                    </select>

                </div>

            </div>


            <!-- =================================================
                 TO YEAR
            ================================================== -->

            <div class="form-group">

                <label>
                    To Academic Year
                </label>

                <div class="input-field">

                    <i class="fa-solid fa-calendar-plus"></i>

                    <select
                        name="to_year"
                        id="to_year"
                        required
                        onchange="updateToSemesters()">

                        <option value="">
                            Select Academic Year
                        </option>

                        <?php foreach ($academicYears as $year): ?>

                            <option
                                value="<?php echo (int)$year["id"]; ?>"
                                data-year-name="<?php echo htmlspecialchars(
                                    $year["year_name"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ); ?>">

                                <?php
                                echo clean(
                                    $year["year_name"]
                                );
                                ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

            </div>


            <!-- =================================================
                 TO SEMESTER
            ================================================== -->

            <div class="form-group">

                <label>
                    To Semester
                </label>

                <div class="input-field">

                    <i class="fa-solid fa-layer-group"></i>

                    <select
                        name="to_semester_id"
                        id="to_semester_id"
                        required>

                        <option value="">
                            Select To Semester
                        </option>

                    </select>

                </div>

            </div>


            <!-- =================================================
                 INFORMATION
            ================================================== -->

            <div class="promotion-info">

                <i class="fa-solid fa-circle-info"></i>

                <span>
                    Students can only be promoted to the next
                    valid semester.
                </span>

            </div>


            <!-- =================================================
                 ACTIONS
            ================================================== -->

            <div class="modal-actions">

                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closePromotionModal()">

                    Cancel

                </button>


                <button
                    type="submit"
                    name="promote_students"
                    class="btn btn-primary">

                    <i class="fa-solid fa-arrow-up"></i>

                    Promote Students

                </button>

            </div>

        </form>

    </div>

</div>
<script>
/* =========================================================
   ADD BRANCH MODAL
========================================================= */

function openDepartmentModal() {
    const modal = document.getElementById("departmentModal");

    if (!modal) {
        console.error("Branch modal not found: #departmentModal");
        return;
    }

    modal.classList.add("show");
    document.body.classList.add("modal-open");

    const form = document.getElementById("departmentForm");
    if (form) {
        form.reset();
    }

    const nameInput = modal.querySelector('input[name="department_name"]');
    if (nameInput) {
        setTimeout(function () {
            nameInput.focus();
        }, 100);
    }
}

function closeDepartmentModal() {
    const modal = document.getElementById("departmentModal");

    if (!modal) {
        return;
    }

    modal.classList.remove("show");
    document.body.classList.remove("modal-open");
}

/* Close branch modal when clicking the backdrop */
document.addEventListener("click", function (event) {
    const modal = document.getElementById("departmentModal");

    if (modal && event.target === modal) {
        closeDepartmentModal();
    }
});

/* Close branch modal with Escape */
document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
        const modal = document.getElementById("departmentModal");

        if (modal && modal.classList.contains("show")) {
            closeDepartmentModal();
        }
    }
});

/* =========================================================
   ADD ACADEMIC YEAR MODAL
========================================================= */

function openYearModal() {
    const modal = document.getElementById("yearModal");

    if (!modal) {
        console.error("Academic year modal not found: #yearModal");
        return;
    }

    modal.classList.add("show");
    document.body.classList.add("modal-open");

    const select = modal.querySelector('select[name="year_name"]');
    if (select) {
        select.value = "";
        setTimeout(function () {
            select.focus();
        }, 100);
    }
}

function closeYearModal() {
    const modal = document.getElementById("yearModal");

    if (!modal) {
        return;
    }

    modal.classList.remove("show");
    document.body.classList.remove("modal-open");
}

/* Close academic year modal when clicking the backdrop */
document.addEventListener("click", function (event) {
    const modal = document.getElementById("yearModal");

    if (modal && event.target === modal) {
        closeYearModal();
    }
});

/* Close academic year modal with Escape */
document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
        const modal = document.getElementById("yearModal");
        if (modal && modal.classList.contains("show")) {
            closeYearModal();
        }
    }
});

/* =========================================================
   ADD STUDENT MODAL
========================================================= */

function openStudentModal() {

    const modal =
        document.getElementById("studentModal");

    if (!modal) {

        console.error(
            "ERROR: #studentModal not found."
        );

        return;
    }


    /* =========================================
       GET FORM
    ========================================= */

    const form =
        document.getElementById("addStudentForm");


    if (form) {

        /* Reset only when opening */

        form.reset();

    }


    /* =========================================
       OPEN MODAL
    ========================================= */

    modal.classList.add("show");

    document.body.classList.add(
        "modal-open"
    );


    /* =========================================
       FOCUS PIN
    ========================================= */

    const pin =
        document.getElementById("add_pin_no");


    if (pin) {

        setTimeout(function () {

            pin.focus();

        }, 100);
    }
}


/* =========================================================
   CLOSE ADD STUDENT MODAL
========================================================= */

function closeStudentModal() {

    const modal =
        document.getElementById("studentModal");


    if (!modal) {
        return;
    }


    modal.classList.remove(
        "show"
    );


    document.body.classList.remove(
        "modal-open"
    );
}


/* =========================================================
   ADD STUDENT FORM
========================================================= */

document.addEventListener(
    "DOMContentLoaded",
    function () {

        const form =
            document.getElementById(
                "addStudentForm"
            );


        if (!form) {
            return;
        }


        form.addEventListener(
            "submit",
            function (event) {

                const nameInput = document.getElementById("student_name");

if (!nameInput) {
    console.error("Student name input not found: #student_name");
    return;
}


                /* Remove unnecessary spaces */

                nameInput.value =
                    nameInput.value.trim();


                /* =====================================
                   NAME VALIDATION
                ===================================== */

                if (
                    nameInput.value === ""
                ) {

                    event.preventDefault();

                    alert(
                        "Please enter the student name."
                    );

                    nameInput.focus();

                    return false;
                }


                console.log(
                    "Student Name:",
                    nameInput.value
                );

            }
        );

    }
);

/* =========================================================
   PROMOTION SEMESTER MAPPING

   1st Year  -> Semester 1, 2
   2nd Year  -> Semester 3, 4
   3rd Year  -> Semester 5, 6
   4th Year  -> Semester 7, 8
========================================================= */

const promotionSemesterMap = {

    "1st Year": [
        {
            number: 1,
            name: "1st Semester"
        },
        {
            number: 2,
            name: "2nd Semester"
        }
    ],

    "2nd Year": [
        {
            number: 3,
            name: "3rd Semester"
        },
        {
            number: 4,
            name: "4th Semester"
        }
    ],

    "3rd Year": [
        {
            number: 5,
            name: "5th Semester"
        },
        {
            number: 6,
            name: "6th Semester"
        }
    ],

    "4th Year": [
        {
            number: 7,
            name: "7th Semester"
        },
        {
            number: 8,
            name: "8th Semester"
        }
    ]

};


/* =========================================================
   GET SELECTED YEAR NAME
========================================================= */

function getSelectedYearName(selectId)
{
    const select =
        document.getElementById(selectId);

    if (!select) {
        return "";
    }

    const option =
        select.options[select.selectedIndex];

    if (!option) {
        return "";
    }

    return option.getAttribute(
        "data-year-name"
    ) || "";
}


/* =========================================================
   LOAD FROM SEMESTERS
========================================================= */

function updatePromotionSemesters()
{
    const semesterSelect =
        document.getElementById(
            "from_semester_id"
        );

    const yearName =
        getSelectedYearName(
            "from_year"
        );

    if (!semesterSelect) {
        return;
    }

    /* CLEAR */

    semesterSelect.innerHTML = `
        <option value="">
            Select From Semester
        </option>
    `;

    if (!yearName) {
        return;
    }

    const semesters =
        promotionSemesterMap[yearName];

    if (!semesters) {
        return;
    }

    /* ADD ONLY VALID SEMESTERS */

    semesters.forEach(function(semester) {

        const option =
            document.createElement("option");

        /*
         * Currently using semester number.
         */
        option.value =
            semester.number;

        option.textContent =
            semester.name;

        semesterSelect.appendChild(
            option
        );

    });
}


/* =========================================================
   LOAD TO SEMESTERS
========================================================= */

function updateToSemesters()
{
    const semesterSelect =
        document.getElementById(
            "to_semester_id"
        );

    const yearName =
        getSelectedYearName(
            "to_year"
        );

    if (!semesterSelect) {
        return;
    }

    /* CLEAR */

    semesterSelect.innerHTML = `
        <option value="">
            Select To Semester
        </option>
    `;

    if (!yearName) {
        return;
    }

    const semesters =
        promotionSemesterMap[yearName];

    if (!semesters) {
        return;
    }

    /* ADD ONLY VALID SEMESTERS */

    semesters.forEach(function(semester) {

        const option =
            document.createElement("option");

        option.value =
            semester.number;

        option.textContent =
            semester.name;

        semesterSelect.appendChild(
            option
        );

    });
}


/* =========================================================
   YEAR CHANGE EVENTS
========================================================= */

document.addEventListener(
    "DOMContentLoaded",
    function()
    {

        const fromYear =
            document.getElementById(
                "from_year"
            );

        const toYear =
            document.getElementById(
                "to_year"
            );


        if (fromYear) {

            fromYear.addEventListener(
                "change",
                updatePromotionSemesters
            );

        }


        if (toYear) {

            toYear.addEventListener(
                "change",
                updateToSemesters
            );

        }


        /* =================================================
           PROMOTION FORM VALIDATION
        ================================================= */

        const promotionForm =
            document.querySelector(
                "#promotionModal form"
            );

        if (!promotionForm) {
            return;
        }


        promotionForm.addEventListener(
            "submit",
            function(event)
            {

                const fromYear =
                    document.getElementById(
                        "from_year"
                    );

                const fromSemester =
                    document.getElementById(
                        "from_semester_id"
                    );

                const toYear =
                    document.getElementById(
                        "to_year"
                    );

                const toSemester =
                    document.getElementById(
                        "to_semester_id"
                    );


                /* =========================================
                   REQUIRED VALIDATION
                ========================================= */

                if (
                    !fromYear.value ||
                    !fromSemester.value ||
                    !toYear.value ||
                    !toSemester.value
                ) {

                    event.preventDefault();

                    alert(
                        "Please select academic year and semester."
                    );

                    return;
                }


                const fromYearName =
                    getSelectedYearName(
                        "from_year"
                    );

                const toYearName =
                    getSelectedYearName(
                        "to_year"
                    );


                const fromSem =
                    parseInt(
                        fromSemester.value,
                        10
                    );

                const toSem =
                    parseInt(
                        toSemester.value,
                        10
                    );


                /* =========================================
                   VALIDATE FROM SEMESTER
                ========================================= */

                const validFrom =
                    promotionSemesterMap[
                        fromYearName
                    ] || [];


                const fromValid =
                    validFrom.some(
                        function(item)
                        {
                            return (
                                item.number ===
                                fromSem
                            );
                        }
                    );


                /* =========================================
                   VALIDATE TO SEMESTER
                ========================================= */

                const validTo =
                    promotionSemesterMap[
                        toYearName
                    ] || [];


                const toValid =
                    validTo.some(
                        function(item)
                        {
                            return (
                                item.number ===
                                toSem
                            );
                        }
                    );


                if (
                    !fromValid ||
                    !toValid
                ) {

                    event.preventDefault();

                    alert(
                        "Invalid academic year and semester combination."
                    );

                    return;
                }


                /* =========================================
                   ONLY NEXT SEMESTER ALLOWED
                ========================================= */

                if (
                    toSem !==
                    fromSem + 1
                ) {

                    event.preventDefault();

                    alert(
                        "Students can only be promoted to the next semester."
                    );

                    return;
                }


                /* =========================================
                   PROMOTION IS VALID
                ========================================= */

            }
        );

    }
);

/* =========================================================
   PROMOTION MODAL
========================================================= */

function openPromotionModal()
{
    const modal =
        document.getElementById("promotionModal");

    if (!modal) {
        return;
    }

    modal.classList.add("show");

    document.body.classList.add("modal-open");
}


function closePromotionModal()
{
    const modal =
        document.getElementById("promotionModal");

    if (!modal) {
        return;
    }

    modal.classList.remove("show");

    document.body.classList.remove("modal-open");
}

    /* =========================================================
   IMPORT STUDENTS MODAL
========================================================= */

function openImportModal() {

    const modal = document.getElementById("importModal");

    if (!modal) {
        console.error("Import modal not found: #importModal");
        return;
    }

    modal.classList.add("show");

    document.body.classList.add("modal-open");
}


function closeImportModal() {

    const modal = document.getElementById("importModal");

    if (!modal) {
        return;
    }

    modal.classList.remove("show");

    document.body.classList.remove("modal-open");
}


/* Close when clicking outside the modal */

document.addEventListener("click", function (event) {

    const modal = document.getElementById("importModal");

    if (!modal) {
        return;
    }

    if (event.target === modal) {
        closeImportModal();
    }

});


/* Close with ESC */

document.addEventListener("keydown", function (event) {

    if (event.key === "Escape") {
        closeImportModal();
    }

});
document.addEventListener("DOMContentLoaded", function () {

    const sidebar = document.querySelector(".sidebar");
    const overlay = document.querySelector(".overlay");
    const menuButton = document.querySelector(".menu-button");
    const closeButton = document.querySelector(".sidebar-header button");

    // Open sidebar
    if (menuButton) {
        menuButton.addEventListener("click", function (e) {
            e.stopPropagation();

            sidebar.classList.add("show");
            overlay.classList.add("show");
        });
    }

    // Close sidebar using close button
    if (closeButton) {
        closeButton.addEventListener("click", function () {
            sidebar.classList.remove("show");
            overlay.classList.remove("show");
        });
    }

    // Close sidebar when clicking overlay / main content
    if (overlay) {
        overlay.addEventListener("click", function () {
            sidebar.classList.remove("show");
            overlay.classList.remove("show");
        });
    }

    // Close sidebar when clicking anywhere outside sidebar
    document.addEventListener("click", function (e) {

        if (
            sidebar &&
            sidebar.classList.contains("show") &&
            !sidebar.contains(e.target) &&
            !menuButton.contains(e.target)
        ) {
            sidebar.classList.remove("show");
            overlay.classList.remove("show");
        }

    });

});

/* =========================================================
   EDIT STUDENT MODAL
========================================================= */

function openEditStudentModal(
    studentId,
    registerNumber,
    studentName,
    contactNo,
    departmentId,
    yearId,
    semesterId
) {

    const modal = document.getElementById("editStudentModal");

    const studentIdInput =
        document.getElementById("edit_student_id");

    const pinInput =
        document.getElementById("edit_pin_no");

    const nameInput =
        document.getElementById("edit_student_name");

    const contactInput =
        document.getElementById("edit_contact_no");

    const semesterInput =
        document.getElementById("edit_semester_id");


    /* =========================================
       CHECK MODAL
    ========================================= */

    if (!modal) {
        console.error("editStudentModal not found.");
        return;
    }


    /* =========================================
       CHECK INPUTS
    ========================================= */

    if (!studentIdInput ||
        !pinInput ||
        !nameInput ||
        !contactInput ||
        !semesterInput) {

        console.error(
            "One or more edit student fields are missing."
        );

        return;
    }


    /* =========================================
       FILL DATA
    ========================================= */

    studentIdInput.value =
        studentId || "";

    pinInput.value =
        registerNumber || "";

    nameInput.value =
        studentName || "";

    contactInput.value =
        contactNo || "";

    semesterInput.value =
        semesterId || "";


    /* =========================================
       OPEN
    ========================================= */

    modal.classList.add("show");

    document.body.classList.add("modal-open");


    /* =========================================
       FOCUS
    ========================================= */

    setTimeout(function () {

        nameInput.focus();

    }, 100);
}


/* =========================================================
   CLOSE EDIT STUDENT MODAL
========================================================= */

function closeEditStudentModal() {

    const modal =
        document.getElementById("editStudentModal");

    if (modal) {

        modal.classList.remove("show");

    }

    document.body.classList.remove("modal-open");
}


/* =========================================================
   CLOSE ON OUTSIDE CLICK
========================================================= */

document.addEventListener("click", function (event) {

    const modal =
        document.getElementById("editStudentModal");

    if (!modal) {
        return;
    }

    if (event.target === modal) {

        closeEditStudentModal();

    }

});


/* =========================================================
   CLOSE WITH ESC
========================================================= */

document.addEventListener("keydown", function (event) {

    if (event.key === "Escape") {

        closeEditStudentModal();

    }

});
</script>
</body>
</html>