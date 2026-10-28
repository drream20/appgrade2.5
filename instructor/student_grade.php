<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/grades.php';
require __DIR__ . '/../includes/active_academic_year.php';
require_once __DIR__ . '/includes/subject_code_lab.php';
requireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$db = getDb();
$instructorId = currentInstructorId();
$enrollmentId = isset($_GET['enrollment_id']) ? (int)$_GET['enrollment_id'] : null;
$activeAY = getActiveAcademicYear($db);

$student = null;
$subjectInfo = null;
$termGrades = [];
$termsList = [];

// --- Fix: Show all students enrolled in the class, and highlight the selected student ---
$enrolledStudents = [];
$selectedStudent = null;
$selectedFinalGrade = null;
$selectedTermGrades = [];

if ($enrollmentId) {
    // Find enrollment - via instructor_subject_id or class_section (MIS upload), verify instructor owns it
    if (!$activeAY) {
        $selectedStudent = null;
    } else {
    $stmt = $db->prepare("
      SELECT e.id as enrollment_id, st.id as student_db_id, st.student_id as student_number, st.full_name,
             COALESCE(e.instructor_subject_id, cs.instructor_subject_id) as instructor_subject_id,
             s.code, s.title
      FROM enrollments e
      JOIN students st ON st.id = e.student_id
      LEFT JOIN class_sections cs ON cs.id = e.class_section_id
      LEFT JOIN instructor_subjects isub ON isub.id = COALESCE(e.instructor_subject_id, cs.instructor_subject_id)
      LEFT JOIN subjects s ON s.id = isub.subject_id
      WHERE e.id = ?
        AND isub.instructor_id = ?
        AND (
              isub.academic_year_id = ?
           OR (isub.academic_year_id IS NULL AND isub.school_year = ? AND isub.semester = ?)
        )
    ");
    $stmt->execute([$enrollmentId, $instructorId, (int)$activeAY['id'], (string)$activeAY['school_year'], (string)$activeAY['semester']]);
    $selectedStudent = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($selectedStudent) {
        if (instructor_subject_code_is_lab_suffix($selectedStudent['code'] ?? '')) {
            header('Location: ' . $baseUrl . '/grades.php');
            exit;
        }
        $subjectInfo = ['code' => $selectedStudent['code'], 'title' => $selectedStudent['title'], 'instructor_subject_id' => $selectedStudent['instructor_subject_id']];
        $instructorSubjectId = (int)$selectedStudent['instructor_subject_id'];

        // List all students enrolled in this instructor_subject (including via class_section)
        $stmt = $db->prepare("
            SELECT e.id as enrollment_id, st.id as student_db_id, st.student_id as student_number, st.full_name
            FROM enrollments e
            JOIN students st ON st.id = e.student_id
            WHERE e.instructor_subject_id = ?
               OR e.class_section_id IN (SELECT id FROM class_sections WHERE instructor_subject_id = ?)
            ORDER BY st.full_name
        ");
        $stmt->execute([$instructorSubjectId, $instructorSubjectId]);
        $enrolledStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Terms
        $stmt = $db->query("SELECT id, name FROM terms ORDER BY sort_order");
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $termsList[$r['id']] = $r['name'];

        // Term grades for selected enrollment
        $stmt = $db->prepare("SELECT * FROM term_grades WHERE enrollment_id = ? ORDER BY term_id");
        $stmt->execute([$enrollmentId]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $selectedTermGrades[$r['term_id']] = $r;

        $prelim = $selectedTermGrades[1]['term_grade_computed'] ?? null;
        $midterm = $selectedTermGrades[2]['term_grade_computed'] ?? null;
        $final = $selectedTermGrades[3]['term_grade_computed'] ?? null;
        $selectedFinalGrade = ($prelim !== null && $midterm !== null && $final !== null) ? computeFinalGrade((float)$prelim, (float)$midterm, (float)$final) : null;
    }
}

$pageTitle = 'Student grade';
$currentPage = 'grades';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Individual grade</h4>
  <?php if (!empty($subjectInfo)): ?>
    <a href="<?= $baseUrl ?>/grades.php?instructor_subject_id=<?= (int) $subjectInfo['instructor_subject_id'] ?>" class="btn btn-outline-secondary btn-sm">Back to class</a>
  <?php endif; ?>
</div>

<?php if (!$selectedStudent): ?>
  <div class="alert alert-warning">Enrollment not found or you do not have access.</div>
<?php else: ?>
  <div class="row">
    <div class="col-md-4">
      <div class="card mb-3">
        <div class="card-header bg-white">Enrolled Students (click to view)</div>
        <ul class="list-group list-group-flush">
          <?php foreach ($enrolledStudents as $stud): ?>
            <li 
              class="list-group-item<?= ($stud['enrollment_id'] == $enrollmentId) ? ' active' : '' ?>"
            >
              <?php if ($stud['enrollment_id'] == $enrollmentId): ?>
                <span class="fw-bold"><?= htmlspecialchars($stud['full_name']) ?></span>
                <br><span class="text-muted small"><?= htmlspecialchars($stud['student_number']) ?></span>
              <?php else: ?>
                <a href="<?= $baseUrl ?>/student_grade.php?enrollment_id=<?= (int)$stud['enrollment_id'] ?>">
                  <?= htmlspecialchars($stud['full_name']) ?><br>
                  <span class="text-muted small"><?= htmlspecialchars($stud['student_number']) ?></span>
                </a>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <div class="col-md-8">
      <div class="card mb-3">
        <div class="card-body">
          <h5><?= htmlspecialchars($selectedStudent['full_name']) ?></h5>
          <p class="text-muted mb-0"><?= htmlspecialchars($selectedStudent['student_number']) ?> — <?= htmlspecialchars($subjectInfo['code'] . ' - ' . $subjectInfo['title']) ?></p>
        </div>
      </div>
      <div class="card">
        <div class="card-header bg-white">Grade breakdown (Prelim 30% · Midterm 30% · Final 40%)</div>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>Term</th>
                <th>Class standing (35%)</th>
                <th>Attendance (5%)</th>
                <th>Performance task (20%)</th>
                <th>Term exam (40%)</th>
                <th>Term grade</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($termsList as $tid => $tname):
                  $t = $selectedTermGrades[$tid] ?? [];
              ?>
                <tr>
                  <td><?= htmlspecialchars($tname) ?></td>
                  <td><?= isset($t['class_standing']) && $t['class_standing'] !== null ? number_format((float)$t['class_standing'], 2) : '—' ?></td>
                  <td><?= isset($t['attendance']) && $t['attendance'] !== null ? number_format((float)$t['attendance'], 2) : '—' ?></td>
                  <td><?= isset($t['performance_task']) && $t['performance_task'] !== null ? number_format((float)$t['performance_task'], 2) : '—' ?></td>
                  <td><?= isset($t['term_examination']) && $t['term_examination'] !== null ? number_format((float)$t['term_examination'], 2) : '—' ?></td>
                  <td><strong><?= isset($t['term_grade_computed']) && $t['term_grade_computed'] !== null ? number_format((float)$t['term_grade_computed'], 2) : '—' ?></strong></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="card-footer bg-white">
          <strong>Final grade:</strong> <?= isset($selectedFinalGrade) ? number_format($selectedFinalGrade, 2) : '—' ?>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
