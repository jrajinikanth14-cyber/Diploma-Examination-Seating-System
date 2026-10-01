<?php

/* =========================================================
   DASHBOARD
   File: admin/dashboard.php
   ========================================================= */

session_start();

require_once "../config/db_connect.php";


/* =========================================================
   CACHE CONTROL
========================================================= */

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
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
   DELETE SINGLE NOTIFICATION
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["delete_notification"])
) {

    $notificationId = (int)($_POST["notification_id"] ?? 0);

    if ($notificationId <= 0) {

        echo "error";
        exit();

    }


    $stmt = $conn->prepare(
        "DELETE FROM notifications WHERE id = ?"
    );


    if (!$stmt) {

        echo "error";
        exit();

    }


    $stmt->bind_param(
        "i",
        $notificationId
    );


    if ($stmt->execute()) {

        echo "success";

    } else {

        echo "error";

    }


    $stmt->close();

    exit();

}


/* =========================================================
   MARK ALL NOTIFICATIONS AS READ
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["mark_notifications_read"])
) {

    $stmt = $conn->prepare(
        "
        UPDATE notifications
        SET is_read = 1
        WHERE is_read = 0
        "
    );


    if ($stmt && $stmt->execute()) {

        echo "success";

    } else {

        echo "error";

    }


    if ($stmt) {

        $stmt->close();

    }


    exit();

}


/* =========================================================
   ADMIN DETAILS
========================================================= */

$adminId = (int)$_SESSION["admin"];


$stmt = $conn->prepare(
    "
    SELECT
        full_name,
        email,
        mobile,
        profile_photo
    FROM users
    WHERE id = ?
    LIMIT 1
    "
);


$stmt->bind_param(
    "i",
    $adminId
);


$stmt->execute();


$adminResult = $stmt->get_result();


$admin = $adminResult->fetch_assoc();


$stmt->close();


/* =========================================================
   SAFETY DEFAULTS
========================================================= */

if (!$admin) {

    $admin = [
        "full_name"     => "Administrator",
        "email"         => "",
        "mobile"        => "",
        "profile_photo" => ""
    ];

}


/* =========================================================
   DASHBOARD COUNTS
========================================================= */


/* =========================================================
   STUDENTS
========================================================= */

$result = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total FROM students"
);

$studentData =
    $result
    ? mysqli_fetch_assoc($result)
    : null;

$studentCount =
    (int)($studentData["total"] ?? 0);


/* =========================================================
   DEPARTMENTS
========================================================= */

$result = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total FROM departments"
);

$departmentData =
    $result
    ? mysqli_fetch_assoc($result)
    : null;

$departmentCount =
    (int)($departmentData["total"] ?? 0);


/* =========================================================
   ACADEMIC YEARS
========================================================= */

$result = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total FROM academic_years"
);

$yearData =
    $result
    ? mysqli_fetch_assoc($result)
    : null;

$yearCount =
    (int)($yearData["total"] ?? 0);


/* =========================================================
   EXAMINATION HALLS
========================================================= */

$result = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total FROM halls"
);

$hallData =
    $result
    ? mysqli_fetch_assoc($result)
    : null;

$hallCount =
    (int)($hallData["total"] ?? 0);


/* =========================================================
   SEAT ALLOCATION
========================================================= */

$result = mysqli_query(
    $conn,
    "
    SELECT COUNT(*) AS total
    FROM allocation_seats ase
    INNER JOIN allocation_batches ab
        ON ab.id = ase.allocation_id
    WHERE ab.status = 'confirmed'
    "
);

$seatData =
    $result
    ? mysqli_fetch_assoc($result)
    : null;

$seatCount =
    (int)($seatData["total"] ?? 0);


/* =========================================================
   ATTENDANCE
========================================================= */

$result = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total FROM attendance"
);

$attendanceData =
    $result
    ? mysqli_fetch_assoc($result)
    : null;

$attendanceCount =
    (int)($attendanceData["total"] ?? 0);


/* =========================================================
   PROFILE IMAGE
========================================================= */

$profilePhoto =
    "../assets/uploads/default-profile.png";


if (
    !empty($admin["profile_photo"]) &&
    file_exists(
        "../assets/uploads/" .
        $admin["profile_photo"]
    )
) {

    $profilePhoto =
        "../assets/uploads/" .
        $admin["profile_photo"];

}


/* =========================================================
   UNREAD NOTIFICATION COUNT
========================================================= */

