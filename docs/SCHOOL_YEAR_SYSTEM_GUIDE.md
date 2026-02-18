# School Year Re-Implementation Guide

## Overview
This document explains the re-implemented school year management system that provides complete data isolation per school year, proper teacher-subject assignments, and role-based grading permissions.

---

## Key Changes

### 1. **Data Isolation Per School Year**
- Every school year now has its own isolated dataset
- Students are enrolled per school year (not globally)
- Grades, quarter locks, and assignments are tied to specific school years
- Each school year can be independently managed and archived

### 2. **Teacher Role Separation**
- **Subject Teachers**: Assigned to teach specific subjects to specific grade/section
  - Can ONLY enter grades for their assigned subjects
  - Can teach multiple subjects across different classes
- **Class Advisers**: Assigned to manage a specific class section
  - Can view their complete class roster
  - Cannot enter grades unless also assigned as subject teacher

### 3. **Grading Permission System**
- Teachers can only enter grades for subjects they are assigned to
- System validates permissions using `fn_can_teacher_grade_subject()`
- Automatic validation via database trigger before grade insertion

---

## Database Structure

### Core Tables

#### 1. `school_years`
Manages school year lifecycle and status.

```sql
school_years
├── id (PK)
├── year (e.g., "2025-2026")
├── start_date
├── end_date
├── is_active (only ONE can be active)
├── status (upcoming, active, archived)
├── created_by (admin user)
└── created_at / updated_at
```

**Key Points:**
- Only one school year can be `is_active = 1` at a time
- Status transitions: `upcoming` → `active` → `archived`
- Admin creates new school year using `sp_create_new_school_year()`

---

#### 2. `school_year_enrollments`
Links students to specific school years (replaces global student assignment).

```sql
school_year_enrollments
├── id (PK)
├── school_year_id (FK → school_years)
├── student_id (FK → students)
├── school_attended_id (FK → schools_attended)
├── grade_level
├── section
├── enrollment_status (enrolled, transferred, dropped, graduated)
├── enrollment_date
└── created_by / created_at
```

**Key Points:**
- Each student must be enrolled per school year
- Unique constraint: ONE enrollment per student per school year
- Tracks enrollment status throughout the year

---

#### 3. `classes_per_year`
Defines class sections per school year with advisers.

```sql
classes_per_year
├── id (PK)
├── school_year_id (FK → school_years)
├── grade_level
├── section
├── adviser_id (FK → users) ← Class adviser
├── room_number
├── capacity
├── current_count (auto-updated)
└── status
```

**Key Points:**
- Each class section is defined per school year
- Adviser is assigned at class level
- Current count auto-updates when students enroll/unenroll

---

#### 4. `subject_teacher_assignments`
Assigns teachers to specific subjects for specific classes.

```sql
subject_teacher_assignments
├── id (PK)
├── school_year_id (FK → school_years)
├── teacher_id (FK → users)
├── subject_id (FK → subjects)
├── grade_level
├── section
└── created_by / created_at
```

**Key Points:**
- Defines WHO can teach WHAT subject to WHICH class
- Unique constraint prevents duplicate assignments
- This table controls grading permissions

---

#### 5. `grades` (Enhanced)
Grade entries now linked to school year.

```sql
grades
├── id (PK)
├── school_year_id (FK → school_years) ← NEW!
├── student_id (FK → students)
├── school_attended_id (FK → schools_attended)
├── subject_id (FK → subjects)
├── quarter (1, 2, 3, 4)
├── grade
├── final_rating
├── teacher_id (FK → users)
└── ... (other fields)
```

**Validation:**
- Trigger validates teacher can grade this subject for this class
- School year must be active
- Teacher must be in `subject_teacher_assignments` for this combination

---

### Supporting Tables

#### `quarter_locks` (Enhanced)
```sql
quarter_locks
├── school_year_id (FK → school_years) ← NEW!
├── school_attended_id
├── quarter
└── locked (0/1)
```
**Now isolated per school year!**

---

## Admin Workflow

### A. Creating a New School Year

```sql
-- Method 1: Using stored procedure (recommended)
CALL sp_create_new_school_year(
    '2026-2027',              -- year
    '2026-08-01',             -- start_date
    '2027-05-31',             -- end_date
    6                          -- admin user_id
);

-- Method 2: Manual insert
INSERT INTO school_years (year, start_date, end_date, is_active, status, created_by)
VALUES ('2026-2027', '2026-08-01', '2027-05-31', 1, 'active', 6);
```

