# Examination Seating Arrangement System

A web-based **Examination Seating Arrangement and Management System** designed to simplify examination hall management, student seating allocation, attendance, and report generation.

The system is developed using **PHP and MySQL/MariaDB** and is designed to run locally using **XAMPP**.

---

## 📌 Project Overview

The Examination Seating Arrangement System helps administrators manage examination-related activities from a centralized dashboard.

The system provides features for:

* Student management
* Department management
* Academic year and semester management
* Examination management
* Examination hall management
* Hall bench configuration
* Automatic seating allocation
* Attendance management
* Seating arrangement reports
* Notifications
* System settings
* Admin authentication and OTP verification

---

## ✨ Features

### 👨‍🎓 Student Management

* Add, edit, and manage student details
* Store student PIN/Register number
* Manage department, academic year, semester, and contact details

### 🏫 Hall Management

* Create and manage examination halls
* Configure rows and columns
* Configure benches for individual hall positions
* Define bench capacity
* Store building and floor information

### 📝 Examination Management

* Create examinations
* Add subject name and subject code
* Select department, academic year, and semester
* Configure examination date and time
* Set examination duration

### 💺 Seating Arrangement

* Generate examination seating arrangements
* Allocate students to available halls
* Store row and column positions
* Store bench information and seating slots
* Maintain allocation records

### 📋 Attendance

* Generate examination attendance information
* Mark students as Present or Absent
* Store attendance records

### 📊 Reports

* Generate examination seating reports
* Display student and hall allocation details
* Support examination-related report generation

### 🔔 Notifications

* Display system notifications
* Maintain notification status

### 🔐 Authentication

* Admin registration and login
* OTP-based verification
* Forgot password functionality
* Password reset functionality
* Session-based authentication

---

## 🛠️ Technologies Used

| Technology      | Purpose                       |
| --------------- | ----------------------------- |
| PHP             | Backend development           |
| MySQL / MariaDB | Database                      |
| HTML5           | Page structure                |
| CSS3            | User interface styling        |
| JavaScript      | Client-side functionality     |
| Bootstrap       | UI components                 |
| Font Awesome    | Icons                         |
| PHPMailer       | Email/OTP functionality       |
| Composer        | PHP dependency management     |
| XAMPP           | Local development environment |

---

## 📁 Project Structure

```text
Examination-Seating-System/
│
├── admin/
│   ├── attendance.php
│   ├── dashboard.php
│   ├── examinations.php
│   ├── reports.php
│   ├── seat_allocation.php
│   ├── settings.php
│   └── students.php
│
├── assets/
│   └── uploads/
│
├── auth/
│   ├── login.php
│   ├── register.php
│   ├── forgot_password.php
│   ├── reset_password.php
│   └── ...
│
├── config/
│   ├── db_connect.php
│   └── mail_config.php
│
├── database/
│   └── examination_seating_system_structure.sql
│
├── includes/
│   ├── auth_check.php
│   ├── footer.php
│   ├── header.php
│   └── sidebar.php
│
├── PHPMailer/
│
├── vendor/
│
├── composer.json
├── composer.lock
├── .gitignore
└── index.php
```

---

## 💾 Database

The project includes a **structure-only SQL database file**.

Location:

```text
database/examination_seating_system_structure.sql
```

The SQL file contains the database structure, including:

* Tables
* Primary keys
* Foreign keys
* Unique keys
* Indexes
* Auto-increment settings
* Table relationships

It does **not contain project/student/admin data**.

---

## 🚀 Installation

### 1. Install XAMPP

Install XAMPP with:

* Apache
* MySQL

### 2. Copy the Project

Copy the project folder into:

```text
C:\xampp\htdocs\
```

The final location should be:

```text
C:\xampp\htdocs\Examination-Seating-System
```

### 3. Start XAMPP

Open XAMPP Control Panel and start:

```text
Apache
MySQL
```

### 4. Create the Database

Open:

```text
http://localhost/phpmyadmin
```

Create a database named:

```text
examination_seating_system
```

### 5. Import the Database Structure

Open the newly created database in phpMyAdmin.

Select:

**Import**

Choose:

```text
database/examination_seating_system_structure.sql
```

Then click:

**Import**

This creates the required tables and relationships.

---

## ⚙️ Database Configuration

Open:

```text
config/db_connect.php
```

Configure the database connection according to your local MySQL setup.

Typical XAMPP configuration:

```php
$host = "localhost";
$username = "root";
$password = "";
$database = "examination_seating_system";
```

If your MySQL installation uses a password, replace the empty password with your own local MySQL password.

---

## 📧 Gmail / OTP Configuration

The project uses **PHPMailer** for email-based OTP functionality.

Open:

```text
config/mail_config.php
```

Replace:

```php
$mail->Username = "YOUR_GMAIL@gmail.com";
$mail->Password = "YOUR_GMAIL_APP_PASSWORD";
```

with your own Gmail address and Gmail **App Password**.

### Gmail Requirements

For Gmail SMTP:

1. Enable **2-Step Verification** on your Google account.
2. Create a **Google App Password**.
3. Use the generated App Password in `mail_config.php`.
4. Do not use your normal Gmail password.
5. Never publish your real App Password on GitHub.

Example:

```php
$mail->Username = "your-email@gmail.com";
$mail->Password = "your-16-character-app-password";
```

**Never commit your real Gmail App Password to GitHub.**

---

## ▶️ Running the Project

After starting Apache and MySQL, open:

```text
http://localhost/Examination-Seating-System/
```

The application can then be accessed through the authentication system.

---

## 🔒 Security

This repository is intended to contain the **source code and database structure**, not private project data.

Do not upload:

* Gmail App Passwords
* Database passwords
* Real student records
* Real admin credentials
* OTP values
* Private uploaded files
* Other confidential information

The `.gitignore` file is included to help prevent common local/private files from being committed.

---

## 🗄️ Database Tables

The database structure includes tables such as:

```text
academic_years
activities
allocation_batches
allocation_hall_details
allocation_report_details
allocation_seats
allocation_student_seating_details
attendance
departments
examinations
halls
hall_benches
notifications
semesters
students
system_settings
users
```

---

## 🎯 Project Objective

The main objective of this project is to provide a computerized system for managing examination seating arrangements and related examination activities.

The system is intended to reduce manual work, organize examination hall information, simplify student seating allocation, and provide easily accessible examination records and reports.

---

## 👨‍💻 Project

**Examination Seating Arrangement System**

**Technology:** PHP + MySQL/MariaDB

**Development Environment:** XAMPP

**Project Type:** Diploma CSE Project

---

## 📄 License

This project is intended for educational and academic purposes.