$notificationCountQuery = mysqli_query(
    $conn,
    "
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE is_read = 0
    "
);


if ($notificationCountQuery) {

    $countData =
        mysqli_fetch_assoc(
            $notificationCountQuery
        );

    $unreadNotificationCount =
        (int)($countData["total"] ?? 0);

} else {

    $unreadNotificationCount = 0;

}


/* =========================================================
   EXISTING COUNT VARIABLE
========================================================= */

$count = [
    "total" => $unreadNotificationCount
];


/* =========================================================
   TOTAL NOTIFICATION COUNT
========================================================= */

$totalNotificationQuery = mysqli_query(
    $conn,
    "
    SELECT COUNT(*) AS total
    FROM notifications
    "
);


if ($totalNotificationQuery) {

    $totalNotificationData =
        mysqli_fetch_assoc(
            $totalNotificationQuery
        );

    $totalNotificationCount =
        (int)(
            $totalNotificationData["total"]
            ?? 0
        );

} else {

    $totalNotificationCount = 0;

}


/* =========================================================
   GET ALL NOTIFICATIONS
========================================================= */

/*
 * IMPORTANT:
 *
 * DO NOT USE LIMIT 10 HERE.
 *
 * All notifications must be loaded into the HTML.
 *
 * JavaScript will show only the latest 10 initially.
 *
 * Example:
 *
 * 15 notifications in database
 *      ↓
 * PHP loads all 15
 *      ↓
 * JS displays first 10
 *      ↓
 * View All displays all 15
 */

$notifications = mysqli_query(
    $conn,
    "
    SELECT
        id,
        type,
        title,
        message,
        is_read,
        created_at
    FROM notifications
    ORDER BY created_at DESC, id DESC
    "
);


/* =========================================================
   NOTIFICATION QUERY SAFETY
========================================================= */

if (!$notifications) {

    $notifications = false;

}

?>
<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
name="viewport"
content="width=device-width, initial-scale=1.0">

<title>

Dashboard | Examination Seating Management System

</title>

<link
rel="stylesheet"
href="../assets/css/dashboard.css">

<link
href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
rel="stylesheet">

<link
rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

</head>

<body>

<div class="overlay" id="overlay"></div>

<div class="sidebar" id="sidebar">

    <div class="sidebar-header">

        <h2>ESMS</h2>

        <button id="closeSidebar">
            <i class="fa-solid fa-xmark"></i>
        </button>

    </div>

    <ul class="menu">

    <li class="active">
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

</div>

<!-- ===================================
        MAIN CONTENT
=================================== -->

<div class="main-content">
    <!-- ==========================================
                TOP HEADER
========================================== -->

<header class="topbar">

    <div class="left-top">

        <button id="menuBtn">

            <i class="fa-solid fa-bars"></i>

        </button>

        <h1>Dashboard</h1>

    </div>

    <div class="center-top">

        <div class="search-box">

    <i class="fa-solid fa-magnifying-glass"></i>

    <input
        type="text"
        id="dashboardSearch"
        placeholder="Search pages...">

</div>
    </div>

    <div class="right-top">

        <div class="datetime">

            <div id="currentDate"></div>

            <div id="currentTime"></div>

        </div>

      <div class="notification">

    <button id="bellBtn">

        <i class="fa-regular fa-bell"></i>

        <?php if($count['total']>0){ ?>

        <span class="notification-badge">

            <?php echo $count['total']; ?>

        </span>

        <?php } ?>

     </button>

     </div>
        <!-- Profile -->
     <div class="profile">

     <!-- Topbar Profile -->
     <img
        src="<?php echo $profilePhoto; ?>"
        id="profileImage"
        alt="Admin">

     <!-- Hidden File Input -->
     <input
        type="file"
        id="profileInput"
        accept="image/*"
        hidden>

     <!-- Dropdown -->
     <div class="profile-dropdown" id="profileDropdown">

        <div class="profile-header">

            <div class="profile-photo-box">

                <img
                    src="<?php echo $profilePhoto; ?>"
                    id="dropdownProfileImage"
                    alt="Profile">

                <label for="profileInput" class="edit-profile-icon">
                    <i class="fa-solid fa-camera"></i>
                </label>

            </div>

            <h3><?php echo htmlspecialchars($admin["full_name"]); ?></h3>

            <p><?php echo htmlspecialchars($admin["email"]); ?></p>

            <p><?php echo htmlspecialchars($admin["mobile"]); ?></p>

        </div>

        <a href="settings.php">
            <i class="fa-solid fa-gear"></i>
            Settings
        </a>

        <a href="../auth/logout.php" class="logout-link">
    <i class="fa-solid fa-right-from-bracket"></i>
    <span>Logout</span>
