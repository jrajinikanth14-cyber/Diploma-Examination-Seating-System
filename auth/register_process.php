<?php
session_start();

include "../config/db_connect.php";

if (!isset($_SESSION["otp_verified"])) {
    header("Location: register.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] != "POST") {
    header("Location: create_password.php");
    exit();
}

/* -----------------------------
   Get Form Data
------------------------------ */

$fullName = trim($_POST["full_name"]);
$password = trim($_POST["password"]);
$confirmPassword = trim($_POST["confirm_password"]);

/* -----------------------------
   Validate Password
------------------------------ */

if ($password !== $confirmPassword) {

    echo "<script>
        alert('Passwords do not match.');
        history.back();
    </script>";
    exit();
}

/* -----------------------------
   Get Registration Details
------------------------------ */

$email = $_SESSION["register_email"] ?? null;
$mobile = $_SESSION["register_mobile"] ?? null;

if ($email === "") {
    $email = null;
}

if ($mobile === "") {
    $mobile = null;
}

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$isVerified = 1;

/* -----------------------------
   Insert User
------------------------------ */

$stmt = $conn->prepare("
INSERT INTO users
(full_name,email,mobile,password,is_verified)
VALUES (?,?,?,?,?)
");

$stmt->bind_param(
    "ssssi",
    $fullName,
    $email,
    $mobile,
    $hashedPassword,
    $isVerified
);

if ($stmt->execute()) {

    session_unset();
    session_destroy();

    echo "<script>
        alert('Registration Successful.');
        window.location='login.php?registered=1';
    </script>";

} else {

    echo "<h3>Registration Failed</h3>";
    echo "<b>Error:</b> " . $stmt->error;
}

$stmt->close();
$conn->close();
?>