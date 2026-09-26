# Felege Kidusan Sunday School Management System (FKSS)
## Master Codebase Understanding & System Archaeology Report: Education Department (`edu_dept`)

**Protocol Standard:** Senior Software Architect, Codebase Archaeologist, Systems Analyst, and Maintenance Engineer Protocol (Sections A through V).  
**Scope of Audit:** Education Department subsystem, academic lifecycle, student rosters, grading, attendance, teacher assignments, report cards, and API/database integrations.  
**Source-of-Truth Hierarchy:** `SSMS/` codebase (PHP 8.2 / MariaDB 10.6+ / Vanilla JS / Tailwind CSS).  
**Audit Date:** September 2026.

---

### A. Executive System Summary
The **Education Department (`edu_dept`)** is the primary academic engine of the Felege Kidusan Sunday School Management System (FKSS). It is responsible for managing:
1. **Academic Year & Term Lifecycles:** Academic years (`academic_years`), terms/semesters (`academic_terms`), and active context tracking.
2. **Class Structures & Levels:** Sequential educational tiers (`classes`), level ordering, age groupings, and sections.
3. **Curriculum & Subject Catalog:** Subject definitions (`subjects`), class-subject bindings (`class_subjects`), and credit/weight settings.
4. **Teacher & Faculty Management:** Teacher profiles (`teacher_assignments`, `users`), subject assignments, homeroom teacher designations, and workload calculations.
5. **Student Enrollment & Mobility:** Enrollment records (`class_enrollments`), student transfers, batch enrollments, unassigned student matching, and annual promotions.
6. **Assessment, Grading & Submissions:** Assessment types (`assessments`), score recording (`academic_records`), teacher marklist submissions review workflow (`grade_submissions`), and audit trails.
7. **Academic Reporting & Transcripts:** Ranked class report cards (`ReportCardService`), letter grading (A–F), weighted averages, attendance rollup integration, and printable PDF bundles.
8. **Timetables & Bell Schedules:** Period definitions (`timetable_periods`) and weekly class schedules (`timetable_entries`).

The subsystem operates as a procedural PHP/MariaDB monolith utilizing specialized single-responsibility service classes (`EnrollmentService`, `AssignmentService`, `ReportCardService`, `TimetableService`, `AttendanceRecordService`, `AttendanceSummaryService`).

---

### B. Technology Stack (Verified)
- **Backend Runtime:** PHP 8.1 / 8.2 (Procedural entry points with Object-Oriented Domain Services under `App\Services`).
- **Database Engine:** MariaDB 10.6+ / MySQL 8.0+ (InnoDB engine, utf8mb4 character set, utf8mb4_unicode_ci collation).
- **Presentation / Frontend:** Server-rendered PHP (`admin/dashboards/edu_dept.php`, `admin/dashboards/teacher.php`), Tailwind CSS (via CDN), Font Awesome 6.5.0, SheetJS/XLSX (`0.18.5`).
- **Mobile Integration:** Flutter / Dart mobile client (`fkss_app` v1.5.0+24) interacting via REST API (`/api/v1/routes/classes.php`, `/api/v1/routes/grades.php`, `/api/v1/routes/attendance.php`).
- **Reporting & Export Engines:** Custom HTML-to-print layouts, CSV generators, and SheetJS spreadsheet exporters.

---

### C. Repository Architecture

