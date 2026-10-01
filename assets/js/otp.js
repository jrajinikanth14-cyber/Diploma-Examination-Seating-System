window.addEventListener("DOMContentLoaded", () => {

    let timeLeft = 30;

    const countdown = document.getElementById("countdown");
    const resendBtn = document.getElementById("resendBtn");
    const timer = document.getElementById("timer");

    const interval = setInterval(() => {

        timeLeft--;

        countdown.textContent = timeLeft;

        if (timeLeft <= 0) {

            clearInterval(interval);

            resendBtn.disabled = false;

            timer.style.display = "none";

        }

    }, 1000);

    resendBtn.addEventListener("click", () => {

        window.location.href = "send_otp.php?resend=1";

    });

});