**What happens:**
1. All previous school years set to `is_active = 0` and `status = 'archived'`
2. New school year created with `is_active = 1` and `status = 'active'`
3. Action logged in `change_logs`

---

### B. Setting Up Classes

```sql
-- Create class sections for the school year
INSERT INTO classes_per_year 
    (school_year_id, grade_level, section, adviser_id, capacity)
VALUES 
    (1, 1, 'A', 10, 40),  -- Grade 1-A, Teacher ID 10 as adviser
    (1, 1, 'B', 11, 40),  -- Grade 1-B, Teacher ID 11 as adviser
    (1, 2, 'A', 12, 40);  -- Grade 2-A, Teacher ID 12 as adviser
```

---

### C. Assigning Subject Teachers

```sql
-- Assign Teacher ID 15 to teach Mathematics to Grade 1-A
INSERT INTO subject_teacher_assignments
    (school_year_id, teacher_id, subject_id, grade_level, section, created_by)
VALUES
    (1, 15, 4, 1, 'A', 6);  -- Math (subject_id=4) to Grade 1-A

-- Assign Teacher ID 15 to teach Mathematics to Grade 1-B (same teacher, different section)
INSERT INTO subject_teacher_assignments
    (school_year_id, teacher_id, subject_id, grade_level, section, created_by)
VALUES
    (1, 15, 4, 1, 'B', 6);

-- Assign Teacher ID 16 to teach English to Grade 1-A
INSERT INTO subject_teacher_assignments
    (school_year_id, teacher_id, subject_id, grade_level, section, created_by)
VALUES
    (1, 16, 3, 1, 'A', 6);  -- English (subject_id=3)
```

**Key Points:**
- One teacher can teach multiple subjects
- One teacher can teach multiple sections
- One subject can have different teachers for different sections
- This defines grading permissions

---

### D. Enrolling Students

```sql
-- Method 1: Using stored procedure (recommended)
CALL sp_enroll_student(
    1,              -- school_year_id
    101,            -- student_id
    50,             -- school_attended_id
    1,              -- grade_level
    'A',            -- section
    '2026-08-01',   -- enrollment_date
    6               -- admin user_id
);

-- Method 2: Manual insert
INSERT INTO school_year_enrollments
    (school_year_id, student_id, school_attended_id, grade_level, section, 
     enrollment_status, enrollment_date, created_by)
VALUES
    (1, 101, 50, 1, 'A', 'enrolled', '2026-08-01', 6);

-- Update class count
UPDATE classes_per_year 
SET current_count = current_count + 1
WHERE school_year_id = 1 AND grade_level = 1 AND section = 'A';
```

---

## Teacher Workflow

### Teacher Types

#### 1. **Adviser Only**
- Can view students in their assigned class
- Cannot enter grades (unless also assigned as subject teacher)

**Query to get my class roster:**
```sql
SELECT * FROM v_class_roster
WHERE school_year_id = 1
  AND adviser_id = ? -- current teacher's user_id
  AND grade_level = ?
  AND section = ?;
```

---

#### 2. **Subject Teacher**
- Can enter grades ONLY for assigned subjects and classes
- May teach multiple subjects/sections

**Query to check what I can teach:**
```sql
SELECT * FROM v_teacher_grading_permissions
WHERE teacher_id = ? -- current teacher's user_id
  AND school_year_id = (SELECT id FROM school_years WHERE is_active = 1);
```

**Example result:**
| teacher_id | subject_name | grade_level | section |
|------------|--------------|-------------|---------|
| 15         | Mathematics  | 1           | A       |
| 15         | Mathematics  | 1           | B       |
| 15         | Science      | 2           | A       |

This teacher can ONLY enter Math grades for Grade 1-A, 1-B, and Science grades for Grade 2-A.

---

### Entering Grades

```sql
-- Teacher enters grade
INSERT INTO grades 
    (school_year_id, student_id, subject_id, quarter, grade, teacher_id, school_year)
VALUES
    (1, 101, 4, 1, 85, 15, '2026-2027');

-- Trigger validates:
-- 1. Is school year active? ✓
-- 2. Is teacher assigned to teach Math (subject_id=4) to student's class? ✓
-- 3. If validation fails → ERROR: Teacher not authorized
```

**The system automatically checks:**
```sql
-- Internal validation (in trigger)
SELECT fn_can_teacher_grade_subject(
    15,  -- teacher_id
    4,   -- subject_id (Math)
    1,   -- grade_level
    'A', -- section
    1    -- school_year_id
);
-- Returns 1 (true) if teacher is assigned, 0 (false) otherwise
```

