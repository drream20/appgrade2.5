# Instructor Recording Web System

A web application for instructors to manage schedules, record student assessments and attendance, edit their profile, view and compute grades per subject and term, and import/export records via CSV (Excel-compatible).

## Requirements

- XAMPP (Apache + PHP 7.4+ + MySQL) or similar
- PHP extensions: PDO, pdo_mysql, mbstring

## Installation

1. **Create the database**
   - Open phpMyAdmin or MySQL CLI.
   - Run `database/schema.sql` to create the database and tables.
   - Optionally run `database/sample_data.sql` for test data.

2. **Configure database**
   - Edit `config/database.php` and set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` if needed (default: root, no password, database `instructor_recording`).

3. **Run the app**
   - Place the project under `htdocs/webapp` (or your web root) and open:  
     `http://localhost/webapp/`  
   - Default test login (after running sample_data.sql):  
     - Email: `instructor@test.com`  
     - Password: `password`

## Features

- **Schedule** – View weekly schedule; multiple subjects per day are supported.
- **Class records** – Per subject and term (Prelim / Midterm / Final):
  - **Assessment**: Class standing (participation, quizzes, projects), Performance task, Term examination. Edit or add entries per student.
  - **Attendance**: Record by date (Present / Absent / Late / Excused). Edit or add per student.
- **Grades** – Automatic computation:
  - **Per term**: Class standing 30%, Attendance 5%, Performance task 30%, Term examination 40%.
  - **Final grade**: Prelim 30%, Midterm 30%, Final 40%.
- **Individual grades** – Click a student name in Grades to see breakdown (all components and term grades).
- **Profile** – Instructors can edit name, employee ID, department, and change password.
- **Download** – Download class record as CSV (per term or all terms) from Class Records or Grades. Opens in Excel.
- **Upload** – Upload CSV (e.g. from Excel) to import scores; columns: Student ID, Class Standing, Attendance, Performance Task, Term Exam. First row = headers.

## File structure

- `config/database.php` – DB config and base URL
- `includes/auth.php` – Login, session, requireLogin
- `includes/grades.php` – Term and final grade formulas, recompute
- `includes/layout.php` – Main layout and nav
- `database/schema.sql` – Tables
- `database/sample_data.sql` – Test instructor, subjects, schedule, students
- `login.php`, `logout.php`, `index.php` – Auth
- `dashboard.php` – Overview and subject list
- `schedule.php` – Weekly schedule
- `records.php` – Class records (subject + term + assessment/attendance)
- `records_assessment.php`, `records_attendance.php` – Record tables
- `grades.php` – Class grades and final grade
- `student_grade.php` – One student’s grade breakdown
- `profile.php` – Edit profile
- `download_record.php` – CSV export (per term or all terms)
- `excel_upload.php` – CSV import
- `assets/js/records.js` – In-page add/edit/delete for records

## Notes

- Instructors only see their own subjects, enrollments, and grades.
- CSV export uses UTF-8 BOM so Excel opens it with correct encoding.
- For production, use HTTPS and strong passwords; restrict DB user and keep `config/database.php` out of public docroot if possible.
