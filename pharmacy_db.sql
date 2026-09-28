-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Sep 28, 2026 at 03:18 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `pharmacy_db`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_settle_invoice_stock` (IN `p_order_id` INT)   BEGIN
    DECLARE v_medicine_id INT;
    DECLARE v_qty         INT;
    DECLARE v_name        VARCHAR(100);
    DECLARE v_msg         VARCHAR(255);
    DECLARE v_done        INT DEFAULT 0;

    DECLARE cur CURSOR FOR
        SELECT medicine_id, quantity FROM ORDER_ITEM WHERE order_id = p_order_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    OPEN cur;

    read_loop: LOOP
        FETCH cur INTO v_medicine_id, v_qty;
        IF v_done THEN
            LEAVE read_loop;
        END IF;

        UPDATE MEDICINE
           SET quantity_in_stock = quantity_in_stock - v_qty
         WHERE medicine_id = v_medicine_id
           AND quantity_in_stock >= v_qty;

        IF ROW_COUNT() = 0 THEN
            SELECT name INTO v_name FROM MEDICINE WHERE medicine_id = v_medicine_id;
            CLOSE cur;
            SET v_msg = CONCAT('Not enough stock left of ', v_name, ' to complete this order.');
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
        END IF;
    END LOOP;

    CLOSE cur;

    UPDATE CUSTOMER_ORDER SET status = 'READY' WHERE order_id = p_order_id;
END$$

--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `fn_invoice_balance` (`p_invoice_id` INT) RETURNS DECIMAL(10,2) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE v_total DECIMAL(10,2);
    DECLARE v_paid  DECIMAL(10,2);

    SELECT total_amount INTO v_total
      FROM INVOICE
     WHERE invoice_id = p_invoice_id;

    SELECT COALESCE(SUM(amount), 0) INTO v_paid
      FROM PAYMENT
     WHERE invoice_id = p_invoice_id
       AND status = 'PAID';

    RETURN v_total - v_paid;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `ADMIN`
--

CREATE TABLE `ADMIN` (
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ADMIN`
--

INSERT INTO `ADMIN` (`user_id`) VALUES
(1),
(16),
(17);

-- --------------------------------------------------------

--
-- Table structure for table `CUSTOMER`
--

