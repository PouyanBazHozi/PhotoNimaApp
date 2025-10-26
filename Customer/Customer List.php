<?php
session_start();
require_once 'DB Config.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Constants for order statuses and customer levels
const ORDER_STATUSES = [
    'pending' => 'در انتظار',
    'in_progress' => 'در حال انجام',
    'completed' => 'تکمیل‌شده',
    'canceled' => 'لغو‌شده'
];

const CUSTOMER_LEVELS = [
    'bronze' => ['label' => 'برنزی', 'discount' => 0.00],
    'silver' => ['label' => 'نقره‌ای', 'discount' => 5.00],
    'gold' => ['label' => 'طلایی', 'discount' => 10.00]
];

const POINT_EVENTS = [
    'order' => ['label' => 'سفارش', 'icon' => 'fa-shopping-cart', 'description' => 'امتیاز کسب‌شده از ثبت سفارش'],
    'referral' => ['label' => 'معرفی', 'icon' => 'fa-user-friends', 'description' => 'امتیاز از معرفی مشتری جدید'],
    'bonus' => ['label' => 'پاداش', 'icon' => 'fa-gift', 'description' => 'امتیاز پاداش ویژه'],
    'redeem' => ['label' => 'استفاده', 'icon' => 'fa-exchange-alt', 'description' => 'استفاده از امتیاز برای تخفیف'],
    'adjustment' => ['label' => 'تنظیم', 'icon' => 'fa-tools', 'description' => 'تنظیم دستی امتیاز توسط مدیر']
];

class CustomerManager {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    private function logError(string $message, array $context = []): void {
        $logMessage = date('Y-m-d H:i:s') . " - $message";
        if ($context) {
            $logMessage .= " | زمینه: " . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        file_put_contents('error.log', "$logMessage\n", FILE_APPEND);
    }

    public function deleteCustomer(int $id): array {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM photonim_db.`order` WHERE customer_id = ?");
            $stmt->execute([$id]);
            $orderCount = $stmt->fetchColumn();

            $refStmt = $this->pdo->prepare("SELECT COUNT(*) FROM photonim_db.referrals WHERE referrer_id = ?");
            $refStmt->execute([$id]);
            $refCount = $refStmt->fetchColumn();

            $referredStmt = $this->pdo->prepare("SELECT COUNT(*) FROM photonim_db.referrals WHERE referred_id = ?");
            $referredStmt->execute([$id]);
            $referredCount = $referredStmt->fetchColumn();

            if ($orderCount > 0 || $refCount > 0) {
                $message = "حذف ممکن نیست: این مشتری ";
                if ($orderCount > 0) $message .= "$orderCount سفارش، ";
                if ($refCount > 0) $message .= "$refCount ارجاع به‌عنوان معرف، ";
                $message = rtrim($message, "، ") . " دارد.";
                $this->pdo->rollBack();
                return ['success' => false, 'message' => $message];
            }

            if ($referredCount > 0) {
                $deleteRefStmt = $this->pdo->prepare("DELETE FROM photonim_db.referrals WHERE referred_id = ?");
                $deleteRefStmt->execute([$id]);
            }

            $stmt = $this->pdo->prepare("DELETE FROM photonim_db.customerregistration WHERE id = ?");
            $success = $stmt->execute([$id]);
            $this->pdo->commit();

            return [
                'success' => $success,
                'message' => $success ? "مشتری با شناسه $id با موفقیت حذف شد." : 'خطا در حذف مشتری رخ داد.'
            ];
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            $this->logError("خطا در حذف مشتری: " . $e->getMessage(), ['شناسه_مشتری' => $id]);
            return ['success' => false, 'message' => 'خطا در حذف: ' . $e->getMessage()];
        }
    }

    public function getCustomerById(int $id): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, fName, lName, phone, date, description, points, level, referred_by, created_at 
                FROM photonim_db.customerregistration 
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            $customer = $stmt->fetch();

            if (!$customer) {
                return ['success' => false, 'message' => "مشتری با شناسه $id یافت نشد."];
            }

