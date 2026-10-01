<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Examination Seating Management System</title>

    <!-- CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">
    

    <!-- Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"/>

</head>

<body>

<div class="container">

    <!-- LEFT SIDE -->

    <div class="left-panel">

        <div class="left-content">

           <img src="../assets/images/logo.png" class="logo" alt="Logo">
            <h1>Examination Seating</h1>

            <h2>Management System</h2>

            <p>
                Smart • Secure • Automated
            </p>

        </div>

    </div>

    <!-- RIGHT SIDE -->

    <div class="right-panel">

        <div class="login-card">

            <h1 id="loginTitle">Get Started</h1>
            <?php

// Registration successful
if (isset($_GET["registered"])) {
    echo "<p class='login-success'>
            Registration completed successfully. Please log in.
          </p>";
}



?>
<p id="loginSubtitle">
Welcome to the Examination Seating Management System
</p>
            <form action="login_process.php" method="POST">

    <!-- Username -->
    <div class="input-box">

        <i class="fa-solid fa-envelope"></i>

        <input
    type="text"
    name="login"
    placeholder="Enter Email or Mobile Number"
    value="<?php echo htmlspecialchars($_GET["login"] ?? "", ENT_QUOTES, "UTF-8"); ?>"
    autocomplete="off"
    required>

    </div>

    <!-- Password -->
    <div class="input-box">

        <i class="fa-solid fa-lock lock-icon"></i>

        <input
            type="password"
            id="password"
            name="password"
            placeholder="Enter Password"
            required>

        <i class="fa-regular fa-eye" id="togglePassword"></i>

    </div>

   <div class="login-options">

    <a href="forgot_password.php">Forgot Password?</a>

    <a href="register.php">Register</a>

</div>
    <!-- Login Button -->

    <button class="login-btn" type="submit">

        <i class="fa-solid fa-right-to-bracket"></i>

        Login

    </button>

</form>
            <?php

if (isset($_GET["error"])) {

    if ($_GET["error"] == "user") {

        echo "<p class='login-error'>Invalid email or mobile number.</p>";

    } elseif ($_GET["error"] == "password") {

        echo "<p class='login-error'>Invalid password.</p>";

    }

}

?>

        </div>

    </div>

</div>

<script src="../assets/js/login.js"></script>

</body>

</html>