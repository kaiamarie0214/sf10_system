# SF10 Management System

## Abstract

The **SF10 Management System** is a web-based application designed to streamline the management, recording, and generation of the **School Form 10 (SF10)**, also known as the Learner's Permanent Academic Record, for elementary education. This system addresses the inefficiencies of manual record-keeping by providing a centralized, secure, and automated platform for school administrators and teachers.

### Key Objectives
1.  **Digitalization**: Transition from paper-based records to a secure digital database.
2.  **Efficiency**: Automate the calculation of grades, general averages, and remarks (Passed/Failed).
3.  **Accuracy**: Minimize human error in grade computation and data entry.
4.  **Compliance**: Ensure alignment with the Department of Education (DepEd) standards for the K-12 curriculum.

### Core Modules
*   **Student Information Management**: Comprehensive profiling of students, including LRN, personal details, and eligibility for enrollment.
*   **Grade Management**:
    *   **Teacher Interface**: Allows subject teachers to input quarterly grades.
    *   **Admin Interface**: Provides oversight, grade modification capabilities, and locking mechanisms for quarterly grades.
    *   **Automated Computations**: System-calculated Final Ratings and General Averages.
*   **Subject Management**: Dynamic configuration of subjects per grade level, including custom subject support for transfer students.
*   **Report Generation**: Automatic generation of the SF10 form (Front and Back) in a printable format, ready for official use.
*   **Security & Access Control**: Role-based access control (RBAC) separating Administrator and Teacher privileges, with detailed activity logging for accountability.
*   **Data Integrity**: Features like "Quarter Locking" to prevent unauthorized changes after a grading period closes.

### Technical Implementation
The system is built using **PHP** and **MySQL** (MariaDB) on the **XAMPP** stack, ensuring broad compatibility and ease of deployment in local school environments. The frontend utilizes standard HTML/CSS/JavaScript for a responsive and user-friendly interface.

### Conclusion
By implementing the SF10 Management System, educational institutions can significantly reduce the administrative burden on teachers, ensure data integrity, and produce accurate, standardized academic records in a timely manner.
