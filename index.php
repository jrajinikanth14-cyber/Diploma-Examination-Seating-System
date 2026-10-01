
<?php

/* =========================================================
   DATABASE CONNECTION
   ========================================================= */

include "config/db_connect.php";

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}


/* =========================================================
   SYSTEM STATISTICS
   ========================================================= */

// Total Students
$studentResult = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total FROM students"
);

if (!$studentResult) {
    die("Students query failed: " . mysqli_error($conn));
}

$students = mysqli_fetch_assoc($studentResult);


// Total Halls
$hallResult = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total FROM halls"
);

if (!$hallResult) {
    die("Halls query failed: " . mysqli_error($conn));
}

$halls = mysqli_fetch_assoc($hallResult);


// Total Examinations
$examResult = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total FROM examinations"
);

if (!$examResult) {
    die("Examinations query failed: " . mysqli_error($conn));
}

$exams = mysqli_fetch_assoc($examResult);


// Total Seat Allocations
$seatResult = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total FROM allocation_seats"
);

if (!$seatResult) {
    die("Seat allocations query failed: " . mysqli_error($conn));
}

$seats = mysqli_fetch_assoc($seatResult);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Examination Seating Management System</title>


    <!-- Main CSS -->
    <link rel="stylesheet" href="assets/css/index.css">


    <!-- Google Font -->
    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet"
    >


    <!-- Font Awesome -->
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
    >

</head>


<body>


<!-- =========================================================
     HEADER
========================================================= -->

<header class="header">

    <div class="container navbar">


        <!-- Logo -->

        <a href="index.php" class="logo">

            <img src="assets/images/logo.png" alt="Logo">

            <div class="logo-text">

                <h2>Exam Seating</h2>

                <span>Management System</span>

            </div>

        </a>


        <!-- Navigation -->

        <nav>

            <ul class="nav-links">

                <li>
                    <a href="#home" class="active">
                        Home
                    </a>
                </li>

                <li>
                    <a href="#about">
                        About
                    </a>
                </li>

                <li>
                    <a href="#features">
                        Features
                    </a>
                </li>

                <li>
                    <a href="#workflow">
                        Workflow
                    </a>
                </li>

                <li>
                    <a href="#contact">
                        Contact
                    </a>
                </li>

            </ul>

        </nav>


        <!-- Login Button -->

        <a href="auth/login.php" class="login-btn">

            <i class="fa-solid fa-right-to-bracket"></i>

            Login

        </a>


    </div>

</header>



<!-- =========================================================
     HERO
========================================================= -->

<section class="hero" id="home">

    <div class="container hero-container">


        <div class="hero-text">


            <span class="badge">

                Smart Examination Management

            </span>


            <h1>

                Examination Seating Management System

            </h1>


            <p>

                A modern web-based platform that automates examination hall
                seating arrangements, reduces manual work, prevents seating
                conflicts, and generates accurate seating plans within seconds.

            </p>


            <div class="hero-buttons">


                <a href="auth/login.php" class="btn-primary">

                    Get Started

                </a>


                <a href="#features" class="btn-secondary">

                    Learn More

                </a>


            </div>


        </div>



        <div class="hero-image">

            <img
                src="assets/images/logo.png"
                alt="Hero"
            >

        </div>


    </div>

</section>



<!-- =========================================================
     ABOUT
========================================================= -->

