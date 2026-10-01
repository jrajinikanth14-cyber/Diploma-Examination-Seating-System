import { auth } from "./firebase-config.js";
import {
    RecaptchaVerifier,
    signInWithPhoneNumber
} from "https://www.gstatic.com/firebasejs/12.2.1/firebase-auth.js";

window.addEventListener("DOMContentLoaded", () => {

    // Create reCAPTCHA
    window.recaptchaVerifier = new RecaptchaVerifier(auth, "recaptcha-container", {
        size: "normal",
        callback: () => {
            console.log("reCAPTCHA verified");
        }
    });

    const sendBtn = document.getElementById("sendOtpBtn");

    sendBtn.addEventListener("click", () => {

        const input = document.getElementById("login").value.trim();

// Email
const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

// Mobile
const mobilePattern = /^[6-9]\d{9}$/;

if (emailPattern.test(input)) {

    // Submit the PHP form for PHPMailer
    document.getElementById("registerForm").submit();
    return;

}

if (!mobilePattern.test(input)) {

    alert("Enter a valid Email or 10-digit Mobile Number.");
    return;

}

let mobile = "+91" + input;
        signInWithPhoneNumber(auth, mobile, window.recaptchaVerifier)
            .then((confirmationResult) => {

                window.confirmationResult = confirmationResult;

                alert("OTP sent successfully.");

                // Don't redirect yet
                console.log("OTP sent");

            })
            .catch((error) => {

                console.error(error);
                alert(error.message);

            });

    });

});