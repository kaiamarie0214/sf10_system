# Quarter Lock Implementation - School Year Specific

## Changes Made

### 1. Database Migration
- **File**: `database_updates/add_school_year_to_quarter_locks.sql`
- **File**: `database_updates/run_migration_quarter_locks.php`
- Added `school_year` column to:
  - `quarter_locks` table
  - `quarter_auto_locks` table  
  - `quarter_auto_unlocks` table

### 2. Updated Files

#### input_grades_form.php (Teacher Grade Input)
- **AJAX Save Handler** (lines 54-84):
  - Fixed incorrect column names (was using `q1_locked`, `q2_locked` - now uses `locked`)
  - Added school_year filtering to check locks
  - Now checks both student-specific and global locks for the current school year
  
- **Display Quarter Locks** (lines 193-256):
  - Updated to filter locks by school_year
  - Handles both global locks (school_attended_id IS NULL) and student-specific locks
  - Prioritizes student-specific locks over global locks

#### manage_quarter_locks.php (Admin Quarter Lock Management)
- Added active school year display
- Updated all queries to filter by school_year
- Changed warning message to clarify school-year-specific behavior

#### grades.php (Admin Grades Page)
- **toggle_quarter_lock AJAX** (lines 570-610):
  - Now gets active school year before locking/unlocking
  - Saves locks with school_year value
  - Log messages now include school year

## How It Works

### School Year Isolation
- Each school year has its own set of quarter locks
- Locking Q1 in "2025-2026" does NOT lock Q1 in "2024-2025"
- Locks are tied to the active school year

### Lock Hierarchy
1. **Student-specific locks** (school_attended_id set, school_year set)
2. **School-year global locks** (school_attended_id NULL, school_year set)
3. **Truly global locks** (school_attended_id NULL, school_year NULL)

### Example Scenarios

**Scenario 1**: Lock Q1 for School Year 2025-2026
- Teachers can still edit Q1 grades for 2024-2025 students
- Teachers CANNOT edit Q1 grades for 2025-2026 students
- Other quarters remain unlocked

**Scenario 2**: Different locks per school year
- 2024-2025: Q1, Q2, Q3, Q4 all locked (school year ended)
- 2025-2026: Only Q1 locked (currently in Q2)
- 2026-2027: All unlocked (hasn't started yet)

## Migration Steps

### To Apply Changes:
1. **Run the migration** (as admin):
   - Visit: `http://localhost/sf10_system/database_updates/run_migration_quarter_locks.php`
   - This adds the school_year column to the tables

2. **Verify**:
   - Go to "Manage Quarter Locks" page
   - Should see current school year in subtitle
   - Lock/unlock a quarter - should only affect current school year

3. **Test**:
   - As teacher, try inputting grades in the current school year
   - Lock a quarter in manage_quarter_locks.php
   - Verify locked quarters show gray background and are readonly
   - Change school year and verify locks are different

## Technical Details

### Database Schema Changes
```sql
ALTER TABLE quarter_locks 
ADD COLUMN school_year VARCHAR(20) DEFAULT NULL;

ALTER TABLE quarter_auto_locks 
ADD COLUMN school_year VARCHAR(20) DEFAULT NULL;

ALTER TABLE quarter_auto_unlocks 
ADD COLUMN school_year VARCHAR(20) DEFAULT NULL;
```

### Query Examples

**Check if quarter is locked:**
```sql
SELECT locked FROM quarter_locks 
WHERE quarter = 1 
AND (school_attended_id IS NULL OR school_attended_id = 123)
AND (school_year = '2025-2026' OR school_year IS NULL)
ORDER BY school_attended_id DESC, school_year DESC
LIMIT 1;
```

**Set global lock for current school year:**
```sql
INSERT INTO quarter_locks (school_attended_id, quarter, locked, school_year) 
VALUES (NULL, 1, 1, '2025-2026');
```

## Benefits
✓ Historical data protection - old school year locks remain in place
✓ Flexible management - each school year managed independently  
✓ No cross-contamination - changes in one year don't affect others
✓ Maintains existing functionality - global locks (NULL school_year) still work
