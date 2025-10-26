<?php
session_start();
require_once 'DB Config.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Constants for system configuration
const MIN_QUANTITY = 1;
const ORDER_STATUSES = [
    'pending' => 'در انتظار',
    'in_progress' => 'در حال انجام',
    'completed' => 'تکمیل شده',
    'canceled' => 'لغو شده'
];
const ORDER_PRIORITIES = [
    'high' => 'بالا',
    'urgent' => 'فوری',
    'normal' => 'معمولی'
];

class OrderManager {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    // Centralized error logging
    private function logError(string $message): void {
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
    }

    // Notification system (simulated for email/SMS, logged to DB)
    private function sendNotification(int $orderId, string $type, string $recipient, string $message): bool {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO photonim_db.notifications (order_id, type, recipient, message)
                VALUES (:order_id, :type, :recipient, :message)
            ");
            return $stmt->execute([
                ':order_id' => $orderId,
                ':type' => $type,
                ':recipient' => $recipient,
                ':message' => $message
            ]);
        } catch (PDOException $e) {
            $this->logError("Notification Error: " . $e->getMessage());
            return false;
        }
    }

    // Delete an order with transaction safety
    public function deleteOrder(int $id): array {
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare("DELETE FROM photonim_db.order_items WHERE order_id = :id");
            $stmt->execute([':id' => $id]);
            $stmt = $this->pdo->prepare("DELETE FROM photonim_db.`order` WHERE id = :id");
            $success = $stmt->execute([':id' => $id]);
            if ($success) {
                $this->sendNotification($id, 'system', 'admin', "سفارش $id با موفقیت حذف شد");
            }
            $this->pdo->commit();
            return [
                'success' => $success,
                'message' => $success ? "سفارش $id با موفقیت حذف شد" : "خطا در حذف سفارش $id"
            ];
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->logError("Delete Error: Order ID $id - " . $e->getMessage());
            return ['success' => false, 'message' => "خطا در حذف سفارش $id: " . $e->getMessage()];
        }
    }

    // Fetch detailed order information
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
                return ['success' => false, 'message' => "سفارش با شناسه $id یافت نشد"];
            }

            $itemsStmt = $this->pdo->prepare("
                SELECT oi.*, p.imagesize, p.type, p.color, p.price 
                FROM photonim_db.order_items oi 
                LEFT JOIN photonim_db.pricelist p ON oi.product_id = p.id 
                WHERE oi.order_id = :id
            ");
            $itemsStmt->execute([':id' => $id]);
            $items = $itemsStmt->fetchAll();

            $historyStmt = $this->pdo->prepare("
                SELECT osh.*, u.username 
                FROM photonim_db.order_status_history osh 
                LEFT JOIN photonim_db.users u ON osh.changed_by = u.id 
                WHERE osh.order_id = :id 
                ORDER BY osh.changed_at DESC
            ");
            $historyStmt->execute([':id' => $id]);
            $history = $historyStmt->fetchAll();

            $subtotal = 0;
            foreach ($items as &$item) {
                $item['subtotal'] = (float)$item['quantity'] * (float)$item['price'];
                $subtotal += $item['subtotal'];
            }
            $order['subtotal_calculated'] = $subtotal;
            $order['items'] = $items;
            $order['history'] = $history;
            $order['is_delayed'] = $this->checkDelay($order);

            return ['success' => true, 'data' => $order];
        } catch (PDOException $e) {
            $this->logError("GetOrderById Error: Order ID $id - " . $e->getMessage());
            return ['success' => false, 'message' => "خطا در دریافت سفارش $id: " . $e->getMessage()];
        }
    }

    // Check if an order is delayed
    private function checkDelay(array $order): bool {
        $deliveryDate = DateTime::createFromFormat('Y-m-d', date('Y-m-d', strtotime("+{$order['delivery_date']} days", strtotime($order['order_date']))));
        $currentDate = new DateTime();
        return $order['status'] !== 'completed' && $deliveryDate < $currentDate;
    }

    // Update order status with history and notifications
    public function updateOrderStatus(int $id, string $status, ?int $userId = null): array {
        if (!array_key_exists($status, ORDER_STATUSES)) {
            return ['success' => false, 'message' => "وضعیت '$status' نامعتبر است"];
        }
        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare("
                UPDATE photonim_db.`order` 
                SET status = :status, updated_at = NOW() 
                WHERE id = :id
            ");
            $success = $stmt->execute([':id' => $id, ':status' => $status]);

            if ($success) {
                $historyStmt = $this->pdo->prepare("
                    INSERT INTO photonim_db.order_status_history (order_id, status, changed_by, notes)
                    VALUES (:order_id, :status, :changed_by, :notes)
                ");
                $historyStmt->execute([
                    ':order_id' => $id,
                    ':status' => $status,
                    ':changed_by' => $userId,
                    ':notes' => "تغییر وضعیت به " . ORDER_STATUSES[$status] . " توسط " . ($userId ? "کاربر $userId" : "سیستم")
                ]);
                $this->sendNotification($id, 'email', 'customer@example.com', "وضعیت سفارش $id به " . ORDER_STATUSES[$status] . " تغییر یافت");
            }
            $this->pdo->commit();
            return [
                'success' => $success,
                'message' => $success ? "وضعیت سفارش $id به " . ORDER_STATUSES[$status] . " تغییر یافت" : "خطا در به‌روزرسانی وضعیت سفارش $id"
            ];
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->logError("UpdateStatus Error: Order ID $id - " . $e->getMessage());
            return ['success' => false, 'message' => "خطا در به‌روزرسانی وضعیت سفارش $id: " . $e->getMessage()];
        }
    }

    // Update order priority
    public function updateOrderPriority(int $id, string $priority): array {
        if (!array_key_exists($priority, ORDER_PRIORITIES)) {
            return ['success' => false, 'message' => "اولویت '$priority' نامعتبر است"];
        }
        try {
            $stmt = $this->pdo->prepare("
                UPDATE photonim_db.`order` 
                SET priority = :priority, updated_at = NOW() 
                WHERE id = :id
            ");
            $success = $stmt->execute([':id' => $id, ':priority' => $priority]);
            if ($success) {
                $this->sendNotification($id, 'system', 'admin', "اولویت سفارش $id به " . ORDER_PRIORITIES[$priority] . " تغییر یافت");
            }
            return [
                'success' => $success,
                'message' => $success ? "اولویت سفارش $id به " . ORDER_PRIORITIES[$priority] . " تغییر یافت" : "خطا در به‌روزرسانی اولویت سفارش $id"
            ];
        } catch (PDOException $e) {
            $this->logError("UpdatePriority Error: Order ID $id - " . $e->getMessage());
            return ['success' => false, 'message' => "خطا در به‌روزرسانی اولویت سفارش $id: " . $e->getMessage()];
        }
    }

    // Batch update order statuses
    public function batchUpdateStatus(array $orderIds, string $status, ?int $userId = null): array {
        if (!array_key_exists($status, ORDER_STATUSES)) {
            return ['success' => false, 'message' => "وضعیت '$status' نامعتبر است"];
        }
        if (empty($orderIds)) {
            return ['success' => false, 'message' => "هیچ سفارشی برای به‌روزرسانی انتخاب نشده است"];
        }
        try {
            $this->pdo->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            $stmt = $this->pdo->prepare("
                UPDATE photonim_db.`order` 
                SET status = ?, updated_at = NOW() 
                WHERE id IN ($placeholders)
            ");
            $params = array_merge([$status], $orderIds);
            $success = $stmt->execute($params);

            if ($success) {
                $historyStmt = $this->pdo->prepare("
                    INSERT INTO photonim_db.order_status_history (order_id, status, changed_by, notes)
                    VALUES (:order_id, :status, :changed_by, :notes)
                ");
                foreach ($orderIds as $id) {
                    $historyStmt->execute([
                        ':order_id' => $id,
                        ':status' => $status,
                        ':changed_by' => $userId,
                        ':notes' => "تغییر گروهی وضعیت به " . ORDER_STATUSES[$status] . " توسط " . ($userId ? "کاربر $userId" : "سیستم")
                    ]);
                    $this->sendNotification($id, 'system', 'admin', "وضعیت سفارش $id به‌صورت گروهی به " . ORDER_STATUSES[$status] . " تغییر یافت");
                }
            }
            $this->pdo->commit();
            return [
                'success' => $success,
                'message' => $success ? "وضعیت " . count($orderIds) . " سفارش به " . ORDER_STATUSES[$status] . " تغییر یافت" : "خطا در به‌روزرسانی گروهی"
            ];
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->logError("BatchUpdateStatus Error: " . $e->getMessage());
            return ['success' => false, 'message' => "خطا در به‌روزرسانی گروهی: " . $e->getMessage()];
        }
    }

    // Search orders with advanced filtering
    public function searchOrders(string $keyword = '', string $field = 'all', string $priority = '', string $status = '', int $limit = 5, int $offset = 0): array {
        try {
            $fields = ['id', 'customer_id', 'subtotal', 'description', 'delivery_date', 'discount', 'payment', 'total', 'balance', 'order_date', 'status', 'priority'];
            $sql = "SELECT o.*, c.fname, c.lname, c.phone 
                    FROM photonim_db.`order` o 
                    LEFT JOIN photonim_db.customerregistration c ON o.customer_id = c.id";
            $params = [];
            $conditions = [];

            if ($keyword !== '') {
                $keyword = trim($keyword);
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
                } elseif ($field === 'customer') {
                    $conditions[] = "c.fname LIKE :kw0";
                    $conditions[] = "c.lname LIKE :kw1";
                    $conditions[] = "c.phone LIKE :kw2";
                    $params[":kw0"] = "%$keyword%";
                    $params[":kw1"] = "%$keyword%";
                    $params[":kw2"] = "%$keyword%";
                } elseif (in_array($field, $fields)) {
                    $param = ":kw0";
                    $conditions[] = "o.$field LIKE $param";
                    $params[$param] = "%$keyword%";
                }
            }

            if ($priority !== '' && array_key_exists($priority, ORDER_PRIORITIES)) {
                $conditions[] = "o.priority = :priority";
                $params[":priority"] = $priority;
            }
            if ($status !== '' && array_key_exists($status, ORDER_STATUSES)) {
                $conditions[] = "o.status = :status";
                $params[":status"] = $status;
            }

            if (!empty($conditions)) {
                $sql .= " WHERE (" . implode(' OR ', $conditions) . ")";
            }

            $sql .= " ORDER BY CASE o.priority 
                    WHEN 'urgent' THEN 1 
                    WHEN 'high' THEN 2 
                    WHEN 'normal' THEN 3 
                    END, o.created_at DESC LIMIT :limit OFFSET :offset";
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
                    $item['subtotal'] = (float)$item['quantity'] * (float)$item['price'];
                    $subtotal += $item['subtotal'];
                }
                $order['subtotal_calculated'] = $subtotal;
                $order['items'] = $items;
                $order['is_delayed'] = $this->checkDelay($order);
            }

            return ['success' => true, 'data' => $orders];
        } catch (PDOException $e) {
            $this->logError("SearchOrders Error: " . $e->getMessage());
            return ['success' => false, 'message' => "خطا در جستجوی سفارشات: " . $e->getMessage()];
        }
    }

    // Get total number of orders for pagination
    public function getTotalOrders(string $keyword = '', string $field = 'all', string $priority = '', string $status = ''): int {
        try {
            $fields = ['id', 'customer_id', 'subtotal', 'description', 'delivery_date', 'discount', 'payment', 'total', 'balance', 'order_date', 'status', 'priority'];
            $sql = "SELECT COUNT(*) 
                    FROM photonim_db.`order` o 
                    LEFT JOIN photonim_db.customerregistration c ON o.customer_id = c.id";
            $params = [];
            $conditions = [];

            if ($keyword !== '') {
                $keyword = trim($keyword);
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
                } elseif ($field === 'customer') {
                    $conditions[] = "c.fname LIKE :kw0";
                    $conditions[] = "c.lname LIKE :kw1";
                    $conditions[] = "c.phone LIKE :kw2";
                    $params[":kw0"] = "%$keyword%";
                    $params[":kw1"] = "%$keyword%";
                    $params[":kw2"] = "%$keyword%";
                } elseif (in_array($field, $fields)) {
                    $param = ":kw0";
                    $conditions[] = "o.$field LIKE $param";
                    $params[$param] = "%$keyword%";
                }
            }

            if ($priority !== '' && array_key_exists($priority, ORDER_PRIORITIES)) {
                $conditions[] = "o.priority = :priority";
                $params[":priority"] = $priority;
            }
            if ($status !== '' && array_key_exists($status, ORDER_STATUSES)) {
                $conditions[] = "o.status = :status";
                $params[":status"] = $status;
            }

            if (!empty($conditions)) {
                $sql .= " WHERE (" . implode(' OR ', $conditions) . ")";
            }

            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $param => $value) {
                $stmt->bindValue($param, $value, PDO::PARAM_STR);
            }
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            $this->logError("GetTotalOrders Error: " . $e->getMessage());
            return 0;
        }
    }

    // Generate sales report
    public function getSalesReport(string $period = 'daily'): array {
        try {
            $dateFilter = match ($period) {
                'daily' => "DATE(o.order_date) = CURDATE()",
                'weekly' => "YEARWEEK(o.order_date) = YEARWEEK(CURDATE())",
                'monthly' => "MONTH(o.order_date) = MONTH(CURDATE()) AND YEAR(o.order_date) = YEAR(CURDATE())",
                'yearly' => "YEAR(o.order_date) = YEAR(CURDATE())",
                default => "1=1"
            };

            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_orders,
                    SUM(o.total) as total_revenue,
                    SUM(o.payment) as total_payments,
                    SUM(CASE WHEN o.status = 'canceled' THEN 1 ELSE 0 END) as canceled_orders,
                    AVG(o.total) as avg_order_value
                FROM photonim_db.`order` o
                WHERE $dateFilter
            ");
            $stmt->execute();
            $report = $stmt->fetch();

            return [
                'success' => true,
                'data' => [
                    'total_orders' => (int)($report['total_orders'] ?? 0),
                    'total_revenue' => (float)($report['total_revenue'] ?? 0),
                    'total_payments' => (float)($report['total_payments'] ?? 0),
                    'canceled_orders' => (int)($report['canceled_orders'] ?? 0),
                    'avg_order_value' => (float)($report['avg_order_value'] ?? 0)
                ]
            ];
        } catch (PDOException $e) {
            $this->logError("GetSalesReport Error: " . $e->getMessage());
            return ['success' => false, 'message' => "خطا در گزارش‌گیری: " . $e->getMessage()];
        }
    }

    // Forecast revenue based on historical data
    public function forecastRevenue(): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    AVG(o.total) as avg_order_value,
                    COUNT(*) as total_orders
                FROM photonim_db.`order` o
                WHERE o.order_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH) AND o.status = 'completed'
            ");
            $stmt->execute();
            $data = $stmt->fetch();

            $avgOrderValue = (float)($data['avg_order_value'] ?? 0);
            $monthlyOrders = (float)(($data['total_orders'] ?? 0) / 3);
            $forecastedRevenue = $avgOrderValue * $monthlyOrders * 12;

            return [
                'success' => true,
                'data' => [
                    'avg_order_value' => $avgOrderValue,
                    'monthly_orders' => $monthlyOrders,
                    'forecasted_revenue' => $forecastedRevenue,
                    'confidence' => $data['total_orders'] > 10 ? 'بالا' : 'متوسط'
                ]
            ];
        } catch (PDOException $e) {
            $this->logError("ForecastRevenue Error: " . $e->getMessage());
            return ['success' => false, 'message' => "خطا در پیش‌بینی درآمد: " . $e->getMessage()];
        }
    }

    // Dashboard statistics
    public function getDashboardStats(): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM photonim_db.`order` WHERE status = 'pending') as pending_orders,
                    (SELECT COUNT(*) FROM photonim_db.`order` WHERE status = 'in_progress') as in_progress_orders,
                    (SELECT SUM(total) FROM photonim_db.`order` WHERE status = 'completed' AND order_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as monthly_revenue,
                    (SELECT COUNT(*) FROM photonim_db.`order` WHERE status NOT IN ('completed', 'canceled') AND DATE_ADD(order_date, INTERVAL delivery_date DAY) < CURDATE()) as delayed_orders
            ");
            $stmt->execute();
            $stats = $stmt->fetch();

            return [
                'success' => true,
                'data' => [
                    'pending_orders' => (int)($stats['pending_orders'] ?? 0),
                    'in_progress_orders' => (int)($stats['in_progress_orders'] ?? 0),
                    'monthly_revenue' => (float)($stats['monthly_revenue'] ?? 0),
                    'delayed_orders' => (int)($stats['delayed_orders'] ?? 0)
                ]
            ];
        } catch (PDOException $e) {
            $this->logError("GetDashboardStats Error: " . $e->getMessage());
            return ['success' => false, 'message' => "خطا در دریافت آمار داشبورد: " . $e->getMessage()];
        }
    }

    // AI-driven optimization suggestions
    public function suggestOptimization(int $orderId): array {
        try {
            $orderResult = $this->getOrderById($orderId);
            if (!$orderResult['success']) {
                return $orderResult;
            }
            $order = $orderResult['data'];

            $suggestions = [];
            if ($order['is_delayed']) {
                $suggestions[] = "سفارش $orderId تأخیر دارد - پیشنهاد: افزایش اولویت به 'فوری' و اطلاع‌رسانی به مشتری.";
            }
            if ($order['balance'] > 0) {
                $suggestions[] = "باقیمانده پرداخت: " . number_format($order['balance']) . " تومان - پیشنهاد: ارسال یادآور پرداخت به مشتری.";
            }
            if ($order['subtotal_calculated'] > $order['total']) {
                $suggestions[] = "مبلغ کل کمتر از جمع جزئی است - پیشنهاد: بررسی تخفیف‌ها یا خطای محاسباتی.";
            }

            return [
                'success' => true,
                'data' => $suggestions ?: ["هیچ پیشنهادی برای سفارش $orderId موجود نیست"]
            ];
        } catch (Exception $e) {
            $this->logError("SuggestOptimization Error: Order ID $orderId - " . $e->getMessage());
            return ['success' => false, 'message' => "خطا در ارائه پیشنهاد برای سفارش $orderId: " . $e->getMessage()];
        }
    }
}