</a>

    </div>

  </div>
 </div>

 </header>
 <!-- ==========================================
        NOTIFICATION PANEL
========================================== -->

<div
    class="notification-overlay"
    id="notificationOverlay">
</div>


<div
    class="notification-panel"
    id="notificationPanel"
    data-total="<?php echo $totalNotificationCount; ?>">


    <!-- ==========================================
            HEADER
    =========================================== -->

    <div class="notification-header">

        <h2>Notifications</h2>

        <button
            type="button"
            id="closeNotification"
            aria-label="Close Notifications">

            <i class="fa-solid fa-xmark"></i>

        </button>

    </div>


    <!-- ==========================================
            NOTIFICATION TOP
    =========================================== -->

    <div class="notification-top">

        <span id="notificationHeading">

            Recent Notifications

        </span>


        <!-- ==========================================
                MARK ALL AS READ
        =========================================== -->

        <button
            type="button"
            id="markAllRead"
            <?php
            echo ($unreadNotificationCount > 0)
                ? ''
                : 'style="display:none;"';
            ?>>

            Mark all as read

        </button>


        <!-- ==========================================
                CLEAR ALL
        =========================================== -->

        <button
            type="button"
            id="clearAllNotifications"
            <?php
            echo ($totalNotificationCount > 0)
                ? ''
                : 'style="display:none;"';
            ?>>

            Clear All

        </button>

    </div>


    <!-- ==========================================
            NOTIFICATION LIST
    =========================================== -->

    <div
        class="notification-list"
        id="notificationList">


        <?php

        if (
            $notifications !== false &&
            mysqli_num_rows($notifications) > 0
        ) {

            while (
                $row = mysqli_fetch_assoc(
                    $notifications
                )
            ) {


                /* ==========================================
                        NOTIFICATION TYPE
                =========================================== */

                $type = strtolower(
                    trim(
                        $row["type"] ?? ""
                    )
                );


                /* ==========================================
                        DEFAULT ICON
                =========================================== */

                $icon = "student-added";
                $fa = "fa-bell";


                /* ==========================================
                        ICON MAPPING
                =========================================== */

                switch ($type) {


                    /* =========================
                       STUDENTS
                    ========================= */

                    case "student_added":

                        $icon = "student-added";
                        $fa = "fa-user-plus";

                        break;


                    case "student_imported":

                        $icon = "student-imported";
                        $fa = "fa-file-import";

                        break;


                    case "student_exported":

                        $icon = "student-exported";
                        $fa = "fa-file-export";

                        break;


                    case "student_deleted":

                        $icon = "student-deleted";
                        $fa = "fa-user-xmark";

                        break;


                    case "students_deleted":

                        $icon = "students-deleted";
                        $fa = "fa-trash";

                        break;


                    case "student_updated":

                        $icon = "student-updated";
                        $fa = "fa-user-pen";

                        break;


                    /* =========================
                       DEPARTMENTS
                    ========================= */

                    case "department_added":

                        $icon = "department-added";
                        $fa = "fa-building-columns";

                        break;


                    case "department_deleted":

                        $icon = "department-deleted";
                        $fa = "fa-trash";

                        break;


                    /* =========================
                       HALLS
                    ========================= */

                    case "hall_added":

                        $icon = "hall-added";
                        $fa = "fa-building";

                        break;


                    case "hall_updated":

                        $icon = "hall-updated";
                        $fa = "fa-pen-to-square";

                        break;


                    case "hall_deleted":

                        $icon = "hall-deleted";
                        $fa = "fa-trash";

                        break;


                    /* =========================
                       EXAM
                    ========================= */

                    case "exam":

                        $icon = "exam";
                        $fa = "fa-calendar-days";

                        break;


                    /* =========================
                       SEAT
                    ========================= */

                    case "seat":

                        $icon = "seat";
                        $fa = "fa-chair";

                        break;


                    /* =========================
                       ATTENDANCE
                    ========================= */

                    case "attendance":

                        $icon = "attendance";
                        $fa = "fa-clipboard-check";

                        break;


                    /* =========================
                       DEFAULT
                    ========================= */

                    default:

                        $icon = "student-added";
                        $fa = "fa-bell";

                        break;

                }


                /* ==========================================
                        READ / UNREAD
                =========================================== */

                $isUnread = (
                    isset($row["is_read"]) &&
                    (int)$row["is_read"] === 0
                );

                ?>


                <!-- ======================================
                        NOTIFICATION ITEM
                ======================================= -->

                <div
                    class="notification-item <?php
                        echo $isUnread
                            ? "unread"
                            : "";
                    ?>"
                    data-id="<?php
                        echo (int)$row["id"];
                    ?>">


                    <!-- ==================================
                            ICON
                    =================================== -->

                    <div
                        class="notification-icon <?php
                            echo htmlspecialchars(
                                $icon
                            );
                        ?>">

                        <i
                            class="fa-solid <?php
                                echo htmlspecialchars(
                                    $fa
                                );
                            ?>">
                        </i>

                    </div>


                    <!-- ==================================
                            CONTENT
                    =================================== -->

                    <div class="notification-content">

                        <h4>

                            <?php

                            echo htmlspecialchars(
                                $row["title"]
                                ?? "Notification"
                            );

                            ?>

                        </h4>


                        <p>

                            <?php

                            echo htmlspecialchars(
                                $row["message"]
                                ?? ""
                            );

                            ?>

                        </p>


                        <div class="notification-time">

                            <?php

                            if (
                                !empty(
                                    $row["created_at"]
                                )
                            ) {

                                $notificationTime =
                                    strtotime(
                                        $row["created_at"]
                                    );


                                if (
                                    $notificationTime !== false
                                ) {

                                    echo date(
                                        "d M Y h:i A",
                                        $notificationTime
                                    );

                                } else {

                                    echo "—";

                                }

                            } else {

                                echo "—";

                            }

                            ?>

                        </div>

                    </div>


                    <!-- ==================================
                            UNREAD DOT
                    =================================== -->

                    <?php if ($isUnread) { ?>

                        <div
                            class="notification-dot">
                        </div>

                    <?php } ?>


                    <!-- ==================================
                            DELETE BUTTON
                    =================================== -->

                    <button
                        type="button"
                        class="delete-notification"
                        data-id="<?php
                            echo (int)$row["id"];
                        ?>"
                        title="Delete notification">

                        <i class="fa-solid fa-trash"></i>

                    </button>


                </div>


                <?php

            }


        } else {

        ?>

            <!-- ==========================================
                    NO NOTIFICATIONS
            =========================================== -->

            <div
                class="notification-item no-notifications">

                <div
                    class="notification-content">

                    <h4>
                        No Notifications
                    </h4>

                    <p>
                        No notifications found.
                    </p>

                </div>

            </div>

        <?php

        }

        ?>

    </div>


    <!-- ==========================================
            NOTIFICATION FOOTER
    =========================================== -->

    <div class="notification-footer">

        <button
            type="button"
            id="toggleNotifications"
            data-expanded="false"
            >

            View All Notifications

        </button>

    </div>


