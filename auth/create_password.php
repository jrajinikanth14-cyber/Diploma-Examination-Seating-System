<?php
session_start();

if (!isset($_SESSION["otp_verified"])) {
    header("Location: register.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Create Password</title>

<link rel="stylesheet" href="../assets/css/register.css">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

</head>

<body>

<div class="container">

<div class="register-card">

<h1>Create Password</h1>

<p>Create a strong password for your administrator account.</p>

<form action="register_process.php" method="POST">
  <!-- Full Name -->
<div class="input-box">

    <i class="fa-solid fa-user"></i>

    <input
        type="text"
        id="full_name"
        name="full_name"
        placeholder="Enter Full Name"
        required>

</div>

<!-- New Password -->
<div class="input-box">

    <i class="fa-solid fa-lock"></i>

    <input
        type="password"
        id="password"
        name="password"
        placeholder="New Password"
        required>

    <i class="fa-regular fa-eye toggle-password" id="togglePassword"></i>

</div>

<!-- Confirm Password -->
<div class="input-box">

    <i class="fa-solid fa-lock"></i>

    <input
        type="password"
        id="confirmPassword"
        name="confirm_password"
        placeholder="Confirm Password"
        required>

    <i class="fa-regular fa-eye toggle-password" id="toggleConfirmPassword"></i>

</div>

<br><br>

<button type="submit" class="register-btn">
    Create Account
</button>
</form>

</div>

</div>

<script src="../assets/js/password.js"></script>

</body>

</html>