```text
SSMS/
├── admin/
│   ├── dashboards/
│   │   ├── edu_dept.php               # Main Education Department SPA Dashboard (2,505 lines)
│   │   ├── teacher.php                # Teacher Portal Dashboard (grade entry & attendance)
│   │   └── attendance_taker.php       # Dedicated Attendance Marker Interface
│   ├── api_education.php              # Core Education Controller (1,538 lines, 28 actions)
│   ├── api_subjects.php               # Subjects & Catalog Controller (38K)
│   ├── api_teachers.php               # Teacher Management & Linkage Controller (50K)
│   ├── api_assignments.php            # Teacher-Class-Subject Matrix Controller (6.9K)
│   ├── api_attendance.php             # Class Attendance Recording Controller (21K)
│   ├── api_timetable.php              # Bell Schedule & Grid Controller (4.1K)
│   ├── api_reports.php                # Academic & Analytical Reports Controller (10.9K)
│   ├── backend/
│   │   └── services/
│   │       ├── EnrollmentService.php        # Transactional Enrollments & Rosters (667 lines)
│   │       ├── AssignmentService.php        # Teacher Assignments & Workloads (1,392 lines)
│   │       ├── ReportCardService.php        # Class Rankings & Report Cards (1,437 lines)
│   │       ├── TimetableService.php         # Schedule Grids & Period Management (379 lines)
│   │       ├── AttendanceRecordService.php  # Daily Attendance Sheet Replacement (186 lines)
│   │       └── AttendanceSummaryService.php # Monthly/Annual Rollups & Low Attendance Alerts (327 lines)
├── api/v1/routes/
│   ├── classes.php                    # REST API: Class lists & enrollments
│   ├── grades.php                     # REST API: Student marklists & grades
│   └── attendance.php                 # REST API: QR & manual attendance syncing
└── sql/
    ├── 004_year_lifecycle.sql         # Academic year & term lifecycle
    ├── 005_roster_indexes.sql         # High-speed roster lookups
    ├── 006_assignment_hardening.sql   # Class-Subject-Teacher assignment schema
    ├── 007_timetable.sql              # Bell schedule & timetable entries
    └── 029_subject_codes_and_limits.sql # Subject codes & Amharic safe length limits
```

---

### D. Application Architecture & Layer Interactions

```text
┌──────────────────────────────────────────────────────────────────────────────────┐
│                      PRESENTATION LAYER (UI / UX)                                │
│   admin/dashboards/edu_dept.php (10 Nav Sections: Tabs, Sheets, Tables, Cards)   │
└───────────────────────┬──────────────────────────────────┬───────────────────────┘
                        │ HTTP / JSON API Fetch            │
┌───────────────────────▼──────────────────────────────────▼───────────────────────┐
│                      CONTROLLER / API GATEWAY                                    │
│   api_education.php | api_subjects.php | api_teachers.php | api_attendance.php   │
│   - Session Guard Validation (AdminSessionGuard)                                 │
│   - CSRF & Rate Limiter Tokens                                                   │
│   - Action Dispatcher & Input Sanitization (LedgerValidation)                    │
└───────────────────────┬──────────────────────────────────┬───────────────────────┘
                        │ Internal Method Dispatch         │
┌───────────────────────▼──────────────────────────────────▼───────────────────────┐
│                      DOMAIN SERVICES LAYER                                       │
│   EnrollmentService | AssignmentService | ReportCardService | TimetableService   │
│   AttendanceRecordService | AttendanceSummaryService                             │
│   - Atomic Transactions (begin_transaction / commit / rollback)                 │
│   - Concurrency Locking (SELECT ... FOR UPDATE)                                  │
│   - Multi-term Aggregations & Rank Computing                                    │
└───────────────────────┬──────────────────────────────────┬───────────────────────┘
                        │ MariaDB SQL Execution            │
┌───────────────────────▼──────────────────────────────────▼───────────────────────┐
│                      PERSISTENCE LAYER (DATABASE)                                │
│   classes | subjects | class_subjects | teacher_assignments | class_enrollments  │
│   academic_years | academic_terms | assessments | academic_records | attendance  │
└──────────────────────────────────────────────────────────────────────────────────┘
```

---

### E. Module-by-Module Explanation (The 10 Education Subsystems)

1. **Dashboard Overview (`sec-dashboard`)**:
   - Displays real-time KPIs: Active Students, Total Classes, Assigned Teachers, Unassigned Students, Active Academic Year & Term banner.
   - Quick action shortcuts to enroll student, add class, add teacher, and record grades.

2. **Teachers & Faculty Management (`sec-teachers`)**:
   - Manages teacher directory (`api_teachers.php`), linked member accounts, credentials, and active teaching assignments.
   - Searchable modal with live member linking (`search_members_for_teacher`).

