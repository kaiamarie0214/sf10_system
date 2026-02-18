# School Year System - Architecture Diagrams

## System Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                         LOGIN PROCESS                                │
└─────────────────────────────────────────────────────────────────────┘

User visits login page
         │
         ├─── Load available school years (not archived)
         │
         ├─── Display school year dropdown
         │    ├─── 2024-2025 (Archived) ← Hidden
         │    ├─── 2025-2026 (Current)  ← Pre-selected
         │    └─── 2026-2027 (Upcoming)
         │
         ├─── User selects school year: 2025-2026
         │
         ├─── User enters credentials
         │
         └─── Submit login
                 │
                 ├─── Validate username/password
                 │
                 ├─── Validate school year selection
                 │
                 ├─── Set session variables:
                 │    ├─── user_id
                 │    ├─── role (admin/teacher)
                 │    ├─── school_year_id ← SELECTED YEAR
                 │    ├─── school_year (e.g., "2025-2026")
                 │    └─── (for teachers) subject_assignments
                 │
                 └─── Redirect to dashboard
                         │
                         ├─── ADMIN DASHBOARD
                         │    └─── Can manage selected school year
                         │
                         └─── TEACHER DASHBOARD
                              └─── See assignments for selected year
```

---

## Data Isolation Architecture

```
┌──────────────────────────────────────────────────────────────────────┐
│                     SCHOOL YEAR ISOLATION                             │
└──────────────────────────────────────────────────────────────────────┘

    SCHOOL YEAR 2024-2025           SCHOOL YEAR 2025-2026
            (Archived)                    (Current)
    ┌─────────────────────┐         ┌─────────────────────┐
    │                     │         │                     │
    │  Students: 150      │         │  Students: 180      │
    │  Classes: 6         │         │  Classes: 7         │
    │  Grades: 2,400      │         │  Grades: 1,200      │
    │  Teachers: 12       │         │  Teachers: 15       │
    │                     │         │                     │
    │  Completely         │         │  Active & Being     │
    │  Separate Data      │         │  Modified           │
    │                     │         │                     │
    └─────────────────────┘         └─────────────────────┘
             │                               │
             │                               │
             └───────────┬───────────────────┘
                         │
                    User Selects
                    At Login Time
                         │
                    ┌────┴─────┐
                    │ Session  │
                    │ school_  │
                    │ year_id  │
                    └──────────┘
```

---

## Teacher Assignment Structure

```
┌──────────────────────────────────────────────────────────────────────┐
│              TEACHER JOHN'S ASSIGNMENTS (2025-2026)                   │
└──────────────────────────────────────────────────────────────────────┘

Teacher: John Doe (ID: 15)
School Year: 2025-2026

Assignment 1                 Assignment 2                 Assignment 3
┌─────────────────┐         ┌─────────────────┐         ┌─────────────────┐
│ Mathematics     │         │ Mathematics     │         │ Science         │
│ Grade 1         │         │ Grade 1         │         │ Grade 2         │
│ Section A       │         │ Section B       │         │ Section A       │
│                 │         │                 │         │                 │
│ Students: 35    │         │ Students: 38    │         │ Students: 40    │
│                 │         │                 │         │                 │
│ [Enter Grades]  │         │ [Enter Grades]  │         │ [Enter Grades]  │
└─────────────────┘         └─────────────────┘         └─────────────────┘

CAN grade:                  CANNOT grade:
✓ Math for 1-A             ✗ English for any class
✓ Math for 1-B             ✗ Filipino for any class
✓ Science for 2-A          ✗ Any subject for other sections
                           ✗ Any subject for other grades
```

---

## Database Relationships

```
┌─────────────────────────────────────────────────────────────────────────┐
│                     CORE TABLE RELATIONSHIPS                             │
└─────────────────────────────────────────────────────────────────────────┘

                        school_years
                        ┌──────────────────┐
                        │ id (PK)          │
                        │ year             │
                        │ is_active        │
                        │ status           │
                        └────────┬─────────┘
                                 │
                    ┌────────────┼────────────────┐
                    │            │                │
                    ▼            ▼                ▼
        ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐
        │ classes_per_ │  │ school_year_ │  │ subject_teacher_ │
        │ year         │  │ enrollments  │  │ assignments      │
        ├──────────────┤  ├──────────────┤  ├──────────────────┤
        │ school_year_id  │ school_year_id  │ school_year_id   │
        │ grade_level  │  │ student_id   │  │ teacher_id       │
        │ section      │  │ grade_level  │  │ subject_id       │
        │ adviser_id   │  │ section      │  │ grade_level      │
        └──────────────┘  └──────┬───────┘  │ section          │
                                 │           └─────────┬────────┘
                                 │                     │
                                 ▼                     │
                          ┌────────────┐              │
                          │ students   │              │
                          ├────────────┤              │
                          │ id (PK)    │              │
                          │ lrn        │              │
                          │ name       │              │
                          └────────────┘              │
                                                      │
                                 ┌────────────────────┘
                                 │
                                 ▼
                          ┌────────────┐
                          │ grades     │
                          ├────────────┤
                          │ school_year_id
                          │ student_id │
                          │ subject_id │
                          │ teacher_id │◄── Must match assignment
                          │ quarter    │
                          │ grade      │
                          └────────────┘
