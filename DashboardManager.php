<?php
session_start();
require_once 'DB Config.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

const ORDER_STATUSES = [
    'pending' => 'در انتظار',
    'in_progress' => 'در حال انجام',
    'completed' => 'تکمیل شده',
    'canceled' => 'لغو شده'
];

class DashboardManager {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    private function logError(string $message, array $context = []): void {
        $logMessage = date('Y-m-d H:i:s') . " - ERROR: $message";
        if ($context) {
            $logMessage .= " | Context: " . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        file_put_contents('error.log', "$logMessage\n", FILE_APPEND);
    }

    private function logDebug(string $message, array $context = []): void {
        $logMessage = date('Y-m-d H:i:s') . " - DEBUG: $message";
        if ($context) {
            $logMessage .= " | Context: " . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        file_put_contents('debug.log', "$logMessage\n", FILE_APPEND);
    }

    public function getDateRange(): array {
        $rangeType = $_GET['range_type'] ?? 'this_month';
        $this->logDebug("Received range_type", ['range_type' => $rangeType, 'GET' => $_GET]);
        $today = new DateTime('now', new DateTimeZone('Asia/Tehran'));
        $startDate = null;
        $endDate = null;
        $errors = [];

        if ($rangeType === 'custom' && isset($_GET['start_date']) && isset($_GET['end_date'])) {
            $startDate = $_GET['start_date'];
            $endDate = $_GET['end_date'];

            $startDateObj = DateTime::createFromFormat('Y-m-d', $startDate);
            $endDateObj = DateTime::createFromFormat('Y-m-d', $endDate);

            if (!$startDateObj || !$endDateObj) {
                $errors[] = "فرمت تاریخ سفارشی نامعتبر است.";
                $startDate = (clone $today)->modify('first day of this month')->format('Y-m-d');
                $endDate = $today->format('Y-m-d');
            } elseif ($startDateObj > $endDateObj) {
                [$startDate, $endDate] = [$endDate, $startDate];
                $errors[] = "تاریخ شروع بعد از تاریخ پایان بود، تعویض شدند.";
            } elseif ($endDateObj > $today) {
                $endDate = $today->format('Y-m-d');
                $errors[] = "تاریخ پایان نمی‌تواند از امروز بیشتر باشد.";
            }
        } else {
            switch ($rangeType) {
                case 'today':
                    $startDate = $today->format('Y-m-d');
                    $endDate = $startDate;
                    break;
                case 'yesterday':
                    $startDate = (clone $today)->modify('-1 day')->format('Y-m-d');
                    $endDate = $startDate;
                    break;
                case 'this_week':
                    $startDate = (clone $today)->modify('monday this week')->format('Y-m-d');
                    $endDate = $today->format('Y-m-d');
                    break;
                case 'last_week':
                    $startDate = (clone $today)->modify('monday last week')->format('Y-m-d');
                    $endDate = (clone $today)->modify('sunday last week')->format('Y-m-d');
                    break;
                case 'this_month':
                    $startDate = (clone $today)->modify('first day of this month')->format('Y-m-d');
                    $endDate = $today->format('Y-m-d');
                    break;
                case 'last_month':
                    $startDate = (clone $today)->modify('first day of last month')->format('Y-m-d');
                    $endDate = (clone $today)->modify('last day of last month')->format('Y-m-d');
                    break;
                case 'this_year':
                    $startDate = (clone $today)->modify('first day of January this year')->format('Y-m-d');
                    $endDate = $today->format('Y-m-d');
                    break;
                case 'last_year':
                    $startDate = (clone $today)->modify('first day of January last year')->format('Y-m-d');
                    $endDate = (clone $today)->modify('last day of December last year')->format('Y-m-d');
                    break;
                case 'all':
                default:
                    $startDate = (clone $today)->modify('-1 year')->format('Y-m-d');
                    $endDate = $today->format('Y-m-d');
                    $rangeType = 'this_month'; // مقدار پیش‌فرض منطقی‌تر
                    break;
            }
        }

        $this->logDebug("Date Range Calculated", ['range_type' => $rangeType, 'start' => $startDate, 'end' => $endDate]);
        if ($errors) {
            $this->logError("Date Range Issues", ['errors' => $errors]);
        }

        return [
            'start' => $startDate,
            'end' => $endDate,
            'errors' => $errors,
            'range_type' => $rangeType
        ];
    }

    public function getFinancialOverview(string $startDate, string $endDate): array {
        try {
            $metrics = [
                'total' => [
                    'revenue' => "SELECT COALESCE(SUM(total), 0) FROM photonim_db.`order` WHERE order_date >= :start AND order_date <= :end AND status = 'completed'",
                    'pending' => "SELECT COALESCE(SUM(total), 0) FROM photonim_db.`order` WHERE order_date >= :start AND order_date <= :end AND status = 'pending'",
                    'profit' => "SELECT COALESCE(SUM((total - (SELECT COALESCE(SUM(oi.quantity * p.price), 0) FROM photonim_db.order_items oi JOIN photonim_db.pricelist p ON oi.product_id = p.id WHERE oi.order_id = o.id))), 0) FROM photonim_db.`order` o WHERE order_date >= :start AND order_date <= :end AND status = 'completed'"
                ]
            ];

            $params = [':start' => $startDate, ':end' => $endDate];
            $result = [];
            foreach ($metrics as $period => $queries) {
                foreach ($queries as $metric => $sql) {
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute($params);
                    $result[$period][$metric] = (float)$stmt->fetchColumn();
                }
            }
            return ['success' => true, 'data' => $result];
        } catch (PDOException $e) {
            $this->logError("Financial Overview Error: " . $e->getMessage(), ['start' => $startDate, 'end' => $endDate]);
            return ['success' => false, 'data' => []];
        }
    }

    public function getOrderStats(string $startDate, string $endDate): array {
        try {
            $stats = [
                'status_counts' => "SELECT status, COUNT(*) as count FROM photonim_db.`order` WHERE order_date >= :start AND order_date <= :end GROUP BY status",
                'new_customers' => "SELECT COUNT(*) FROM photonim_db.customerregistration WHERE created_at >= :start AND created_at <= :end",
                'avg_delivery_days' => "SELECT COALESCE(AVG(DATEDIFF(DATE_ADD(order_date, INTERVAL delivery_date DAY), order_date)), 0) FROM photonim_db.`order` WHERE order_date >= :start AND order_date <= :end AND status = 'completed'",
                'pending_value' => "SELECT COALESCE(SUM(balance), 0) FROM photonim_db.`order` WHERE order_date >= :start AND order_date <= :end AND status IN ('pending', 'in_progress')"
            ];

            $params = [':start' => $startDate, ':end' => $endDate];
            $result = ['status_counts' => array_fill_keys(array_keys(ORDER_STATUSES), 0)];
            $stmt = $this->pdo->prepare($stats['status_counts']);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $result['status_counts'][$row['status']] = (int)$row['count'];
            }
            $stmt = $this->pdo->prepare($stats['new_customers']);
            $stmt->execute($params);
            $result['new_customers'] = (int)$stmt->fetchColumn();
            $stmt = $this->pdo->prepare($stats['avg_delivery_days']);
            $stmt->execute($params);
            $result['avg_delivery_days'] = (float)$stmt->fetchColumn();
            $stmt = $this->pdo->prepare($stats['pending_value']);
            $stmt->execute($params);
            $result['pending_value'] = (float)$stmt->fetchColumn();

            return ['success' => true, 'data' => $result];
        } catch (PDOException $e) {
            $this->logError("Order Stats Error: " . $e->getMessage(), ['start' => $startDate, 'end' => $endDate]);
            return ['success' => false, 'data' => []];
        }
    }

