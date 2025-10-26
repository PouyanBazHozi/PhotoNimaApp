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
const POINTS_PER_TOMAN = 0.001; // 1 point per 1000 Tomans

class OrderManager {
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

    public function deleteOrder(int $id): array {
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare("DELETE FROM photonim_db.order_items WHERE order_id = :id");
            $stmt->execute([':id' => $id]);
            $stmt = $this->pdo->prepare("DELETE FROM photonim_db.`order` WHERE id = :id");
            $success = $stmt->execute([':id' => $id]);
            $this->pdo->commit();
            return [
                'success' => $success,
                'message' => $success ? "سفارش $id با موفقیت حذف شد" : 'خطا در حذف سفارش'
            ];
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->logError("Delete Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'خطا در حذف: ' . $e->getMessage()];
        }
    }

    public function getOrderById(int $id): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT o.*, c.fname, c.lname, c.phone 
                FROM photonim_db.`order` o 
                LEFT JOIN photonim_db.customerregistration c ON o.customer_id = c.id 
                WHERE o.id = :id
            ");
            $stmt->execute([':id' => $id]);
            $order = $stmt->fetch();

            if (!$order) {
                return ['success' => false, 'message' => "سفارش $id یافت نشد"];
            }

            $itemsStmt = $this->pdo->prepare("
                SELECT oi.*, p.imagesize, p.type, p.color, p.price 
                FROM photonim_db.order_items oi 
                LEFT JOIN photonim_db.pricelist p ON oi.product_id = p.id 
                WHERE oi.order_id = :id
            ");
            $itemsStmt->execute([':id' => $id]);
            $items = $itemsStmt->fetchAll();

            $subtotal = 0;
            foreach ($items as &$item) {
                $item['subtotal'] = $item['quantity'] * $item['price'];
                $subtotal += $item['subtotal'];
            }
            $order['subtotal'] = $subtotal;
            $order['total'] = max($subtotal - $order['discount'], 0);
            $order['balance'] = $order['total'] - $order['payment'];
            $order['items'] = $items;

            return ['success' => true, 'data' => $order];
        } catch (PDOException $e) {
            $this->logError("Fetch Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'خطا در دریافت: ' . $e->getMessage()];
        }
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

    public function updateOrderStatus(int $id, string $status): array {
        if (!array_key_exists($status, ORDER_STATUSES)) {
            return ['success' => false, 'message' => 'وضعیت نامعتبر'];
        }
        try {
            $this->pdo->beginTransaction();

            // Check current status to determine if points should be awarded
            $stmt = $this->pdo->prepare("SELECT status, customer_id, total FROM photonim_db.`order` WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $order = $stmt->fetch();
            if (!$order) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => "سفارش $id یافت نشد"];
            }

            $pointsMessage = '';
            if ($status === 'completed' && $order['status'] !== 'completed') {
                $points = $this->awardPoints($order['customer_id'], $order['total']);
                $this->updateCustomerLevel($order['customer_id']);
                $pointsMessage = " و $points امتیاز به مشتری اختصاص یافت";
            }

            $stmt = $this->pdo->prepare("
                UPDATE photonim_db.`order` 
                SET status = :status, updated_at = NOW() 
                WHERE id = :id
            ");
            $success = $stmt->execute([':id' => $id, ':status' => $status]);

            $this->pdo->commit();
            return [
                'success' => $success,
                'message' => $success ? "وضعیت سفارش $id به‌روزرسانی شد$pointsMessage" : 'خطا در به‌روزرسانی'
            ];
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->logError("Update Error: " . $e->getMessage(), ['order_id' => $id, 'status' => $status]);
            return ['success' => false, 'message' => 'خطا در به‌روزرسانی: ' . $e->getMessage()];
        }
    }

    public function searchOrders(string $keyword = '', string $field = 'all', int $limit = 5, int $offset = 0): array {
        try {
            $fields = [
                'id', 'customer_id', 'subtotal', 'description', 'delivery_date', 'discount',
                'payment', 'total', 'balance', 'order_date', 'status'
            ];
            $sql = "SELECT o.*, c.fname, c.lname, c.phone 
                    FROM photonim_db.`order` o 
                    LEFT JOIN photonim_db.customerregistration c ON o.customer_id = c.id";
            $params = [];

            if ($keyword !== '') {
                $keyword = trim($keyword);
                $conditions = [];
                $paramIndex = 0;

                if ($field === 'all') {
                    foreach ($fields as $f) {
                        $param = ":kw" . $paramIndex++;
                        $conditions[] = "o.$f LIKE $param";
                        $params[$param] = "%$keyword%";
                    }
                    $conditions[] = "c.fname LIKE :kw" . $paramIndex;
                    $params[":kw" . $paramIndex++] = "%$keyword%";
                    $conditions[] = "c.lname LIKE :kw" . $paramIndex;
                    $params[":kw" . $paramIndex++] = "%$keyword%";
                    $conditions[] = "c.phone LIKE :kw" . $paramIndex;
                    $params[":kw" . $paramIndex] = "%$keyword%";
                    $sql .= " WHERE (" . implode(' OR ', $conditions) . ")";
                } elseif ($field === 'customer') {
                    $conditions[] = "c.fname LIKE :kw0";
                    $conditions[] = "c.lname LIKE :kw1";
                    $conditions[] = "c.phone LIKE :kw2";
                    $conditions[] = "o.customer_id LIKE :kw3";
                    $params[":kw0"] = "%$keyword%";
                    $params[":kw1"] = "%$keyword%";
                    $params[":kw2"] = "%$keyword%";
                    $params[":kw3"] = "%$keyword%";
                    $sql .= " WHERE (" . implode(' OR ', $conditions) . ")";
                } elseif (in_array($field, $fields)) {
                    $param = ":kw0";
                    $conditions[] = "o.$field LIKE $param";
                    $params[$param] = "%$keyword%";
                    $sql .= " WHERE " . implode(' AND ', $conditions);
                }
            }

            $sql .= " ORDER BY o.created_at DESC LIMIT :limit OFFSET :offset";
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $param => $value) {
                $stmt->bindValue($param, $value, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $orders = $stmt->fetchAll();

            foreach ($orders as &$order) {
                $itemsStmt = $this->pdo->prepare("
                    SELECT oi.*, p.imagesize, p.type, p.color, p.price 
                    FROM photonim_db.order_items oi 
                    LEFT JOIN photonim_db.pricelist p ON oi.product_id = p.id 
                    WHERE oi.order_id = :id
                ");
                $itemsStmt->execute([':id' => $order['id']]);
                $items = $itemsStmt->fetchAll();

                $subtotal = 0;
                foreach ($items as &$item) {
                    $item['subtotal'] = $item['quantity'] * $item['price'];
                    $subtotal += $item['subtotal'];
                }
                $order['subtotal'] = $subtotal;
                $order['total'] = max($subtotal - $order['discount'], 0);
                $order['balance'] = $order['total'] - $order['payment'];
                $order['items'] = $items;
            }

            return ['success' => true, 'data' => $orders];
        } catch (PDOException $e) {
            $this->logError("Search Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'خطا در جستجو: ' . $e->getMessage()];
        }
    }

    public function getTotalOrders(string $keyword = '', string $field = 'all'): int {
        try {
            $fields = [
                'id', 'customer_id', 'subtotal', 'description', 'delivery_date', 'discount',
                'payment', 'total', 'balance', 'order_date', 'status'
            ];
            $sql = "SELECT COUNT(*) 
                    FROM photonim_db.`order` o 
                    LEFT JOIN photonim_db.customerregistration c ON o.customer_id = c.id";
            $params = [];

            if ($keyword !== '') {
                $keyword = trim($keyword);
                $conditions = [];
                $paramIndex = 0;

                if ($field === 'all') {
                    foreach ($fields as $f) {
                        $param = ":kw" . $paramIndex++;
                        $conditions[] = "o.$f LIKE $param";
                        $params[$param] = "%$keyword%";
                    }
                    $conditions[] = "c.fname LIKE :kw" . $paramIndex;
                    $params[":kw" . $paramIndex++] = "%$keyword%";
                    $conditions[] = "c.lname LIKE :kw" . $paramIndex;
                    $params[":kw" . $paramIndex++] = "%$keyword%";
                    $conditions[] = "c.phone LIKE :kw" . $paramIndex;
                    $params[":kw" . $paramIndex] = "%$keyword%";
                    $sql .= " WHERE (" . implode(' OR ', $conditions) . ")";
                } elseif ($field === 'customer') {
                    $conditions[] = "c.fname LIKE :kw0";
                    $conditions[] = "c.lname LIKE :kw1";
                    $conditions[] = "c.phone LIKE :kw2";
                    $conditions[] = "o.customer_id LIKE :kw3";
                    $params[":kw0"] = "%$keyword%";
                    $params[":kw1"] = "%$keyword%";
                    $params[":kw2"] = "%$keyword%";
                    $params[":kw3"] = "%$keyword%";
                    $sql .= " WHERE (" . implode(' OR ', $conditions) . ")";
                } elseif (in_array($field, $fields)) {
                    $param = ":kw0";
                    $conditions[] = "o.$field LIKE $param";
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
            $this->logError("Count Error: " . $e->getMessage());
            return 0;
        }
    }
}

if (!isset($conn) || !$conn instanceof PDO) {
    http_response_code(500);
    exit("<p style='color: #EF4444; text-align: center; font-family: Vazirmatn;'>خطا: اتصال به دیتابیس برقرار نیست.</p>");
}

$orderManager = new OrderManager($conn);

$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?? 1;
$limit = 5;
$offset = ($page - 1) * $limit;

$actions = [
    'Dele' => fn($id) => $orderManager->deleteOrder($id),
    'Edit' => fn($id) => header("Location: EditOrder.php?Edit=$id"),
    'GenerateInvoice' => function($id) use ($orderManager) {
        $result = $orderManager->getOrderById($id);
        if ($result['success']) {
            header("Location: PrintInvoice.php?InvoiceId=$id");
            exit;
        }
        return $result;
    }
];

$response = null;
foreach ($actions as $key => $action) {
    if (isset($_GET[$key]) && filter_var($_GET[$key], FILTER_VALIDATE_INT)) {
        $id = (int)$_GET[$key];
        $response = $action($id);
        if ($response && ($response['success'] ?? false)) {
            $queryParams = "?page=$page" . (isset($_GET['query']) ? "&query=" . urlencode($_GET['query']) . "&search_field=" . urlencode($_GET['search_field'] ?? 'all') : "");
            header("Location: " . $_SERVER['PHP_SELF'] . $queryParams);
            exit;
        }
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'], $_POST['order_id'], $_POST['status'])) {
    $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
    $status = trim($_POST['status']); // Changed to trim instead of FILTER_SANITIZE_STRING
    if ($orderId && $status) {
        $response = $orderManager->updateOrderStatus($orderId, $status);
        if ($response['success']) {
            $_SESSION['message'] = $response['message']; // Store message in session for display
            $queryParams = "?page=$page" . (isset($_GET['query']) ? "&query=" . urlencode($_GET['query']) . "&search_field=" . urlencode($_GET['search_field'] ?? 'all') : "");
            header("Location: " . $_SERVER['PHP_SELF'] . $queryParams);
            exit;
        }
    }
}

$searchKeyword = filter_input(INPUT_GET, 'query', FILTER_UNSAFE_RAW) ?? '';
$searchKeyword = htmlspecialchars($searchKeyword, ENT_QUOTES, 'UTF-8');

$searchField = filter_input(INPUT_GET, 'search_field', FILTER_UNSAFE_RAW) ?? 'all';
$searchField = htmlspecialchars($searchField, ENT_QUOTES, 'UTF-8');

$searchResult = $orderManager->searchOrders($searchKeyword, $searchField, $limit, $offset);
$orders = $searchResult['success'] ? $searchResult['data'] : [];
$totalOrders = $orderManager->getTotalOrders($searchKeyword, $searchField);
$totalPages = ceil($totalOrders / $limit);

if (!$searchResult['success']) {
    $response = $searchResult;
}
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
    <title>استودیو نیما | لیست سفارشات</title>
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
            --status-pending: #F59E0B;
            --status-in-progress: #2563EB;
            --status-completed: #16A34A;
            --status-canceled: #DC2626;
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

        .nav-logo:hover img {
            transform: scale(1.05);
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

        .search-section {
            background: var(--secondary-bg);
            padding: var(--spacing-unit);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: calc(var(--spacing-unit) * 1.5);
        }

        .search-form {
            display: flex;
            gap: calc(var(--spacing-unit) * 0.5);
            align-items: center;
            flex-wrap: wrap;
        }

        .search-select, .search-input, .search-btn {
            padding: 8px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 0.9rem;
            transition: var(--transition);
            box-shadow: var(--shadow);
        }

        .search-select {
            min-width: 180px;
            background: var(--secondary-bg) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='%236B7280' viewBox='0 0 20 20'%3E%3Cpath d='M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z'/%3E%3C/svg%3E") no-repeat left 10px center;
            background-size: 14px;
            padding-left: 32px;
            appearance: none;
        }

        .search-input {
            flex: 1;
            min-width: 220px;
        }

        .search-btn {
            background: var(--accent);
            color: var(--secondary-bg);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .search-btn:hover {
            background: var(--accent-hover);
            box-shadow: var(--shadow-hover);
        }

        .search-select:focus, .search-input:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 6px rgba(37, 99, 235, 0.2);
        }

        .orders-section {
            display: grid;
            gap: var(--spacing-unit);
        }

        .order-card {
            background: var(--secondary-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: var(--spacing-unit);
            transition: var(--transition);
            border: 1px solid var(--border);
        }

        .order-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
            background: var(--card-hover);
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: calc(var(--spacing-unit) * 0.75);
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
            gap: 8px;
        }

        .order-id {
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

        .order-status {
            font-size: 0.9rem;
            font-weight: 500;
            padding: 6px 12px;
            border-radius: var(--radius);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .order-status.pending { color: var(--status-pending); background: rgba(245, 158, 11, 0.1); }
        .order-status.in-progress { color: var(--status-in-progress); background: rgba(37, 99, 235, 0.1); }
        .order-status.completed { color: var(--status-completed); background: rgba(22, 163, 74, 0.1); }
        .order-status.canceled { color: var(--status-canceled); background: rgba(220, 38, 38, 0.1); }

        .order-details {
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

        .order-actions {
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
        .action-btn.invoice { background: var(--accent); }
        .action-btn.details { background: var(--warning); }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }

        .action-btn.delete:hover { background: var(--danger-hover); }
        .action-btn.edit:hover { background: var(--success-hover); }
        .action-btn.invoice:hover { background: var(--accent-hover); }
        .action-btn.details:hover { background: var(--warning-hover); }

        .status-group {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .status-btn {
            padding: 6px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--secondary-bg);
            color: var(--text-muted);
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
        }

        .status-btn.active {
            background: var(--accent);
            color: var(--secondary-bg);
            border-color: var(--accent);
        }

        .status-btn:hover:not(.active) {
            background: var(--card-hover);
            color: var(--text-dark);
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

        .modal-order-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: calc(var(--spacing-unit) * 0.75);
            padding: calc(var(--spacing-unit) * 0.75);
            background: var(--card-hover);
            border-radius: var(--radius);
            margin-bottom: var(--spacing-unit);
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

        .order-items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: calc(var(--spacing-unit) * 0.75);
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

        .fade-in {
            animation: fadeIn 0.3s ease-out forwards;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 1024px) {
            .order-details, .modal-order-info { grid-template-columns: repeat(2, 1fr); }
            .modal { max-width: 700px; }
            .search-select, .search-input { min-width: 200px; }
        }

        @media (max-width: 768px) {
            .nav-content { gap: calc(var(--spacing-unit) * 0.25); padding: 0 8px; }
            .nav-btn, .nav-logo { padding: 6px 12px; font-size: 0.85rem; }
            .search-form { flex-direction: column; }
            .search-select, .search-input { width: 100%; }
            .order-details, .modal-order-info { grid-template-columns: 1fr; }
            .order-actions { flex-direction: column; align-items: flex-end; }
            .order-header { flex-direction: column; align-items: flex-start; }
            .modal { width: 95%; }
            .order-items-table th, .order-items-table td { font-size: 0.8rem; padding: 8px; }
            .header-title { font-size: 1.75rem; }
        }

        @media (max-width: 480px) {
            .pagination { flex-direction: column; gap: calc(var(--spacing-unit) * 0.5); }
            .pagination-btn, .pagination-info { width: 100%; text-align: center; }
            .action-btn { width: 100%; justify-content: center; }
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
            <h1 class="header-title">لیست سفارشات</h1>
            <div class="header-divider"></div>
        </header>

        <section class="search-section fade-in">
            <form method="GET" class="search-form">
                <select name="search_field" class="search-select">
                    <option value="all" <?= $searchField === 'all' ? 'selected' : '' ?>>همه</option>
                    <option value="id" <?= $searchField === 'id' ? 'selected' : '' ?>>شناسه سفارش</option>
                    <option value="customer" <?= $searchField === 'customer' ? 'selected' : '' ?>>مشتری</option>
                    <option value="subtotal" <?= $searchField === 'subtotal' ? 'selected' : '' ?>>جمع جزئی</option>
                    <option value="discount" <?= $searchField === 'discount' ? 'selected' : '' ?>>تخفیف</option>
                    <option value="total" <?= $searchField === 'total' ? 'selected' : '' ?>>مبلغ کل</option>
                    <option value="payment" <?= $searchField === 'payment' ? 'selected' : '' ?>>پرداخت‌شده</option>
                    <option value="balance" <?= $searchField === 'balance' ? 'selected' : '' ?>>باقیمانده</option>
                    <option value="delivery_date" <?= $searchField === 'delivery_date' ? 'selected' : '' ?>>تاریخ تحویل</option>
                    <option value="order_date" <?= $searchField === 'order_date' ? 'selected' : '' ?>>تاریخ سفارش</option>
                    <option value="description" <?= $searchField === 'description' ? 'selected' : '' ?>>توضیحات</option>
                    <option value="status" <?= $searchField === 'status' ? 'selected' : '' ?>>وضعیت</option>
                </select>
                <input type="text" name="query" class="search-input" value="<?= htmlspecialchars($searchKeyword) ?>" placeholder="جستجو در سفارشات">
                <button type="submit" class="search-btn"><i class="fas fa-search"></i> جستجو</button>
            </form>
        </section>

        <section class="orders-section fade-in">
            <?php if (!empty($orders)): ?>
                <?php foreach ($orders as $order): ?>
                    <div class="order-card">
                        <div class="order-header">
                            <span class="order-id"><i class="fas fa-id-badge"></i> <?= htmlspecialchars($order['id']) ?></span>
                            <span class="order-status <?= str_replace('_', '-', $order['status']) ?>">
                                <i class="fas <?= $order['status'] === 'pending' ? 'fa-hourglass-half' : ($order['status'] === 'in_progress' ? 'fa-cogs' : ($order['status'] === 'completed' ? 'fa-check-circle' : 'fa-times-circle')) ?>"></i>
                                <?= ORDER_STATUSES[$order['status']] ?>
                            </span>
                        </div>
                        <div class="order-details">
                            <div class="detail-item">
                                <span class="detail-label">مشتری</span>
                                <span class="detail-value"><i class="fas fa-user"></i> <?= htmlspecialchars($order['fname'] . ' ' . $order['lname']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">شماره</span>
                                <span class="detail-value"><i class="fas fa-phone"></i> <?= htmlspecialchars($order['phone'] ?? '-') ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">جمع جزئی</span>
                                <span class="detail-value"><i class="fas fa-money-bill"></i> <?= number_format($order['subtotal']) ?> ت</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">تخفیف</span>
                                <span class="detail-value"><i class="fas fa-percentage"></i> <?= number_format($order['discount']) ?> ت</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">مبلغ کل</span>
                                <span class="detail-value"><i class="fas fa-wallet"></i> <?= number_format($order['total']) ?> ت</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">پرداخت‌شده</span>
                                <span class="detail-value"><i class="fas fa-credit-card"></i> <?= number_format($order['payment']) ?> ت</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">باقیمانده</span>
                                <span class="detail-value"><i class="fas fa-balance-scale"></i> <?= number_format($order['balance']) ?> ت</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">تاریخ سفارش</span>
                                <span class="detail-value"><i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($order['order_date']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">تاریخ تحویل</span>
                                <span class="detail-value"><i class="fas fa-calendar-check"></i> <?= htmlspecialchars($order['delivery_date']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">توضیحات</span>
                                <span class="detail-value"><i class="fas fa-comment"></i> <?= htmlspecialchars($order['description'] ?? '-') ?></span>
                            </div>
                        </div>
                        <div class="order-actions">
                            <a href="?Dele=<?= $order['id'] ?>" class="action-btn delete" onclick="return confirm('آیا از حذف سفارش <?= $order['id'] ?> مطمئن هستید؟')"><i class="fas fa-trash"></i> حذف</a>
                            <a href="?Edit=<?= $order['id'] ?>" class="action-btn edit"><i class="fas fa-edit"></i> ویرایش</a>
                            <a href="?GenerateInvoice=<?= $order['id'] ?>" class="action-btn invoice"><i class="fas fa-file-invoice"></i> فاکتور</a>
                            <button class="action-btn details" data-order='<?= json_encode($order, JSON_UNESCAPED_UNICODE) ?>'><i class="fas fa-eye"></i> جزئیات</button>
                            <div class="status-group">
                                <?php foreach (ORDER_STATUSES as $value => $label): ?>
                                    <form method="POST">
                                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                        <input type="hidden" name="status" value="<?= $value ?>">
                                        <input type="hidden" name="update_status" value="1">
                                        <button type="submit" class="status-btn <?= $order['status'] === $value ? 'active' : '' ?>" <?= $order['status'] === $value ? 'disabled' : '' ?>><?= $label ?></button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-results">هیچ سفارشی با شرایط جستجو یافت نشد!</div>
            <?php endif; ?>
        </section>

        <?php if ($totalPages > 1): ?>
            <div class="pagination fade-in">
                <a href="?page=<?= $page - 1 ?>&query=<?= urlencode($searchKeyword) ?>&search_field=<?= urlencode($searchField) ?>" 
                   class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>"><i class="fas fa-chevron-right"></i> قبلی</a>
                <span class="pagination-info">صفحه <?= $page ?> از <?= $totalPages ?> (<?= $totalOrders ?> سفارش)</span>
                <a href="?page=<?= $page + 1 ?>&query=<?= urlencode($searchKeyword) ?>&search_field=<?= urlencode($searchField) ?>" 
                   class="pagination-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">بعدی <i class="fas fa-chevron-left"></i></a>
            </div>
        <?php endif; ?>
    </div>

    <div class="modal-overlay"></div>
    <div class="modal">
        <div class="modal-header">
            <span class="modal-title"><i class="fas fa-info-circle"></i> جزئیات سفارش <span id="modal-order-id"></span></span>
            <button class="modal-close"><i class="fas fa-times"></i> بستن</button>
        </div>
        <div class="modal-body">
            <div class="modal-order-info">
                <div class="modal-detail-item">
                    <span class="modal-detail-label">مشتری</span>
                    <span class="modal-detail-value"><i class="fas fa-user"></i> <span id="modal-order-customer"></span></span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">شماره</span>
                    <span class="modal-detail-value"><i class="fas fa-phone"></i> <span id="modal-order-phone"></span></span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">تاریخ سفارش</span>
                    <span class="modal-detail-value"><i class="fas fa-calendar-alt"></i> <span id="modal-order-date"></span></span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">تاریخ تحویل</span>
                    <span class="modal-detail-value"><i class="fas fa-calendar-check"></i> <span id="modal-order-delivery"></span></span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">توضیحات</span>
                    <span class="modal-detail-value"><i class="fas fa-comment"></i> <span id="modal-order-description"></span></span>
                </div>
            </div>

            <table class="order-items-table">
                <thead>
                    <tr>
                        <th>ردیف</th>
                        <th>محصول</th>
                        <th>اندازه</th>
                        <th>رنگ</th>
                        <th>تعداد</th>
                        <th>قیمت واحد (ت)</th>
                        <th>جمع (ت)</th>
                    </tr>
                </thead>
                <tbody id="modal-items"></tbody>
                <tfoot>
                    <tr>
                        <td colspan="4">جمع کل</td>
                        <td id="modal-items-quantity"></td>
                        <td></td>
                        <td id="modal-items-total"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <?php if (isset($response) && !$response['success']): ?>
        <script>
            alert('<?= addslashes($response['message']) ?>');
        </script>
    <?php endif; ?>

    <?php if (isset($_SESSION['message'])): ?>
        <script>
            alert('<?= addslashes($_SESSION['message']) ?>');
            <?php unset($_SESSION['message']); ?>
        </script>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const overlay = document.querySelector('.modal-overlay');
            const modal = document.querySelector('.modal');
            const modalItems = document.getElementById('modal-items');
            const modalOrderId = document.getElementById('modal-order-id');
            const modalOrderCustomer = document.getElementById('modal-order-customer');
            const modalOrderPhone = document.getElementById('modal-order-phone');
            const modalOrderDate = document.getElementById('modal-order-date');
            const modalOrderDelivery = document.getElementById('modal-order-delivery');
            const modalOrderDescription = document.getElementById('modal-order-description');
            const modalItemsTotal = document.getElementById('modal-items-total');
            const modalItemsQuantity = document.getElementById('modal-items-quantity');
            const closeBtn = document.querySelector('.modal-close');
            const detailBtns = document.querySelectorAll('.action-btn.details');

            function openModal(order) {
                overlay.classList.add('active');
                modal.classList.add('active');
                modalOrderId.textContent = order.id;
                modalOrderCustomer.textContent = `${order.fname} ${order.lname}`;
                modalOrderPhone.textContent = order.phone || '-';
                modalOrderDate.textContent = order.order_date;
                modalOrderDelivery.textContent = order.delivery_date;
                modalOrderDescription.textContent = order.description || '-';
                modalItems.innerHTML = '';

                let total = 0;
                let quantity = 0;
                order.items.forEach((item, index) => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${index + 1}</td>
                        <td>${item.type || '-'}</td>
                        <td>${item.imagesize || '-'}</td>
                        <td>${item.color || '-'}</td>
                        <td>${item.quantity || 0}</td>
                        <td>${Number(item.price || 0).toLocaleString('fa-IR')}</td>
                        <td>${Number(item.subtotal || 0).toLocaleString('fa-IR')}</td>
                    `;
                    modalItems.appendChild(row);
                    total += parseFloat(item.subtotal || 0);
                    quantity += parseInt(item.quantity || 0);
                });

                modalItemsTotal.textContent = total.toLocaleString('fa-IR') + ' ت';
                modalItemsQuantity.textContent = quantity.toLocaleString('fa-IR');
            }

            function closeModal() {
                overlay.classList.remove('active');
                modal.classList.remove('active');
            }

            detailBtns.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const order = JSON.parse(btn.dataset.order);
                    openModal(order);
                });
            });

            closeBtn.addEventListener('click', closeModal);
            overlay.addEventListener('click', closeModal);

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && modal.classList.contains('active')) {
                    closeModal();
                }
            });
        });
    </script>
</body>
</html>
