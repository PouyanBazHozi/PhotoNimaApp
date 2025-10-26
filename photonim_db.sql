-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Oct 26, 2025 at 10:55 AM
-- Server version: 10.6.23-MariaDB
-- PHP Version: 8.1.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `photonim_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `customerregistration`
--

CREATE TABLE `customerregistration` (
  `id` int(11) NOT NULL,
  `fName` varchar(255) NOT NULL,
  `lName` varchar(255) NOT NULL,
  `phone` varchar(255) NOT NULL,
  `date` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `points` int(11) DEFAULT 0 COMMENT 'امتیاز مشتری',
  `level` enum('bronze','silver','gold') DEFAULT 'bronze' COMMENT 'سطح مشتری',
  `referred_by` int(11) DEFAULT NULL COMMENT 'شناسه مشتری معرف',
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'تاریخ آخرین به‌روزرسانی'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customerregistration`
--

INSERT INTO `customerregistration` (`id`, `fName`, `lName`, `phone`, `date`, `description`, `created_at`, `points`, `level`, `referred_by`, `updated_at`) VALUES
(36, 'رضا', 'بازحوضی', '09155130549', NULL, NULL, '2025-03-24 07:56:23', 100, 'bronze', NULL, '2025-04-25 23:25:45'),
(39, 'پویان', 'بازحوضی', '09195308703', NULL, NULL, '2025-04-25 23:25:45', 11259, 'gold', 36, '2025-04-26 10:46:49'),
(40, 'محمد', 'موسوی', '09155140549', NULL, NULL, '2025-04-26 10:46:49', 0, 'bronze', 39, NULL),
(41, 'محمد', 'موشوی', '09195308707', NULL, NULL, '2025-05-13 09:18:52', 1140, 'silver', NULL, '2025-05-13 09:30:53');

-- --------------------------------------------------------

--
-- Table structure for table `level_history`
--

CREATE TABLE `level_history` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `old_level` enum('bronze','silver','gold') NOT NULL,
  `new_level` enum('bronze','silver','gold') NOT NULL,
  `points` int(11) NOT NULL,
  `changed_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order`
--