    public function getTopProducts(string $startDate, string $endDate, int $limit = 10): array {
        try {
            $sql = "
                SELECT 
                    p.id, p.product_code, p.imagesize, p.type, p.color, p.price,
                    COALESCE(SUM(oi.quantity), 0) as total_sold,
                    COALESCE(SUM(oi.quantity * p.price), 0) as total_revenue,
                    COUNT(DISTINCT o.id) as order_count,
                    COALESCE(AVG(oi.quantity), 0) as avg_quantity_per_order
                FROM photonim_db.order_items oi
                JOIN photonim_db.pricelist p ON oi.product_id = p.id
                JOIN photonim_db.`order` o ON oi.order_id = o.id
                WHERE o.status = 'completed' AND o.order_date >= :start AND o.order_date <= :end
                GROUP BY p.id, p.product_code, p.imagesize, p.type, p.color, p.price
                ORDER BY total_sold DESC
                LIMIT :limit
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':start', $startDate);
            $stmt->bindValue(':end', $endDate);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return ['success' => true, 'data' => $stmt->fetchAll()];
        } catch (PDOException $e) {
            $this->logError("Top Products Error: " . $e->getMessage(), ['start' => $startDate, 'end' => $endDate]);
            return ['success' => false, 'data' => []];
        }
    }

