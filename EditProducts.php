<?php
session_start();
require_once 'DB Config.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

class ProductManager {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    private function logError(string $message, array $context = []): void {
        $logMessage = date('Y-m-d H:i:s') . " - $message";
        if ($context) {
            $logMessage .= " | Context: " . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        file_put_contents('error.log', "$logMessage\n", FILE_APPEND);
    }

    public function getProductById(int $id): array {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM photonim_db.pricelist WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $data = $stmt->fetch();
            return $data ? ['success' => true, 'data' => $data] : ['success' => false, 'message' => 'محصول یافت نشد'];
        } catch (PDOException $e) {
            $this->logError("Fetch Product Error: " . $e->getMessage(), ['id' => $id]);
            return ['success' => false, 'message' => 'خطا در بازیابی اطلاعات: ' . $e->getMessage()];
        }
    }

    public function updateProduct(int $id, string $imagesize, ?string $type = null, ?string $color = null, ?float $price = null, ?float $default_discount = null, ?string $description = null): array {
        try {
            // Mandatory field validation
            $imagesize = trim($imagesize);
            if (empty($imagesize)) {
                throw new Exception('ابعاد محصول اجباری است.');
            }

            // Optional field sanitization
            $type = $type ? trim($type) : null;
            $color = $color ? trim($color) : null;
            $description = $description ? trim($description) : null;

            // Price and discount validation
            if ($price !== null && $price < 0) {
                throw new Exception('قیمت نمی‌تواند منفی باشد.');
            }
            if ($default_discount !== null && $default_discount < 0) {
                throw new Exception('تخفیف نمی‌تواند منفی باشد.');
            }

            // Fetch existing product code for messaging
            $stmt = $this->pdo->prepare("SELECT product_code FROM photonim_db.pricelist WHERE id = ?");
            $stmt->execute([$id]);
            $product = $stmt->fetch();
            if (!$product) {
                throw new Exception('محصول یافت نشد.');
            }
            $productCode = $product['product_code'];

            // Update product in database
            $sql = "UPDATE photonim_db.pricelist 
                    SET imagesize = ?, type = ?, color = ?, price = ?, default_discount = ?, description = ?
                    WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$imagesize, $type, $color, $price, $default_discount, $description, $id]);

