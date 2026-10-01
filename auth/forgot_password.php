<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Forgot Password | Examination Seating Management System</title>

<link rel="stylesheet" href="../assets/css/forgot-password.css">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

</head>

<body>

<div class="container">

<div class="forgot-card">

<h1>Forgot Password</h1>

<p>Enter your registered Email or Mobile Number to receive an OTP.</p>

<?php

if (isset($_GET["error"])) {

    if ($_GET["error"] == "invalid") {

        echo "<p class='error-msg'>Please enter a valid email address or mobile number.</p>";

    } elseif ($_GET["error"] == "notfound") {

        echo "<p class='error-msg'>No account found with the entered email or mobile number.</p>";

    }

}

if (isset($_GET["success"])) {

    echo "<p class='success-msg'>OTP has been sent successfully.</p>";

}

?>

<form action="forgot_password_process.php" method="POST" autocomplete="off">

    <div class="input-box">

        <i class="fa-solid fa-envelope"></i>

        <input
    type="text"
    name="login"
    id="forgotLogin"
    placeholder="Enter Email or Mobile Number"
    value=""
    autocomplete="off"
    autocorrect="off"
    autocapitalize="off"
    spellcheck="false"
    required>
    </div>

    <button type="submit" class="verify-btn">

        <i class="fa-solid fa-paper-plane"></i>

        Send OTP

    </button>

</form>

<a href="login.php" class="back">

    <i class="fa-solid fa-arrow-left"></i>

    Back to Login

</a>

</div>

</div>

</body>

</html>