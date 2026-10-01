<?php
session_start();

if (!isset($_SESSION["admin"])) {
    exit("Unauthorized");
}

include "../config/db_connect.php";

$adminId = $_SESSION["admin"];

if (isset($_FILES["profile_photo"])) {

    $file = $_FILES["profile_photo"];

    // Check upload error
    if ($file["error"] != 0) {
        exit("Upload failed.");
    }

    // Allowed extensions
    $allowed = ["jpg", "jpeg", "png", "webp"];

    $extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));

    if (!in_array($extension, $allowed)) {
        exit("Invalid image format.");
    }

    // Generate unique filename
    $newFileName = "admin_" . time() . "." . $extension;

    $uploadPath = "../assets/uploads/" . $newFileName;

    if (move_uploaded_file($file["tmp_name"], $uploadPath)) {

        // Update database
        $stmt = $conn->prepare("UPDATE users SET profile_photo=? WHERE id=?");
        $stmt->bind_param("si", $newFileName, $adminId);
        $stmt->execute();

        echo "success";

    } else {

        echo "Upload failed.";

    }

}
?>