// Database connection check
if (!isset($conn) || !$conn instanceof PDO) {
    http_response_code(500);
    exit("<p style='color: #EF4444; text-align: center; font-family: Vazirmatn;'>خطا: اتصال به دیتابیس برقرار نیست.</p>");
}

$orderManager = new OrderManager($conn);

// Pagination and request handling
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
        if ($response && $response['success']) {
            $queryParams = "?page=$page" . (isset($_GET['query']) ? "&query=" . urlencode($_GET['query']) . "&search_field=" . urlencode($_GET['search_field'] ?? 'all') : "");
            header("Location: " . $_SERVER['PHP_SELF'] . $queryParams);
            exit;
        }
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_status'], $_POST['order_id'], $_POST['status'])) {
        $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
        $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
        if ($orderId && $status) {
            $response = $orderManager->updateOrderStatus($orderId, $status, $_SESSION['user_id'] ?? null);
            if ($response['success']) {
                $queryParams = "?page=$page" . (isset($_GET['query']) ? "&query=" . urlencode($_GET['query']) . "&search_field=" . urlencode($_GET['search_field'] ?? 'all') : "");
                header("Location: " . $_SERVER['PHP_SELF'] . $queryParams);
                exit;
            }
        }
    } elseif (isset($_POST['batch_update'], $_POST['order_ids'], $_POST['status'])) {
        $orderIds = array_map('intval', (array)$_POST['order_ids']);
        $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
        if (!empty($orderIds) && $status) {
            $response = $orderManager->batchUpdateStatus($orderIds, $status, $_SESSION['user_id'] ?? null);
            if ($response['success']) {
                $queryParams = "?page=$page" . (isset($_GET['query']) ? "&query=" . urlencode($_GET['query']) . "&search_field=" . urlencode($_GET['search_field'] ?? 'all') : "");
                header("Location: " . $_SERVER['PHP_SELF'] . $queryParams);
                exit;
            }
        }
    }
}

