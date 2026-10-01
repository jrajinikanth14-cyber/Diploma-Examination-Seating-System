<?php
session_start();

if (!isset($_SESSION["reset_verified"])) {
    header("Location: forgot_password.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Reset Password</title>

<link rel="stylesheet" href="../assets/css/register.css">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

</head>

<body>

<div class="container">

<div class="register-card">

<h1>Reset Password</h1>

<p>Create a new password for your administrator account.</p>

<?php

if(isset($_GET["error"])){

    if($_GET["error"]=="match"){

        echo "<p style='color:#EA4335;margin-bottom:15px;'>
                Passwords do not match.
              </p>";

    }

    if($_GET["error"]=="password"){

        echo "<p style='color:#EA4335;margin-bottom:15px;'>
                Please enter a stronger password.
              </p>";

    }

}

?>

<form action="update_password.php" method="POST">

    <!-- Password -->

    <div class="input-box">

        <i class="fa-solid fa-lock"></i>

        <input
        type="password"
        id="password"
        name="password"
        placeholder="New Password"
        required>

        <i class="fa-regular fa-eye toggle-password"
        id="togglePassword"></i>

    </div>

    <p id="strengthMessage"></p>

    <!-- Confirm Password -->

    <div class="input-box">

        <i class="fa-solid fa-lock"></i>

        <input
        type="password"
        id="confirmPassword"
        name="confirm_password"
        placeholder="Confirm Password"
        required>

        <i class="fa-regular fa-eye toggle-password"
        id="toggleConfirmPassword"></i>

    </div>

    <button class="register-btn" type="submit">

        Update Password

    </button>

</form>

<br>

<a href="login.php">

Back to Login

</a>

</div>

</div>

<script src="../assets/js/password.js"></script>

</body>

</html>