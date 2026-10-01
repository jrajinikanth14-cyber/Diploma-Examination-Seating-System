<?php
session_start();

include "../config/db_connect.php";

if ($_SERVER["REQUEST_METHOD"] != "POST") {
    header("Location: login.php");
    exit();
}

$login = trim($_POST["login"]);
$password = trim($_POST["password"]);

// Check whether email/mobile exists
$stmt = $conn->prepare("
    SELECT id, full_name, email, mobile, password
    FROM users
    WHERE email = ? OR mobile = ?
    LIMIT 1
");

$stmt->bind_param("ss", $login, $login);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows == 0) {

    // Invalid Email/Mobile
    header("Location: login.php?error=user&login=" . urlencode($login));
    exit();

}

$user = $result->fetch_assoc();

// Verify Password
if (!password_verify($password, $user["password"])) {

    // Invalid Password
    header("Location: login.php?error=password&login=" . urlencode($login));
    exit();

}

// Login Successful
session_regenerate_id(true);

$_SESSION["admin"] = $user["id"];
$_SESSION["full_name"] = $user["full_name"];
$_SESSION["email"] = $user["email"];
$_SESSION["mobile"] = $user["mobile"];
$_SESSION["logged_in"] = true;

header("Location: ../admin/dashboard.php");
exit();
?>