3. **Classes & Sections (`sec-classes`)**:
   - Maintains class definitions (`classes`), Amharic/English names, level order, section tags, age categories, and active student rosters.
   - Triggers modal dialogs for creating and editing classes.

4. **Subjects & Curriculum (`sec-subjects`)**:
   - Maintains subject catalog (`subjects`) with unique subject codes.
   - Class-subject binding interface for attaching subjects to specific grades.

5. **Student Enrollment & Transfers (`sec-enrollment`)**:
   - Multi-mode student placement: Single Student Enrollment, Bulk Enrollment by Age/Section, Class-to-Class Transfers, and Annual Promotion.
   - Roster viewer with instant gender, member type, and age breakdown.

6. **Grade Recording (`sec-grades`)**:
   - Score entry interface per class, subject, term, and assessment type.
   - Supports numeric score inputs, remark notes, and automatic letter grade preview.

7. **Assessments Configuration (`sec-assessments`)**:
   - Configuration of assessment instruments (Tests, Midterms, Final Exams, Assignments, Projects) with maximum scores and percentage weights.

8. **Academic Settings (`sec-settings`)**:
   - Configuration of Ethiopian Calendar (EC) and Gregorian (GC) academic years.
   - Semester / Term configuration and one-click current year activation toggle.

9. **Teacher Submissions & Marklist Review (`sec-submissions`)**:
   - Formal workflow for teacher-submitted grades (`grade_submissions`).
   - Education Department review modal with Accept, Reject, and Request Revision capabilities.

10. **Report Cards & Analytics (`sec-reportcards`)**:
    - Class-wide ranked report card generation.
    - Integrates subject scores, class averages, total obtained marks, rank in class, and attendance summaries into printable student transcripts.

---

### F. File-Level Map

| File Path | Primary Responsibility | Key Consumers / Callers |
| :--- | :--- | :--- |
| `admin/dashboards/edu_dept.php` | Main single-page Education Department dashboard UI | Super Admin, School Admin, Edu Dept users |
| `admin/api_education.php` | REST API handling enrollments, classes, years, terms, dashboard stats | `edu_dept.php`, `teacher.php`, Flutter mobile app |
| `admin/api_subjects.php` | CRUD for subjects and class-subject bindings | `edu_dept.php`, `AssignmentService` |
| `admin/api_teachers.php` | CRUD for teachers, account linking, assignments | `edu_dept.php`, `AssignmentService` |
| `admin/api_assignments.php` | Teacher assignment matrix, workload calculation, gap analysis | `edu_dept.php`, `teacher.php` |
| `admin/api_attendance.php` | Class attendance recording, sheets replacement, summaries | `edu_dept.php`, `attendance_taker.php`, Mobile app |
| `admin/api_timetable.php` | Bell schedule periods and weekly timetable grid | `edu_dept.php`, Mobile app |
| `admin/backend/services/EnrollmentService.php` | Atomic enrollments, transfers, code generation, roster queries | `api_education.php`, `ReportCardService` |
| `admin/backend/services/AssignmentService.php` | Teacher-subject-class assignment rules, homeroom/primary flags | `api_assignments.php`, `api_teachers.php` |
| `admin/backend/services/ReportCardService.php` | Grade calculation, rankings, subject averages, report bundles | `api_education.php`, `api_reports.php`, `teacher.php` |
| `admin/backend/services/TimetableService.php` | Period CRUD, weekly class grid conflict validation | `api_timetable.php` |
| `admin/backend/services/AttendanceRecordService.php` | Daily attendance sheet normalization & atomic atomic replacement | `api_attendance.php` |
| `admin/backend/services/AttendanceSummaryService.php` | Monthly attendance rollups, low-attendance threshold alerts | `api_attendance.php`, `AttendanceRecordService` |

---

### G. Function & Class Map

