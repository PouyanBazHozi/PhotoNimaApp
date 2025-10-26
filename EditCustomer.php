<?php
session_start();
require_once 'DB Config.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Constants for customer levels and referral points (aligned with CustomerRegistration.php)
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
    private function validateReferrer(?string $referrerFName, ?string $referrerLName, ?int $excludeCustomerId = null): ?int {
        if (!$referrerFName || !$referrerLName) return null;
        $sql = "SELECT id FROM photonim_db.customerregistration WHERE fName = ? AND lName = ?";
        if ($excludeCustomerId) {
            $sql .= " AND id != ?";
        }
        $stmt = $this->pdo->prepare($sql);
        $params = [trim($referrerFName), trim($referrerLName)];
        if ($excludeCustomerId) {
            $params[] = $excludeCustomerId;
        }
        $stmt->execute($params);
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
     * Fetches customer data by ID
     */
    public function getCustomerById(int $id): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT c.*, r.fName AS referrer_fname, r.lName AS referrer_lname 
                FROM photonim_db.customerregistration c 
                LEFT JOIN photonim_db.customerregistration r ON c.referred_by = r.id 
                WHERE c.id = :id
            ");
            $stmt->execute([':id' => $id]);
            $data = $stmt->fetch();
            return $data ? ['success' => true, 'data' => $data] : ['success' => false, 'message' => 'مشتری یافت نشد'];
        } catch (PDOException $e) {
            $this->logError("Fetch Error: " . $e->getMessage(), ['id' => $id]);
            return ['success' => false, 'message' => 'خطا در بازیابی اطلاعات: ' . $e->getMessage()];
        }
    }

    /**
     * Updates customer information, including referrer details
     */
    public function updateCustomer(
        int $id,
        string $fname,
        string $lname,
        string $phone,
        ?string $date = null,
        ?string $description = null,
        ?string $referrerFName = null,
        ?string $referrerLName = null
    ): array {
        try {
            // Input validation
            $fname = trim($fname);
            $lname = trim($lname);
            $phone = trim($phone);
            if (empty($fname) || empty($lname) || empty($phone)) {
                throw new Exception('فیلدهای نام، نام خانوادگی و شماره تماس اجباری هستند.');
            }

            if (!$this->validatePhone($phone)) {
                throw new Exception('شماره تماس باید با ۰۹ شروع شود و ۱۱ رقم باشد.');
            }

            // Check for duplicate phone number (excluding current customer)
            $stmt = $this->pdo->prepare("SELECT id FROM photonim_db.customerregistration WHERE phone = ? AND id != ?");
            $stmt->execute([$phone, $id]);
            if ($stmt->fetch()) {
                throw new Exception('این شماره تماس قبلاً برای مشتری دیگری ثبت شده است.');
            }

            if ($date && !$this->validateDate($date)) {
                throw new Exception('تاریخ تولد نامعتبر است یا نمی‌تواند در آینده باشد.');
            }

            $referredBy = $this->validateReferrer($referrerFName, $referrerLName, $id);

            // Start transaction for data consistency
            $this->pdo->beginTransaction();

            // Fetch current customer data to check for referrer changes
            $currentData = $this->getCustomerById($id);
            if (!$currentData['success']) {
                throw new Exception($currentData['message']);
            }
            $currentReferrerId = $currentData['data']['referred_by'];

            // Update customer details
            $sql = "UPDATE photonim_db.customerregistration 
                    SET fName = ?, lName = ?, phone = ?, date = ?, description = ?, referred_by = ?, updated_at = NOW()
                    WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$fname, $lname, $phone, $date ?: null, $description ?: null, $referredBy, $id]);

            // Handle referral changes
            if ($referredBy !== $currentReferrerId) {
                // Remove old referral record if it exists
                if ($currentReferrerId) {
                    $stmt = $this->pdo->prepare("DELETE FROM photonim_db.referrals WHERE referred_id = ?");
                    $stmt->execute([$id]);

                    // Deduct points from old referrer
                    $stmt = $this->pdo->prepare("
                        UPDATE photonim_db.customerregistration 
                        SET points = GREATEST(points - ?, 0), updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $stmt->execute([REFERRAL_POINTS, $currentReferrerId]);
                    $this->logPoints($currentReferrerId, -REFERRAL_POINTS, 'referral_removed', $id);

                    // Update old referrer's level
                    $this->updateCustomerLevel($currentReferrerId);
                }

                // Add new referral record if new referrer is specified
                if ($referredBy) {
                    $referralSql = "INSERT INTO photonim_db.referrals 
                                  (referrer_id, referred_id, status, created_at) 
                                  VALUES (?, ?, 'pending', NOW())";
                    $stmt = $this->pdo->prepare($referralSql);
                    $stmt->execute([$referredBy, $id]);

                    // Add referral points to new referrer
                    $stmt = $this->pdo->prepare("
                        UPDATE photonim_db.customerregistration 
                        SET points = points + ?, updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $stmt->execute([REFERRAL_POINTS, $referredBy]);
                    $this->logPoints($referredBy, REFERRAL_POINTS, 'referral', $id);

                    // Update new referrer's level
                    $levelUpdate = $this->updateCustomerLevel($referredBy);
                }
            }

            // Commit transaction
            $this->pdo->commit();

            // Prepare success response
            $response = [
                'success' => true,
                'message' => "اطلاعات مشتری $fname $lname با موفقیت به‌روزرسانی شد."
            ];

            if ($referredBy && $referredBy !== $currentReferrerId && isset($levelUpdate) && $levelUpdate['success']) {
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
            $this->logError("Update Error: " . $e->getMessage(), [
                'id' => $id,
                'fname' => $fname,
                'lname' => $lname,
                'phone' => $phone,
                'referrer_fname' => $referrerFName,
                'referrer_lname' => $referrerLName
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
$userData = null;
$errorMessage = null;

// Handle GET request to fetch customer data
if (isset($_GET['Edit'])) {
    $editUserId = filter_input(INPUT_GET, 'Edit', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($editUserId === false || $editUserId === null) {
        $errorMessage = 'شناسه مشتری نامعتبر است.';
    } else {
        $result = $customerManager->getCustomerById($editUserId);
        if ($result['success']) {
            $userData = $result['data'];
        } else {
            $errorMessage = $result['message'];
        }
    }
}

// Handle POST request to update customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update']) && $userData) {
    $fname = filter_input(INPUT_POST, 'updated_fname', FILTER_SANITIZE_STRING) ?: '';
    $lname = filter_input(INPUT_POST, 'updated_lname', FILTER_SANITIZE_STRING) ?: '';
    $phone = filter_input(INPUT_POST, 'updated_phone', FILTER_SANITIZE_STRING) ?: '';
    $date = filter_input(INPUT_POST, 'updated_date', FILTER_SANITIZE_STRING) ?: null;
    $description = filter_input(INPUT_POST, 'updated_description', FILTER_SANITIZE_STRING) ?: null;
    $referrerFName = filter_input(INPUT_POST, 'referrer_fname', FILTER_SANITIZE_STRING) ?: null;
    $referrerLName = filter_input(INPUT_POST, 'referrer_lname', FILTER_SANITIZE_STRING) ?: null;

    $response = $customerManager->updateCustomer(
        $userData['id'],
        $fname,
        $lname,
        $phone,
        $date,
        $description,
        $referrerFName,
        $referrerLName
    );

    if ($response['success']) {
        $_SESSION['message'] = $response['message'];
        if (isset($response['referrer_updated'])) {
            $_SESSION['referrer_message'] = "به معرف جدید " . REFERRAL_POINTS . " امتیاز اضافه شد و سطح جدید: " .
                CUSTOMER_LEVELS[$response['referrer_updated']['new_level']]['label'] .
                ($response['referrer_updated']['level_changed'] ? " (سطح ارتقا یافت)" : "");
        }
        header("Location: Customer%20List.php?updated=success");
        exit;
    } else {
        $errorMessage = $response['message'];
    }
}

// Redirect if no valid customer data or Edit parameter
if (!$userData && !isset($_GET['Edit'])) {
    header("Location: Customer%20List.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="ویرایش حرفه‌ای اطلاعات مشتری در استودیو نیما">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="Image/Logo1.png" type="image/png" sizes="80x80">
    <title>استودیو نیما | ویرایش مشتری</title>
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
            <h1 class="header-title">ویرایش اطلاعات مشتری</h1>
            <div class="header-divider"></div>
        </header>

        <?php if ($userData): ?>
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
                <form method="POST" id="editCustomerForm" class="form-body">
                    <div class="form-group">
                        <label class="form-label" for="updated_fname"><i class="fas fa-user"></i> نام *</label>
                        <input type="text" name="updated_fname" id="updated_fname" class="form-input" 
                               placeholder="مثال: محمد" value="<?php echo htmlspecialchars($userData['fName'] ?? ''); ?>" required>
                        <div class="form-error" id="fname_error"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="updated_lname"><i class="fas fa-user"></i> نام خانوادگی *</label>
                        <input type="text" name="updated_lname" id="updated_lname" class="form-input" 
                               placeholder="مثال: موسوی" value="<?php echo htmlspecialchars($userData['lName'] ?? ''); ?>" required>
                        <div class="form-error" id="lname_error"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="updated_phone"><i class="fas fa-phone"></i> شماره تماس *</label>
                        <input type="tel" name="updated_phone" id="updated_phone" class="form-input" 
                               placeholder="مثال: 09195308703" value="<?php echo htmlspecialchars($userData['phone'] ?? ''); ?>" 
                               pattern="09[0-9]{9}" required title="شماره باید با ۰۹ شروع شود و ۱۱ رقم باشد">
                        <div class="form-error" id="phone_error"></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="updated_date"><i class="fas fa-calendar-alt"></i> تاریخ تولد</label>
                        <input type="date" name="updated_date" id="updated_date" class="form-input" 
                               value="<?php echo htmlspecialchars($userData['date'] ?? ''); ?>" max="<?php echo date('Y-m-d'); ?>">
                        <div class="form-error" id="date_error"></div>
                    </div>
                    <div class="form-group referral-group">
                        <div>
                            <label class="form-label" for="referrer_fname"><i class="fas fa-user-friends"></i> نام معرف (اختیاری)</label>
                            <input type="text" name="referrer_fname" id="referrer_fname" class="form-input" 
                                   placeholder="مثال: علی" value="<?php echo htmlspecialchars($userData['referrer_fname'] ?? ''); ?>">
                            <div class="form-error" id="referrer_fname_error"></div>
                        </div>
                        <div>
                            <label class="form-label" for="referrer_lname"><i class="fas fa-user-friends"></i> نام خانوادگی معرف (اختیاری)</label>
                            <input type="text" name="referrer_lname" id="referrer_lname" class="form-input" 
                                   placeholder="مثال: رضایی" value="<?php echo htmlspecialchars($userData['referrer_lname'] ?? ''); ?>">
                            <div class="form-error" id="referrer_lname_error"></div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="updated_description"><i class="fas fa-comment"></i> توضیحات</label>
                        <textarea name="updated_description" id="updated_description" class="form-textarea" 
                                  placeholder="توضیحات اضافی (اختیاری)"><?php echo htmlspecialchars($userData['description'] ?? ''); ?></textarea>
                    </div>
                    <button type="submit" name="update" class="submit-btn"><i class="fas fa-save"></i> به‌روزرسانی</button>
                </form>
            </section>
        <?php else: ?>
            <div class="form-error active fade-in"><?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('editCustomerForm');

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

                const fname = document.getElementById('updated_fname').value.trim();
                if (!fname) {
                    showError('fname', 'نام کوچک اجباری است.');
                    isValid = false;
                }

                const lname = document.getElementById('updated_lname').value.trim();
                if (!lname) {
                    showError('lname', 'نام خانوادگی اجباری است.');
                    isValid = false;
                }

                const phone = document.getElementById('updated_phone').value.trim();
                if (!phone) {
                    showError('phone', 'شماره تماس اجباری است.');
                    isValid = false;
                } else if (!/^09\d{9}$/.test(phone)) {
                    showError('phone', 'شماره تماس باید با ۰۹ شروع شود و ۱۱ رقم باشد.');
                    isValid = false;
                }

                const date = document.getElementById('updated_date').value;
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

            if (form) {
                form.addEventListener('submit', (e) => {
                    if (!validateForm()) {
                        e.preventDefault();
                        const firstError = document.querySelector('.form-error.active');
                        if (firstError) firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                });
            }
        });
    </script>
</body>
</html>