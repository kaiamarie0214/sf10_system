# School Year System - Quick Reference

## 🎯 System Overview

```
┌─────────────────────────────────────────────────────────────┐
│  LOGIN PAGE - Select School Year                            │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  School Year:  [2025-2026 (Current) ▼]             │   │
│  │  Username:     [_______________]                     │   │
│  │  Password:     [_______________]                     │   │
│  │  [Login]                                             │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
                        ↓
        ┌───────────────┴───────────────┐
        │                               │
    ADMIN                           TEACHER
        │                               │
        ↓                               ↓
┌─────────────────┐           ┌──────────────────┐
│ Create School   │           │ See Assignments  │
│ Year 2026-2027  │           │ for Selected     │
│                 │           │ School Year:     │
│ Setup Classes:  │           │                  │
│ • Grade 1-A     │           │ • Math - Gr 1-A  │
│ • Grade 1-B     │           │ • Math - Gr 1-B  │
│ • Grade 2-A     │           │ • Sci - Gr 2-A   │
│                 │           │                  │
│ Assign Teachers │           │ [Enter Grades]   │
│ to Subjects     │           │                  │
└─────────────────┘           └──────────────────┘
```

---

## 📋 Quick Workflow

### Admin: Create New School Year

```
1. Login as admin
   ↓
2. Go to "School Year Management"
   ↓
3. Click "Create New School Year"
   ↓
   Year: 2026-2027
   Start: 2026-08-01
   End: 2027-05-31
   Make Default: ✓ Yes
   ↓
4. Click "Create"
   ↓
5. Setup classes for new year
   ↓
6. Assign teachers to subjects
   ↓
7. Enroll students
   ↓
DONE! ✓
```

---

### Admin: Assign Teacher to Multiple Subjects

```
1. Go to "Teacher Subject Assignments"
   ↓
2. Click teacher "John Doe"
   ↓
3. Select school year "2025-2026"
   ↓
4. Click "Add Assignment" (repeat as needed)
   
   Assignment 1:
   Subject: Mathematics
   Grade: 1
   Section: A
   
   Assignment 2:
   Subject: Mathematics
   Grade: 1
   Section: B
   
   Assignment 3:
   Subject: Science
   Grade: 2
   Section: A
   ↓
5. Click "Save All Assignments"
   ↓
DONE! ✓ Teacher John can now grade Math for Grade 1-A, 1-B and Science for Grade 2-A
```

---

### Teacher: Login and Enter Grades

```
1. Go to login page
   ↓
2. Select school year from dropdown
   School Year: [2025-2026 (Current)]
   ↓
3. Enter username and password
   ↓
4. Click Login
   ↓
5. Dashboard shows:
   
   My Subject Assignments (2025-2026):
   • Mathematics - Grade 1-A    [Enter Grades]
   • Mathematics - Grade 1-B    [Enter Grades]
   • Science - Grade 2-A        [Enter Grades]
   ↓
6. Click "Enter Grades" for desired class
   ↓
7. Enter/update grades
   ↓
DONE! ✓
```

---

## 🔑 Key Database Tables

### school_years
```
id | year      | start_date | end_date   | is_active | status
---|-----------|------------|------------|-----------|----------
1  | 2024-2025 | 2024-08-01 | 2025-05-31 | 0         | archived
2  | 2025-2026 | 2025-08-01 | 2026-05-31 | 1         | active ← Default
3  | 2026-2027 | 2026-08-01 | 2027-05-31 | 0         | upcoming
```

### classes_per_year
```
id | school_year_id | grade_level | section | adviser_id | capacity
---|----------------|-------------|---------|------------|----------
1  | 2              | 1           | A       | 10         | 40
2  | 2              | 1           | B       | 11         | 40
3  | 2              | 2           | A       | 12         | 40
```

### subject_teacher_assignments
```
id | school_year_id | teacher_id | subject_id | grade | section
---|----------------|------------|------------|-------|--------
1  | 2              | 15         | 4 (Math)   | 1     | A
2  | 2              | 15         | 4 (Math)   | 1     | B
3  | 2              | 15         | 5 (Sci)    | 2     | A
4  | 2              | 16         | 3 (Eng)    | 1     | A
```

