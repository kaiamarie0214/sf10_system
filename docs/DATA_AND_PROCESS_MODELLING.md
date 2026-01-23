# Data and Process Modelling

This document describes the overall data and process model for the SF10 Learner Record Management System. It contains a Context Diagram, an Entity Relationship Diagram (ERD), and Data Flow Diagrams (DFD) Level 0 and Level 1. Diagrams are provided as Mermaid blocks for easy rendering.

---

**Context Diagram**

The system interacts with external actors: Teachers, Admins, Registrar/Staff, and External Export (Printer/PDF).

```mermaid
flowchart LR
  Teacher[Teacher] -->|Login / Enter Grades / View Class| SYS["SF10 System"]
  Admin[Admin] -->|Login / Manage Subjects / Run Reports / Configure Locks| SYS
  Registrar[Registrar / Staff] -->|Manage Students & Enrollments / Generate SF10| SYS
  Export[Printer / PDF] <-->|Export SF10 / Reports| SYS
  SYS -->|Reads / Writes| DB[(Database)]
```

Notes: the SF10 System is the central application which authenticates users, enforces permissions and orchestrates data flows to/from the Database and export targets.

---

**Entity Relationship Diagram (ERD)**

Main entities and relationships (simplified). Use a Mermaid ER diagram viewer to render.

```mermaid
flowchart TB
  users["USERS\nid PK\nusername\nfull_name\nrole"]
  students["STUDENTS\nid PK\nlrn\nlast_name\nfirst_name"]
  schools["SCHOOLS_ATTENDED\nid PK\nstudent_id FK\ngrade_level\nsection\nschool_year\nis_transfer"]
  subjects["SUBJECTS\nid PK\nsubject_name"]
  sgg["SUBJECT_GRADE_GROUPS\nid PK\ngrade_level\nsubject_id FK\nsubject_name"]
  ta["TEACHER_ASSIGNMENTS\nid PK\nteacher_id FK\nassignment_type\nsubject_id\ngrade_level\nsection"]
  grades["GRADES\nid PK\nstudent_id FK\nschool_attended_id FK\nsubject_id FK\nquarter\ngrade\nfinal_rating"]
  remedial["REMEDIAL_CLASSES\nid PK\nstudent_id FK\nlearning_area\nfinal_rating"]
  custom["STUDENT_CUSTOM_SUBJECTS\nid PK\nstudent_id FK\nschool_attended_id FK\nsubject_id FK\ncustom_subject_name"]
  qlocks["QUARTER_LOCKS\nid PK\nschool_attended_id FK NULL\nquarter\nlocked"]
  changelogs["CHANGE_LOGS\nid PK\nuser_id FK\naction\ntable_name\ndetails"]

  users -->|assigns| ta
  users -->|enters| grades
  users -->|creates| changelogs

  students -->|enrolled in| schools
  students -->|has grades| grades
  students -->|may have| remedial
  students -->|may have| custom

  schools -->|context for| grades
  schools -->|scope for| custom
  schools -->|may have| qlocks

  subjects -->|overrides| sgg
  subjects -->|are graded in| grades

  sgg --- subjects
  ta --- subjects

  grades --- subjects
  grades --- students

  changelogs --- users
```

Notes: Primary keys and foreign-key relationships are shown. Key tables used by application code include `users`, `students`, `schools_attended`, `subjects`, `grades`, and audit `change_logs`. The DB schema file is included in the repository (for example `sf10_system(6).sql`).

---

**Data Flow Diagram (DFD) — Level 0 (Top-level)**

```mermaid
flowchart TD
  Teacher[Teacher] -->|Login / Enter grades / Request SF10| P1["SF10 System"]
  Admin[Admin] -->|Manage subjects / Configure locks / Reports| P1
  Registrar[Registrar] -->|Manage students / Enrollments| P1
  P1 -->|Read/Write| DS[(Database)]
  P1 -->|Generate / Export| Export[(PDF / Printer)]
```

Notes: Level 0 shows system as a single process interacting with actors and the Database and export destinations.

---

**Data Flow Diagram (DFD) — Level 1 (Detailed)**

