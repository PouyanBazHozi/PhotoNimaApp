<?php
session_start();
require_once 'DB Config.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

const MIN_QUANTITY = 1;
const ORDER_STATUSES = [
    'pending' => 'در انتظار',
    'in_progress' => 'در حال انجام',
    'completed' => 'تکمیل شده',
    'canceled' => 'لغو شده'
];
const POINTS_PER_TOMAN = 0.001; // 1 point per 1000 Tomans (configurable)

class OrderManager {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    private function logError(string $message, array $context = []): void {
        $logMessage = date('Y-m-d H:i:s') . " - $message";
        if ($context) {
            $logMessage .= " | Context: " . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        file_put_contents('error.log', "$logMessage\n", FILE_APPEND);
    }

    private function customerExists(int $customerId): bool {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM photonim_db.customerregistration WHERE id = ?");
        $stmt->execute([$customerId]);
        return $stmt->fetchColumn() > 0;
    }

    private function validateDate(string $date): bool {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    private function updateCustomerLevel(int $customerId): void {
        $stmt = $this->pdo->prepare("SELECT points FROM photonim_db.customerregistration WHERE id = ?");
        $stmt->execute([$customerId]);
        $points = $stmt->fetchColumn();

        if ($points === false) return;

        $level = 'bronze';
        if ($points >= 5000) {
            $level = 'gold';
        } elseif ($points >= 1000) {
            $level = 'silver';
        }

        $stmt = $this->pdo->prepare("UPDATE photonim_db.customerregistration SET level = ? WHERE id = ?");
        $stmt->execute([$level, $customerId]);
    }

    private function awardPoints(int $customerId, float $total): int {
        $points = (int)($total * POINTS_PER_TOMAN);
        $stmt = $this->pdo->prepare("UPDATE photonim_db.customerregistration SET points = points + ? WHERE id = ?");
        $stmt->execute([$points, $customerId]);
        $this->logError("Points Awarded: $points points added to customer ID $customerId", [
            'total' => $total,
            'points_per_toman' => POINTS_PER_TOMAN
        ]);
        return $points;
    }

    public function getOrderById(int $id): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT o.*, c.fname, c.lname, c.phone 
                FROM photonim_db.`order` o 
                LEFT JOIN photonim_db.customerregistration c ON o.customer_id = c.id 
                WHERE o.id = ?
            ");
            $stmt->execute([$id]);
            $order = $stmt->fetch();

            if (!$order) {
                return ['success' => false, 'message' => "سفارش با کد $id یافت نشد."];
            }

            $stmt = $this->pdo->prepare("
                SELECT oi.product_id, oi.quantity, oi.unit_price, p.product_code, p.imagesize, p.type, p.color, p.price 
                FROM photonim_db.order_items oi 
                JOIN photonim_db.pricelist p ON oi.product_id = p.id 
                WHERE oi.order_id = ?
            ");
            $stmt->execute([$id]);
            $items = $stmt->fetchAll();

            return ['success' => true, 'data' => array_merge($order, ['items' => $items])];
        } catch (PDOException $e) {
            $this->logError("Get Order Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'خطا در بازیابی سفارش: ' . $e->getMessage()];
        }
    }

    public function updateOrder(int $id, array $data, array $items): array {
        try {
            $requiredFields = [
                'customer_id' => 'انتخاب مشتری',
                'delivery_date' => 'موعد تحویل',
                'order_date' => 'تاریخ سفارش',
                'payment' => 'پرداختی',
                'status' => 'وضعیت سفارش'
            ];
            foreach ($requiredFields as $field => $label) {
                if (empty($data[$field]) && $data[$field] !== '0') {
                    throw new Exception("فیلد '$label' اجباری است.");
                }
            }

            $customerId = (int)$data['customer_id'];
            if (!$this->customerExists($customerId)) {
                throw new Exception("مشتری با ID $customerId وجود ندارد.");
            }

            if (!$this->validateDate($data['order_date'])) {
                throw new Exception('تاریخ سفارش نامعتبر است.');
            }

            if (empty($items)) {
                throw new Exception('حداقل یک محصول باید انتخاب شود.');
            }

            $discount = (float)($data['discount'] ?? 0.0);
            $payment = (float)$data['payment'];
            if ($discount < 0 || $payment < 0) {
                throw new Exception('تخفیف و پرداختی نمی‌توانند منفی باشند.');
            }

            $subtotal = 0.0;
            $productIds = array_column($items, 'product_id');
            $stmt = $this->pdo->prepare("SELECT id, price FROM photonim_db.pricelist WHERE id IN (" . implode(',', array_fill(0, count($productIds), '?')) . ")");
            $stmt->execute($productIds);
            $prices = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            foreach ($items as $index => $item) {
                $quantity = (int)$item['quantity'];
                if ($quantity < MIN_QUANTITY) {
                    throw new Exception("تعداد در ردیف " . ($index + 1) . " باید حداقل " . MIN_QUANTITY . " باشد.");
                }
                $productId = (int)$item['product_id'];
                if (!isset($prices[$productId])) {
                    throw new Exception("محصول با ID $productId یافت نشد.");
                }
                $price = $prices[$productId];
                $subtotal += $price * $quantity;
            }

            $total = max($subtotal - $discount, 0);
            $balance = $total - $payment;

            $this->pdo->beginTransaction();

            $sql = "UPDATE photonim_db.`order` SET 
                customer_id = ?, subtotal = ?, description = ?, delivery_date = ?, 
                discount = ?, payment = ?, total = ?, balance = ?, order_date = ?, 
                status = ?, updated_at = NOW() 
                WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $customerId, $subtotal, $data['description'] ?? null, $data['delivery_date'],
                $discount, $payment, $total, $balance, $data['order_date'], $data['status'], $id
            ]);

            $this->pdo->prepare("DELETE FROM photonim_db.order_items WHERE order_id = ?")->execute([$id]);

            $itemSql = "INSERT INTO photonim_db.order_items (order_id, product_id, quantity, unit_price, subtotal) 
                        VALUES (?, ?, ?, ?, ?)";
            $itemStmt = $this->pdo->prepare($itemSql);
            foreach ($items as $item) {
                $price = $prices[$item['product_id']];
                $subtotalItem = $price * $item['quantity'];
                $itemStmt->execute([$id, $item['product_id'], $item['quantity'], $price, $subtotalItem]);
            }

            $pointsMessage = '';
            if ($data['status'] === 'completed') {
                $pointsEarned = $this->awardPoints($customerId, $total);
                $this->updateCustomerLevel($customerId);
                $pointsMessage = " و $pointsEarned امتیاز به مشتری اختصاص یافت.";
            }

            $this->pdo->commit();
            return [
                'success' => true,
                'message' => "سفارش با کد $id با موفقیت به‌روزرسانی شد!" . $pointsMessage,
                'order_id' => $id,
                'total' => $total
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->logError("Update Order Error: " . $e->getMessage(), ['data' => $data, 'items' => $items]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getProducts(): array {
        try {
            $stmt = $this->pdo->query("SELECT id, product_code, imagesize, type, color, price FROM photonim_db.pricelist ORDER BY imagesize");
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            $this->logError("Get Products Error: " . $e->getMessage());
            return [];
        }
    }

    public function searchCustomers(string $query): array {
        $query = trim($query);
        if (empty($query)) return ['results' => []];
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, fname, lname, phone 
                FROM photonim_db.customerregistration 
                WHERE id LIKE ? OR CONCAT(fname, ' ', lname) LIKE ? OR phone LIKE ? 
                ORDER BY lname, fname 
                LIMIT 10
            ");
            $stmt->execute(["%$query%", "%$query%", "%$query%"]);
            return ['results' => array_map(function ($row) {
                return [
                    'id' => $row['id'],
                    'display' => htmlspecialchars("$row[id] - $row[fname] $row[lname] ($row[phone])"),
                    'name' => htmlspecialchars("$row[fname] $row[lname]"),
                    'phone' => htmlspecialchars($row['phone'])
                ];
            }, $stmt->fetchAll())];
        } catch (PDOException $e) {
            $this->logError("Search Customers Error: " . $e->getMessage());
            return ['results' => []];
        }
    }

    public function getCustomerById(int $id): array {
        try {
            $stmt = $this->pdo->prepare("SELECT id, fname, lname, phone FROM photonim_db.customerregistration WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) return ['data' => null];
            return [
                'data' => [
                    'id' => $row['id'],
                    'display' => htmlspecialchars("$row[id] - $row[fname] $row[lname] ($row[phone])"),
                    'name' => htmlspecialchars("$row[fname] $row[lname]"),
                    'phone' => htmlspecialchars($row['phone'])
                ]
            ];
        } catch (PDOException $e) {
            $this->logError("Get Customer Error: " . $e->getMessage());
            return ['data' => null];
        }
    }
}

if (!isset($conn) || !$conn instanceof PDO) {
    http_response_code(500);
    exit("<p style='color: #EF4444; text-align: center; font-family: Vazirmatn;'>خطا: اتصال به دیتابیس برقرار نیست</p>");
}

$orderManager = new OrderManager($conn);
$editOrderId = filter_input(INPUT_GET, 'Edit', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$userData = null;
$orderItems = [];

if ($editOrderId) {
    $result = $orderManager->getOrderById($editOrderId);
    if ($result['success']) {
        $userData = $result['data'];
        $orderItems = $result['data']['items'];
    } else {
        $_SESSION['message'] = $result['message'];
        header("Location: Order%20List.php?page=1");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    switch ($_POST['action']) {
        case 'search_customers':
            echo json_encode($orderManager->searchCustomers($_POST['query'] ?? ''));
            break;
        case 'get_customer':
            echo json_encode($orderManager->getCustomerById((int)$_POST['id']));
            break;
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update']) && $editOrderId) {
    $data = [
        'customer_id' => filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT) ?: '',
        'description' => filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING),
        'delivery_date' => filter_input(INPUT_POST, 'delivery_date', FILTER_SANITIZE_STRING),
        'discount' => filter_input(INPUT_POST, 'discount', FILTER_VALIDATE_FLOAT, ['options' => ['default' => 0.0]]),
        'payment' => filter_input(INPUT_POST, 'payment', FILTER_VALIDATE_FLOAT) ?: 0.0,
        'order_date' => filter_input(INPUT_POST, 'order_date', FILTER_SANITIZE_STRING),
        'status' => filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING) ?: 'pending'
    ];

    $items = [];
    if (isset($_POST['products']) && is_array($_POST['products'])) {
        foreach ($_POST['products'] as $index => $product) {
            if (!isset($product['id']) || !is_numeric($product['id']) || !isset($product['quantity']) || !is_numeric($product['quantity'])) {
                $orderManager->logError("Invalid product data at index $index", ['product' => $product]);
                $errorMessage = "داده‌های محصول در ردیف " . ($index + 1) . " نامعتبر است.";
                break;
            }
            $quantity = (int)$product['quantity'];
            if ($quantity < MIN_QUANTITY) {
                $errorMessage = "تعداد در ردیف " . ($index + 1) . " باید حداقل " . MIN_QUANTITY . " باشد.";
                break;
            }
            $items[] = [
                'product_id' => (int)$product['id'],
                'quantity' => $quantity
            ];
        }
    } else {
        $errorMessage = "هیچ محصولی انتخاب نشده است.";
    }

    if (!isset($errorMessage)) {
        $response = $orderManager->updateOrder($editOrderId, $data, $items);
        if ($response['success']) {
            $_SESSION['message'] = $response['message'];
            header("Location: Order%20List.php?page=1");
            exit;
        } else {
            $errorMessage = $response['message'];
        }
    }
}

if (!$userData && !$editOrderId) {
    $_SESSION['message'] = 'لطفاً یک سفارش را برای ویرایش انتخاب کنید.';
    header("Location: Order%20List.php?page=1");
    exit;
}

$products = $orderManager->getProducts();
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="مدیریت حرفه‌ای سفارشات استودیو نیما">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="Image/Logo1.png" type="image/png" sizes="80x80">
    <title>استودیو نیما | ویرایش سفارش (کد: <?= htmlspecialchars($userData['id'] ?? '') ?>)</title>
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

        .form-input, .form-select, .form-textarea {
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

        .form-select {
            background: var(--secondary-bg) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='%236B7280' viewBox='0 0 20 20'%3E%3Cpath d='M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z'/%3E%3C/svg%3E") no-repeat left 10px center;
            background-size: 14px;
            padding-left: 32px;
            appearance: none;
        }

        .form-textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 6px rgba(37, 99, 235, 0.2);
        }

        .products-container {
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: calc(var(--spacing-unit) * 0.75);
            background: var(--secondary-bg);
            margin-top: calc(var(--spacing-unit) * 0.5);
        }

        .order-items-table {
            width: 100%;
            border-collapse: collapse;
            box-shadow: var(--shadow);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .order-items-table th, .order-items-table td {
            padding: 10px 14px;
            text-align: right;
            border-bottom: 1px solid var(--border);
        }

        .order-items-table th {
            background: var(--accent);
            color: var(--secondary-bg);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        .order-items-table td {
            background: var(--secondary-bg);
            font-size: 0.85rem;
            color: var(--text-dark);
            transition: var(--transition);
        }

        .order-items-table tr:hover td {
            background: var(--card-hover);
        }

        .order-items-table tfoot td {
            background: var(--order-id-bg);
            font-weight: 600;
            color: var(--accent);
            padding: 12px 14px;
            border-top: 2px solid var(--border);
        }

        .order-items-table input[type="number"] {
            width: 70px;
            padding: 6px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 0.9rem;
            text-align: center;
            box-shadow: var(--shadow);
        }

        .autocomplete-container {
            position: relative;
        }

        .autocomplete-list {
            position: absolute;
            width: 100%;
            background: var(--secondary-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: var(--shadow-hover);
        }

        .autocomplete-item {
            padding: 8px 14px;
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .autocomplete-item:hover {
            background: var(--accent);
            color: var(--secondary-bg);
        }

        .summary-box {
            background: var(--card-hover);
            padding: var(--spacing-unit);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-top: var(--spacing-unit);
            border: 1px solid var(--border);
        }

        .summary-box p {
            margin: 0 0 0.5rem;
            display: flex;
            justify-content: space-between;
            font-size: 0.95rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px dashed var(--border);
        }

        .summary-box p:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .submit-btn, .add-product-btn {
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

        .add-product-btn {
            background: var(--accent);
        }

        .submit-btn:hover {
            background: var(--success-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }

        .add-product-btn:hover {
            background: var(--accent-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }

        .remove-btn {
            padding: 6px 12px;
            border-radius: var(--radius);
            font-size: 0.85rem;
            color: var(--secondary-bg);
            background: var(--danger);
            border: none;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: var(--shadow);
        }

        .remove-btn:hover {
            background: var(--danger-hover);
            transform: scale(1.05);
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
            max-width: 950px;
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

        .modal-search {
            margin-bottom: var(--spacing-unit);
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
            .modal { max-width: 700px; }
        }

        @media (max-width: 768px) {
            .nav-content { gap: calc(var(--spacing-unit) * 0.25); padding: 0 8px; }
            .nav-btn, .nav-logo { padding: 6px 12px; font-size: 0.85rem; }
            .form-body { grid-template-columns: 1fr; }
            .modal { width: 95%; }
            .order-items-table th, .order-items-table td { font-size: 0.8rem; padding: 8px; }
            .header-title { font-size: 1.75rem; }
        }

        @media (max-width: 480px) {
            .order-items-table { display: block; overflow-x: auto; }
            .submit-btn, .add-product-btn { width: 100%; text-align: center; }
            .order-items-table input[type="number"] { width: 60px; }
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
            <h1 class="header-title">ویرایش سفارش (کد: <?= htmlspecialchars($userData['id']) ?>)</h1>
            <div class="header-divider"></div>
        </header>

        <section class="form-section fade-in">
            <form method="POST" id="editOrderForm" class="form-body">
                <div class="form-group">
                    <label class="form-label" for="customer_search"><i class="fas fa-user"></i> انتخاب مشتری *</label>
                    <div class="autocomplete-container">
                        <input type="text" id="customer_search" class="form-input" value="<?= htmlspecialchars("{$userData['id']} - {$userData['fname']} {$userData['lname']} ({$userData['phone']})") ?>" placeholder="جستجو (نام، شماره یا ID)" required>
                        <input type="hidden" name="customer_id" id="customer_id" value="<?= htmlspecialchars($userData['customer_id']) ?>" required>
                        <div class="autocomplete-list" id="customer_autocomplete_list"></div>
                    </div>
                    <div class="form-error" id="customer_error"></div>
                </div>

                <div class="form-group">
                    <label class="form-label"><i class="fas fa-boxes"></i> محصولات سفارش *</label>
                    <button type="button" class="add-product-btn" id="add_product_btn"><i class="fas fa-plus"></i> افزودن محصول</button>
                    <div class="products-container">
                        <table class="order-items-table" id="products_table">
                            <thead>
                                <tr>
                                    <th>کد محصول</th>
                                    <th>اندازه</th>
                                    <th>نوع</th>
                                    <th>رنگ</th>
                                    <th>قیمت واحد (ت)</th>
                                    <th>تعداد</th>
                                    <th>جمع (ت)</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody id="products_list">
                                <?php foreach ($orderItems as $index => $item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['product_code']) ?></td>
                                        <td><?= htmlspecialchars($item['imagesize'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($item['type'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($item['color'] ?? '-') ?></td>
                                        <td><?= number_format($item['price']) ?></td>
                                        <td>
                                            <input type="number" class="quantity" min="1" value="<?= $item['quantity'] ?>" data-index="<?= $index ?>">
                                            <input type="hidden" name="products[<?= $index ?>][id]" value="<?= $item['product_id'] ?>">
                                            <input type="hidden" name="products[<?= $index ?>][quantity]" value="<?= $item['quantity'] ?>" class="hidden-quantity" data-index="<?= $index ?>">
                                        </td>
                                        <td><?= number_format($item['quantity'] * $item['price']) ?></td>
                                        <td><button type="button" class="remove-btn" data-index="<?= $index ?>"><i class="fas fa-trash"></i></button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot id="products_totals" <?= empty($orderItems) ? 'style="display: none;"' : '' ?>>
                                <tr>
                                    <td colspan="5">جمع کل</td>
                                    <td id="total_quantity"><?= array_sum(array_column($orderItems, 'quantity')) ?></td>
                                    <td id="total_subtotal"><?= number_format(array_sum(array_map(fn($i) => $i['quantity'] * $i['price'], $orderItems))) ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="form-error" id="products_error"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="delivery_date"><i class="fas fa-calendar-check"></i> موعد تحویل *</label>
                    <input type="text" name="delivery_date" id="delivery_date" class="form-input" placeholder="مثال: 1403-12-15" value="<?= htmlspecialchars($userData['delivery_date']) ?>" required>
                    <div class="form-error" id="delivery_date_error"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="order_date"><i class="fas fa-calendar-alt"></i> تاریخ سفارش *</label>
                    <input type="date" name="order_date" id="order_date" class="form-input" value="<?= htmlspecialchars($userData['order_date']) ?>" required>
                    <div class="form-error" id="order_date_error"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="description"><i class="fas fa-comment"></i> توضیحات</label>
                    <textarea name="description" id="description" class="form-textarea" placeholder="توضیحات سفارش (اختیاری)"><?= htmlspecialchars($userData['description'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label" for="discount"><i class="fas fa-percentage"></i> تخفیف (تومان)</label>
                    <input type="number" name="discount" id="discount" class="form-input" value="<?= htmlspecialchars($userData['discount']) ?>" min="0" step="0.01">
                    <div class="form-error" id="discount_error"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="payment"><i class="fas fa-wallet"></i> پرداختی (تومان) *</label>
                    <input type="number" name="payment" id="payment" class="form-input" value="<?= htmlspecialchars($userData['payment']) ?>" min="0" step="0.01" required>
                    <div class="form-error" id="payment_error"></div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="status"><i class="fas fa-info-circle"></i> وضعیت سفارش *</label>
                    <select name="status" id="status" class="form-select" required>
                        <?php foreach (ORDER_STATUSES as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $userData['status'] === $value ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-error" id="status_error"></div>
                </div>

                <div class="summary-box">
                    <p><span>جمع محصولات:</span> <span id="subtotal"><?= number_format($userData['subtotal']) ?></span> تومان</p>
                    <p><span>تخفیف:</span> <span id="discount_display"><?= number_format($userData['discount']) ?></span> تومان</p>
                    <p><span>مبلغ قابل پرداخت:</span> <span id="total_display"><?= number_format($userData['total']) ?></span> تومان</p>
                    <p><span>پرداختی:</span> <span id="payment_display"><?= number_format($userData['payment']) ?></span> تومان</p>
                    <p><span id="balance_label"><?= $userData['balance'] > 0 ? 'بدهی:' : ($userData['balance'] < 0 ? 'اضافه پرداخت:' : 'مانده:') ?></span> <span id="balance_display"><?= number_format(abs($userData['balance'])) ?></span> تومان</p>
                </div>

                <button type="submit" name="update" class="submit-btn"><i class="fas fa-check"></i> به‌روزرسانی سفارش</button>
            </form>
            <?php if (isset($errorMessage)): ?>
                <div class="form-error active fade-in"><?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>
        </section>
    </div>

    <div class="modal-overlay" id="product_modal_overlay"></div>
    <div class="modal" id="product_modal">
        <div class="modal-header">
            <span class="modal-title"><i class="fas fa-boxes"></i> انتخاب محصولات</span>
            <button class="modal-close" id="product_modal_close"><i class="fas fa-times"></i> بستن</button>
        </div>
        <div class="modal-body">
            <div class="modal-search">
                <input type="text" id="modal_product_search" class="form-input" placeholder="جستجو (کد، اندازه، نوع، رنگ)">
            </div>
            <table class="order-items-table" id="modal_product_table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select_all_products" title="انتخاب همه"></th>
                        <th>کد محصول</th>
                        <th>اندازه</th>
                        <th>نوع</th>
                        <th>رنگ</th>
                        <th>قیمت (ت)</th>
                        <th>تعداد</th>
                    </tr>
                </thead>
                <tbody id="modal_product_list">
                    <?php foreach ($products as $product): ?>
                        <tr data-product-id="<?= htmlspecialchars($product['id']) ?>">
                            <td><input type="checkbox" class="product-checkbox"></td>
                            <td><?= htmlspecialchars($product['product_code']) ?></td>
                            <td><?= htmlspecialchars($product['imagesize'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($product['type'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($product['color'] ?? '-') ?></td>
                            <td><?= number_format($product['price']) ?></td>
                            <td><input type="number" min="1" value="1" class="quantity form-input"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="add-product-btn" id="add_selected_products"><i class="fas fa-check"></i> افزودن انتخاب‌شده‌ها</button>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('editOrderForm');
            const customerSearch = document.getElementById('customer_search');
            const customerIdInput = document.getElementById('customer_id');
            const customerAutocompleteList = document.getElementById('customer_autocomplete_list');
            const addProductBtn = document.getElementById('add_product_btn');
            const productsList = document.getElementById('products_list');
            const productsTotals = document.getElementById('products_totals');
            const totalQuantity = document.getElementById('total_quantity');
            const totalSubtotal = document.getElementById('total_subtotal');
            const subtotalDisplay = document.getElementById('subtotal');
            const discountInput = document.getElementById('discount');
            const discountDisplay = document.getElementById('discount_display');
            const totalDisplay = document.getElementById('total_display');
            const paymentInput = document.getElementById('payment');
            const paymentDisplay = document.getElementById('payment_display');
            const balanceLabel = document.getElementById('balance_label');
            const balanceDisplay = document.getElementById('balance_display');
            const productModalOverlay = document.getElementById('product_modal_overlay');
            const productModal = document.getElementById('product_modal');
            const productModalClose = document.getElementById('product_modal_close');
            const modalProductSearch = document.getElementById('modal_product_search');
            const modalProductList = document.getElementById('modal_product_list');
            const addSelectedProductsBtn = document.getElementById('add_selected_products');
            const selectAllProducts = document.getElementById('select_all_products');

            let selectedProducts = <?= json_encode(array_map(fn($item) => [
                'id' => $item['product_id'],
                'product_code' => $item['product_code'],
                'imagesize' => $item['imagesize'],
                'type' => $item['type'],
                'color' => $item['color'],
                'price' => $item['price'],
                'quantity' => $item['quantity']
            ], $orderItems), JSON_UNESCAPED_UNICODE) ?>;

            function calculateTotals() {
                let subtotal = 0;
                let totalQty = 0;
                selectedProducts.forEach(product => {
                    const qty = parseInt(product.quantity) || 0;
                    const price = parseFloat(product.price) || 0;
                    subtotal += qty * price;
                    totalQty += qty;
                });

                const discount = parseFloat(discountInput.value) || 0;
                const total = Math.max(subtotal - discount, 0);
                const payment = parseFloat(paymentInput.value) || 0;
                const balance = total - payment;

                totalQuantity.textContent = totalQty.toLocaleString('fa-IR');
                totalSubtotal.textContent = subtotal.toLocaleString('fa-IR') + ' ت';
                subtotalDisplay.textContent = subtotal.toLocaleString('fa-IR');
                discountDisplay.textContent = discount.toLocaleString('fa-IR');
                totalDisplay.textContent = total.toLocaleString('fa-IR');
                paymentDisplay.textContent = payment.toLocaleString('fa-IR');
                balanceDisplay.textContent = Math.abs(balance).toLocaleString('fa-IR');
                balanceLabel.textContent = balance > 0 ? 'بدهی:' : (balance < 0 ? 'اضافه پرداخت:' : 'مانده:');
                balanceDisplay.style.color = balance > 0 ? 'var(--danger)' : (balance < 0 ? 'var(--success)' : 'var(--text-dark)');
                productsTotals.style.display = totalQty > 0 ? 'table-footer-group' : 'none';
            }

            function renderSelectedProducts() {
                productsList.innerHTML = '';
                selectedProducts.forEach((product, index) => {
                    const subtotal = (product.price * product.quantity).toLocaleString('fa-IR');
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${product.product_code}</td>
                        <td>${product.imagesize || '-'}</td>
                        <td>${product.type || '-'}</td>
                        <td>${product.color || '-'}</td>
                        <td>${product.price.toLocaleString('fa-IR')}</td>
                        <td>
                            <input type="number" class="quantity" min="1" value="${product.quantity}" data-index="${index}">
                            <input type="hidden" name="products[${index}][id]" value="${product.id}">
                            <input type="hidden" name="products[${index}][quantity]" value="${product.quantity}" class="hidden-quantity" data-index="${index}">
                        </td>
                        <td>${subtotal}</td>
                        <td><button type="button" class="remove-btn" data-index="${index}"><i class="fas fa-trash"></i></button></td>
                    `;
                    productsList.appendChild(row);
                });
                calculateTotals();
            }

            productsList.addEventListener('input', (e) => {
                if (e.target.classList.contains('quantity')) {
                    const index = parseInt(e.target.dataset.index);
                    selectedProducts[index].quantity = Math.max(1, parseInt(e.target.value) || 1);
                    const hiddenQty = productsList.querySelector(`.hidden-quantity[data-index="${index}"]`);
                    if (hiddenQty) hiddenQty.value = selectedProducts[index].quantity;
                    renderSelectedProducts();
                }
            });

            productsList.addEventListener('click', (e) => {
                const btn = e.target.closest('.remove-btn');
                if (btn) {
                    selectedProducts.splice(parseInt(btn.dataset.index), 1);
                    renderSelectedProducts();
                }
            });

            let debounceTimer;
            customerSearch.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    const query = customerSearch.value.trim();
                    if (query.length < 1) {
                        customerAutocompleteList.style.display = 'none';
                        return;
                    }
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=search_customers&query=${encodeURIComponent(query)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        customerAutocompleteList.innerHTML = '';
                        if (data.results.length > 0) {
                            data.results.forEach(customer => {
                                const item = document.createElement('div');
                                item.className = 'autocomplete-item';
                                item.textContent = customer.display;
                                item.onclick = () => {
                                    customerSearch.value = customer.display;
                                    customerIdInput.value = customer.id;
                                    customerAutocompleteList.style.display = 'none';
                                };
                                customerAutocompleteList.appendChild(item);
                            });
                            customerAutocompleteList.style.display = 'block';
                        } else {
                            customerAutocompleteList.innerHTML = '<div class="autocomplete-item">مشتری یافت نشد</div>';
                            customerAutocompleteList.style.display = 'block';
                        }
                    })
                    .catch(error => console.error('Error:', error));
                }, 300);
            });

            document.addEventListener('click', (e) => {
                if (!customerSearch.contains(e.target) && !customerAutocompleteList.contains(e.target)) {
                    customerAutocompleteList.style.display = 'none';
                }
            });

            addProductBtn.addEventListener('click', () => {
                productModalOverlay.classList.add('active');
                productModal.classList.add('active');
                modalProductSearch.value = '';
                filterModalProducts('');
                updateModalProductQuantities();
            });

            productModalClose.addEventListener('click', closeModal);
            productModalOverlay.addEventListener('click', closeModal);

            function closeModal() {
                productModalOverlay.classList.remove('active');
                productModal.classList.remove('active');
            }

            let productSearchTimer;
            modalProductSearch.addEventListener('input', () => {
                clearTimeout(productSearchTimer);
                productSearchTimer = setTimeout(() => {
                    filterModalProducts(modalProductSearch.value.trim().toLowerCase());
                }, 200);
            });

            function filterModalProducts(query) {
                const rows = modalProductList.querySelectorAll('tr');
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(query) ? '' : 'none';
                });
                updateSelectAllCheckbox();
            }

            function updateModalProductQuantities() {
                const rows = modalProductList.querySelectorAll('tr');
                rows.forEach(row => {
                    const productId = row.dataset.productId;
                    const selected = selectedProducts.find(p => p.id === productId);
                    const checkbox = row.querySelector('.product-checkbox');
                    const qtyInput = row.querySelector('.quantity');
                    checkbox.checked = !!selected;
                    qtyInput.value = selected ? selected.quantity : 1;
                });
                updateSelectAllCheckbox();
            }

            selectAllProducts.addEventListener('change', () => {
                const isChecked = selectAllProducts.checked;
                modalProductList.querySelectorAll('.product-checkbox').forEach(cb => {
                    if (cb.closest('tr').style.display !== 'none') cb.checked = isChecked;
                });
                updateSelectAllCheckbox();
            });

            function updateSelectAllCheckbox() {
                const checkboxes = modalProductList.querySelectorAll('.product-checkbox');
                const visible = Array.from(checkboxes).filter(cb => cb.closest('tr').style.display !== 'none');
                selectAllProducts.checked = visible.every(cb => cb.checked);
                selectAllProducts.indeterminate = visible.some(cb => cb.checked) && !selectAllProducts.checked;
            }

            addSelectedProductsBtn.addEventListener('click', () => {
                selectedProducts = [];
                modalProductList.querySelectorAll('tr').forEach(row => {
                    if (row.querySelector('.product-checkbox').checked) {
                        const qty = parseInt(row.querySelector('.quantity').value) || 1;
                        if (qty < 1) return;
                        selectedProducts.push({
                            id: row.dataset.productId,
                            product_code: row.cells[1].textContent,
                            imagesize: row.cells[2].textContent,
                            type: row.cells[3].textContent,
                            color: row.cells[4].textContent,
                            price: parseFloat(row.cells[5].textContent.replace(/[^\d.-]/g, '')),
                            quantity: qty
                        });
                    }
                });
                renderSelectedProducts();
                closeModal();
            });

            discountInput.addEventListener('input', calculateTotals);
            paymentInput.addEventListener('input', calculateTotals);

            form.addEventListener('submit', (e) => {
                let isValid = true;
                const errors = {};

                if (!customerIdInput.value) {
                    errors.customer = 'لطفاً مشتری را انتخاب کنید';
                    isValid = false;
                }

                if (selectedProducts.length === 0) {
                    errors.products = 'حداقل یک محصول انتخاب کنید';
                    isValid = false;
                } else {
                    selectedProducts.forEach((p, i) => {
                        if (p.quantity < 1) {
                            errors.products = `تعداد محصول در ردیف ${i + 1} باید حداقل 1 باشد`;
                            isValid = false;
                        }
                    });
                }

                const deliveryDate = document.getElementById('delivery_date');
                if (!deliveryDate.value.trim()) {
                    errors.delivery_date = 'موعد تحویل اجباری است';
                    isValid = false;
                }

                const orderDate = document.getElementById('order_date');
                if (!orderDate.value) {
                    errors.order_date = 'تاریخ سفارش نامعتبر است';
                    isValid = false;
                }

                const payment = document.getElementById('payment');
                if (!payment.value || parseFloat(payment.value) < 0) {
                    errors.payment = 'پرداختی نامعتبر است';
                    isValid = false;
                }

                const discount = document.getElementById('discount');
                if (discount.value && parseFloat(discount.value) < 0) {
                    errors.discount = 'تخفیف نمی‌تواند منفی باشد';
                    isValid = false;
                }

                const status = document.getElementById('status');
                if (!Object.keys(<?php echo json_encode(ORDER_STATUSES); ?>).includes(status.value)) {
                    errors.status = 'وضعیت سفارش نامعتبر است';
                    isValid = false;
                }

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

            calculateTotals();
        });
    </script>
</body>
</html>