            return [
                'success' => true,
                'message' => "محصول با کد $productCode با موفقیت به‌روزرسانی شد."
            ];
        } catch (Exception $e) {
            $this->logError("Update Product Error: " . $e->getMessage(), [
                'id' => $id,
                'imagesize' => $imagesize,
                'type' => $type,
                'color' => $color,
                'price' => $price,
                'default_discount' => $default_discount
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getTypes(): array {
        try {
            $stmt = $this->pdo->query("SELECT DISTINCT type FROM photonim_db.pricelist WHERE type IS NOT NULL ORDER BY type");
            return array_column($stmt->fetchAll(), 'type');
        } catch (PDOException $e) {
            $this->logError("Get Types Error: " . $e->getMessage());
            return [];
        }
    }
}

if (!isset($conn) || !$conn instanceof PDO) {
    http_response_code(500);
    exit("<p style='color: #EF4444; text-align: center; font-family: Vazirmatn;'>خطا: اتصال به دیتابیس برقرار نیست</p>");
}

$productManager = new ProductManager($conn);
$productData = null;
$errorMessage = null;

if (isset($_GET['Edit'])) {
    $editProductId = filter_input(INPUT_GET, 'Edit', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($editProductId === false || $editProductId === null) {
        $errorMessage = 'شناسه محصول نامعتبر است.';
    } else {
        $result = $productManager->getProductById($editProductId);
        if ($result['success']) {
            $productData = $result['data'];
        } else {
            $errorMessage = $result['message'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update']) && $productData) {
    $imagesize = filter_input(INPUT_POST, 'updated_imagesize', FILTER_SANITIZE_STRING) ?: '';
    $type = filter_input(INPUT_POST, 'updated_type', FILTER_SANITIZE_STRING) ?: null;
    $color = filter_input(INPUT_POST, 'updated_color', FILTER_SANITIZE_STRING) ?: null;
    $price = filter_input(INPUT_POST, 'updated_price', FILTER_VALIDATE_FLOAT) ?: null;
    $default_discount = filter_input(INPUT_POST, 'updated_default_discount', FILTER_VALIDATE_FLOAT) ?: null;
    $description = filter_input(INPUT_POST, 'updated_description', FILTER_SANITIZE_STRING) ?: null;

    $response = $productManager->updateProduct($productData['id'], $imagesize, $type, $color, $price, $default_discount, $description);
    if ($response['success']) {
        $_SESSION['message'] = $response['message'];
        header("Location: Products%20List.php");
        exit;
    } else {
        $errorMessage = $response['message'];
    }
}

if (!$productData && !isset($_GET['Edit'])) {
    header("Location: Products%20List.php");
    exit;
}

$types = $productManager->getTypes();
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="ویرایش حرفه‌ای محصولات استودیو نیما">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="Image/Logo1.png" type="image/png" sizes="80x80">
    <title>استودیو نیما | ویرایش محصول (کد: <?= htmlspecialchars($productData['product_code'] ?? '') ?>)</title>
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

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 1024px) {
            .form-body { grid-template-columns: repeat(2, 1fr); }
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
            <h1 class="header-title">ویرایش محصول (کد: <?= htmlspecialchars($productData['product_code'] ?? '') ?>)</h1>
            <div class="header-divider"></div>
        </header>

        <?php if ($productData): ?>
            <section class="form-section fade-in">
                <form method="POST" id="editProductForm" class="form-body">
                    <div class="form-group">
                        <label class="form-label" for="updated_imagesize"><i class="fas fa-ruler"></i> ابعاد محصول *</label>
                        <input type="text" name="updated_imagesize" id="updated_imagesize" class="form-input" 
                               placeholder="مثال: 15×21" value="<?= htmlspecialchars($productData['imagesize'] ?? '') ?>" required>
                        <div class="form-error" id="imagesize_error"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="updated_type"><i class="fas fa-tag"></i> نوع</label>
                        <input type="text" name="updated_type" id="updated_type" class="form-input" 
                               placeholder="مثال: قاب" value="<?= htmlspecialchars($productData['type'] ?? '') ?>" list="typeList">
                        <datalist id="typeList">
                            <?php foreach ($types as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <div class="form-error" id="type_error"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="updated_color"><i class="fas fa-palette"></i> رنگ</label>
                        <input type="text" name="updated_color" id="updated_color" class="form-input" 
                               placeholder="مثال: سفید" value="<?= htmlspecialchars($productData['color'] ?? '') ?>">
                        <div class="form-error" id="color_error"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="updated_price"><i class="fas fa-money-bill"></i> قیمت (تومان)</label>
                        <input type="number" name="updated_price" id="updated_price" class="form-input" 
                               placeholder="مثال: 150000" value="<?= htmlspecialchars($productData['price'] ?? '') ?>" min="0" step="0.01">
                        <div class="form-error" id="price_error"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="updated_default_discount"><i class="fas fa-percentage"></i> تخفیف پیش‌فرض (تومان)</label>
                        <input type="number" name="updated_default_discount" id="updated_default_discount" class="form-input" 
                               placeholder="مثال: 5000" value="<?= htmlspecialchars($productData['default_discount'] ?? '0') ?>" min="0" step="0.01">
                        <div class="form-error" id="default_discount_error"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="updated_description"><i class="fas fa-comment"></i> توضیحات</label>
                        <textarea name="updated_description" id="updated_description" class="form-textarea" 
                                  placeholder="توضیحات محصول (اختیاری)"><?= htmlspecialchars($productData['description'] ?? '') ?></textarea>
                        <div class="form-error" id="description_error"></div>
                    </div>

                    <button type="submit" name="update" class="submit-btn"><i class="fas fa-save"></i> به‌روزرسانی</button>
                </form>
                <?php if (isset($errorMessage)): ?>
                    <div class="form-error active fade-in"><?= htmlspecialchars($errorMessage) ?></div>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <div class="form-error active fade-in"><?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('editProductForm');

            function showError(elementId, message) {
                const errorElement = document.getElementById(`${elementId}_error`);
                errorElement.textContent = message;
                errorElement.classList.add('active');
                setTimeout(() => errorElement.classList.remove('active'), 5000);
            }

            function validateForm() {
                let isValid = true;

                const imagesize = document.getElementById('updated_imagesize').value.trim();
                if (!imagesize) {
                    showError('imagesize', 'ابعاد محصول اجباری است');
                    isValid = false;
                }

                const price = document.getElementById('updated_price').value;
                if (price && parseFloat(price) < 0) {
                    showError('price', 'قیمت نمی‌تواند منفی باشد');
                    isValid = false;
                }

                const defaultDiscount = document.getElementById('updated_default_discount').value;
                if (defaultDiscount && parseFloat(defaultDiscount) < 0) {
                    showError('default_discount', 'تخفیف نمی‌تواند منفی باشد');
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