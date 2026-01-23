<?php
/**
 * K-12 Subject Helper Functions
 * Functions to handle grade-appropriate subject retrieval and management
 */

/**
 * Get subjects applicable for a specific grade level
 * @param mysqli $conn Database connection
 * @param int $grade_level Grade level (1-6)
 * @param bool $include_mapeh_components Whether to include individual MAPEH components
 * @return array Array of subjects
 */
function getSubjectsByGrade($conn, $grade_level, $include_mapeh_components = true) {
    // Get configured subjects for this grade
    $query = "SELECT s.*, 
              COALESCE(sgc.is_active, 
                      CASE 
                          WHEN ? BETWEEN s.min_grade AND s.max_grade THEN 1 
                          ELSE 0 
                      END) as is_active
              FROM subjects s
              LEFT JOIN subject_grade_config sgc ON s.id = sgc.subject_id AND sgc.grade_level = ?
              WHERE s.subject_name != 'General Average'
              AND ? BETWEEN COALESCE(s.min_grade, 1) AND COALESCE(s.max_grade, 6)";
    
    if (!$include_mapeh_components) {
        $query .= " AND s.is_mapeh_component = 0";
    }
    
    $query .= " HAVING is_active = 1 ORDER BY s.display_order, s.subject_name";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iii", $grade_level, $grade_level, $grade_level);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $subjects = [];
    while ($row = $result->fetch_assoc()) {
        $subjects[] = $row;
    }
    
    return $subjects;
}

/**
 * Get all core subjects for K-12
 * @param mysqli $conn Database connection
 * @return array Array of core subjects
 */
function getCoreSubjects($conn) {
    $result = $conn->query("SELECT * FROM subjects 
                           WHERE is_core = 1 
                           AND subject_name != 'General Average' 
                           ORDER BY display_order");
    
    $subjects = [];
    while ($row = $result->fetch_assoc()) {
        $subjects[] = $row;
    }
    
    return $subjects;
}

/**
 * Check if a subject is applicable for a grade level
 * @param mysqli $conn Database connection
 * @param int $subject_id Subject ID
 * @param int $grade_level Grade level
 * @return bool True if applicable
 */
function isSubjectApplicableForGrade($conn, $subject_id, $grade_level) {
    $stmt = $conn->prepare("SELECT 
                           COALESCE(sgc.is_active, 
                                   CASE 
                                       WHEN ? BETWEEN s.min_grade AND s.max_grade THEN 1 
                                       ELSE 0 
                                   END) as is_applicable
                           FROM subjects s
                           LEFT JOIN subject_grade_config sgc ON s.id = sgc.subject_id AND sgc.grade_level = ?
                           WHERE s.id = ?");
    $stmt->bind_param("iii", $grade_level, $grade_level, $subject_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return (bool)$row['is_applicable'];
    }
    
    return false;
}

/**
 * Get subject type label
 * @param array $subject Subject array
 * @return string Subject type description
 */
function getSubjectTypeLabel($subject) {
    if ($subject['subject_name'] === 'General Average') {
        return 'Computed';
    }
    
    if ($subject['is_mapeh_component']) {
        return 'MAPEH Component';
    }
    
    if ($subject['is_core']) {
        return 'Core Subject';
    }
    
    return 'Additional/Optional';
}

/**
 * Get grade level range description
 * @param int $min_grade Minimum grade
 * @param int $max_grade Maximum grade
 * @return string Grade range description
 */
function getGradeRangeLabel($min_grade, $max_grade) {
    if ($min_grade == 1 && $max_grade == 6) {
        return 'All Grades';
    }
    
    if ($min_grade == $max_grade) {
        return "Grade $min_grade only";
    }
    
    return "Grades $min_grade-$max_grade";
}

/**
 * Validate subject name change
 * @param mysqli $conn Database connection
 * @param int $subject_id Subject ID
 * @param string $new_name New subject name
 * @return array Result with 'success' and 'message'
 */
function validateSubjectNameChange($conn, $subject_id, $new_name) {
    $new_name = trim($new_name);
    
    if (empty($new_name)) {
        return ['success' => false, 'message' => 'Subject name cannot be empty'];
    }
    
    if (strlen($new_name) > 100) {
        return ['success' => false, 'message' => 'Subject name too long (max 100 characters)'];
    }
    
    // Check if name already exists for a different subject
    $stmt = $conn->prepare("SELECT id FROM subjects WHERE subject_name = ? AND id != ?");
    $stmt->bind_param("si", $new_name, $subject_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return ['success' => false, 'message' => 'Subject name already exists'];
    }
    
    return ['success' => true, 'message' => 'Valid'];
}

/**
 * Update subject name
 * @param mysqli $conn Database connection
 * @param int $subject_id Subject ID
 * @param string $new_name New subject name
 * @return bool Success status
 */
function updateSubjectName($conn, $subject_id, $new_name) {
    $validation = validateSubjectNameChange($conn, $subject_id, $new_name);
    
    if (!$validation['success']) {
        return false;
    }
    
    $stmt = $conn->prepare("UPDATE subjects SET subject_name = ? WHERE id = ?");
    $stmt->bind_param("si", $new_name, $subject_id);
    
    return $stmt->execute();
}

/**
 * Get K-12 curriculum summary
 * @return array Curriculum structure
 */
function getK12CurriculumStructure() {
    return [
        'grades_1_3' => [
            'label' => 'Lower Elementary (Grades 1-3)',
            'subjects' => [
                'Mother Tongue-Based MLE',
                'Filipino',
                'English', 
                'Mathematics',
                'Science',
                'Araling Panlipunan',
                'MAPEH',
                'Edukasyon sa Pagpapakatao'
            ]
        ],
        'grades_4_6' => [
            'label' => 'Upper Elementary (Grades 4-6)',
            'subjects' => [
                'Filipino',
                'English',
                'Mathematics', 
                'Science',
                'Araling Panlipunan',
                'EPP/TLE',
                'MAPEH',
                'Edukasyon sa Pagpapakatao'
            ]
        ],
        'mapeh_components' => [
            'Music',
            'Arts',
            'Physical Education',
            'Health'
        ]
    ];
}
