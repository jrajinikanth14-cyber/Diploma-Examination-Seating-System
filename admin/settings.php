<?php
/* =========================================================
   ESMS SETTINGS MODULE
   File: admin/settings.php

   Settings menu:
   1. Institution Details
   2. Admin Profile
   3. Security
   4. About
   5. Logout
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

function postValue(string $key, string $default = ""): string
{
    return trim((string)($_POST[$key] ?? $default));
}

/* CSRF token */
if (empty($_SESSION["settings_csrf"])) {
    $_SESSION["settings_csrf"] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION["settings_csrf"];

/* Current administrator */
$adminId = (int)$_SESSION["admin"];
$stmt = $conn->prepare("SELECT id, full_name, email, mobile, profile_photo, password FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit();
}

/* Settings storage. This does not use the deleted legacy seat_allocation tables. */
$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_system_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$defaults = [
    "institution_name" => "",
    "institution_code" => "",
    "institution_address" => "",
    "institution_city" => "",
    "institution_state" => "Telangana",
    "institution_pincode" => "",
    "institution_phone" => "",
    "institution_email" => "",
    "institution_website" => "",
    "institution_logo" => ""
];

foreach ($defaults as $key => $value) {
    $stmt = $conn->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    if ($stmt) {
        $stmt->bind_param("ss", $key, $value);
        $stmt->execute();
        $stmt->close();
    }
}

function getSettings(mysqli $conn): array
{
    $settings = [];
    $result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $settings[$row["setting_key"]] = $row["setting_value"];
        }
    }
    return $settings;
}

function saveSetting(mysqli $conn, string $key, string $value): bool
{
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    if (!$stmt) return false;
    $stmt->bind_param("ss", $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

$settings = getSettings($conn);
$message = "";
$messageType = "success";
$openSection = "";

/* POST actions */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals($csrfToken, (string)($_POST["csrf_token"] ?? ""))) {
        $message = "Invalid security token. Please refresh the page and try again.";
        $messageType = "error";
    } else {
        $action = (string)($_POST["action"] ?? "");

        /* Institution Details */
        if ($action === "save_institution") {
            $openSection = "institution";

            $values = [
                "institution_name" => postValue("institution_name"),
                "institution_code" => postValue("institution_code"),
                "institution_address" => postValue("institution_address"),
                "institution_city" => postValue("institution_city"),
                "institution_state" => postValue("institution_state", "Telangana"),
                "institution_pincode" => postValue("institution_pincode"),
                "institution_phone" => postValue("institution_phone"),
                "institution_email" => postValue("institution_email"),
                "institution_website" => postValue("institution_website")
            ];

            if ($values["institution_email"] !== "" && !filter_var($values["institution_email"], FILTER_VALIDATE_EMAIL)) {
                $message = "Please enter a valid institution email address.";
                $messageType = "error";
            } elseif ($values["institution_website"] !== "" && !filter_var($values["institution_website"], FILTER_VALIDATE_URL)) {
                $message = "Please enter a valid website URL, including https://.";
                $messageType = "error";
            } else {
                $ok = true;
                foreach ($values as $key => $value) {
                    if (!saveSetting($conn, $key, $value)) {
                        $ok = false;
                        break;
                    }
                }

                if ($ok && isset($_FILES["institution_logo"]) && $_FILES["institution_logo"]["error"] !== UPLOAD_ERR_NO_FILE) {
                    $file = $_FILES["institution_logo"];
                    $allowedMime = [
                        "image/jpeg" => "jpg",
                        "image/png" => "png",
                        "image/webp" => "webp"
                    ];

                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = $finfo ? finfo_file($finfo, $file["tmp_name"]) : "";
                    if ($finfo) finfo_close($finfo);

                    if ($file["error"] !== UPLOAD_ERR_OK) {
                        $ok = false;
                        $message = "Institution logo upload failed.";
                        $messageType = "error";
                    } elseif ($file["size"] > 3 * 1024 * 1024) {
                        $ok = false;
                        $message = "Institution logo must be 3 MB or smaller.";
                        $messageType = "error";
                    } elseif (!isset($allowedMime[$mime])) {
                        $ok = false;
                        $message = "Only JPG, PNG and WEBP logo files are allowed.";
                        $messageType = "error";
                    } else {
                        $uploadDir = __DIR__ . "/../assets/uploads/institution/";
                        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                            $ok = false;
                            $message = "Unable to create the institution logo folder.";
                            $messageType = "error";
                        } else {
                            $newName = "institution_logo_" . $adminId . "_" . time() . "." . $allowedMime[$mime];
                            $destination = $uploadDir . $newName;
                            if (move_uploaded_file($file["tmp_name"], $destination)) {
                                if (!saveSetting($conn, "institution_logo", $newName)) {
                                    $ok = false;
                                    $message = "Logo was uploaded but could not be saved to settings.";
                                    $messageType = "error";
                                }
                            } else {
                                $ok = false;
                                $message = "Unable to save the institution logo.";
                                $messageType = "error";
                            }
                        }
                    }
                }

                if ($ok) {
                    $message = "Institution details saved successfully.";
                    $messageType = "success";
                }
            }
        }

        /* Admin Profile */
        if ($action === "save_profile") {
            $openSection = "profile";
            $fullName = postValue("full_name");
            $email = postValue("email");
            $mobile = postValue("mobile");

            if ($fullName === "") {
                $message = "Full name is required.";
                $messageType = "error";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = "Please enter a valid email address.";
                $messageType = "error";
            } else {
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
                $stmt->bind_param("si", $email, $adminId);
                $stmt->execute();
                $duplicate = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($duplicate) {
                    $message = "That email address is already used by another account.";
                    $messageType = "error";
                } else {
                    $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, mobile = ? WHERE id = ?");
                    $stmt->bind_param("sssi", $fullName, $email, $mobile, $adminId);
                    if ($stmt->execute()) {
                        $message = "Profile updated successfully.";
                        $messageType = "success";
                    } else {
                        $message = "Unable to update profile.";
                        $messageType = "error";
                    }
                    $stmt->close();
                }
            }
        }

        /* Security */
        if ($action === "change_password") {
            $openSection = "security";
            $currentPassword = (string)($_POST["current_password"] ?? "");
            $newPassword = (string)($_POST["new_password"] ?? "");
            $confirmPassword = (string)($_POST["confirm_password"] ?? "");

            if ($currentPassword === "" || $newPassword === "" || $confirmPassword === "") {
                $message = "Please fill all password fields.";
                $messageType = "error";
            } elseif (!password_verify($currentPassword, $admin["password"])) {
                $message = "Current password is incorrect.";
                $messageType = "error";
            } elseif (strlen($newPassword) < 8) {
                $message = "New password must contain at least 8 characters.";
                $messageType = "error";
            } elseif ($newPassword !== $confirmPassword) {
                $message = "New password and confirmation password do not match.";
                $messageType = "error";
            } elseif (password_verify($newPassword, $admin["password"])) {
                $message = "Please choose a different password.";
                $messageType = "error";
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hash, $adminId);
                if ($stmt->execute()) {
                    $message = "Password changed successfully.";
                    $messageType = "success";
                } else {
                    $message = "Unable to change password.";
                    $messageType = "error";
                }
                $stmt->close();
            }
        }
    }

    /* Refresh current values */
    $stmt = $conn->prepare("SELECT id, full_name, email, mobile, profile_photo, password FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $settings = getSettings($conn);
}