---

## Useful Queries

### 1. Get Active School Year
```sql
SELECT * FROM school_years WHERE is_active = 1;
```

---

### 2. Get All Students in a Class
```sql
SELECT * FROM v_class_roster
WHERE school_year_id = 1
  AND grade_level = 1
  AND section = 'A'
  AND enrollment_status = 'enrolled';
```

---

### 3. Get Teacher's Subject Assignments
```sql
SELECT 
    s.subject_name,
    sta.grade_level,
    sta.section,
    COUNT(DISTINCT sye.student_id) as student_count
FROM subject_teacher_assignments sta
JOIN subjects s ON sta.subject_id = s.id
LEFT JOIN school_year_enrollments sye ON 
    sye.school_year_id = sta.school_year_id
    AND sye.grade_level = sta.grade_level
    AND sye.section = sta.section
    AND sye.enrollment_status = 'enrolled'
WHERE sta.teacher_id = ?
  AND sta.school_year_id = (SELECT id FROM school_years WHERE is_active = 1)
GROUP BY sta.id, s.subject_name, sta.grade_level, sta.section;
```

---

### 4. Get Adviser's Class Roster
```sql
SELECT * FROM v_class_roster
WHERE adviser_id = ?
  AND school_year_id = (SELECT id FROM school_years WHERE is_active = 1)
ORDER BY student_name;
```

---

### 5. Check If Teacher Can Grade a Subject
```sql
SELECT fn_can_teacher_grade_subject(
    ?,  -- teacher_id
    ?,  -- subject_id
    ?,  -- grade_level
    ?,  -- section
    ?   -- school_year_id
) as can_grade;
```

---

### 6. Get Student's Grades for Current Year
```sql
SELECT 
    sub.subject_name,
    g.quarter,
    g.grade,
    g.final_rating,
    u.full_name as teacher_name
FROM grades g
JOIN subjects sub ON g.subject_id = sub.id
JOIN users u ON g.teacher_id = u.id
WHERE g.student_id = ?
  AND g.school_year_id = (SELECT id FROM school_years WHERE is_active = 1)
ORDER BY sub.subject_name, g.quarter;
```

---

## Application Integration

### PHP Session Management

Update your session to include:
```php
// After login, store active school year in session
$active_year = $conn->query("SELECT * FROM school_years WHERE is_active = 1")->fetch_assoc();
$_SESSION['school_year_id'] = $active_year['id'];
$_SESSION['school_year'] = $active_year['year'];

// For teachers, load their permissions
if ($_SESSION['role'] == 'teacher') {
    $teacher_id = $_SESSION['user_id'];
    
    // Get subject assignments
    $assignments = $conn->query("
        SELECT * FROM v_teacher_grading_permissions 
        WHERE teacher_id = $teacher_id
    ")->fetch_all(MYSQLI_ASSOC);
    
    $_SESSION['subject_assignments'] = $assignments;
    
    // Get adviser assignment
    $adviser_class = $conn->query("
        SELECT * FROM classes_per_year 
        WHERE adviser_id = $teacher_id 
        AND school_year_id = {$_SESSION['school_year_id']}
    ")->fetch_assoc();
    
    $_SESSION['adviser_class'] = $adviser_class;
}
```

---

### Grade Entry Validation (PHP)

```php
// Before inserting grade, check permission
function canTeacherGradeSubject($teacher_id, $subject_id, $grade_level, $section, $school_year_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT fn_can_teacher_grade_subject(?, ?, ?, ?, ?) as can_grade
    ");
    $stmt->bind_param("iiisi", $teacher_id, $subject_id, $grade_level, $section, $school_year_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    return $result['can_grade'] == 1;
}

// Usage in grade entry
if (!canTeacherGradeSubject($_SESSION['user_id'], $subject_id, $grade_level, $section, $_SESSION['school_year_id'])) {
    die(json_encode(['success' => false, 'message' => 'Not authorized to grade this subject']));
}
```

---

## Migration Steps

### Step 1: Backup Database
```bash
mysqldump -u root sf10_system > sf10_backup_before_migration.sql
```

---

### Step 2: Run Migration Scripts
```bash
# 1. Apply schema changes
mysql -u root sf10_system < database_updates/school_year_reimplementation.sql

# 2. Migrate existing data
mysql -u root sf10_system < database_updates/migrate_existing_data.sql
```

---