            $ordersStmt = $this->pdo->prepare("
                SELECT o.id, o.subtotal, o.discount, o.total, o.payment, o.balance, o.order_date, o.delivery_date, o.status,
                       COUNT(oi.id) as item_count
                FROM photonim_db.`order` o
                LEFT JOIN photonim_db.order_items oi ON o.id = oi.order_id
                WHERE o.customer_id = ?
                GROUP BY o.id
                ORDER BY o.created_at DESC
            ");
            $ordersStmt->execute([$id]);
            $customer['orders'] = $ordersStmt->fetchAll();

            $statsStmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_orders,
                    SUM(total) as total_spent,
                    AVG(total) as avg_order_value,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders
                FROM photonim_db.`order`
                WHERE customer_id = ?
            ");
            $statsStmt->execute([$id]);
            $customer['stats'] = $statsStmt->fetch();

            $refStmt = $this->pdo->prepare("
                SELECT r.id, r.referred_id, r.order_id, r.status, r.created_at, c.fName, c.lName
                FROM photonim_db.referrals r
                JOIN photonim_db.customerregistration c ON r.referred_id = c.id
                WHERE r.referrer_id = ?
            ");
            $refStmt->execute([$id]);
            $customer['referrals'] = $refStmt->fetchAll();

            if ($customer['referred_by']) {
                $referrerStmt = $this->pdo->prepare("
                    SELECT id, fName, lName 
                    FROM photonim_db.customerregistration 
                    WHERE id = ?
                ");
                $referrerStmt->execute([$customer['referred_by']]);
                $customer['referrer'] = $referrerStmt->fetch();
            }

            $pointsStmt = $this->pdo->prepare("
                SELECT ph.points, ph.event_type, ph.related_id, ph.created_at, 
                       c.fName as related_fname, c.lName as related_lname,
                       o.total as order_total
                FROM photonim_db.point_history ph
                LEFT JOIN photonim_db.customerregistration c ON ph.related_id = c.id AND ph.event_type = 'referral'
                LEFT JOIN photonim_db.`order` o ON ph.related_id = o.id AND ph.event_type = 'order'
                WHERE ph.customer_id = ?
                ORDER BY ph.created_at DESC
            ");
            $pointsStmt->execute([$id]);
            $customer['points_history'] = $pointsStmt->fetchAll();

            return ['success' => true, 'data' => $customer];
        } catch (PDOException $e) {
            $this->logError("خطا در دریافت اطلاعات مشتری: " . $e->getMessage(), ['شناسه_مشتری' => $id]);
            return ['success' => false, 'message' => 'خطا در دریافت اطلاعات: ' . $e->getMessage()];
        }
    }

    public function searchCustomers(string $keyword = '', string $field = 'all', int $limit = 5, int $offset = 0): array {
        try {
            $fields = ['id', 'fName', 'lName', 'phone', 'date', 'description', 'points', 'level', 'created_at'];
            $sql = "SELECT id, fName, lName, phone, date, description, points, level, created_at 
                    FROM photonim_db.customerregistration";
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
                } elseif (in_array($field, $fields)) {
                    $param = ":kw0";
                    $conditions[] = "$field LIKE $param";
                    $params[$param] = "%$keyword%";
                }

                if ($conditions) {
                    $sql .= " WHERE " . implode(' OR ', $conditions);
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
            $this->logError("خطا در جستجوی مشتریان: " . $e->getMessage(), ['کلیدواژه' => $keyword, 'فیلد' => $field]);
            return ['success' => false, 'message' => 'خطا در جستجو: ' . $e->getMessage()];
        }
    }

    public function getTotalCustomers(string $keyword = '', string $field = 'all'): int {
        try {
            $fields = ['id', 'fName', 'lName', 'phone', 'date', 'description', 'points', 'level', 'created_at'];
            $sql = "SELECT COUNT(*) FROM photonim_db.customerregistration";
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
                } elseif (in_array($field, $fields)) {
                    $param = ":kw0";
                    $conditions[] = "$field LIKE $param";
                    $params[$param] = "%$keyword%";
                }

                if ($conditions) {
                    $sql .= " WHERE " . implode(' OR ', $conditions);
                }
            }

            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $param => $value) {
                $stmt->bindValue($param, $value, PDO::PARAM_STR);
            }
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            $this->logError("خطا در شمارش مشتریان: " . $e->getMessage(), ['کلیدواژه' => $keyword, 'فیلد' => $field]);
            return 0;
        }
    }
}

if (!isset($conn) || !$conn instanceof PDO) {
    http_response_code(500);
    exit("<p style='color: #EF4444; text-align: center; font-family: Vazirmatn;'>خطا: اتصال به پایگاه داده برقرار نیست.</p>");
}

$customerManager = new CustomerManager($conn);