// Search and filter parameters
$searchKeyword = filter_input(INPUT_GET, 'query', FILTER_UNSAFE_RAW) ?? '';
$searchKeyword = htmlspecialchars($searchKeyword, ENT_QUOTES, 'UTF-8');
$searchField = filter_input(INPUT_GET, 'search_field', FILTER_UNSAFE_RAW) ?? 'all';
$searchField = htmlspecialchars($searchField, ENT_QUOTES, 'UTF-8');
$priorityFilter = filter_input(INPUT_GET, 'priority', FILTER_UNSAFE_RAW) ?? '';
$statusFilter = filter_input(INPUT_GET, 'status', FILTER_UNSAFE_RAW) ?? '';

$searchResult = $orderManager->searchOrders($searchKeyword, $searchField, $priorityFilter, $statusFilter, $limit, $offset);
$orders = $searchResult['success'] ? $searchResult['data'] : [];
$totalOrders = $orderManager->getTotalOrders($searchKeyword, $searchField, $priorityFilter, $statusFilter);
$totalPages = ceil($totalOrders / $limit);

$dashboardStats = $orderManager->getDashboardStats();
$salesReport = $orderManager->getSalesReport('monthly');
$revenueForecast = $orderManager->forecastRevenue();
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="سیستم مدیریت سفارشات حرفه‌ای استودیو نیما">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="Image/Logo1.png" type="image/png" sizes="80x80">
    <title>استودیو نیما | مدیریت سفارشات</title>
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
            --danger: #DC2626;
            --warning: #F59E0B;
            --border: #E2E8F0;
            --shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            --radius: 6px;
            --spacing-unit: 1rem;
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
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .container {
            max-width: 1400px;
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

        .nav-btn {
            padding: 8px 16px;
            background: var(--accent);
            color: var(--secondary-bg);
            text-decoration: none;
            border-radius: var(--radius);
            font-weight: 500;
            transition: background 0.2s ease-out;
        }

        .nav-btn:hover {
            background: var(--accent-hover);
        }

        .nav-btn i {
            margin-left: 6px;
        }

        .header {
            text-align: center;
            padding: calc(var(--spacing-unit) * 1.5) 0;
        }

        .header-title {
            font-size: 2rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .header-divider {
            width: 100px;
            height: 3px;
            background: var(--accent);
            margin: calc(var(--spacing-unit) * 0.5) auto;
            border-radius: var(--radius);
        }

        .dashboard-section {
            background: var(--secondary-bg);
            padding: var(--spacing-unit);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: calc(var(--spacing-unit) * 1.5);
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--spacing-unit);
        }

        .dashboard-item {
            text-align: center;
            padding: calc(var(--spacing-unit) * 0.75);
            background: #F1F5F9;
            border-radius: var(--radius);
            border: 1px solid var(--border);
            transition: transform 0.2s ease-out;
        }

        .dashboard-item:hover {
            transform: translateY(-2px);
        }

        .dashboard-label {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .dashboard-value {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-dark);
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

        .search-select, .search-input {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 0.9rem;
            background: var(--secondary-bg);
            color: var(--text-dark);
        }

        .search-input {
            flex: 1;
            min-width: 200px;
        }

        .search-btn {
            padding: 8px 16px;
            background: var(--accent);
            color: var(--secondary-bg);
            border: none;
            border-radius: var(--radius);
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s ease-out;
        }

        .search-btn:hover {
            background: var(--accent-hover);
        }

        .search-btn i {
            margin-left: 6px;
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
            border: 1px solid var(--border);
            transition: box-shadow 0.2s ease-out;
        }

        .order-card:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .order-card.delayed {
            border-left: 4px solid var(--warning);
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: calc(var(--spacing-unit) * 0.75);
            border-bottom: 1px solid var(--border);
            margin-bottom: calc(var(--spacing-unit) * 0.75);
        }

        .order-id {
            font-weight: 600;
            font-size: 1.1rem;
        }

        .order-id i {
            margin-left: 6px;
            color: var(--accent);
        }

        .order-status {
            padding: 6px 12px;
            border-radius: var(--radius);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .order-status.pending { color: var(--status-pending); background: rgba(245, 158, 11, 0.1); }
        .order-status.in-progress { color: var(--status-in-progress); background: rgba(37, 99, 235, 0.1); }
        .order-status.completed { color: var(--status-completed); background: rgba(22, 163, 74, 0.1); }
        .order-status.canceled { color: var(--status-canceled); background: rgba(220, 38, 38, 0.1); }

        .order-status i {
            margin-left: 6px;
        }

        .order-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: calc(var(--spacing-unit) * 0.75);
            padding: calc(var(--spacing-unit) * 0.75) 0;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .detail-label {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .detail-value {
            font-size: 0.95rem;
            font-weight: 500;
        }

        .detail-value i {
            margin-left: 6px;
            color: var(--accent);
        }

        .order-actions {
            display: flex;
            gap: calc(var(--spacing-unit) * 0.5);
            justify-content: flex-end;
            align-items: center;
            padding-top: calc(var(--spacing-unit) * 0.75);
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 6px 14px;
            border-radius: var(--radius);
            text-decoration: none;
            font-weight: 500;
            color: var(--secondary-bg);
            background: var(--accent);
            transition: background 0.2s ease-out;
        }

        .action-btn:hover {
            background: var(--accent-hover);
        }

        .action-btn.delete { background: var(--danger); }
        .action-btn.delete:hover { background: #B91C1C; }
        .action-btn.edit { background: var(--success); }
        .action-btn.edit:hover { background: #15803D; }
        .action-btn.details { background: var(--warning); }
        .action-btn.details:hover { background: #D97706; }

        .action-btn i {
            margin-left: 6px;
        }

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
            color: var(--text-dark);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s ease-out;
        }

        .status-btn:hover:not(:disabled) {
            background: var(--accent);
            color: var(--secondary-bg);
            border-color: var(--accent);
        }

        .status-btn.active, .status-btn:disabled {
            background: var(--accent);
            color: var(--secondary-bg);
            border-color: var(--accent);
            opacity: 0.7;
            cursor: not-allowed;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: calc(var(--spacing-unit) * 0.75);
            padding: calc(var(--spacing-unit) * 1.5) 0;
        }

        .pagination-btn {
            padding: 8px 16px;
            background: var(--accent);
            color: var(--secondary-bg);
            border-radius: var(--radius);
            text-decoration: none;
            font-weight: 500;
            transition: background 0.2s ease-out;
        }

        .pagination-btn:hover:not(.disabled) {
            background: var(--accent-hover);
        }

        .pagination-btn.disabled {
            background: var(--border);
            color: var(--text-muted);
            pointer-events: none;
        }

        .pagination-btn i {
            margin-right: 6px;
        }

        .pagination-info {
            padding: 8px 14px;
            background: var(--secondary-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .no-results {
            text-align: center;
            padding: var(--spacing-unit);
            background: var(--secondary-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            color: var(--text-muted);
            font-size: 1rem;
        }

        .batch-actions {
            margin-bottom: calc(var(--spacing-unit) * 1.5);
            display: flex;
            gap: calc(var(--spacing-unit) * 0.5);
            flex-wrap: wrap;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 2000;
            display: none;
        }

        .modal-overlay.active {
            display: block;
        }

        .modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: var(--secondary-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
            z-index: 2100;
            display: none;
        }

        .modal.active {
            display: block;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: var(--spacing-unit);
            border-bottom: 1px solid var(--border);
            background: #F1F5F9;
        }

        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .modal-title i {
            margin-left: 6px;
            color: var(--accent);
        }

        .modal-close {
            padding: 6px 12px;
            background: var(--danger);
            color: var(--secondary-bg);
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
        }

        .modal-close:hover {
            background: #B91C1C;
        }

        .modal-body {
            padding: var(--spacing-unit);
        }

        .modal-order-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: calc(var(--spacing-unit) * 0.75);
            margin-bottom: var(--spacing-unit);
        }

        .modal-detail-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .modal-detail-label {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .modal-detail-value {
            font-size: 0.95rem;
            font-weight: 500;
        }

        .modal-detail-value i {
            margin-left: 6px;
            color: var(--accent);
        }

        .order-items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: var(--spacing-unit);
        }

        .order-items-table th, .order-items-table td {
            padding: calc(var(--spacing-unit) * 0.75);
            border: 1px solid var(--border);
            text-align: right;
            font-size: 0.9rem;
        }

        .order-items-table th {
            background: #F1F5F9;
            font-weight: 600;
        }

        .order-items-table tfoot td {
            font-weight: 600;
            background: #F9FAFB;
        }

        h3 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: calc(var(--spacing-unit) * 0.5);
        }
    </style>
</head>
<body>
    <div class="nav-bar">
        <nav class="nav-content">
            <a href="Customer%20Registration.php" class="nav-btn"><i class="fas fa-user-plus"></i> ثبت مشتری</a>
            <a href="Order.php" class="nav-btn"><i class="fas fa-cart-plus"></i> ثبت سفارش</a>
            <a href="Customer%20List.php" class="nav-btn"><i class="fas fa-users"></i> لیست مشتریان</a>
            <a href="Order%20List.php" class="nav-btn"><i class="fas fa-list-ul"></i> لیست سفارشات</a>
            <a href="Products%20List.php" class="nav-btn"><i class="fas fa-boxes"></i> لیست محصولات</a>
            <a href="Index.php" class="nav-btn"><i class="fas fa-sign-out-alt"></i> خروج</a>
        </nav>
    </div>

    <div class="container">
        <header class="header">
            <h1 class="header-title">مدیریت سفارشات</h1>
            <div class="header-divider"></div>
        </header>

        <section class="dashboard-section">
            <div class="dashboard-item">
                <div class="dashboard-label">سفارشات در انتظار</div>
                <div class="dashboard-value"><?= $dashboardStats['success'] ? $dashboardStats['data']['pending_orders'] : 'خطا' ?></div>
            </div>
            <div class="dashboard-item">
                <div class="dashboard-label">سفارشات در حال انجام</div>
                <div class="dashboard-value"><?= $dashboardStats['success'] ? $dashboardStats['data']['in_progress_orders'] : 'خطا' ?></div>
            </div>
            <div class="dashboard-item">
                <div class="dashboard-label">درآمد ماهانه</div>
                <div class="dashboard-value"><?= $dashboardStats['success'] ? number_format($dashboardStats['data']['monthly_revenue']) . ' ت' : 'خطا' ?></div>
            </div>
            <div class="dashboard-item">
                <div class="dashboard-label">سفارشات تأخیردار</div>
                <div class="dashboard-value"><?= $dashboardStats['success'] ? $dashboardStats['data']['delayed_orders'] : 'خطا' ?></div>
            </div>
        </section>

        <section class="search-section">
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
                    <option value="priority" <?= $searchField === 'priority' ? 'selected' : '' ?>>اولویت</option>
                </select>
                <input type="text" name="query" class="search-input" value="<?= htmlspecialchars($searchKeyword) ?>" placeholder="جستجو در سفارشات...">
                <select name="priority" class="search-select">
                    <option value="">همه اولویت‌ها</option>
                    <?php foreach (ORDER_PRIORITIES as $value => $label): ?>
                        <option value="<?= $value ?>" <?= $priorityFilter === $value ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status" class="search-select">
                    <option value="">همه وضعیت‌ها</option>
                    <?php foreach (ORDER_STATUSES as $value => $label): ?>
                        <option value="<?= $value ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="search-btn"><i class="fas fa-search"></i> جستجو</button>
            </form>
        </section>

        <?php if (!empty($orders)): ?>
            <form method="POST" class="batch-actions" id="batch-form">
                <select name="status" class="search-select">
                    <option value="">انتخاب وضعیت برای به‌روزرسانی</option>
                    <?php foreach (ORDER_STATUSES as $value => $label): ?>
                        <option value="<?= $value ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="batch_update" class="search-btn"><i class="fas fa-sync"></i> به‌روزرسانی گروهی</button>
            </form>
        <?php endif; ?>

        <section class="orders-section">
            <?php if (!empty($orders)): ?>
                <?php foreach ($orders as $order): ?>
                    <?php $optimization = $orderManager->suggestOptimization($order['id']); ?>
                    <div class="order-card <?= $order['is_delayed'] ? 'delayed' : '' ?>">
                        <div class="order-header">
                            <span class="order-id"><i class="fas fa-id-badge"></i> سفارش #<?= htmlspecialchars($order['id']) ?></span>
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
                                <span class="detail-label">شماره تماس</span>
                                <span class="detail-value"><i class="fas fa-phone"></i> <?= htmlspecialchars($order['phone'] ?? '-') ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">جمع جزئی</span>
                                <span class="detail-value"><i class="fas fa-money-bill"></i> <?= number_format($order['subtotal_calculated']) ?> ت</span>
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
                                <span class="detail-label">اولویت</span>
                                <span class="detail-value"><i class="fas fa-exclamation-circle"></i> <?= ORDER_PRIORITIES[$order['priority']] ?></span>
                            </div>
                        </div>
                        <div class="order-actions">
                            <input type="checkbox" name="order_ids[]" value="<?= $order['id'] ?>" form="batch-form">
                            <a href="?Dele=<?= $order['id'] ?>" class="action-btn delete" onclick="return confirm('آیا از حذف سفارش <?= $order['id'] ?> مطمئن هستید؟')"><i class="fas fa-trash"></i> حذف</a>
                            <a href="?Edit=<?= $order['id'] ?>" class="action-btn edit"><i class="fas fa-edit"></i> ویرایش</a>
                            <a href="?GenerateInvoice=<?= $order['id'] ?>" class="action-btn"><i class="fas fa-file-invoice"></i> فاکتور</a>
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
            <div class="pagination">
                <a href="?page=<?= $page - 1 ?>&query=<?= urlencode($searchKeyword) ?>&search_field=<?= urlencode($searchField) ?>&priority=<?= urlencode($priorityFilter) ?>&status=<?= urlencode($statusFilter) ?>" 
                   class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>"><i class="fas fa-chevron-right"></i> قبلی</a>
                <span class="pagination-info">صفحه <?= $page ?> از <?= $totalPages ?> (<?= $totalOrders ?> سفارش)</span>
                <a href="?page=<?= $page + 1 ?>&query=<?= urlencode($searchKeyword) ?>&search_field=<?= urlencode($searchField) ?>&priority=<?= urlencode($priorityFilter) ?>&status=<?= urlencode($statusFilter) ?>" 
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
                    <span class="modal-detail-label">شماره تماس</span>
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
                <div class="modal-detail-item">
                    <span class="modal-detail-label">اولویت</span>
                    <span class="modal-detail-value"><i class="fas fa-exclamation-circle"></i> <span id="modal-order-priority"></span></span>
                </div>
            </div>

            <h3>اقلام سفارش</h3>
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

            <h3>تاریخچه وضعیت</h3>
            <table class="order-items-table">
                <thead>
                    <tr>
                        <th>وضعیت</th>
                        <th>تغییر توسط</th>
                        <th>تاریخ تغییر</th>
                        <th>یادداشت</th>
                    </tr>
                </thead>
                <tbody id="modal-history"></tbody>
            </table>
        </div>
    </div>

    <?php if (isset($response) && !$response['success']): ?>
        <script>
            alert('<?= addslashes($response['message']) ?>');
        </script>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const overlay = document.querySelector('.modal-overlay');
            const modal = document.querySelector('.modal');
            const modalItems = document.getElementById('modal-items');
            const modalHistory = document.getElementById('modal-history');
            const modalOrderId = document.getElementById('modal-order-id');
            const modalOrderCustomer = document.getElementById('modal-order-customer');
            const modalOrderPhone = document.getElementById('modal-order-phone');
            const modalOrderDate = document.getElementById('modal-order-date');
            const modalOrderDelivery = document.getElementById('modal-order-delivery');
            const modalOrderDescription = document.getElementById('modal-order-description');
            const modalOrderPriority = document.getElementById('modal-order-priority');
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
                modalOrderPriority.textContent = <?= json_encode(ORDER_PRIORITIES) ?>[order.priority] || '-';
                modalItems.innerHTML = '';
                modalHistory.innerHTML = '';

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

                order.history.forEach((entry) => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${<?= json_encode(ORDER_STATUSES) ?>[entry.status] || '-'}</td>
                        <td>${entry.username || 'سیستم'}</td>
                        <td>${entry.changed_at}</td>
                        <td>${entry.notes || '-'}</td>
                    `;
                    modalHistory.appendChild(row);
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