```mermaid
%%{init: {"themeVariables": {"fontSize":"22px","baseFontSize":"22px"}}}%%
flowchart LR
  subgraph Auth[Authentication]
    direction TB
    A[Auth Service]
  end

  subgraph Services[Core Services]
    direction LR
    B[Student\nManagement]
    C[Subject\nManagement]
    D[Grade Entry &\nCalculation]
    E[Quarter\nLocking]
    F[SF10 Generation &\nExport]
    G[Audit\nLogging]
  end

  Teacher --> A
  Admin --> A
  Registrar --> A

  A --> B
  A --> C
  A --> D
  A --> E
  A --> F
  A --> G

  B -->|student records| DS_STUDENTS[(students)]
  B -->|enrollment| DS_SA[(schools_attended)]
  C -->|subject metadata| DS_SUB[(subjects)]
  C -->|grade overrides| DS_SGG[(subject_grade_groups)]
  D -->|grades| DS_GRADES[(grades)]
  D -->|remedial| DS_REM[(remedial_classes)]
  D -->|drafts| DS_DFT[(grade_drafts)]
  E -->|lock status| DS_QL[(quarter_locks)]
  F -->|read grades + student info| DS_GRADES
  G -->|write logs| DS_CL[(change_logs)]

  F -->|PDF/Excel| Export[(PDF / Printer)]

  classDef large fill:#ffffff,stroke:#333,stroke-width:1px,font-size:20px;
  class A,B,C,D,E,F,G,DS_STUDENTS,DS_SA,DS_SUB,DS_SGG,DS_GRADES,DS_REM,DS_DFT,DS_QL,DS_CL,Export large
```

Notes: Level 1 breaks the system into logical services: authentication, student management, subject/format configuration, grade entry and calculations (including General Average computation and remedial handling), quarter-locking enforcement (with auto-lock schedules), SF10 generation, and audit logging.

---

**System Flowchart**

The following top-down system flowchart shows actors, main processes, data stores and external export targets in a single system view.

```mermaid
%%{init: {"themeVariables": {"fontSize":"20px","baseFontSize":"20px"}}}%%
flowchart TB
  subgraph Actors[Actors]
    direction LR
    T(Teacher)
    A(Admin)
    R(Registrar / Staff)
  end

  T -->|Login / Enter grades / View| Auth[Auth Service]
  A -->|Manage subjects / Configure locks / Reports| Auth
  R -->|Manage students / Enrollments / Generate SF10| Auth

  subgraph SYS[SF10 System]
    direction TB
    Auth --> SM[Student Management]
    SM --> DB[(Database)]

    Auth --> SubM[Subject Management]
    SubM --> DB

    Auth --> GE[Grade Entry & Calculation]
    GE -->|validate| QA[Validation / GA Calculation / Remedial]
    QA --> DB
    GE --> DB

    Auth --> QL[Quarter Locking Service]
    QL --> DB

    Auth --> SF[SF10 Generation & Export]
    SF -->|reads| DB
    SF --> Export[(Printer / PDF / Excel)]

    Auth --> AL[Audit Logging]
    AL --> DB
  end

  Export -.->|optional archive| DB

  classDef sysnode fill:#f8f9fb,stroke:#2b7cff,stroke-width:1px,font-size:18px;
  class Auth,SM,SubM,GE,QA,QL,SF,AL,DB sysnode
```

---

Choose whether you want this exported as PNG/SVG, added as a separate `.mmd` file, or further style tweaks.

---

**Program Flowchart**

This flowchart describes the main program-level flows: authentication, page routing, the Grades page save/draft flow (AJAX + server validation + DB upsert), and SF10 generation.

