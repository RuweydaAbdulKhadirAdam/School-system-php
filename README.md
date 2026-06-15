# School Management System

A PHP and MySQL school management project for handling student admissions, enrollments, attendance, exams, marks, finance, timetables, roles, and admin reporting.

The repository contains:

- `school/` - the PHP application
- `school_system_db.sql` - the MySQL database dump with sample data

## Features

- Role-based authentication using `users`, `roles`, and `user_roles`
- Admin dashboard with summary stats, payments, and activity logs
- Reception dashboard with quick access to student admission flows
- Student admission and student profile management
- Academic years, grades, sections, and subject management
- Teacher and teacher-subject management
- Enrollment management
- Attendance sessions and attendance taking
- Exam, marks, and result management
- Fee structures, invoices, and payments
- Timetable management
- Permissions and activity log tracking
- SMS outbox and SMS log tables

## Tech Stack

- PHP
- MySQL / MariaDB
- MySQLi
- Bootstrap CSS
- SweetAlert2
- Font Awesome

## Project Structure

```text
.
├── README.md
├── school/
│   ├── login.php
│   ├── registration.php
│   ├── dashboardadmin.php
│   ├── dashboardreception.php
│   ├── students.php
│   ├── students_add.php
│   ├── enrollments.php
│   ├── attendance_*.php
│   ├── exams.php
│   ├── marks.php
│   ├── results.php
│   ├── finance_*.php
│   ├── timetable*.php
│   └── conncation.php
└── school_system_db.sql
```

## Database Overview

The SQL dump creates and seeds many core tables, including:

- `users`, `roles`, `user_roles`, `permissions`, `role_permissions`, `user_permissions`
- `students`, `parents`, `student_parents`, `student_documents`
- `employees`, `teachers`, `teacher_subjects`
- `academic_years`, `school_levels`, `grades`, `sections`, `subjects`
- `enrollments`
- `attendance_sessions`, `attendance_records`
- `exams`, `exam_papers`, `marks`, `student_result_summary`
- `fee_structures`, `student_invoices`, `invoice_items`, `payments`
- `timetables`, `timetable_entries`, `time_slots`, `week_days`, `rooms`
- `activity_logs`, `sms_outbox`, `sms_logs`

The configured database name in [`school/conncation.php`](/Users/saidabdirahman/Downloads/Code-Files/school_system_db.sql/school/conncation.php:7) is `school_system_db`.

## Local Setup

### 1. Requirements

- PHP 8.x recommended
- MySQL or MariaDB
- Apache, Nginx, XAMPP, WAMP, or MAMP

### 2. Import the database

Create a database named `school_system_db`, then import the dump:

```sql
CREATE DATABASE school_system_db;
```

Then import `school_system_db.sql` using phpMyAdmin or the MySQL CLI:

```bash
mysql -u root -p school_system_db < school_system_db.sql
```

### 3. Configure the database connection

Update the credentials in [`school/conncation.php`](/Users/saidabdirahman/Downloads/Code-Files/school_system_db.sql/school/conncation.php:5):

Note: the file is named `conncation.php` in this project.

```php
$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "school_system_db";
$DB_PORT = 3306;
```

### 4. Serve the `school/` directory

Point your local web server document root to the `school/` folder, or place it inside your web root.

Example URL:

```text
http://localhost/school/login.php
```

### 5. Sign in or create an admin

- If the database has no admin user yet, open `registration.php` to create the first admin account.
- If you imported the provided SQL dump, sample users already exist.
- The dump stores hashed passwords only, so plaintext sample passwords are not included in this repository.

## Main Screens

- [`school/login.php`](/Users/saidabdirahman/Downloads/Code-Files/school_system_db.sql/school/login.php:1) - login page
- [`school/registration.php`](/Users/saidabdirahman/Downloads/Code-Files/school_system_db.sql/school/registration.php:1) - first admin / user registration
- [`school/dashboardadmin.php`](/Users/saidabdirahman/Downloads/Code-Files/school_system_db.sql/school/dashboardadmin.php:1) - admin dashboard
- [`school/reception.php`](/Users/saidabdirahman/Downloads/Code-Files/school_system_db.sql/school/reception.php:1) - reception dashboard
- [`school/students_add.php`](/Users/saidabdirahman/Downloads/Code-Files/school_system_db.sql/school/students_add.php:1) - admission form
- [`school/students.php`](/Users/saidabdirahman/Downloads/Code-Files/school_system_db.sql/school/students.php:1) - student listing
- [`school/enrollments.php`](/Users/saidabdirahman/Downloads/Code-Files/school_system_db.sql/school/enrollments.php:1) - enrollment management
- [`school/attendance_sessions.php`](/Users/saidabdirahman/Downloads/Code-Files/school_system_db.sql/school/attendance_sessions.php:1) - attendance session setup
- [`school/attendance_take.php`](/Users/saidabdirahman/Downloads/Code-Files/school_system_db.sql/school/attendance_take.php:1) - take attendance
- [`school/exams.php`](/Users/saidabdirahman/Downloads/Code-Files/school_system_db.sql/school/exams.php:1) - exams
- [`school/marks.php`](/Users/saidabdirahman/Downloads/Code-Files/school_system_db.sql/school/marks.php:1) - marks
- [`school/results.php`](/Users/saidabdirahman/Downloads/Code-Files/school_system_db.sql/school/results.php:1) - results
- [`school/finance_invoices.php`](/Users/saidabdirahman/Downloads/Code-Files/school_system_db.sql/school/finance_invoices.php:1) - invoices
- [`school/finance_payments.php`](/Users/saidabdirahman/Downloads/Code-Files/school_system_db.sql/school/finance_payments.php:1) - payments
- [`school/timetable.php`](/Users/saidabdirahman/Downloads/Code-Files/school_system_db.sql/school/timetable.php:1) - timetable builder

## Known Gaps

- `login.php` maps some roles to `dashboardteacher.php`, `dashboardfinance.php`, and `dashboardstudent.php`, but those files are not present in this repository.
- Some attendance pages link to `dashboard.php`, which is also not present.
- The login page loads SweetAlert2 and Font Awesome from CDNs, so those assets work best with internet access.

## Security Notes

- Change the database credentials before deploying.
- Replace any sample data before production use.
- Review file upload handling in `school/uploads/` and student photo flows before going live.

## License

No license file is currently included in this repository. Add one if you plan to share or distribute the project.