```

---

## Permission Validation Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│              TEACHER GRADE ENTRY VALIDATION                              │
└─────────────────────────────────────────────────────────────────────────┘

Teacher clicks "Enter Grades" for Student 101, Subject Math, Grade 1-A
                            │
                            ▼
            ┌───────────────────────────────┐
            │ 1. Get student's enrollment   │
            │    What grade/section?        │
            │    Grade 1, Section A         │
            └───────────┬───────────────────┘
                        │
                        ▼
            ┌───────────────────────────────┐
            │ 2. Check subject assignment   │
            │    fn_can_teacher_grade()     │
            └───────────┬───────────────────┘
                        │
                        ▼
            ┌───────────────────────────────┐
            │ Query: subject_teacher_       │
            │        assignments            │
            │                               │
            │ WHERE teacher_id = 15         │
            │   AND subject_id = 4 (Math)   │
            │   AND grade_level = 1         │
            │   AND section = 'A'           │
            │   AND school_year_id = 2      │
            └───────────┬───────────────────┘
                        │
                ┌───────┴────────┐
                │                │
            Found?           Not Found?
                │                │
                ▼                ▼
        ┌────────────┐    ┌──────────────┐
        │ ALLOW      │    │ DENY         │
        │ Insert     │    │ Error:       │
        │ Grade      │    │ "Not         │
        │            │    │ authorized"  │
        └────────────┘    └──────────────┘
```

---

## Admin Workflow: Create New School Year

```
┌─────────────────────────────────────────────────────────────────────────┐
│          ADMIN: SETUP NEW SCHOOL YEAR WORKFLOW                           │
└─────────────────────────────────────────────────────────────────────────┘

STEP 1: Create School Year
┌────────────────────────────────┐
│ School Year Management         │
│ [+ Create New School Year]     │
└────────────────────────────────┘
         │
         ▼
┌────────────────────────────────┐
│ Year: 2026-2027                │
│ Start: 2026-08-01              │
│ End: 2027-05-31                │
│ Make Default: ☐ Yes            │
│ [Create]                       │
└────────────────────────────────┘
         │
         ▼
School Year Created (ID: 3)

─────────────────────────────────

STEP 2: Create Class Sections
┌────────────────────────────────┐
│ Classes for 2026-2027          │
│ ┌────────────────────────────┐ │
│ │ Grade 1 - A  [Create]      │ │
│ │ Grade 1 - B  [Create]      │ │
│ │ Grade 2 - A  [Create]      │ │
│ └────────────────────────────┘ │
└────────────────────────────────┘
         │
         ▼
Classes Created

─────────────────────────────────

STEP 3: Assign Class Advisers
┌────────────────────────────────┐
│ Grade 1-A                      │
│ Adviser: [Teacher John ▼]      │
│ [Save]                         │
└────────────────────────────────┘
         │
         ▼
Advisers Assigned

─────────────────────────────────

STEP 4: Assign Subject Teachers
┌────────────────────────────────┐
│ Teacher Subject Assignments    │
│ Teacher: John Doe              │
│ School Year: 2026-2027         │
│                                │
│ [+ Add Assignment]             │
│ Math → Grade 1 → A             │
│ Math → Grade 1 → B             │
│ [Save All]                     │
└────────────────────────────────┘
         │
         ▼
Subject Teachers Assigned

─────────────────────────────────

STEP 5: Enroll Students
┌────────────────────────────────┐
│ Student Enrollment             │
│ School Year: 2026-2027         │
│ Student: Juan Dela Cruz        │
│ Grade: 1   Section: A          │
│ [Enroll]                       │
└────────────────────────────────┘
         │
         ▼
Students Enrolled

─────────────────────────────────

✓ READY FOR NEW SCHOOL YEAR!
```

---

## Session Storage Structure

```
┌─────────────────────────────────────────────────────────────────────────┐
│                  PHP SESSION AFTER LOGIN                                 │
└─────────────────────────────────────────────────────────────────────────┘

$_SESSION = [
    // User Info
    'user_id' => 15,
    'username' => 'john.doe',
    'role' => 'teacher',
    'full_name' => 'John Doe',
    
    // Selected School Year Info
    'school_year_id' => 2,                    ← KEY: Selected at login
    'school_year' => '2025-2026',
    'school_year_status' => 'active',
    
    // Teacher-Specific (if role == teacher)
    'subject_assignments' => [
        [
            'id' => 1,
            'subject_id' => 4,
            'subject_name' => 'Mathematics',
            'grade_level' => 1,
            'section' => 'A',
            'class_display' => 'Grade 1-A'
        ],
        [
            'id' => 2,
            'subject_id' => 4,
            'subject_name' => 'Mathematics',
            'grade_level' => 1,
            'section' => 'B',
            'class_display' => 'Grade 1-B'
        ],
        [
            'id' => 3,
            'subject_id' => 5,
            'subject_name' => 'Science',
            'grade_level' => 2,
            'section' => 'A',
            'class_display' => 'Grade 2-A'
        ]
    ],
    
    // Adviser Info (if applicable)
    'is_adviser' => true,
    'adviser_class' => [
        'id' => 10,
        'grade_level' => 1,
        'section' => 'A',
        'current_count' => 35,
        'capacity' => 40,
        'class_display' => 'Grade 1-A'
    ]
]

ALL DATABASE QUERIES USE: $_SESSION['school_year_id']
```