**Result:** 
- Teacher 15 can grade Math for Grade 1-A, 1-B and Science for Grade 2-A
- Teacher 16 can grade English for Grade 1-A

### school_year_enrollments
```
id | school_year_id | student_id | grade_level | section | status
---|----------------|------------|-------------|---------|----------
1  | 2              | 101        | 1           | A       | enrolled
2  | 2              | 102        | 1           | A       | enrolled
3  | 2              | 103        | 1           | B       | enrolled
```

---

## 🎨 UI Components

### Login Page - School Year Selector
```html
┌─────────────────────────────────────────┐
│ [📅] School Year                        │
│ ┌─────────────────────────────────────┐ │
│ │ 2025-2026 (Current)               ▼ │ │
│ └─────────────────────────────────────┘ │
│ Options:                                │
│ • 2025-2026 (Current) ← Selected        │
│ • 2024-2025                             │
│ • 2026-2027                             │
└─────────────────────────────────────────┘
```

### Teacher Assignment Page
```
┌────────────────────┬──────────────────────────────────┐
│ Teachers           │ Assignments for: John Doe        │
├────────────────────┼──────────────────────────────────┤
│ • John Doe    ◄────┤ School Year: [2025-2026 ▼]      │
│ • Jane Smith       │                                  │
│ • Bob Wilson       │ Current Assignments:             │
│                    │ ┌────────────────────────────┐   │
│                    │ │ Math → Grade 1 → A    [×]  │   │
│                    │ └────────────────────────────┘   │
│                    │ ┌────────────────────────────┐   │
│                    │ │ Math → Grade 1 → B    [×]  │   │
│                    │ └────────────────────────────┘   │
│                    │ ┌────────────────────────────┐   │
│                    │ │ Science → Grade 2 → A [×]  │   │
│                    │ └────────────────────────────┘   │
│                    │                                  │
│                    │ [+ Add Assignment]               │
│                    │ [Save All Assignments]           │
└────────────────────┴──────────────────────────────────┘
```

### Teacher Dashboard
```
┌──────────────────────────────────────────────────┐
│ Welcome, John Doe                                │
│ School Year: 2025-2026                           │
├──────────────────────────────────────────────────┤
│ My Subject Assignments                           │
│                                                  │
│ Subject       | Class          | Action          │
│ ─────────────┼────────────────┼─────────────── │
│ Mathematics   | Grade 1-A      | [Enter Grades] │
│ Mathematics   | Grade 1-B      | [Enter Grades] │
│ Science       | Grade 2-A      | [Enter Grades] │
└──────────────────────────────────────────────────┘
```

---

## ✅ Permission System

### Who Can Do What?

| Action                          | Admin | Teacher | Notes                                    |
|---------------------------------|-------|---------|------------------------------------------|
| Create school year              | ✓     | ✗       | Admin only                               |
| Setup class sections            | ✓     | ✗       | Admin only                               |
| Assign teachers to subjects     | ✓     | ✗       | Admin only                               |
| Select school year on login     | ✓     | ✓       | Both can select                          |
| View assigned classes           | ✓     | ✓       | Teachers see only their assignments      |
| Enter grades                    | ✓     | ✓       | Teachers: only assigned subjects         |
| View all students               | ✓     | ✗       | Teachers: only their class if adviser    |

### Grade Entry Validation

```
Teacher tries to enter grade
         ↓
Is teacher assigned to this subject + class?
         ↓
    ┌────┴────┐
   YES       NO
    │         │
    ↓         ↓
 ALLOW    DENY ✗
 GRADE    "Not authorized"
 ENTRY
```

**Validation Query:**
```sql
SELECT fn_can_teacher_grade_subject(
    teacher_id,
    subject_id,
    grade_level,
    section,
    school_year_id
) = 1
```

---

## 📊 Example Use Cases

### Use Case 1: Multi-Subject Teacher
**Scenario:** Teacher John teaches Math to two sections and Science to one section