</div>
<!-- ==========================================
                WELCOME CARD
========================================== -->

<section class="welcome-card">

    <div class="welcome-text">
        <h2>
            Welcome Back,
            <?php echo htmlspecialchars($admin["full_name"]); ?> 👋
        </h2>

        <p>
           A web-based system that automates examination seating allocation,
            To ensure secure, fair, and safe examinations
        </p>
    </div>

    <div class="welcome-image">
        <img src="../assets/images/banner.png" alt="Dashboard">
    </div>

</section>
<!-- ==========================================
            DASHBOARD STATISTICS
========================================== -->

<section class="dashboard-cards">

    <div class="card blue">

        <div class="card-icon">

            <i class="fa-solid fa-user-graduate"></i>

        </div>

        <div class="card-info">

            <h4>Total Students</h4>

            <h2><?php echo $studentCount; ?></h2>
            <p>Registered Students</p>

        </div>

    </div>

    <div class="card green">

        <div class="card-icon">

            <i class="fa-solid fa-building"></i>

        </div>

        <div class="card-info">

            <h4>Departments</h4>

            <h2><?php echo $departmentCount; ?></h2>

            <p>Total Departments</p>

        </div>

    </div>

    <div class="card orange">

        <div class="card-icon">

            <i class="fa-solid fa-layer-group"></i>

        </div>

        <div class="card-info">

            <h4>Academic Years</h4>

            <h2><?php echo $yearCount; ?></h2>

            <p>Year Groups</p>

        </div>

    </div>

    <div class="card purple">

        <div class="card-icon">

            <i class="fa-solid fa-school"></i>

        </div>

        <div class="card-info">

            <h4>Exam Halls</h4>

            <h2><?php echo $hallCount; ?></h2>

            <p>Available Halls</p>

        </div>

    </div>



    <div class="card dark">

        <div class="card-icon">

            <i class="fa-solid fa-chair"></i>

        </div>

        <div class="card-info">

            <h4>Seat Allocation</h4>

            <h2><?php echo $seatCount; ?></h2>

            <p>Allocated Seats</p>

        </div>

    </div>

    <div class="card pink">

        <div class="card-icon">

            <i class="fa-solid fa-clipboard-check"></i>

        </div>

        <div class="card-info">

            <h4>Attendance</h4>

            <h2><?php echo $attendanceCount; ?></h2>

            <p>Attendance Records</p>

        </div>

    </div>

