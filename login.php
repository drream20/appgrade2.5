<?php
/**
 * Unified login for Student, MIS, Instructor, Registrar, and Dean
 */
require __DIR__ . '/instructor/config/database.php';
require __DIR__ . '/instructor/includes/auth.php';
require __DIR__ . '/registrar/includes/auth.php';
require __DIR__ . '/mis/includes/auth.php';
require __DIR__ . '/student/includes/auth.php';
require __DIR__ . '/mis/includes/logger.php';
require __DIR__ . '/dean/includes/auth.php';
require_once __DIR__ . '/includes/password_payload.php';

$baseUrl = defined('BASE_URL') ? BASE_URL : rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

if (currentMisUserId()) {
    header('Location: ' . $baseUrl . '/mis/dashboard.php');
    exit;
}
if (currentDeanId()) {
    header('Location: ' . $baseUrl . '/dean/dashboard.php');
    exit;
}
if (currentInstructorId()) {
    header('Location: ' . $baseUrl . '/instructor/dashboard.php');
    exit;
}
if (currentRegistrarId()) {
    header('Location: ' . $baseUrl . '/registrar/dashboard.php');
    exit;
}
if (currentStudentId()) {
    header('Location: ' . $baseUrl . '/student/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? $_POST['email'] ?? '');
    $password = normalize_password_input($_POST['password'] ?? '');
    if ($login && $password) {
        if (studentLogin($login, $password)) {
            header('Location: ' . $baseUrl . '/student/dashboard.php');
            exit;
        }
        if (misLogin($login, $password)) {
            logActivity('mis', currentMisUserId(), 'login');
            header('Location: ' . $baseUrl . '/mis/dashboard.php');
            exit;
        }
        if (deanLogin($login, $password)) {
            if (function_exists('logActivity')) {
                logActivity('dean', currentDeanId(), 'login');
            }
            header('Location: ' . $baseUrl . '/dean/dashboard.php');
            exit;
        }
        if (login($login, $password)) {
            if (function_exists('logActivity')) {
                logActivity('instructor', currentInstructorId(), 'login');
            }
            header('Location: ' . $baseUrl . '/instructor/dashboard.php');
            exit;
        }
        if (registrarLogin($login, $password)) {
            if (function_exists('logActivity')) {
                logActivity('registrar', currentRegistrarId(), 'login');
            }
            header('Location: ' . $baseUrl . '/registrar/dashboard.php');
            exit;
        }
    }
    $error = 'Invalid Student ID, email or password.';
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AppGrade Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background-color: #f3f4f6; }
        .brand-blue { color: #1e3a8a; }
        .bg-brand-blue { background-color: #1a365d; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">

    <div class="bg-white w-full max-w-5xl shadow-xl overflow-hidden flex flex-col md:flex-row min-h-[500px]">
        
        <div class="w-full md:w-1/2 p-8 sm:p-10 md:p-12 flex flex-col justify-center">
            <img
                src="logo.jpg"
                alt="Concepcion Holy Cross College Logo"
                class="block sm:hidden mx-auto h-auto w-[200px] ">
            <h1 class="text-4xl text-center font-bold brand-blue mb-6">AppGrade</h1>
            
            <div class="mb-8">
                <h2 class="text-2xl text-center  font-bold text-gray-900">Sign in to your account</h2>
                <p class="text-sm text-center  text-gray-500">“Grades Made Simple. Results Made Powerful.”</p>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-300 text-red-700 px-4 py-2 rounded mb-4 text-center text-sm">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="" class="space-y-6" id="loginForm">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Student No. or Email</label>
                    <input type="text" 
                        name="login" 
                        required 
                        value="<?= htmlspecialchars($_POST['login'] ?? $_POST['email'] ?? '') ?>"
                        placeholder="Student No. or email" 
                        class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:outline-none transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <div class="relative">
                        <input type="password"
                            name="password"
                            id="password-input"
                            required
                            placeholder="Password"
                            class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 focus:outline-none transition pr-12">
                        <button type="button" id="toggle-password"
                                tabindex="-1"
                                class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-700 text-sm bg-transparent px-2 py-1 rounded"
                                style="outline: none;"
                                aria-label="Show or hide password">
                            Show
                        </button>
                    </div>
                </div>
                <button type="submit"
                    class="w-full bg-brand-blue text-white font-semibold py-3 rounded-md hover:bg-blue-900 transition duration-200 shadow-md">
                    Sign in
                </button>
            </form>
            <p class="text-gray-400 text-xs mt-5">
                Use your assigned student number or email and password.
            </p>
            
        </div>

        <div class="w-full md:w-1/2 flex items-center justify-center 
            p-0 sm:p-0 md:p-0 
            border-0 md:border-0 
            ">
            <img 
                src="logo.jpg" 
                alt="Concepcion Holy Cross College Logo" 
                class="
                    hidden 
                    sm:block 
                    mx-auto 
                    h-auto 
                    w-[360px] 
                    sm:w-[460px] 
                    md:w-[600px] 
                    lg:w-[680px]
                ">
        </div>

    </div>
    <script>
        async function loginPasswordSha256Hex(plain) {
            const buf = new TextEncoder().encode(plain);
            const hash = await crypto.subtle.digest('SHA-256', buf);
            return Array.from(new Uint8Array(hash))
                .map(function (b) { return b.toString(16).padStart(2, '0'); })
                .join('');
        }
        const loginForm = document.getElementById('loginForm');
        const toggleBtn = document.getElementById('toggle-password');
        const pwdInput = document.getElementById('password-input');
        if (loginForm && pwdInput) {
            loginForm.addEventListener('submit', async function (e) {
                if (loginForm.dataset.passwordHashed === '1') {
                    return;
                }
                const plain = pwdInput.value;
                if (plain.length === 0) {
                    return;
                }
                // Web Crypto is only available in secure contexts (HTTPS) except on localhost.
                // On plain HTTP, submit plaintext; PHP normalizes (includes/password_payload.php).
                if (!window.crypto || !window.crypto.subtle) {
                    return;
                }
                e.preventDefault();
                try {
                    pwdInput.value = await loginPasswordSha256Hex(plain);
                    loginForm.dataset.passwordHashed = '1';
                    loginForm.submit();
                } catch (err) {
                    pwdInput.value = plain;
                    loginForm.dataset.passwordHashed = '1';
                    loginForm.submit();
                }
            });
        }
        if (toggleBtn && pwdInput) {
            toggleBtn.addEventListener('click', function () {
                if (pwdInput.type === 'password') {
                    pwdInput.type = 'text';
                    toggleBtn.textContent = 'Hide';
                } else {
                    pwdInput.type = 'password';
                    toggleBtn.textContent = 'Show';
                }
            });
        }
    </script>

</body>
</html>