#### 1. `EnrollmentService` (`App\Services\EnrollmentService`)
- `enroll(\mysqli $conn, int $memberId, int $classId, ?int $yearId, ?int $enrolledBy): array`: Enrolls a student within an atomic transaction with `SELECT ... FOR UPDATE` lock on member.
- `transferByEnrollment(\mysqli $conn, int $enrollmentId, int $toClassId, ?int $enrolledBy): array`: Moves active enrollment to `transferred` and creates a new active enrollment in the target class.
- `resolveRosterYear(\mysqli $conn, int $classId, ?int $preferredYearId): array`: Resolves active year context with safe historical fallback.
- `fetchRoster(\mysqli $conn, int $classId, ?int $yearId, array $filters): array`: Queries class members with gender, age, and type statistics.

#### 2. `AssignmentService` (`App\Services\AssignmentService`)
- `assign(\mysqli $conn, int $teacherId, int $classId, int $subjectId, ?int $yearId, bool $isPrimary, bool $isHomeroom): array`: Links a teacher to a class and subject.
- `matrix(\mysqli $conn, int $yearId): array`: Generates a complete 2D matrix of classes vs. subjects showing assigned teachers.
- `gaps(\mysqli $conn, int $yearId): array`: Detects classes or subjects lacking assigned teachers.
- `workload(\mysqli $conn, int $yearId): array`: Computes teaching periods and class counts per instructor.

#### 3. `ReportCardService` (`App\Services\ReportCardService`)
- `letter(float $pct): string`: Converts percentage to letter grade (>=90% 'A', >=80% 'B', >=70% 'C', >=60% 'D', <60% 'F').
- `buildRankedClass(\mysqli $conn, int $classId, int $yearId, int $termId): array`: Calculates total scores, class averages, ties handling, and numerical ranking (1st, 2nd, 3rd, ...).
- `getCard(\mysqli $conn, int $memberId, int $classId, int $yearId, int $termId): array`: Retrieves a single student's complete academic transcript.
- `canViewClass(\mysqli $conn, int $userId, string $role, int $classId): bool`: Role-based security check for accessing class records.

---

### H. Database Architecture & Table Relationships

```text
                  ┌───────────────────┐
                  │  academic_years   │
                  └─────────┬─────────┘
                            │ 1:N
                            ├───────────────────────────┐
                            │                           │
                  ┌─────────▼─────────┐       ┌─────────▼─────────┐
                  │  academic_terms   │       │ class_enrollments │
                  └─────────┬─────────┘       └─────────▲─────────┘
                            │                           │ 1:N
                  ┌─────────▼─────────┐       ┌─────────┴─────────┐
                  │    assessments    │       │      classes      │
                  └─────────┬─────────┘       └─────────┬─────────┘
                            │ 1:N                       │ 1:N
                  ┌─────────▼─────────┐       ┌─────────▼─────────┐
                  │ academic_records  │       │  class_subjects   │
                  └─────────▲─────────┘       └─────────▲─────────┘
                            │                           │
                  ┌─────────┴─────────┐       ┌─────────┴─────────┐
                  │      members      │       │     subjects      │
                  └───────────────────┘       └───────────────────┘
```

#### Key Schema Constraints & Indexes:
1. `class_enrollments`: Unique constraint on `(member_id, class_id, academic_year_id)` prevents duplicate enrollments in the same class per academic year.
2. `class_subjects`: Unique constraint on `(class_id, subject_id)` prevents duplicate subject bindings.
3. `teacher_assignments`: Composite key on `(teacher_id, class_id, subject_id, academic_year_id)`.
4. `academic_records`: Foreign key indexing across `member_id`, `class_id`, `subject_id`, `academic_year_id`, and `term_id`.
5. `attendance`: Compound index on `(class_id, attendance_date)` for fast daily roster lookups.

---

### I. API Route Map