</section>
<!-- =====================================
        DASHBOARD STATISTICS CHART
===================================== -->

<section class="dashboard-row">

    <div class="chart-card">

        <div class="card-header">
            <h3>
                <i class="fa-solid fa-chart-column"></i>
                Dashboard Statistics
            </h3>
        </div>

        <canvas id="dashboardChart"></canvas>

    </div>

</section>

<!-- =====================================
            QUICK ACTIONS
===================================== -->

<section class="quick-actions">

    <div class="section-title">

        <h2>

            <i class="fa-solid fa-bolt"></i>

            Quick Actions

        </h2>

    </div>

    <div class="action-grid">

        <a href="students.php" class="action-card">

            <i class="fa-solid fa-user-plus"></i>

            <h4>Add Student</h4>

        </a>


        <a href="halls.php" class="action-card">

            <i class="fa-solid fa-school"></i>

            <h4>Add Hall</h4>

        </a>

        <a href="seat_allocation.php" class="action-card">

            <i class="fa-solid fa-chair"></i>

            <h4>Generate Seating</h4>

        </a>

        <a href="attendance.php" class="action-card">

            <i class="fa-solid fa-clipboard-check"></i>

            <h4>Attendance Sheet</h4>

        </a>

        <a href="reports.php" class="action-card">

            <i class="fa-solid fa-chart-line"></i>

            <h4>Reports</h4>

        </a>

        <a href="settings.php" class="action-card">

            <i class="fa-solid fa-gear"></i>

            <h4>Settings</h4>

        </a>

    </div>

</section>
<!-- ==========================================
     UPCOMING EXAMINATIONS
========================================== -->

<section class="exam-section">

    <div class="section-title">

        <h2>
            <i class="fa-solid fa-calendar-check"></i>
            Upcoming Examinations
        </h2>

    </div>

    <div class="table-responsive">

        <table class="exam-table">

            <thead>

                <tr>
                    <th>Branch</th>
                    <th>Year</th>
                    <th>Semester</th>
                    <th>Subject</th>
                    <th>Subject Code</th>
                    <th>Exam Date</th>
                    <th>Exam Time</th>
                    <th>Status</th>
                </tr>

            </thead>

            <tbody>

<?php

/* =========================================================
   UPCOMING EXAMINATIONS

   Uses:
   examinations
   departments
   academic_years
   semesters

   NO exam_sessions table is used.
   ========================================================= */