$page = filter_input(INPUT_GET, 'صفحه', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?? 1;
$limit = 5;
$offset = ($page - 1) * $limit;

$actions = [
    'حذف' => fn($id) => $customerManager->deleteCustomer($id),
    'ویرایش' => fn($id) => header("Location: EditCustomer.php?Edit=$id"),
    'جزئیات' => fn($id) => $customerManager->getCustomerById($id)
];

$response = null;
foreach ($actions as $key => $action) {
    if (isset($_GET[$key]) && filter_var($_GET[$key], FILTER_VALIDATE_INT)) {
        $id = (int)$_GET[$key];
        $response = $action($id);
        if ($key !== 'جزئیات' && $response && ($response['success'] ?? false)) {
            $queryParams = "?صفحه=$page" . (isset($_GET['جستجو']) ? "&جستجو=" . urlencode($_GET['جستجو']) . "&فیلد_جستجو=" . urlencode($_GET['فیلد_جستجو'] ?? 'همه') : "");
            header("Location: " . $_SERVER['PHP_SELF'] . $queryParams);
            exit;
        }
        break;
    }
}

$searchKeyword = filter_input(INPUT_GET, 'جستجو', FILTER_UNSAFE_RAW) ?? '';
$searchKeyword = htmlspecialchars($searchKeyword, ENT_QUOTES, 'UTF-8');

$searchField = filter_input(INPUT_GET, 'فیلد_جستجو', FILTER_UNSAFE_RAW) ?? 'همه';
$searchField = htmlspecialchars($searchField, ENT_QUOTES, 'UTF-8');

$searchResult = $customerManager->searchCustomers($searchKeyword, $searchField, $limit, $offset);
$customers = $searchResult['success'] ? $searchResult['data'] : [];
$totalCustomers = $customerManager->getTotalCustomers($searchKeyword, $searchField);
$totalPages = ceil($totalCustomers / $limit);

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
    <meta name="description" content="مدیریت حرفه‌ای مشتریان استودیو نیما">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="Image/Logo1.png" type="image/png" sizes="80x80">
    <title>استودیو نیما | فهرست مشتریان</title>
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
            --level-bronze: linear-gradient(135deg, #CD7F32 0%, #8B4513 100%);
            --level-silver: linear-gradient(135deg, #C0C0C0 0%, #808080 100%);
            --level-gold: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
            --points-positive: #16A34A;
            --points-negative: #DC2626;
            --points-neutral: #6B7280;
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

        .customers-section {
            display: grid;
            gap: var(--spacing-unit);
        }

        .customer-card {
            background: var(--secondary-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: var(--spacing-unit);
            transition: var(--transition);
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }

        .customer-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
            background: var(--card-hover);
        }

        .customer-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: calc(var(--spacing-unit) * 0.75);
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
            gap: 8px;
        }

        .customer-id {
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

        .customer-level {
            font-size: 0.9rem;
            font-weight: 600;
            padding: 6px 16px;
            border-radius: 20px;
            color: #FFFFFF;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .customer-level.bronze { background: var(--level-bronze); }
        .customer-level.silver { background: var(--level-silver); }
        .customer-level.gold { background: var(--level-gold); }

        .customer-level:hover {
            transform: scale(1.05);
            box-shadow: inset 0 4px 8px rgba(0, 0, 0, 0.3), 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .customer-details {
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

        .customer-actions {
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
            max-width: 1100px;
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

        .modal-customer-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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

        .stats-section, .history-section {
            margin: calc(var(--spacing-unit) * 0.75) 0;
            padding: calc(var(--spacing-unit) * 0.75);
            background: var(--secondary-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: calc(var(--spacing-unit) * 0.5);
        }

        .history-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: calc(var(--spacing-unit) * 0.75);
            box-shadow: var(--shadow);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .history-table th, .history-table td {
            padding: 10px 14px;
            text-align: right;
            border-bottom: 1px solid var(--border);
        }

        .history-table th {
            background: var(--accent);
            color: var(--secondary-bg);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .history-table td {
            background: var(--secondary-bg);
            font-size: 0.85rem;
            color: var(--text-dark);
            transition: var(--transition);
        }

        .history-table tr:hover td {
            background: var(--card-hover);
        }

        .order-status, .referral-status {
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

        .referral-status.pending { color: var(--warning); background: rgba(245, 158, 11, 0.1); }
        .referral-status.completed { color: var(--success); background: rgba(22, 163, 74, 0.1); }

        .points-filter {
            display: flex;
            gap: calc(var(--spacing-unit) * 0.5);
            align-items: center;
            margin-bottom: calc(var(--spacing-unit) * 0.75);
            flex-wrap: wrap;
        }

        .points-filter select, .points-filter button {
            padding: 8px 14px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 0.9rem;
            transition: var(--transition);
            box-shadow: var(--shadow);
        }

        .points-filter select {
            min-width: 180px;
            background: var(--secondary-bg) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='%236B7280' viewBox='0 0 20 20'%3E%3Cpath d='M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z'/%3E%3C/svg%3E") no-repeat left 10px center;
            background-size: 14px;
            padding-left: 32px;
            appearance: none;
        }

        .points-filter button {
            background: var(--accent);
            color: var(--secondary-bg);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .points-filter button:hover {
            background: var(--accent-hover);
            box-shadow: var(--shadow-hover);
        }

        .points-value {
            font-weight: 600;
            padding: 4px 8px;
            border-radius: var(--radius);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .points-value.positive { color: var(--points-positive); background: rgba(22, 163, 74, 0.1); }
        .points-value.negative { color: var(--points-negative); background: rgba(220, 38, 38, 0.1); }
        .points-value.neutral { color: var(--points-neutral); background: rgba(107, 114, 128, 0.1); }

        .points-description {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 2px;
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
            .customer-details, .modal-customer-info, .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .modal { max-width: 900px; }
        }

        @media (max-width: 768px) {
            .nav-content { gap: calc(var(--spacing-unit) * 0.25); padding: 0 8px; }
            .nav-btn, .nav-logo { padding: 6px 12px; font-size: 0.85rem; }
            .search-form { flex-direction: column; }
            .search-select, .search-input { width: 100%; }
            .customer-details, .modal-customer-info, .stats-grid { grid-template-columns: 1fr; }
            .customer-actions { flex-direction: column; align-items: flex-end; }
            .customer-header { flex-direction: column; align-items: flex-start; }
            .modal { width: 95%; }
            .history-table th, .history-table td { font-size: 0.8rem; padding: 8px; }
            .points-filter { flex-direction: column; }
            .points-filter select, .points-filter button { width: 100%; }
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
            <a href="Nima%20Studio.html" class="nav-logo"><img src="Image/Logo2.png" alt="لوگوی استودیو نیما"></a>
            <a href="Customer%20Registration.php" class="nav-btn"><i class="fas fa-user-plus"></i> ثبت مشتری</a>
            <a href="Order.php" class="nav-btn"><i class="fas fa-cart-plus"></i> ثبت سفارش</a>
            <a href="Customer%20List.php" class="nav-btn"><i class="fas fa-users"></i> فهرست مشتریان</a>
            <a href="Order%20List.php" class="nav-btn"><i class="fas fa-list-ul"></i> فهرست سفارش‌ها</a>
            <a href="Products%20List.php" class="nav-btn"><i class="fas fa-boxes"></i> فهرست محصولات</a>
            <a href="Index.php" class="nav-btn"><i class="fas fa-sign-out-alt"></i> خروج</a>
        </nav>
    </div>

    <div class="container">
        <header class="header fade-in">
            <h1 class="header-title">فهرست مشتریان</h1>
            <div class="header-divider"></div>
        </header>

        <section class="search-section fade-in">
            <form method="GET" class="search-form">
                <select name="فیلد_جستجو" class="search-select">
                    <option value="همه" <?= $searchField === 'همه' ? 'selected' : '' ?>>همه موارد</option>
                    <option value="id" <?= $searchField === 'id' ? 'selected' : '' ?>>شناسه</option>
                    <option value="fName" <?= $searchField === 'fName' ? 'selected' : '' ?>>نام</option>
                    <option value="lName" <?= $searchField === 'lName' ? 'selected' : '' ?>>نام خانوادگی</option>
                    <option value="phone" <?= $searchField === 'phone' ? 'selected' : '' ?>>شماره تلفن</option>
                    <option value="date" <?= $searchField === 'date' ? 'selected' : '' ?>>تاریخ تولد</option>
                    <option value="description" <?= $searchField === 'description' ? 'selected' : '' ?>>توضیحات</option>
                    <option value="points" <?= $searchField === 'points' ? 'selected' : '' ?>>امتیاز</option>
                    <option value="level" <?= $searchField === 'level' ? 'selected' : '' ?>>سطح</option>
                    <option value="created_at" <?= $searchField === 'created_at' ? 'selected' : '' ?>>تاریخ ثبت</option>
                </select>
                <input type="text" name="جستجو" class="search-input" value="<?= htmlspecialchars($searchKeyword) ?>" placeholder="جستجو در مشتریان">
                <button type="submit" class="search-btn"><i class="fas fa-search"></i> جستجو</button>
            </form>
        </section>

        <section class="customers-section fade-in">
            <?php if (!empty($customers)): ?>
                <?php foreach ($customers as $customer): ?>
                    <div class="customer-card">
                        <div class="customer-header">
                            <span class="customer-id"><i class="fas fa-id-badge"></i> <?= htmlspecialchars($customer['id']) ?></span>
                            <span class="customer-level <?= htmlspecialchars($customer['level']) ?>">
                                <i class="fas fa-trophy"></i> <?= htmlspecialchars(CUSTOMER_LEVELS[$customer['level']]['label']) ?> 
                                (تخفیف: <?= CUSTOMER_LEVELS[$customer['level']]['discount'] ?>%)
                            </span>
                        </div>
                        <div class="customer-details">
                            <div class="detail-item">
                                <span class="detail-label">نام</span>
                                <span class="detail-value"><i class="fas fa-user"></i> <?= htmlspecialchars($customer['fName']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">نام خانوادگی</span>
                                <span class="detail-value"><i class="fas fa-user"></i> <?= htmlspecialchars($customer['lName']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">شماره تلفن</span>
                                <span class="detail-value"><i class="fas fa-phone"></i> <?= htmlspecialchars($customer['phone'] ?? '-') ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">تاریخ تولد</span>
                                <span class="detail-value"><i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($customer['date'] ?? '-') ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">توضیحات</span>
                                <span class="detail-value"><i class="fas fa-comment"></i> <?= htmlspecialchars($customer['description'] ?? '-') ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">امتیاز</span>
                                <span class="detail-value"><i class="fas fa-star"></i> <?= htmlspecialchars($customer['points']) ?></span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">تاریخ ثبت</span>
                                <span class="detail-value"><i class="fas fa-clock"></i> <?= htmlspecialchars($customer['created_at']) ?></span>
                            </div>
                        </div>
                        <div class="customer-actions">
                            <a href="?حذف=<?= $customer['id'] ?>&صفحه=<?= $page ?>&جستجو=<?= urlencode($searchKeyword) ?>&فیلد_جستجو=<?= urlencode($searchField) ?>" 
                               class="action-btn delete" 
                               onclick="return confirm('آیا از حذف مشتری با شناسه #<?= $customer['id'] ?> مطمئن هستید؟')">
                                <i class="fas fa-trash"></i> حذف
                            </a>
                            <a href="?ویرایش=<?= $customer['id'] ?>" class="action-btn edit"><i class="fas fa-edit"></i> ویرایش</a>
                            <?php
                            $customerDetails = $customerManager->getCustomerById($customer['id']);
                            $customerData = $customerDetails['success'] && isset($customerDetails['data']) ? $customerDetails['data'] : [];
                            ?>
                            <button class="action-btn details" 
                                    data-customer='<?= htmlspecialchars(json_encode($customerData, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>'>
                                <i class="fas fa-eye"></i> جزئیات
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-results">هیچ مشتری با شرایط جستجو یافت نشد!</div>
            <?php endif; ?>
        </section>

        <?php if ($totalPages > 1): ?>
            <div class="pagination fade-in">
                <a href="?صفحه=<?= $page - 1 ?>&جستجو=<?= urlencode($searchKeyword) ?>&فیلد_جستجو=<?= urlencode($searchField) ?>" 
                   class="pagination-btn <?= $page <= 1 ? 'disabled' : '' ?>"><i class="fas fa-chevron-right"></i> صفحه قبل</a>
                <span class="pagination-info">صفحه <?= $page ?> از <?= $totalPages ?> (<?= $totalCustomers ?> مشتری)</span>
                <a href="?صفحه=<?= $page + 1 ?>&جستجو=<?= urlencode($searchKeyword) ?>&فیلد_جستجو=<?= urlencode($searchField) ?>" 
                   class="pagination-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">صفحه بعد <i class="fas fa-chevron-left"></i></a>
            </div>
        <?php endif; ?>
    </div>

    <div class="modal-overlay" id="modal_overlay"></div>
    <div class="modal" id="modal">
        <div class="modal-header">
            <span class="modal-title"><i class="fas fa-info-circle"></i> جزئیات مشتری #<span id="modal_customer_id"></span></span>
            <button class="modal-close" id="modal_close"><i class="fas fa-times"></i> بستن</button>
        </div>
        <div class="modal-body">
            <div class="modal-customer-info">
                <div class="modal-detail-item">
                    <span class="modal-detail-label">نام</span>
                    <span class="modal-detail-value"><i class="fas fa-user"></i> <span id="modal_customer_fname"></span></span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">نام خانوادگی</span>
                    <span class="modal-detail-value"><i class="fas fa-user"></i> <span id="modal_customer_lname"></span></span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">شماره تلفن</span>
                    <span class="modal-detail-value"><i class="fas fa-phone"></i> <span id="modal_customer_phone"></span></span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">تاریخ تولد</span>
                    <span class="modal-detail-value"><i class="fas fa-calendar-alt"></i> <span id="modal_customer_date"></span></span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">توضیحات</span>
                    <span class="modal-detail-value"><i class="fas fa-comment"></i> <span id="modal_customer_description"></span></span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">امتیاز</span>
                    <span class="modal-detail-value"><i class="fas fa-star"></i> <span id="modal_customer_points"></span></span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">سطح</span>
                    <span class="modal-detail-value"><i class="fas fa-trophy"></i> <span id="modal_customer_level"></span></span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">معرف</span>
                    <span class="modal-detail-value"><i class="fas fa-user-friends"></i> <span id="modal_customer_referrer"></span></span>
                </div>
                <div class="modal-detail-item">
                    <span class="modal-detail-label">تاریخ ثبت</span>
                    <span class="modal-detail-value"><i class="fas fa-clock"></i> <span id="modal_customer_created_at"></span></span>
                </div>
            </div>

            <div class="stats-section">
                <div class="stats-grid">
                    <div class="modal-detail-item">
                        <span class="modal-detail-label">تعداد سفارش‌ها</span>
                        <span class="modal-detail-value"><i class="fas fa-shopping-cart"></i> <span id="modal_total_orders"></span></span>
                    </div>
                    <div class="modal-detail-item">
                        <span class="modal-detail-label">سفارش‌های تکمیل‌شده</span>
                        <span class="modal-detail-value"><i class="fas fa-check-circle"></i> <span id="modal_completed_orders"></span></span>
                    </div>
                    <div class="modal-detail-item">
                        <span class="modal-detail-label">مجموع هزینه</span>
                        <span class="modal-detail-value"><i class="fas fa-wallet"></i> <span id="modal_total_spent"></span> تومان</span>
                    </div>
                    <div class="modal-detail-item">
                        <span class="modal-detail-label">میانگین سفارش</span>
                        <span class="modal-detail-value"><i class="fas fa-chart-bar"></i> <span id="modal_avg_order"></span> تومان</span>
                    </div>
                    <div class="modal-detail-item">
                        <span class="modal-detail-label">تعداد ارجاعات</span>
                        <span class="modal-detail-value"><i class="fas fa-users"></i> <span id="modal_customer_referrals"></span></span>
                    </div>
                </div>
            </div>

            <div class="history-section">
                <h3>تاریخچه سفارش‌ها</h3>
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>شناسه سفارش</th>
                            <th>جمع جزئی</th>
                            <th>تخفیف</th>
                            <th>مبلغ کل</th>
                            <th>پرداخت‌شده</th>
                            <th>باقیمانده</th>
                            <th>تعداد آیتم‌ها</th>
                            <th>تاریخ سفارش</th>
                            <th>تاریخ تحویل</th>
                            <th>وضعیت</th>
                        </tr>
                    </thead>
                    <tbody id="modal_orders"></tbody>
                </table>
            </div>

            <div class="history-section">
                <h3>تاریخچه امتیازات</h3>
                <div class="points-filter">
                    <select id="points_filter">
                        <option value="all">فیلتر: همه</option>
                        <option value="order">فیلتر: سفارش</option>
                        <option value="referral">فیلتر: معرفی</option>
                    </select>
                    <select id="points_sort">
                        <option value="points_desc">مرتب‌سازی: امتیاز (نزولی)</option>
                        <option value="points_asc">مرتب‌سازی: امتیاز (صعودی)</option>
                    </select>
                    <button id="points_reset"><i class="fas fa-sync-alt"></i> بازنشانی</button>
                </div>
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>امتیاز</th>
                            <th>نوع رویداد</th>
                            <th>شناسه مرتبط</th>
                            <th>مشتری/سفارش مرتبط</th>
                            <th>توضیحات</th>
                            <th>تاریخ</th>
                        </tr>
                    </thead>
                    <tbody id="modal_points_history"></tbody>
                </table>
            </div>

            <div class="history-section">
                <h3>تاریخچه ارجاعات</h3>
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>شناسه ارجاع</th>
                            <th>مشتری معرفی‌شده</th>
                            <th>شناسه سفارش</th>
                            <th>وضعیت</th>
                            <th>تاریخ</th>
                        </tr>
                    </thead>
                    <tbody id="modal_referrals"></tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if (isset($response) && !$response['success']): ?>
        <script>
            alert('<?= addslashes($response['message']) ?>');
        </script>
    <?php endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const overlay = document.getElementById('modal_overlay');
        const modal = document.getElementById('modal');
        const modalOrders = document.getElementById('modal_orders');
        const modalPointsHistory = document.getElementById('modal_points_history');
        const modalReferrals = document.getElementById('modal_referrals');
        const modalCustomerId = document.getElementById('modal_customer_id');
        const modalCustomerFname = document.getElementById('modal_customer_fname');
        const modalCustomerLname = document.getElementById('modal_customer_lname');
        const modalCustomerPhone = document.getElementById('modal_customer_phone');
        const modalCustomerDate = document.getElementById('modal_customer_date');
        const modalCustomerDescription = document.getElementById('modal_customer_description');
        const modalCustomerPoints = document.getElementById('modal_customer_points');
        const modalCustomerLevel = document.getElementById('modal_customer_level');
        const modalCustomerReferrer = document.getElementById('modal_customer_referrer');
        const modalCustomerCreatedAt = document.getElementById('modal_customer_created_at');
        const modalTotalOrders = document.getElementById('modal_total_orders');
        const modalCompletedOrders = document.getElementById('modal_completed_orders');
        const modalTotalSpent = document.getElementById('modal_total_spent');
        const modalAvgOrder = document.getElementById('modal_avg_order');
        const modalCustomerReferrals = document.getElementById('modal_customer_referrals');
        const closeBtn = document.getElementById('modal_close');
        const detailBtns = document.querySelectorAll('.action-btn.details');
        const pointsSort = document.getElementById('points_sort');
        const pointsFilter = document.getElementById('points_filter');
        const pointsReset = document.getElementById('points_reset');
        let pointsData = [];

        function renderPointsHistory(data, sort = 'points_desc', filter = 'all') {
            modalPointsHistory.innerHTML = '';
            let filteredData = filter === 'all' ? [...data] : data.filter(p => p.event_type === filter);

            filteredData.sort((a, b) => {
                if (sort === 'points_desc') return b.points - a.points;
                if (sort === 'points_asc') return a.points - b.points;
                return 0;
            });

            if (filteredData.length > 0) {
                filteredData.forEach(point => {
                    const pointsClass = point.points > 0 ? 'positive' : point.points < 0 ? 'negative' : 'neutral';
                    const eventInfo = <?= json_encode(POINT_EVENTS) ?>[point.event_type] || { label: point.event_type, icon: 'fa-question', description: 'نامشخص' };
                    const relatedInfo = point.event_type === 'referral' && point.related_fname ? 
                        `${point.related_fname} ${point.related_lname} (#${point.related_id})` :
                        point.event_type === 'order' && point.order_total ? 
                        `سفارش #${point.related_id} (${Number(point.order_total).toLocaleString('fa-IR')} تومان)` : '-';

                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td><span class="points-value ${pointsClass}"><i class="fas fa-star"></i> ${point.points.toLocaleString('fa-IR')}</span></td>
                        <td><i class="fas ${eventInfo.icon}"></i> ${eventInfo.label}</td>
                        <td>${point.related_id ? `#${point.related_id}` : '-'}</td>
                        <td>${relatedInfo}</td>
                        <td><div class="points-description">${eventInfo.description}</div></td>
                        <td>${point.created_at || '-'}</td>
                    `;
                    modalPointsHistory.appendChild(row);
                });
            } else {
                modalPointsHistory.innerHTML = '<tr><td colspan="6">هیچ تاریخچه امتیازی با این فیلتر یافت نشد.</td></tr>';
            }
        }

        function openModal(customer) {
            try {
                if (!customer || Object.keys(customer).length === 0) {
                    console.warn('No customer data available');
                    customer = {}; // Fallback to empty object
                }

                overlay.classList.add('active');
                modal.classList.add('active');
                modalCustomerId.textContent = customer.id || '-';
                modalCustomerFname.textContent = customer.fName || '-';
                modalCustomerLname.textContent = customer.lName || '-';
                modalCustomerPhone.textContent = customer.phone || '-';
                modalCustomerDate.textContent = customer.date || '-';
                modalCustomerDescription.textContent = customer.description || '-';
                modalCustomerPoints.textContent = customer.points || '0';
                modalCustomerLevel.textContent = customer.level ? 
                    `${<?= json_encode(array_map(fn($v) => $v['label'], CUSTOMER_LEVELS)) ?>[customer.level]} (تخفیف: ${<?= json_encode(array_map(fn($v) => $v['discount'], CUSTOMER_LEVELS)) ?>[customer.level]}%)` : '-';
                modalCustomerReferrer.textContent = customer.referrer ? 
                    `${customer.referrer.fName} ${customer.referrer.lName} (#${customer.referrer.id})` : '-';
                modalCustomerCreatedAt.textContent = customer.created_at || '-';
                modalTotalOrders.textContent = customer.stats?.total_orders?.toLocaleString('fa-IR') || '0';
                modalCompletedOrders.textContent = customer.stats?.completed_orders?.toLocaleString('fa-IR') || '0';
                modalTotalSpent.textContent = customer.stats?.total_spent ? Number(customer.stats.total_spent).toLocaleString('fa-IR') : '0';
                modalAvgOrder.textContent = customer.stats?.avg_order_value ? Number(customer.stats.avg_order_value).toLocaleString('fa-IR') : '0';
                modalCustomerReferrals.textContent = customer.referrals?.length?.toLocaleString('fa-IR') || '0';

                modalOrders.innerHTML = '';
                if (customer.orders && customer.orders.length > 0) {
                    customer.orders.forEach(order => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>${order.id || '-'}</td>
                            <td>${Number(order.subtotal || 0).toLocaleString('fa-IR')}</td>
                            <td>${Number(order.discount || 0).toLocaleString('fa-IR')}</td>
                            <td>${Number(order.total || 0).toLocaleString('fa-IR')}</td>
                            <td>${Number(order.payment || 0).toLocaleString('fa-IR')}</td>
                            <td>${Number(order.balance || 0).toLocaleString('fa-IR')}</td>
                            <td>${order.item_count?.toLocaleString('fa-IR') || '0'}</td>
                            <td>${order.order_date || '-'}</td>
                            <td>${order.delivery_date || '-'}</td>
                            <td><span class="order-status ${order.status?.replace('_', '-') || ''}">
                                <i class="fas ${order.status === 'pending' ? 'fa-hourglass-half' : (order.status === 'in_progress' ? 'fa-cogs' : (order.status === 'completed' ? 'fa-check-circle' : 'fa-times-circle'))}"></i>
                                ${<?= json_encode(ORDER_STATUSES) ?>[order.status] || order.status || '-'}
                            </span></td>
                        `;
                        modalOrders.appendChild(row);
                    });
                } else {
                    modalOrders.innerHTML = '<tr><td colspan="10">هیچ سفارشی ثبت نشده است.</td></tr>';
                }

                pointsData = customer.points_history || [];
                renderPointsHistory(pointsData);

                modalReferrals.innerHTML = '';
                if (customer.referrals && customer.referrals.length > 0) {
                    customer.referrals.forEach(referral => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>${referral.id}</td>
                            <td>${referral.fName} ${referral.lName} (#${referral.referred_id})</td>
                            <td>${referral.order_id || '-'}</td>
                            <td><span class="referral-status ${referral.status}">${referral.status === 'pending' ? 'در انتظار' : 'تکمیل‌شده'}</span></td>
                            <td>${referral.created_at || '-'}</td>
                        `;
                        modalReferrals.appendChild(row);
                    });
                } else {
                    modalReferrals.innerHTML = '<tr><td colspan="5">هیچ ارجاعی ثبت نشده است.</td></tr>';
                }
            } catch (error) {
                console.error('Error opening modal:', error);
                alert('خطایی در نمایش جزئیات رخ داد. لطفاً دوباره تلاش کنید.');
            }
        }

        function closeModal() {
            overlay.classList.remove('active');
            modal.classList.remove('active');
        }

        detailBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                let customer;
                try {
                    customer = JSON.parse(btn.dataset.customer);
                } catch (err) {
                    console.error('Invalid JSON in data-customer:', btn.dataset.customer, err);
                    customer = {};
                }
                openModal(customer);
            });
        });

        closeBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', closeModal);

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                closeModal();
            }
        });

        pointsSort.addEventListener('change', () => {
            renderPointsHistory(pointsData, pointsSort.value, pointsFilter.value);
        });

        pointsFilter.addEventListener('change', () => {
            renderPointsHistory(pointsData, pointsSort.value, pointsFilter.value);
        });

        pointsReset.addEventListener('click', () => {
            pointsSort.value = 'points_desc';
            pointsFilter.value = 'all';
            renderPointsHistory(pointsData);
        });
    });
    </script>
</body>
</html>
