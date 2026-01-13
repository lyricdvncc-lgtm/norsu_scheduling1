# Smart Scheduling System

A comprehensive web-based class scheduling and management system built with Symfony 7.3 for educational institutions. The system streamlines the process of creating, managing, and tracking academic schedules while preventing conflicts and optimizing resource allocation.

## 🎯 Features

### For Administrators
- **Dashboard Overview**: Real-time statistics and system monitoring
- **User Management**: Create and manage faculty, department heads, and admin accounts
- **Department Management**: Organize departments and assign department heads
- **Curriculum Management**: 
  - Import curriculum templates via CSV
  - Manage subjects and prerequisites
  - Configure course requirements by year level
- **Schedule Management**:
  - Automated conflict detection
  - Room and faculty availability checking
  - Batch schedule creation
  - Visual weekly schedule view
  - Export schedules to PDF
- **Room Management**: Configure classrooms, labs, and other facilities
- **Academic Year & Semester Control**: Set current academic periods

### For Department Heads
- **Department Dashboard**: Overview of department schedules and faculty
- **Schedule Creation**: Create and approve schedules for their department
- **Faculty Assignment**: Assign courses to department faculty members
- **Curriculum Oversight**: Manage department-specific curricula
- **Schedule Validation**: Review and validate schedule conflicts

### For Faculty Members
- **Personal Dashboard**: 
  - Today's schedule with real-time status (In Progress, Upcoming, Completed)
  - Weekly teaching load statistics
  - Active classes overview
- **Schedule View**: 
  - Complete weekly schedule
  - PDF export functionality
  - Semester filtering
- **Class Management**: 
  - View assigned classes
  - Student enrollment numbers
  - Room assignments
- **Profile Management**: Update personal and academic information
- **Performance Analytics**: View teaching load and class statistics

## 🛠️ Technology Stack

- **Framework**: Symfony 7.3 (PHP 8.2+)
- **Database**: MySQL/MariaDB with Doctrine ORM
- **Frontend**: 
  - Twig templating
  - Tailwind CSS for styling
  - Alpine.js for interactivity
  - Symfony UX Turbo for SPA-like experience
- **PDF Generation**: TCPDF
- **File Processing**: PhpSpreadsheet for CSV imports
- **Security**: Symfony Security component with role-based access control

## 📋 Requirements

- PHP 8.2 or higher
- Composer 2.x
- MySQL 5.7+ or MariaDB 10.3+
- Node.js 18+ (for asset compilation, if needed)
- Apache/Nginx web server

## 🚀 Installation

### 1. Clone the Repository

```bash
git clone https://github.com/jdkwer/Norsu_Sced.git
cd smart_scheduling_system
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Configure Environment

Copy the `.env` file and configure your database settings:

```bash
cp .env .env.local
```

Edit `.env.local`:

```env
DATABASE_URL="mysql://username:password@127.0.0.1:3306/scheduling_db?serverVersion=8.0"
```

### 4. Create Database and Run Migrations

```bash
# Create the database
php bin/console doctrine:database:create

# Run migrations
php bin/console doctrine:migrations:migrate
```

### 5. Create Admin User

Run the admin creation command:

```bash
php bin/console app:create-admin
```

You will be prompted for:
- Username (default: admin)
- Email (default: admin@norsu.edu.ph)
- Password (minimum 8 characters)
- First Name (default: Admin)
- Last Name (default: User)

**Alternative**: Create admin with arguments:

```bash
php bin/console app:create-admin admin admin@norsu.edu.ph secretpassword --first-name=John --last-name=Doe
```

### 6. Start the Development Server

```bash
symfony server:start
```

Or use PHP's built-in server:

```bash
php -S localhost:8000 -t public
```

Visit `http://localhost:8000` in your browser.

## 👤 User Roles

The system has three main user roles:

1. **ROLE_ADMIN** (Role ID: 1)
   - Full system access
   - User and department management
   - Global schedule oversight

2. **ROLE_DEPT_HEAD** (Role ID: 2)
   - Department-specific management
   - Schedule creation for department
   - Faculty assignment within department

3. **ROLE_FACULTY** (Role ID: 3)
   - Personal schedule viewing
   - Class management
   - Profile updates

## 📖 Usage Guide

### Creating a New Academic Year

1. Login as Admin
2. Navigate to **Settings** → **Academic Years**
3. Click **Add Academic Year**
4. Enter year (e.g., "2024-2025")
5. Set as current if needed

### Importing Curriculum

1. Prepare a CSV file with curriculum data:
   - Columns: `year_level`, `semester`, `subject_code`, `subject_title`, `units`, `lec_hours`, `lab_hours`, `prerequisites`
2. Navigate to **Curriculum** → **Import**
3. Select department and program
4. Upload CSV file
5. Review and confirm import

### Creating Schedules

1. Navigate to **Schedules** → **Create Schedule**
2. Select:
   - Academic Year & Semester
   - Department
   - Subject
   - Faculty
   - Room
   - Day Pattern (M-W-F, T-TH, M-T-TH-F, etc.) - Note: Wednesday is reserved for events
   - Time slots
3. System automatically checks for conflicts
4. Save schedule

### Faculty Dashboard Features

Faculty members can:
- View today's schedule organized by time:
  - **In Progress**: Currently ongoing classes (with pulsing indicator)
  - **Upcoming**: Future classes today
  - **Completed**: Finished classes
- Export weekly schedule to PDF
- View teaching load statistics
- Access class details and student counts

## 🔧 Maintenance Commands

### Create Admin User
```bash
php bin/console app:create-admin
```

### Clean Orphaned Curricula
```bash
php bin/console app:clean-orphaned-curricula
```

### Clear Cache
```bash
php bin/console cache:clear
```

### Database Backup (Example)
```bash
mysqldump -u username -p scheduling_db > backup_$(date +%Y%m%d).sql
```

## 🎨 Customization

### Styling
The system uses Tailwind CSS. Modify styles in:
- `assets/styles/app.css`
- Inline classes in Twig templates

### Templates
Twig templates are located in:
- `templates/admin/` - Admin views
- `templates/faculty/` - Faculty views
- `templates/department_head/` - Department head views

### Configuration
Adjust system settings in:
- `config/packages/` - Symfony configuration
- `.env` - Environment variables

## 🐛 Troubleshooting

### Database Connection Issues
- Verify database credentials in `.env.local`
- Ensure MySQL/MariaDB service is running
- Check database exists: `php bin/console doctrine:database:create`

### Permission Errors
```bash
# Fix file permissions
chmod -R 777 var/
```

### Clear Cache Issues
```bash
php bin/console cache:clear --no-warmup
php bin/console cache:warmup
```

## 📝 License

This project is proprietary software. All rights reserved.

## 👥 Credits

Developed for Negros Oriental State University (NORSU) scheduling management.

## 📧 Support

For issues and questions, please contact the IT department or system administrator.

---

**Version**: 1.0  
**Last Updated**: December 2025  
**Symfony Version**: 7.3.*
