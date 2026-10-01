<?php
// ============================================================
// Gmail SMTP Configuration
// ============================================================
//
// Before using Gmail SMTP:
//
// 1. Use your own Gmail account.
//
// 2. Enable 2-Step Verification:
//    Google Account → Security → 2-Step Verification
//
// 3. Create a Google App Password:
//    Google Account → Security → 2-Step Verification
//    → App passwords
//
// 4. Create an App Password for this project.
//
// 5. Copy the generated 16-character App Password
//    and enter it below.
//
// IMPORTANT:
// - Do NOT use your normal Gmail password.
// - Use the Google App Password instead.
// - Never share your App Password publicly.
// - Never upload your real App Password to GitHub.
//
// Replace the values below with YOUR own Gmail details.
// ============================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Load PHPMailer manually (without Composer)
require_once __DIR__ . "/../PHPMailer/src/Exception.php";
require_once __DIR__ . "/../PHPMailer/src/PHPMailer.php";
require_once __DIR__ . "/../PHPMailer/src/SMTP.php";

function sendOTP($to, $name, $otp)
{
    $mail = new PHPMailer(true);
    
    try {
        
// Disable SMTP debug output
    $mail->SMTPDebug = 0;
        // SMTP Settings
         $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
       

        $mail->Username = "YOUR_GMAIL@gmail.com";
        $mail->Password = "YOUR_GMAIL_APP_PASSWORD";
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Sender
        $mail->setFrom(
    $mail->Username,
    "Examination Seating Management System"
);
        $mail->addReplyTo(
    $mail->Username,
    "Examination Seating Management System"
);
        $mail->addAddress($to);

        // Email Content
        $mail->isHTML(true);
        $mail->AltBody = "Your OTP is $otp. This OTP is valid for 10 minutes. If you did not request this OTP, ignore this email.";
       $mail->Subject = "Your OTP for Examination Seating Management System";

        $mail->Body = "
<div style='font-family:Arial,sans-serif;background:#f4f6f9;padding:20px;'>

<div style='max-width:600px;margin:auto;background:#ffffff;padding:30px;border-radius:10px;border:1px solid #ddd;'>

<h2 style='color:#123d88;text-align:center;'>
Examination Seating Management System
</h2>

<p>Dear <b>$name</b>,</p>

<p>Your One-Time Password (OTP) is:</p>

<h1 style='text-align:center;color:#0B57D0;'>$otp</h1>

<p>This OTP is valid for <strong>10 minutes</strong>.</p>

<p>If you did not request this OTP, you can safely ignore this email.</p>

<hr>

<p style='text-align:center;font-size:12px;color:#777;'>
© 2026 Examination Seating Management System
</p>

</div>

</div>
";

        $mail->send();

        return true;

    } catch (Exception $e) {

        return $e->getMessage();

    }
}
?>