<section class="about" id="about">

    <div class="container">


        <!-- About Content -->

        <div class="about-content" data-aos="fade-up">


            <span class="section-tag">

                ABOUT THE SYSTEM

            </span>


            <h2>

                Modernizing Examination Seating Management

            </h2>


            <p>

                The Examination Seating Management System is an intelligent
                web application designed to simplify and automate the
                examination seating process for educational institutions.
                It minimizes manual effort, eliminates seating conflicts,
                optimizes hall utilization, and generates printable seating
                reports with speed and accuracy.

            </p>


            <div class="about-points">


                <div>

                    <i class="fa-solid fa-circle-check"></i>

                    Automated Seating Allocation

                </div>


                <div>

                    <i class="fa-solid fa-circle-check"></i>

                    Conflict-Free Hall Arrangement

                </div>


                <div>

                    <i class="fa-solid fa-circle-check"></i>

                    Secure Administrator Access

                </div>


                <div>

                    <i class="fa-solid fa-circle-check"></i>

                    Instant Report Generation

                </div>


            </div>


        </div>



        <!-- Cards Below Paragraph -->

        <div class="about-cards" data-aos="fade-up">


            <div class="info-card">


                <div class="card-icon">

                    <i class="fa-solid fa-bullseye"></i>

                </div>


                <h3>

                    Our Mission

                </h3>


                <p>

                    To provide educational institutions with an efficient,
                    secure, and fully automated examination seating solution
                    that saves time and improves operational accuracy.

                </p>


            </div>



            <div class="info-card">


                <div class="card-icon">

                    <i class="fa-solid fa-eye"></i>

                </div>


                <h3>

                    Our Vision

                </h3>


                <p>

                    To become a reliable digital platform that transforms
                    examination management through intelligent automation
                    and innovative technology.

                </p>


            </div>



            <div class="info-card">


                <div class="card-icon">

                    <i class="fa-solid fa-award"></i>

                </div>


                <h3>

                    Why Choose Our System?

                </h3>


                <p>

                    Built with security, accuracy, scalability, and
                    simplicity in mind, our system helps institutions
                    conduct examinations efficiently while reducing manual
                    workload and administrative complexity.

                </p>


            </div>


        </div>


    </div>

</section>



<!-- =========================================================
     FEATURES
========================================================= -->

<section class="features" id="features">

    <div class="container">


        <div class="section-heading" data-aos="fade-up">


            <span>

                CORE FEATURES

            </span>


            <h2>

                Powerful Features for Smarter Examination Management

            </h2>


            <p>

                Designed to simplify examination planning with intelligent
                automation, secure administration, and accurate seating
                allocation for educational institutions.

            </p>


        </div>



        <div class="feature-grid">


            <!-- Student Management -->

            <div
                class="feature-card"
                data-aos="zoom-in"
                data-aos-delay="100"
            >

                <div class="feature-icon">

                    <i class="fa-solid fa-user-graduate"></i>

                </div>


                <h3>

                    Student Management

                </h3>


                <p>

                    Manage student records, departments, semesters, and roll
                    numbers efficiently from a centralized system.

                </p>


            </div>



            <!-- Hall Management -->

            <div
                class="feature-card"
                data-aos="zoom-in"
                data-aos-delay="200"
            >

                <div class="feature-icon">

                    <i class="fa-solid fa-building"></i>

                </div>


                <h3>

                    Hall Management

                </h3>


                <p>

                    Configure examination halls with seating capacity and room
                    information for accurate seat planning.

                </p>


            </div>



            <!-- Subject Allocation -->

            <div
                class="feature-card"
                data-aos="zoom-in"
                data-aos-delay="300"
            >

                <div class="feature-icon">

                    <i class="fa-solid fa-book-open"></i>

                </div>


                <h3>

                    Subject Allocation

                </h3>


                <p>

                    Organize examination subjects, schedules, and student
                    assignments with ease.

                </p>


            </div>



            <!-- Automatic Seat Allocation -->

            <div
                class="feature-card"
                data-aos="zoom-in"
                data-aos-delay="400"
            >

                <div class="feature-icon">

                    <i class="fa-solid fa-chair"></i>

                </div>


                <h3>

                    Automatic Seat Allocation

                </h3>


                <p>

                    Generate conflict-free seating arrangements instantly while
                    maximizing hall utilization.

                </p>


            </div>



            <!-- Reports -->

            <div
                class="feature-card"
                data-aos="zoom-in"
                data-aos-delay="500"
            >

                <div class="feature-icon">

                    <i class="fa-solid fa-file-pdf"></i>

                </div>


                <h3>

                    Reports & Printing

                </h3>


                <p>

                    Export professional seating charts, hall allotments, and
                    examination reports in printable format.

                </p>


            </div>



            <!-- Secure Administration -->

            <div
                class="feature-card"
                data-aos="zoom-in"
                data-aos-delay="600"
            >

                <div class="feature-icon">

                    <i class="fa-solid fa-shield-halved"></i>

                </div>


                <h3>

                    Secure Administration

                </h3>


                <p>

                    Protect administrative access with secure authentication and
                    centralized system management.

                </p>


            </div>


        </div>


    </div>

</section>



<!-- =========================================================
     HOW IT WORKS
========================================================= -->

