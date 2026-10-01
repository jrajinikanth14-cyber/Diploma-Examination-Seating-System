<?php
session_start();
include "../config/db_connect.php";

if (!isset($_SESSION["admin"])) {
    exit("error");
}

$action = "";

if (isset($_POST["action"])) {
    $action = $_POST["action"];
}

if (isset($_GET["action"])) {
    $action = $_GET["action"];
}

    if ($action == "mark") {
        mysqli_query($conn, "
            UPDATE notifications
            SET is_read = 1
            WHERE is_read = 0
        ");

        echo "marked";
        exit();
    }

    if ($action == "clear") {

        mysqli_query($conn, "
            DELETE FROM notifications
        ");

        echo "cleared";
        exit();
    }



echo "error";