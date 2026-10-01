<?php
session_start();

include "../config/db_connect.php";
include "../config/mail_config.php";

/* ==========================================
   RESEND RESET OTP
========================================== */

if (isset($_GET["resend"])) {

    if (!isset($_SESSION["reset_email"])) {
        header("Location: forgot_password.php");
        exit();
    }

    $otp = rand(100000,999999);

    $_SESSION["reset_otp"] = $otp;
    $_SESSION["reset_otp_expiry"] = time() + 600;

    if (!empty($_SESSION["reset_email"])) {

      $status = sendOTP(
    $_SESSION["reset_email"],
    "User",
    $otp
);

        if ($status === true) {

            header("Location: verify_reset_otp.php?resent=1");
            exit();

        } else {

            die("Mail Error : ".$status);

        }

    }

    header("Location: verify_reset_otp.php");
    exit();
}

/* ==========================================
   SEND OTP
========================================== */

if ($_SERVER["REQUEST_METHOD"] != "POST") {

    header("Location: forgot_password.php");
    exit();

}

$login = trim($_POST["login"]);

/* Email */

if (filter_var($login, FILTER_VALIDATE_EMAIL)) {

    $email = $login;
    $mobile = "";

/* Mobile */

} elseif (preg_match('/^[6-9][0-9]{9}$/', $login)) {

    $email = "";
    $mobile = $login;

} else {

   header("Location: forgot_password.php?error=invalid");
    exit();

}

/* Check Account */

$stmt = $conn->prepare("SELECT id,email,mobile FROM users WHERE email=? OR mobile=? LIMIT 1");
$stmt->bind_param("ss",$email,$mobile);
$stmt->execute();

$result = $stmt->get_result();

if($result->num_rows==0){

    header("Location: forgot_password.php?error=notfound");
    exit();

}

$user = $result->fetch_assoc();

/* Generate OTP */

$otp = rand(100000,999999);

/* Save Session */

$_SESSION["reset_user_id"] = $user["id"];
$_SESSION["reset_email"] = $user["email"];
$_SESSION["reset_mobile"] = $user["mobile"];
$_SESSION["reset_otp"] = $otp;
$_SESSION["reset_otp_expiry"] = time()+600;

/* Send Email */

if(!empty($user["email"])){

    $status = sendOTP(
    $user["email"],
    "User",
    $otp
);

    if($status===true){

        header("Location: verify_reset_otp.php?success=1");
exit();

    }else{

        die("Mail Error : ".$status);

    }

}

/* Mobile (Firebase Later) */

if(!empty($user["mobile"])){

    header("Location: verify_reset_otp.php?success=1");
exit();

}

header("Location: forgot_password.php");
exit();

?>