    public function getTopCustomers(string $startDate, string $endDate, int $limit = 10): array {
        try {
            $sql = "
                SELECT c.id, c.fname, c.lname, c.phone, 
                       COALESCE(SUM(o.total), 0) as total_spent, 
                       COUNT(o.id) as order_count,
                       COALESCE(AVG(o.total), 0) as avg_order_value
                FROM photonim_db.customerregistration c
                JOIN photonim_db.`order` o ON c.id = o.customer_id
                WHERE o.status = 'completed' AND o.order_date >= :start AND o.order_date <= :end
                GROUP BY c.id, c.fname, c.lname, c.phone
                ORDER BY total_spent DESC
                LIMIT :limit
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':start', $startDate);
            $stmt->bindValue(':end', $endDate);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return ['success' => true, 'data' => $stmt->fetchAll()];
        } catch (PDOException $e) {
            $this->logError("Top Customers Error: " . $e->getMessage(), ['start' => $startDate, 'end' => $endDate]);
            return ['success' => false, 'data' => []];
        }
    }

    public function getKPIs(string $startDate, string $endDate): array {
        try {
            $result = [];

            $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT customer_id) FROM photonim_db.`order` WHERE order_date >= :start AND order_date <= :end AND status = 'completed'");
            $stmt->execute([':start' => $startDate, ':end' => $endDate]);
            $result['total_customers'] = (int)$stmt->fetchColumn();

            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM photonim_db.`order` WHERE order_date >= :start AND order_date <= :end AND status = 'completed'");
            $stmt->execute([':start' => $startDate, ':end' => $endDate]);
            $result['total_orders'] = (int)$stmt->fetchColumn();

            $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM photonim_db.`order` WHERE order_date >= :start AND order_date <= :end AND status = 'completed'");
            $stmt->execute([':start' => $startDate, ':end' => $endDate]);
            $result['total_revenue'] = (float)$stmt->fetchColumn();

            $stmt = $this->pdo->prepare("SELECT COALESCE(SUM((total - (SELECT COALESCE(SUM(oi.quantity * p.price), 0) FROM photonim_db.order_items oi JOIN photonim_db.pricelist p ON oi.product_id = p.id WHERE oi.order_id = o.id))), 0) FROM photonim_db.`order` o WHERE order_date >= :start AND order_date <= :end AND status = 'completed'");
            $stmt->execute([':start' => $startDate, ':end' => $endDate]);
            $result['total_profit'] = (float)$stmt->fetchColumn();

            $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT customer_id) FROM photonim_db.`order` WHERE order_date >= :start AND order_date <= :end AND status = 'completed' GROUP BY customer_id HAVING COUNT(*) > 1");
            $stmt->execute([':start' => $startDate, ':end' => $endDate]);
            $result['repeat_customers'] = $stmt->rowCount();
            $result['return_rate'] = $result['total_customers'] ? ($result['repeat_customers'] / $result['total_customers']) * 100 : 0;

            $result['aov'] = $result['total_orders'] ? $result['total_revenue'] / $result['total_orders'] : 0;
            $result['profit_margin'] = $result['total_revenue'] ? ($result['total_profit'] / $result['total_revenue']) * 100 : 0;

            $stmt = $this->pdo->prepare("SELECT COALESCE(AVG((SELECT SUM(quantity) FROM photonim_db.order_items oi WHERE oi.order_id = o.id)), 0) FROM photonim_db.`order` o WHERE order_date >= :start AND order_date <= :end AND status = 'completed'");
            $stmt->execute([':start' => $startDate, ':end' => $endDate]);
            $result['avg_items_per_order'] = (float)$stmt->fetchColumn();

            $stmt = $this->pdo->prepare("SELECT COALESCE(AVG(DATEDIFF(DATE_ADD(order_date, INTERVAL delivery_date DAY), order_date)), 0) FROM photonim_db.`order` WHERE order_date >= :start AND order_date <= :end AND status = 'completed'");
            $stmt->execute([':start' => $startDate, ':end' => $endDate]);
            $result['avg_delivery_time'] = (float)$stmt->fetchColumn();

            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM photonim_db.`order` WHERE order_date >= :start AND order_date <= :end AND status = 'completed' AND DATEDIFF(DATE_ADD(order_date, INTERVAL delivery_date DAY), order_date) <= delivery_date");
            $stmt->execute([':start' => $startDate, ':end' => $endDate]);
            $result['on_time_delivery'] = (int)$stmt->fetchColumn();

            $result['on_time_rate'] = $result['total_orders'] ? ($result['on_time_delivery'] / $result['total_orders']) * 100 : 0;
            $result['clv'] = $result['total_customers'] ? $result['total_revenue'] / $result['total_customers'] : 0;

            return ['success' => true, 'data' => $result];
        } catch (PDOException $e) {
            $this->logError("KPIs Error: " . $e->getMessage(), ['start' => $startDate, 'end' => $endDate]);
            return ['success' => false, 'data' => []];
        }
    }

    public function getNonCompletedOrders(string $startDate, string $endDate): array {
        try {
            $sql = "
                SELECT o.*, c.fname, c.lname, c.phone
                FROM photonim_db.`order` o
                JOIN photonim_db.customerregistration c ON o.customer_id = c.id
                WHERE o.status != 'completed' AND o.order_date >= :start AND o.order_date <= :end
                ORDER BY o.order_date DESC
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':start' => $startDate, ':end' => $endDate]);
            $orders = $stmt->fetchAll();

            foreach ($orders as &$order) {
                $itemsStmt = $this->pdo->prepare("
                    SELECT oi.*, p.imagesize, p.type, p.color, p.price 
                    FROM photonim_db.order_items oi 
                    JOIN photonim_db.pricelist p ON oi.product_id = p.id 
                    WHERE oi.order_id = :id
                ");
                $itemsStmt->execute([':id' => $order['id']]);
                $order['items'] = $itemsStmt->fetchAll();

                $subtotal = 0;
                foreach ($order['items'] as &$item) {
                    $item['subtotal'] = $item['quantity'] * $item['price'];
                    $subtotal += $item['subtotal'];
                }
                $order['subtotal'] = $subtotal;
                $order['total'] = max($subtotal - ($order['discount'] ?? 0), 0);
                $order['balance'] = $order['total'] - ($order['payment'] ?? 0);
            }

            return ['success' => true, 'data' => $orders];
        } catch (PDOException $e) {
            $this->logError("Non-Completed Orders Error: " . $e->getMessage(), ['start' => $startDate, 'end' => $endDate]);
            return ['success' => false, 'data' => []];
        }
    }

    public function getOrdersDueInTwoDays(string $startDate, string $endDate): array {
        try {
            $currentDate = new DateTime('now', new DateTimeZone('Asia/Tehran'));
            $sql = "
                SELECT o.*, c.fname, c.lname, c.phone
                FROM photonim_db.`order` o
                JOIN photonim_db.customerregistration c ON o.customer_id = c.id
                WHERE o.status NOT IN ('completed', 'canceled') AND o.order_date >= :start AND o.order_date <= :end
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':start' => $startDate, ':end' => $endDate]);
            $orders = $stmt->fetchAll();

            $filteredOrders = [];
            foreach ($orders as &$order) {
                $deliveryDays = (int)($order['delivery_date'] ?? 0);
                $orderDate = new DateTime($order['order_date'], new DateTimeZone('Asia/Tehran'));
                $deliveryDate = (clone $orderDate)->modify("+{$deliveryDays} days");
                $daysUntilDelivery = $currentDate->diff($deliveryDate)->days;

                if ($deliveryDate >= $currentDate && $daysUntilDelivery == 2) {
                    $itemsStmt = $this->pdo->prepare("
                        SELECT oi.*, p.imagesize, p.type, p.color, p.price 
                        FROM photonim_db.order_items oi 
                        JOIN photonim_db.pricelist p ON oi.product_id = p.id 
                        WHERE oi.order_id = :id
                    ");
                    $itemsStmt->execute([':id' => $order['id']]);
                    $order['items'] = $itemsStmt->fetchAll();

                    $subtotal = 0;
                    foreach ($order['items'] as &$item) {
                        $item['subtotal'] = $item['quantity'] * $item['price'];
                        $subtotal += $item['subtotal'];
                    }
                    $order['subtotal'] = $subtotal;
                    $order['total'] = max($subtotal - ($order['discount'] ?? 0), 0);
                    $order['balance'] = $order['total'] - ($order['payment'] ?? 0);
                    $order['delivery_date_calculated'] = $deliveryDate->format('Y-m-d');
                    $filteredOrders[] = $order;
                }
            }

            return ['success' => true, 'data' => $filteredOrders];
        } catch (PDOException $e) {
            $this->logError("Orders Due in 2 Days Error: " . $e->getMessage(), ['start' => $startDate, 'end' => $endDate]);
            return ['success' => false, 'data' => []];
        }
    }
}