| Endpoint | Action Parameter | HTTP Method | Auth Role Required | Description |
| :--- | :--- | :--- | :--- | :--- |
| `/admin/api_education.php` | `dashboard` | `GET` | `edu_dept`, `admin` | Fetches education KPI statistics |
| `/admin/api_education.php` | `get_classes` | `GET` | `edu_dept`, `admin`, `teacher` | Lists all classes with student counts |
| `/admin/api_education.php` | `save_class` | `POST` | `edu_dept`, `admin` | Creates or updates a class |
| `/admin/api_education.php` | `delete_class` | `POST` | `edu_dept`, `admin` | Deactivates or removes an empty class |
| `/admin/api_education.php` | `enroll` | `POST` | `edu_dept`, `admin` | Enrolls a student into a class |
| `/admin/api_education.php` | `transfer_student`| `POST` | `edu_dept`, `admin` | Transfers a student between classes |
| `/admin/api_education.php` | `promote` | `POST` | `edu_dept`, `admin` | Promotes student to next grade level |
| `/admin/api_education.php` | `record_grade` | `POST` | `edu_dept`, `admin`, `teacher` | Records assessment score for student |
| `/admin/api_subjects.php` | `get_subjects` | `GET` | `edu_dept`, `admin` | Lists subjects catalog |
| `/admin/api_subjects.php` | `save_subject` | `POST` | `edu_dept`, `admin` | Adds or modifies a subject |
| `/admin/api_teachers.php` | `get_teachers` | `GET` | `edu_dept`, `admin` | Lists teachers and account linkages |
| `/admin/api_assignments.php`| `matrix` | `GET` | `edu_dept`, `admin` | Returns 2D teacher assignment matrix |
| `/admin/api_attendance.php` | `save_attendance` | `POST` | `edu_dept`, `admin`, `attendance_taker` | Atomically commits daily class attendance |

---

### J. Authentication, Authorization & Access Control
1. **Session Enforcement:** Every education API endpoint validates `AdminSessionGuard::enforce(['super_admin', 'school_admin', 'edu_dept'])`.
2. **Teacher Scoping:** When a user with role `teacher` accesses class data, `ReportCardService::canViewClass` verifies that the teacher has an active row in `teacher_assignments` for that specific `class_id`. Teachers cannot inspect rosters of unassigned classes.
3. **CSRF Protection:** Modifying POST requests require a valid session CSRF token verified via `AdminSessionGuard`.
4. **Rate Limiting:** Teacher password resets and bulk actions are rate-limited using `SecurityRateLimiter`.

---

### K. State Management & Lifecycle
1. **Client-Side SPA Tabs:** `edu_dept.php` utilizes vanilla JavaScript tab switching (`showTab('section_id')`) with state persistence in the browser URL hash (`#classes`, `#teachers`, `#grades`, etc.).
2. **Scroll Reset:** Section switches trigger an automatic scroll reset (`_main.scrollTop = 0`).
3. **Mobile Bottom Sheets:** All dialogs (`classModal`, `teacherModal`, `subjectModal`, `transferModal`, etc.) render as responsive bottom sheets on mobile devices (<768px) with touch grabbers.
4. **Server-Side Active Year Context:** Active year is determined via `academic_years WHERE is_current = 1 LIMIT 1`. All subsequent queries filter through this active year context unless explicitly overridden for historical reporting.

---

### L. Business Rules (Extracted from Code)

1. **Student Promotion Rules (`api_education.php:427`)**:
   - Source class and target class cannot be identical.
   - Student must have an active enrollment record in the source class.
   - Old enrollment status is transitioned to `completed`.
   - New enrollment record is inserted with `promoted_from = from_class_id` and `status = 'active'`.
   - All steps execute inside an atomic MariaDB transaction (`$conn->begin_transaction()`).

2. **Grading & Ranking Rules (`ReportCardService.php:17-44`)**:
   - **Passing Mark:** `50.0%`.
   - **Letter Grades:**
     - `A`: 90.0% – 100.0% (Excellent)
     - `B`: 80.0% – 89.9% (Very Good)
     - `C`: 70.0% – 79.9% (Good)
     - `D`: 60.0% – 69.9% (Pass)
     - `F`: 0.0% – 59.9% (Needs Work)
   - **Class Ranking:** Computed dynamically using total score descending. Ties receive identical ranks, and subsequent ranks skip accordingly (Standard Competition Ranking 1224).

