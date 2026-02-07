-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Feb 07, 2026 at 05:40 AM
-- Server version: 10.11.14-MariaDB-ubu2204
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `fina_finance`
--

-- --------------------------------------------------------

--
-- Table structure for table `budget_allocations`
--

CREATE TABLE `budget_allocations` (
  `id` int(11) NOT NULL,
  `budget_proposal_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `fiscal_year` varchar(10) DEFAULT NULL,
  `allocated_amount` decimal(15,2) DEFAULT NULL,
  `spent_amount` decimal(15,2) DEFAULT 0.00,
  `remaining_amount` decimal(15,2) DEFAULT NULL,
  `status` enum('Active','Closed','Overbudget') DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `budget_proposals`
--

CREATE TABLE `budget_proposals` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `department` int(11) NOT NULL,
  `fiscal_year` varchar(20) NOT NULL,
  `submitted_by` int(11) NOT NULL,
  `status` varchar(50) DEFAULT 'Pending',
  `total_amount` decimal(15,2) DEFAULT 0.00,
  `submitted_date` datetime DEFAULT NULL,
  `approved_date` datetime DEFAULT NULL,
  `rejected_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `department_contact_id` int(11) DEFAULT NULL,
  `ar_contact_id` int(11) DEFAULT NULL,
  `remaining_amount` decimal(15,2) DEFAULT 0.00,
  `spent_amount` decimal(15,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `budget_proposals`
--

INSERT INTO `budget_proposals` (`id`, `title`, `department`, `fiscal_year`, `submitted_by`, `status`, `total_amount`, `submitted_date`, `approved_date`, `rejected_date`, `created_at`, `updated_at`, `department_contact_id`, `ar_contact_id`, `remaining_amount`, `spent_amount`) VALUES
(166, 'HR', 1, '2026', 10, 'Approved', 1500000.00, NULL, '2026-01-30 21:46:01', NULL, '2026-01-30 13:45:56', '2026-02-05 13:27:11', NULL, NULL, 1379082.97, 120917.03),
(167, 'Log1', 9, '2026', 10, 'Approved', 1700000.00, NULL, '2026-01-30 21:46:26', NULL, '2026-01-30 13:46:22', '2026-01-30 13:46:26', NULL, NULL, 1700000.00, 0.00),
(168, 'Log2', 10, '2026', 10, 'Approved', 2000000.00, NULL, '2026-01-30 21:46:44', NULL, '2026-01-30 13:46:41', '2026-02-01 02:26:34', NULL, NULL, 2000000.00, 0.00),
(169, 'Core', 2, '2026', 10, 'Approved', 1000000.00, NULL, '2026-02-03 19:02:22', NULL, '2026-02-03 11:02:19', '2026-02-03 11:02:22', NULL, NULL, 1000000.00, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `budget_spending`
--

CREATE TABLE `budget_spending` (
  `id` int(11) NOT NULL,
  `budget_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `spending_date` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `business_contacts`
--

CREATE TABLE `business_contacts` (
  `id` int(11) NOT NULL,
  `contact_id` varchar(20) NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `type` enum('Vendor','Customer') NOT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_budget_contact` tinyint(4) DEFAULT 0,
  `budget_category` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chart_of_accounts`
--

CREATE TABLE `chart_of_accounts` (
  `id` int(11) NOT NULL,
  `account_code` varchar(20) NOT NULL,
  `account_name` varchar(100) NOT NULL,
  `account_type` enum('Asset','Liability','Equity','Revenue','Expense') NOT NULL,
  `balance` decimal(15,2) DEFAULT 0.00,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `account_subtype` varchar(50) DEFAULT NULL,
  `statement_type` varchar(20) NOT NULL DEFAULT 'Balance Sheet'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chart_of_accounts`
--

INSERT INTO `chart_of_accounts` (`id`, `account_code`, `account_name`, `account_type`, `balance`, `status`, `created_at`, `account_subtype`, `statement_type`) VALUES
(34, 'CASH-001', 'Cash on Hand', 'Asset', -897125.00, 'Active', '2026-01-24 07:44:01', NULL, 'Balance Sheet'),
(35, 'AST-002', 'Loans Receivable', 'Asset', 456000.00, 'Active', '2026-01-24 07:44:01', NULL, 'Balance Sheet'),
(36, 'AST-003', 'Office Equipment', 'Asset', 0.00, 'Active', '2026-01-24 07:44:01', NULL, 'Balance Sheet'),
(37, 'LIA-001', 'Accounts Payable', 'Liability', 0.00, 'Active', '2026-01-24 07:44:01', NULL, 'Balance Sheet'),
(38, 'LIA-002', 'Notes Payable', 'Liability', 0.00, 'Active', '2026-01-24 07:44:01', NULL, 'Balance Sheet'),
(39, 'CAP-001', 'Initial Capital', 'Equity', 100000.00, 'Active', '2026-01-24 07:44:01', NULL, 'Balance Sheet'),
(40, 'EQ-002', 'Retained Earnings', 'Equity', 0.00, 'Active', '2026-01-24 07:44:01', NULL, 'Balance Sheet'),
(41, 'REV-001', 'Interest Income', 'Revenue', 0.00, 'Active', '2026-01-24 07:44:01', NULL, 'Balance Sheet'),
(42, 'REV-002', 'Penalty Income', 'Revenue', 0.00, 'Active', '2026-01-24 07:44:01', NULL, 'Balance Sheet'),
(43, 'REV-003', 'Service Fee Income', 'Revenue', 0.00, 'Active', '2026-01-24 07:44:01', NULL, 'Balance Sheet'),
(44, 'EXP-001', 'General Expenses', 'Expense', 150000.00, 'Active', '2026-01-24 07:44:01', NULL, 'Balance Sheet'),
(45, 'EXP-002', 'Salaries and Wages', 'Expense', 391125.00, 'Active', '2026-01-24 07:44:01', NULL, 'Balance Sheet'),
(46, 'EXP-003', 'Rent Expense', 'Expense', 0.00, 'Active', '2026-01-24 07:44:01', NULL, 'Balance Sheet'),
(47, 'EXP-004', 'Utilities Expense', 'Expense', 0.00, 'Active', '2026-01-24 07:44:01', NULL, 'Balance Sheet'),
(48, 'EXP-005', 'Bad Debts Expense', 'Expense', 0.00, 'Active', '2026-01-24 07:44:01', NULL, 'Balance Sheet');

-- --------------------------------------------------------

--
-- Table structure for table `contacts`
--

CREATE TABLE `contacts` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` enum('Customer','Vendor') NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`, `description`, `status`, `created_at`) VALUES
(1, 'HR Payroll', 'Manages employee salaries, wages, benefits, and payroll processing.', 'active', '2025-10-21 05:25:08'),
(2, 'Core Budget', 'Oversees general operational expenses, utilities, rent, and office supplies.', 'active', '2025-10-21 05:25:08'),
(9, 'Logistics 1', 'Logistics Department 1', 'active', '2026-01-26 12:40:52'),
(10, 'Logistics 2', 'Logistics Department 2', 'active', '2026-01-26 12:40:52');

-- --------------------------------------------------------

--
-- Table structure for table `disbursement_requests`
--

CREATE TABLE `disbursement_requests` (
  `id` int(11) NOT NULL,
  `request_id` varchar(50) DEFAULT NULL,
  `external_reference` varchar(100) DEFAULT NULL,
  `requested_by` varchar(100) NOT NULL,
  `requested_by_name` varchar(255) DEFAULT NULL,
  `department` varchar(50) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `request_details` longtext DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected','Completed') DEFAULT 'Pending',
  `date_requested` date NOT NULL,
  `date_approved` date DEFAULT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `budget_source` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `budget_proposal_id` int(11) DEFAULT NULL,
  `transaction_type` varchar(20) DEFAULT 'Make',
  `invoice_id` int(11) DEFAULT NULL,
  `contact_id` int(11) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `budget_id` int(11) DEFAULT NULL,
  `budget_title` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fiscal_years`
--

CREATE TABLE `fiscal_years` (
  `id` int(11) NOT NULL,
  `fiscal_year` varchar(20) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fiscal_years`
--

INSERT INTO `fiscal_years` (`id`, `fiscal_year`, `start_date`, `end_date`, `status`, `created_at`) VALUES
(5, '2026', '2026-01-01', '2026-12-31', 'active', '2026-01-23 07:16:00'),
(6, '2027', '2027-01-01', '2027-12-31', 'active', '2026-01-23 07:16:00'),
(7, '2028', '2028-01-01', '2028-12-31', 'active', '2026-01-23 07:16:00');

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL,
  `invoice_number` varchar(20) NOT NULL,
  `contact_id` varchar(20) DEFAULT NULL,
  `type` enum('Receivable','Payable') NOT NULL,
  `issue_date` date NOT NULL,
  `due_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('Pending','Paid','Overdue','Cancelled') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `paid_amount` decimal(15,2) DEFAULT 0.00,
  `is_budget_allocation` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` int(11) NOT NULL,
  `entry_id` varchar(20) NOT NULL,
  `entry_date` date NOT NULL,
  `description` text NOT NULL,
  `status` enum('Draft','Posted') DEFAULT 'Draft',
  `created_by` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_entry_lines`
--

CREATE TABLE `journal_entry_lines` (
  `id` int(11) NOT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `debit` decimal(15,2) DEFAULT 0.00,
  `credit` decimal(15,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `journal_entry_lines`
--
DELIMITER $$
CREATE TRIGGER `update_account_balance_after_journal_entry` AFTER INSERT ON `journal_entry_lines` FOR EACH ROW BEGIN
    DECLARE acct_type VARCHAR(50);

    -- 1. Alamin kung anong Account Type (Asset, Expense, etc.)
    SELECT account_type INTO acct_type FROM chart_of_accounts WHERE id = NEW.account_id;

    -- 2. Apply Accounting Equation
    -- Assets & Expenses: Debit increases (+), Credit decreases (-)
    IF acct_type IN ('Asset', 'Expense') THEN
        UPDATE chart_of_accounts 
        SET balance = balance + NEW.debit - NEW.credit
        WHERE id = NEW.account_id;
    
    -- Liabilities, Equity, & Revenue: Credit increases (+), Debit decreases (-)
    ELSE
        UPDATE chart_of_accounts 
        SET balance = balance + NEW.credit - NEW.debit
        WHERE id = NEW.account_id;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `type` enum('info','warning','success','error') DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `title` varchar(255) NOT NULL DEFAULT 'Notification'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `message`, `type`, `is_read`, `created_at`, `title`) VALUES
(140, 10, 'Your budget proposal \'HR\' has been approved!', 'success', 1, '2026-01-30 13:46:01', 'Notification'),
(141, 10, 'Your budget proposal \'Log1\' has been approved!', 'success', 1, '2026-01-30 13:46:26', 'Notification'),
(142, 10, 'Your budget proposal \'Log2\' has been approved!', 'success', 1, '2026-01-30 13:46:44', 'Notification'),
(143, 10, 'Your budget proposal \'Core\' has been approved!', 'success', 1, '2026-02-03 11:02:22', 'Notification');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `payment_id` varchar(20) NOT NULL,
  `contact_id` int(11) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_method` enum('Cash','Check','Bank Transfer','Credit Card') NOT NULL,
  `type` enum('Receive','Make') NOT NULL,
  `status` enum('Completed','Processing','Scheduled','Cancelled') DEFAULT 'Processing',
  `reference_number` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_entries`
--

CREATE TABLE `payment_entries` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_method` enum('Cash','Check','Bank Transfer','Credit Card','Online Payment') NOT NULL,
  `reference_number` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `type` enum('collection','disbursement') DEFAULT 'collection',
  `status` enum('completed','processing','cancelled') DEFAULT 'completed',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pending_disbursements`
--

CREATE TABLE `pending_disbursements` (
  `id` int(11) NOT NULL,
  `pending_id` varchar(50) DEFAULT NULL,
  `contact_id` varchar(50) DEFAULT NULL,
  `invoice_id` varchar(50) DEFAULT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `requested_by_name` varchar(255) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `transaction_type` varchar(20) DEFAULT 'Make',
  `status` varchar(20) DEFAULT 'Pending',
  `budget_id` int(11) DEFAULT NULL,
  `budget_title` varchar(255) DEFAULT NULL,
  `date_requested` timestamp NULL DEFAULT current_timestamp(),
  `date_approved` timestamp NULL DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `receipts`
--

CREATE TABLE `receipts` (
  `id` int(11) NOT NULL,
  `receipt_number` varchar(50) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `contact_id` int(11) NOT NULL,
  `receipt_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `role` varchar(20) DEFAULT 'user',
  `email` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `name`, `role`, `email`, `created_at`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ralf', 'admin', 'ralf@company.com', '2025-10-05 10:38:58'),
(2, 'user1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'John Smith', 'user', 'john@company.com', '2025-10-05 10:38:58'),
(10, 'microfinancial25@gmail.com', '$2y$10$fWi5Z4YpLmLCLHjDs1iZKuKQ98NVan5Omit8G8IUN1XvALFfECHg2', 'Admin', 'administrator', 'microfinancial25@gmail.com', '2025-10-23 12:56:55');

-- --------------------------------------------------------

--
-- Table structure for table `user_notifications`
--

CREATE TABLE `user_notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `notification_type` varchar(50) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_otps`
--

CREATE TABLE `user_otps` (
  `id` int(11) NOT NULL,
  `username` varchar(255) NOT NULL,
  `otp` varchar(6) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `workflow_approvals`
--

CREATE TABLE `workflow_approvals` (
  `id` int(11) NOT NULL,
  `proposal_id` int(11) NOT NULL,
  `approver_id` int(11) NOT NULL,
  `action` enum('Approved','Rejected') NOT NULL,
  `comments` text DEFAULT NULL,
  `approved_at` datetime DEFAULT current_timestamp(),
  `step_completed` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `workflow_approvals`
--

INSERT INTO `workflow_approvals` (`id`, `proposal_id`, `approver_id`, `action`, `comments`, `approved_at`, `step_completed`) VALUES
(39, 166, 10, 'Approved', '', '2026-01-30 21:46:01', 1),
(40, 167, 10, 'Approved', '', '2026-01-30 21:46:26', 1),
(41, 168, 10, 'Approved', '', '2026-01-30 21:46:44', 1),
(42, 169, 10, 'Approved', '', '2026-02-03 19:02:22', 1);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `budget_allocations`
--
ALTER TABLE `budget_allocations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `budget_proposals`
--
ALTER TABLE `budget_proposals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `submitted_by` (`submitted_by`),
  ADD KEY `department` (`department`),
  ADD KEY `ar_contact_id` (`ar_contact_id`),
  ADD KEY `idx_budget_proposals_department` (`department`,`fiscal_year`,`status`),
  ADD KEY `idx_budget_proposals_remaining` (`remaining_amount`);

--
-- Indexes for table `budget_spending`
--
ALTER TABLE `budget_spending`
  ADD PRIMARY KEY (`id`),
  ADD KEY `budget_id` (`budget_id`);

--
-- Indexes for table `business_contacts`
--
ALTER TABLE `business_contacts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `contact_id` (`contact_id`);

--
-- Indexes for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `account_code` (`account_code`);

--
-- Indexes for table `contacts`
--
ALTER TABLE `contacts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `disbursement_requests`
--
ALTER TABLE `disbursement_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `request_id` (`request_id`),
  ADD KEY `idx_disbursement_requests_budget` (`budget_id`,`status`);

--
-- Indexes for table `fiscal_years`
--
ALTER TABLE `fiscal_years`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `contact_id` (`contact_id`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `entry_id` (`entry_id`);

--
-- Indexes for table `journal_entry_lines`
--
ALTER TABLE `journal_entry_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `journal_entry_id` (`journal_entry_id`),
  ADD KEY `account_id` (`account_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payment_id` (`payment_id`),
  ADD KEY `contact_id` (`contact_id`),
  ADD KEY `invoice_id` (`invoice_id`);

--
-- Indexes for table `payment_entries`
--
ALTER TABLE `payment_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `pending_disbursements`
--
ALTER TABLE `pending_disbursements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `pending_id` (`pending_id`),
  ADD KEY `requested_by` (`requested_by`),
  ADD KEY `approved_by` (`approved_by`),
  ADD KEY `budget_id` (`budget_id`);

--
-- Indexes for table `receipts`
--
ALTER TABLE `receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `receipt_number` (`receipt_number`),
  ADD KEY `payment_id` (`payment_id`),
  ADD KEY `contact_id` (`contact_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `user_notifications`
--
ALTER TABLE `user_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `user_otps`
--
ALTER TABLE `user_otps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `workflow_approvals`
--
ALTER TABLE `workflow_approvals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `proposal_id` (`proposal_id`),
  ADD KEY `approver_id` (`approver_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `budget_allocations`
--
ALTER TABLE `budget_allocations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `budget_proposals`
--
ALTER TABLE `budget_proposals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=186;

--
-- AUTO_INCREMENT for table `budget_spending`
--
ALTER TABLE `budget_spending`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `business_contacts`
--
ALTER TABLE `business_contacts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=131;

--
-- AUTO_INCREMENT for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `contacts`
--
ALTER TABLE `contacts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `disbursement_requests`
--
ALTER TABLE `disbursement_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=249;

--
-- AUTO_INCREMENT for table `fiscal_years`
--
ALTER TABLE `fiscal_years`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=153;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=78;

--
-- AUTO_INCREMENT for table `journal_entry_lines`
--
ALTER TABLE `journal_entry_lines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=105;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=144;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=116;

--
-- AUTO_INCREMENT for table `payment_entries`
--
ALTER TABLE `payment_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `pending_disbursements`
--
ALTER TABLE `pending_disbursements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `receipts`
--
ALTER TABLE `receipts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `user_notifications`
--
ALTER TABLE `user_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=150;

--
-- AUTO_INCREMENT for table `user_otps`
--
ALTER TABLE `user_otps`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=95;

--
-- AUTO_INCREMENT for table `workflow_approvals`
--
ALTER TABLE `workflow_approvals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `budget_proposals`
--
ALTER TABLE `budget_proposals`
  ADD CONSTRAINT `budget_proposals_ibfk_1` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `budget_proposals_ibfk_2` FOREIGN KEY (`department`) REFERENCES `departments` (`id`),
  ADD CONSTRAINT `budget_proposals_ibfk_3` FOREIGN KEY (`ar_contact_id`) REFERENCES `business_contacts` (`id`);

--
-- Constraints for table `budget_spending`
--
ALTER TABLE `budget_spending`
  ADD CONSTRAINT `budget_spending_ibfk_1` FOREIGN KEY (`budget_id`) REFERENCES `budget_proposals` (`id`);

--
-- Constraints for table `disbursement_requests`
--
ALTER TABLE `disbursement_requests`
  ADD CONSTRAINT `disbursement_requests_ibfk_1` FOREIGN KEY (`budget_id`) REFERENCES `budget_proposals` (`id`),
  ADD CONSTRAINT `disbursement_requests_ibfk_2` FOREIGN KEY (`budget_id`) REFERENCES `budget_proposals` (`id`),
  ADD CONSTRAINT `disbursement_requests_ibfk_3` FOREIGN KEY (`budget_id`) REFERENCES `budget_proposals` (`id`);

--
-- Constraints for table `journal_entry_lines`
--
ALTER TABLE `journal_entry_lines`
  ADD CONSTRAINT `journal_entry_lines_ibfk_1` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `journal_entry_lines_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `business_contacts` (`id`),
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`);

--
-- Constraints for table `payment_entries`
--
ALTER TABLE `payment_entries`
  ADD CONSTRAINT `payment_entries_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `business_contacts` (`id`),
  ADD CONSTRAINT `payment_entries_ibfk_2` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`),
  ADD CONSTRAINT `payment_entries_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `pending_disbursements`
--
ALTER TABLE `pending_disbursements`
  ADD CONSTRAINT `pending_disbursements_ibfk_1` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `pending_disbursements_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `pending_disbursements_ibfk_3` FOREIGN KEY (`budget_id`) REFERENCES `budget_proposals` (`id`);

--
-- Constraints for table `receipts`
--
ALTER TABLE `receipts`
  ADD CONSTRAINT `receipts_ibfk_1` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`),
  ADD CONSTRAINT `receipts_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `business_contacts` (`id`),
  ADD CONSTRAINT `receipts_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `user_notifications`
--
ALTER TABLE `user_notifications`
  ADD CONSTRAINT `user_notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `workflow_approvals`
--
ALTER TABLE `workflow_approvals`
  ADD CONSTRAINT `workflow_approvals_ibfk_1` FOREIGN KEY (`proposal_id`) REFERENCES `budget_proposals` (`id`),
  ADD CONSTRAINT `workflow_approvals_ibfk_2` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
