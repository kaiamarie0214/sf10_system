# School Year Selection on Login - Setup Guide

## Overview
This implementation allows admin and teachers to **select which school year** they want to work with when logging in. This enables access to different datasets (students, grades, etc.) for different school years.

---

## Key Features

### 1. **Login with School Year Selection**
- Users see a dropdown of available school years on login page
- Can select which school year to access
- Current/default school year is pre-selected
- Only non-archived school years are shown

### 2. **Multiple Subject Assignments**
- Teachers can be assigned to multiple subjects
- Assignments can span different grades and sections
- Example: Teacher John can teach:
  - Math to Grade 1-A
  - Math to Grade 1-B
  - Science to Grade 2-A

### 3. **Assignment Management Page**
- Admin can manage teacher subject assignments
- Select teacher → Select school year → Add assignments
- Real-time assignment editor
- Bulk save functionality

---

## Files Created/Updated

### New Files:
1. **login_with_school_year.php** - Login page with school year selector
2. **pages/teacher_subject_assignments.php** - Manage teacher assignments
3. **docs/LOGIN_SCHOOL_YEAR_GUIDE.md** - This guide

### Updated Files:
1. **database_updates/school_year_reimplementation.sql**
   - Updated stored procedure to support manual school year selection
   - `is_active` now means "default on login" instead of "only active year"

---

## Setup Instructions

### Step 1: Run Database Migration
```bash
# Make sure you have a backup first!
mysql -u root sf10_system < database_updates/school_year_reimplementation.sql
mysql -u root sf10_system < database_updates/migrate_existing_data.sql
```

### Step 2: Replace Login Page
You have two options:

**Option A: Replace existing login.php**
```bash
# Backup old login
mv login.php login.php.backup

# Use new login
mv login_with_school_year.php login.php
```

**Option B: Keep both (for testing)**
- Access new login at: `http://localhost/sf10_system/login_with_school_year.php`
- Access old login at: `http://localhost/sf10_system/login.php`

### Step 3: Create School Years
1. Login as admin
2. Go to "School Year Management" page
3. Create school years (example):
   - 2024-2025 (archived or active)
   - 2025-2026 (current - set as default)
   - 2026-2027 (upcoming)

### Step 4: Create Class Sections
For each school year, create class sections:
```sql
-- Example: Create classes for school year 2025-2026
INSERT INTO classes_per_year (school_year_id, grade_level, section, capacity)
VALUES 
    (2, 1, 'A', 40),
    (2, 1, 'B', 40),
    (2, 2, 'A', 40);
```

### Step 5: Assign Teachers to Subjects
1. Go to "Teacher Subject Assignments" page
2. Select a teacher from the left panel
3. Select school year
4. Click "Add Assignment"
5. Select Subject, Grade, Section
6. Repeat for multiple assignments
7. Click "Save All Assignments"

---

## Usage Workflow

### For Admin:

#### 1. Create New School Year
```sql
CALL sp_create_new_school_year(
    '2026-2027',     -- year
    '2026-08-01',    -- start_date
    '2027-05-31',    -- end_date
    1,               -- admin user_id
    1                -- make_default (1=yes, 0=no)
);
```

#### 2. Setup Classes
- Create class sections via admin interface
- Assign advisers to each class

#### 3. Assign Teachers
- Use "Teacher Subject Assignments" page
- Assign teachers to subjects per grade/section
- Example assignments for Teacher John:
  ```
  Subject: Mathematics, Grade: 1, Section: A
  Subject: Mathematics, Grade: 1, Section: B
  Subject: Science, Grade: 2, Section: A
  ```

#### 4. Enroll Students
- Students must be enrolled per school year
- Use enrollment interface or stored procedure

---

### For Teachers:

#### 1. Login
1. Go to login page
2. **Select School Year** from dropdown
3. Enter username and password
4. Click Login

#### 2. View Assignments
- Dashboard shows all subject assignments
- Example display:
  ```
  My Subject Assignments (2025-2026):
  - Mathematics → Grade 1-A [Enter Grades]
  - Mathematics → Grade 1-B [Enter Grades]
  - Science → Grade 2-A [Enter Grades]
  ```

#### 3. Enter Grades
- Click "Enter Grades" for a specific assignment
- Only students in that grade/section are shown
- Can only enter grades for assigned subjects

---

## Database Changes

### School Years Table
```sql
is_active = 1  -- This school year is DEFAULT on login dropdown
is_active = 0  -- Not default (but still selectable if not archived)
status = 'active'    -- Currently ongoing
status = 'upcoming'  -- Future school year
status = 'archived'  -- Past (hidden from login)
```

### Subject Teacher Assignments Table
```sql
subject_teacher_assignments:
- id
- school_year_id (which school year)
- teacher_id (which teacher)
- subject_id (which subject)
- grade_level (which grade)
- section (which section)
```

**Example Data:**
| teacher_id | subject_id | subject_name | grade_level | section | school_year |
|------------|------------|--------------|-------------|---------|-------------|
| 10         | 4          | Mathematics  | 1           | A       | 2025-2026   |
| 10         | 4          | Mathematics  | 1           | B       | 2025-2026   |
| 10         | 5          | Science      | 2           | A       | 2025-2026   |
| 11         | 3          | English      | 1           | A       | 2025-2026   |
| 11         | 3          | English      | 2           | A       | 2025-2026   |