3. **Attendance Status Rules (`AttendanceRecordService.php`)**:
   - Permitted statuses: `present`, `absent`, `late`, `excused`, `holiday`.
   - Replaces the entire sheet for `(class_id, attendance_date)` in one transaction to avoid duplicate or partial saves.
   - Updates `attendance_summary` rollup counts asynchronously.

4. **Teacher Assignment Rules (`AssignmentService.php`)**:
   - Each class can have exactly one `is_homeroom = 1` teacher.
   - Each subject within a class can have one `is_primary = 1` lead teacher.
   - Soft-delete status flag (`status = 'inactive'`) preserves historical grades when a teacher departs.

---

### M. End-to-End Workflow Maps

#### Workflow 1: Student Enrollment & Class Assignment
```text
Admin fills Enrollment Form (Member ID + Target Class)
       │
       ▼
POST /admin/api_education.php?action=enroll
       │
       ▼
EnrollmentService::enroll()
  ├── Acquires SELECT ... FOR UPDATE on members row
  ├── Checks active academic year (academic_years)
  ├── Verifies member is not already active in class_enrollments
  ├── Inserts class_enrollments record
  └── Denormalizes class_id on members table
       │
       ▼
Returns JSON { status: "success", enrollment_id: 104 }
```

#### Workflow 2: Teacher Marklist Submission & Education Review
```text
Teacher enters student scores in teacher.php
       │
       ▼
Teacher clicks "Submit for Review" (grade_submissions status: 'submitted')
       │
       ▼
Education Department receives notification in sec-submissions
       │
       ▼
Edu Dept clicks "Review Submission"
  ├── APPROVE: Status becomes 'approved', scores locked into academic_records
  ├── REVISION NEEDED: Status becomes 'revision_needed', notes sent back to teacher
  └── REJECT: Status becomes 'rejected'
```

---

### N. Status & State Transition Maps

```text
[STUDENT ENROLLMENT STATE MACHINE]
                  ┌──────────────┐
                  │    ACTIVE    │
                  └──────┬───────┘
                         │
         ┌───────────────┼───────────────┐
         │ Transfer      │ Promote       │ Withdraw
         ▼               ▼               ▼
  ┌──────────────┐┌──────────────┐┌──────────────┐
  │ TRANSFERRED  ││  COMPLETED   ││  WITHDRAWN   │
  └──────────────┘└──────────────┘└──────────────┘

[GRADE SUBMISSION STATE MACHINE]
  ┌──────────────┐
  │  INCOMPLETE  │
  └──────┬───────┘
         │ Complete entry
         ▼
  ┌──────────────┐
  │    DRAFT     │
  └──────┬───────┘
         │ Teacher submits
         ▼
  ┌──────────────┐  Revision Needed  ┌──────────────────┐
  │  SUBMITTED   │ ◄─────────────── │ REVISION_NEEDED  │
  └──────┬───────┘                  └─────────▲────────┘
         │                                    │
         ├────────────────────────────────────┘
         │
         ├───► [ APPROVED ] (Locked into report cards)
         │
         └───► [ REJECTED ]
```

---

### O. External Integrations & Cross-Module Dependencies
1. **QR Roster Attendance Scanner:** Synchronizes with mobile camera scans (`api_qr_roster.php`).
2. **SheetJS XLSX Exporter:** Exports class rosters and grade books directly to `.xlsx` in the browser.
3. **Report Card PDF Engine:** Generates print-ready student report cards with Ethiopian Orthodox Sunday School branding.
4. **Notification Center:** Dispatches alerts to teachers upon submission reviews.

---

### P. Configuration & Feature Toggles (`school_config.php`)
- `FEATURE_GRADES` (`true`): Controls assessment and marklist module availability.
- `FEATURE_ATTENDANCE` (`true`): Controls attendance sheets and summary rollups.
- `FEATURE_REPORTS` (`true`): Controls report card generation and ranking exports.
- `DEPT_EDU_NAME` (`'ትምህርት ክፍል'`): Amharic label for the Education Department.
- `DEPT_EDU_NAME_EN` (`'Education Department'`): English label.

