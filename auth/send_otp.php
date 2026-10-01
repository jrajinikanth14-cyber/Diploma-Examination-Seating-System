<?php
session_start();

include "../config/db_connect.php";
include "../config/mail_config.php";

/* ==========================================
   RESEND OTP
========================================== */

if (isset($_GET["resend"])) {

    if (!empty($_SESSION["register_email"])) {

        $email = $_SESSION["register_email"];

        $otp = rand(100000, 999999);

        $_SESSION["register_otp"] = $otp;
        $_SESSION["register_otp_expiry"] = time() + 600;

        $status = sendOTP(
    $email,
    $_SESSION["register_name"],
    $otp
);

      if ($status === true) {

    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>OTP Sent</title>
    </head>
    <body>

    <script>
        alert('OTP sent successfully!');
        window.location.href='verify_otp.php';
    </script>

    </body>
    </html>";

    exit();

} else {

    echo "<script>
        alert('Mail Error: " . addslashes($status) . "');
        window.location.href='register.php';
    </script>";

    exit();
}
    }
}

/* ==========================================
   NEW REGISTRATION OTP
========================================== */

if ($_SERVER["REQUEST_METHOD"] != "POST") {
    header("Location: register.php");
    exit();
}

$login = trim($_POST["login"]);
$fullName = trim($_POST["full_name"]);
$_SESSION["register_name"] = $fullName;

// Check whether input is email or mobile
if (filter_var($login, FILTER_VALIDATE_EMAIL)) {

    $email = $login;
    $mobile = "";

} elseif (preg_match('/^[6-9][0-9]{9}$/', $login)) {

    $email = "";
    $mobile = $login;

} else {

    echo "<script>
        alert('Please enter a valid Email or Mobile Number.');
        window.location='register.php';
    </script>";
    exit();

}

// Check whether account already exists

if (!empty($email)) {

    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);

} else {

    $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = ? LIMIT 1");
    $stmt->bind_param("s", $mobile);

}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {

    echo "<script>
        alert('Account already exists. Please login.');
        window.location='login.php';
    </script>";
    exit();

}

// Generate OTP
$otp = rand(100000, 999999);

// Save in session
$_SESSION["register_email"] = $email;
$_SESSION["register_mobile"] = $mobile;
$_SESSION["register_otp"] = $otp;
$_SESSION["register_otp_expiry"] = time() + 600;

// Send Email OTP
if (!empty($email)) {


$status = sendOTP(
    $email,
    $_SESSION["register_name"],
    $otp
);

   if ($status === true) {

    echo "
    <!DOCTYPE html>
    <html>
    <head>
        <title>OTP Sent</title>
    </head>
    <body>

    <script>
        alert('OTP sent successfully!');
        window.location.href='verify_otp.php';
    </script>

    </body>
    </html>";

    exit();

} else {

    echo "
    <script>
        alert('Mail Error: " . addslashes($status) . "');
        window.location.href='register.php';
    </script>";

    exit();

}

} else {

    // Firebase SMS
    echo "<script>
        alert('SMS OTP integration will be added later.');
    </script>";

}
?>