CREATE TABLE `CUSTOMER` (
  `user_id` int(11) NOT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `CUSTOMER`
--

INSERT INTO `CUSTOMER` (`user_id`, `phone`, `address`) VALUES
(4, '0771234507', '27, Green Road, Trincomalee'),
(5, '0762345678', '108/A, Hospital Street, Hatton'),
(6, '0713456789', '56, Kandy Road, Chunnakam'),
(7, '0774567890', '12, Beach Road, Killinochchi'),
(8, '0725678901', '7, Main Street, Orr\'s Hill, Nallur'),
(14, '0711111111', '2 New Lane');

-- --------------------------------------------------------

--
-- Table structure for table `CUSTOMER_ORDER`
--

CREATE TABLE `CUSTOMER_ORDER` (
  `order_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `pharmacist_id` int(11) DEFAULT NULL,
  `order_date` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('PENDING','CONFIRMED','READY','COLLECTED','CANCELLED') NOT NULL DEFAULT 'PENDING'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `CUSTOMER_ORDER`
--

INSERT INTO `CUSTOMER_ORDER` (`order_id`, `customer_id`, `pharmacist_id`, `order_date`, `status`) VALUES
(1, 4, 2, '2026-07-02 09:15:00', 'COLLECTED'),
(2, 5, 2, '2026-07-08 11:40:00', 'COLLECTED'),
(3, 6, 3, '2026-07-15 16:05:00', 'COLLECTED'),
(4, 4, 3, '2026-07-22 10:30:00', 'COLLECTED'),
(5, 7, 2, '2026-08-01 14:20:00', 'COLLECTED'),
(6, 8, 3, '2026-08-04 08:50:00', 'COLLECTED'),
(7, 5, 2, '2026-08-07 19:35:00', 'READY'),
(8, 6, 2, '2026-07-28 13:00:00', 'CANCELLED'),
(9, 6, 2, '2026-08-27 16:28:44', 'COLLECTED'),
(10, 6, 2, '2026-08-27 16:30:37', 'READY'),
(11, 6, 2, '2026-08-27 16:44:27', 'COLLECTED'),
(12, 6, 2, '2026-08-27 17:43:48', 'COLLECTED'),
(13, 7, 3, '2026-08-27 23:59:51', 'CANCELLED'),
(15, 14, 2, '2026-08-30 22:00:26', 'READY'),
(16, 4, 2, '2026-09-05 10:44:30', 'READY'),
(17, 4, NULL, '2026-09-05 10:47:21', 'CANCELLED'),
(18, 4, 2, '2026-09-05 23:18:07', 'READY'),
(22, 8, 2, '2026-09-05 23:52:15', 'READY'),
(25, 4, 2, '2026-09-07 22:14:11', 'COLLECTED'),
(26, 4, 2, '2026-09-07 22:20:21', 'CANCELLED'),
(27, 4, NULL, '2026-09-07 22:36:47', 'CANCELLED'),
(28, 4, NULL, '2026-09-13 20:38:37', 'CANCELLED'),
(29, 4, NULL, '2026-09-13 20:44:28', 'CANCELLED'),
(30, 4, 2, '2026-09-13 20:47:43', 'CONFIRMED'),
(31, 4, NULL, '2026-09-13 20:49:44', 'CANCELLED'),
(32, 4, 2, '2026-09-22 14:17:21', 'COLLECTED');

-- --------------------------------------------------------

--
-- Table structure for table `INVOICE`
--

CREATE TABLE `INVOICE` (
  `invoice_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `issue_date` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `INVOICE`
--

INSERT INTO `INVOICE` (`invoice_id`, `order_id`, `total_amount`, `issue_date`) VALUES
(1, 1, 175.00, '2026-07-02 09:15:00'),
(2, 2, 432.00, '2026-07-08 11:40:00'),
(3, 3, 850.00, '2026-07-15 16:05:00'),
(4, 4, 1430.00, '2026-07-22 10:30:00'),
(5, 5, 720.00, '2026-08-01 14:20:00'),
(6, 6, 720.00, '2026-08-04 08:50:00'),
(7, 9, 90.00, '2026-08-27 16:28:55'),
(8, 10, 12.00, '2026-08-27 16:30:37'),
(9, 7, 1450.00, '2026-08-27 16:39:50'),
(10, 11, 18.00, '2026-08-27 16:44:27'),
(11, 12, 2118.00, '2026-08-27 17:45:18'),
(12, 13, 1736.00, '2026-08-28 00:00:17'),
(14, 16, 2697.50, '2026-09-05 10:54:55'),
(15, 15, 76.00, '2026-09-05 10:56:07'),
(16, 18, 1750.00, '2026-09-05 23:19:36'),
(20, 25, 4165.00, '2026-09-07 22:23:09'),
(21, 22, 70.00, '2026-09-12 08:39:43'),
(22, 30, 246.00, '2026-09-13 20:51:16'),
(23, 32, 50.00, '2026-09-22 14:18:05');

-- --------------------------------------------------------

--
-- Table structure for table `MEDICINE`
--

CREATE TABLE `MEDICINE` (
  `medicine_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `quantity_in_stock` int(11) NOT NULL DEFAULT 0,
  `expiry_date` date DEFAULT NULL,
  `reorder_threshold` int(11) NOT NULL DEFAULT 10,
  `med_type` enum('PRESCRIPTION','OTC') DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ;

--
-- Dumping data for table `MEDICINE`
--

INSERT INTO `MEDICINE` (`medicine_id`, `name`, `category`, `price`, `quantity_in_stock`, `expiry_date`, `reorder_threshold`, `med_type`, `is_active`) VALUES
(1, 'Paracetamol 500mg', 'Analgesic', 5.50, 4617, '2027-11-30', 100, 'OTC', 1),
(2, 'Amoxicillin 250mg', 'Antibiotic', 18.00, 30, '2026-09-10', 50, 'PRESCRIPTION', 0),
(3, 'Cetirizine 10mg', 'Antihistamine', 7.50, 1945, '2026-11-15', 80, 'OTC', 1),
(4, 'Metformin 500mg', 'Antidiabetic', 12.00, 809, '2028-01-31', 60, 'PRESCRIPTION', 1),
(5, 'Omeprazole 20mg', 'Antacid', 15.00, 579, '2027-08-31', 50, 'PRESCRIPTION', 1),
(6, 'Vitamin C 500mg', 'Supplement', 6.00, 1000, '2028-03-31', 100, 'OTC', 1),
(7, 'Ibuprofen 400mg', 'Analgesic', 9.00, 1175, '2027-10-31', 80, 'OTC', 1),
(8, 'Salbutamol Inhaler', 'Respiratory', 850.00, 58, '2027-02-28', 50, 'PRESCRIPTION', 1),
(9, 'ORS Sachet', 'Rehydration', 25.00, 1920, '2026-10-05', 120, 'OTC', 1),
(10, 'Losartan 50mg', 'Antihypertensive', 14.00, 654, '2027-12-31', 60, 'PRESCRIPTION', 1),
(11, 'Surgical Face Mask', 'Consumable', 20.00, 2970, NULL, 200, NULL, 1),
(12, 'Digital Thermometer', 'Device', 1250.00, 52, NULL, 30, NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `ORDER_ITEM`
--

CREATE TABLE `ORDER_ITEM` (
  `order_id` int(11) NOT NULL,
  `medicine_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL
) ;

--
-- Dumping data for table `ORDER_ITEM`
--

INSERT INTO `ORDER_ITEM` (`order_id`, `medicine_id`, `quantity`, `unit_price`) VALUES
(1, 1, 20, 5.00),
(1, 2, 5, 18.00),
(1, 3, 10, 7.50),
(2, 1, 100, 5.00),
(2, 2, 14, 18.00),
(2, 6, 30, 6.00),
(3, 1, 100, 5.00),
(3, 8, 1, 850.00),
(4, 1, 50, 5.00),
(4, 7, 20, 9.00),
(4, 9, 40, 25.00),
(5, 5, 20, 15.00),
(5, 10, 30, 14.00),
(6, 4, 60, 12.00),
(7, 11, 10, 20.00),
(7, 12, 1, 1250.00),
(8, 2, 10, 18.00),
(9, 2, 5, 18.00),
(10, 6, 2, 6.00),
(11, 6, 3, 6.00),
(12, 2, 1, 18.00),
(12, 8, 1, 850.00),
(12, 12, 1, 1250.00),
(13, 2, 2, 18.00),
(13, 8, 2, 850.00),
(15, 1, 8, 5.00),
(15, 7, 4, 9.00),
(16, 1, 35, 5.00),
(16, 2, 5, 18.00),
(16, 3, 15, 7.50),
(16, 4, 20, 12.00),
(16, 6, 40, 6.00),
(16, 9, 10, 25.00),
(16, 10, 10, 14.00),
(16, 11, 10, 20.00),
(16, 12, 1, 1250.00),
(17, 11, 100, 20.00),
(18, 1, 20, 5.00),
(18, 2, 10, 18.00),
(18, 6, 20, 6.00),
(18, 11, 5, 20.00),
(18, 12, 1, 1250.00),
(22, 10, 5, 14.00),
(25, 1, 50, 5.00),
(25, 3, 30, 7.50),
(25, 4, 10, 12.00),
(25, 6, 20, 6.00),
(25, 9, 30, 25.00),
(25, 11, 10, 20.00),
(25, 12, 2, 1250.00),
(26, 2, 20, 18.00),
(26, 4, 10, 12.00),
(26, 8, 30, 850.00),
(26, 10, 10, 14.00),
(26, 11, 20, 20.00),
(27, 11, 1000, 20.00),
(28, 6, 20, 6.00),
(28, 7, 10, 9.00),
(29, 6, 24, 6.00),
(29, 7, 10, 9.00),
(30, 6, 23, 6.00),
(30, 7, 12, 9.00),
(31, 6, 17, 6.00),
(31, 7, 18, 9.00),
(32, 4, 1, 12.00),
(32, 5, 1, 15.00),
(32, 7, 1, 9.00),
(32, 10, 1, 14.00);

-- --------------------------------------------------------

--
-- Table structure for table `OTC_MEDICINE`
--

CREATE TABLE `OTC_MEDICINE` (
  `medicine_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `OTC_MEDICINE`
--

INSERT INTO `OTC_MEDICINE` (`medicine_id`) VALUES
(1),
(3),
(6),
(7),
(9);

-- --------------------------------------------------------

--
-- Table structure for table `PAYMENT`
--

CREATE TABLE `PAYMENT` (
  `payment_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `payer_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_date` datetime NOT NULL DEFAULT current_timestamp(),
  `method` enum('CASH','CARD','ONLINE') NOT NULL DEFAULT 'CASH',
  `status` enum('PENDING','PAID','FAILED','REFUNDED') NOT NULL DEFAULT 'PENDING'
) ;

--
-- Dumping data for table `PAYMENT`
--

INSERT INTO `PAYMENT` (`payment_id`, `invoice_id`, `payer_id`, `amount`, `payment_date`, `method`, `status`) VALUES
(1, 1, 4, 175.00, '2026-07-02 09:20:00', 'CASH', 'PAID'),
(2, 2, 5, 432.00, '2026-07-08 11:45:00', 'CARD', 'PAID'),
(3, 3, 6, 850.00, '2026-07-15 16:10:00', 'ONLINE', 'PAID'),
(4, 4, 4, 500.00, '2026-07-22 10:35:00', 'CASH', 'PAID'),
(5, 4, 4, 930.00, '2026-07-22 10:36:00', 'CARD', 'PAID'),
(6, 5, 7, 720.00, '2026-08-01 14:25:00', 'ONLINE', 'PAID'),
(7, 6, 8, 720.00, '2026-08-04 08:55:00', 'CASH', 'PENDING'),
(8, 7, 6, 40.00, '2026-08-27 16:29:07', 'CASH', 'PAID'),
(9, 7, 6, 50.00, '2026-08-27 16:29:19', 'CARD', 'PAID'),
(10, 6, 7, 720.00, '2026-08-27 16:29:41', 'ONLINE', 'PAID'),
(11, 8, 6, 12.00, '2026-08-27 16:39:43', 'CASH', 'PAID'),
(12, 9, 5, 1450.00, '2026-08-27 16:40:00', 'CASH', 'PAID'),
(13, 10, 6, 8.00, '2026-08-27 16:44:41', 'CASH', 'PAID'),
(14, 10, 6, 10.00, '2026-08-27 16:44:41', 'CARD', 'PAID'),
(15, 11, 6, 2118.00, '2026-08-27 17:50:29', 'CASH', 'PAID'),
(17, 14, 4, 2697.50, '2026-09-05 11:15:05', 'CASH', 'PAID'),
(20, 20, 4, 2000.00, '2026-09-07 22:24:55', 'CASH', 'PAID'),
(21, 20, 4, 2165.00, '2026-09-07 22:25:24', 'CARD', 'PAID'),
(22, 16, 4, 750.00, '2026-09-07 22:34:41', 'ONLINE', 'PAID'),
(23, 21, 8, 70.00, '2026-09-12 08:40:01', 'CASH', 'PAID'),
(24, 16, 4, 1000.00, '2026-09-12 08:41:20', 'CASH', 'PAID'),
(25, 15, 6, 76.00, '2026-09-16 21:21:34', 'CARD', 'PAID'),
(26, 23, 4, 50.00, '2026-09-22 14:19:07', 'CASH', 'PAID');

--
-- Triggers `PAYMENT`
--
DELIMITER $$
CREATE TRIGGER `trg_payment_no_overpay` BEFORE INSERT ON `PAYMENT` FOR EACH ROW BEGIN
    IF NEW.status = 'PAID' AND NEW.amount > fn_invoice_balance(NEW.invoice_id) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Payment amount exceeds the invoice balance';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `PHARMACIST`
--

CREATE TABLE `PHARMACIST` (
  `user_id` int(11) NOT NULL,
  `license_no` varchar(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `PHARMACIST`
--

INSERT INTO `PHARMACIST` (`user_id`, `license_no`) VALUES
(3, 'PH-2001-0187'),
(2, 'PH-2002-0456'),
(18, 'PH-2003-1208');

-- --------------------------------------------------------

--
-- Table structure for table `PRESCRIPTION_MEDICINE`
--

CREATE TABLE `PRESCRIPTION_MEDICINE` (
  `medicine_id` int(11) NOT NULL,
  `prescription_required_level` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `PRESCRIPTION_MEDICINE`
--

INSERT INTO `PRESCRIPTION_MEDICINE` (`medicine_id`, `prescription_required_level`) VALUES
(2, 'HIGH'),
(4, 'MEDIUM'),
(5, 'MEDIUM'),
(8, 'HIGH'),
(10, 'MEDIUM');

-- --------------------------------------------------------

--
-- Table structure for table `RESTOCK_ITEM`
--

CREATE TABLE `RESTOCK_ITEM` (
  `restock_id` int(11) NOT NULL,
  `medicine_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_cost` decimal(10,2) NOT NULL
) ;

--
-- Dumping data for table `RESTOCK_ITEM`
--

INSERT INTO `RESTOCK_ITEM` (`restock_id`, `medicine_id`, `quantity`, `unit_cost`) VALUES
(1, 1, 5000, 2.50),
(1, 3, 2000, 3.80),
(1, 6, 1500, 3.00),
(2, 2, 60, 11.00),
(2, 5, 600, 9.50),
(2, 8, 40, 620.00),
(2, 10, 700, 8.50),
(3, 4, 900, 7.50),
(3, 7, 1200, 5.20),
(3, 9, 2000, 16.00),
(3, 11, 3000, 12.00),
(3, 12, 25, 880.00),
(4, 1, 3000, 2.50),
(4, 2, 500, 11.00),
(5, 12, 5, 880.00),
(7, 12, 25, 880.00),
(9, 2, 10, 11.00),
(9, 8, 20, 620.00),
(9, 12, 13, 880.00),
(12, 11, 5, 12.00),
(13, 12, 15, 880.00),
(14, 6, 1000, 3.00);

-- --------------------------------------------------------

--
-- Table structure for table `RESTOCK_ORDER`
--

CREATE TABLE `RESTOCK_ORDER` (
  `restock_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `restock_date` date NOT NULL,
  `status` enum('PLACED','RECEIVED','CANCELLED') NOT NULL DEFAULT 'PLACED'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `RESTOCK_ORDER`
--

INSERT INTO `RESTOCK_ORDER` (`restock_id`, `admin_id`, `supplier_id`, `restock_date`, `status`) VALUES
(1, 1, 1, '2026-05-10', 'RECEIVED'),
(2, 1, 2, '2026-06-18', 'RECEIVED'),
(3, 1, 3, '2026-07-25', 'RECEIVED'),
(4, 1, 1, '2026-08-05', 'PLACED'),
(5, 1, 3, '2026-09-05', 'RECEIVED'),
(7, 1, 3, '2026-09-05', 'CANCELLED'),
(9, 1, 3, '2026-09-05', 'RECEIVED'),
(12, 1, 3, '2026-09-16', 'RECEIVED'),
(13, 1, 3, '2026-09-16', 'RECEIVED'),
(14, 1, 2, '2026-09-22', 'RECEIVED');

-- --------------------------------------------------------

--
-- Table structure for table `SUPPLIER`
--

CREATE TABLE `SUPPLIER` (
  `supplier_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `contact_no` varchar(15) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `SUPPLIER`
--

INSERT INTO `SUPPLIER` (`supplier_id`, `name`, `contact_no`, `is_active`) VALUES
(1, 'Lanka Pharma Distributors', '0112345678', 1),
(2, 'Ceylon Medical Supplies', '0114567890', 1),
(3, 'Northern Drug Agencies', '0212228899', 1);

-- --------------------------------------------------------

--
-- Table structure for table `SYSTEM_USER`
--

CREATE TABLE `SYSTEM_USER` (
  `user_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `SYSTEM_USER`
--

INSERT INTO `SYSTEM_USER` (`user_id`, `name`, `email`, `password`, `is_active`) VALUES
(1, 'admin', 'admin@pharmacy.lk', '$2y$10$T7fK0JYxGVVhP1utv1uM.unUgpmIj1SY5rVc8iSoEu2kN8U95Bap2', 1),
(2, 'Robinson J', 'pharma1@pharmacy.lk', '$2y$10$T7fK0JYxGVVhP1utv1uM.unUgpmIj1SY5rVc8iSoEu2kN8U95Bap2', 1),
(3, 'Yasikumar M', 'pharma2@pharmacy.lk', '$2y$10$T7fK0JYxGVVhP1utv1uM.unUgpmIj1SY5rVc8iSoEu2kN8U95Bap2', 1),
(4, 'Aathithyayan S', 'aathi@mail.com', '$2y$10$T7fK0JYxGVVhP1utv1uM.unUgpmIj1SY5rVc8iSoEu2kN8U95Bap2', 1),
(5, 'Shanjaie V', 'shanjaie@mail.com', '$2y$10$T7fK0JYxGVVhP1utv1uM.unUgpmIj1SY5rVc8iSoEu2kN8U95Bap2', 1),
(6, 'Kajanthan S', 'kajee@mail.com', '$2y$10$T7fK0JYxGVVhP1utv1uM.unUgpmIj1SY5rVc8iSoEu2kN8U95Bap2', 1),
(7, 'Alaxan S', 'alaxan@mail.com', '$2y$10$T7fK0JYxGVVhP1utv1uM.unUgpmIj1SY5rVc8iSoEu2kN8U95Bap2', 1),
(8, 'Divigsharan V', 'sharan@mail.com', '$2y$10$T7fK0JYxGVVhP1utv1uM.unUgpmIj1SY5rVc8iSoEu2kN8U95Bap2', 1),
(14, 'Test Customer Edited', 'testcust2@example.com', '$2y$10$u99aMvUTj66Z.ve/gZBpbOVtHpTruXfz3dV2ql4XEo0vizrFB4rWy', 1),
(16, 'Test Admin B', 'testadminb@example.com', '$2y$10$T4Ys8qhYJqfhOiM2AIcdN.F9qOswYLuNmuKC9wCDmlICXmxs7JG.O', 0),
(17, 'Test Admin C', 'testadminc@example.com', '$2y$10$/6aK6Sv3QGXYjhS4CilT8urqs3Ngu5Vs.mKPmUOO1Cd7LW0Iyj2dy', 0),
(18, 'Test Pharmacist', 'pharma0@pharmacy.lk', '$2y$10$SsLHcxHUm1pNRCFIyb9X2OtRRT61S4QFNm1zpwRlvlheOH58aovkK', 0);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_medicine_type`
-- (See below for the actual view)
--
CREATE TABLE `v_medicine_type` (
`medicine_id` int(11)
,`name` varchar(100)
,`stored_med_type` enum('PRESCRIPTION','OTC')
,`derived_med_type` varchar(12)
);

-- --------------------------------------------------------

--
-- Structure for view `v_medicine_type`
--
DROP TABLE IF EXISTS `v_medicine_type`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_medicine_type`  AS SELECT `m`.`medicine_id` AS `medicine_id`, `m`.`name` AS `name`, `m`.`med_type` AS `stored_med_type`, CASE WHEN `pm`.`medicine_id` is not null THEN 'PRESCRIPTION' WHEN `om`.`medicine_id` is not null THEN 'OTC' ELSE NULL END AS `derived_med_type` FROM ((`medicine` `m` left join `prescription_medicine` `pm` on(`pm`.`medicine_id` = `m`.`medicine_id`)) left join `otc_medicine` `om` on(`om`.`medicine_id` = `m`.`medicine_id`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ADMIN`
--
ALTER TABLE `ADMIN`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `CUSTOMER`
--
ALTER TABLE `CUSTOMER`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `CUSTOMER_ORDER`
--
ALTER TABLE `CUSTOMER_ORDER`
  ADD PRIMARY KEY (`order_id`),
  ADD KEY `idx_order_customer` (`customer_id`),
  ADD KEY `idx_order_status` (`status`),
  ADD KEY `fk_order_pharmacist` (`pharmacist_id`);

--
-- Indexes for table `INVOICE`
--
ALTER TABLE `INVOICE`
  ADD PRIMARY KEY (`invoice_id`),
  ADD UNIQUE KEY `uq_invoice_order` (`order_id`);

--
-- Indexes for table `MEDICINE`
--
ALTER TABLE `MEDICINE`
  ADD PRIMARY KEY (`medicine_id`),
  ADD KEY `idx_medicine_name` (`name`),
  ADD KEY `idx_medicine_active` (`is_active`);

--
-- Indexes for table `ORDER_ITEM`
--
ALTER TABLE `ORDER_ITEM`
  ADD PRIMARY KEY (`order_id`,`medicine_id`),
  ADD KEY `idx_orderitem_medicine` (`medicine_id`);

--
-- Indexes for table `OTC_MEDICINE`
--
ALTER TABLE `OTC_MEDICINE`
  ADD PRIMARY KEY (`medicine_id`);

--
-- Indexes for table `PAYMENT`
--
ALTER TABLE `PAYMENT`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `idx_payment_invoice` (`invoice_id`),
  ADD KEY `idx_payment_payer` (`payer_id`);

--
-- Indexes for table `PHARMACIST`
--
ALTER TABLE `PHARMACIST`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_pharmacist_license` (`license_no`);

--
-- Indexes for table `PRESCRIPTION_MEDICINE`
--
ALTER TABLE `PRESCRIPTION_MEDICINE`
  ADD PRIMARY KEY (`medicine_id`);

--
-- Indexes for table `RESTOCK_ITEM`
--
ALTER TABLE `RESTOCK_ITEM`
  ADD PRIMARY KEY (`restock_id`,`medicine_id`),
  ADD KEY `idx_restockitem_medicine` (`medicine_id`);

--
-- Indexes for table `RESTOCK_ORDER`
--
ALTER TABLE `RESTOCK_ORDER`
  ADD PRIMARY KEY (`restock_id`),
  ADD KEY `idx_restock_supplier` (`supplier_id`),
  ADD KEY `fk_restock_admin` (`admin_id`);

--
-- Indexes for table `SUPPLIER`
--
ALTER TABLE `SUPPLIER`
  ADD PRIMARY KEY (`supplier_id`),
  ADD KEY `idx_supplier_active` (`is_active`);

--
-- Indexes for table `SYSTEM_USER`
--
ALTER TABLE `SYSTEM_USER`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_user_email` (`email`),
  ADD KEY `idx_user_active` (`is_active`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `CUSTOMER_ORDER`
--
ALTER TABLE `CUSTOMER_ORDER`
  MODIFY `order_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `INVOICE`
--
ALTER TABLE `INVOICE`
  MODIFY `invoice_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `MEDICINE`
--
ALTER TABLE `MEDICINE`
  MODIFY `medicine_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `PAYMENT`
--
ALTER TABLE `PAYMENT`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `RESTOCK_ORDER`
--
ALTER TABLE `RESTOCK_ORDER`
  MODIFY `restock_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `SUPPLIER`
--
ALTER TABLE `SUPPLIER`
  MODIFY `supplier_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `SYSTEM_USER`
--
ALTER TABLE `SYSTEM_USER`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `ADMIN`
--
ALTER TABLE `ADMIN`
  ADD CONSTRAINT `fk_admin_user` FOREIGN KEY (`user_id`) REFERENCES `SYSTEM_USER` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `CUSTOMER`
--
ALTER TABLE `CUSTOMER`
  ADD CONSTRAINT `fk_customer_user` FOREIGN KEY (`user_id`) REFERENCES `SYSTEM_USER` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `CUSTOMER_ORDER`
--
ALTER TABLE `CUSTOMER_ORDER`
  ADD CONSTRAINT `fk_order_customer` FOREIGN KEY (`customer_id`) REFERENCES `CUSTOMER` (`user_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_order_pharmacist` FOREIGN KEY (`pharmacist_id`) REFERENCES `PHARMACIST` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `INVOICE`
--
ALTER TABLE `INVOICE`
  ADD CONSTRAINT `fk_invoice_order` FOREIGN KEY (`order_id`) REFERENCES `CUSTOMER_ORDER` (`order_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `ORDER_ITEM`
--
ALTER TABLE `ORDER_ITEM`
  ADD CONSTRAINT `fk_orderitem_medicine` FOREIGN KEY (`medicine_id`) REFERENCES `MEDICINE` (`medicine_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_orderitem_order` FOREIGN KEY (`order_id`) REFERENCES `CUSTOMER_ORDER` (`order_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `OTC_MEDICINE`
--
ALTER TABLE `OTC_MEDICINE`
  ADD CONSTRAINT `fk_otc_medicine` FOREIGN KEY (`medicine_id`) REFERENCES `MEDICINE` (`medicine_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `PAYMENT`
--
ALTER TABLE `PAYMENT`
  ADD CONSTRAINT `fk_payment_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `INVOICE` (`invoice_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_payment_payer` FOREIGN KEY (`payer_id`) REFERENCES `CUSTOMER` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `PHARMACIST`
--
ALTER TABLE `PHARMACIST`
  ADD CONSTRAINT `fk_pharmacist_user` FOREIGN KEY (`user_id`) REFERENCES `SYSTEM_USER` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `PRESCRIPTION_MEDICINE`
--
ALTER TABLE `PRESCRIPTION_MEDICINE`
  ADD CONSTRAINT `fk_presc_medicine` FOREIGN KEY (`medicine_id`) REFERENCES `MEDICINE` (`medicine_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `RESTOCK_ITEM`
--
ALTER TABLE `RESTOCK_ITEM`
  ADD CONSTRAINT `fk_restockitem_medicine` FOREIGN KEY (`medicine_id`) REFERENCES `MEDICINE` (`medicine_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_restockitem_restock` FOREIGN KEY (`restock_id`) REFERENCES `RESTOCK_ORDER` (`restock_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `RESTOCK_ORDER`
--
ALTER TABLE `RESTOCK_ORDER`
  ADD CONSTRAINT `fk_restock_admin` FOREIGN KEY (`admin_id`) REFERENCES `ADMIN` (`user_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_restock_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `SUPPLIER` (`supplier_id`) ON UPDATE CASCADE;

DELIMITER $$
--
-- Events
--
CREATE DEFINER=`root`@`localhost` EVENT `ev_deactivate_expired_medicines` ON SCHEDULE EVERY 1 DAY STARTS '2026-09-13 01:00:00' ON COMPLETION NOT PRESERVE ENABLE DO BEGIN
    UPDATE MEDICINE
       SET is_active = 0
     WHERE expiry_date IS NOT NULL
       AND expiry_date < CURDATE()
       AND is_active = 1;
END$$

DELIMITER ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
