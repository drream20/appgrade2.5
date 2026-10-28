<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/../instructor/includes/grades.php';
registrarRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

$db = getDb();

// Stats
$stmt = $db->query("SELECT COUNT(*) as n FROM students");
$totalStudents = (int) $stmt->fetch()['n'];

$stmt = $db->query("SELECT COUNT(DISTINCT instructor_subject_id) as n FROM enrollments");
$totalClasses = (int) $stmt->fetch()['n'];

$stmt = $db->query("SELECT COUNT(*) as n FROM subjects");
$totalSubjects = (int) $stmt->fetch()['n'];

// Students per academic year (for bar chart)
$stmt = $db->query("
  SELECT ay.school_year, ay.semester, COUNT(DISTINCT e.student_id) AS student_count
  FROM enrollments e
  JOIN class_sections cs ON cs.id = e.class_section_id
  JOIN academic_years ay ON ay.id = cs.academic_year_id
  GROUP BY ay.school_year, ay.semester
  ORDER BY ay.school_year, ay.semester
");
$studentsPerAy = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $label = $row['school_year'] . ' ' . $row['semester'];
    $studentsPerAy[] = ['label' => $label, 'count' => (int)$row['student_count']];
}

// All subjects with enrolled students (class list summary)
$stmt = $db->query("
  SELECT isub.id as instructor_subject_id, s.code, s.title, isub.school_year, isub.semester, COUNT(e.id) as enrolled
  FROM instructor_subjects isub
  JOIN subjects s ON s.id = isub.subject_id
  LEFT JOIN enrollments e ON e.instructor_subject_id = isub.id
  GROUP BY isub.id, s.code, s.title, isub.school_year, isub.semester
  HAVING enrolled > 0
  ORDER BY s.code, isub.school_year, isub.semester
");
$classListSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Registrar Dashboard';
$currentPage = 'dashboard';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Registrar Dashboard</h4>
</div>

<!-- Bar Chart for Total Number of Students Per Academic Year -->
<div class="card mb-4">
  <div class="card-header bg-white">
    <strong><i class="bi bi-bar-chart me-2"></i>Total Students Per Academic Year</strong>
  </div>
  <div class="card-body">
    <div id="students_per_ay_chart" style="height: 350px;"></div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body d-flex align-items-center">
        <div class="rounded-circle bg-primary bg-opacity-10 p-3 me-3"><i class="bi bi-people text-primary fs-4"></i></div>
        <div>
          <div class="text-muted small">Students</div>
          <div class="fs-4 fw-bold"><?= $totalStudents ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body d-flex align-items-center">
        <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3"><i class="bi bi-journal-text text-success fs-4"></i></div>
        <div>
          <div class="text-muted small">Class Sections</div>
          <div class="fs-4 fw-bold"><?= $totalClasses ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body d-flex align-items-center">
        <div class="rounded-circle bg-info bg-opacity-10 p-3 me-3"><i class="bi bi-book text-info fs-4"></i></div>
        <div>
          <div class="text-muted small">Subjects</div>
          <div class="fs-4 fw-bold"><?= $totalSubjects ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- <div class="card">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <strong><i class="bi bi-people me-2"></i>Class List by Subject</strong>
    <a href="<?= $baseUrl ?>/class_list.php" class="btn btn-sm btn-outline-primary">View All</a>
  </div>
  <div class="card-body p-0">
    <?php if (empty($classListSummary)): ?>
      <p class="text-muted p-3 mb-0">No enrolled classes found.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Subject</th>
              <th>School Year</th>
              <th>Semester</th>
              <th>Enrolled</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($classListSummary as $row): ?>
              <tr>
                <td><strong><?= htmlspecialchars($row['code']) ?></strong> — <?= htmlspecialchars($row['title']) ?></td>
                <td><?= htmlspecialchars($row['school_year'] ?? '-') ?></td>
                <td><?= htmlspecialchars($row['semester'] ?? '-') ?></td>
                <td><?= (int) $row['enrolled'] ?></td>
                <td>
                  <a href="<?= $baseUrl ?>/class_list.php?instructor_subject_id=<?= $row['instructor_subject_id'] ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-eye me-1"></i>View
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div> -->

<!-- <div class="card mt-4">
  <div class="card-header bg-white">
    <strong><i class="bi bi-download me-2"></i>Quick Actions</strong>
  </div>
  <div class="card-body">
    <p class="text-muted mb-2">Go to <a href="<?= $baseUrl ?>/students.php">Students</a> to download TOR (Transcript of Record) and Prospectus per student.</p>
  </div>
</div> -->
<script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
<script>
  // Data for Google Charts
  var studentsPerAY = <?= json_encode($studentsPerAy); ?>;
  google.charts.load('current', {'packages':['corechart','bar']});
  google.charts.setOnLoadCallback(drawStudentsPerAYChart);

  function drawStudentsPerAYChart() {
    var data = new google.visualization.DataTable();
    data.addColumn('string', 'Academic Year');
    data.addColumn('number', 'Students');
    studentsPerAY.forEach(function(row) {
      data.addRow([row.label, row.count]);
    });

    var options = {
      title: 'Total Number of Students per Academic Year',
      chartArea: {width: '70%', height:'70%'},
      legend: { position: "none" },
      hAxis: {
        title: "Academic Year",
        slantedText: true,
        slantedTextAngle: 30,
        textStyle: { fontSize: 11 }
      },
      vAxis: {
        title: "Students",
        minValue: 0,
        gridlines: { count: -1 }
      },
      colors: ['#4285f4']
    };

    var chart = new google.visualization.ColumnChart(document.getElementById('students_per_ay_chart'));
    chart.draw(data, options);
  }
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
