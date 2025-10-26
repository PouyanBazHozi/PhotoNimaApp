<?php
session_start();
require_once 'DB Config.php'; // فایل تنظیمات دیتابیس

// تنظیمات اولیه
ini_set('display_errors', 0);
error_reporting(E_ALL);

// کلاس مدیریت ثبت‌نام
class UserManager {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    // تولید توکن CSRF
    public function generateCsrfToken(): string {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    // اعتبارسنجی توکن CSRF
    private function validateCsrfToken(string $token): bool {
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    // لاگ‌گذاری خطاها
    private function logError(string $message): void {
        file_put_contents(
            'register_errors.log',
            date('Y-m-d H:i:s') . " - " . $message . " - IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "\n",
            FILE_APPEND
        );
    }

    // ثبت کاربر جدید
    public function register(string $username, string $password, string $csrf_token): array {
        try {
            // بررسی توکن CSRF
            if (!$this->validateCsrfToken($csrf_token)) {
                $this->logError("CSRF Token mismatch for username: $username");
                return [
                    'success' => false,
                    'status' => 403,
                    'message' => 'خطای امنیتی: توکن نامعتبر است'
                ];
            }

            // اعتبارسنجی ورودی‌ها
            if (empty($username) || empty($password)) {
                return [
                    'success' => false,
                    'status' => 400,
                    'message' => 'نام کاربری و کلمه عبور اجباری هستند'
                ];
            }

            if (strlen($username) < 3 || strlen($password) < 6) {
                return [
                    'success' => false,
                    'status' => 400,
                    'message' => 'نام کاربری حداقل 3 و کلمه عبور حداقل 6 کاراکتر باشد'
                ];
            }

            // بررسی وجود کاربر
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
            $stmt->execute([':username' => $username]);
            if ($stmt->fetchColumn() > 0) {
                return [
                    'success' => false,
                    'status' => 409,
                    'message' => 'این نام کاربری قبلاً ثبت شده است'
                ];
            }

            // هش کردن رمز عبور
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            // ثبت کاربر در دیتابیس
            $stmt = $this->pdo->prepare("INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)");
            $stmt->execute([
                ':username' => $username,
                ':password_hash' => $password_hash
            ]);

            return [
                'success' => true,
                'status' => 201,
                'message' => 'کاربر با موفقیت ثبت شد',
                'redirect' => 'index.php' // فرضاً به صفحه ورود هدایت شود
            ];
        } catch (Exception $e) {
            $this->logError("Register error: " . $e->getMessage());
            return [
                'success' => false,
                'status' => 500,
                'message' => 'خطا در سرور: ' . $e->getMessage()
            ];
        }
    }
}

// بررسی اتصال به دیتابیس
if (!isset($conn) || !$conn instanceof PDO) {
    http_response_code(500);
    echo json_encode(['success' => false, 'status' => 500, 'message' => 'خطا: اتصال به دیتابیس برقرار نیست']);
    exit;
}

// ایجاد شیء مدیریت ثبت‌نام
$userManager = new UserManager($conn);

// تولید توکن CSRF برای فرم
$csrf_token = $userManager->generateCsrfToken();

// مدیریت درخواست ثبت‌نام
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    header('Content-Type: application/json');
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING) ?: '';
    $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING) ?: '';
    $csrf_token_input = filter_input(INPUT_POST, 'csrf_token', FILTER_SANITIZE_STRING) ?: '';

    $response = $userManager->register($username, $password, $csrf_token_input);
    echo json_encode($response);
    exit;
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="ثبت‌نام در نرم‌افزار مدیریت حرفه‌ای استودیو نیما">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="Image/Logo1.png" type="image/png" sizes="80x80">
    <title>نیما | ثبت‌نام</title>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary-bg: #F7FAFC;
            --secondary-bg: rgba(255, 255, 255, 0.9);
            --text-dark: #1F2937;
            --text-muted: #6B7280;
            --accent: #2563EB;
            --accent-hover: #1D4ED8;
            --success: #16A34A;
            --danger: #DC2626;
            --gradient-start: #2563EB;
            --gradient-end: #6B7280;
            --shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
            --shadow-hover: 0 12px 40px rgba(0, 0, 0, 0.18);
            --border: rgba(226, 232, 240, 0.6);
            --neon-glow: 0 0 10px var(--accent);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --spacing-unit: 0.75rem;
            --radius: 16px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Vazirmatn', sans-serif;
            direction: rtl;
        }

        body {
            background: linear-gradient(135deg, var(--gradient-start), var(--gradient-end));
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            position: relative;
        }

        .background-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at center, rgba(255, 255, 255, 0.1), transparent 70%);
            z-index: 0;
            animation: pulse 15s infinite ease-in-out;
        }

        .background-shapes {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 0;
        }

        .shape {
            position: absolute;
            background: rgba(37, 99, 235, 0.15);
            border-radius: 50%;
            animation: float 8s infinite ease-in-out;
        }

        .shape:nth-child(1) { width: 120px; height: 120px; top: 10%; left: 15%; animation-delay: 0s; }
        .shape:nth-child(2) { width: 80px; height: 80px; top: 60%; left: 75%; animation-delay: 2s; }
        .shape:nth-child(3) { width: 150px; height: 150px; top: 80%; left: 20%; animation-delay: 4s; }

        .container {
            position: relative;
            z-index: 1;
            max-width: 420px;
            padding: calc(var(--spacing-unit) * 2);
            background: var(--secondary-bg);
            backdrop-filter: blur(12px);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            transition: var(--transition);
        }

        .container:hover {
            transform: translateY(-4px) scale(1.02);
            box-shadow: var(--shadow-hover);
        }

        .logo {
            display: block;
            margin: 0 auto calc(var(--spacing-unit) * 1.5);
            width: 64px;
            height: 40px;
            filter: drop-shadow(0 4px 8px rgba(37, 99, 235, 0.25));
            transition: var(--transition);
        }

        .logo:hover {
            transform: scale(1.15) rotate(5deg);
            filter: drop-shadow(var(--neon-glow));
        }

        .title {
            font-size: 1.9rem;
            font-weight: 700;
            color: var(--text-dark);
            text-align: center;
            margin-bottom: calc(var(--spacing-unit) * 0.5);
            letter-spacing: -0.6px;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .subtitle {
            font-size: 0.95rem;
            font-weight: 400;
            color: var(--text-muted);
            text-align: center;
            margin-bottom: calc(var(--spacing-unit) * 1.25);
        }

        .title-divider {
            width: 90px;
            height: 5px;
            background: linear-gradient(to left, var(--accent), var(--accent-hover));
            margin: 0 auto calc(var(--spacing-unit) * 1.5);
            border-radius: 3px;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .title-divider::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.4);
            animation: shine 2.5s infinite;
        }

        .container:hover .title-divider {
            width: 120px;
        }

        .form-group {
            position: relative;
            margin-bottom: calc(var(--spacing-unit) * 1.5);
        }

        .form-group label {
            position: absolute;
            top: 50%;
            right: 50px;
            font-size: 0.9rem;
            font-weight: 400;
            color: var(--text-muted);
            transform: translateY(-50%);
            transition: var(--transition);
            pointer-events: none;
            padding: 0 6px;
            background: var(--secondary-bg);
        }

        .form-group input {
            width: 100%;
            padding: 12px 16px 12px 48px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 0.95rem;
            color: var(--text-dark);
            background: rgba(255, 255, 255, 0.85);
            transition: var(--transition);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .form-group input:focus,
        .form-group input:not(:placeholder-shown) {
            border-color: var(--accent);
            background: var(--secondary-bg);
            box-shadow: 0 0 14px rgba(37, 99, 235, 0.3), inset 0 0 0 transparent;
        }

        .form-group input:focus + label,
        .form-group input:not(:placeholder-shown) + label {
            top: -8px;
            font-size: 0.75rem;
            color: var(--accent);
            font-weight: 500;
        }

        .form-group i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1rem;
            transition: var(--transition);
        }

        .form-group input:focus ~ i,
        .form-group input:not(:placeholder-shown) ~ i {
            color: var(--accent);
            transform: translateY(-50%) scale(1.1);
        }

        .error-message, .success-message {
            font-size: 0.85rem;
            text-align: center;
            margin-bottom: calc(var(--spacing-unit) * 1.25);
            padding: 8px 12px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            opacity: 0;
            transform: translateY(-8px);
            transition: var(--transition);
        }

        .error-message {
            color: var(--danger);
            background: rgba(220, 38, 38, 0.2);
            border: 1px solid rgba(220, 38, 38, 0.4);
            box-shadow: 0 2px 6px rgba(220, 38, 38, 0.1);
        }

        .success-message {
            color: var(--success);
            background: rgba(22, 163, 74, 0.2);
            border: 1px solid rgba(22, 163, 74, 0.4);
            box-shadow: 0 2px 6px rgba(22, 163, 74, 0.1);
        }

        .error-message.active, .success-message.active {
            opacity: 1;
            transform: translateY(0);
        }

        .submit-btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(90deg, var(--accent), var(--accent-hover));
            color: var(--secondary-bg);
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: var(--shadow), var(--neon-glow);
            position: relative;
            overflow: hidden;
        }

        .submit-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .submit-btn:hover::before {
            width: 500px;
            height: 500px;
        }

        .submit-btn:hover {
            transform: translateY(-2px) scale(1.04);
            box-shadow: var(--shadow-hover), 0 0 20px var(--accent);
        }

        .submit-btn.loading::after {
            content: '';
            width: 18px;
            height: 18px;
            border: 2px solid var(--secondary-bg);
            border-top: 2px solid transparent;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            position: absolute;
        }

        .fade-in {
            animation: fadeIn 0.7s ease-out forwards;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes pulse {
            0%, 100% { opacity: 0.8; }
            50% { opacity: 0.4; }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-30px) scale(1.05); }
        }

        @keyframes shine {
            0% { left: -100%; }
            20% { left: 100%; }
            100% { left: 100%; }
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .container {
                max-width: 90%;
                padding: calc(var(--spacing-unit) * 1.5);
            }
            .title { font-size: 1.7rem; }
            .subtitle { font-size: 0.9rem; }
            .logo { width: 56px; height: 34px; }
            .submit-btn { font-size: 0.95rem; padding: 11px; }
            .form-group input { padding: 11px 16px 11px 48px; }
        }

        @media (max-width: 480px) {
            .title { font-size: 1.5rem; }
            .subtitle { font-size: 0.85rem; }
            .form-group input { padding: 10px 14px 10px 40px; }
            .submit-btn { padding: 10px; }
            .title-divider { width: 70px; }
            .container:hover .title-divider { width: 100px; }
            .form-group i { left: 12px; font-size: 0.9rem; }
        }
    </style>