<section class="process" id="workflow">

    <div class="container">


        <div class="section-title">


            <span>

                HOW IT WORKS

            </span>


            <h2>

                Simple 5-Step Examination Workflow

            </h2>


            <p>

                Our intelligent examination seating system automates the entire
                seating process from student registration to printable reports.

            </p>


        </div>



        <div class="process-grid">


            <!-- Step 01 -->

            <div class="process-card">

                <div class="step">

                    01

                </div>


                <div class="icon">

                    <i class="fa-solid fa-user-plus"></i>

                </div>


                <h3>

                    Register Students

                </h3>


                <p>

                    Add student details including roll number, department,
                    semester and examination information.

                </p>


            </div>



            <!-- Step 02 -->

            <div class="process-card">

                <div class="step">

                    02

                </div>


                <div class="icon">

                    <i class="fa-solid fa-building"></i>

                </div>


                <h3>

                    Configure Halls

                </h3>


                <p>

                    Create examination halls and define seating capacity for
                    every room.

                </p>


            </div>



            <!-- Step 03 -->

            <div class="process-card">

                <div class="step">

                    03

                </div>


                <div class="icon">

                    <i class="fa-solid fa-book-open"></i>

                </div>


                <h3>

                    Assign Subjects

                </h3>


                <p>

                    Configure subjects and examination schedules before seat
                    allocation.

                </p>


            </div>



            <!-- Step 04 -->

            <div class="process-card">

                <div class="step">

                    04

                </div>


                <div class="icon">

                    <i class="fa-solid fa-chair"></i>

                </div>


                <h3>

                    Generate Seating

                </h3>


                <p>

                    The system automatically generates conflict-free seating
                    arrangements.

                </p>


            </div>



            <!-- Step 05 -->

            <div class="process-card">

                <div class="step">

                    05

                </div>


                <div class="icon">

                    <i class="fa-solid fa-file-pdf"></i>

                </div>


                <h3>

                    Export Reports

                </h3>


                <p>

                    Download printable seating plans, hall allotments and
                    examination reports instantly.

                </p>


            </div>


        </div>


    </div>

</section>



<!-- =========================================================
     SYSTEM STATISTICS
========================================================= -->

<section class="stats" id="statistics">

    <div class="container">


        <div
            class="section-heading stats-heading"
            data-aos="fade-up"
        >

            <span>

                SYSTEM STATISTICS

            </span>


            <h2>

                Trusted Performance & Reliable Automation

            </h2>


            <p>

                Real-time statistics from the Examination Seating Management
                System, providing accurate insights into student records,
                examination halls, scheduled examinations, and automated
                seat allocations.

            </p>


        </div>



        <div class="stats-grid">


            <!-- =================================================
                 STUDENTS
            ================================================== -->

            <div
                class="stat-card"
                data-aos="zoom-in"
            >

                <div class="stat-icon">

                    <i class="fa-solid fa-user-graduate"></i>

                </div>


                <h3
                    class="counter"
                    data-target="<?php echo (int)$students['total']; ?>"
                >

                    0

                </h3>


                <p>

                    Registered Students

                </p>


            </div>



            <!-- =================================================
                 HALLS
            ================================================== -->

            <div
                class="stat-card"
                data-aos="zoom-in"
                data-aos-delay="100"
            >

                <div class="stat-icon">

                    <i class="fa-solid fa-building"></i>

                </div>


                <h3
                    class="counter"
                    data-target="<?php echo (int)$halls['total']; ?>"
                >

                    0

                </h3>


                <p>

                    Examination Halls

                </p>


            </div>



            <!-- =================================================
                 EXAMINATIONS
            ================================================== -->

            <div
                class="stat-card"
                data-aos="zoom-in"
                data-aos-delay="200"
            >

                <div class="stat-icon">

                    <i class="fa-solid fa-file-signature"></i>

                </div>


                <h3
                    class="counter"
                    data-target="<?php echo (int)$exams['total']; ?>"
                >

                    0

                </h3>


                <p>

                    Scheduled Examinations

                </p>


            </div>



            <!-- =================================================
                 SEAT ALLOCATIONS
            ================================================== -->

            <div
                class="stat-card"
                data-aos="zoom-in"
                data-aos-delay="300"
            >

                <div class="stat-icon">

                    <i class="fa-solid fa-chair"></i>

                </div>


                <h3
                    class="counter"
                    data-target="<?php echo (int)$seats['total']; ?>"
                >

                    0

                </h3>


                <p>

                    Seat Allocations

                </p>


            </div>


        </div>


    </div>

