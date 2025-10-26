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

    public function generateProductCode(): string {
        return 'PRD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }

    public function registerProduct(array $data): array {
        try {
            $requiredFields = ['imagesize' => 'ابعاد محصول'];
            foreach ($requiredFields as $field => $label) {
                if (empty($data[$field])) {
                    throw new Exception("فیلد '$label' اجباری است.");
                }
            }

            $price = (float)($data['price'] ?? 0);
            $default_discount = (float)($data['default_discount'] ?? 0);
            if ($price < 0 || $default_discount < 0) {
                throw new Exception('قیمت و تخفیف نمی‌توانند منفی باشند.');
            }

            $productCode = $this->generateProductCode();
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM photonim_db.pricelist WHERE product_code = ?");
            $stmt->execute([$productCode]);
            while ($stmt->fetchColumn() > 0) {
                $productCode = $this->generateProductCode();
            }

            $sql = "INSERT INTO photonim_db.pricelist (
                product_code, imagesize, type, color, price, default_discount, description, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $productCode,
                trim($data['imagesize']),
                trim($data['type']) ?: null,
                trim($data['color']) ?: null,
                $price,
                $default_discount,
                trim($data['description']) ?: null
            ]);

            $id = $this->pdo->lastInsertId();
            return [
                'success' => true,
                'message' => "محصول با کد $productCode با موفقیت ثبت شد.",
                'product_id' => $id
            ];
        } catch (Exception $e) {
            $this->logError("Product Registration Error: " . $e->getMessage(), ['data' => $data]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function deleteProduct(int $id): array {
        try {
            $stmt = $this->pdo->prepare("SELECT product_code FROM photonim_db.pricelist WHERE id = ?");
            $stmt->execute([$id]);
            $product = $stmt->fetch();
            if (!$product) {
                return ['success' => false, 'message' => 'محصول یافت نشد.'];
            }
            $productCode = $product['product_code'];

            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM photonim_db.order_items WHERE product_id = ?");
            $stmt->execute([$id]);
            $orderCount = (int)$stmt->fetchColumn();

            if ($orderCount > 0) {
                $message = "هشدار: محصول با کد $productCode در $orderCount سفارش استفاده شده است و نمی‌توان آن را حذف کرد.";
                $this->logError("Delete Product Warning: Product has dependencies", [
                    'product_id' => $id,
                    'order_count' => $orderCount
                ]);
                return ['success' => false, 'message' => $message];
            }

            $stmt = $this->pdo->prepare("DELETE FROM photonim_db.pricelist WHERE id = ?");
            $stmt->execute([$id]);

            return [
                'success' => true,
                'message' => "محصول با کد $productCode با موفقیت حذف شد."
            ];
        } catch (PDOException $e) {
            $this->logError("Delete Product Error: " . $e->getMessage(), ['product_id' => $id]);
            return ['success' => false, 'message' => 'خطا در حذف: مشکلی در سیستم رخ داده است.'];
        }
    }

    public function searchProducts(string $keyword = '', string $field = 'all', int $limit = 5, int $offset = 0): array {
        try {
            $fields = ['product_code', 'imagesize', 'type', 'color', 'price', 'default_discount', 'description', 'created_at'];
            $sql = "SELECT * FROM photonim_db.pricelist";
            $params = [];

            if ($keyword !== '') {
                $keyword = trim($keyword);
                $conditions = [];
                $paramIndex = 0;

                if ($field === 'all') {
                    foreach ($fields as $f) {
                        $param = ":kw" . $paramIndex++;
                        $conditions[] = "$f LIKE $param";
                        $params[$param] = "%$keyword%";
                    }
                    $sql .= " WHERE (" . implode(' OR ', $conditions) . ")";
                } elseif (in_array($field, $fields)) {
                    $param = ":kw0";
                    $conditions[] = "$field LIKE $param";
                    $params[$param] = "%$keyword%";
                    $sql .= " WHERE " . implode(' AND ', $conditions);
                }
            }

            $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $param => $value) {
                $stmt->bindValue($param, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return ['success' => true, 'data' => $stmt->fetchAll()];
        } catch (PDOException $e) {
            $this->logError("Search Products Error: " . $e->getMessage(), ['keyword' => $keyword]);
            return ['success' => false, 'message' => 'خطا در جستجو: ' . $e->getMessage()];
        }
    }

    public function getTotalProducts(string $keyword = '', string $field = 'all'): int {
        try {
            $fields = ['product_code', 'imagesize', 'type', 'color', 'price', 'default_discount', 'description', 'created_at'];
            $sql = "SELECT COUNT(*) FROM photonim_db.pricelist";
            $params = [];

            if ($keyword !== '') {
                $keyword = trim($keyword);
                $conditions = [];
                $paramIndex = 0;

                if ($field === 'all') {
                    foreach ($fields as $f) {
                        $param = ":kw" . $paramIndex++;
                        $conditions[] = "$f LIKE $param";
                        $params[$param] = "%$keyword%";
                    }
                    $sql .= " WHERE (" . implode(' OR ', $conditions) . ")";
                } elseif (in_array($field, $fields)) {
                    $param = ":kw0";
                    $conditions[] = "$field LIKE $param";
                    $params[$param] = "%$keyword%";
                    $sql .= " WHERE " . implode(' AND ', $conditions);
                }
            }

            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $param => $value) {
                $stmt->bindValue($param, $value, PDO::PARAM_STR);
            }
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            $this->logError("Count Products Error: " . $e->getMessage(), ['keyword' => $keyword]);
            return 0;
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
    exit("<p style='color: #EF4444; text-align: center; font-family: Vazirmatn;'>خطا: اتصال به دیتابیس برقرار نیست.</p>");
}

$productManager = new ProductManager($conn);
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['insert'])) {
    $data = [
        'imagesize' => filter_input(INPUT_POST, 'imagesize', FILTER_SANITIZE_STRING),
        'type' => filter_input(INPUT_POST, 'type', FILTER_SANITIZE_STRING),
        'color' => filter_input(INPUT_POST, 'color', FILTER_SANITIZE_STRING),
        'price' => filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT),
        'default_discount' => filter_input(INPUT_POST, 'default_discount', FILTER_VALIDATE_FLOAT) ?: 0.0,
        'description' => filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING)
    ];

    $response = $productManager->registerProduct($data);
    if ($response['success']) {
        $_SESSION['message'] = $response['message'];
        $queryParams = "?page=" . (isset($_GET['page']) ? $_GET['page'] : 1) . (isset($_GET['query']) ? "&query=" . urlencode($_GET['query']) . "&search_field=" . urlencode($_GET['search_field'] ?? 'all') : "");
        header("Location: " . $_SERVER['PHP_SELF'] . $queryParams);
        exit;
    } else {
        $errorMessage = $response['message'];
    }
}

