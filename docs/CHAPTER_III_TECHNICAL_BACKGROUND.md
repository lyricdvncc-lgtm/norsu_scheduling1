# CHAPTER III: TECHNICAL BACKGROUND

This chapter discusses and presents the different sets of diagrams and charts pertaining to the Smart Scheduling System being developed by the proponents. It covers the technicality of the project, the details of the technologies to be used, and provides a thorough explanation of how the project works. It also presents the theoretical and conceptual frameworks that guided the design and development of the system.

---

## 3.1 Technicality of the Project

### 3.1.1 System Overview

The Smart Scheduling System is a web-based application designed to automate and optimize the academic scheduling process for educational institutions. The system addresses the complex and time-consuming nature of manual class scheduling by providing an intelligent platform that handles schedule creation, conflict detection, resource allocation, and multi-role coordination. The system employs a three-tier architecture consisting of:

1. **Presentation Layer** – Handles user interface and user interactions through responsive web pages built with Twig templates and Tailwind CSS.
2. **Business Logic Layer** – Processes scheduling algorithms, conflict detection, curriculum management, and business rules through dedicated service classes.
3. **Data Access Layer** – Manages database operations and data persistence through the Doctrine ORM and repository pattern.

### 3.1.2 System Architecture

The application follows the **Model-View-Controller (MVC)** architectural pattern implemented through the Symfony 7.3 framework. This pattern provides:

- **Separation of Concerns** – Clear division between data handling, business logic, and presentation.
- **Maintainability** – Easier code management and updates due to modular structure.
- **Scalability** – Ability to grow and adapt to increasing demands from multiple departments and colleges.
- **Testability** – Simplified unit and integration testing through dependency injection and service isolation.

The layered architecture of the system is outlined below:

```
┌─────────────────────────────────────────────────────┐
│                  Presentation Layer                  │
│     (Twig Templates + Tailwind CSS + JavaScript)    │
└────────────────────────┬────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────┐
│                  Application Layer                   │
│            (Symfony Controllers + Forms)             │
│   AdminController, ScheduleController,              │
│   DepartmentHeadController, FacultyController       │
└────────────────────────┬────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────┐
│                   Business Layer                     │
│             (Services + Event Subscribers)           │
│   ScheduleConflictDetector, DashboardService,       │
│   CurriculumService, ActivityLogService, etc.       │
└────────────────────────┬────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────┐
│                     Data Layer                       │
│            (Doctrine ORM + Repositories)             │
│   13 Entity Classes, Repository Pattern              │
└────────────────────────┬────────────────────────────┘
                         │
┌────────────────────────▼────────────────────────────┐
│                      Database                        │
│                   (MySQL 8.0)                        │
└─────────────────────────────────────────────────────┘
```

### 3.1.3 Core Features

#### A. Schedule Management
- **Automated Schedule Creation** – Generate class schedules based on subject requirements, room availability, faculty assignment, and time constraints. Supports batch creation of multiple sections simultaneously.
- **Real-Time Conflict Detection** – Intelligent identification of scheduling conflicts across five categories: room-time conflicts, faculty conflicts, section conflicts, block-sectioning conflicts, and capacity violations.
- **Multi-Section Support** – Handle multiple sections for the same subject with atomic validation, ensuring that either all sections in a batch are created or none are saved.
- **Status Tracking** – Monitor schedule status through a defined workflow (active, pending, approved, rejected).
- **Faculty Loading and Overload Management** – Assign faculty to schedules, view teaching load summaries, and toggle overload flags per schedule.

#### B. Resource Management
- **Room Allocation** – Manage rooms across departments with support for room types (classroom, laboratory, auditorium, office), capacity tracking, building/floor metadata, and equipment information. Cross-department room sharing is facilitated through Department Groups.
- **Faculty Assignment** – Assign instructors to classes with workload visibility. Department heads can manage faculty assignments within their own departments.
- **Department and College Organization** – Hierarchical organizational structure with colleges containing departments, and departments containing faculty, subjects, rooms, and curricula.

#### C. Curriculum Management
- **Curriculum Builder** – Create and manage curricula organized by terms (year level and semester). Each curriculum term contains a list of subjects with associated units.
- **Bulk Upload** – Upload curriculum subjects in batch via Excel spreadsheet (PhpSpreadsheet integration).
- **Publish/Draft Workflow** – Curricula can be drafted and published; a curriculum must have at least one subject to be eligible for publication.
- **Version Control** – Curricula support versioning to track revisions over time.

#### D. User Role Management
The system implements **Role-Based Access Control (RBAC)** with three distinct user roles:

| Role | Role ID | Symfony Role | Access Scope |
|------|---------|-------------|--------------|
| Administrator | 1 | ROLE_ADMIN | Full system access across all departments and colleges |
| Department Head | 2 | ROLE_DEPARTMENT_HEAD | Department-scoped management of faculty, rooms, schedules, and curricula |
| Faculty | 3 | ROLE_FACULTY | View-only access to personal schedules, classes, and teaching load |

#### E. Reporting and Export
- **PDF Generation** – Export teaching load reports, room schedules, faculty reports, room reports, and subject reports as downloadable PDF documents using TCPDF.
- **Faculty History and Room History Export** – Historical data can be exported for record-keeping purposes.

#### F. Activity Logging and Audit Trail
- **System-Wide Activity Logging** – All user actions (login, logout, CRUD operations on schedules, users, rooms, subjects, curricula) are recorded in an audit log with metadata including IP address, user agent, entity type, and entity ID.
- **Searchable Activity Feed** – Paginated and filterable activity log with JSON API endpoint for dynamic loading.

#### G. Conflict Detection Engine
The system implements a comprehensive conflict detection engine (`ScheduleConflictDetector`) that checks for the following conflict types:

| Conflict Type | Description |
|--------------|-------------|
| **Room-Time Conflict** | Same room assigned to multiple classes at overlapping time slots on overlapping days |
| **Faculty Conflict** | Same instructor scheduled for multiple classes at the same time |
| **Section Conflict (Duplicate)** | Same subject and section combination scheduled more than once in the same academic year and semester |
| **Block-Sectioning Conflict** | Different subjects sharing the same section and year level scheduled at overlapping times, preventing students from attending both |
| **Capacity Violation** | Enrolled students exceeding the assigned room's capacity |

---

## 3.2 Technologies Used