</section>



<!-- =========================================================
     CALL TO ACTION
========================================================= -->

<section class="cta">

    <div class="container">


        <div
            class="cta-box"
            data-aos="zoom-in"
        >


            <span class="cta-badge">

                GET STARTED TODAY

            </span>


            <h2>

                Ready to Simplify Examination Seating Management?

            </h2>


            <p>

                Automate seat allocation, eliminate manual errors, optimize
                examination hall utilization, and generate professional seating
                reports with a secure and intelligent examination seating
                management solution.

            </p>


            <div class="cta-buttons">


                <a
                    href="auth/login.php"
                    class="btn-primary"
                >

                    <i class="fa-solid fa-right-to-bracket"></i>

                    Get Started

                </a>


                <a
                    href="#features"
                    class="btn-outline"
                >

                    <i class="fa-solid fa-circle-info"></i>

                    Explore Features

                </a>


            </div>


        </div>


    </div>

</section>



<!-- =========================================================
     FOOTER
========================================================= -->

<footer
    class="footer"
    id="contact"
>

    <div class="container">


        <div class="footer-grid">


            <!-- About -->

            <div class="footer-column">


                <div class="footer-logo">


                    <img
                        src="assets/images/logo.png"
                        alt="Logo"
                    >


                    <h3>

                        Exam Seating

                    </h3>


                </div>


                <p>

                    The Examination Seating Management System is a modern web
                    application that automates examination seating allocation,
                    minimizes manual work, and improves examination management
                    with secure and efficient digital solutions.

                </p>


            </div>



            <!-- Quick Links -->

            <div class="footer-column">


                <h4>

                    Quick Links

                </h4>


                <ul>


                    <li>

                        <a href="#home">

                            Home

                        </a>

                    </li>


                    <li>

                        <a href="#about">

                            About

                        </a>

                    </li>


                    <li>

                        <a href="#features">

                            Features

                        </a>

                    </li>


                    <li>

                        <a href="#workflow">

                            Workflow

                        </a>

                    </li>


                    <li>

                        <a href="#statistics">

                            Statistics

                        </a>

                    </li>


                </ul>


            </div>



            <!-- System -->

            <div class="footer-column">


                <h4>

                    System Modules

                </h4>


                <ul>


                    <li>

                        Student Management

                    </li>


                    <li>

                        Hall Management

                    </li>


                    <li>

                        Seat Allocation

                    </li>


                    <li>

                        Reports

                    </li>


                    <li>

                        Administrator Panel

                    </li>


                </ul>


            </div>



            <!-- Contact -->

            <div class="footer-column">


                <h4>

                    Contact

                </h4>


                <ul>


                    <li>

                        <i class="fa-solid fa-envelope"></i>

                        support@example.com

                    </li>


                    <li>

                        <i class="fa-solid fa-phone"></i>

                        Your Mobile Number

                    </li>


                    <li>

                        <i class="fa-solid fa-location-dot"></i>

                        Examination Cell

                    </li>


                </ul>


            </div>


        </div>



        <div class="footer-bottom">


            <p>

                © 2026 Examination Seating Management System.
                All Rights Reserved.

            </p>


        </div>


    </div>

</footer>



<!-- =========================================================
     SYSTEM STATISTICS COUNTER JAVASCRIPT
========================================================= -->

<script>

document.addEventListener("DOMContentLoaded", function () {


    const counters = document.querySelectorAll(".counter");


    counters.forEach(function (counter) {


        const target = parseInt(
            counter.getAttribute("data-target"),
            10
        ) || 0;


        let current = 0;


        /*
         * Counter duration in milliseconds
         */
        const duration = 1500;


        /*
         * Number of animation frames
         */
        const frameDuration = 20;


        /*
         * Calculate increment
         */
        const increment = target / (duration / frameDuration);


        /*
         * If target is 0
         */
        if (target === 0) {

            counter.textContent = "0";

            return;

        }


        /*
         * Counter animation
         */
        function updateCounter() {


            current += increment;


            if (current >= target) {

                counter.textContent = target;

                return;

            }


            counter.textContent = Math.floor(current);


            setTimeout(updateCounter, frameDuration);

        }


        /*
         * Start animation
         */
        updateCounter();


    });


});

</script>



</body>

</html>