$actions = [
    'Dele' => fn($id) => $productManager->deleteProduct($id),
    'Edit' => fn($id) => header("Location: EditProducts.php?Edit=$id")
];

foreach ($actions as $key => $action) {
    if (isset($_GET[$key]) && filter_var($_GET[$key], FILTER_VALIDATE_INT)) {
        $id = (int)$_GET[$key];
        $response = $action($id);
        if ($response && ($response['success'] ?? false)) {
            $queryParams = "?page=" . (isset($_GET['page']) ? $_GET['page'] : 1) . (isset($_GET['query']) ? "&query=" . urlencode($_GET['query']) . "&search_field=" . urlencode($_GET['search_field'] ?? 'all') : "");
            header("Location: " . $_SERVER['PHP_SELF'] . $queryParams);
            exit;
        }
        break;
    }
}

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?? 1;
$limit = 5;
$offset = ($page - 1) * $limit;
$searchKeyword = filter_input(INPUT_GET, 'query', FILTER_UNSAFE_RAW) ?? '';
$searchKeyword = htmlspecialchars($searchKeyword, ENT_QUOTES, 'UTF-8');

$searchField = filter_input(INPUT_GET, 'search_field', FILTER_UNSAFE_RAW) ?? 'all';
$searchField = htmlspecialchars($searchField, ENT_QUOTES, 'UTF-8');

