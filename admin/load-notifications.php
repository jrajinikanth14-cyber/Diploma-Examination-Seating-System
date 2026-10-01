<?php
session_start();

if (!isset($_SESSION["admin"])) {
    exit;
}

include "../config/db_connect.php";

$result = mysqli_query($conn,"
SELECT *
FROM notifications
ORDER BY created_at DESC
");

if(mysqli_num_rows($result)>0){

while($row=mysqli_fetch_assoc($result)){

$type = strtolower($row["type"]);

$icon = "student-added";
$fa = "fa-user-plus";

switch ($type) {

    case "student_added":
        $icon = "student-added";
        $fa = "fa-user-plus";
        break;

    case "student_imported":
        $icon = "student-imported";
        $fa = "fa-file-import";
        break;

    case "student_exported":
        $icon = "student-exported";
        $fa = "fa-file-export";
        break;

    case "student_deleted":
        $icon = "student-deleted";
        $fa = "fa-trash";
        break;

    case "students_deleted":
        $icon = "student-deleted";
        $fa = "fa-trash";
        break;

    case "department":
        $icon = "department";
        $fa = "fa-building";
        break;

    case "exam":
        $icon = "exam";
        $fa = "fa-calendar-days";
        break;

    case "seat":
        $icon = "seat";
        $fa = "fa-chair";
        break;

    case "attendance":
        $icon = "attendance";
        $fa = "fa-clipboard-check";
        break;
}

?>

<div class="notification-item <?php echo !$row['is_read']?'unread':'';?>">

<div class="notification-icon <?php echo $icon;?>">
<i class="fa-solid <?php echo $fa;?>"></i>
</div>

<div class="notification-content">

<h4><?php echo htmlspecialchars($row["title"]);?></h4>

<p><?php echo htmlspecialchars($row["message"]);?></p>

<div class="notification-time">
<?php echo date("d M Y h:i A",strtotime($row["created_at"]));?>
</div>

</div>

<?php if(!$row['is_read']){ ?>
<div class="notification-dot"></div>
<?php } ?>

</div>

<?php

}

}else{

echo '
<div class="notification-item">
<div class="notification-content">
<h4>No Notifications</h4>
<p>No notifications found.</p>
</div>
</div>';

}
?>