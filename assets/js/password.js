window.addEventListener("DOMContentLoaded", () => {

    const password = document.getElementById("password");
    const confirmPassword = document.getElementById("confirmPassword");

    const togglePassword = document.getElementById("togglePassword");
    const toggleConfirmPassword = document.getElementById("toggleConfirmPassword");

    function toggle(input, icon) {

        if (input.type === "password") {

            input.type = "text";
            icon.classList.replace("fa-eye", "fa-eye-slash");

        } else {

            input.type = "password";
            icon.classList.replace("fa-eye-slash", "fa-eye");

        }

    }

    if (togglePassword) {
        togglePassword.onclick = () => toggle(password, togglePassword);
    }

    if (toggleConfirmPassword) {
        toggleConfirmPassword.onclick = () => toggle(confirmPassword, toggleConfirmPassword);
    }

    document.querySelector("form").addEventListener("submit", (e) => {

        const pattern =
        /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&^#])[A-Za-z\d@$!%*?&^#]{8,}$/;

        if (!pattern.test(password.value)) {

            e.preventDefault();
            alert("Please enter a valid password.");
            password.focus();
            return;

        }

        if (password.value !== confirmPassword.value) {

            e.preventDefault();
            alert("Passwords do not match.");
            confirmPassword.focus();
            return;

        }

    });

});