$searchResult = $productManager->searchProducts($searchKeyword, $searchField, $limit, $offset);
$products = $searchResult['success'] ? $searchResult['data'] : [];
$totalProducts = $productManager->getTotalProducts($searchKeyword, $searchField);
$totalPages = ceil($totalProducts / $limit);
$types = $productManager->getTypes();
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="مدیریت حرفه‌ای محصولات استودیو نیما">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="Image/Logo1.png" type="image/png" sizes="80x80">
    <title>استودیو نیما | لیست محصولات</title>
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
            --order-id-bg: rgba(37, 99, 235, 0.1);
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
            transition: var(--transition);
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

        .form-section, .search-section {
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

        .search-form {
            display: flex;
            gap: calc(var(--spacing-unit) * 0.5);
            align-items: center;
            flex-wrap: wrap;
        }

        .search-select {
            min-width: 180px;
            padding: 8px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 0.9rem;
            transition: var(--transition);
            box-shadow: var(--shadow);
            background: var(--secondary-bg) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='%236B7280' viewBox='0 0 20 20'%3E%3Cpath d='M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z'/%3E%3C/svg%3E") no-repeat left 10px center;
            background-size: 14px;
            padding-left: 32px;
            appearance: none;
        }

        .search-input {
            flex: 1;
            min-width: 220px;
            padding: 8px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 0.9rem;
            transition: var(--transition);
            box-shadow: var(--shadow);
        }

        .search-select:focus, .search-input:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 6px rgba(37, 99, 235, 0.2);
        }

        .search-btn, .submit-btn {
            padding: 8px 16px;
            border-radius: var(--radius);
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--secondary-bg);
            transition: var(--transition);
            border: none;
            cursor: pointer;
            box-shadow: var(--shadow);
        }

        .submit-btn {
            background: var(--success);
        }

        .search-btn {
            background: var(--accent);
        }

        .submit-btn:hover {
            background: var(--success-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }

        .search-btn:hover {
            background: var(--accent-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }

        .products-section {
            display: grid;
            gap: var(--spacing-unit);
        }

        .product-card {
            background: var(--secondary-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: var(--spacing-unit);
            transition: var(--transition);
            border: 1px solid var(--border);
        }

        .product-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
            background: var(--card-hover);
        }

        .product-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: calc(var(--spacing-unit) * 0.75);
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
            gap: 8px;
        }

        .product-id {
            font-size: 1rem;
            font-weight: 600;
            padding: 6px 12px;
            background: var(--order-id-bg);
            color: var(--accent);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .product-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: calc(var(--spacing-unit) * 0.75);
            padding: var(--spacing-unit) 0;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .detail-label {
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .detail-value {
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .product-actions {
            display: flex;
            gap: calc(var(--spacing-unit) * 0.5);
            justify-content: flex-end;
            padding-top: calc(var(--spacing-unit) * 0.75);
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 6px 14px;
            border-radius: var(--radius);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.85rem;
            color: var(--secondary-bg);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
            border: none;
            cursor: pointer;
            box-shadow: var(--shadow);
        }

        .action-btn.delete { background: var(--danger); }
        .action-btn.edit { background: var(--success); }
        .action-btn.details { background: var(--warning); }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }

        .action-btn.delete:hover { background: var(--danger-hover); }
        .action-btn.edit:hover { background: var(--success-hover); }
        .action-btn.details:hover { background: var(--warning-hover); }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--modal-bg);
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease-out;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.98);
            background: var(--secondary-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow-hover);
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            z-index: 1001;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .modal.active {
            opacity: 1;
            visibility: visible;
            transform: translate(-50%, -50%) scale(1);
        }

        .modal-header {
            background: var(--accent);
            color: var(--secondary-bg);
            padding: calc(var(--spacing-unit) * 0.75) var(--spacing-unit);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: var(--radius) var(--radius) 0 0;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .modal-close {
            background: var(--secondary-bg);
            color: var(--text-dark);
            border: none;
            padding: 6px 12px;
            border-radius: var(--radius);
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .modal-close:hover {
            background: var(--warning);
            color: var(--secondary-bg);
        }

        .modal-body {
            padding: var(--spacing-unit);
        }

        .modal-product-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: calc(var(--spacing-unit) * 0.75);
            padding: calc(var(--spacing-unit) * 0.75);
            background: var(--card-hover);
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .modal-detail-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .modal-detail-label {
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .modal-detail-value {
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: calc(var(--spacing-unit) * 0.75);
            padding: calc(var(--spacing-unit) * 1.5) 0;
            flex-wrap: wrap;
        }

        .pagination-btn {
            padding: 8px 16px;
            background: var(--accent);
            color: var(--secondary-bg);
            border-radius: var(--radius);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: var(--shadow);
        }

        .pagination-btn:hover {
            background: var(--accent-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }

        .pagination-btn.disabled {
            background: var(--border);
            color: var(--text-muted);
            pointer-events: none;
            box-shadow: none;
        }

        .pagination-info {
            font-weight: 500;
            color: var(--text-dark);
            padding: 8px 14px;
            background: var(--secondary-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .no-results {
            text-align: center;
            padding: var(--spacing-unit);
            background: var(--secondary-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            color: var(--text-muted);
            font-size: 1rem;
            margin: calc(var(--spacing-unit) * 1.5) 0;
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
            .product-details, .modal-product-info { grid-template-columns: repeat(2, 1fr); }
            .modal { max-width: 700px; }
            .search-select, .search-input { min-width: 200px; }
        }

        @media (max-width: 768px) {
            .nav-content { gap: calc(var(--spacing-unit) * 0.25); padding: 0 8px; }
            .nav-btn, .nav-logo { padding: 6px 12px; font-size: 0.85rem; }
            .form-body { grid-template-columns: 1fr; }
            .search-form { flex-direction: column; }
            .search-select, .search-input { width: 100%; }
            .product-details, .modal-product-info { grid-template-columns: 1fr; }
            .product-actions { flex-direction: column; align-items: flex-end; }
            .product-header { flex-direction: column; align-items: flex-start; }
            .modal { width: 95%; }
            .header-title { font-size: 1.75rem; }
        }

        @media (max-width: 480px) {
            .pagination { flex-direction: column; gap: calc(var(--spacing-unit) * 0.5); }
            .pagination-btn, .pagination-info { width: 100%; text-align: center; }
            .action-btn { width: 100%; justify-content: center; }
            .submit-btn { width: 100%; }
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
            <h1 class="header-title">ثبت محصول جدید</h1>
            <div class="header-divider"></div>
        </header>

        <section class="form-section fade-in">
            <form method="POST" id="productForm" class="form-body">
                <div class="form-group">
                    <label class="form-label" for="imagesize"><i class="fas fa-ruler"></i> ابعاد محصول *</label>
                    <input type="text" name="imagesize" id="imagesize" class="form-input" placeholder="مثال: 15×21" required>
                    <div class="form-error" id="imagesize_error"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="type"><i class="fas fa-tag"></i> نوع</label>
                    <input type="text" name="type" id="type" class="form-input" placeholder="مثال: قاب" list="typeList">
                    <datalist id="typeList">
                        <?php foreach ($types as $type): ?>
                            <option value="<?= htmlspecialchars($type) ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <div class="form-error" id="type_error"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="color"><i class="fas fa-palette"></i> رنگ</label>
                    <input type="text" name="color" id="color" class="form-input" placeholder="مثال: سفید">
                    <div class="form-error" id="color_error"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="price"><i class="fas fa-money-bill"></i> قیمت (تومان)</label>
                    <input type="number" name="price" id="price" class="form-input" placeholder="مثال: 150000" min="0" step="0.01">
                    <div class="form-error" id="price_error"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="default_discount"><i class="fas fa-percentage"></i> تخفیف پیش‌فرض (تومان)</label>
                    <input type="number" name="default_discount" id="default_discount" class="form-input" value="0" min="0" step="0.01">
                    <div class="form-error" id="default_discount_error"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="description"><i class="fas fa-comment"></i> توضیحات</label>
                    <textarea name="description" id="description" class="form-textarea" placeholder="توضیحات محصول (اختیاری)"></textarea>
                    <div class="form-error" id="description_error"></div>
                </div>

                <button type="submit" name="insert" class="submit-btn"><i class="fas fa-check"></i> ثبت محصول</button>
            </form>
            <?php if (isset($errorMessage)): ?>
                <div class="form-error active fade-in"><?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>
        </section>

        <header class="header fade-in">
            <h1 class="header-title">لیست محصولات</h1>
            <div class="header-divider"></div>
        </header>

        <section class="search-section fade-in">
            <form method="GET" class="search-form">
                <select name="search_field" class="search-select">
                    <option value="all" <?= $searchField === 'all' ? 'selected' : '' ?>>همه</option>
                    <option value="product_code" <?= $searchField === 'product_code' ? 'selected' : '' ?>>کد محصول</option>
                    <option value="imagesize" <?= $searchField === 'imagesize' ? 'selected' : '' ?>>اندازه</option>
                    <option value="type" <?= $searchField === 'type' ? 'selected' : '' ?>>نوع</option>
                    <option value="color" <?= $searchField === 'color' ? 'selected' : '' ?>>رنگ</option>
                    <option value="price" <?= $searchField === 'price' ? 'selected' : '' ?>>قیمت</option>
                    <option value="default_discount" <?= $searchField === 'default_discount' ? 'selected' : '' ?>>تخفیف پیش‌فرض</option>
                    <option value="description" <?= $searchField === 'description' ? 'selected' : '' ?>>توضیحات</option>
                    <option value="created_at" <?= $searchField === 'created_at' ? 'selected' : '' ?>>تاریخ ثبت</option>
                </select>
                <input type="text" name="query" class="search-input" value="<?= htmlspecialchars($searchKeyword) ?>" placeholder="جستجو در محصولات">
                <button type="submit" class="search-btn"><i class="fas fa-search"></i> جستجو</button>
            </form>
        </section>

        <section class="products-section fade-in">
            <?php if (!empty($products)): ?>
                <?php foreach ($products as $product): ?>
                    <div class="product-card">
                        <div class="product-header">
                            <span class="product-id"><i class="fas fa-id-badge"></i> <?= htmlspecialchars($product['product_code']) ?></span>
                        </div>
                        <div class="product-details">
                            <div class="detail-item">
                                <span class="detail-label">اندازه</span>
                                <span class="detail-value"><i class="fas fa-ruler"></i> <?= htmlspecialchars($product['imagesize']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">نوع</span>
                                <span class="detail-value"><i class="fas fa-tag"></i> <?= htmlspecialchars($product['type'] ?? '-') ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">رنگ</span>
                                <span class="detail-value"><i class="fas fa-palette"></i> <?= htmlspecialchars($product['color'] ?? '-') ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">قیمت</span>
                                <span class="detail-value"><i class="fas fa-money-bill"></i> <?= number_format($product['price']) ?> ت</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">تخفیف پیش‌فرض</span>
                                <span class="detail-value"><i class="fas fa-percentage"></i> <?= number_format($product['default_discount']) ?> ت</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">تاریخ ثبت</span>
                                <span class="detail-value"><i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($product['created_at']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">توضیحات</span>
                                <span class="detail-value"><i class="fas fa-comment"></i> <?= htmlspecialchars($product['description'] ?? '-') ?></span>
                            </div>
                        </div>
                        <div class="product-actions">
                            <a href="?Dele=<?= $product['id'] ?>" class="action-btn delete" onclick="return confirm('آیا از حذف محصول با کد <?= $product['product_code'] ?> مطمئن هستید؟')"><i class="fas fa-trash"></i> حذف</a>
                            <a href="?Edit=<?= $product['id'] ?>" class="action-btn edit"><i class="fas fa-edit"></i> ویرایش</a>
                            <button class="action-btn details" data-product='<?= json_encode($product, JSON_UNESCAPED_UNICODE) ?>'><i class="fas fa-eye"></i> جزئیات</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-results">هیچ محصولی با شرایط جستجو یافت نشد!</div>
            <?php endif; ?>
        </section>

        <?php if ($totalPages > 1): ?>
            <div class="pagination fade-in">
                <a href="?page=<?= $page - 1 ?>&query=<?= urlencode($searchKeyword) ?>&search_field=<?= urlencode($searchField) ?>" 
                   class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>"><i class="fas fa-chevron-right"></i> قبلی</a>
                <span class="pagination-info">صفحه <?= $page ?> از <?= $totalPages ?> (<?= $totalProducts ?> محصول)</span>
                <a href="?page=<?= $page + 1 ?>&query=<?= urlencode($searchKeyword) ?>&search_field=<?= urlencode($searchField) ?>" 
                   class="pagination-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">بعدی <i class="fas fa-chevron-left"></i></a>
            </div>
        <?php endif; ?>
    </div>

    <div class="modal-overlay"></div>
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title"><i class="fas fa-info-circle"></i> جزئیات محصول <span id="modal-product-code"></span></span>
            <button class="modal-close"><i class="fas fa-times"></i> بستن</button>
        </div>
        <div class="modal-body">
            <div class="modal-product-info">
                <div class="modal-detail-item">
                    <span class="modal-detail-label">اندازه</span>
                    <span class="modal-detail-value"><i class="fas fa-ruler"></i> <span id="modal-product-imagesize"></span></span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">نوع</span>
                    <span class="modal-detail-value"><i class="fas fa-tag"></i> <span id="modal-product-type"></span></span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">رنگ</span>
                    <span class="modal-detail-value"><i class="fas fa-palette"></i> <span id="modal-product-color"></span></span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">قیمت</span>
                    <span class="modal-detail-value"><i class="fas fa-money-bill"></i> <span id="modal-product-price"></span></span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">تخفیف پیش‌فرض</span>
                    <span class="modal-detail-value"><i class="fas fa-percentage"></i> <span id="modal-product-discount"></span></span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">تاریخ ثبت</span>
                    <span class="modal-detail-value"><i class="fas fa-calendar-alt"></i> <span id="modal-product-date"></span></span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">توضیحات</span>
                    <span class="modal-detail-value"><i class="fas fa-comment"></i> <span id="modal-product-description"></span></span>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const overlay = document.querySelector('.modal-overlay');
            const modal = document.querySelector('.modal');
            const modalProductCode = document.getElementById('modal-product-code');
            const modalProductImagesize = document.getElementById('modal-product-imagesize');
            const modalProductType = document.getElementById('modal-product-type');
            const modalProductColor = document.getElementById('modal-product-color');
            const modalProductPrice = document.getElementById('modal-product-price');
            const modalProductDiscount = document.getElementById('modal-product-discount');
            const modalProductDate = document.getElementById('modal-product-date');
            const modalProductDescription = document.getElementById('modal-product-description');
            const closeBtn = document.querySelector('.modal-close');
            const detailBtns = document.querySelectorAll('.action-btn.details');
            const form = document.getElementById('productForm');

            function openModal(product) {
                overlay.classList.add('active');
                modal.classList.add('active');
                modalProductCode.textContent = product.product_code;
                modalProductImagesize.textContent = product.imagesize;
                modalProductType.textContent = product.type || '-';
                modalProductColor.textContent = product.color || '-';
                modalProductPrice.textContent = Number(product.price).toLocaleString('fa-IR') + ' ت';
                modalProductDiscount.textContent = Number(product.default_discount).toLocaleString('fa-IR') + ' ت';
                modalProductDate.textContent = product.created_at;
                modalProductDescription.textContent = product.description || '-';
            }

            function closeModal() {
                overlay.classList.remove('active');
                modal.classList.remove('active');
            }

            detailBtns.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const product = JSON.parse(btn.dataset.product);
                    openModal(product);
                });
            });

            closeBtn.addEventListener('click', closeModal);
            overlay.addEventListener('click', closeModal);

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && modal.classList.contains('active')) {
                    closeModal();
                }
            });

            // Form validation
            form.addEventListener('submit', (e) => {
                let isValid = true;
                const errors = {};

                const imagesize = document.getElementById('imagesize');
                if (!imagesize.value.trim()) {
                    errors.imagesize = 'ابعاد محصول اجباری است';
                    isValid = false;
                }

                const price = document.getElementById('price');
                if (price.value && parseFloat(price.value) < 0) {
                    errors.price = 'قیمت نمی‌تواند منفی باشد';
                    isValid = false;
                }

                const defaultDiscount = document.getElementById('default_discount');
                if (defaultDiscount.value && parseFloat(defaultDiscount.value) < 0) {
                    errors.default_discount = 'تخفیف نمی‌تواند منفی باشد';
                    isValid = false;
                }

                // Display errors
                Object.keys(errors).forEach(key => {
                    const errorEl = document.getElementById(`${key}_error`);
                    if (errorEl) {
                        errorEl.textContent = errors[key];
                        errorEl.classList.add('active');
                        setTimeout(() => errorEl.classList.remove('active'), 5000);
                    }
                });

                if (!isValid) {
                    e.preventDefault();
                    const firstError = document.querySelector('.form-error.active');
                    if (firstError) firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        });
    </script>
</body>
</html>