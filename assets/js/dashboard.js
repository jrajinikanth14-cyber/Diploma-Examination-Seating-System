document.addEventListener("DOMContentLoaded", function () {

    /*=========================================
            DATE & TIME
    =========================================*/

    function updateDateTime() {

        const now = new Date();

        const date = now.toLocaleDateString("en-IN", {
            weekday: "long",
            day: "numeric",
            month: "long",
            year: "numeric"
        });

        const time = now.toLocaleTimeString("en-IN");

        const currentDate =
            document.getElementById("currentDate");

        const currentTime =
            document.getElementById("currentTime");

        if (currentDate) {
            currentDate.textContent = date;
        }

        if (currentTime) {
            currentTime.textContent = time;
        }
    }

    updateDateTime();

    setInterval(updateDateTime, 1000);


    /*=========================================
            SIDEBAR
    =========================================*/

    const menuBtn =
        document.getElementById("menuBtn");

    const sidebar =
        document.getElementById("sidebar");

    const closeSidebar =
        document.getElementById("closeSidebar");

    const overlay =
        document.getElementById("overlay");


    /* OPEN SIDEBAR */

    if (menuBtn) {

        menuBtn.addEventListener("click", function (e) {

            e.preventDefault();
            e.stopPropagation();

            if (sidebar) {
                sidebar.classList.add("active");
            }

            if (overlay) {
                overlay.classList.add("active");
            }

        });

    }


    /* CLOSE SIDEBAR */

    if (closeSidebar) {

        closeSidebar.addEventListener("click", function (e) {

            e.preventDefault();
            e.stopPropagation();

            if (sidebar) {
                sidebar.classList.remove("active");
            }

            if (overlay) {
                overlay.classList.remove("active");
            }

        });

    }


    /* CLICK OVERLAY */

    if (overlay) {

        overlay.addEventListener("click", function () {

            if (sidebar) {
                sidebar.classList.remove("active");
            }

            overlay.classList.remove("active");

        });

    }


    /* CLICK OUTSIDE SIDEBAR */

    document.addEventListener("click", function (e) {

        if (!sidebar) {
            return;
        }

        if (!sidebar.classList.contains("active")) {
            return;
        }

        if (sidebar.contains(e.target)) {
            return;
        }

        if (menuBtn && menuBtn.contains(e.target)) {
            return;
        }

        sidebar.classList.remove("active");

        if (overlay) {
            overlay.classList.remove("active");
        }

    });


    /*=========================================
            PROFILE DROPDOWN
    =========================================*/

    const profileImage =
        document.getElementById("profileImage");

    const profileDropdown =
        document.getElementById("profileDropdown");


    if (profileImage && profileDropdown) {

        profileImage.addEventListener("click", function (e) {

            e.preventDefault();
            e.stopPropagation();

            profileDropdown.classList.toggle("show");

        });

    }


    document.addEventListener("click", function () {

        if (profileDropdown) {

            profileDropdown.classList.remove("show");

        }

    });


    if (profileDropdown) {

        profileDropdown.addEventListener("click", function (e) {

            e.stopPropagation();

        });

    }


    /*=========================================
            PROFILE PHOTO
    =========================================*/

    const profileInput =
        document.getElementById("profileInput");

    const profileHeaderImage =
        document.querySelector(".profile-header img");

    const editProfileIcon =
        document.querySelector(".edit-profile-icon");


    /* OPEN FILE BROWSER */

    if (editProfileIcon && profileInput) {

        editProfileIcon.addEventListener("click", function (e) {

            e.preventDefault();
            e.stopPropagation();

            profileInput.click();

        });

    }


    /* SELECT IMAGE */

    if (profileInput) {

        profileInput.addEventListener("change", function () {

            const file = this.files[0];

            if (!file) {
                return;
            }


            const allowed = [
                "image/jpeg",
                "image/png",
                "image/webp"
            ];


            if (!allowed.includes(file.type)) {

                alert(
                    "Please select JPG, PNG or WEBP image."
                );

                profileInput.value = "";

                return;

            }


            /* PREVIEW */

            const reader = new FileReader();


            reader.onload = function (e) {

                if (profileImage) {

                    profileImage.src =
                        e.target.result;

                }

                if (profileHeaderImage) {

                    profileHeaderImage.src =
                        e.target.result;

                }

            };


            reader.readAsDataURL(file);


            /* UPLOAD */

            const formData =
                new FormData();

            formData.append(
                "profile_photo",
                file
            );


            fetch("upload-profile.php", {

                method: "POST",

                body: formData

            })

            .then(response => response.text())

            .then(result => {

                result = result.trim();

                if (result === "success") {

                    console.log(
                        "Profile photo updated."
                    );

                } else {

                    alert(result);

                }

            })

            .catch(error => {

                console.error(error);

                alert(
                    "Unable to upload profile photo."
                );

            });

        });

    }


    /*=========================================
            DASHBOARD PIE CHART
    =========================================*/

    const chartCanvas =
        document.getElementById("dashboardChart");


    if (
        chartCanvas &&
        typeof Chart !== "undefined"
    ) {

        const chartData = [

            Number(studentCount),

            Number(departmentCount),

            Number(yearCount),

            Number(hallCount),

            Number(seatCount),

            Number(attendanceCount)

        ];


        const hasData =
            chartData.some(
                value => value > 0
            );


        new Chart(chartCanvas, {

            type: "pie",


            data: {

                labels: hasData

                    ? [
                        "Students",
                        "Departments",
                        "Academic Years",
                        "Exam Halls",
                        "Seat Allocation",
                        "Attendance"
                    ]

                    : [
                        "No Data Available"
                    ],


                datasets: [{

                    data: hasData
                        ? chartData
                        : [1],


                    backgroundColor: hasData

                        ? [
                            "#2563eb",
                            "#10b981",
                            "#f59e0b",
                            "#8b5cf6",
                            "#374151",
                            "#ec4899"
                        ]

                        : [
                            "#d1d5db"
                        ],


                    borderColor:
                        "#ffffff",

                    borderWidth:
                        3,

                    hoverOffset:
                        hasData ? 12 : 0

                }]

            },


            options: {

                responsive: true,

                maintainAspectRatio: false,


                plugins: {

                    legend: {

                        position: "bottom",

                        labels: {

                            padding: 20,

                            font: {

                                family:
                                    "Poppins",

                                size:
                                    14

                            }

                        }

                    },


                    tooltip: {

                        enabled:
                            hasData

                    }

                },


                animation: {

                    animateRotate: true,

                    animateScale: true,

                    duration: 1500,

                    easing:
                        "easeOutQuart"

                }

            },


            plugins: [{

                id: "noDataText",

                afterDraw(chart) {

                    if (hasData) {
                        return;
                    }


                    const { ctx } =
                        chart;

                    const meta =
                        chart.getDatasetMeta(0);


                    if (
                        !meta.data.length
                    ) {
                        return;
                    }


                    const x =
                        meta.data[0].x;

                    const y =
                        meta.data[0].y;


                    ctx.save();


                    ctx.textAlign =
                        "center";

                    ctx.textBaseline =
                        "middle";


                    ctx.fillStyle =
                        "#6b7280";


                    ctx.font =
                        "600 18px Poppins";


                    ctx.fillText(
                        "No Data",
                        x,
                        y - 10
                    );


                    ctx.font =
                        "14px Poppins";


                    ctx.fillText(
                        "Available",
                        x,
                        y + 15
                    );


                    ctx.restore();

                }

            }]

        });

    }


    /*=========================================
            DASHBOARD SMART SEARCH
    =========================================*/

    const dashboardSearch =
        document.getElementById(
            "dashboardSearch"
        );


    if (dashboardSearch) {

        const pages = {

            "dashboard":
                "dashboard.php",

            "student":
                "students.php",

            "students":
                "students.php",

            "add student":
                "students.php",

            "department":
                "departments.php",

            "departments":
                "departments.php",

            "academic":
                "academic-years.php",

            "academic year":
                "academic-years.php",

            "academic years":
                "academic-years.php",

            "hall":
                "halls.php",

            "halls":
                "halls.php",

            "exam hall":
                "halls.php",

            "session":
                "examinations.php",

            "sessions":
                "examinations.php",

            "exam session":
                "examinations.php",

            "seat":
                "seat_allocation.php",

            "seat allocation":
                "seat_allocation.php",

            "allocation":
                "seat_allocation.php",

            "attendance":
                "attendance.php",

            "report":
                "reports.php",

            "reports":
                "reports.php",

            "setting":
                "settings.php",

            "settings":
                "settings.php",

            "logout":
                "../auth/logout.php"

        };


        dashboardSearch.addEventListener(
            "keydown",
            function (e) {

                if (e.key !== "Enter") {
                    return;
                }

                e.preventDefault();


                const keyword =
                    this.value
                        .trim()
                        .toLowerCase();


                if (keyword === "") {
                    return;
                }


                if (pages[keyword]) {

                    window.location.href =
                        pages[keyword];

                } else {

                    alert(
                        'No page found for "' +
                        keyword +
                        '"'
                    );

                }

            }
        );

    }


    /*=========================================
            NOTIFICATION SIDE PANEL
    =========================================*/

    const bellBtn =
        document.getElementById(
            "bellBtn"
        );

    const notificationPanel =
        document.getElementById(
            "notificationPanel"
        );

    const notificationOverlay =
        document.getElementById(
            "notificationOverlay"
        );

    const closeNotification =
        document.getElementById(
            "closeNotification"
        );


    /*=========================================
            NOTIFICATION HELPERS
    =========================================*/

    function getNotificationItems() {

        const list =
            document.getElementById(
                "notificationList"
            );

        if (!list) {
            return [];
        }


        return Array.from(
            list.querySelectorAll(
                ".notification-item:not(.no-notifications)"
            )
        );

    }


    function updateNotificationHeading(
        text
    ) {

        const heading =
            document.querySelector(
                ".notification-top > span"
            );

        if (heading) {

            heading.textContent =
                text;

        }

    }


    function updateNotificationUI() {

        const list =
            document.getElementById(
                "notificationList"
            );


        if (!list) {
            return;
        }


        const items =
            getNotificationItems();


        /* NO NOTIFICATIONS */

        if (items.length === 0) {

            list.innerHTML = `

                <div class="notification-item no-notifications">

                    <div class="notification-content">

                        <h4>
                            No Notifications
                        </h4>

                        <p>
                            No notifications found.
                        </p>

                    </div>

                </div>

            `;


            if (clearAllBtn) {

                clearAllBtn.style.display =
                    "none";

            }


            if (markAllRead) {

                markAllRead.style.display =
                    "none";

            }


            const toggleButton =
                document.getElementById(
                    "toggleNotifications"
                );


            if (toggleButton) {

                toggleButton.style.display =
                    "none";

            }


            const badge =
                document.querySelector(
                    ".notification-badge"
                );


            if (badge) {

                badge.textContent = "0";

                badge.style.display =
                    "none";

            }


            updateNotificationHeading(
                "Recent Notifications"
            );

            return;

        }


        /* SHOW CLEAR ALL */

        if (clearAllBtn) {

            clearAllBtn.style.display =
                "inline-flex";

        }


        /* COUNT UNREAD */

        const unreadItems =
            list.querySelectorAll(
                ".notification-item.unread"
            );


        const unreadCount =
            unreadItems.length;


        /* UPDATE BADGE */

        const badge =
            document.querySelector(
                ".notification-badge"
            );


        if (badge) {

            if (unreadCount > 0) {

                badge.textContent =
                    unreadCount;

                badge.style.display =
                    "flex";

            } else {

                badge.textContent =
                    "0";

                badge.style.display =
                    "none";

            }

        }


        /* MARK ALL BUTTON */

        if (markAllRead) {

            if (unreadCount > 0) {

                markAllRead.style.display =
                    "inline-flex";

            } else {

                markAllRead.style.display =
                    "none";

            }

        }


        /* VIEW ALL BUTTON */

        const toggleButton =
            document.getElementById(
                "toggleNotifications"
            );


        if (toggleButton) {

    if (items.length > 10) {

        toggleButton.style.display = "inline-flex";

        toggleButton.textContent =
            "View All Notifications";

    } else {

        toggleButton.style.display = "none";

    }

}

    }


    /*=========================================
            INITIAL NOTIFICATION VIEW
    =========================================*/

    function showRecentNotifications() {

        const items =
            getNotificationItems();


        items.forEach(function (
            item,
            index
        ) {

            if (index < 10) {

                item.style.display =
                    "flex";

            } else {

                item.style.display =
                    "none";

            }

        });


        const toggleButton =
            document.getElementById(
                "toggleNotifications"
            );


        if (toggleButton) {

            toggleButton.textContent =
                "View All Notifications";

            toggleButton.dataset.expanded =
                "false";

        }


        updateNotificationHeading(
            "Recent Notifications"
        );

    }


    /*=========================================
            SHOW ALL NOTIFICATIONS
    =========================================*/

    function showAllNotifications() {

        const items =
            getNotificationItems();


        items.forEach(function (item) {

            item.style.display =
                "flex";

        });


        const toggleButton =
            document.getElementById(
                "toggleNotifications"
            );


        if (toggleButton) {

            toggleButton.textContent =
                "View Recent Notifications";

            toggleButton.dataset.expanded =
                "true";

        }


        updateNotificationHeading(
            "All Notifications"
        );

    }


    /*=========================================
            OPEN NOTIFICATIONS
    =========================================*/

    function openNotification() {

        if (!notificationPanel) {
            return;
        }


        notificationPanel.classList.add(
            "show"
        );


        if (notificationOverlay) {

            notificationOverlay.classList.add(
                "show"
            );

        }


        document.body.classList.add(
            "notification-open"
        );


        /*

            Mark notifications as read.

            This request does NOT reload
            the dashboard page.

        */

        fetch("dashboard.php", {

            method: "POST",

            headers: {

                "Content-Type":
                    "application/x-www-form-urlencoded"

            },

            body:
                "mark_notifications_read=1"

        })

        .then(response =>
            response.text()
        )

        .then(data => {

            if (
                data.trim() !==
                "success"
            ) {

                return;

            }


            document
                .querySelectorAll(
                    ".notification-item.unread"
                )
                .forEach(function (item) {

                    item.classList.remove(
                        "unread"
                    );

                });


            document
                .querySelectorAll(
                    ".notification-dot"
                )
                .forEach(function (dot) {

                    dot.remove();

                });


            updateNotificationUI();

        })

        .catch(error => {

            console.error(
                "Notification read error:",
                error
            );

        });

    }


    /*=========================================
            CLOSE NOTIFICATIONS
    =========================================*/

    function closeNotificationPanel() {

        if (notificationPanel) {

            notificationPanel.classList.remove(
                "show"
            );

        }


        if (notificationOverlay) {

            notificationOverlay.classList.remove(
                "show"
            );

        }


        document.body.classList.remove(
            "notification-open"
        );

    }


    /* BELL */

    if (bellBtn) {

        bellBtn.addEventListener(
            "click",
            function (e) {

                e.preventDefault();

                e.stopPropagation();

                openNotification();

            }
        );

    }


    /* CLOSE BUTTON */

    if (closeNotification) {

        closeNotification.addEventListener(
            "click",
            function (e) {

                e.preventDefault();

                closeNotificationPanel();

            }
        );

    }


    /* OVERLAY */

    if (notificationOverlay) {

        notificationOverlay.addEventListener(
            "click",
            function () {

                closeNotificationPanel();

            }
        );

    }


    /*=========================================
            MARK ALL AS READ
    =========================================*/

    const markAllRead =
        document.getElementById(
            "markAllRead"
        );


    if (markAllRead) {

        markAllRead.addEventListener(
            "click",
            function (e) {

                e.preventDefault();

                e.stopPropagation();


                fetch("dashboard.php", {

                    method: "POST",

                    headers: {

                        "Content-Type":
                            "application/x-www-form-urlencoded"

                    },

                    body:
                        "mark_notifications_read=1"

                })

                .then(response =>
                    response.text()
                )

                .then(data => {

                    if (
                        data.trim() !==
                        "success"
                    ) {

                        alert(
                            "Unable to mark notifications as read."
                        );

                        return;

                    }


                    document
                        .querySelectorAll(
                            ".notification-item.unread"
                        )
                        .forEach(function (item) {

                            item.classList.remove(
                                "unread"
                            );

                        });


                    document
                        .querySelectorAll(
                            ".notification-dot"
                        )
                        .forEach(function (dot) {

                            dot.remove();

                        });


                    updateNotificationUI();

                })

                .catch(error => {

                    console.error(
                        "Mark read error:",
                        error
                    );

                    alert(
                        "Unable to mark notifications as read."
                    );

                });

            }
        );

    }


    /*=========================================
            DELETE SINGLE NOTIFICATION
    =========================================*/

    document.addEventListener(
        "click",
        function (e) {

            const button =
                e.target.closest(
                    ".delete-notification"
                );


            if (!button) {
                return;
            }


            e.preventDefault();

            e.stopPropagation();


            const id =
                button.dataset.id;


            if (!id) {
                return;
            }


            if (
                !confirm(
                    "Delete this notification?"
                )
            ) {

                return;

            }


            fetch("dashboard.php", {

                method: "POST",

                headers: {

                    "Content-Type":
                        "application/x-www-form-urlencoded"

                },

                body:
                    "delete_notification=1&notification_id=" +
                    encodeURIComponent(id)

            })

            .then(response =>
                response.text()
            )

            .then(data => {

                if (
                    data.trim() !==
                    "success"
                ) {

                    alert(
                        "Unable to delete notification."
                    );

                    return;

                }


                const item =
                    button.closest(
                        ".notification-item"
                    );


                if (item) {

                    item.remove();

                }


                updateNotificationUI();

            })

            .catch(error => {

                console.error(
                    "Delete notification error:",
                    error
                );

                alert(
                    "Unable to delete notification."
                );

            });

        }
    );


    /*=========================================
            CLEAR ALL NOTIFICATIONS
    =========================================*/

    const clearAllBtn =
        document.getElementById(
            "clearAllNotifications"
        );


    if (clearAllBtn) {

        clearAllBtn.addEventListener(
            "click",
            function (e) {

                e.preventDefault();

                e.stopPropagation();


                if (
                    !confirm(
                        "Delete all notifications?"
                    )
                ) {

                    return;

                }


                fetch(
                    "mark-all-read.php?action=clear"
                )

                .then(response =>
                    response.text()
                )

                .then(data => {

                    if (
                        data.trim() !==
                        "cleared"
                    ) {

                        alert(
                            "Unable to clear notifications."
                        );

                        return;

                    }


                    const list =
                        document.getElementById(
                            "notificationList"
                        );


                    if (list) {

                        list.innerHTML = `

                            <div class="notification-item no-notifications">

                                <div class="notification-content">

                                    <h4>
                                        No Notifications
                                    </h4>

                                    <p>
                                        No notifications found.
                                    </p>

                                </div>

                            </div>

                        `;

                    }


                    clearAllBtn.style.display =
                        "none";


                    if (markAllRead) {

                        markAllRead.style.display =
                            "none";

                    }


                    const toggleButton =
                        document.getElementById(
                            "toggleNotifications"
                        );


                    if (toggleButton) {

                        toggleButton.style.display =
                            "none";

                    }


                    const badge =
                        document.querySelector(
                            ".notification-badge"
                        );


                    if (badge) {

                        badge.textContent =
                            "0";

                        badge.style.display =
                            "none";

                    }


                    updateNotificationHeading(
                        "Recent Notifications"
                    );

                })

                .catch(error => {

                    console.error(
                        "Clear notifications error:",
                        error
                    );

                    alert(
                        "Unable to clear notifications."
                    );

                });

            }
        );

    }


    /*=========================================
            VIEW ALL / VIEW RECENT
            NO PAGE REFRESH
    =========================================*/

    const toggleNotifications =
        document.getElementById(
            "toggleNotifications"
        );


    if (toggleNotifications) {

        toggleNotifications.addEventListener(
            "click",
            function (e) {

                e.preventDefault();

                e.stopPropagation();


                const expanded =
                    this.dataset.expanded ===
                    "true";


                if (expanded) {

                    /*

                        Currently showing ALL.

                        Change back to latest 10.

                    */

                    showRecentNotifications();

                } else {

                    /*

                        Currently showing latest 10.

                        Show ALL.

                    */

                    showAllNotifications();

                }

            }
        );

    }


    /*=========================================
            INITIALIZE NOTIFICATIONS
    =========================================*/

    const initialNotificationItems =
        getNotificationItems();


    if (
        initialNotificationItems.length >
        0
    ) {

        showRecentNotifications();

    }


    updateNotificationUI();


    /*=========================================
            CLOSE NOTIFICATION WITH ESC
    =========================================*/

    document.addEventListener(
        "keydown",
        function (e) {

            if (e.key === "Escape") {

                closeNotificationPanel();

            }

        }
    );

});
document.addEventListener("DOMContentLoaded", function () {

    const menuBtn = document.getElementById("menuBtn");
    const sidebar = document.getElementById("sidebar");
    const closeSidebar = document.getElementById("closeSidebar");
    const overlay = document.getElementById("overlay");

    function closeMenu() {
        if (sidebar) {
            sidebar.classList.remove("active");
        }

        if (overlay) {
            overlay.classList.remove("active");
        }

        document.body.classList.remove("menu-open");
    }

    // Close sidebar when Escape key is pressed
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" || event.key === "Esc") {
            closeMenu();
        }
    });

});