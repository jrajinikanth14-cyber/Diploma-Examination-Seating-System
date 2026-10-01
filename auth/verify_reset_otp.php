<?php
session_start();

if (!isset($_SESSION["reset_otp"])) {
    header("Location: forgot_password.php");
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $enteredOtp = trim($_POST["otp"]);

    if (time() > $_SESSION["reset_otp_expiry"]) {

        $error = "OTP has expired.";

    } elseif ($enteredOtp == $_SESSION["reset_otp"]) {

        $_SESSION["reset_verified"] = true;

        header("Location: reset_password.php");
        exit();

    } else {

        $error = "Invalid OTP.";

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

<p>Enter the 6-digit OTP sent to your registered Email.</p>

<?php
if ($error != "") {
    echo "<p style='color:red;margin-bottom:15px;'>$error</p>";
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

<script>

let seconds = 30;

const resendBtn = document.getElementById("resendBtn");
const countdown = document.getElementById("countdown");

const timer = setInterval(() => {

    seconds--;

    countdown.innerText = seconds;

    if(seconds <= 0){

        clearInterval(timer);

        resendBtn.disabled = false;

        document.getElementById("timer").innerHTML = "";

    }

},1000);

resendBtn.onclick = function(){

    window.location.href = "forgot_password_process.php?resend=1";

};

</script>
<script>
document.addEventListener("DOMContentLoaded", function () {

    <?php if (isset($_GET["success"]) || isset($_GET["resent"])): ?>

        alert("OTP sent successfully.");

    <?php endif; ?>

});
</script>
</body>

</html>