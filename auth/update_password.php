<?php
session_start();

include "../config/db_connect.php";

// User must have verified the OTP
if (
    !isset($_SESSION["reset_verified"]) ||
    !isset($_SESSION["reset_user_id"])
) {
    header("Location: forgot_password.php");
    exit();

}

if ($_SERVER["REQUEST_METHOD"] != "POST") {
    header("Location: reset_password.php");
    exit();
}

$password = trim($_POST["password"]);
$confirmPassword = trim($_POST["confirm_password"]);

// Check passwords match
if ($password != $confirmPassword) {

    header("Location: reset_password.php?error=match");
    exit();

}

// Password validation
$pattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&^#])[A-Za-z\d@$!%*?&^#]{8,}$/';

if (!preg_match($pattern, $password)) {

    header("Location: reset_password.php?error=password");
    exit();

}

// Encrypt password
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

// Update password
$stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
$stmt->bind_param("si", $hashedPassword, $_SESSION["reset_user_id"]);

if ($stmt->execute()) {

    // Clear reset sessions
    unset($_SESSION["reset_user_id"]);
    unset($_SESSION["reset_email"]);
    unset($_SESSION["reset_mobile"]);
    unset($_SESSION["reset_otp"]);
    unset($_SESSION["reset_otp_expiry"]);
    unset($_SESSION["reset_verified"]);

    header("Location: login.php?reset=success");
    exit();

} else {

    die("Failed to update password.");

}
?>