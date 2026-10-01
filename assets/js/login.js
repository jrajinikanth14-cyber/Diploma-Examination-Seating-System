// =======================================
// LOGIN PAGE
// =======================================

document.addEventListener("DOMContentLoaded", function () {

    const password = document.getElementById("password");
    const togglePassword = document.getElementById("togglePassword");
    const form = document.querySelector("form");

    if (password && togglePassword) {

        // Show/Hide Password
        togglePassword.addEventListener("click", function () {

            if (password.type === "password") {
                password.type = "text";
                togglePassword.classList.remove("fa-eye");
                togglePassword.classList.add("fa-eye-slash");
            } else {
                password.type = "password";
                togglePassword.classList.remove("fa-eye-slash");
                togglePassword.classList.add("fa-eye");
            }

        });

        // Submit on Enter
        password.addEventListener("keydown", function (event) {
            if (event.key === "Enter") {
                event.preventDefault();
                form.submit();
            }
        });

    } else {
        console.log("Password or toggle icon not found.");
    }

});