```mermaid
%%{init: {"themeVariables": {"fontSize":"24px","baseFontSize":"24px"}}}%%
flowchart TB
  subgraph Actor[User]
    direction LR
    U(User)
  end

  U -->|Login| LoginPage["<b>Login Page</b><br/>(login.php)"]
  LoginPage -->|POST creds| Auth["<b>Auth Service</b><br/>(password_verify)"]
  Auth -->|ok| Session["<b>Start Session</b><br/>(set $_SESSION)"]
  Auth -->|fail| LoginFail["<b>Login Error</b>"]

  Session --> Dashboard["<b>Dashboard / Nav</b>"]

  Dashboard --> GradesPage["<b>Grades Page</b><br/>(pages/grades.php)"]
  GradesPage --> LoadStudents["<b>Load Class / Students</b><br/>(AJAX)"]
  GradesPage --> LoadSubjects["<b>Load Subjects & Overrides</b>"]

  GradesPage --> EnterGrades["<b>Enter / Modify Grades</b>"]
  EnterGrades --> ClientValidate["<b>Client Validation</b>"]
  ClientValidate -->|ok| AjaxSave["<b>AJAX: Save Grades</b>"]
  ClientValidate -->|errors| ShowErrors["<b>Show Errors</b>"]

  AjaxSave --> ServerAuthCheck["<b>Server: Auth Check</b>"]
  ServerAuthCheck --> QuarterLockCheck["<b>Quarter Lock Check</b>"]
  QuarterLockCheck -->|locked| RejectSave["<b>Reject: Locked</b>"]
  QuarterLockCheck -->|open| ServerValidate["<b>Server Validation</b>"]

  ServerValidate --> CalcGA["<b>Compute GA & Remedial</b>"]
  CalcGA --> DBWrite["<b>DB Write: INSERT/UPDATE</b>"]
  DBWrite --> LogChange["<b>Write Audit Log</b>"]
  LogChange --> AjaxResponse["<b>Response: Success</b>"]
  AjaxResponse --> GradesPage

  GradesPage --> SaveDraft["<b>Save Draft</b>"]
  SaveDraft --> DraftWrite["<b>DB: grade_drafts</b>"]

  Dashboard --> Reports["<b>Reports / SF10</b>"]
  Reports --> SF10Page["<b>SF10 Generation</b>"]
  SF10Page --> ReadDB["<b>Read DB: grades + students</b>"]
  ReadDB --> RenderPDF["<b>Render PDF / Excel</b>"]
  RenderPDF --> ExportOut["<b>Printer / Download</b>"]

  classDef boldNode fill:#ffffff,stroke:#2b7cff,stroke-width:1px,font-size:20px,font-weight:bold;
  class LoginPage,Auth,Session,Dashboard,GradesPage,LoadStudents,LoadSubjects,EnterGrades,ClientValidate,AjaxSave,ServerAuthCheck,QuarterLockCheck,ServerValidate,CalcGA,DBWrite,LogChange,AjaxResponse,SaveDraft,DraftWrite,Reports,SF10Page,ReadDB,RenderPDF,ExportOut boldNode
```

Notes: keep sensitive checks (quarter locks, auth, server validation) on server; client validation is UX-only.


**Data Stores (mapping to DB tables)**
- `users` — authentication and user profile
- `students` — student master data
- `schools_attended` — enrollment records; `is_transfer` and `active` flags
- `subjects` — canonical subjects
- `subject_grade_groups` — per-grade subject display overrides
- `student_custom_subjects` — per-transfer-student subject overrides
- `grades` — per-quarter grades, final ratings, GA rows
- `remedial_classes` — remedial session records
- `quarter_locks`, `quarter_auto_locks`, `quarter_auto_unlocks` — lock status and schedules
- `change_logs` — audit trail
- `grade_drafts` — draft storage (referenced in code; ensure it exists in DB)

---

Assumptions, issues & next steps
- The application contains business rules implemented in code (examples: skip lists for GA calculation, transfer-student overrides). Consider moving those rules to DB configuration to make them editable.
- Ensure `grade_drafts` exists in the runtime DB schema; the SQL dump used to build these docs may be missing it.
- If you want these diagrams exported as PNG/SVG, use a Mermaid renderer (e.g., mermaid.live or the VS Code Mermaid preview). I can also add separate `.mmd` files or commit rendered images to `docs/` if you want.

---

How to render
- Open this file in a Markdown renderer that supports Mermaid (VS Code + Mermaid Preview, GitHub pages with mermaid plugin, or mermaid.live).

---

If you want, I can now:
- Add these diagrams as separate Mermaid files (`docs/*.mmd`) and commit them.
- Generate PNG/SVG exports and add them to `docs/`.
- Produce a short `README.md` that links to this document and the DB schema file `sf10_system(6).sql`.

Choose one and I'll continue.