if (!isset($conn) || !$conn instanceof PDO) {
    http_response_code(500);
    exit("<p style='color: #EF4444; text-align: center; font-family: Vazirmatn;'>خطا: اتصال به دیتابیس برقرار نیست</p>");
}

$dashboardManager = new DashboardManager($conn);
$dateRange = $dashboardManager->getDateRange();
$startDate = $dateRange['start'];
$endDate = $dateRange['end'];
$errors = $dateRange['errors'];
$rangeType = $dateRange['range_type'];

$rangeTypeTranslations = [
    'all' => 'همه تاریخ‌ها',
    'today' => 'امروز',
    'yesterday' => 'دیروز',
    'this_week' => 'این هفته',
    'last_week' => 'هفته گذشته',
    'this_month' => 'این ماه',
    'last_month' => 'ماه گذشته',
    'this_year' => 'امسال',
    'last_year' => 'سال گذشته',
    'custom' => 'انتخاب دستی'
];
$rangeTypeDisplay = $rangeTypeTranslations[$rangeType] ?? 'این ماه';

echo "<div style='text-align: center; padding: 10px; background: #f0f0f0;'>";
echo "تاریخ شروع: " . htmlspecialchars($startDate) . " | تاریخ پایان: " . htmlspecialchars($endDate) . " | نوع بازه: " . htmlspecialchars($rangeTypeDisplay);
if ($errors) {
    echo "<br><span style='color: #DC2626;'>خطاها: " . implode(", ", array_map('htmlspecialchars', $errors)) . "</span>";
}
echo "</div>";