**Setup:**
```sql
INSERT INTO subject_teacher_assignments 
(school_year_id, teacher_id, subject_id, grade_level, section)
VALUES 
(2, 15, 4, 1, 'A'),  -- Math, Grade 1-A
(2, 15, 4, 1, 'B'),  -- Math, Grade 1-B
(2, 15, 5, 2, 'A');  -- Science, Grade 2-A
```

**Result:**
- John sees 3 "Enter Grades" buttons on dashboard
- Can grade Math for 1-A students
- Can grade Math for 1-B students
- Can grade Science for 2-A students
- CANNOT grade any other subject/class

---

### Use Case 2: Adviser + Subject Teacher
**Scenario:** Teacher Mary is adviser for Grade 3-A AND teaches Filipino to the same class

**Setup:**
```sql
-- Set as adviser
UPDATE classes_per_year 
SET adviser_id = 20 
WHERE school_year_id = 2 AND grade_level = 3 AND section = 'A';

-- Assign as Filipino teacher
INSERT INTO subject_teacher_assignments 
(school_year_id, teacher_id, subject_id, grade_level, section)
VALUES (2, 20, 2, 3, 'A');  -- Filipino, Grade 3-A
```

**Result:**
- Mary can VIEW all students in Grade 3-A (as adviser)
- Mary can GRADE Filipino for Grade 3-A students (as subject teacher)
- Mary CANNOT grade other subjects for 3-A
- Dashboard shows:
  ```
  My Class: Grade 3-A (Adviser)
  Students: 35 / 40
  
  My Subject Assignments:
  • Filipino - Grade 3-A [Enter Grades]
  ```

---

### Use Case 3: Previous School Year Access
**Scenario:** Admin needs to review/edit data from last year

**Workflow:**
1. Logout (if logged in)
2. Login page → Select "2024-2025"
3. Login
4. Access to 2024-2025 data only
5. View/edit students, grades from that year
6. To switch back: Logout → Select "2025-2026" → Login

---

## 🚀 Getting Started Checklist

### Initial Setup
- [ ] Run database migration scripts
- [ ] Test login page with school year selector
- [ ] Create first school year (e.g., 2025-2026)
- [ ] Set it as default (is_active = 1)
- [ ] Create class sections for the school year
- [ ] Create teacher user accounts

### Configure Teachers
- [ ] Go to "Teacher Subject Assignments" page
- [ ] For each teacher:
  - [ ] Select teacher
  - [ ] Select school year
  - [ ] Add subject assignments
  - [ ] Save

### Test with Teacher Account
- [ ] Logout from admin
- [ ] Login as teacher
- [ ] Select school year
- [ ] Verify subject assignments shown
- [ ] Test entering grades
- [ ] Verify cannot grade unauthorized subjects

### Production Launch
- [ ] Train admin staff
- [ ] Train teachers
- [ ] Distribute login instructions
- [ ] Monitor for issues
- [ ] Collect feedback

---

## 📞 Support

**Common Questions:**

**Q: Can a teacher access multiple school years?**
A: Yes! Teachers select which school year on login. They can logout and login with a different year.

**Q: How many subjects can a teacher be assigned?**
A: Unlimited! A teacher can teach multiple subjects across multiple grades and sections.

**Q: What happens to old data when creating a new school year?**
A: Old data is preserved! Each school year is completely isolated. Old years can be archived.

**Q: Can I change a teacher's assignments mid-year?**
A: Yes! Admin can add/remove assignments anytime via the Teacher Subject Assignments page.

**Q: What if no school year is created?**
A: Login page will show a warning: "No School Year Available - Contact administrator"

---

## 🔗 Related Files

- **Login:** `login_with_school_year.php`
- **Teacher Assignments:** `pages/teacher_subject_assignments.php`
- **School Year Mgmt:** `pages/school_year_management.php`
- **Database Schema:** `database_updates/school_year_reimplementation.sql`
- **Full Guide:** `docs/SCHOOL_YEAR_SYSTEM_GUIDE.md`
- **Implementation:** `docs/IMPLEMENTATION_GUIDE.md`

---

**Last Updated:** February 6, 2026