### 3.2.1 Backend Technologies

#### A. PHP 8.2+
**Purpose**: Server-side programming language

PHP is the core programming language used to build the entire backend of the Smart Scheduling System. Version 8.2 and above provides modern language features that enhance code quality and developer productivity.

**Key Features Used**:
- **Strict Type Declarations** – Enhanced code reliability by ensuring type safety across method signatures and properties.
- **PHP 8 Attributes** – Used for Doctrine ORM entity mapping (#[ORM\Entity], #[ORM\Column]), Symfony routing (#[Route]), and validation (#[Assert\NotBlank]) instead of docblock annotations.
- **Constructor Property Promotion** – Cleaner entity and service constructor definitions.
- **Named Arguments** – Improved code readability in method calls.
- **Enums and Match Expressions** – Simplified conditional logic where applicable.

#### B. Symfony 7.3 Framework
**Purpose**: Primary PHP framework for backend development

Symfony is the full-stack web application framework that provides the structural backbone of the project. The system uses **Symfony 7.3** components extensively.

**Key Components Used**:

| Symfony Component | Purpose |
|-------------------|---------|
| `symfony/framework-bundle` | Core framework functionality (routing, controllers, HTTP handling) |
| `symfony/security-bundle` | Authentication, authorization, CSRF protection, role-based access |
| `symfony/form` | Server-side form building, validation, and CSRF token generation |
| `symfony/validator` | Input validation with constraint annotations |
| `symfony/twig-bundle` | Integration of the Twig template engine |
| `symfony/doctrine-messenger` | Asynchronous message handling |
| `symfony/monolog-bundle` | Application logging |
| `symfony/mailer` | Email notification capability |
| `symfony/rate-limiter` | Login attempt throttling |
| `symfony/stimulus-bundle` | Stimulus.js integration for client-side interactivity |
| `symfony/ux-turbo` | Hotwire Turbo integration for SPA-like navigation |
| `symfony/asset-mapper` | Asset management without Node.js bundlers, using native importmaps |

**Justification**: Symfony provides enterprise-grade, long-term-support features with an extensive ecosystem of reusable components, making it well-suited for complex institutional systems.

#### C. Doctrine ORM (Object-Relational Mapping)
**Purpose**: Database abstraction and object-relational mapping

Doctrine ORM serves as the data access layer of the system, translating PHP objects (entities) to and from relational database rows.

**Key Features Used**:
- **Entity Mapping via PHP Attributes** – 13 entity classes mapped to database tables using PHP 8 attribute syntax.
- **Query Builder** – Type-safe database queries constructed programmatically for complex filtering (e.g., finding conflicting schedules by room, time, and day).
- **Migration System** – 28 version-controlled migration files that track every schema change throughout development.
- **Repository Pattern** – Custom repository classes encapsulate common and complex queries per entity.
- **Lifecycle Callbacks** – `@PrePersist` and `@PreUpdate` events automatically manage `createdAt` and `updatedAt` timestamps.
- **Lazy Loading** – Related entities are loaded on demand to optimize performance.

#### D. TCPDF
**Purpose**: PDF document generation

TCPDF (`tecnickcom/tcpdf`) is used to generate downloadable PDF reports including teaching load reports, room schedules, faculty reports, room inventory reports, and subject listings.

#### E. PhpSpreadsheet
**Purpose**: Excel file processing

PhpSpreadsheet (`phpoffice/phpspreadsheet`) enables bulk curriculum upload by parsing uploaded Excel spreadsheets and mapping rows to `CurriculumSubject` entities.

### 3.2.2 Frontend Technologies

#### A. Twig Template Engine
**Purpose**: Server-side template rendering

Twig is Symfony's default template engine and is used to render all HTML views in the application.

**Key Features Used**:
- **Template Inheritance** – A base layout template (`base.html.twig`) provides common structure (navigation, stylesheets, scripts), and role-specific layouts (`admin/base.html.twig`, `department_head/base.html.twig`, `faculty/base.html.twig`) extend it.
- **Blocks and Includes** – Reusable component templates (e.g., modals, filter panels, statistics cards) are included across pages.
- **Filters and Functions** – Data formatting (date formatting, string slicing, uppercase) is handled in the template layer.
- **Auto-Escaping** – All output is automatically escaped to prevent Cross-Site Scripting (XSS) attacks.

#### B. Tailwind CSS 3.3
**Purpose**: Utility-first CSS framework for responsive design

Tailwind CSS is the primary styling framework used across all templates, enabling rapid UI development without writing custom CSS.

**Key Features Used**:
- **Responsive Design** – Mobile-first utility classes ensure the application adapts to different screen sizes.
- **Component Patterns** – Cards, badges, modals, tables, and navigation elements are built using Tailwind utility classes.
- **Form Plugin** (`@tailwindcss/forms`) – Provides consistent form element styling.
- **Typography Plugin** (`@tailwindcss/typography`) – Enhances text content rendering.
- **Custom Color System** – Year-level badges use a color-coding system (blue for Year 1, green for Year 2, orange for Year 3, red for Year 4).

The Tailwind build is managed via the `tailwindcss` CLI, which processes the input CSS (`assets/styles/app.css`) and outputs the final compiled CSS to `public/build/app.css`.

#### C. JavaScript (Vanilla ES6+)
**Purpose**: Client-side interactivity and dynamic functionality

Vanilla JavaScript is used for UI interactions that do not require a full frontend framework.

**Key Features Implemented**:
- **Real-Time Schedule Filtering** – Client-side filtering by search text, room, day pattern, and status without page reload.
- **Modal Management** – Opening, closing, and populating schedule detail modals and conflict modals dynamically using data attributes.
- **AJAX Conflict Checking** – Asynchronous POST requests to the server for live conflict detection during schedule creation and editing.
- **Form Interactivity** – Dynamic form field updates (e.g., loading departments based on selected college, loading rooms based on selected department).
- **CSRF Protection Controller** – A Stimulus controller (`csrf_protection_controller.js`) manages CSRF tokens for AJAX requests.

#### D. Hotwire (Stimulus + Turbo)
**Purpose**: SPA-like page navigation and client-side controller framework

The application integrates Symfony UX packages for Hotwire:
- **Stimulus** – Provides lightweight JavaScript controllers attached to HTML elements via data attributes.
- **Turbo** – Enables faster page navigation by replacing only the page body on link clicks, avoiding full page reloads.

### 3.2.3 Database Technology

#### MySQL 8.0
**Purpose**: Relational database management system

MySQL 8.0 serves as the primary data store for the Smart Scheduling System. The database configuration is managed through Doctrine DBAL with the connection URL sourced from the `DATABASE_URL` environment variable.

**Key Features Used**:
- **ACID Compliance** – Ensures data integrity and consistency for concurrent schedule operations.
- **InnoDB Storage Engine** – Transaction-safe storage with row-level locking, configured with a 256MB buffer pool in the Docker Compose setup.
- **Foreign Key Constraints** – Referential integrity is enforced between related tables (e.g., `schedules.room_id` references `rooms.id`; `schedules.subject_id` references `subjects.id`).
- **Composite Indexes** – Strategic index placement on frequently queried field combinations (e.g., the `schedules` table has a composite index on `room_id, day_pattern, start_time, end_time` for fast conflict detection queries).
- **JSON Column Support** – Used for the `metadata` field in `activity_logs` and `sections_mapping` in `curriculum_subjects`.
- **Soft Deletes** – Implemented via `deletedAt` columns on Users, Subjects, Rooms, Departments, Colleges, Academic Years, and Curricula to preserve historical data.

**Database Schema** — The system schema consists of 13 interconnected tables:

| Table | Purpose |
|-------|---------|
| `users` | Stores administrators, department heads, and faculty members |
| `colleges` | Top-level organizational units |
| `departments` | Academic departments belonging to colleges |
| `department_groups` | Shared resource groups linking multiple departments |
| `subjects` | Academic courses with units, hours, and type information |
| `rooms` | Physical facilities with capacity, type, and location |
| `schedules` | Core scheduling data linking subjects, rooms, faculty, and time slots |
| `academic_years` | Academic year periods with semester tracking |
| `curricula` | Named curriculum definitions per department |
| `curriculum_terms` | Year-level and semester terms within a curriculum |
| `curriculum_subjects` | Subject assignments within curriculum terms |
| `activity_logs` | System-wide audit trail of user actions |

### 3.2.4 Development and Deployment Tools

#### A. Composer
**Purpose**: PHP dependency management

Composer manages all PHP dependencies for the project. The `composer.json` file defines 40+ required packages including Symfony components, Doctrine, TCPDF, and PhpSpreadsheet. Composer also handles PSR-4 autoloading for the `App\` namespace.

#### B. Docker and Docker Compose
**Purpose**: Containerized local development environment

The project includes a `Dockerfile` and `docker-compose.yml` for consistent development and deployment environments. The Docker setup includes:
- **PHP 8.3 FPM** with extensions: `pdo_mysql`, `mbstring`, `gd`, `zip`, `intl`, `opcache`, `bcmath`.
- **Nginx** as the web server (reverse-proxying to PHP-FPM).
- **Supervisor** for process management in production.
- **MySQL 8.0** as the database service with persistent volume storage.
- **phpMyAdmin** as an optional database management tool.

#### C. Railway
**Purpose**: Cloud deployment platform

The application is deployed on the Railway platform using a Dockerfile-based builder. The `railway.toml` configuration defines:
- **Build Triggers** – Watches for changes in PHP files, Composer files, templates, and config directories.
- **Health Check** – GET request on the root path with a 100-second timeout.
- **Restart Policy** – Automatic restart on failure with a maximum of 10 retries.

#### D. Symfony Asset Mapper
**Purpose**: Frontend asset management

Symfony Asset Mapper provides JavaScript module management via native browser importmaps, eliminating the need for Webpack or other JavaScript bundlers for framework libraries.

#### E. PHPUnit
**Purpose**: Unit and integration testing framework

PHPUnit is configured via `phpunit.dist.xml` for running automated tests located in the `tests/` directory, covering service-level logic and security components.

---

## 3.3 How the Project Works

### 3.3.1 System Workflow

#### A. User Authentication Flow

The authentication process follows Symfony's security component workflow with a custom authenticator (`AppAuthenticator`), a custom user provider (`AppUserProvider`), and a pre-authentication user checker (`UserChecker`).

```
User enters email and password on login page
    │
    ▼
AppAuthenticator receives login request
    │
    ▼
AppUserProvider loads user from database (by email or username)
    │
    ▼
UserChecker validates:
    ├─ Is user active? (isActive == true)
    └─ Is user not soft-deleted? (deletedAt == null)
    │
    ▼ (if valid)
Symfony Security verifies password hash (bcrypt)
    │
    ▼ (if authenticated)
LoginListener logs the login activity via ActivityLogService
    │
    ▼
Role-based redirect:
    ├─ Role 1 (Admin)           → /admin/dashboard
    ├─ Role 2 (Department Head) → /department-head/dashboard
    └─ Role 3 (Faculty)         → /faculty/dashboard
```

Additionally, a **RoleRedirectSubscriber** (event subscriber on `KernelEvents::REQUEST`) runs on every request to:
- Refresh the user from the database to detect mid-session deactivation or deletion.
- Prevent users from accessing routes outside their role's scope (e.g., a faculty member cannot access `/admin/*`).
- Invalidate sessions for deactivated or deleted users.

#### B. Schedule Creation Workflow

The schedule creation process supports creating multiple sections at once with atomic validation.

```
1. Admin/Department Head navigates to Schedule Management
    │
    ▼
2. Selects target Department (if not already selected)
    │
    ▼
3. Clicks "Create Schedule" → GET /admin/schedule/new?department={id}
    │
    ▼
4. Fills Schedule Form:
    ├─ Academic Year and Semester selection
    ├─ Subject selection (filtered by department)
    ├─ Section number(s) — supports multiple sections
    ├─ Day Pattern selection (M-W-F, T-TH, M-T-TH-F, etc.)
    ├─ Start Time and End Time
    ├─ Room selection (filtered by department + department group)
    ├─ Faculty assignment (optional)
    └─ Enrolled students count
    │
    ▼
5. Form Submission → POST /admin/schedule/new
    │
    ▼
6. Server-Side Validation:
    ├─ Input format validation (Symfony Validator)
    ├─ Time range validation (end > start, 30min ≤ duration ≤ 8hrs)
    └─ Room capacity validation (enrolled ≤ capacity)
    │
    ▼
7. Conflict Detection (ScheduleConflictDetector):
    ├─ Room-Time conflict check
    ├─ Faculty conflict check
    ├─ Duplicate subject-section check
    └─ Block-sectioning conflict check
    │
    ▼
8. Decision:
    ├─ If conflicts found → Return to form with detailed error messages
    └─ If no conflicts    → Persist all sections to database atomically
    │
    ▼
9. Set isConflicted flag on each saved schedule
    │
    ▼
10. Log activity → Redirect to index with success flash message
```

#### C. Conflict Detection Algorithm

The `ScheduleConflictDetector` service is the core algorithm of the system. The following pseudocode describes its logic:

```
Function detectConflicts(newSchedule, excludeSelf = false):
    conflicts = []

    // Step 1: Parse day pattern into individual days
    newDays = extractDays(newSchedule.dayPattern)
    // e.g., "M-W-F" → ["Monday", "Wednesday", "Friday"]

    // Step 2: Query all active schedules in the same academic year and semester
    existingSchedules = repository.findBy({
        academicYear: newSchedule.academicYear,
        semester: newSchedule.semester,
        status: 'active'
    })

    // Step 3: For each existing schedule, check for overlaps
    For each existing in existingSchedules:
        If excludeSelf AND existing.id == newSchedule.id:
            Continue  // Skip self when editing

        existingDays = extractDays(existing.dayPattern)

        // Check if any days overlap
        commonDays = array_intersect(newDays, existingDays)
        If commonDays is empty:
            Continue  // No day overlap, no conflict

        // Check if times overlap: (start1 < end2) AND (end1 > start2)
        If NOT (newSchedule.startTime < existing.endTime
                AND newSchedule.endTime > existing.startTime):
            Continue  // No time overlap, no conflict

        // Room-Time Conflict
        If existing.room.id == newSchedule.room.id:
            conflicts.add({
                type: "room_time_conflict",
                message: "Room {room.code} is already occupied by
                          {existing.subject.code} Section {existing.section}",
                conflictingSchedule: existing
            })

        // Faculty Conflict
        If newSchedule.faculty IS NOT NULL
           AND existing.faculty IS NOT NULL
           AND existing.faculty.id == newSchedule.faculty.id:
            conflicts.add({
                type: "faculty_conflict",
                message: "Faculty {faculty.fullName} is already assigned to
                          {existing.subject.code} Section {existing.section}",
                conflictingSchedule: existing
            })

    // Step 4: Check duplicate subject-section
    duplicate = repository.findOneBy({
        subject: newSchedule.subject,
        section: newSchedule.section,
        academicYear: newSchedule.academicYear,
        semester: newSchedule.semester,
        status: 'active'
    })
    If duplicate exists AND duplicate.id != newSchedule.id:
        conflicts.add({
            type: "duplicate_subject_section",
            message: "Section {section} already exists for {subject.code}"
        })

    // Step 5: Block-sectioning conflict (cross-subject)
    // Find schedules with same section number and year level
    // in the same department that overlap in time
    yearLevel = getYearLevelFromCurriculum(newSchedule.subject)
    sameBlockSchedules = findBySectionAndYearLevel(
        newSchedule.section, yearLevel, newSchedule.academicYear,
        newSchedule.semester, newSchedule.subject.department
    )
    For each blockSchedule in sameBlockSchedules:
        If timeOverlap(newSchedule, blockSchedule):
            conflicts.add({
                type: "block_sectioning_conflict",
                message: "Conflict with {blockSchedule.subject.code} —
                          same section students cannot attend both"
            })

    Return conflicts
```

#### D. Batch Conflict Scanning

The system also provides a batch scanning capability via `scanAndUpdateAllConflicts()` which iterates through all active schedules in a department, recalculates conflicts, and updates the `isConflicted` flag accordingly. This is used to maintain conflict status consistency after edits or deletions.

### 3.3.2 Data Flow Diagram

The following diagram illustrates the data flow for a typical request in the system:

```
┌──────────────┐       ┌───────────────────┐       ┌─────────────────────┐
│              │       │                   │       │                     │
│  User/Browser│──────>│  Symfony Router   │──────>│    Controller       │
│  (HTTP GET/  │       │  (URL Matching)   │       │  (AdminController,  │
│   POST)      │       │                   │       │   ScheduleController│
│              │       │                   │       │   etc.)             │
└──────────────┘       └───────────────────┘       └──────────┬──────────┘
                                                              │
                       ┌──────────────────────────────────────┘
                       │
                       ▼
              ┌─────────────────────┐       ┌─────────────────────────┐
              │                     │       │                         │
              │   Service Layer     │──────>│   Repository Layer      │
              │ (ConflictDetector,  │       │  (Doctrine Queries)     │
              │  DashboardService,  │       │                         │
              │  CurriculumService) │       └────────────┬────────────┘
              │                     │                    │
              └─────────────────────┘                    ▼
                                                ┌────────────────┐
                                                │                │
                                                │  MySQL 8.0     │
                                                │  Database      │
                                                │                │
                                                └───────┬────────┘
                                                        │
        ┌───────────────────────────────────────────────┘
        │
        ▼
┌──────────────────┐       ┌───────────────────┐       ┌──────────────┐
│                  │       │                   │       │              │
│  Twig Template   │──────>│  HTML Response    │──────>│  User/Browser│
│  (Rendering)     │       │  (with CSS/JS)    │       │              │
│                  │       │                   │       │              │
└──────────────────┘       └───────────────────┘       └──────────────┘
```

### 3.3.3 Entity Relationship Diagram

The following diagram represents the database relationships among the 13 entities in the system:

```
┌──────────────┐     1    *  ┌──────────────┐     *    1  ┌──────────────────┐
│   College    │────────────>│  Department  │────────────>│ DepartmentGroup  │
│              │             │              │             │  (Shared Rooms)  │
└──────┬───────┘             └──────┬───────┘             └──────────────────┘
       │ 1                          │ 1
       │                            │
       ▼ *                          ▼ *
┌──────────────┐             ┌──────────────┐
│    User      │             │   Subject    │
│ (Admin,      │             │ (Courses)    │
│  Dept Head,  │             └──────┬───────┘
│  Faculty)    │                    │ 1
└──────────────┘                    │
       ▲                            ▼ *
       │ *─1                 ┌──────────────┐    *─1  ┌──────────────┐
       │                     │   Schedule   │────────>│     Room     │
       │                     │              │         └──────────────┘
       └─────────────────────│  (Core Table)│
         faculty_id          │              │────────> AcademicYear (*─1)
                             └──────────────┘

┌──────────────┐     1    *  ┌──────────────────┐    1    *  ┌─────────────────────┐
│  Curriculum  │────────────>│  CurriculumTerm  │───────────>│  CurriculumSubject  │
│ (per Dept)   │             │ (Year + Semester)│            │  (Subject linkage)  │
└──────────────┘             └──────────────────┘            └──────────┬──────────┘
                                                                       │ *─1
                                                                       ▼
                                                              ┌──────────────┐
                                                              │   Subject    │
                                                              └──────────────┘

┌──────────────┐
│ ActivityLog  │────────> User (*─1, nullable)
│ (Audit Trail)│
└──────────────┘
```

### 3.3.4 Request-Response Cycle

The following example traces the complete request-response cycle for creating a new schedule:

| Step | Component | Action |
|------|-----------|--------|
| 1 | **Browser** | Admin clicks "Create Schedule" button |
| 2 | **HTTP** | GET `/admin/schedule/new?department=5` |
| 3 | **Router** | Matches route `app_schedule_new` to `ScheduleController::new()` |
| 4 | **Security** | Verifies user has `ROLE_ADMIN` or `ROLE_DEPARTMENT_HEAD` |
| 5 | **Controller** | Loads department, subjects, rooms, faculty; prepares Twig context |
| 6 | **Twig** | Renders schedule creation form with pre-populated dropdowns |
| 7 | **Browser** | User fills in subject, section(s), room, time, days, faculty; clicks Submit |
| 8 | **HTTP** | POST `/admin/schedule/new` with form data + CSRF token |
| 9 | **Controller** | Validates CSRF token; parses form input |
| 10 | **Validator** | Symfony Validator checks required fields, data types, and ranges |
| 11 | **ConflictDetector** | `validateTimeRange()` → `validateRoomCapacity()` → `detectConflicts()` |
| 12a | **If conflicts** | Controller adds flash error messages; re-renders form with errors |
| 12b | **If no conflicts** | `EntityManager::persist()` and `flush()` for each section |
| 13 | **ConflictDetector** | `updateConflictStatus()` sets `isConflicted` flag on saved schedules |
| 14 | **ActivityLogService** | Logs "Schedule Created" action with metadata |
| 15 | **Controller** | Adds success flash message; redirects to `app_schedule_index` |
| 16 | **Browser** | Displays schedule list page with green success toast |

### 3.3.5 Role-Based System Navigation

Each user role accesses a distinct set of pages and features:

**Administrator** (~88 routes under `/admin`):
- Dashboard with system-wide statistics (user counts, activity logs, system status)
- Full CRUD for Colleges, Departments, Users, Subjects, Rooms, Academic Years
- Schedule Management with cross-department visibility
- Curriculum Management with bulk upload capability
- System Settings (active semester configuration)
- Activity Log viewer with full search and filtering

**Department Head** (~40 routes under `/department-head`):
- Dashboard with department-scoped statistics
- Faculty management (create, edit, activate/deactivate) within own department
- Room management within own department
- Schedule creation and management for own department
- Faculty assignment and teaching load management
- Curriculum viewing and publish control
- Workload reports and history

**Faculty** (~8 routes under `/faculty`):
- Dashboard showing today's schedule and weekly summary
- Weekly timetable view with semester filter
- Class list with enrollment statistics
- Teaching load PDF export
- Profile management

---

## 3.4 Theoretical/Conceptual Framework

This section discusses the theories and concepts used in the course of designing and developing the Smart Scheduling System. Each concept was selected based on its direct applicability to the project's objectives of automating schedule creation, detecting conflicts, managing institutional resources, and providing role-appropriate interfaces.

### 3.4.1 Course Scheduling Problem (CSP)

The academic scheduling system is grounded in the **Course Scheduling Problem**, a well-studied constraint satisfaction problem in the fields of operations research and computer science (Carter & Laporte, 1996).

**Definition**: The Course Scheduling Problem involves assigning courses to time slots, rooms, and instructors while satisfying a set of hard and soft constraints.

**Hard Constraints** (Must be satisfied — violation makes a schedule infeasible):
- No room can host two classes at the same time on the same day.
- No instructor can be assigned to two classes at the same time on the same day.
- Room capacity must accommodate the enrolled student count.
- Each subject-section pair must be uniquely scheduled within an academic year and semester.
- Students in the same block section cannot have two classes at the same time.

**Soft Constraints** (Preferred but not mandatory — affect schedule quality):
- Minimize gaps in instructor schedules.
- Balance room utilization across available facilities.
- Distribute teaching loads equitably among faculty.
- Minimize building transitions for faculty between consecutive classes.

**Application in the System**: The `ScheduleConflictDetector` service implements the hard constraint checking. When a new schedule is submitted, the system queries all existing active schedules in the same academic year and semester, checks for day and time overlaps, and validates resource uniqueness. This constraint-checking approach ensures that no infeasible schedule is persisted to the database.

### 3.4.2 Model-View-Controller (MVC) Pattern

**Concept**: The MVC pattern separates an application into three interconnected components to isolate business logic from the user interface (Gamma et al., 1994).

**Model** (Entities + Services + Repositories):
- Represents data structures and business rules.
- In this project: 13 Doctrine entity classes define the data schema; 21 service classes contain business logic; repository classes handle database queries.

**View** (Twig Templates):
- Responsible for presenting data to the user.
- In this project: Over 50 Twig templates organized by role (`admin/`, `department_head/`, `faculty/`) with shared components in `components/`.

**Controller** (Symfony Controllers):
- Receives HTTP requests, coordinates between the Model and View, and returns HTTP responses.
- In this project: 9 controller classes collectively define over 130 routes.

**Application in the Project**:
```
User Request (HTTP)
    │
    ▼
Controller (ScheduleController::new)
    │
    ├──> Service (ScheduleConflictDetector::detectConflicts)
    │        │
    │        └──> Repository (ScheduleRepository::findBy criteria)
    │                  │
    │                  └──> Database (MySQL query)
    │
    └──> View (Twig: admin/schedule/new.html.twig)
              │
              └──> HTML Response to Browser
```

### 3.4.3 Repository Pattern

**Concept**: The Repository pattern provides an abstraction layer between domain logic and data access logic, acting as an in-memory collection of domain objects (Fowler, 2002).

**Benefits Applied in the System**:
- **Centralized Data Access** – All database queries for a given entity are defined in one place (e.g., `ScheduleRepository` for schedule queries).
- **Testability** – Repository interfaces can be mocked in unit tests without requiring a live database connection.
- **Maintainability** – Changes to query logic (e.g., adding a new filter condition) are isolated within the repository class.
- **Query Reusability** – Common queries (e.g., `findByDepartment`, `findByAcademicYearAndSemester`) are defined once and reused across controllers and services.

### 3.4.4 Dependency Injection (DI) Pattern

**Concept**: The Dependency Injection pattern is a technique where an object receives its dependencies from an external source rather than creating them internally. This is a specific implementation of the broader Inversion of Control (IoC) principle (Fowler, 2004).

**Application in the System**: Symfony's Dependency Injection Container automatically resolves and injects dependencies based on type declarations. For example:

```php
class ScheduleConflictDetector
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ScheduleRepository $scheduleRepository,
        private CurriculumSubjectRepository $curriculumSubjectRepository
    ) {}
}
```

The container automatically creates and injects the correct `EntityManager`, `ScheduleRepository`, and `CurriculumSubjectRepository` instances when `ScheduleConflictDetector` is used. This eliminates hard-coded dependencies, making the code more modular, testable, and maintainable.

### 3.4.5 Role-Based Access Control (RBAC)

**Concept**: RBAC restricts system access based on the roles assigned to individual users within an organization. Permissions are associated with roles, and users are assigned roles — simplifying access management (Ferraiolo & Kuhn, 1992).

**Implementation in the System**:

The system uses integer-based role identifiers mapped to Symfony security roles:

| Role ID | Symfony Role | Dashboard Path | Scope |
|---------|-------------|----------------|-------|
| 1 | `ROLE_ADMIN` | `/admin/dashboard` | System-wide |
| 2 | `ROLE_DEPARTMENT_HEAD` | `/department-head/dashboard` | Own department |
| 3 | `ROLE_FACULTY` | `/faculty/dashboard` | Own schedules |

**Access Control Matrix**:

| Action | Admin | Dept Head | Faculty |
|--------|:-----:|:---------:|:-------:|
| Create Schedule | ✓ | ✓ (own dept) | ✗ |
| Edit Schedule | ✓ (all) | ✓ (own dept) | ✗ |
| Delete Schedule | ✓ | ✓ (own dept) | ✗ |
| View Schedules | ✓ | ✓ (own dept) | ✓ (own only) |
| Manage Users | ✓ (all roles) | ✓ (faculty only) | ✗ |
| Manage Rooms | ✓ | ✓ (own dept) | ✗ |
| Manage Curricula | ✓ | ✓ (view + publish) | ✗ |
| Export PDF Reports | ✓ | ✓ | ✓ (own load) |
| View Activity Logs | ✓ | ✗ | ✗ |
| System Settings | ✓ | ✗ | ✗ |

Access enforcement occurs at multiple layers:
1. **Symfony Security Configuration** – Route-level access rules (e.g., `/admin/*` requires `ROLE_ADMIN`).
2. **RoleRedirectSubscriber** – Runtime request interception to prevent cross-role access.
3. **UserChecker** – Pre-authentication validation of user status.
4. **Controller-Level Checks** – `canAccessUser()` methods verify department-scoped access for department heads.

### 3.4.6 Database Normalization

**Concept**: Database normalization is the process of organizing a relational database to reduce data redundancy and improve data integrity by decomposing tables into smaller, well-structured relations (Codd, 1970).

**Normal Forms Applied in the System**:

**First Normal Form (1NF)** – All columns contain atomic (indivisible) values, and each record is uniquely identified by a primary key.
- Example: The `schedules` table stores `day_pattern` as a single coded string (e.g., `M-W-F`) rather than a set of separate columns or a comma-separated list of day names.

**Second Normal Form (2NF)** – All non-key attributes are fully functionally dependent on the primary key.
- Example: Subject details (code, title, units, hours) are stored in the `subjects` table, not repeated in the `schedules` table. The `schedules` table only stores `subject_id` as a foreign key.

**Third Normal Form (3NF)** – No transitive dependencies exist between non-key attributes.
- Example: Room building and floor information is stored in the `rooms` table. Department name is stored in `departments`. The `schedules` table references these through foreign keys rather than duplicating the data.

**Practical Application**:
```
schedules table:
    id, subject_id, room_id, faculty_id, academic_year_id,
    semester, day_pattern, start_time, end_time, section,
    enrolled_students, status, is_conflicted, notes

Rather than:
    id, subject_code, subject_title, subject_units,
    room_code, room_name, room_building, room_capacity,
    faculty_first_name, faculty_last_name, ...
```

This normalized design ensures that updates to a subject's title, a room's capacity, or a faculty member's name are made in exactly one place and automatically reflected wherever that entity is referenced.

### 3.4.7 Event-Driven Architecture

**Concept**: In event-driven architecture, components communicate through the publication and subscription of events rather than through direct method calls. This promotes loose coupling and extensibility (Fowler, 2017).

**Application in the System**: The Symfony Event Dispatcher is used in several critical areas:

1. **Authentication Events** — The `LoginListener` subscribes to `LoginSuccessEvent` and `LogoutEvent` to record login and logout activities in the activity log without modifying the core authentication code.

2. **Request Interception** — The `RoleRedirectSubscriber` listens on `KernelEvents::REQUEST` to enforce role-based routing and detect mid-session user deactivation.

3. **Entity Lifecycle Events** — Doctrine lifecycle callbacks (`@PrePersist`, `@PreUpdate`) automatically manage timestamp fields on entities, ensuring `createdAt` and `updatedAt` are consistently maintained.

### 3.4.8 Constraint Satisfaction Problem (CSP) Framework

**Concept**: A Constraint Satisfaction Problem is defined as a triple (X, D, C) where X is a set of variables, D is a set of domains for those variables, and C is a set of constraints restricting the values the variables can simultaneously take (Russell & Norvig, 2010).

**CSP Formulation for the Smart Scheduling System**:

**Variables**:
- For each schedule entry S_i: `time_slot(S_i)`, `room(S_i)`, `faculty(S_i)`, `day_pattern(S_i)`

**Domains**:
- `time_slot` ∈ {7:00 AM, 7:30 AM, ..., 9:00 PM} (30-minute granularity)
- `room` ∈ {all active rooms in the department's pool + shared department group rooms}
- `faculty` ∈ {all active faculty members in the department}
- `day_pattern` ∈ {M-W-F, T-TH, M-T-TH-F, M-T, TH-F, SAT}

**Constraints**:
```
C1 (Room Uniqueness):
    ∀ S_i, S_j where i ≠ j:
        room(S_i) = room(S_j) ∧ days_overlap(S_i, S_j)
        → ¬time_overlap(S_i, S_j)

C2 (Faculty Uniqueness):
    ∀ S_i, S_j where i ≠ j:
        faculty(S_i) = faculty(S_j) ∧ days_overlap(S_i, S_j)
        → ¬time_overlap(S_i, S_j)

C3 (Capacity):
    ∀ S_i:
        enrolled_students(S_i) ≤ capacity(room(S_i))

C4 (Section Uniqueness):
    ∀ S_i, S_j where i ≠ j:
        subject(S_i) = subject(S_j) ∧ section(S_i) = section(S_j)
        ∧ year(S_i) = year(S_j) ∧ semester(S_i) = semester(S_j)
        → false

C5 (Block Sectioning):
    ∀ S_i, S_j where i ≠ j:
        section(S_i) = section(S_j) ∧ year_level(S_i) = year_level(S_j)
        ∧ department(S_i) = department(S_j)
        ∧ days_overlap(S_i, S_j)
        → ¬time_overlap(S_i, S_j)
```

Where:
- `days_overlap(S_i, S_j)` = `|extractDays(S_i.dayPattern) ∩ extractDays(S_j.dayPattern)| > 0`
- `time_overlap(S_i, S_j)` = `(S_i.startTime < S_j.endTime) ∧ (S_i.endTime > S_j.startTime)`

### 3.4.9 Soft Delete Pattern

**Concept**: Instead of permanently removing records from the database, records are marked as "deleted" using a timestamp column (`deletedAt`). This preserves historical data and allows for data recovery (Fowler, 2002).

**Application in the System**: Six of the thirteen entities implement soft deletes: `User`, `Subject`, `Room`, `Department`, `College`, and `AcademicYear`. Queries in the repository layer filter out soft-deleted records by default (e.g., `WHERE deleted_at IS NULL`), while administrative functions can access the full dataset when needed for historical reporting.

---

## 3.5 System Integration

### 3.5.1 Component Integration Architecture

The system integrates multiple components across the full stack to provide comprehensive functionality:

```
┌─────────────────────────────────────────────────────────┐
│                 User Interface Layer                    │
│    Twig Templates + Tailwind CSS 3.3 + JavaScript      │
│    Stimulus Controllers + Hotwire Turbo                 │
└──────────────────────────┬──────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────┐
│              Symfony 7.3 Framework Core                  │
│  ┌────────────┐  ┌────────────┐  ┌───────────────────┐ │
│  │  Security  │  │   Forms    │  │ Event Dispatcher  │ │
│  │  Bundle    │  │ Component  │  │   + Subscribers   │ │
│  └────────────┘  └────────────┘  └───────────────────┘ │
│  ┌────────────┐  ┌────────────┐  ┌───────────────────┐ │
│  │  Validator │  │  Monolog   │  │  Rate Limiter     │ │
│  └────────────┘  └────────────┘  └───────────────────┘ │
└──────────────────────────┬──────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────┐
│                  Business Services (21)                  │
│  ┌─────────────────────┐  ┌───────────────────────────┐ │
│  │ ScheduleConflict    │  │  DashboardService         │ │
│  │ Detector            │  │  CurriculumService        │ │
│  └─────────────────────┘  │  CurriculumUploadService  │ │
│  ┌─────────────────────┐  └───────────────────────────┘ │
│  │ PDF Services:       │  ┌───────────────────────────┐ │
│  │ TeachingLoadPdf,    │  │  ActivityLogService       │ │
│  │ RoomSchedulePdf,    │  │  UserService              │ │
│  │ FacultyReportPdf,   │  │  RoomService              │ │
│  │ RoomsReportPdf,     │  │  SubjectService           │ │
│  │ SubjectsReportPdf   │  │  AcademicYearService      │ │
│  └─────────────────────┘  └───────────────────────────┘ │
└──────────────────────────┬──────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────┐
│               Doctrine ORM Layer                        │
│  ┌──────────────────┐  ┌──────────────────────────────┐ │
│  │  13 Entities     │  │  Repository Classes          │ │
│  │  28 Migrations   │  │  (Custom query methods)      │ │
│  └──────────────────┘  └──────────────────────────────┘ │
└──────────────────────────┬──────────────────────────────┘
                           │
┌──────────────────────────▼──────────────────────────────┐
│                    MySQL 8.0                             │
│           13 Tables, InnoDB, Foreign Keys                │
│         Composite Indexes for Conflict Queries           │
└─────────────────────────────────────────────────────────┘
```

### 3.5.2 External System Integration

The system integrates with or supports the following external capabilities:
- **PDF Export** – TCPDF generates downloadable teaching load, room schedule, faculty, room inventory, and subject PDF reports.
- **Excel Import** – PhpSpreadsheet parses uploaded `.xlsx` files for bulk curriculum subject creation.
- **Email System** – Symfony Mailer component (`symfony/mailer`) provides email notification capability.
- **Logging** – Monolog handles application-level logging to file and other transports.

---

## 3.6 Security Framework

### 3.6.1 Authentication

- **Form-Based Login** – Users authenticate via email and password through a custom `AppAuthenticator` class extending Symfony's `AbstractLoginFormAuthenticator`.
- **Custom User Provider** – `AppUserProvider` loads users from the database by email or username, supporting flexible login identifiers.
- **Pre-Authentication Checks** – `UserChecker` blocks login for inactive or soft-deleted accounts, throwing a `DisabledException` before password verification.
- **Password Hashing** – Passwords are hashed using the bcrypt algorithm via Symfony's `PasswordHasherInterface`.
- **Session Management** – PHP sessions are stored on the server with configurable lifetime; `RoleRedirectSubscriber` invalidates sessions when users are deactivated mid-session.

### 3.6.2 Authorization

- **Symfony Security Configuration** – Route-level access control rules restrict URL patterns to specific roles (e.g., `^/admin` requires `ROLE_ADMIN`).
- **CSRF Protection** – All form submissions include CSRF tokens validated server-side. A dedicated Stimulus controller (`csrf_protection_controller.js`) manages tokens for AJAX requests.
- **Runtime Role Validation** – `RoleRedirectSubscriber` intercepts every request (priority 9) to verify that users access only their role-appropriate areas.

### 3.6.3 Data Protection

- **Input Validation** – Symfony Validator component with constraint attributes (`#[Assert\NotBlank]`, `#[Assert\Length]`, `#[Assert\Range]`) validates all user input server-side.
- **SQL Injection Prevention** – Doctrine ORM uses parameterized queries exclusively; no raw SQL is executed from user input.
- **XSS Prevention** – Twig template engine auto-escapes all output by default; raw filters are used only with server-generated HTML.
- **HTTPS Enforcement** – Production deployment on Railway enforces HTTPS communication.

---

## 3.7 Performance Optimization

### 3.7.1 Database Optimization

- **Composite Indexing** – The `schedules` table includes a composite index on `(room_id, day_pattern, start_time, end_time)` to accelerate conflict detection queries.
- **Strategic Indexes** – Additional indexes on `activity_logs.action`, `activity_logs.created_at`, `departments.is_active`, and `departments.deleted_at` for common query patterns.
- **Efficient Joins** – Doctrine DQL queries use explicit joins to fetch related entities in single queries rather than N+1 patterns.
- **Connection Configuration** – InnoDB buffer pool set to 256MB in Docker Compose; savepoints enabled for nested transaction support.

### 3.7.2 Application Optimization

- **OPcache** – PHP OPcache is configured in the Docker image with 256MB memory, validating timestamps disabled in production for maximum performance.
- **Symfony Cache** – Production environment uses Symfony cache pools for Doctrine query and result caching.
- **Lazy Loading** – Doctrine lazy ghost objects are enabled, loading related entities only when accessed.
- **CSS Minification** – Tailwind CSS is compiled with the `--minify` flag for production builds, reducing the CSS payload.
- **Asset Mapping** – Symfony Asset Mapper uses importmaps for JavaScript modules, allowing the browser to natively manage module loading without a bundler.

---

## 3.8 Deployment Architecture

### 3.8.1 Local Development with Docker Compose

For local development, the system uses Docker Compose with three services:

| Service | Image | Port | Purpose |
|---------|-------|------|---------|
| `app` | Custom (Dockerfile) | 8000 | PHP 8.3 FPM + Nginx |
| `db` | `mysql:8.0` | 3306 | MySQL database with InnoDB |
| `phpmyadmin` | `phpmyadmin/phpmyadmin` | 8080 | Database management (optional, debug profile) |

### 3.8.2 Production Deployment with Railway

The production environment is deployed on the Railway cloud platform using a multi-stage Dockerfile:

```
┌───────────────────────────────────────────────────┐
│               Railway Platform                     │
│                                                    │
│  ┌──────────────────────────────────────────────┐ │
│  │         Docker Build (Multi-Stage)           │ │
│  │  Stage 1: composer install --no-dev          │ │
│  │  Stage 2: PHP 8.3-FPM + Nginx + Supervisor  │ │
│  │           Extensions: pdo_mysql, gd, zip,    │ │
│  │           intl, opcache, bcmath, mbstring    │ │
│  └──────────────────────────────────────────────┘ │
│                                                    │
│  ┌──────────────────────────────────────────────┐ │
│  │         Application Runtime                  │ │
│  │  Nginx (reverse proxy) → PHP-FPM             │ │
│  │  Supervisor manages both processes           │ │
│  │  Start script: /usr/local/bin/start.sh       │ │
│  └──────────────────────────────────────────────┘ │
│                                                    │
│  ┌──────────────────────────────────────────────┐ │
│  │         MySQL Database Service               │ │
│  │  Railway-managed MySQL instance              │ │
│  └──────────────────────────────────────────────┘ │
│                                                    │
│  Health Check: GET / (timeout: 100s)               │
│  Restart Policy: on_failure (max 10 retries)       │
│  Timezone: Asia/Manila                             │
└───────────────────────────────────────────────────┘
```

**Build Process**:
1. Railway detects the `Dockerfile` based on `railway.toml` configuration.
2. Multi-stage build installs PHP dependencies (`composer install --no-dev`) in a separate stage.
3. Production image includes PHP 8.3 FPM, Nginx, and Supervisor with OPcache pre-configured.
4. Environment variables (including `DATABASE_URL`) are baked into `.env.local.php` during the build.
5. The `start.sh` script launches Supervisor, which manages both Nginx and PHP-FPM processes.
6. Railway performs a health check on the root URL (`/`) before routing live traffic to the new deployment.

---

## References

Carter, M. W., & Laporte, G. (1996). Recent developments in practical course timetabling. In *Practice and Theory of Automated Timetabling* (pp. 3–19). Springer.

Codd, E. F. (1970). A relational model of data for large shared data banks. *Communications of the ACM*, 13(6), 377–387.

Doctrine Project. (2024). Doctrine ORM documentation. Retrieved from https://www.doctrine-project.org

Ferraiolo, D. F., & Kuhn, D. R. (1992). Role-based access controls. In *Proceedings of the 15th National Computer Security Conference* (pp. 554–563).

Fowler, M. (2002). *Patterns of Enterprise Application Architecture*. Addison-Wesley.

Fowler, M. (2004). Inversion of control containers and the dependency injection pattern. Retrieved from https://martinfowler.com/articles/injection.html

Fowler, M. (2017). What do you mean by "Event-Driven"? Retrieved from https://martinfowler.com/articles/201701-event-driven.html

Gamma, E., Helm, R., Johnson, R., & Vlissides, J. (1994). *Design Patterns: Elements of Reusable Object-Oriented Software*. Addison-Wesley.

Russell, S. J., & Norvig, P. (2010). *Artificial Intelligence: A Modern Approach* (3rd ed.). Prentice Hall.

Symfony. (2024). The Symfony framework documentation. Retrieved from https://symfony.com/doc

Tailwind Labs. (2024). Tailwind CSS documentation. Retrieved from https://tailwindcss.com/docs

Railway Corp. (2024). Railway documentation. Retrieved from https://docs.railway.app