---

### Q. Error Handling Architecture
- Domain services throw `LedgerInputException` for expected user input validation errors, returning structured JSON `{ "status": "error", "message": "..." }` with appropriate HTTP status codes (400, 403, 404, 409).
- Unhandled runtime errors are logged via `reportInternalError()` and return safe non-leaking user messages.
- Database transactions enforce all-or-nothing atomicity via `try ... catch` and `$conn->rollback()`.

---

### R. Critical Dependency Graph
- `edu_dept.php` ➔ Depends on `api_education.php`, `api_subjects.php`, `api_teachers.php`, `api_assignments.php`.
- `api_education.php` ➔ Depends on `EnrollmentService`, `ReportCardService`, `AdminSessionGuard`.
- `ReportCardService` ➔ Depends on `EnrollmentService`, `academic_records`, `assessments`, `attendance_summary`.
- `teacher.php` ➔ Depends on `AssignmentService` for class scoping and `grade_submissions`.

---

### S. Potential Problems & Technical Debt (Audit Observations)
1. **Single Point of Configuration in Active Year:** If no row in `academic_years` has `is_current = 1`, several API endpoints fall back to historical heuristics, which can cause confusion during semester rollovers if not explicitly set.
2. **Large Single File Dashboard:** `edu_dept.php` is ~2,500 lines containing inline templates and JavaScript. Modularizing section renderers into `/admin/dashboards/sections/edu/` will improve maintainability.
3. **Denormalized `members.class_id` Column:** `members` maintains a denormalized `class_id` alongside `class_enrollments`. In rare race conditions or manual database edits, these two tables can become desynchronized if not updated via `EnrollmentService`.
4. **Score Weight Validation:** In `assessments`, total weight sums for a subject are calculated at report card generation time rather than strictly constrained at assessment creation time.

---

### T. Unknowns & Unverified Items
- Long-term archival strategy for multi-year assessment scores beyond 5+ academic years.
- Whether class level ordering (`level_order`) is universally linear or supports branching tracks (e.g. specialized liturgical zema tracks vs. regular curriculum).

---

### U. Documentation Mismatches
- In older database documentation comments, `grade_submissions.submission_type` was documented as having an `'exam'` enum value; however, the actual MySQL table schema uses `ENUM('marklist','attendance','report')`.

---

### V. Change Impact Map (Safe Modification Blueprint)

| Proposed Feature Change | Files That Must Be Inspected / Updated | Database & API Implications | Regressions / Tests to Run |
| :--- | :--- | :--- | :--- |
| **1. Update Grading Scale / Passing Marks** | `ReportCardService.php`, `edu_dept.php`, `teacher.php` | Affects `ReportCardService::GRADE_SCALE`, `ReportCardService::letter()` | Run `test_edu_uiux.py`, verify PDF report card layout |
| **2. Add New Class / Section Fields** | `api_education.php`, `edu_dept.php`, `classes` table | Requires adding columns to `classes`, update `get_classes` API | Verify `classBody` table headers and mobile card labels |
| **3. Enhance Teacher Assignment Workflow** | `AssignmentService.php`, `api_assignments.php`, `edu_dept.php` | Updates `teacher_assignments`, affects assignment matrix | Verify homeroom teacher uniqueness constraints |
| **4. Modify Student Promotion Logic** | `EnrollmentService.php`, `api_education.php` (`promote` case) | Affects `class_enrollments` status transitions and `members.promoted_at` | Verify transaction rollback on failure |
| **5. Add Assessment Types & Weighting Rules** | `api_education.php`, `assessments` table, `ReportCardService.php` | Updates `assessments` schema and score aggregation in report cards | Verify weighted average calculation across multiple terms |

---

**Report Certification:** This deep archaeological audit has been compiled directly from the active `SSMS` repository source files without modifying any code. We are now ready to review feature requests and implement updates safely.
