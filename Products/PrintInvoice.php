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
const CUSTOMER_LEVELS = [
    'bronze' => 'برنزی',
    'silver' => 'نقره‌ای',
    'gold' => 'طلایی'
];

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

    public function getOrderById(int $id): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT o.*, 
                       CONCAT(c.fname, ' ', c.lname) AS customer_name,
                       c.phone AS customer_phone,
                       c.points AS customer_points,
                       c.level AS customer_level
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
                SELECT oi.product_id, oi.quantity, oi.unit_price, oi.subtotal,
                       p.product_code, p.imagesize, p.type, p.color, p.price, p.cost_per_unit
                FROM photonim_db.order_items oi
                JOIN photonim_db.pricelist p ON oi.product_id = p.id
                WHERE oi.order_id = ?
            ");
            $stmt->execute([$id]);
            $items = $stmt->fetchAll();

            $stmt = $this->pdo->prepare("
                SELECT status, changed_at 
                FROM photonim_db.order_status_history 
                WHERE order_id = ? 
                ORDER BY changed_at DESC
            ");
            $stmt->execute([$id]);
            $statusHistory = $stmt->fetchAll();

            $order['items'] = $items;
            $order['status_history'] = $statusHistory;
            return ['success' => true, 'data' => $order];
        } catch (PDOException $e) {
            $this->logError("Get Order Error: " . $e->getMessage(), ['order_id' => $id]);
            return ['success' => false, 'message' => 'خطا در بازیابی اطلاعات سفارش: ' . $e->getMessage()];
        }
    }

    public function generateInvoiceNumber(int $orderId): string {
        return sprintf("INV-%06d", $orderId);
    }
}

if (!isset($conn) || !$conn instanceof PDO) {
    http_response_code(500);
    exit("<p style='color: #EF4444; text-align: center; font-family: Vazirmatn;'>خطا: اتصال به دیتابیس برقرار نیست</p>");
}

if (!isset($_SESSION['user_id'])) {
    $_SESSION['message'] = 'لطفاً ابتدا وارد سیستم شوید.';
    header("Location: Index.php");
    exit;
}

$orderManager = new OrderManager($conn);
$orderData = null;
$errorMessage = null;
$invoiceNumber = 'نامشخص';