$institutionLogo = "";
if (!empty($settings["institution_logo"]) && is_file(__DIR__ . "/../assets/uploads/institution/" . basename($settings["institution_logo"]))) {
    $institutionLogo = "../assets/uploads/institution/" . basename($settings["institution_logo"]);
}

/* Open the relevant detail screen after saving. */
if ($openSection !== "") {
    $autoOpenScript = "window.__openSettingsSection = " . json_encode($openSection) . ";";
} else {
    $autoOpenScript = "window.__openSettingsSection = '';";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Examination Seating Management System Settings">
    <title>Settings | ESMS</title>

    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/settings.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>
<body>

<div class="overlay" id="overlay"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h2>ESMS</h2>
        <button type="button" id="closeSidebar" aria-label="Close Menu"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <ul class="menu">
        <li><a href="dashboard.php"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
        <li><a href="students.php"><i class="fa-solid fa-user-graduate"></i><span>Students</span></a></li>
        <li><a href="halls.php"><i class="fa-solid fa-school"></i><span>Examination Halls</span></a></li>
        <li><a href="examinations.php"><i class="fa-solid fa-calendar-days"></i><span>Examination Sessions</span></a></li>
        <li><a href="seat_allocation.php"><i class="fa-solid fa-chair"></i><span>Seat Allocation</span></a></li>
        <li><a href="attendance.php"><i class="fa-solid fa-clipboard-check"></i><span>Attendance</span></a></li>
        <li><a href="reports.php"><i class="fa-solid fa-chart-column"></i><span>Reports</span></a></li>
        <li class="active"><a href="settings.php"><i class="fa-solid fa-gear"></i><span>Settings</span></a></li>
        <li class="logout"><a href="../auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a></li>
    </ul>
</div>

<div class="main-content settings-main">
    <header class="topbar settings-topbar">
        <div class="settings-header">
            <div class="breadcrumb">
                <button type="button" class="page-menu-btn" id="menuBtn" title="Open Menu" aria-label="Open Menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <a href="dashboard.php" class="breadcrumb-dashboard"><i class="fa-solid fa-house"></i> Dashboard</a>
                <span class="breadcrumb-separator">/</span>
                <span class="current-page" id="breadcrumbCurrent">Settings</span>
            </div>
            <div class="settings-title-row">
                <span class="section-label">SYSTEM CONFIGURATION</span>
                <h1 id="pageTitle">Settings</h1>
                <p id="pageDescription">Manage your institution, administrator account and security.</p>
            </div>
        </div>
    </header>

    <main class="settings-content">
        <?php if ($message !== ""): ?>
            <div class="settings-alert <?= $messageType === "error" ? "alert-error" : "alert-success" ?>" role="alert">
                <i class="fa-solid <?= $messageType === "error" ? "fa-circle-exclamation" : "fa-circle-check" ?>"></i>
                <span><?= clean($message) ?></span>
                <button type="button" class="alert-close" aria-label="Close message">&times;</button>
            </div>
        <?php endif; ?>

        <!-- =================================================
             SETTINGS HOME
        ================================================== -->
        <section class="settings-home" id="settingsHome">
            <div class="settings-list-card">
                <button type="button" class="settings-list-item" data-section="institution">
                    <span class="settings-item-icon"><i class="fa-solid fa-building-columns"></i></span>
                    <span class="settings-item-text">
                        <strong>Institution Details</strong>
                        <small>Manage institution information</small>
                    </span>
                    <i class="fa-solid fa-chevron-right settings-item-arrow"></i>
                </button>

                <button type="button" class="settings-list-item" data-section="profile">
                    <span class="settings-item-icon"><i class="fa-solid fa-user-shield"></i></span>
                    <span class="settings-item-text">
                        <strong>Admin Profile</strong>
                        <small>Manage your administrator account</small>
                    </span>
                    <i class="fa-solid fa-chevron-right settings-item-arrow"></i>
                </button>

                <button type="button" class="settings-list-item" data-section="security">
                    <span class="settings-item-icon"><i class="fa-solid fa-lock"></i></span>
                    <span class="settings-item-text">
                        <strong>Security</strong>
                        <small>Change your account password</small>
                    </span>
                    <i class="fa-solid fa-chevron-right settings-item-arrow"></i>
                </button>

                <button type="button" class="settings-list-item" data-section="about">
                    <span class="settings-item-icon"><i class="fa-solid fa-circle-info"></i></span>
                    <span class="settings-item-text">
                        <strong>About</strong>
                        <small>About the Examination Seating Management System</small>
                    </span>
                    <i class="fa-solid fa-chevron-right settings-item-arrow"></i>
                </button>
            </div>

            <div class="logout-card">
                <a class="settings-list-item logout-list-item" href="../auth/logout.php">
                    <span class="settings-item-icon"><i class="fa-solid fa-right-from-bracket"></i></span>
                    <span class="settings-item-text">
                        <strong>Logout</strong>
                        <small>Exit the administrator account</small>
                    </span>
                    <i class="fa-solid fa-chevron-right settings-item-arrow"></i>
                </a>
            </div>
        </section>

        <!-- =================================================
             DETAIL VIEW
        ================================================== -->
        <section class="settings-detail" id="settingsDetail" hidden>
            <div class="detail-header">
                <button type="button" class="back-button" id="backToSettings">
                    <i class="fa-solid fa-arrow-left"></i>
                    <span>Settings</span>
                </button>
                <div class="detail-heading">
                    <span class="detail-heading-icon" id="detailIcon"><i class="fa-solid fa-gear"></i></span>
                    <div>
                        <h2 id="detailTitle">Settings</h2>
                        <p id="detailDescription">Manage settings</p>
                    </div>
                </div>
            </div>

            <!-- INSTITUTION -->
            <div class="detail-panel" id="panel-institution" hidden>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= clean($csrfToken) ?>">
                    <input type="hidden" name="action" value="save_institution">

                    <div class="form-section-title">
                        <h3>Institution Information</h3>
                        <p>Enter the basic information used throughout the examination system.</p>
                    </div>

                    <div class="form-grid two-columns">
                        <div class="field-group">
                            <label for="institution_name">Institution Name</label>
                            <input id="institution_name" name="institution_name" type="text" maxlength="200" value="<?= clean($settings["institution_name"] ?? "") ?>" placeholder="Enter institution name">
                        </div>
                        <div class="field-group">
                            <label for="institution_code">Institution Code</label>
                            <input id="institution_code" name="institution_code" type="text" maxlength="50" value="<?= clean($settings["institution_code"] ?? "") ?>" placeholder="Enter institution code">
                        </div>
                        <div class="field-group full-width">
                            <label for="institution_address">Address</label>
                            <textarea id="institution_address" name="institution_address" maxlength="500" placeholder="Enter institution address"><?= clean($settings["institution_address"] ?? "") ?></textarea>
                        </div>
                        <div class="field-group">
                            <label for="institution_city">City</label>
                            <input id="institution_city" name="institution_city" type="text" maxlength="100" value="<?= clean($settings["institution_city"] ?? "") ?>" placeholder="Enter city">
                        </div>
                        <div class="field-group">
                            <label for="institution_state">State</label>
                            <input id="institution_state" name="institution_state" type="text" maxlength="100" value="<?= clean($settings["institution_state"] ?? "Telangana") ?>" placeholder="Enter state">
                        </div>
                        <div class="field-group">
                            <label for="institution_pincode">Pincode</label>
                            <input id="institution_pincode" name="institution_pincode" type="text" maxlength="20" value="<?= clean($settings["institution_pincode"] ?? "") ?>" placeholder="Enter pincode">
                        </div>
                        <div class="field-group">
                            <label for="institution_phone">Phone</label>
                            <input id="institution_phone" name="institution_phone" type="text" maxlength="30" value="<?= clean($settings["institution_phone"] ?? "") ?>" placeholder="Enter phone number">
                        </div>
                        <div class="field-group">
                            <label for="institution_email">Email</label>
                            <input id="institution_email" name="institution_email" type="email" maxlength="150" value="<?= clean($settings["institution_email"] ?? "") ?>" placeholder="office@example.com">
                        </div>
                        <div class="field-group">
                            <label for="institution_website">Website</label>
                            <input id="institution_website" name="institution_website" type="url" maxlength="200" value="<?= clean($settings["institution_website"] ?? "") ?>" placeholder="https://example.com">
                        </div>
                        <div class="field-group full-width">
                            <label for="institution_logo">Institution Logo</label>
                            <div class="logo-upload-row">
                                <div class="logo-preview">
                                    <?php if ($institutionLogo): ?>
                                        <img src="<?= clean($institutionLogo) ?>" alt="Institution Logo">
                                    <?php else: ?>
                                        <i class="fa-solid fa-building-columns"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="logo-upload-control">
                                    <input id="institution_logo" name="institution_logo" type="file" accept="image/jpeg,image/png,image/webp">
                                    <small>JPG, PNG or WEBP. Maximum 3 MB.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="primary-btn"><i class="fa-solid fa-floppy-disk"></i> Save</button>
                    </div>
                </form>
            </div>

            <!-- PROFILE -->
            <div class="detail-panel" id="panel-profile" hidden>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= clean($csrfToken) ?>">
                    <input type="hidden" name="action" value="save_profile">

                    <div class="form-section-title">
                        <h3>Administrator Information</h3>
                        <p>Update the administrator details used for this account.</p>
                    </div>

                    <div class="form-grid three-columns">
                        <div class="field-group">
                            <label for="full_name">Full Name</label>
                            <input id="full_name" name="full_name" type="text" maxlength="100" required value="<?= clean($admin["full_name"]) ?>">
                        </div>
                        <div class="field-group">
                            <label for="email">Email</label>
                            <input id="email" name="email" type="email" maxlength="150" required value="<?= clean($admin["email"]) ?>">
                        </div>
                        <div class="field-group">
                            <label for="mobile">Mobile</label>
                            <input id="mobile" name="mobile" type="text" maxlength="30" value="<?= clean($admin["mobile"]) ?>">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="primary-btn"><i class="fa-solid fa-user-pen"></i> Update</button>
                    </div>
                </form>
            </div>

            <!-- SECURITY -->
            <div class="detail-panel" id="panel-security" hidden>
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= clean($csrfToken) ?>">
                    <input type="hidden" name="action" value="change_password">

                    <div class="form-section-title">
                        <h3>Password & Security</h3>
                        <p>Keep your administrator account secure by changing your password regularly.</p>
                    </div>

                    <div class="form-grid three-columns">
                        <div class="field-group password-field">
                            <label for="current_password">Current Password</label>
                            <div class="password-wrap">
                                <input id="current_password" name="current_password" type="password" required>
                                <button type="button" class="toggle-password" data-target="current_password" aria-label="Show password"><i class="fa-regular fa-eye"></i></button>
                            </div>
                        </div>
                        <div class="field-group password-field">
                            <label for="new_password">New Password</label>
                            <div class="password-wrap">
                                <input id="new_password" name="new_password" type="password" minlength="8" required>
                                <button type="button" class="toggle-password" data-target="new_password" aria-label="Show password"><i class="fa-regular fa-eye"></i></button>
                            </div>
                        </div>
                        <div class="field-group password-field">
                            <label for="confirm_password">Confirm Password</label>
                            <div class="password-wrap">
                                <input id="confirm_password" name="confirm_password" type="password" minlength="8" required>
                                <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Show password"><i class="fa-regular fa-eye"></i></button>
                            </div>
                        </div>
                    </div>

                    <div class="security-note">
                        <i class="fa-solid fa-circle-info"></i>
                        <span>Use at least 8 characters and avoid easily guessed passwords.</span>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="primary-btn"><i class="fa-solid fa-key"></i> Change</button>
                    </div>
                </form>
            </div>

            <!-- ABOUT -->
            <div class="detail-panel about-detail-panel" id="panel-about" hidden>
                <div class="about-page">
                    <div class="about-hero">
                        <div class="about-project-icon"><i class="fa-solid fa-building-columns"></i></div>
                        <div>
                            <span class="about-kicker">ABOUT THIS PROJECT</span>
                            <h3>Examination Seating Arrangement System</h3>
                            <p>Complete project information, purpose, features, management and future scope.</p>
                        </div>
                    </div>

                    <div class="about-content about-full-content">
                        <section class="about-section-row">
    <div class="about-section-number">01</div>
    <div class="about-section-body">
        <h3>Introduction</h3>
        <p>The Examination Seating Arrangement System is a web-based application designed to simplify, automate, and efficiently manage the process of arranging students in examination halls. Examination seating arrangement is an important part of academic administration because a properly organized seating plan helps students, invigilators, and examination authorities conduct examinations smoothly.</p>
        <p>In many educational institutions, examination seating arrangements are prepared manually using student lists, hall details, registers, spreadsheets, or handwritten records. When the number of students and examination halls is small, manual preparation may be manageable. However, when the number of students increases and students belong to different branches, years, semesters, and examination groups, preparing the seating arrangement becomes complicated and time-consuming.</p>
        <p>Manual allocation can also result in problems such as assigning the same student more than once, assigning multiple students to the same seat beyond the permitted limit, exceeding hall capacity, leaving available seats unused, entering incorrect row or column information, or preparing inconsistent reports. The Examination Seating Arrangement System is developed to address these difficulties by providing a centralized and systematic digital solution.</p>
        <p>The system allows administrators to manage student information, examination halls, physical seating structures, seating patterns, effective capacity, seat allocation, allocation status, and reports. The system follows predefined rules during allocation and provides a flexible approach in which seating arrangements can be changed according to examination requirements.</p>
        <p>The main objective of the system is to make examination seating management accurate, efficient, flexible, organized, and easier to maintain while reducing the amount of manual work required from examination administrators.</p>
      
    </div>
</section>
<section class="about-section-row">
    <div class="about-section-number">02</div>
    <div class="about-section-body">
        <h3>Purpose of the System</h3>
        <p>The primary purpose of the Examination Seating Arrangement System is to provide an organized method for allocating students to examination halls and specific seating positions.</p>
        <p>The system is designed to reduce the difficulties associated with manual seating preparation. Instead of manually calculating every seat and repeatedly checking student lists and hall capacity, the administrator can provide the required information and allow the system to process the allocation according to predefined rules.</p>
        <p>The system aims to:</p>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Reduce manual work involved in seating arrangement.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Minimize human errors during allocation.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Manage student information systematically.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Manage examination halls and their physical seating structures.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Calculate effective seating capacity dynamically.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Support different seating patterns.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Allocate students according to predefined rules.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Prevent duplicate and invalid seat allocations.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Track allocated and unallocated students.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Provide clear hall-wise and student-wise reports.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Make the seating arrangement process faster and more reliable.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Provide a foundation for future AI-based intelligent seating optimization.</span></div>
        <p>The system is therefore not simply a seat-number generator. It is designed as a complete examination seating management solution that connects student information, hall structure, seating rules, allocation logic, and reporting.</p>
      
    </div>
</section>
<section class="about-section-row">
    <div class="about-section-number">03</div>
    <div class="about-section-body">
        <h3>Rule-Based Seating Allocation</h3>
        <p>One of the most important features of the system is the use of a rule-based seating allocation algorithm.</p>
        <p>A rule-based algorithm makes decisions by following predefined conditions and rules. In this system, students are not placed randomly. The system checks the selected seating requirements and applies the appropriate rules before assigning students to seats.</p>
        <p>The rules help control how students are placed within available halls and seating positions. The administrator determines the required seating pattern, and the system uses that selection during allocation.</p>
        <p>Important rules can include:</p>
        <div class="about-numbered-item"><span class="about-number-dot">1</span><span>Every student must have a valid student PIN.</span></div>
        <div class="about-numbered-item"><span class="about-number-dot">2</span><span>A student should be assigned only once.</span></div>
        <div class="about-numbered-item"><span class="about-number-dot">3</span><span>A seat should not be allocated beyond its permitted occupancy.</span></div>
        <div class="about-numbered-item"><span class="about-number-dot">4</span><span>The total number of allocated students must not exceed the effective capacity of the hall.</span></div>
        <div class="about-numbered-item"><span class="about-number-dot">5</span><span>Only available seats should be used.</span></div>
        <div class="about-numbered-item"><span class="about-number-dot">6</span><span>Row and column positions must remain valid.</span></div>
        <div class="about-numbered-item"><span class="about-number-dot">7</span><span>The selected seating pattern must be followed.</span></div>
        <div class="about-numbered-item"><span class="about-number-dot">8</span><span>The system should maintain a correct relationship between a student and their seat.</span></div>
        <div class="about-numbered-item"><span class="about-number-dot">9</span><span>Allocated seats should not be assigned again.</span></div>
        <div class="about-numbered-item"><span class="about-number-dot">10</span><span>Students who cannot be accommodated should be identified.</span></div>
        <div class="about-numbered-item"><span class="about-number-dot">11</span><span>Hall capacity should be recalculated when the seating pattern changes.</span></div>
        <div class="about-numbered-item"><span class="about-number-dot">12</span><span>The final allocation should remain consistent with the selected examination requirements.</span></div>
        <p>These rules provide a structured method of allocating students and help prevent common errors that can occur during manual preparation.</p>
        <p>The rule-based approach also makes the system predictable. Given the same student data, hall structure, and seating rules, the system can produce an allocation according to the defined conditions.</p>
      
    </div>
</section>
<section class="about-section-row">
    <div class="about-section-number">04</div>
    <div class="about-section-body">
        <h3>Dynamic Seating Capacity</h3>
        <p>A major feature of the system is its ability to handle dynamic seating capacity.</p>
        <p>In a real examination hall, the physical arrangement of benches may remain fixed, but the number of students permitted in the hall can change depending on examination requirements.</p>
        <p>For example, consider a hall with:</p>
        <div class="about-highlight">8 Rows × 3 Columns = 24 Physical Benches</div>
        <p>If the examination arrangement allows 2 students per bench, the effective capacity becomes:</p>
        <div class="about-highlight">24 Benches × 2 Students = 48 Students</div>
        <p>If the administrator selects 1 student per bench, the effective capacity becomes:</p>
        <div class="about-highlight">24 Benches × 1 Student = 24 Students</div>
        <p>Therefore, the physical number of benches remains 24, while the effective examination capacity changes according to the selected seating pattern.</p>
        <p>This distinction is important because the system should not permanently treat a hall&#x27;s initial capacity as its only possible capacity. Instead, it should understand the physical structure and calculate the effective capacity based on the selected examination rule.</p>
        <p>This dynamic approach makes the system flexible for different examination situations. An administrator can change the seating pattern according to requirements without having to recreate the physical hall structure.</p>
      
    </div>
</section>
<section class="about-section-row">
    <div class="about-section-number">05</div>
    <div class="about-section-body">
        <h3>Seating Patterns</h3>
        <p>The system can support multiple seating patterns so that administrators can select an arrangement suitable for a particular examination.</p>
        <h4 class="about-subheading">Single-Person Seating</h4>
        <p>In single-person seating, one student is assigned to each permitted bench or seating position. This can be used when maximum separation between students is required.</p>
        <h4 class="about-subheading">Two-Person Seating</h4>
        <p>In two-person seating, two students may be assigned to a permitted bench. This allows greater utilization of the physical seating capacity while following the selected examination arrangement.</p>
        <h4 class="about-subheading">Alternative Seating</h4>
        <p>Alternative seating can be used when students need to be separated according to a particular arrangement. The allocation algorithm follows the specified pattern while assigning students to available positions.</p>
        <h4 class="about-subheading">Customized Seating</h4>
        <p>Customized seating provides flexibility when the administrator has a specific requirement that does not completely match the standard patterns. The administrator can select the required configuration, and the system can calculate the resulting effective capacity and apply the relevant allocation rules.</p>
        <p>The ability to support multiple patterns makes the system suitable for different examination conditions.</p>
      
    </div>
</section>
<section class="about-section-row">
    <div class="about-section-number">06</div>
    <div class="about-section-body">
        <h3>Student Management</h3>
        <p>The Student Management component is responsible for maintaining the student information required for examination seating allocation.</p>
        <p>Student records may contain:</p>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Student PIN</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Branch</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Academic year</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Semester</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Other required academic information</span></div>
        <p>The student PIN is particularly important because it provides a unique identifier for the student during the allocation process.</p>
        <p>The system can organize students according to their branch, year, and semester. This allows administrators to select the appropriate group of students for a particular examination.</p>
        <p>For example, students from different branches or academic years may have different examination requirements. The administrator can select the required students based on the available academic information and include them in the seating allocation process.</p>
        <p>This reduces the need to manually search through large student lists.</p>
      
    </div>
</section>
<section class="about-section-row">
    <div class="about-section-number">07</div>
    <div class="about-section-body">
        <h3>Hall Management</h3>
        <p>The Hall Management component allows administrators to maintain information about examination halls and their physical seating structure.</p>
        <p>Hall information may include:</p>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Hall number</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Number of rows</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Number of columns</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Physical bench count</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Effective capacity</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Allocation status</span></div>
        <p>The physical hall structure is represented using rows and columns. Each position can be identified using a combination of row and column information.</p>
        <p>For example:</p>
        <p>Hall 01</p>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Row 1 – Column 1</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Row 1 – Column 2</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Row 1 – Column 3</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Row 2 – Column 1</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Row 2 – Column 2</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Row 2 – Column 3</span></div>
        <p>and so on.</p>
        <p>This structure allows the system to assign a student to an exact physical position.</p>
        <p>Hall status can also be maintained so that administrators can identify whether a hall is allocated, partially allocated, or available for use.</p>
      
    </div>
</section>
<section class="about-section-row">
    <div class="about-section-number">08</div>
    <div class="about-section-body">
        <h3>Seating Allocation Process</h3>
        <p>The Seating Allocation component is the core of the system.</p>
        <p>The general allocation process can be represented as:</p>
        <div class="about-highlight">Select Students → Select Hall → Determine Physical Capacity → Select Seating Pattern → Calculate Effective Capacity → Apply Rules → Allocate Seats → Save Allocation → Generate Reports</div>
        <p>The administrator first selects the required students and suitable examination halls. The seating pattern is then selected according to the examination requirement.</p>
        <p>The system calculates the effective capacity based on the physical hall structure and selected seating pattern. It then applies the predefined rules and begins assigning students to available positions.</p>
        <p>During the process, the system maintains the relationship between:</p>
        <div class="about-highlight">Student → Hall → Row → Column → Seat/Bench</div>
        <p>This allows the final seating arrangement to be clearly identified.</p>
        <p>If the number of students is greater than the available effective capacity, the system can identify the remaining unallocated students. Similarly, unused seating positions can be identified when the number of students is less than the available capacity.</p>
      
    </div>
</section>
<section class="about-section-row">
    <div class="about-section-number">09</div>
    <div class="about-section-body">
        <h3>Branch, Year and Semester Based Allocation</h3>
        <p>Educational institutions generally have students belonging to multiple branches and academic levels.</p>
        <p>The system can manage students according to information such as:</p>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Branch</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Year</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Semester</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Student PIN</span></div>
        <p>This makes it possible to select the required student groups for an examination.</p>
        <p>For example, students from CSE, ECE, EEE, Civil, Mechanical, or other branches may be included according to examination requirements. Different years and semesters can also be selected according to the examination being conducted.</p>
        <p>This provides greater control over allocation and allows the administrator to prepare arrangements based on actual examination requirements rather than treating all students as a single group.</p>
      
    </div>
</section>
<section class="about-section-row">
    <div class="about-section-number">10</div>
    <div class="about-section-body">
        <h3>Conflict Prevention</h3>
        <p>Preventing seating conflicts is one of the important objectives of the system.</p>
        <p>During manual allocation, an administrator may accidentally:</p>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Allocate the same student twice.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Assign the same seat to multiple students.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Exceed the permitted number of students per bench.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Allocate more students than a hall can accommodate.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Enter an invalid row or column.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Assign a student to an unavailable position.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Produce inconsistent seating reports.</span></div>
        <p>The rule-based allocation process helps reduce these problems by checking the relevant conditions before completing the allocation.</p>
        <p>Each student should have a valid allocation, and each seat should have a controlled occupancy. The system also maintains the row and column information so that the digital allocation corresponds to the physical hall arrangement.</p>
      
    </div>
</section>
<section class="about-section-row">
    <div class="about-section-number">11</div>
    <div class="about-section-body">
        <h3>Reports</h3>
        <p>The system provides reports that make the seating arrangement easier to understand and communicate.</p>
        <h4 class="about-subheading">Hall Allocation Report</h4>
        <p>The Hall Allocation Report is intended for displaying or publishing hall-wise information.</p>
        <p>It may contain:</p>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Hall number</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Effective capacity</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Allocated branch</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Year</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Semester</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Student PIN range</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Allocation status</span></div>
        <p>This allows students and examination staff to identify which students have been assigned to each hall.</p>
        <h4 class="about-subheading">Student Seating Report</h4>
        <p>The Student Seating Report provides individual seating information.</p>
        <p>It may contain:</p>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Student PIN</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Hall number</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Row number</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Column number</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Seat or bench position</span></div>
        <p>This allows a student to identify their exact examination location without having to search through the complete seating arrangement.</p>
        <p>The reports therefore act as an important connection between the generated digital allocation and the physical examination environment.</p>
      
    </div>
</section>
<section class="about-section-row">
    <div class="about-section-number">12</div>
    <div class="about-section-body">
        <h3>System Information</h3>
        <p>The Examination Seating Arrangement System is a web-based, database-driven application designed for examination seating management.</p>
        <p>The system consists of several functional components that work together to provide the complete seating management process.</p>
        <p>The main system information includes:</p>
        <p>Application Type: Web-Based Examination Management Application</p>
        <p>Primary User: Administrator / Examination Management Staff</p>
        <p>Main Purpose: Examination Hall and Student Seat Allocation</p>
        <p>Allocation Method: Rule-Based Seating Allocation</p>
        <p>Student Identifier: Student PIN</p>
        <p>Hall Structure: Rows, Columns, and Physical Benches</p>
        <p>Seating Options: Single Person, Two Persons, Alternative, and Customized</p>
        <p>Capacity Management: Dynamic Effective Capacity</p>
        <p>Main Functional Areas: Student Management, Hall Management, Seating Allocation, Allocation Status, Reports, and Settings</p>
        <p>Database: Structured database for maintaining student, hall, and allocation information</p>
        <p>The system provides an administrator-oriented interface through which authorized users can manage records, select students, configure halls, choose seating patterns, generate allocations, and view reports.</p>
        <p>The system is designed to keep the physical hall structure separate from the effective examination capacity. This allows seating rules to change without requiring the administrator to recreate the entire hall.</p>
      
    </div>
</section>
<section class="about-section-row">
    <div class="about-section-number">13</div>
    <div class="about-section-body">
        <h3>Data Management</h3>
        <p>The system maintains structured information related to students, halls, and seating allocations.</p>
        <p>Student information is used to identify the individuals who need to be allocated. Hall information defines the available physical seating structure. Allocation information establishes the relationship between students and their assigned examination positions.</p>
        <p>This structured approach provides several benefits:</p>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Faster retrieval of information.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Better organization of records.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Reduced duplication.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Easier updating.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Easier verification.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Accurate allocation tracking.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Better report generation.</span></div>
        <p>Maintaining the information in a centralized system also reduces dependence on separate manual lists and calculations.</p>
      
    </div>
</section>
<section class="about-section-row">
    <div class="about-section-number">14</div>
    <div class="about-section-body">
        <h3>Administrator Role</h3>
        <p>The administrator plays an important role in controlling the seating arrangement process.</p>
        <p>The administrator can:</p>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Manage student information.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Manage examination halls.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Configure hall rows and columns.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>View physical and effective capacity.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Select students for allocation.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Select multiple halls when required.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Select branches, years, and semesters.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Select the required seating pattern.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Generate seating allocations.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Check allocated and unallocated students.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>View allocation status.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Generate hall-wise reports.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Generate student-wise seating reports.</span></div>
        <div class="about-bullet"><i class="fa-solid fa-check"></i><span>Manage relevant system settings.</span></div>
        <p>The administrator controls the input and examination requirements, while the allocation system processes those inputs according to predefined rules.</p>
      
    </div>
</section>
<section class="about-section-row">
    <div class="about-section-number">15</div>
    <div class="about-section-body">
        <h3>Benefits of the System</h3>
        <p>The Examination Seating Arrangement System provides several important benefits.</p>
        <h4 class="about-subheading">Time Saving</h4>
        <p>Automated allocation reduces the time required to manually prepare seating arrangements.</p>
        <h4 class="about-subheading">Accuracy</h4>
        <p>Rule-based validation helps reduce errors during seat assignment.</p>
        <h4 class="about-subheading">Flexibility</h4>
        <p>Different seating patterns can be selected according to examination requirements.</p>
        <h4 class="about-subheading">Dynamic Capacity</h4>
        <p>The effective capacity can change based on the selected number of students per bench or customized seating rules.</p>
        <h4 class="about-subheading">Reduced Manual Work</h4>
        <p>The administrator does not need to manually calculate every seat position.</p>
        <h4 class="about-subheading">Better Organization</h4>
        <p>Student, hall, and allocation information is maintained in a structured manner.</p>
        <h4 class="about-subheading">Conflict Reduction</h4>
        <p>The system checks for duplicate and invalid allocations.</p>
        <h4 class="about-subheading">Easy Reporting</h4>
        <p>Hall-wise and student-wise reports can be generated from the allocation data.</p>
        <h4 class="about-subheading">Scalability</h4>
        <p>The system can be extended to support larger numbers of students, halls, and examination requirements.</p>
        <h4 class="about-subheading">Maintainability</h4>
        <p>The modular structure allows individual functions to be improved without redesigning the complete system.</p>
      
    </div>
</section>
<section class="about-section-row">
    <div class="about-section-number">16</div>
    <div class="about-section-body">
        <h3>Future Scope Using Artificial Intelligence</h3>
        <p>The current system uses rule-based algorithms, which provide a controlled and predictable method of allocating students. In the future, the system can be enhanced by integrating Artificial Intelligence (AI) and optimization techniques.</p>
        <p>AI can make the system more intelligent by analyzing multiple factors and recommending efficient seating arrangements while still following mandatory examination rules.</p>
        <p>One possible future feature is AI-based seating optimization. Instead of only following a fixed allocation sequence, AI could evaluate multiple possible arrangements and identify an efficient solution based on factors such as the number of students, available halls, effective capacity, seating patterns, branch distribution, year, semester, examination requirements, and available seating positions.</p>
        <p>For example, if there are several halls with different capacities and a large number of students, an AI system could analyze the available combinations and recommend how students should be distributed among the halls while minimizing unused capacity and avoiding conflicts.</p>
        <p>AI could also provide intelligent hall selection. When the administrator provides the number of students and required seating pattern, the system could analyze available halls and recommend the most suitable halls. It could consider effective capacity, current allocation status, and other predefined requirements.</p>
        <p>Another important application is AI-based conflict detection. AI could examine the generated allocation and identify potential problems such as duplicate student assignments, insufficient capacity, invalid seat positions, overlapping allocations, or violations of specific seating rules. The system could then provide recommendations to the administrator before the allocation is finalized.</p>
        <p>AI can also support intelligent branch and student distribution. If the institution has specific policies regarding how students from different branches, years, or semesters should be distributed, AI could analyze the available student groups and recommend an arrangement that satisfies those policies.</p>
        <p>A future version could provide an AI assistant for administrators. Instead of navigating through multiple options, an administrator could ask questions in natural language, such as:</p>
        <blockquote class="about-quote">&quot;How many halls are required for these students?&quot;</blockquote>
        <blockquote class="about-quote">&quot;Which available halls have enough capacity?&quot;</blockquote>
        <blockquote class="about-quote">&quot;How many students are unallocated?&quot;</blockquote>
        <blockquote class="about-quote">&quot;Which seating pattern provides enough capacity?&quot;</blockquote>
        <p>The AI assistant could analyze the system&#x27;s stored information and provide understandable answers.</p>
        <p>AI could also be used for capacity prediction and examination planning. By analyzing previous examination data, the system could identify patterns in student numbers and hall usage. This could help administrators estimat</p>
    </div>
</section>
                    </div>

                    <div class="about-source-note">
                        <i class="fa-solid fa-circle-info"></i>
                        <span>The About section contains the project description supplied for this system.</span>
                    </div>
                </div>
            </div>
            </div>
        </section>
    </main>
</div>

<script>
<?= $autoOpenScript ?>

document.addEventListener("DOMContentLoaded", function () {
    const sidebar = document.getElementById("sidebar");
    const overlay = document.getElementById("overlay");
    const menuBtn = document.getElementById("menuBtn");
    const closeSidebar = document.getElementById("closeSidebar");
    const settingsHome = document.getElementById("settingsHome");
    const settingsDetail = document.getElementById("settingsDetail");
    const backButton = document.getElementById("backToSettings");
    const pageTitle = document.getElementById("pageTitle");
    const pageDescription = document.getElementById("pageDescription");
    const breadcrumbCurrent = document.getElementById("breadcrumbCurrent");
    const detailTitle = document.getElementById("detailTitle");
    const detailDescription = document.getElementById("detailDescription");
    const detailIcon = document.getElementById("detailIcon");

    const sections = {
        institution: {
            title: "Institution Details",
            description: "Manage institution information.",
            icon: "fa-solid fa-building-columns"
        },
        profile: {
            title: "Admin Profile",
            description: "Manage your administrator account.",
            icon: "fa-solid fa-user-shield"
        },
        security: {
            title: "Security",
            description: "Change your account password.",
            icon: "fa-solid fa-lock"
        },
        about: {
            title: "About",
            description: "About the Examination Seating Arrangement System.",
            icon: "fa-solid fa-circle-info"
        }
    };

    function openSidebar() {
    sidebar?.classList.add("active");
    overlay?.classList.add("active");
    document.body.classList.add("menu-open");
}

function closeMenu() {
    sidebar?.classList.remove("active");
    overlay?.classList.remove("active");
    document.body.classList.remove("menu-open");
}

/* Open and close menu button */
menuBtn?.addEventListener("click", function (event) {
    event.preventDefault();
    event.stopPropagation();

    if (sidebar?.classList.contains("active")) {
        closeMenu();
    } else {
        openSidebar();
    }
});

/* Close X button */
closeSidebar?.addEventListener("click", function (event) {
    event.preventDefault();
    event.stopPropagation();
    closeMenu();
});

/* Close overlay */
overlay?.addEventListener("click", closeMenu);

/* Close after clicking any sidebar link */
sidebar?.querySelectorAll("a").forEach(function (link) {
    link.addEventListener("click", function () {
        closeMenu();
    });
});

/* Close when clicking outside the sidebar */
document.addEventListener("click", function (event) {
    if (!sidebar || !menuBtn) return;

    const clickedInsideSidebar = sidebar.contains(event.target);
    const clickedMenuButton = menuBtn.contains(event.target);

    if (
        sidebar.classList.contains("active") &&
        !clickedInsideSidebar &&
        !clickedMenuButton
    ) {
        closeMenu();
    }
});

    function showSection(sectionName) {
        const data = sections[sectionName];
        if (!data) return;

        settingsHome.hidden = true;
        settingsDetail.hidden = false;

        Object.keys(sections).forEach(function (name) {
            const panel = document.getElementById("panel-" + name);
            if (panel) panel.hidden = name !== sectionName;
        });

        pageTitle.textContent = data.title;
        pageDescription.textContent = data.description;
        breadcrumbCurrent.textContent = data.title;
        detailTitle.textContent = data.title;
        detailDescription.textContent = data.description;
        detailIcon.innerHTML = '<i class="' + data.icon + '"></i>';

        window.scrollTo({ top: 0, behavior: "smooth" });
    }

    function showHome() {
        settingsDetail.hidden = true;
        settingsHome.hidden = false;
        pageTitle.textContent = "Settings";
        pageDescription.textContent = "Manage your institution, administrator account and security.";
        breadcrumbCurrent.textContent = "Settings";
        window.scrollTo({ top: 0, behavior: "smooth" });
    }

    document.querySelectorAll(".settings-list-item[data-section]").forEach(function (item) {
        item.addEventListener("click", function () {
            showSection(item.dataset.section);
        });
    });

    backButton?.addEventListener("click", showHome);

    document.querySelectorAll(".toggle-password").forEach(function (button) {
        button.addEventListener("click", function () {
            const target = document.getElementById(button.dataset.target);
            const icon = button.querySelector("i");
            if (!target) return;

            const show = target.type === "password";
            target.type = show ? "text" : "password";
            button.setAttribute("aria-label", show ? "Hide password" : "Show password");
            if (icon) icon.className = show ? "fa-regular fa-eye-slash" : "fa-regular fa-eye";
        });
    });

    document.querySelectorAll(".alert-close").forEach(function (button) {
        button.addEventListener("click", function () {
            button.closest(".settings-alert")?.remove();
        });
    });

    const logoInput = document.getElementById("institution_logo");
    logoInput?.addEventListener("change", function () {
        const file = logoInput.files?.[0];
        if (!file || !file.type.startsWith("image/")) return;

        const reader = new FileReader();
        reader.onload = function (event) {
            const preview = document.querySelector(".logo-preview");
            if (preview) {
                preview.innerHTML = '<img src="' + event.target.result + '" alt="Logo Preview">';
            }
        };
        reader.readAsDataURL(file);
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            closeMenu();
            if (!settingsDetail.hidden) showHome();
        }
    });

    /* Return to the same detail screen after a successful/failed save. */
    if (window.__openSettingsSection && sections[window.__openSettingsSection]) {
        showSection(window.__openSettingsSection);
    }
});
</script>
</body>
</html>
