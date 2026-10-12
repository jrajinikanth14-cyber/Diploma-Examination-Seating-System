
<?php
session_start();

include "../config/db_connect.php";
include "../config/mail_config.php";

/* ==========================================
   RESEND RESET OTP
========================================== */

if (isset($_GET["resend"])) {

    // Ensure a valid reset session exists
    if (
        empty($_SESSION["reset_user_id"]) ||
        empty($_SESSION["reset_email"])
    ) {
        header("Location: forgot_password.php");
        exit();
    }

    // Generate a new OTP
    $otp = random_int(100000, 999999);

    $_SESSION["reset_otp"] = (string) $otp;
    $_SESSION["reset_otp_expiry"] = time() + 600;
    $_SESSION["reset_verified"] = false;

    // Send to the email associated with the selected account
    $status = sendOTP(
        $_SESSION["reset_email"],
        "User",
        $otp
    );

    if ($status === true) {
        header("Location: verify_reset_otp.php?resent=1");
        exit();
    }

    exit("Unable to send OTP. Please check your mail configuration.");
}

/* ==========================================
   PROCESS FORGOT PASSWORD REQUEST
========================================== */

// Only accept POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: forgot_password.php");
    exit();
}

// Check submitted value
$login = trim($_POST["login"] ?? "");

$email = "";
$mobile = "";

/* ==========================================
   VALIDATE EMAIL OR MOBILE NUMBER
========================================== */

if (filter_var($login, FILTER_VALIDATE_EMAIL)) {

    // Email was entered
    $email = $login;

} elseif (preg_match('/^[6-9][0-9]{9}$/', $login)) {

    // Mobile number was entered
    $mobile = $login;

} else {

    header("Location: forgot_password.php?error=invalid");
    exit();
}

/* ==========================================
   FIND THE CORRECT ACCOUNT
   IMPORTANT: Search by ONE field only.
========================================== */

if ($email !== "") {

    // Search by email only.
    // Do not check the mobile column here.
    $stmt = $conn->prepare(
        "SELECT id, email, mobile
         FROM users
         WHERE email = ?
         LIMIT 1"
    );

    if (!$stmt) {
        exit("Database query could not be prepared.");
    }

    $stmt->bind_param("s", $email);

} else {

    // Search by mobile only.
    // Do not check the email column here.
    $stmt = $conn->prepare(
        "SELECT id, email, mobile
         FROM users
         WHERE mobile = ?
         LIMIT 1"
    );

    if (!$stmt) {
        exit("Database query could not be prepared.");
    }

    $stmt->bind_param("s", $mobile);
}

if (!$stmt->execute()) {
    $stmt->close();
    exit("Unable to check the account. Please try again.");
}

$result = $stmt->get_result();

if ($result->num_rows !== 1) {

    $stmt->close();

    header("Location: forgot_password.php?error=notfound");
    exit();
}

$user = $result->fetch_assoc();
$stmt->close();

/* ==========================================
   CHECK WHETHER EMAIL OTP IS AVAILABLE
========================================== */

// This project currently sends OTP through email.
// Mobile OTP needs a configured SMS service.
if (empty($user["email"])) {

    if (!empty($user["mobile"])) {
        exit(
            "This account has no registered email address. " .
            "Mobile OTP delivery is not configured yet."
        );
    }

    header("Location: forgot_password.php?error=notfound");
    exit();
}

/* ==========================================
   GENERATE OTP
========================================== */

$otp = random_int(100000, 999999);

/* ==========================================
   CLEAR PREVIOUS RESET SESSION
========================================== */

unset(
    $_SESSION["reset_user_id"],
    $_SESSION["reset_email"],
    $_SESSION["reset_mobile"],
    $_SESSION["reset_otp"],
    $_SESSION["reset_otp_expiry"],
    $_SESSION["reset_verified"]
);

/* ==========================================
   SAVE THE CORRECT USER IN SESSION
========================================== */

$_SESSION["reset_user_id"] = (int) $user["id"];
$_SESSION["reset_email"] = $user["email"];
$_SESSION["reset_mobile"] = $user["mobile"];
$_SESSION["reset_otp"] = (string) $otp;
$_SESSION["reset_otp_expiry"] = time() + 600;
$_SESSION["reset_verified"] = false;

/* ==========================================
   SEND OTP TO THE MATCHED USER'S EMAIL
========================================== */

$status = sendOTP(
    $user["email"],
    "User",
    $otp
);

if ($status === true) {

    header("Location: verify_reset_otp.php?success=1");
    exit();

} else {

    // Invalidate the reset session if sending failed
    unset(
        $_SESSION["reset_user_id"],
        $_SESSION["reset_email"],
        $_SESSION["reset_mobile"],
        $_SESSION["reset_otp"],
        $_SESSION["reset_otp_expiry"],
        $_SESSION["reset_verified"]
    );

    exit(
        "Unable to send OTP. Please check your mail configuration " .
        "and try again."
    );
}
?>