$financialOverview = $dashboardManager->getFinancialOverview($startDate, $endDate)['data'];
$orderStats = $dashboardManager->getOrderStats($startDate, $endDate)['data'];
$topProducts = $dashboardManager->getTopProducts($startDate, $endDate)['data'];
$topCustomers = $dashboardManager->getTopCustomers($startDate, $endDate)['data'];
$kpis = $dashboardManager->getKPIs($startDate, $endDate)['data'];
$nonCompletedOrders = $dashboardManager->getNonCompletedOrders($startDate, $endDate)['data'];
$ordersDueInTwoDays = $dashboardManager->getOrdersDueInTwoDays($startDate, $endDate)['data'];

$bestCustomer = !empty($topCustomers) ? $topCustomers[0] : null;
$bestCustomerText = $bestCustomer ? "بهترین مشتری: " . htmlspecialchars($bestCustomer['fname'] . ' ' . $bestCustomer['lname']) . " با " . number_format($bestCustomer['total_spent']) . " تومان" : "هیچ مشتری برتری یافت نشد.";
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="داشبورد حرفه‌ای مدیریت کسب‌وکار استودیو نیما">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="Image/Logo1.png" type="image/png" sizes="80x80">
    <title>استودیو نیما | داشبورد مدیریت</title>
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

        .filter-section {
            background: var(--secondary-bg);
            padding: var(--spacing-unit);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: calc(var(--spacing-unit) * 1.5);
        }

        .filter-form {
            display: flex;
            gap: calc(var(--spacing-unit) * 0.75);
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-input, .filter-select {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 0.9rem;
            color: var(--text-dark);
            background: var(--primary-bg);
            transition: var(--transition);
        }

        .filter-input:focus, .filter-select:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2);
        }

        .filter-btn {
            padding: 8px 16px;
            background: var(--accent);
            color: var(--secondary-bg);
            border: none;
            border-radius: var(--radius);
            font-weight: 500;
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filter-btn:hover {
            background: var(--accent-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }

        .filter-btn.reset {
            background: var(--warning);
        }

        .filter-btn.reset:hover {
            background: var(--warning-hover);
        }

        .error-message {
            color: var(--danger);
            font-size: 0.9rem;
            margin-top: 8px;
            text-align: center;
            width: 100%;
        }

        .dashboard-section {
            background: var(--secondary-bg);
            padding: var(--spacing-unit);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: calc(var(--spacing-unit) * 1.5);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: var(--spacing-unit);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: calc(var(--spacing-unit) * 0.75);
        }

        .stat-card {
            background: var(--secondary-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: calc(var(--spacing-unit) * 0.75);
            transition: var(--transition);
            border: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
            background: var(--card-hover);
        }

        .stat-card .card-title {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .stat-card .card-text {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .order-card {
            background: var(--secondary-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: var(--spacing-unit);
            transition: var(--transition);
            border: 1px solid var(--border);
            margin-bottom: var(--spacing-unit);
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

        .action-btn.details { background: var(--warning); }
        .action-btn:hover { transform: translateY(-1px); box-shadow: var(--shadow-hover); }
        .action-btn.details:hover { background: var(--warning-hover); }

        .action-btn.customer-orders { background: #8B5CF6; }
        .action-btn.customer-orders:hover { background: #7C3AED; }

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
            .stat-grid { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
            .order-details, .modal-order-info { grid-template-columns: repeat(2, 1fr); }
            .modal { max-width: 700px; }
        }

        @media (max-width: 768px) {
            .nav-content { gap: calc(var(--spacing-unit) * 0.25); padding: 0 8px; }
            .nav-btn, .nav-logo { padding: 6px 12px; font-size: 0.85rem; }
            .stat-grid { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
            .order-details, .modal-order-info { grid-template-columns: 1fr; }
            .order-header { flex-direction: column; align-items: flex-start; }
            .modal { width: 95%; }
            .order-items-table th, .order-items-table td { font-size: 0.8rem; padding: 8px; }
            .header-title { font-size: 1.75rem; }
            .filter-form { flex-direction: column; align-items: flex-start; }
        }

        @media (max-width: 480px) {
            .stat-grid { grid-template-columns: 1fr; }
            .action-btn { width: 100%; justify-content: center; }
            .filter-input, .filter-select { width: 100%; }
            .filter-btn { width: 100%; }
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
            <h1 class="header-title">داشبورد مدیریت کسب‌وکار</h1>
            <div class="header-divider"></div>
        </header>

        <section class="filter-section fade-in">
            <h2 class="section-title"><i class="fas fa-filter"></i> فیلتر تاریخ</h2>
            <form class="filter-form" method="GET" action="" id="filterForm">
                <select name="range_type" class="filter-select" id="rangeTypeSelect" onchange="toggleDateFields(this)">
                    <option value="all" <?= $rangeType === 'all' ? 'selected' : '' ?>>همه تاریخ‌ها</option>
                    <option value="today" <?= $rangeType === 'today' ? 'selected' : '' ?>>امروز</option>
                    <option value="yesterday" <?= $rangeType === 'yesterday' ? 'selected' : '' ?>>دیروز</option>
                    <option value="this_week" <?= $rangeType === 'this_week' ? 'selected' : '' ?>>این هفته</option>
                    <option value="last_week" <?= $rangeType === 'last_week' ? 'selected' : '' ?>>هفته گذشته</option>
                    <option value="this_month" <?= $rangeType === 'this_month' ? 'selected' : '' ?>>این ماه</option>
                    <option value="last_month" <?= $rangeType === 'last_month' ? 'selected' : '' ?>>ماه گذشته</option>
                    <option value="this_year" <?= $rangeType === 'this_year' ? 'selected' : '' ?>>امسال</option>
                    <option value="last_year" <?= $rangeType === 'last_year' ? 'selected' : '' ?>>سال گذشته</option>
                    <option value="custom" <?= $rangeType === 'custom' ? 'selected' : '' ?>>انتخاب دستی</option>
                </select>
                <input type="date" name="start_date" class="filter-input" id="startDateInput" value="<?= htmlspecialchars($startDate) ?>" max="<?= date('Y-m-d') ?>" style="display: <?= $rangeType === 'custom' ? 'block' : 'none' ?>;">
                <input type="date" name="end_date" class="filter-input" id="endDateInput" value="<?= htmlspecialchars($endDate) ?>" max="<?= date('Y-m-d') ?>" style="display: <?= $rangeType === 'custom' ? 'block' : 'none' ?>;">
                <button type="submit" class="filter-btn" id="applyFilterBtn"><i class="fas fa-search"></i> اعمال فیلتر</button>
                <a href="?range_type=this_month" class="filter-btn reset"><i class="fas fa-undo"></i> بازنشانی</a>
            </form>
            <?php if ($errors): ?>
                <div class="error-message"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
            <?php endif; ?>
        </section>

        <section class="dashboard-section fade-in">
            <h2 class="section-title"><i class="fas fa-chart-line"></i> نمای کلی مالی</h2>
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="card-title">درآمد کل</div>
                    <div class="card-text"><i class="fas fa-money-bill"></i> <?= number_format($financialOverview['total']['revenue']) ?> ت</div>
                </div>
                <div class="stat-card">
                    <div class="card-title">سود کل</div>
                    <div class="card-text"><i class="fas fa-coins"></i> <?= number_format($financialOverview['total']['profit']) ?> ت</div>
                </div>
                <div class="stat-card">
                    <div class="card-title">در انتظار</div>
                    <div class="card-text"><i class="fas fa-hourglass-half"></i> <?= number_format($financialOverview['total']['pending']) ?> ت</div>
                </div>
            </div>
        </section>

        <section class="dashboard-section fade-in">
            <h2 class="section-title"><i class="fas fa-chart-bar"></i> آمار سفارشات و مشتریان</h2>
            <div class="stat-grid">
                <?php foreach ($orderStats['status_counts'] as $status => $count): ?>
                    <div class="stat-card">
                        <div class="card-title"><?= ORDER_STATUSES[$status] ?></div>
                        <div class="card-text"><i class="fas <?= $status === 'pending' ? 'fa-hourglass-half' : ($status === 'in_progress' ? 'fa-cogs' : ($status === 'completed' ? 'fa-check-circle' : 'fa-times-circle')) ?>"></i> <?= $count ?></div>
                    </div>
                <?php endforeach; ?>
                <div class="stat-card">
                    <div class="card-title">مشتریان جدید</div>
                    <div class="card-text"><i class="fas fa-user-plus"></i> <?= $orderStats['new_customers'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="card-title">میانگین زمان تحویل</div>
                    <div class="card-text"><i class="fas fa-calendar-check"></i> <?= round($orderStats['avg_delivery_days'], 1) ?> روز</div>
                </div>
                <div class="stat-card">
                    <div class="card-title">ارزش سفارشات در انتظار</div>
                    <div class="card-text"><i class="fas fa-balance-scale"></i> <?= number_format($orderStats['pending_value']) ?> ت</div>
                </div>
            </div>
        </section>

        <section class="dashboard-section fade-in">
            <h2 class="section-title"><i class="fas fa-boxes"></i> ۱۰ محصول پرفروش</h2>
            <div class="order-items-table">
                <table>
                    <thead>
                        <tr>
                            <th>رتبه</th>
                            <th>کد محصول</th>
                            <th>اندازه</th>
                            <th>نوع</th>
                            <th>رنگ</th>
                            <th>تعداد فروش</th>
                            <th>درآمد کل (ت)</th>
                            <th>تعداد سفارشات</th>
                            <th>میانگین تعداد</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topProducts as $index => $product): ?>
                            <tr <?= $index === 0 ? 'class="highlight"' : '' ?>>
                                <td><?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($product['product_code']) ?></td>
                                <td><?= htmlspecialchars($product['imagesize']) ?></td>
                                <td><?= htmlspecialchars($product['type']) ?></td>
                                <td><?= htmlspecialchars($product['color'] ?? '-') ?></td>
                                <td><?= number_format($product['total_sold']) ?></td>
                                <td><?= number_format($product['total_revenue']) ?></td>
                                <td><?= $product['order_count'] ?></td>
                                <td><?= round($product['avg_quantity_per_order'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        
        <section class="dashboard-section fade-in">
            <h2 class="section-title"><i class="fas fa-users"></i> ۱۰ مشتری برتر</h2>
            <div class="order-items-table">
                <table>
                    <thead>
                        <tr>
                            <th>رتبه</th>
                            <th>نام</th>
                            <th>شماره</th>
                            <th>تعداد سفارش</th>
                            <th>مجموع هزینه (ت)</th>
                            <th>میانگین ارزش سفارش (ت)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topCustomers as $index => $customer): ?>
                            <tr <?= $index === 0 ? 'class="highlight"' : '' ?>>
                                <td><?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($customer['fname'] . ' ' . $customer['lname']) ?></td>
                                <td><?= htmlspecialchars($customer['phone'] ?? '-') ?></td>
                                <td><?= $customer['order_count'] ?></td>
                                <td><?= number_format($customer['total_spent']) ?></td>
                                <td><?= number_format($customer['avg_order_value']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="no-results"><?= $bestCustomerText ?></div>
        </section>

        <section class="dashboard-section fade-in">
            <h2 class="section-title"><i class="fas fa-tachometer-alt"></i> شاخص‌های کلیدی عملکرد</h2>
            <div class="stat-grid">
                <div class="stat-card">
                    <div class="card-title">تعداد کل مشتریان</div>
                    <div class="card-text"><i class="fas fa-users"></i> <?= number_format($kpis['total_customers']) ?></div>
                </div>
                <div class="stat-card">
                    <div class="card-title">تعداد کل سفارشات</div>
                    <div class="card-text"><i class="fas fa-shopping-cart"></i> <?= number_format($kpis['total_orders']) ?></div>
                </div>
                <div class="stat-card">
                    <div class="card-title">درآمد کل</div>
                    <div class="card-text"><i class="fas fa-money-bill"></i> <?= number_format($kpis['total_revenue']) ?> ت</div>
                </div>
                <div class="stat-card">
                    <div class="card-title">سود کل</div>
                    <div class="card-text"><i class="fas fa-coins"></i> <?= number_format($kpis['total_profit']) ?> ت</div>
                </div>
                <div class="stat-card">
                    <div class="card-title">نرخ بازگشت مشتری</div>
                    <div class="card-text"><i class="fas fa-users"></i> <?= round($kpis['return_rate'], 2) ?>% (<?= $kpis['repeat_customers'] ?>)</div>
                </div>
                <div class="stat-card">
                    <div class="card-title">میانگین ارزش سفارش</div>
                    <div class="card-text"><i class="fas fa-wallet"></i> <?= number_format($kpis['aov']) ?> ت</div>
                </div>
                <div class="stat-card">
                    <div class="card-title">حاشیه سود</div>
                    <div class="card-text"><i class="fas fa-percentage"></i> <?= round($kpis['profit_margin'], 2) ?>%</div>
                </div>
                <div class="stat-card">
                    <div class="card-title">میانگین اقلام در سفارش</div>
                    <div class="card-text"><i class="fas fa-box"></i> <?= round($kpis['avg_items_per_order'], 2) ?></div>
                </div>
                <div class="stat-card">
                    <div class="card-title">ارزش طول عمر مشتری</div>
                    <div class="card-text"><i class="fas fa-money-check"></i> <?= number_format($kpis['clv']) ?> ت</div>
                </div>
                <div class="stat-card">
                    <div class="card-title">میانگین زمان تحویل</div>
                    <div class="card-text"><i class="fas fa-calendar-check"></i> <?= round($kpis['avg_delivery_time'], 1) ?> روز</div>
                </div>
                <div class="stat-card">
                    <div class="card-title">نرخ تحویل به‌موقع</div>
                    <div class="card-text"><i class="fas fa-clock"></i> <?= round($kpis['on_time_rate'], 2) ?>% (<?= $kpis['on_time_delivery'] ?>)</div>
                </div>
            </div>
        </section>

        <section class="dashboard-section fade-in">
            <h2 class="section-title"><i class="fas fa-hourglass-half"></i> سفارشات تکمیل‌نشده</h2>
            <?php if (empty($nonCompletedOrders)): ?>
                <div class="no-results">هیچ سفارش تکمیل‌نشده‌ای وجود ندارد.</div>
            <?php else: ?>
                <?php foreach ($nonCompletedOrders as $order): ?>
                    <div class="order-card">
                        <div class="order-header">
                            <span class="order-id"><i class="fas fa-id-badge"></i> <?= htmlspecialchars($order['id']) ?></span>
                            <span class="order-status <?= str_replace('_', '-', $order['status']) ?>">
                                <i class="fas <?= $order['status'] === 'pending' ? 'fa-hourglass-half' : ($order['status'] === 'in_progress' ? 'fa-cogs' : 'fa-times-circle') ?>"></i>
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
                                <span class="detail-label">مبلغ کل</span>
                                <span class="detail-value"><i class="fas fa-wallet"></i> <?= number_format($order['total']) ?> ت</span>
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
                        </div>
                        <div class="order-actions">
                            <button class="action-btn details" data-order='<?= json_encode($order, JSON_UNESCAPED_UNICODE) ?>'><i class="fas fa-eye"></i> جزئیات</button>
                            <a href="Order%20List.php?query=<?= urlencode($order['customer_id']) ?>&search_field=customer" class="action-btn customer-orders"><i class="fas fa-list-ul"></i> سفارشات مشتری</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section class="dashboard-section fade-in">
            <h2 class="section-title"><i class="fas fa-clock"></i> سفارشات با موعد تحویل ۲ روز آینده</h2>
            <?php if (empty($ordersDueInTwoDays)): ?>
                <div class="no-results">هیچ سفارشی با موعد تحویل ۲ روز آینده وجود ندارد.</div>
            <?php else: ?>
                <?php foreach ($ordersDueInTwoDays as $order): ?>
                    <div class="order-card">
                        <div class="order-header">
                            <span class="order-id"><i class="fas fa-id-badge"></i> <?= htmlspecialchars($order['id']) ?></span>
                            <span class="order-status <?= str_replace('_', '-', $order['status']) ?>">
                                <i class="fas <?= $order['status'] === 'pending' ? 'fa-hourglass-half' : 'fa-cogs' ?>"></i>
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
                                <span class="detail-label">موعد تحویل</span>
                                <span class="detail-value"><i class="fas fa-calendar-check"></i> <?= $order['delivery_date_calculated'] ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">مبلغ کل</span>
                                <span class="detail-value"><i class="fas fa-wallet"></i> <?= number_format($order['total']) ?> ت</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">باقیمانده</span>
                                <span class="detail-value"><i class="fas fa-balance-scale"></i> <?= number_format($order['balance']) ?> ت</span>
                            </div>
                        </div>
                        <div class="order-actions">
                            <button class="action-btn details" data-order='<?= json_encode($order, JSON_UNESCAPED_UNICODE) ?>'><i class="fas fa-eye"></i> جزئیات</button>
                            <a href="Order%20List.php?query=<?= urlencode($order['customer_id']) ?>&search_field=customer" class="action-btn customer-orders"><i class="fas fa-list-ul"></i> سفارشات مشتری</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
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

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const filterForm = document.getElementById('filterForm');
            const rangeTypeSelect = document.getElementById('rangeTypeSelect');
            const startDateInput = document.getElementById('startDateInput');
            const endDateInput = document.getElementById('endDateInput');
            const applyFilterBtn = document.getElementById('applyFilterBtn');
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

            function toggleDateFields(select) {
                if (select.value === 'custom') {
                    startDateInput.style.display = 'block';
                    endDateInput.style.display = 'block';
                    startDateInput.required = true;
                    endDateInput.required = true;
                } else {
                    startDateInput.style.display = 'none';
                    endDateInput.style.display = 'none';
                    startDateInput.required = false;
                    endDateInput.required = false;
                    startDateInput.value = '';
                    endDateInput.value = '';
                }
            }

            toggleDateFields(rangeTypeSelect);

            filterForm.addEventListener('submit', (e) => {
                const rangeType = rangeTypeSelect.value;
                if (rangeType === 'custom') {
                    const startDate = new Date(startDateInput.value);
                    const endDate = new Date(endDateInput.value);
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);

                    if (!startDateInput.value || !endDateInput.value) {
                        e.preventDefault();
                        alert('لطفاً هر دو تاریخ شروع و پایان را وارد کنید.');
                        return;
                    }

                    if (isNaN(startDate.getTime()) || isNaN(endDate.getTime())) {
                        e.preventDefault();
                        alert('فرمت تاریخ نامعتبر است.');
                        return;
                    }

                    if (startDate > endDate) {
                        e.preventDefault();
                        alert('تاریخ شروع نمی‌تواند از تاریخ پایان بزرگ‌تر باشد.');
                        return;
                    }

                    if (endDate > today) {
                        e.preventDefault();
                        alert('تاریخ پایان نمی‌تواند از امروز بزرگ‌تر باشد.');
                        return;
                    }
                }
            });

            applyFilterBtn.addEventListener('click', () => filterForm.submit());

            function openModal(order) {
                overlay.classList.add('active');
                modal.classList.add('active');
                modalOrderId.textContent = order.id;
                modalOrderCustomer.textContent = `${order.fname} ${order.lname}`;
                modalOrderPhone.textContent = order.phone || '-';
                modalOrderDate.textContent = order.order_date;
                modalOrderDelivery.textContent = order.delivery_date_calculated || order.delivery_date;
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