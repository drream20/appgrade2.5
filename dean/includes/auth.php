<?php
/**
 * Dean authentication
 */
require_once __DIR__ . '/../../includes/password_payload.php';
if (!function_exists('getDb')) {
    require __DIR__ . '/../config/database.php';
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function deanRequireLogin(): void {
    if (!empty($_SESSION['dean_id'])) {
        return;
    }
    $root = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    // If another role is already logged in, send them to their dashboard
    if (!empty($_SESSION['mis_user_id'])) {
        header('Location: ' . $root . '/mis/dashboard.php');
        exit;
    }
    if (!empty($_SESSION['instructor_id'])) {
        header('Location: ' . $root . '/instructor/dashboard.php');
        exit;
    }
    if (!empty($_SESSION['registrar_id'])) {
        header('Location: ' . $root . '/registrar/dashboard.php');
        exit;
    }
    if (!empty($_SESSION['student_db_id'])) {
        header('Location: ' . $root . '/student/dashboard.php');
        exit;
    }

    header('Location: ' . $root . '/login.php');
    exit;
}

function currentDeanId(): int {
    return (int) ($_SESSION['dean_id'] ?? 0);
}

function currentDean(): ?array {
    if (empty($_SESSION['dean_id'])) return null;
    $db = getDb();
    require_once __DIR__ . '/../../includes/welcome_modal_db.php';
    welcomeModalEnsureColumns($db);
    $stmt = $db->prepare('SELECT id, email, full_name, employee_id, department, image_path, welcome_modal_ack FROM deans WHERE id = ?');
    $stmt->execute([$_SESSION['dean_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function deanLogin(string $email, string $password): bool {
    $db = getDb();
    $stmt = $db->prepare('SELECT id, password_hash FROM deans WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row || !verify_password_payload($password, $row['password_hash'])) return false;
    $_SESSION['dean_id'] = (int) $row['id'];
    return true;
}

function deanLogout(): void {
    unset($_SESSION['dean_id']);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Program helpers
 * There are 6 undergraduate programs plus an Executive Dean who can view all.
 */
function deanPrograms(): array {
    return [
        'SCS' => 'School of Computer Studies',
        'SBAA'  => 'School of Business Administration & Accountancy',
        'SN' => 'School of Nursing',
        'SHM'   => 'School Hospitality Management',
        'SCJE'  => 'School of Criminal Justice Education',
        'STE' => 'School of Teacher Education',
    ];
}

/**
 * Map full department names to short department codes used by instructors.
 */
function deanDepartmentCodeMap(): array {
    return [
        'SCS' => ['School of Computer Studies', 'School of Science in Computer Studies'],
        'SHM' => ['School Hospitality Management'],
        'STE' => ['School of Teacher Education'],
        'SBAA' => ['School of Business Administration & Accountancy'],
        'SCJE' => ['School of Criminal Justice Education'],
        'SN' => ['School of Nursing'],
    ];
}

/**
 * Resolve a department code (SCS/SHM/...) from a full department name.
 */
function deanDepartmentCodeForName(?string $department): ?string {
    $needle = deanNormalizeDepartmentName($department);
    if ($needle === '') return null;
    foreach (deanDepartmentCodeMap() as $code => $names) {
        foreach ($names as $name) {
            if (deanNormalizeDepartmentName($name) === $needle) {
                return $code;
            }
        }
    }
    return null;
}

function deanNormalizeDepartmentName(?string $value): string {
    $v = strtolower(trim((string)$value));
    $v = preg_replace('/\s+/', ' ', $v ?? '');
    $v = str_replace('school of science in computer studies', 'school of computer studies', $v);
    return $v;
}

function deanIsExecutive(): bool {
    $dean = currentDean();
    $dept = trim($dean['department'] ?? '');
    if ($dept === '') return true;
    $lower = strtolower($dept);
    return $lower === 'executive' || $lower === 'executive dean';
}

function deanProgramKeyForDepartment(?string $department): ?string {
    if ($department === null || $department === '') return null;
    $programs = deanPrograms();
    $needle = deanNormalizeDepartmentName($department);
    foreach ($programs as $key => $name) {
        if (deanNormalizeDepartmentName($name) === $needle) {
            return $key;
        }
    }
    return null;
}

/**
 * Selected program key (short code) for current Dean.
 * - Regular Dean: fixed by their department.
 * - Executive Dean: can switch via ?program=KEY, falling back to "all programs" when null.
 */
function deanSelectedProgramKey(): ?string {
    $programs = deanPrograms();
    if (deanIsExecutive()) {
        // Executive Dean can switch programs via ?program=KEY, or view all when cleared.
        if (isset($_GET['program'])) {
            $val = $_GET['program'];
            if ($val === '' || strtoupper($val) === 'ALL') {
                unset($_SESSION['dean_program']);
            } elseif (array_key_exists($val, $programs)) {
                $_SESSION['dean_program'] = $val;
            }
        }
        $key = $_SESSION['dean_program'] ?? null;
        return ($key && array_key_exists($key, $programs)) ? $key : null; // null = all programs
    }
    $dean = currentDean();
    return deanProgramKeyForDepartment($dean['department'] ?? '') ?? null;
}

/**
 * Full department/program name to use for filtering records.
 * Returns null when Executive Dean is viewing "All programs".
 */
function deanSelectedProgramDepartment(): ?string {
    if (!deanIsExecutive()) {
        $dean = currentDean();
        $rawDept = trim((string)($dean['department'] ?? ''));
        if ($rawDept !== '') {
            return $rawDept;
        }
    }
    $key = deanSelectedProgramKey();
    if (!$key) return null;
    $programs = deanPrograms();
    return $programs[$key] ?? null;
}

/**
 * Map student course codes to Dean departments.
 */
function deanDepartmentCourseMap(): array {
    return [
        'School of Computer Studies' => ['BSCS'],
        'School Hospitality Management' => ['BSHM'],
        'School of Teacher Education' => ['BEED', 'BSEDFIL', 'BSEDMAT', 'BSEDENG', 'BSEDSS'],
        'School of Business Administration & Accountancy' => ['BSA', 'BSBAFM'],
        'School of Criminal Justice Education' => ['BSCRIM', 'BSISM'],
        'School of Nursing' => ['BSN'],
    ];
}

/**
 * Return course codes mapped to the given department name.
 * Falls back to normalized matching to handle minor naming variations.
 */
function deanCoursesForDepartment(?string $department): array {
    $dept = trim((string) $department);
    if ($dept === '') {
        return [];
    }

    $map = deanDepartmentCourseMap();
    if (isset($map[$dept])) {
        return $map[$dept];
    }

    $normalizedDept = deanNormalizeDepartmentName($dept);
    foreach ($map as $name => $courses) {
        if (deanNormalizeDepartmentName($name) === $normalizedDept) {
            return $courses;
        }
    }
    return [];
}

/**
 * Return all possible department values used by class/subject records
 * for the selected dean program. Data may contain either school names
 * (e.g., "School of Computer Studies") or course codes (e.g., "BSCS").
 */
function deanDepartmentFilterValues(?string $department): array {
    $values = [];
    $dept = trim((string)$department);
    if ($dept !== '') {
        $values[] = $dept;
    }

    $programKey = deanProgramKeyForDepartment($department);
    $programs = deanPrograms();
    if ($programKey && isset($programs[$programKey])) {
        $values[] = $programs[$programKey];
    }

    foreach (deanDepartmentCodeMap() as $code => $names) {
        foreach ($names as $name) {
            if (deanNormalizeDepartmentName($name) === deanNormalizeDepartmentName($department)) {
                $values[] = $code;
                foreach ($names as $n) {
                    $values[] = $n;
                }
                break 2;
            }
        }
    }

    foreach (deanCoursesForDepartment($department) as $courseCode) {
        $values[] = $courseCode;
    }

    $values = array_values(array_unique(array_filter(array_map('trim', $values), static fn($v) => $v !== '')));
    return $values;
}




