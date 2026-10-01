<?php
session_start();

if (!isset($_SESSION["register_otp"])) {
    header("Location: register.php");
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $enteredOtp = trim($_POST["otp"]);

// Check OTP expiry
if (time() > $_SESSION["register_otp_expiry"]) {

    unset($_SESSION["register_otp"]);
    unset($_SESSION["register_otp_expiry"]);

    $error = "OTP has expired. Please register again.";

} elseif ($enteredOtp == $_SESSION["register_otp"]) {

    $_SESSION["otp_verified"] = true;

    header("Location: create_password.php");
    exit();

} else {

    $error = "Invalid OTP";

}

}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Verify OTP</title>

<link rel="stylesheet" href="../assets/css/register.css">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

</head>

<body>

<div class="container">

<div class="register-card">

<h1>OTP Verification</h1>

<p>Enter the 6-digit OTP sent to your Email.</p>

<?php
if($error!=""){
echo "<p style='color:red;'>$error</p>";
}
?>

<form method="POST">

    <div class="input-box">

        <input
            type="text"
            name="otp"
            maxlength="6"
            placeholder="Enter 6-digit OTP"
            required>

    </div>

    <button class="register-btn" type="submit">

        Verify OTP

    </button>

    <div class="resend-section">

        <button
            type="button"
            id="resendBtn"
            disabled>

            Resend OTP

        </button>

        <p id="timer">

            Resend OTP in
            <span id="countdown">30</span>
            seconds

        </p>

    </div>

</form>

</div>

</div>
<script src="../assets/js/otp.js"></script>
</body>

</html>