</head>
<body>
    <div class="background-overlay"></div>
    <div class="background-shapes">
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
    </div>
    <div class="container fade-in">
        <img src="Image/Logo2.png" alt="نیما استودیو" class="logo">
        <h2 class="title">استودیو نیما</h2>
        <p class="subtitle">ثبت‌نام کاربر جدید</p>
        <div class="title-divider"></div>
        <div id="error-message" class="error-message"></div>
        <div id="success-message" class="success-message"></div>
        <form id="register-form" method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="form-group">
                <input type="text" name="username" id="username" required placeholder=" ">
                <label for="username">نام کاربری</label>
                <i class="fas fa-user"></i>
            </div>
            <div class="form-group">
                <input type="password" name="password" id="password" required placeholder=" ">
                <label for="password">کلمه عبور</label>
                <i class="fas fa-lock"></i>
            </div>
            <button type="submit" class="submit-btn"><i class="fas fa-user-plus"></i> ثبت‌نام</button>
        </form>
    </div>

    <script>
        document.getElementById('register-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const errorMessage = document.getElementById('error-message');
            const successMessage = document.getElementById('success-message');
            const submitBtn = e.target.querySelector('.submit-btn');

            // اعتبارسنجی سمت کاربر
            const username = formData.get('username');
            const password = formData.get('password');
            if (username.length < 3 || password.length < 6) {
                errorMessage.textContent = 'نام کاربری حداقل 3 و کلمه عبور حداقل 6 کاراکتر باشد';
                errorMessage.classList.add('active');
                errorMessage.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${errorMessage.textContent}`;
                setTimeout(() => errorMessage.classList.remove('active'), 4000);
                return;
            }

            submitBtn.classList.add('loading');
            submitBtn.disabled = true;

            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData,
                    headers: { 'Accept': 'application/json' }
                });
                const result = await response.json();

                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;

                if (result.success) {
                    successMessage.textContent = result.message;
                    successMessage.classList.add('active');
                    successMessage.innerHTML = `<i class="fas fa-check-circle"></i> ${result.message}`;
                    setTimeout(() => {
                        successMessage.classList.remove('active');
                        document.body.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                        document.body.style.opacity = '0';
                        document.body.style.transform = 'scale(0.98)';
                        setTimeout(() => window.location.href = result.redirect, 600);
                    }, 2000);
                } else {
                    errorMessage.textContent = result.message;
                    errorMessage.classList.add('active');
                    errorMessage.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${result.message}`;
                    setTimeout(() => errorMessage.classList.remove('active'), 4000);
                }
            } catch (error) {
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
                errorMessage.textContent = 'خطا در ارتباط با سرور';
                errorMessage.classList.add('active');
                errorMessage.innerHTML = `<i class="fas fa-exclamation-circle"></i> خطا در ارتباط با سرور`;
                setTimeout(() => errorMessage.classList.remove('active'), 4000);
                console.error('Register error:', error);
            }
        });
    </script>
</body>
</html>