---

## Grade Entry Permission Matrix

```
┌─────────────────────────────────────────────────────────────────────────┐
│           TEACHER GRADING PERMISSION MATRIX                              │
└─────────────────────────────────────────────────────────────────────────┘

Teacher: John (ID: 15)
School Year: 2025-2026

Subject Assignments:
- Math → Grade 1-A
- Math → Grade 1-B
- Science → Grade 2-A

┌─────────┬───────┬───────────┬──────────┬────────────────┐
│ Subject │ Grade │ Section   │ Can Grade│ Reason         │
├─────────┼───────┼───────────┼──────────┼────────────────┤
│ Math    │ 1     │ A         │ ✓ YES    │ Assigned       │
│ Math    │ 1     │ B         │ ✓ YES    │ Assigned       │
│ Math    │ 1     │ C         │ ✗ NO     │ Not assigned   │
│ Math    │ 2     │ A         │ ✗ NO     │ Not assigned   │
│ Science │ 2     │ A         │ ✓ YES    │ Assigned       │
│ Science │ 2     │ B         │ ✗ NO     │ Not assigned   │
│ English │ 1     │ A         │ ✗ NO     │ Not assigned   │
│ Filipino│ 1     │ A         │ ✗ NO     │ Not assigned   │
└─────────┴───────┴───────────┴──────────┴────────────────┘

Validation Query:
SELECT fn_can_teacher_grade_subject(15, subject_id, grade, section, 2)
Returns 1 (true) for ✓ YES, 0 (false) for ✗ NO
```

---

## School Year States

```
┌─────────────────────────────────────────────────────────────────────────┐
│                  SCHOOL YEAR LIFECYCLE                                   │
└─────────────────────────────────────────────────────────────────────────┘

┌──────────────┐
│  UPCOMING    │  Future school year being set up
│ is_active=0  │  • Visible on login dropdown
│ status=      │  • Admin can configure
│ 'upcoming'   │  • Teachers can view but not grade
└──────┬───────┘
       │
       │ Admin activates OR start_date arrives
       ▼
┌──────────────┐
│  ACTIVE      │  Currently running school year
│ is_active=1  │  • Default on login dropdown
│ status=      │  • Admin can manage
│ 'active'     │  • Teachers can grade
└──────┬───────┘
       │
       │ School year ends OR admin archives
       ▼
┌──────────────┐
│  ARCHIVED    │  Past school year
│ is_active=0  │  • Hidden from login dropdown
│ status=      │  • Read-only access
│ 'archived'   │  • Historical data preserved
└──────────────┘

LOGIN DROPDOWN SHOWS:
• Upcoming (if any)
• Active (default selected)
• Previous active (if not archived)

DOES NOT SHOW:
• Archived years
```

---

## Complete System Map

```
┌─────────────────────────────────────────────────────────────────────────┐
│                    SF10 SCHOOL YEAR SYSTEM MAP                           │
└─────────────────────────────────────────────────────────────────────────┘

                           ┌──────────────┐
                           │  LOGIN PAGE  │
                           │ + Select SY  │
                           └──────┬───────┘
                                  │
                        ┌─────────┴─────────┐
                        │                   │
                   ┌────▼────┐         ┌───▼────┐
                   │  ADMIN  │         │TEACHER │
                   └────┬────┘         └───┬────┘
                        │                  │
        ┌───────────────┼──────────────┐   │
        │               │              │   │
        ▼               ▼              ▼   ▼
┌──────────────┐ ┌──────────┐ ┌────────────────┐
│School Year   │ │Teacher   │ │My Assignments  │
│Management    │ │Subject   │ │ • Math 1-A     │
│              │ │Assign.   │ │ • Math 1-B     │
│• Create SY   │ │          │ │ • Science 2-A  │
│• Activate    │ │Select    │ │                │
│• Archive     │ │Teacher   │ │[Enter Grades]  │
└──────────────┘ │          │ └────────┬───────┘
                 │Add       │          │
                 │Assign.   │          ▼
                 │          │ ┌─────────────────┐
                 │[Save]    │ │ Grade Entry     │
                 └──────────┘ │ Form            │
                              │                 │
                              │ Validates:      │
                              │ ✓ Teacher auth  │
                              │ ✓ School year   │
                              │ ✓ Student enr.  │
                              └─────────────────┘

All components use: $_SESSION['school_year_id']
```

---

These diagrams illustrate the complete architecture of your new school year system! 🎨