$upcomingExamQuery = mysqli_query($conn, "

    SELECT
        e.id,
        e.subject_name,
        e.subject_code,
        e.exam_date,
        e.exam_time,

        d.department_name,
        d.department_code,

        ay.year_name,

        s.semester_number,
        s.semester_name

    FROM examinations e

    LEFT JOIN departments d
        ON d.id = e.department_id

    LEFT JOIN academic_years ay
        ON ay.id = e.academic_year_id

    LEFT JOIN semesters s
        ON s.id = e.semester_id

    WHERE e.exam_date >= CURDATE()

    ORDER BY
        e.exam_date ASC,
        e.exam_time ASC

    LIMIT 10

");


/* =========================================================
   DATABASE ERROR
========================================================= */

if (!$upcomingExamQuery) {

?>

<tr>

    <td colspan="8" class="no-data">

        <i class="fa-solid fa-triangle-exclamation"></i>

        <strong>Database Error</strong>

        <br>

        <?php
        echo htmlspecialchars(mysqli_error($conn));
        ?>

    </td>

</tr>

<?php

}


/* =========================================================
   EXAMINATIONS FOUND
========================================================= */

elseif (mysqli_num_rows($upcomingExamQuery) > 0) {

    while ($row = mysqli_fetch_assoc($upcomingExamQuery)) {

?>

<tr>

    <!-- ==========================================
         BRANCH
    =========================================== -->

    <td>

        <strong class="exam-branch">

            <?php
            echo htmlspecialchars(
                $row["department_name"] ?? "—"
            );
            ?>

        </strong>

        <?php if (!empty($row["department_code"])) { ?>

            <small class="exam-branch-code">

                (
                <?php
                echo htmlspecialchars(
                    $row["department_code"]
                );
                ?>
                )

            </small>

        <?php } ?>

    </td>


    <!-- ==========================================
         YEAR
    =========================================== -->

    <td>

        <?php
        echo htmlspecialchars(
            $row["year_name"] ?? "—"
        );
        ?>

    </td>


    <!-- ==========================================
         SEMESTER
    =========================================== -->

    <td>

        <?php

        if (
            isset($row["semester_number"]) &&
            $row["semester_number"] !== null &&
            $row["semester_number"] !== ""
        ) {

            echo "Semester " .
                 htmlspecialchars(
                     $row["semester_number"]
                 );

        } elseif (
            isset($row["semester_name"]) &&
            !empty($row["semester_name"])
        ) {

            echo htmlspecialchars(
                $row["semester_name"]
            );

        } else {

            echo "—";

        }

        ?>

    </td>


    <!-- ==========================================
         SUBJECT
    =========================================== -->

    <td>

        <strong class="exam-subject">

            <?php
            echo htmlspecialchars(
                $row["subject_name"] ?? "—"
            );
            ?>

        </strong>

    </td>


    <!-- ==========================================
         SUBJECT CODE
    =========================================== -->

    <td>

        <span class="subject-code">

            <?php
            echo htmlspecialchars(
                $row["subject_code"] ?? "—"
            );
            ?>

        </span>

    </td>


    <!-- ==========================================
         EXAM DATE
    =========================================== -->

    <td>

        <?php

        if (!empty($row["exam_date"])) {

            $examDate = strtotime($row["exam_date"]);

            if ($examDate !== false) {

                echo date(
                    "d M Y",
                    $examDate
                );

            } else {

                echo "—";

            }

        } else {

            echo "—";

        }

        ?>

    </td>


    <!-- ==========================================
         EXAM TIME
    =========================================== -->

    <td>

        <?php

        if (!empty($row["exam_time"])) {

            $examTime = strtotime($row["exam_time"]);

            if ($examTime !== false) {

                echo date(
                    "h:i A",
                    $examTime
                );

            } else {

                echo "—";

            }

        } else {

            echo "—";

        }

        ?>

    </td>


    <!-- ==========================================
         STATUS
    =========================================== -->

    <td>

        <span class="status scheduled">

            Scheduled

        </span>

    </td>

</tr>

<?php

    }

}


/* =========================================================
   NO UPCOMING EXAMINATIONS
========================================================= */

else {

?>

<tr>

    <td colspan="8" class="no-data">

        <i class="fa-solid fa-calendar-xmark"></i>

        No Upcoming Examinations Found

    </td>

</tr>

<?php

}

?>

            </tbody>

        </table>

    </div>

</section>

<!-- ==========================================
                FOOTER
========================================== -->

<footer class="dashboard-footer">

    <p>

        © <?php echo date("Y"); ?>

        Examination Seating Management System

    </p>

    <span>

        Administrator Dashboard

    </span>

</footer>

</div>

<!-- ==========================================
            JAVASCRIPT VARIABLES
========================================== -->

<script>
const studentCount = <?php echo $studentCount; ?>;
const departmentCount = <?php echo $departmentCount; ?>;
const yearCount = <?php echo $yearCount; ?>;
const hallCount = <?php echo $hallCount; ?>;
const seatCount = <?php echo $seatCount; ?>;
const attendanceCount = <?php echo $attendanceCount; ?>;
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>

const totalNotifications =
<?php echo $count['total']; ?>;

</script>
<script src="../assets/js/dashboard.js"></script>
<script>
window.addEventListener("pageshow", function (event) {
    if (event.persisted || performance.getEntriesByType("navigation")[0]?.type === "back_forward") {
        window.location.reload();
    }
});
</script>
</body>

</html>