### Step 3: Verify Migration
```sql
-- Check school years created
SELECT * FROM school_years;

-- Check enrollments migrated
SELECT school_year_id, COUNT(*) as student_count 
FROM school_year_enrollments 
GROUP BY school_year_id;

-- Check grades have school_year_id
SELECT 
    COUNT(*) as total_grades,
    COUNT(school_year_id) as grades_with_year,
    COUNT(*) - COUNT(school_year_id) as missing_year
FROM grades;
```

---

### Step 4: Update Application Code
See [IMPLEMENTATION_GUIDE.md](IMPLEMENTATION_GUIDE.md) for PHP code updates.

---

## Benefits of New System

✅ **Data Isolation**: Each school year is completely separate  
✅ **Clean Slate**: Easy to start fresh each year  
✅ **Role Clarity**: Clear distinction between advisers and subject teachers  
✅ **Security**: Teachers can only grade assigned subjects  
✅ **Scalability**: Easy to archive old years without affecting current data  
✅ **Reporting**: Better analytics per school year  
✅ **Audit Trail**: Complete tracking of who can grade what  

---

## Common Scenarios

### Scenario 1: Teacher teaches same subject to multiple sections
```sql
-- Assign Teacher 20 to teach English to Grade 2-A, 2-B, 2-C
INSERT INTO subject_teacher_assignments (school_year_id, teacher_id, subject_id, grade_level, section)
VALUES 
    (1, 20, 3, 2, 'A'),
    (1, 20, 3, 2, 'B'),
    (1, 20, 3, 2, 'C');
```

---

### Scenario 2: Teacher is both adviser AND subject teacher
```sql
-- Teacher 25 is adviser for Grade 3-A
UPDATE classes_per_year 
SET adviser_id = 25
WHERE school_year_id = 1 AND grade_level = 3 AND section = 'A';

-- Teacher 25 also teaches Filipino to Grade 3-A
INSERT INTO subject_teacher_assignments (school_year_id, teacher_id, subject_id, grade_level, section)
VALUES (1, 25, 2, 3, 'A');  -- Filipino

-- Teacher can: View class roster AND enter Filipino grades
```

---

### Scenario 3: Starting a new school year (complete workflow)
```sql
-- 1. Admin creates new school year
CALL sp_create_new_school_year('2027-2028', '2027-08-01', '2028-05-31', 6);

-- 2. Admin creates class sections
INSERT INTO classes_per_year (school_year_id, grade_level, section, capacity)
VALUES 
    (2, 1, 'A', 40),
    (2, 1, 'B', 40),
    (2, 2, 'A', 40);

-- 3. Admin assigns advisers
UPDATE classes_per_year SET adviser_id = 10 WHERE school_year_id = 2 AND grade_level = 1 AND section = 'A';
UPDATE classes_per_year SET adviser_id = 11 WHERE school_year_id = 2 AND grade_level = 1 AND section = 'B';

-- 4. Admin assigns subject teachers
INSERT INTO subject_teacher_assignments (school_year_id, teacher_id, subject_id, grade_level, section)
VALUES 
    (2, 15, 4, 1, 'A'),  -- Math teacher
    (2, 16, 3, 1, 'A');  -- English teacher

-- 5. Admin enrolls students (or bulk import)
CALL sp_enroll_student(2, 101, 50, 1, 'A', '2027-08-01', 6);

-- 6. Teachers can now enter grades for new school year!
```

---

## Troubleshooting

### Issue: "Teacher not authorized to grade this subject"
**Cause:** Teacher not assigned in `subject_teacher_assignments`

**Fix:**
```sql
-- Check current assignments
SELECT * FROM v_teacher_grading_permissions WHERE teacher_id = ?;

-- Add missing assignment
INSERT INTO subject_teacher_assignments (school_year_id, teacher_id, subject_id, grade_level, section)
VALUES (1, ?, ?, ?, ?);
```

---

### Issue: "Cannot enter grades for inactive school year"
**Cause:** Trying to grade for archived school year

**Fix:**
```sql
-- Check active school year
SELECT * FROM school_years WHERE is_active = 1;

-- Activate correct school year
UPDATE school_years SET is_active = 0;  -- Deactivate all
UPDATE school_years SET is_active = 1, status = 'active' WHERE id = ?;
```

---

## Next Steps

1. Review and run migration scripts
2. Update PHP application code (see IMPLEMENTATION_GUIDE.md)
3. Test with sample data
4. Train users on new workflow
5. Deploy to production

---

## Support

For questions or issues, check:
- `docs/DATA_AND_PROCESS_MODELLING.md`
- Database schema diagrams
- Application logs in `change_logs` table
