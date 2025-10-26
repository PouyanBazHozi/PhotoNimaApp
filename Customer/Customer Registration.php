<?php
session_start();
require_once 'DB Config.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Constants for customer levels and referral points
const LEVEL_THRESHOLDS = [
    'bronze' => 0,    // 0 to 999 points
    'silver' => 1000, // 1000 to 4999 points
    'gold' => 5000    // 5000+ points
];

const CUSTOMER_LEVELS = [
    'bronze' => ['label' => 'برنزی', 'discount' => 0.00],
    'silver' => ['label' => 'نقره‌ای', 'discount' => 5.00],
    'gold' => ['label' => 'طلایی', 'discount' => 10.00]
];

const REFERRAL_POINTS = 100; // 100 points per referral

class CustomerManager {
    private PDO $pdo;

    /**
     * Constructor to initialize PDO connection
     */
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /**
     * Logs errors to a file for debugging and auditing
     */
    private function logError(string $message, array $context = []): void {
        $logMessage = date('Y-m-d H:i:s') . " - $message";
        if ($context) {
            $logMessage .= " | Context: " . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        file_put_contents('error.log', "$logMessage\n", FILE_APPEND);
    }

    /**
     * Validates date format and ensures it's not in the future
     */
    private function validateDate(?string $date): bool {
        if (!$date) return true;
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date && $d <= new DateTime('today');
    }

    /**
     * Validates Iranian mobile phone number (starts with 09, 11 digits)
     */
    private function validatePhone(string $phone): bool {
        return preg_match('/^09\d{9}$/', $phone);
    }

    /**
     * Validates referrer by first and last name, returns their ID or null
     */
    private function validateReferrer(?string $referrerFName, ?string $referrerLName): ?int {
        if (!$referrerFName || !$referrerLName) return null;
        $stmt = $this->pdo->prepare("SELECT id FROM photonim_db.customerregistration WHERE fName = ? AND lName = ?");
        $stmt->execute([trim($referrerFName), trim($referrerLName)]);
        $referrerId = $stmt->fetchColumn();
        if ($referrerId === false) {
            throw new Exception('معرف با این نام و نام خانوادگی یافت نشد.');
        }
        return $referrerId;
    }

    /**
     * Determines the customer level based on points
     */
    private function determineCustomerLevel(int $points): string {
        if ($points >= LEVEL_THRESHOLDS['gold']) return 'gold';
        if ($points >= LEVEL_THRESHOLDS['silver']) return 'silver';
        return 'bronze';
    }

    /**
     * Updates customer level if necessary and logs changes
     */
    private function updateCustomerLevel(int $customerId): array {
        try {
            // Fetch current points and level
            $stmt = $this->pdo->prepare("SELECT points, level FROM photonim_db.customerregistration WHERE id = ?");
            $stmt->execute([$customerId]);
            $customer = $stmt->fetch();

            if ($customer === false) {
                throw new Exception("مشتری با شناسه $customerId یافت نشد.");
            }

            $points = (int)$customer['points'];
            $oldLevel = $customer['level'];
            $newLevel = $this->determineCustomerLevel($points);

            // Check if level has changed
            if ($oldLevel !== $newLevel) {
                $stmt = $this->pdo->prepare("
                    UPDATE photonim_db.customerregistration 
                    SET level = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$newLevel, $customerId]);

                // Log the level change
                $this->logLevelChange($customerId, $oldLevel, $newLevel, $points);

                // Log success for auditing
                $this->logError("Level changed successfully", [
                    'customer_id' => $customerId,
                    'old_level' => $oldLevel,
                    'new_level' => $newLevel,
                    'points' => $points
                ]);
            }

            return [
                'success' => true,
                'points' => $points,
                'old_level' => $oldLevel,
                'new_level' => $newLevel,
                'level_changed' => $oldLevel !== $newLevel
            ];
        } catch (Exception $e) {
            $this->logError("Level Update Error: " . $e->getMessage(), ['customer_id' => $customerId]);
            return [
                'success' => false,
                'message' => 'خطا در به‌روزرسانی سطح مشتری: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Logs level changes in level_history table
     */
    private function logLevelChange(int $customerId, string $oldLevel, string $newLevel, int $points): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO photonim_db.level_history 
            (customer_id, old_level, new_level, points, changed_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$customerId, $oldLevel, $newLevel, $points]);
    }

    /**
     * Logs points transactions in point_history table
     */
    private function logPoints(int $customerId, int $points, string $eventType, ?int $relatedId = null): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO photonim_db.point_history 
            (customer_id, points, event_type, related_id, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$customerId, $points, $eventType, $relatedId]);
    }

    /**
     * Registers a new customer and handles referral logic
     */
    public function registerCustomer(
        string $fname,
        string $lname,
        string $phone,
        ?string $date = null,
        ?string $description = null,
        ?string $referrerFName = null,
        ?string $referrerLName = null
    ): array {
        try {
            $fname = trim($fname);
            $lname = trim($lname);
            $phone = trim($phone);

            // Input validation
            if (empty($fname) || empty($lname) || empty($phone)) {
                throw new Exception('فیلدهای نام، نام خانوادگی و شماره تماس اجباری هستند.');
            }

            if (!$this->validatePhone($phone)) {
                throw new Exception('شماره تماس باید با ۰۹ شروع شود و ۱۱ رقم باشد.');
            }

            $stmt = $this->pdo->prepare("SELECT id FROM photonim_db.customerregistration WHERE phone = ?");
            $stmt->execute([$phone]);
            if ($stmt->fetch()) {
                throw new Exception('این شماره تماس قبلاً ثبت شده است.');
            }

            if ($date && !$this->validateDate($date)) {
                throw new Exception('تاریخ تولد نامعتبر است یا نمی‌تواند در آینده باشد.');
            }

            $referredBy = $this->validateReferrer($referrerFName, $referrerLName);

            // Start transaction for data consistency
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
            }

            // Insert new customer
            $sql = "INSERT INTO photonim_db.customerregistration 
                    (fName, lName, phone, date, description, points, level, referred_by, created_at) 
                    VALUES (?, ?, ?, ?, ?, 0, 'bronze', ?, NOW())";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$fname, $lname, $phone, $date ?: null, $description ?: null, $referredBy]);
            $customerId = $this->pdo->lastInsertId();

            // Handle referral logic
            if ($referredBy) {
                // Insert referral record
                $referralSql = "INSERT INTO photonim_db.referrals 
                              (referrer_id, referred_id, status, created_at) 
                              VALUES (?, ?, 'pending', NOW())";
                $stmt = $this->pdo->prepare($referralSql);
                $stmt->execute([$referredBy, $customerId]);

                // Add referral points to referrer
                $stmt = $this->pdo->prepare("
                    UPDATE photonim_db.customerregistration 
                    SET points = points + ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([REFERRAL_POINTS, $referredBy]);

                // Log points transaction
                $this->logPoints($referredBy, REFERRAL_POINTS, 'referral', $customerId);

                // Update referrer's level
                $levelUpdate = $this->updateCustomerLevel($referredBy);
            }

            // Commit transaction
            $this->pdo->commit();

            // Prepare success response
            $response = [
                'success' => true,
                'message' => "مشتری $fname $lname با موفقیت ثبت شد! برای ارجاع دیگران، از نام و نام خانوادگی خود ($fname $lname) استفاده کنید.",
                'customer_id' => $customerId
            ];

            if ($referredBy && isset($levelUpdate) && $levelUpdate['success']) {
                $response['referrer_updated'] = [
                    'referrer_id' => $referredBy,
                    'points_added' => REFERRAL_POINTS,
                    'new_level' => $levelUpdate['new_level'],
                    'level_changed' => $levelUpdate['level_changed']
                ];
            }

            return $response;
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->logError("Customer Registration Error: " . $e->getMessage(), [
                'fname' => $fname,
                'lname' => $lname,
                'phone' => $phone,
                'referrer_fname' => $referrerFName,
                'referrer_lname' => $referrerLName,
                'date' => $date,
                'description' => $description
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

// Check database connection
if (!isset($conn) || !$conn instanceof PDO) {
    http_response_code(500);
    exit("<p style='color: #EF4444; text-align: center; font-family: Vazirmatn;'>خطا: اتصال به دیتابیس برقرار نیست</p>");
}

$customerManager = new CustomerManager($conn);
$errorMessage = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['insert'])) {
    $fname = trim($_POST['fname'] ?? '');
    $lname = trim($_POST['lname'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $date = trim($_POST['date'] ?? '') ?: null;
    $description = trim($_POST['description'] ?? '') ?: null;
    $referrerFName = trim($_POST['referrer_fname'] ?? '') ?: null;
    $referrerLName = trim($_POST['referrer_lname'] ?? '') ?: null;

    $response = $customerManager->registerCustomer($fname, $lname, $phone, $date, $description, $referrerFName, $referrerLName);

    if ($response['success']) {
        $_SESSION['message'] = $response['message'];
        if (isset($response['referrer_updated'])) {
            $_SESSION['referrer_message'] = "به معرف شما " . REFERRAL_POINTS . " امتیاز اضافه شد و سطح جدید: " . 
                CUSTOMER_LEVELS[$response['referrer_updated']['new_level']]['label'] .
                ($response['referrer_updated']['level_changed'] ? " (سطح ارتقا یافت)" : "");
        }
        header("Location: Order.php?new_customer_id=" . urlencode($response['customer_id']));
        exit;
    } else {
        $errorMessage = $response['message'];
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="ثبت حرفه‌ای مشتری جدید در استودیو نیما">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="Image/Logo1.png" type="image/png" sizes="80x80">
    <title>استودیو نیما | ثبت مشتری جدید</title>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary-bg: #F7FAFC;
            --secondary-bg: #FFFFFF;
            --text-dark: #1F2937;
            --text-muted: #6B7280;
            --accent: #2563EB;
            --accent-hover: #1D4ED8;
            --success: #16A34A;
            --success-hover: #15803D;
            --danger: #DC2626;
            --danger-hover: #B91C1C;
            --warning: #F59E0B;
            --warning-hover: #D97706;
            --border: #E2E8F0;
            --shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            --shadow-hover: 0 8px 16px rgba(0, 0, 0, 0.1);
            --modal-bg: rgba(0, 0, 0, 0.75);
            --card-hover: #F1F5F9;
            --transition: all 0.15s ease-out;
            --spacing-unit: 1rem;
            --radius: 6px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Vazirmatn', sans-serif;
            direction: rtl;
        }

        body {
            background: var(--primary-bg);
            color: var(--text-dark);
            min-height: 100vh;
            line-height: 1.5;
            font-size: 0.95rem;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        .container {
            max-width: 1360px;
            margin: 0 auto;
            padding: var(--spacing-unit);
        }

        .nav-bar {
            position: sticky;
            top: 0;
            background: var(--secondary-bg);
            box-shadow: var(--shadow);
            padding: calc(var(--spacing-unit) * 0.5) var(--spacing-unit);
            z-index: 1000;
        }

        .nav-content {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: calc(var(--spacing-unit) * 0.5);
            flex-wrap: wrap;
        }

        .nav-btn, .nav-logo {
            padding: 8px 16px;
            background: var(--accent);
            color: var(--secondary-bg);
            text-decoration: none;
            border-radius: var(--radius);
            font-weight: 500;
            font-size: 0.9rem;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: var(--shadow);
        }

        .nav-btn:hover, .nav-logo:hover {
            background: var(--accent-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }

        .nav-logo img {
            width: 36px;
            height: 22px;
        }

        .header {
            text-align: center;
            padding: calc(var(--spacing-unit) * 1.5) 0;
        }

        .header-title {
            font-size: 2rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: calc(var(--spacing-unit) * 0.25);
        }

        .header-divider {
            width: 80px;
            height: 3px;
            background: var(--accent);
            margin: 0 auto;
            border-radius: 2px;
            transition: var(--transition);
        }

        .header:hover .header-divider {
            width: 120px;
        }

        .form-section {
            background: var(--secondary-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: var(--spacing-unit);
            margin-bottom: calc(var(--spacing-unit) * 1.5);
        }

        .form-body {
            display: grid;
            gap: calc(var(--spacing-unit) * 0.75);
        }

        .form-group {
            position: relative;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-input, .form-textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 0.9rem;
            color: var(--text-dark);
            background: var(--secondary-bg);
            transition: var(--transition);
            box-shadow: var(--shadow);
        }

        .form-textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-input:focus, .form-textarea:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 6px rgba(37, 99, 235, 0.2);
        }

        .submit-btn {
            padding: 8px 16px;
            border-radius: var(--radius);
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--secondary-bg);
            background: var(--success);
            transition: var(--transition);
            border: none;
            cursor: pointer;
            box-shadow: var(--shadow);
        }

        .submit-btn:hover {
            background: var(--success-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }

        .form-error {
            color: var(--danger);
            font-size: 0.8rem;
            margin-top: 4px;
            display: none;
            text-align: center;
        }

        .form-error.active {
            display: block;
        }

        .fade-in {
            animation: fadeIn 0.3s ease-out forwards;
        }

        .referral-group {
            background: #F0F9FF;
            padding: 8px;
            border-radius: var(--radius);
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 1024px) {
            .form-body { grid-template-columns: repeat(2, 1fr); }
            .referral-group { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .nav-content { gap: calc(var(--spacing-unit) * 0.25); padding: 0 8px; }
            .nav-btn, .nav-logo { padding: 6px 12px; font-size: 0.85rem; }
            .form-body { grid-template-columns: 1fr; }
            .header-title { font-size: 1.75rem; }
        }

        @media (max-width: 480px) {
            .submit-btn { width: 100%; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="nav-bar">
        <nav class="nav-content fade-in">
            <a href="Nima%20Studio.html" class="nav-logo"><img src="Image/Logo2.png" alt="لوگو استودیو نیما"></a>
            <a href="Customer%20Registration.php" class="nav-btn"><i class="fas fa-user-plus"></i> ثبت مشتری</a>
            <a href="Order.php" class="nav-btn"><i class="fas fa-cart-plus"></i> ثبت سفارش</a>
            <a href="Customer%20List.php" class="nav-btn"><i class="fas fa-users"></i> لیست مشتریان</a>
            <a href="Order%20List.php" class="nav-btn"><i class="fas fa-list-ul"></i> لیست سفارشات</a>
            <a href="Products%20List.php" class="nav-btn"><i class="fas fa-boxes"></i> لیست محصولات</a>
            <a href="Index.php" class="nav-btn"><i class="fas fa-sign-out-alt"></i> خروج</a>
        </nav>
    </div>

    <div class="container">
        <header class="header fade-in">
            <h1 class="header-title">ثبت مشتری جدید</h1>
            <div class="header-divider"></div>
        </header>

        <section class="form-section fade-in">
            <?php if (isset($_SESSION['message'])): ?>
                <div class="form-error active fade-in" style="color: var(--success);"><?php echo htmlspecialchars($_SESSION['message']); unset($_SESSION['message']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['referrer_message'])): ?>
                <div class="form-error active fade-in" style="color: var(--success);"><?php echo htmlspecialchars($_SESSION['referrer_message']); unset($_SESSION['referrer_message']); ?></div>
            <?php endif; ?>
            <?php if (isset($errorMessage)): ?>
                <div class="form-error active fade-in"><?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>
            <form method="POST" id="customerForm" class="form-body">
                <div class="form-group">
                    <label class="form-label" for="fname"><i class="fas fa-user"></i> نام *</label>
                    <input type="text" name="fname" id="fname" class="form-input" placeholder="مثال: محمد" value="<?php echo htmlspecialchars($_POST['fname'] ?? ''); ?>" required>
                    <div class="form-error" id="fname_error"></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="lname"><i class="fas fa-user"></i> نام خانوادگی *</label>
                    <input type="text" name="lname" id="lname" class="form-input" placeholder="مثال: موسوی" value="<?php echo htmlspecialchars($_POST['lname'] ?? ''); ?>" required>
                    <div class="form-error" id="lname_error"></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="phone"><i class="fas fa-phone"></i> شماره تماس *</label>
                    <input type="tel" name="phone" id="phone" class="form-input" placeholder="مثال: 09195308703" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" pattern="09[0-9]{9}" required title="شماره باید با ۰۹ شروع شود و ۱۱ رقم باشد">
                    <div class="form-error" id="phone_error"></div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="date"><i class="fas fa-calendar-alt"></i> تاریخ تولد</label>
                    <input type="date" name="date" id="date" class="form-input" value="<?php echo htmlspecialchars($_POST['date'] ?? ''); ?>" max="<?php echo date('Y-m-d'); ?>">
                    <div class="form-error" id="date_error"></div>
                </div>
                <div class="form-group referral-group">
                    <div>
                        <label class="form-label" for="referrer_fname"><i class="fas fa-user-friends"></i> نام معرف (اختیاری)</label>
                        <input type="text" name="referrer_fname" id="referrer_fname" class="form-input" placeholder="مثال: علی" value="<?php echo htmlspecialchars($_POST['referrer_fname'] ?? ''); ?>">
                        <div class="form-error" id="referrer_fname_error"></div>
                    </div>
                    <div>
                        <label class="form-label" for="referrer_lname"><i class="fas fa-user-friends"></i> نام خانوادگی معرف (اختیاری)</label>
                        <input type="text" name="referrer_lname" id="referrer_lname" class="form-input" placeholder="مثال: رضایی" value="<?php echo htmlspecialchars($_POST['referrer_lname'] ?? ''); ?>">
                        <div class="form-error" id="referrer_lname_error"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="description"><i class="fas fa-comment"></i> توضیحات</label>
                    <textarea name="description" id="description" class="form-textarea" placeholder="توضیحات اضافی (اختیاری)"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>
                <button type="submit" name="insert" class="submit-btn"><i class="fas fa-save"></i> ثبت و ادامه</button>
            </form>
        </section>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('customerForm');

            /**
             * Displays validation error messages
             */
            function showError(elementId, message) {
                const errorElement = document.getElementById(`${elementId}_error`);
                errorElement.textContent = message;
                errorElement.classList.add('active');
                setTimeout(() => errorElement.classList.remove('active'), 5000);
            }

            /**
             * Validates form inputs before submission
             */
            function validateForm() {
                let isValid = true;

                const fname = document.getElementById('fname').value.trim();
                if (!fname) {
                    showError('fname', 'نام کوچک اجباری است.');
                    isValid = false;
                }

                const lname = document.getElementById('lname').value.trim();
                if (!lname) {
                    showError('lname', 'نام خانوادگی اجباری است.');
                    isValid = false;
                }

                const phone = document.getElementById('phone').value.trim();
                if (!phone) {
                    showError('phone', 'شماره تماس اجباری است.');
                    isValid = false;
                } else if (!/^09\d{9}$/.test(phone)) {
                    showError('phone', 'شماره تماس باید با ۰۹ شروع شود و ۱۱ رقم باشد.');
                    isValid = false;
                }

                const date = document.getElementById('date').value;
                if (date && new Date(date) > new Date()) {
                    showError('date', 'تاریخ تولد نمی‌تواند در آینده باشد.');
                    isValid = false;
                }

                const referrerFName = document.getElementById('referrer_fname').value.trim();
                const referrerLName = document.getElementById('referrer_lname').value.trim();
                if ((referrerFName && !referrerLName) || (!referrerFName && referrerLName)) {
                    showError(referrerFName ? 'referrer_lname' : 'referrer_fname', 'هر دو فیلد نام و نام خانوادگی معرف باید پر شوند.');
                    isValid = false;
                }

                return isValid;
            }

            form.addEventListener('submit', (e) => {
                if (!validateForm()) {
                    e.preventDefault();
                    const firstError = document.querySelector('.form-error.active');
                    if (firstError) firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        });
    </script>
</body>
</html>
