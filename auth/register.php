<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Create Administrator Account</title>

<link rel="stylesheet" href="../assets/css/register.css">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

</head>

<body>

<div class="container">

<div class="register-card">

<h1>Create Administrator Account</h1>

<p>
Enter your Email Address or Mobile Number to receive an OTP.
</p>

<form id="registerForm" action="send_otp.php" method="POST" autocomplete="off">

<div class="input-box">
    <i class="fa-solid fa-user"></i>
    <input
    type="text"
    name="full_name"
    placeholder="Full Name"
    autocomplete="off"
    value=""
    required>
</div>

<div class="input-box">
    <i class="fa-solid fa-envelope"></i>
    <input
    type="text"
    name="login"
    placeholder="Email Address or Mobile Number"
    autocomplete="off"
    value=""
    required>
</div>

<!-- Firebase reCAPTCHA -->
<div id="recaptcha-container"></div>

<button type="submit" id="sendOtpBtn" class="verify-btn">
    <i class="fa-solid fa-paper-plane"></i>
    Send OTP
</button>

</form>

<br>

<a href="login.php">

Already have an account? Login

</a>

</div>

</div>

<script type="module" src="../assets/js/firebase-config.js"></script>
<script type="module" src="../assets/js/firebase-auth.js"></script>

</body>

</html>