if (isset($_GET['InvoiceId'])) {
    $invoiceId = filter_input(INPUT_GET, 'InvoiceId', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($invoiceId === false || $invoiceId === null) {
        $errorMessage = 'شناسه سفارش نامعتبر است';
    } else {
        $result = $orderManager->getOrderById($invoiceId);
        if ($result['success']) {
            $orderData = $result['data'];
            $invoiceNumber = $orderManager->generateInvoiceNumber($invoiceId);
        } else {
            $errorMessage = $result['message'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="فاکتور رسمی استودیو نیما">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="Image/Logo1.png" type="image/png" sizes="80x80">
    <title>فاکتور #<?php echo htmlspecialchars($invoiceNumber); ?> | استودیو نیما</title>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.5/JsBarcode.all.min.js"></script>
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
            --level-bronze: #CD7F32;
            --level-silver: #C0C0C0;
            --level-gold: #FFD700;
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
            padding: calc(var(--spacing-unit) * 2);
            -webkit-font-smoothing: antialiased;
        }

        .invoice-container {
            max-width: 900px;
            margin: 0 auto;
            background: var(--secondary-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: calc(var(--spacing-unit) * 2);
            position: relative;
            overflow: hidden;
        }

        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: var(--spacing-unit);
            border-bottom: 2px solid var(--accent);
            margin-bottom: calc(var(--spacing-unit) * 1.5);
        }

        .invoice-header img {
            width: 100px;
            height: 60px;
            object-fit: contain;
        }

        .invoice-header .header-text {
            text-align: right;
        }

        .invoice-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-dark);
        }

        .invoice-header p {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-top: 4px;
        }

        .barcode-container {
            margin-top: var(--spacing-unit);
            text-align: right;
        }

        .invoice-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: calc(var(--spacing-unit) * 0.75);
            margin-bottom: calc(var(--spacing-unit) * 1.5);
            background: var(--card-hover);
            padding: var(--spacing-unit);
            border-radius: var(--radius);
            border: 1px solid var(--border);
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

        .status-badge, .level-badge {
            padding: 6px 12px;
            border-radius: var(--radius);
            font-size: 0.85rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .status-pending { color: var(--status-pending); background: rgba(245, 158, 11, 0.1); }
        .status-in-progress { color: var(--status-in-progress); background: rgba(37, 99, 235, 0.1); }
        .status-completed { color: var(--status-completed); background: rgba(22, 163, 74, 0.1); }
        .status-canceled { color: var(--status-canceled); background: rgba(220, 38, 38, 0.1); }
        .level-bronze { color: var(--level-bronze); background: rgba(205, 127, 50, 0.1); }
        .level-silver { color: var(--level-silver); background: rgba(192, 192, 192, 0.1); }
        .level-gold { color: var(--level-gold); background: rgba(255, 215, 0, 0.1); }

        .products-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: calc(var(--spacing-unit) * 1.5);
            box-shadow: var(--shadow);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .products-table th, .products-table td {
            padding: 12px 16px;
            text-align: right;
            border-bottom: 1px solid var(--border);
        }

        .products-table th {
            background: var(--accent);
            color: var(--secondary-bg);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        .products-table td {
            background: var(--secondary-bg);
            font-size: 0.85rem;
            color: var(--text-dark);
            transition: var(--transition);
        }

        .products-table tr:hover td {
            background: var(--card-hover);
        }

        .status-history {
            margin-bottom: calc(var(--spacing-unit) * 1.5);
            background: var(--card-hover);
            padding: var(--spacing-unit);
            border-radius: var(--radius);
            border: 1px solid var(--border);
        }

        .status-history h3 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: calc(var(--spacing-unit) * 0.5);
        }

        .status-history ul {
            list-style: none;
            padding: 0;
        }

        .status-history li {
            padding: 8px 0;
            border-bottom: 1px dashed var(--border);
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
        }

        .status-history li:last-child {
            border-bottom: none;
        }

        .totals {
            text-align: right;
            padding: var(--spacing-unit);
            background: var(--card-hover);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .totals p {
            margin: 0 0 0.5rem;
            display: flex;
            justify-content: space-between;
            font-size: 0.95rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px dashed var(--border);
        }

        .totals p:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .totals .label {
            font-weight: 600;
            color: var(--text-dark);
        }

        .totals .value {
            color: var(--accent);
        }

        .totals .highlight {
            font-weight: 700;
            color: var(--success);
        }

        .footer {
            text-align: center;
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: calc(var(--spacing-unit) * 1.5);
            padding-top: var(--spacing-unit);
            border-top: 1px dashed var(--border);
        }

        .footer .signature {
            font-style: italic;
            color: var(--accent);
            margin-top: 8px;
        }

        .error-message {
            color: var(--danger);
            font-size: 0.9rem;
            text-align: center;
            margin: var(--spacing-unit) 0;
            background: rgba(220, 38, 38, 0.1);
            padding: var(--spacing-unit);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .action-buttons {
            display: flex;
            gap: calc(var(--spacing-unit) * 0.5);
            margin-bottom: var(--spacing-unit);
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 8px 16px;
            border-radius: var(--radius);
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--secondary-bg);
            transition: var(--transition);
            border: none;
            cursor: pointer;
            box-shadow: var(--shadow);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .print-btn {
            background: var(--success);
        }

        .pdf-btn {
            background: var(--accent);
        }

        .back-btn {
            background: var(--warning);
        }

        .print-btn:hover {
            background: var(--success-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }

        .pdf-btn:hover {
            background: var(--accent-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }

        .back-btn:hover {
            background: var(--warning-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }

        .fade-in {
            animation: fadeIn 0.3s ease-out forwards;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media print {
            body {
                background: none;
                padding: 0;
                margin: 0;
                font-size: 10pt;
            }
            .invoice-container {
                box-shadow: none;
                padding: var(--spacing-unit);
                margin: 0;
                max-width: 100%;
                border: none;
            }
            .action-buttons {
                display: none;
            }
            .footer {
                page-break-before: avoid;
            }
            .barcode-container {
                display: block !important;
            }
            .invoice-container::before {
                content: "فاکتور استودیو نیما - شماره: <?php echo htmlspecialchars($invoiceNumber); ?>";
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                background: var(--accent);
                color: var(--secondary-bg);
                padding: 5mm;
                text-align: center;
                font-size: 10pt;
                font-weight: 600;
            }
        }

        @media (max-width: 768px) {
            body {
                padding: var(--spacing-unit);
            }
            .invoice-header {
                flex-direction: column;
                text-align: center;
                gap: var(--spacing-unit);
            }
            .invoice-header img {
                margin-top: var(--spacing-unit);
            }
            .invoice-details {
                grid-template-columns: 1fr;
            }
            .products-table th, .products-table td {
                font-size: 0.8rem;
                padding: 8px;
            }
            .totals p {
                font-size: 0.9rem;
            }
            .action-btn {
                width: 100%;
                justify-content: center;
            }
            .barcode-container {
                text-align: center;
            }
        }

        @media (max-width: 480px) {
            .products-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            .status-history li {
                flex-direction: column;
                align-items: flex-end;
                gap: 4px;
            }
        }
    </style>
</head>
<body>
    <?php if ($orderData): ?>
        <div class="action-buttons fade-in">
            <button class="action-btn print-btn"><i class="fas fa-print"></i> چاپ فاکتور</button>
            <button class="action-btn pdf-btn"><i class="fas fa-file-pdf"></i> دانلود PDF</button>
            <button class="action-btn back-btn" onclick="window.location.href='Order%20List.php?page=1'"><i class="fas fa-arrow-right"></i> بازگشت به لیست سفارشات</button>
        </div>
    <?php endif; ?>

    <div class="invoice-container fade-in">
        <?php if ($orderData): ?>
            <div class="invoice-header">
                <div class="header-text">
                    <h1>استودیو نیما</h1>
                    <p>مدیریت حرفه‌ای سفارشات و مشتریان</p>
                </div>
                <img src="Image/Logo2.png" alt="لوگو استودیو نیما">
            </div>

            <div class="barcode-container">
                <svg id="barcode"></svg>
            </div>

            <div class="invoice-details">
                <div class="detail-item">
                    <span class="detail-label">شماره فاکتور</span>
                    <span class="detail-value"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($invoiceNumber); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">شناسه سفارش</span>
                    <span class="detail-value"><i class="fas fa-id-badge"></i> <?php echo htmlspecialchars($orderData['id']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">تاریخ صدور</span>
                    <span class="detail-value"><i class="fas fa-calendar-alt"></i> <?php echo date('Y-m-d'); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">وضعیت</span>
                    <span class="status-badge status-<?php echo str_replace('_', '-', $orderData['status']); ?>">
                        <i class="fas <?php echo $orderData['status'] === 'pending' ? 'fa-hourglass-half' : ($orderData['status'] === 'in_progress' ? 'fa-cogs' : ($orderData['status'] === 'completed' ? 'fa-check-circle' : 'fa-times-circle')); ?>"></i>
                        <?php echo htmlspecialchars(ORDER_STATUSES[$orderData['status']]); ?>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">مشتری</span>
                    <span class="detail-value"><i class="fas fa-user"></i> <?php echo htmlspecialchars($orderData['customer_name']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">شماره تماس</span>
                    <span class="detail-value"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($orderData['customer_phone'] ?? '-'); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">سطح مشتری</span>
                    <span class="level-badge level-<?php echo htmlspecialchars($orderData['customer_level']); ?>">
                        <i class="fas fa-medal"></i>
                        <?php echo htmlspecialchars(CUSTOMER_LEVELS[$orderData['customer_level']]); ?>
                    </span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">امتیازات مشتری</span>
                    <span class="detail-value"><i class="fas fa-star"></i> <?php echo number_format($orderData['customer_points'], 0, '.', ','); ?> امتیاز</span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">تاریخ سفارش</span>
                    <span class="detail-value"><i class="fas fa-calendar-alt"></i> <?php echo htmlspecialchars($orderData['order_date']); ?></span>
                </div>
                <div class="detail-item">
                    <span class="detail-label">موعد تحویل</span>
                    <span class="detail-value"><i class="fas fa-calendar-check"></i> <?php echo htmlspecialchars($orderData['delivery_date']); ?></span>
                </div>
            </div>

            <table class="products-table">
                <thead>
                    <tr>
                        <th>ردیف</th>
                        <th>کد محصول</th>
                        <th>محصول</th>
                        <th>تعداد</th>
                        <th>قیمت واحد (ت)</th>
                        <th>جمع (ت)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orderData['items'])): ?>
                        <tr><td colspan="6">هیچ محصولی ثبت نشده است</td></tr>
                    <?php else: ?>
                        <?php $rowNum = 1; ?>
                        <?php foreach ($orderData['items'] as $item): ?>
                            <tr>
                                <td><?php echo $rowNum++; ?></td>
                                <td><?php echo htmlspecialchars($item['product_code']); ?></td>
                                <td><?php echo htmlspecialchars("{$item['imagesize']} {$item['type']} {$item['color']}"); ?></td>
                                <td><?php echo number_format($item['quantity'], 0, '.', ','); ?></td>
                                <td><?php echo number_format($item['unit_price'], 0, '.', ','); ?></td>
                                <td><?php echo number_format($item['subtotal'], 0, '.', ','); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if (!empty($orderData['status_history'])): ?>
                <div class="status-history">
                    <h3>تاریخچه وضعیت سفارش</h3>
                    <ul>
                        <?php foreach ($orderData['status_history'] as $history): ?>
                            <li>
                                <span><?php echo htmlspecialchars(ORDER_STATUSES[$history['status']]); ?></span>
                                <span><?php echo htmlspecialchars($history['change_at']); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="totals">
                <p><span class="label">توضیحات:</span> <span class="value"><?php echo htmlspecialchars($orderData['description'] ?? 'بدون توضیحات'); ?></span></p>
                <?php if ($orderData['cost'] > 0): ?>
                    <p><span class="label">هزینه اضافی:</span> <span class="value"><?php echo number_format($orderData['cost'], 0, '.', ','); ?> تومان</span></p>
                <?php endif; ?>
                <p><span class="label">جمع کل محصولات:</span> <span class="value"><?php echo number_format($orderData['subtotal'], 0, '.', ','); ?> تومان</span></p>
                <p><span class="label">تخفیف:</span> <span class="value"><?php echo number_format($orderData['discount'], 0, '.', ','); ?> تومان</span></p>
                <p><span class="label">مبلغ قابل پرداخت:</span> <span class="value"><?php echo number_format($orderData['total'], 0, '.', ','); ?> تومان</span></p>
                <p><span class="label">مبلغ پرداختی:</span> <span class="highlight"><?php echo number_format($orderData['payment'], 0, '.', ','); ?> تومان</span></p>
                <p><span class="label"><?php echo $orderData['balance'] > 0 ? 'بدهی:' : ($orderData['balance'] < 0 ? 'اضافه پرداخت:' : 'مانده:'); ?></span> 
                    <span class="value"><?php echo number_format(abs($orderData['balance']), 0, '.', ','); ?> تومان</span></p>
            </div>

            <div class="footer">
                <p>استودیو نیما | تماس: 0912-XXX-XXXX | آدرس: [آدرس استودیو]</p>
                <p>ایمیل: info@photonima.ir | وبسایت: www.photonima.ir</p>
                <p class="signature">با تشکر از شما برای انتخاب استودیو نیما</p>
            </div>
        <?php elseif ($errorMessage): ?>
            <p class="error-message"><?php echo htmlspecialchars($errorMessage); ?></p>
        <?php else: ?>
            <p class="error-message">لطفاً یک سفارش معتبر انتخاب کنید</p>
        <?php endif; ?>
    </div>

    <script>
        <?php if ($orderData): ?>
        document.addEventListener('DOMContentLoaded', () => {
            const printBtn = document.querySelector('.print-btn');
            const pdfBtn = document.querySelector('.pdf-btn');
            let isFirstPrint = true;

            // Generate barcode
            JsBarcode("#barcode", "<?php echo htmlspecialchars($invoiceNumber); ?>", {
                format: "CODE128",
                displayValue: true,
                fontSize: 14,
                height: 50,
                width: 2,
                margin: 10
            });

            printBtn.addEventListener('click', () => {
                window.print();
            });

            pdfBtn.addEventListener('click', () => {
                const element = document.querySelector('.invoice-container');
                const opt = {
                    margin: [10, 10, 10, 10],
                    filename: `Invoice_${<?php echo json_encode($invoiceNumber); ?>}.pdf`,
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 2, useCORS: true },
                    jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
                };
                html2pdf().set(opt).from(element).save();
            });

            window.matchMedia('print').addEventListener('change', (e) => {
                if (e.matches && isFirstPrint) {
                    isFirstPrint = false;
                    setTimeout(() => {
                        if (confirm('آیا می‌خواهید به صفحه سفارشات بازگردید؟')) {
                            window.location.href = 'Order%20List.php?page=1';
                        }
                    }, 500);
                }
            });
        });
        <?php endif; ?>
    </script>
</body>
</html>