---

## Login Page Features

### School Year Dropdown
- Shows all non-archived school years
- Default/current year is pre-selected
- Shows "(Current)" label for default year
- Visual indicators:
  - Green border = Active
  - Yellow border = Upcoming

### Session Variables Set on Login
```php
$_SESSION['user_id']
$_SESSION['username']
$_SESSION['role']
$_SESSION['full_name']
$_SESSION['school_year_id']      // Selected school year ID
$_SESSION['school_year']         // Selected school year (e.g., "2025-2026")
$_SESSION['school_year_status']  // active/upcoming/archived

// For teachers only:
$_SESSION['subject_assignments'] // Array of subject assignments
$_SESSION['is_adviser']          // true/false
$_SESSION['adviser_class']       // Class info if adviser
```

---

## Teacher Subject Assignment Page

### Features:
- **Left Panel**: List of all teachers
- **Right Panel**: Assignment editor for selected teacher
- **School Year Selector**: Choose which year to edit assignments for
- **Add Multiple Assignments**: Click "Add Assignment" repeatedly
- **Bulk Save**: Saves all assignments at once

### How to Assign:
1. Click teacher name (e.g., "John Doe")
2. Select school year from dropdown
3. Click "Add Assignment" button
4. Select Subject, Grade, Section for each assignment
5. Click "Save All Assignments"

### Example Assignment Session:
```
Teacher: John Doe
School Year: 2025-2026

Assignments:
1. Mathematics → Grade 1 → Section A
2. Mathematics → Grade 1 → Section B
3. Science → Grade 2 → Section A
4. English → Grade 3 → Section C

[Save All Assignments]
```

---

## Switching School Years

### Option 1: Logout and Login Again
1. Logout
2. Login
3. Select different school year from dropdown
4. Continue working

### Option 2: Add School Year Switcher (Future Enhancement)
Could add a dropdown in header to switch school years without logging out.

---

## Validation & Security

### Login Validation:
- ✓ School year must be selected
- ✓ School year must exist and not be archived
- ✓ User credentials verified
- ✓ Session includes selected school year

### Grading Validation:
- ✓ Teacher must be assigned to subject for that class
- ✓ School year must match login session
- ✓ Database trigger validates before insert
- ✓ Function: `fn_can_teacher_grade_subject()`

---

## Example Scenarios

### Scenario 1: Admin wants to work on next year's setup
1. Login
2. Select "2026-2027" from school year dropdown
3. Login
4. Create class sections for 2026-2027
5. Assign teachers to subjects
6. Pre-enroll students

### Scenario 2: Teacher needs to review last year's grades
1. Login
2. Select "2024-2025" from dropdown
3. Login
4. View grades (read-only if archived)

### Scenario 3: Teacher John teaches multiple subjects
**Admin Setup:**
1. Go to Teacher Subject Assignments
2. Select "John Doe"
3. Select school year "2025-2026"
4. Add assignments:
   - Math → Grade 1-A
   - Math → Grade 1-B
   - Science → Grade 2-A
5. Save

**Teacher Experience:**
1. Login (select 2025-2026)
2. Dashboard shows:
   ```
   My Subject Assignments:
   • Mathematics - Grade 1-A [Enter Grades]
   • Mathematics - Grade 1-B [Enter Grades]
   • Science - Grade 2-A [Enter Grades]
   ```
3. Click "Enter Grades" for each
4. Can only grade assigned subjects

---

## Troubleshooting

### Issue: No school years shown on login
**Solution:**
```sql
-- Check if any school years exist
SELECT * FROM school_years WHERE status != 'archived';

-- Create one if needed
INSERT INTO school_years (year, start_date, end_date, is_active, status, created_by)
VALUES ('2025-2026', '2025-08-01', '2026-05-31', 1, 'active', 1);
```

### Issue: Teacher can't enter grades
**Check:**
1. Is teacher assigned to subject for that class?
   ```sql
   SELECT * FROM subject_teacher_assignments
   WHERE teacher_id = ? AND school_year_id = ?;
   ```

2. Does class exist for this school year?
   ```sql
   SELECT * FROM classes_per_year
   WHERE school_year_id = ? AND grade_level = ? AND section = ?;
   ```

3. Is student enrolled in this school year?
   ```sql
   SELECT * FROM school_year_enrollments
   WHERE student_id = ? AND school_year_id = ?;
   ```

---

## Next Steps

1. ✅ Run database migrations
2. ✅ Replace/test login page
3. ✅ Create initial school years
4. ✅ Setup class sections
5. ✅ Assign teachers to subjects
6. ✅ Test login with different school years
7. ✅ Test grade entry with teacher accounts
8. ✅ Train staff on new workflow

---

## Support & References

- Main Guide: `docs/SCHOOL_YEAR_SYSTEM_GUIDE.md`
- Implementation: `docs/IMPLEMENTATION_GUIDE.md`
- Database Schema: `database_updates/school_year_reimplementation.sql`
- Login Page: `login_with_school_year.php`
- Teacher Assignments: `pages/teacher_subject_assignments.php`