CREATE TABLE `order` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `subtotal` decimal(15,2) DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `delivery_date` varchar(50) NOT NULL,
  `discount` decimal(15,2) DEFAULT 0.00,
  `payment` decimal(15,2) NOT NULL,
  `balance` decimal(15,2) DEFAULT 0.00,
  `total` decimal(15,2) NOT NULL,
  `order_date` date NOT NULL,
  `status` enum('pending','in_progress','completed','canceled') DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `cost` decimal(15,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `order`
--

INSERT INTO `order` (`id`, `customer_id`, `subtotal`, `description`, `delivery_date`, `discount`, `payment`, `balance`, `total`, `order_date`, `status`, `created_at`, `updated_at`, `cost`) VALUES
(35, 39, 10169879.00, 'فوری', '15', 100000.00, 600000.00, 9469879.00, 10069879.00, '2025-04-25', 'completed', '2025-04-25 23:26:58', '2025-04-25 23:47:09', 0.00),
(36, 40, 790000.00, '', '15', 100000.00, 500000.00, 190000.00, 690000.00, '2025-04-26', 'canceled', '2025-04-26 10:48:36', '2025-06-04 22:26:38', 0.00),
(37, 41, 1190000.00, '', '15', 50000.00, 80000.00, 1060000.00, 1140000.00, '2025-05-13', 'completed', '2025-05-13 09:22:56', '2025-05-13 09:30:53', 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `quantity`, `unit_price`, `subtotal`) VALUES
(129, 35, 5, 2, 195000.00, 390000.00),
(130, 35, 3, 2, 400000.00, 800000.00),
(131, 35, 9, 1, 8979879.00, 8979879.00),
(132, 36, 5, 2, 195000.00, 390000.00),
(133, 36, 3, 1, 400000.00, 400000.00),
(134, 37, 5, 2, 195000.00, 390000.00),
(135, 37, 3, 2, 400000.00, 800000.00);

-- --------------------------------------------------------

--
-- Table structure for table `order_status_history`
--

CREATE TABLE `order_status_history` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `status` enum('draft','pending','in_progress','completed','canceled') DEFAULT NULL,
  `changed_at` datetime DEFAULT current_timestamp(),
  `changed_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_status_history`
--

INSERT INTO `order_status_history` (`id`, `order_id`, `status`, `changed_at`, `changed_by`, `notes`) VALUES
(1, 32, 'completed', '2025-03-22 16:04:41', 2, 'تغییر گروهی وضعیت به تکمیل شده توسط کاربر 2');

-- --------------------------------------------------------

--
-- Table structure for table `point_history`
--

CREATE TABLE `point_history` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `points` int(11) NOT NULL COMMENT 'مقدار امتیاز اضافه یا کسر شده',
  `event_type` varchar(50) NOT NULL COMMENT 'نوع رویداد (مثل referral)',
  `related_id` int(11) DEFAULT NULL COMMENT 'شناسه مرتبط (مثل مشتری معرفی‌شده)',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `point_history`
--

INSERT INTO `point_history` (`id`, `customer_id`, `points`, `event_type`, `related_id`, `created_at`) VALUES
(1, 36, 100, 'referral', 39, '2025-04-25 23:25:45'),
(2, 39, 100, 'referral', 40, '2025-04-26 10:46:49');

-- --------------------------------------------------------

--
-- Table structure for table `pricelist`
--

CREATE TABLE `pricelist` (
  `id` int(11) NOT NULL,
  `product_code` varchar(20) NOT NULL COMMENT 'کد محصول به صورت خودکار تولید می‌شود',
  `imagesize` varchar(100) NOT NULL COMMENT 'ابعاد محصول (قبلاً name)',
  `type` varchar(50) DEFAULT NULL COMMENT 'نوع محصول (قبلاً category)',
  `color` varchar(30) DEFAULT NULL COMMENT 'رنگ محصول',
  `price` decimal(15,2) NOT NULL DEFAULT 0.00 COMMENT 'قیمت محصول به تومان',
  `default_discount` decimal(15,2) DEFAULT 0.00 COMMENT 'تخفیف پیش‌فرض به تومان',
  `description` text DEFAULT NULL COMMENT 'توضیحات محصول',
  `created_at` datetime DEFAULT current_timestamp() COMMENT 'تاریخ ثبت',
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp() COMMENT 'تاریخ آخرین به‌روزرسانی',
  `cost_per_unit` decimal(15,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='جدول لیست قیمت محصولات';

--
-- Dumping data for table `pricelist`
--

INSERT INTO `pricelist` (`id`, `product_code`, `imagesize`, `type`, `color`, `price`, `default_discount`, `description`, `created_at`, `updated_at`, `cost_per_unit`) VALUES
(3, 'PRD-20250303-2924', '20*30', 'شاسی', 'سفید', 400000.00, 10000.00, NULL, '2025-03-03 15:16:36', '2025-03-11 12:52:54', 0.00),
(5, 'PRD-20250304-4147', '16*21', 'مجموعه 3 نایی', 'قهوه ای', 195000.00, 0.00, NULL, '2025-03-04 13:01:06', '2025-03-11 13:02:31', 0.00),
(8, 'PRD-20250311-4612', '45*53', 'بسیب', 'سیبس', 25525252.00, 0.00, NULL, '2025-03-11 21:52:57', NULL, 0.00),
(9, 'PRD-20250311-7655', '22*22', 'یسب', 'یبسب', 8979879.00, 0.00, NULL, '2025-03-11 21:53:10', NULL, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `referrals`
--

CREATE TABLE `referrals` (
  `id` int(11) NOT NULL,
  `referrer_id` int(11) NOT NULL COMMENT 'شناسه مشتری معرف',
  `referred_id` int(11) NOT NULL COMMENT 'شناسه مشتری معرفی‌شده',
  `order_id` int(11) DEFAULT NULL COMMENT 'شناسه سفارش تکمیل‌شده',
  `status` enum('pending','completed') DEFAULT 'pending' COMMENT 'وضعیت ارجاع',
  `created_at` datetime DEFAULT current_timestamp() COMMENT 'تاریخ ایجاد'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_persian_ci;

--
-- Dumping data for table `referrals`
--

INSERT INTO `referrals` (`id`, `referrer_id`, `referred_id`, `order_id`, `status`, `created_at`) VALUES
(7, 36, 39, NULL, 'pending', '2025-04-25 23:25:45'),
(8, 39, 40, NULL, 'pending', '2025-04-26 10:46:49');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`) VALUES
(2, 'Pouyan', '$2y$10$CnapvUEuk0aSBPs0nbR9reyyOoVxNVO09pNXbwPqXUDx2S.XJQYwm'),
(4, 'admin', '$2y$10$9e5vjyQCp9myzLfJEyjRCuvcqbrfZz2Np7Qw7/ywTNi1VwhyuvdVe');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `customerregistration`
--
ALTER TABLE `customerregistration`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_customer_search` (`id`,`fName`,`lName`),
  ADD KEY `referred_by` (`referred_by`);

--
-- Indexes for table `level_history`
--
ALTER TABLE `level_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`);

--
-- Indexes for table `order`
--
ALTER TABLE `order`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `order_status_history`
--
ALTER TABLE `order_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `point_history`
--
ALTER TABLE `point_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`);

--
-- Indexes for table `pricelist`
--
ALTER TABLE `pricelist`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_code` (`product_code`);

--
-- Indexes for table `referrals`
--
ALTER TABLE `referrals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `referrer_id` (`referrer_id`),
  ADD KEY `referred_id` (`referred_id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `customerregistration`
--
ALTER TABLE `customerregistration`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `level_history`
--
ALTER TABLE `level_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order`
--
ALTER TABLE `order`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=136;

--
-- AUTO_INCREMENT for table `order_status_history`
--
ALTER TABLE `order_status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `point_history`
--
ALTER TABLE `point_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `pricelist`
--
ALTER TABLE `pricelist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `referrals`
--
ALTER TABLE `referrals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `customerregistration`
--
ALTER TABLE `customerregistration`
  ADD CONSTRAINT `customerregistration_ibfk_1` FOREIGN KEY (`referred_by`) REFERENCES `customerregistration` (`id`);

--
-- Constraints for table `level_history`
--
ALTER TABLE `level_history`
  ADD CONSTRAINT `level_history_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customerregistration` (`id`);

--
-- Constraints for table `order`
--
ALTER TABLE `order`
  ADD CONSTRAINT `order_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customerregistration` (`id`);

--
-- Constraints for table `point_history`
--
ALTER TABLE `point_history`
  ADD CONSTRAINT `point_history_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customerregistration` (`id`);

--
-- Constraints for table `referrals`
--
ALTER TABLE `referrals`
  ADD CONSTRAINT `referrals_ibfk_1` FOREIGN KEY (`referrer_id`) REFERENCES `customerregistration` (`id`),
  ADD CONSTRAINT `referrals_ibfk_2` FOREIGN KEY (`referred_id`) REFERENCES `customerregistration` (`id`),
  ADD CONSTRAINT `referrals_ibfk_3` FOREIGN KEY (`order_id`) REFERENCES `order` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
