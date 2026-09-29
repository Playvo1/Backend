-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 29, 2026 at 08:08 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `playvo`
--

-- --------------------------------------------------------

--
-- Table structure for table `assistant_queries`
--

CREATE TABLE `assistant_queries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `query_text` text NOT NULL,
  `parsed_sport_id` bigint(20) UNSIGNED DEFAULT NULL,
  `parsed_date` date DEFAULT NULL,
  `parsed_hour` time DEFAULT NULL,
  `suggested_venue_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `admin_user_id` bigint(20) UNSIGNED NOT NULL,
  `action` varchar(255) NOT NULL,
  `target_type` varchar(255) NOT NULL,
  `target_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `time_slot_id` bigint(20) UNSIGNED NOT NULL,
  `captain_user_id` bigint(20) UNSIGNED NOT NULL,
  `captain_name` varchar(255) NOT NULL,
  `captain_role` varchar(50) NOT NULL,
  `captain_phone` varchar(255) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `result` varchar(255) DEFAULT NULL,
  `status` enum('pending_payment','confirmed','cancelled') NOT NULL DEFAULT 'pending_payment',
  `hold_expires_at` timestamp NULL DEFAULT NULL,
  `share_token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`id`, `time_slot_id`, `captain_user_id`, `captain_name`, `captain_role`, `captain_phone`, `total_price`, `result`, `status`, `hold_expires_at`, `share_token`, `created_at`, `updated_at`) VALUES
(17, 1, 15, 'Hatem Shbeir', 'Captain', '970597103855', 100.00, NULL, 'confirmed', '2026-09-24 19:02:34', '4HtK82V4YW7K3o2sLDVDoNhQvoA5rSjDoaIRE23a', '2026-09-24 18:56:42', '2026-09-24 18:57:34');

-- --------------------------------------------------------

--
-- Table structure for table `cache`
--

CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache_locks`
--

CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cities`
--

CREATE TABLE `cities` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `country_id` bigint(20) UNSIGNED NOT NULL,
  `name_ar` varchar(255) NOT NULL,
  `name_en` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cities`
--

INSERT INTO `cities` (`id`, `country_id`, `name_ar`, `name_en`, `created_at`, `updated_at`) VALUES
(1, 1, 'غزة', 'Gaza', '2026-09-20 11:29:36', '2026-09-20 11:29:36');

-- --------------------------------------------------------

--
-- Table structure for table `countries`
--

CREATE TABLE `countries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name_ar` varchar(255) NOT NULL,
  `name_en` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `countries`
--

INSERT INTO `countries` (`id`, `name_ar`, `name_en`, `created_at`, `updated_at`) VALUES
(1, 'فلسطين', 'Palestine', '2026-09-20 11:29:36', '2026-09-20 11:29:36');

-- --------------------------------------------------------

--
-- Table structure for table `device_tokens`
--

CREATE TABLE `device_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `fcm_token` varchar(255) NOT NULL,
  `platform` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `favorites`
--

CREATE TABLE `favorites` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `venue_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `images`
--

CREATE TABLE `images` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `imageable_type` varchar(255) NOT NULL,
  `imageable_id` bigint(20) UNSIGNED NOT NULL,
  `image_url` varchar(255) NOT NULL,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `images`
--

INSERT INTO `images` (`id`, `imageable_type`, `imageable_id`, `image_url`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 'App\\Models\\Venue', 1, '/storage/venues/ScfM82PPzt3UNE9yEKl26YCUsY0VE8bUR9cCFkec.png', 1, '2026-09-27 07:25:41', '2026-09-27 07:25:41');

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL,
  `reserved_at` int(10) UNSIGNED DEFAULT NULL,
  `available_at` int(10) UNSIGNED NOT NULL,
  `created_at` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_batches`
--

CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '0001_01_01_000000_create_users_table', 1),
(2, '0001_01_01_000001_create_cache_table', 1),
(3, '0001_01_01_000002_create_jobs_table', 1),
(4, '2026_09_14_134438_create_countries_table', 1),
(5, '2026_09_14_134439_create_cities_table', 1),
(6, '2026_09_14_134440_create_sports_table', 1),
(7, '2026_09_14_134441_create_verification_codes_table', 1),
(8, '2026_09_14_134444_create_venues_table', 1),
(9, '2026_09_14_134445_create_images_table', 1),
(10, '2026_09_14_134445_create_time_slots_table', 1),
(11, '2026_09_14_134445_create_venue_sports_table', 1),
(12, '2026_09_14_134446_create_bookings_table', 1),
(13, '2026_09_14_134446_create_device_tokens_table', 1),
(14, '2026_09_14_134447_create_payment_receipts_table', 1),
(15, '2026_09_14_134447_create_venue_ratings_table', 1),
(16, '2026_09_14_134448_create_favorites_table', 1),
(17, '2026_09_14_134448_create_notifications_table', 1),
(18, '2026_09_14_134449_create_sync_status_table', 1),
(19, '2026_09_14_134450_create_assistant_queries_table', 1),
(20, '2026_09_14_134450_create_audit_logs_table', 1),
(21, '2026_09_14_141545_create_permission_tables', 1),
(22, '2026_09_14_182911_create_personal_access_tokens_table', 1),
(23, '2026_09_15_063928_make_password_nullable_in_users_table', 2),
(24, '2026_09_15_090359_add_reset_token_to_verification_codes_table', 3),
(25, '2026_09_16_125331_add_verified_at_to_verification_codes_table', 4),
(26, '2026_09_16_131038_remove_reset_token_from_verification_codes_table', 5),
(52, '2026_09_17_143534_update_status_in_bookings_table', 6),
(53, '2026_09_17_144331_update_status_in_payment_receipts_table', 6),
(54, '2026_09_17_144653_update_status_in_venues_table', 6),
(55, '2026_09_17_145035_update_status_in_users_table', 6),
(56, '2026_09_17_145240_update_status_in_time_slots_table', 6),
(57, '2026_09_24_200454_add_hold_expires_at_to_bookings_table', 7),
(58, '2026_09_27_131557_add_deleted_at_to_venues_table', 8);

-- --------------------------------------------------------

--
-- Table structure for table `model_has_permissions`
--

CREATE TABLE `model_has_permissions` (
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `model_has_roles`
--

CREATE TABLE `model_has_roles` (
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `model_has_roles`
--

INSERT INTO `model_has_roles` (`role_id`, `model_type`, `model_id`) VALUES
(1, 'App\\Models\\User', 1),
(1, 'App\\Models\\User', 2),
(1, 'App\\Models\\User', 3),
(1, 'App\\Models\\User', 4),
(1, 'App\\Models\\User', 5),
(1, 'App\\Models\\User', 6),
(1, 'App\\Models\\User', 7),
(1, 'App\\Models\\User', 8),
(1, 'App\\Models\\User', 9),
(1, 'App\\Models\\User', 10),
(1, 'App\\Models\\User', 11),
(1, 'App\\Models\\User', 12),
(1, 'App\\Models\\User', 13),
(1, 'App\\Models\\User', 14),
(1, 'App\\Models\\User', 15),
(2, 'App\\Models\\User', 16),
(3, 'App\\Models\\User', 17);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `booking_id` bigint(20) UNSIGNED DEFAULT NULL,
  `type` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_receipts`
--

CREATE TABLE `payment_receipts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `booking_id` bigint(20) UNSIGNED NOT NULL,
  `image_url` varchar(255) NOT NULL,
  `receipt_hash` varchar(255) NOT NULL,
  `status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `verified_by` bigint(20) UNSIGNED DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payment_receipts`
--

INSERT INTO `payment_receipts` (`id`, `booking_id`, `image_url`, `receipt_hash`, `status`, `uploaded_at`, `verified_by`, `verified_at`, `created_at`, `updated_at`) VALUES
(1, 17, 'http://127.0.0.1:8000/storage/payment-receipts/UWFU4x7wrDalg8dd9N2vvmkeqJ3yT44AM18JbgfX.jpg', '4261603d7932bef16107643948e976cce4ab622d6144b99f7e84e89cc9da7378', 'pending', '2026-09-24 18:57:34', NULL, NULL, '2026-09-24 18:57:34', '2026-09-24 18:57:34');

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) UNSIGNED NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `personal_access_tokens`
--

INSERT INTO `personal_access_tokens` (`id`, `tokenable_type`, `tokenable_id`, `name`, `token`, `abilities`, `last_used_at`, `expires_at`, `created_at`, `updated_at`) VALUES
(2, 'App\\Models\\User', 1, 'auth_token', 'b77e9c8daaa6e8b2ab0ec9c77e99374b368561ea59ee68d9d919c8f8ae545beb', '[\"*\"]', NULL, NULL, '2026-09-15 05:55:26', '2026-09-15 05:55:26'),
(3, 'App\\Models\\User', 1, 'auth_token', '3ea3930f32f2522dc1b02e40ed7e2bbb834776abfaf5ea99504efea28b81037a', '[\"*\"]', NULL, NULL, '2026-09-15 06:16:37', '2026-09-15 06:16:37'),
(4, 'App\\Models\\User', 1, 'auth_token', '26742bb38c3d00aaf9b98670317f84263cf5e473f744e094ccf5e1c7aab7d0b4', '[\"*\"]', NULL, NULL, '2026-09-16 06:13:05', '2026-09-16 06:13:05'),
(5, 'App\\Models\\User', 1, 'auth_token', 'fd5e20b3891524ea7e42900ae61d604d214ca37e6c51489c6d56138a271dca66', '[\"*\"]', NULL, NULL, '2026-09-16 10:14:22', '2026-09-16 10:14:22'),
(6, 'App\\Models\\User', 3, 'auth_token', 'a77502afeea237252c75656f81958782ef9642aaf580a72ab909b675cbd8f733', '[\"*\"]', NULL, NULL, '2026-09-16 16:01:00', '2026-09-16 16:01:00'),
(7, 'App\\Models\\User', 3, 'auth_token', '5aed3b1b623584e59530580df918e6a3036288bb03989e09189c92042bb89c91', '[\"*\"]', NULL, NULL, '2026-09-16 16:39:54', '2026-09-16 16:39:54'),
(9, 'App\\Models\\User', 7, 'auth_token', '87dd0e88a50a03e20e07221dfc777825e7a709668b6f6298f698d616b187cf5b', '[\"*\"]', NULL, NULL, '2026-09-17 13:48:19', '2026-09-17 13:48:19'),
(10, 'App\\Models\\User', 7, 'auth_token', 'e7cb0d1e857177bae341c98e7ff8a198b4d79435a6b2c9940e668cea7265abf4', '[\"*\"]', NULL, NULL, '2026-09-17 13:49:34', '2026-09-17 13:49:34'),
(11, 'App\\Models\\User', 7, 'auth_token', '045f89611eccdd437f579f82a67bb08ea3d4955a7738ad7dceaeef420df3ea99', '[\"*\"]', '2026-09-20 12:07:22', NULL, '2026-09-20 11:41:31', '2026-09-20 12:07:22'),
(12, 'App\\Models\\User', 7, 'auth_token', '42c3203e727fe1b9f6709902cefb1a205a00718dead08caee93060ad8da90e15', '[\"*\"]', NULL, NULL, '2026-09-20 14:08:53', '2026-09-20 14:08:53'),
(17, 'App\\Models\\User', 12, 'auth_token', 'f2956029067cc3eab057a411a86d92201172d4811ee1039dac0e6ef4d004abaa', '[\"*\"]', NULL, NULL, '2026-09-21 09:39:26', '2026-09-21 09:39:26'),
(21, 'App\\Models\\User', 15, 'auth_token', 'eaf260076a9c6afaffc74b571a4599b0927b18849343a163455e96b786b5f1a5', '[\"*\"]', '2026-09-22 06:11:45', NULL, '2026-09-22 06:04:19', '2026-09-22 06:11:45'),
(22, 'App\\Models\\User', 15, 'auth_token', 'aa209003daafc54f5bc5d110b03b4694f3bcdcd4d74a882717cc0464b867650e', '[\"*\"]', '2026-09-24 19:01:33', NULL, '2026-09-24 17:55:40', '2026-09-24 19:01:33'),
(23, 'App\\Models\\User', 15, 'auth_token', 'fe1b053afb9b1b64d7676127a8aa03781825c9a8c698ecb70fd33140167eadb5', '[\"*\"]', '2026-09-27 06:35:51', NULL, '2026-09-27 06:25:12', '2026-09-27 06:35:51'),
(24, 'App\\Models\\User', 16, 'auth_token', '1a0991f10993e957885df10be5336d81a860b62a21b1fc60743b2f03156ec92e', '[\"*\"]', NULL, NULL, '2026-09-27 07:01:11', '2026-09-27 07:01:11'),
(25, 'App\\Models\\User', 16, 'auth_token', '1c0084a53701cec5d530ad1ed3ae9cacb2cf9b544983f95116dc21406cba9cd6', '[\"*\"]', NULL, NULL, '2026-09-27 07:07:58', '2026-09-27 07:07:58'),
(26, 'App\\Models\\User', 16, 'auth_token', 'bd8ae1aee3368da653c6a5f37c4afb41a36473f0be28ce15db164e544a6a78d8', '[\"*\"]', '2026-09-27 07:25:41', NULL, '2026-09-27 07:11:11', '2026-09-27 07:25:41'),
(27, 'App\\Models\\User', 16, 'auth_token', 'df2bc6cd08ef1a8af544d2f346c78e18e5ebe685c8bf3d25991e1ce1212929ce', '[\"*\"]', '2026-09-27 08:53:51', NULL, '2026-09-27 08:16:00', '2026-09-27 08:53:51'),
(28, 'App\\Models\\User', 17, 'postman', '72441b0dd9d9195d214cb11d201b8728aa68635177b84aa74f6ea4548635f35f', '[\"*\"]', '2026-09-27 11:21:17', NULL, '2026-09-27 11:00:56', '2026-09-27 11:21:17'),
(29, 'App\\Models\\User', 17, 'postman', '8ed5c2cd6ef2fc7ed46013b4622f4ad161b8788f30d351832e3755d9d38e206d', '[\"*\"]', '2026-09-27 11:43:22', NULL, '2026-09-27 11:41:41', '2026-09-27 11:43:22');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `guard_name`, `created_at`, `updated_at`) VALUES
(1, 'player', 'web', '2026-09-15 04:48:08', '2026-09-15 04:48:08'),
(2, 'venue_owner', 'web', '2026-09-15 04:48:16', '2026-09-15 04:48:16'),
(3, 'admin', 'web', '2026-09-15 04:48:27', '2026-09-15 04:48:27');

-- --------------------------------------------------------

--
-- Table structure for table `role_has_permissions`
--

CREATE TABLE `role_has_permissions` (
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `role_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sessions`
--

INSERT INTO `sessions` (`id`, `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity`) VALUES
('1Tt3DBAwY46MGUlcIjfs0U4Va1vS7usCqTccVZMr', NULL, '127.0.0.1', 'PostmanRuntime/2.6.0', 'YTozOntzOjY6Il90b2tlbiI7czo0MDoieFNIY1lIS21JcXU1SkNLRFBqdXhkZEJGd2dzU3ZsM3NUcDl3TndKNiI7czo5OiJfcHJldmlvdXMiO2E6Mjp7czozOiJ1cmwiO3M6MjE6Imh0dHA6Ly8xMjcuMC4wLjE6ODAwMCI7czo1OiJyb3V0ZSI7Tjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==', 1789915654),
('F9PtXGH2jyxH5L1W5wUEBMmhLA0fA0hhOeetHrSl', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Code/1.138.0 Chrome/148.0.7778.280 Electron/42.10.0 Safari/537.36', 'YTozOntzOjY6Il90b2tlbiI7czo0MDoiOUpPZ3FJbHpkOVBFTGxjcFpKYjY2a284UVozRjQxVWxCUFk2M2c0WiI7czo5OiJfcHJldmlvdXMiO2E6Mjp7czozOiJ1cmwiO3M6MjE6Imh0dHA6Ly8xMjcuMC4wLjE6ODAwMCI7czo1OiJyb3V0ZSI7Tjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==', 1789580235),
('ixhNVbPdygdjlR5oT49i3v2lnDN8ebanMerat8mG', NULL, '127.0.0.1', 'PostmanRuntime/2.6.0', 'YTozOntzOjY6Il90b2tlbiI7czo0MDoiNnNWZFppZWJnellIRFFNM2RtVmtQUElLSkhmNDB5ZnhFcHNLYkExcyI7czo5OiJfcHJldmlvdXMiO2E6Mjp7czozOiJ1cmwiO3M6MjE6Imh0dHA6Ly8xMjcuMC4wLjE6ODAwMCI7czo1OiJyb3V0ZSI7Tjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==', 1789460400),
('IZOn4enWRbEHkN7c9k2UXwjK0pT1VhpmPeWGfznX', NULL, '127.0.0.1', 'PostmanRuntime/2.7.0', 'YTozOntzOjY6Il90b2tlbiI7czo0MDoiWGtCQ2FrSXNBN3p6YnFHOHlIQnl6UHdxSmE2bFFZOUx0VGxKV2RDaiI7czo5OiJfcHJldmlvdXMiO2E6Mjp7czozOiJ1cmwiO3M6MjE6Imh0dHA6Ly8xMjcuMC4wLjE6ODAwMCI7czo1OiJyb3V0ZSI7Tjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==', 1790067874);

-- --------------------------------------------------------

--
-- Table structure for table `sports`
--

CREATE TABLE `sports` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name_ar` varchar(255) NOT NULL,
  `name_en` varchar(255) NOT NULL,
  `icon_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sports`
--

INSERT INTO `sports` (`id`, `name_ar`, `name_en`, `icon_url`, `created_at`, `updated_at`) VALUES
(1, 'كرة القدم', 'Football', NULL, '2026-09-20 11:29:36', '2026-09-20 11:29:36');

-- --------------------------------------------------------

--
-- Table structure for table `sync_status`
--

CREATE TABLE `sync_status` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `device_token_id` bigint(20) UNSIGNED NOT NULL,
  `data_type` varchar(255) NOT NULL,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `time_slots`
--

CREATE TABLE `time_slots` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `venue_id` bigint(20) UNSIGNED NOT NULL,
  `sport_id` bigint(20) UNSIGNED NOT NULL,
  `slot_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `hourly_price` decimal(10,2) NOT NULL,
  `status` enum('available','booked','blocked') NOT NULL DEFAULT 'available',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `time_slots`
--

INSERT INTO `time_slots` (`id`, `venue_id`, `sport_id`, `slot_date`, `start_time`, `end_time`, `hourly_price`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 1, '2026-09-22', '20:00:00', '21:00:00', 100.00, 'blocked', '2026-09-20 11:29:37', '2026-09-24 18:56:42'),
(2, 1, 1, '2026-09-22', '21:00:00', '22:00:00', 120.00, 'available', '2026-09-20 11:29:37', '2026-09-24 19:03:01'),
(3, 1, 1, '2026-09-22', '22:00:00', '23:00:00', 120.00, 'booked', '2026-09-20 11:29:37', '2026-09-20 11:29:37'),
(4, 1, 1, '2026-09-30', '21:00:00', '22:00:00', 120.00, 'available', '2026-09-27 08:17:12', '2026-09-27 08:26:22');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `status` enum('active','locked','unverified') NOT NULL DEFAULT 'active',
  `failed_login_attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `phone`, `google_id`, `email_verified_at`, `status`, `failed_login_attempts`, `locked_until`, `created_at`, `updated_at`) VALUES
(6, 'te', 'hatem.shbar2003@gmail.com', '$2y$12$CUfrtJ03XOXeCrNABsDMLOpOt7OFIoLKvIXgCCBbqPHZshgLcBq8C', '223556566', NULL, NULL, 'active', 1, NULL, '2026-09-17 13:25:50', '2026-09-27 06:56:48'),
(15, 'hatem', 'hatem407910314@gmail.com', '$2y$12$WoVdgFh7SfK2FkppDv0Ht.CiuI3ZNx5/92lWkGz4EfFYPNS7x46b2', '597103855', NULL, '2026-09-21 09:57:20', 'active', 0, NULL, '2026-09-21 09:55:43', '2026-09-28 11:02:21'),
(16, 'Test Venue Owner', 'venueowner@playvo.test', '$2y$12$BxtoazFmG4G4mwbCEgDE3.803SH1ZiHVVKJ/ToefgbLpJ4.JlEQlm', '0599999999', NULL, '2026-09-27 07:13:47', 'active', 0, NULL, '2026-09-27 06:57:59', '2026-09-27 07:13:48'),
(17, 'Playvo Admin', 'admin@playvo.com', '$2y$12$qDlw6rqizVCPohn0f5x0Vu82Il68FKsv2BnsrdTkF9SzDv0QsV4pW', NULL, NULL, NULL, 'active', 0, NULL, '2026-09-27 11:00:26', '2026-09-27 11:00:26');

-- --------------------------------------------------------

--
-- Table structure for table `venues`
--

CREATE TABLE `venues` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `owner_id` bigint(20) UNSIGNED NOT NULL,
  `city_id` bigint(20) UNSIGNED NOT NULL,
  `name_ar` varchar(255) NOT NULL,
  `name_en` varchar(255) NOT NULL,
  `address_ar` text DEFAULT NULL,
  `address_en` text DEFAULT NULL,
  `area_ar` varchar(255) DEFAULT NULL,
  `area_en` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `length_m` decimal(8,2) DEFAULT NULL,
  `width_m` decimal(8,2) DEFAULT NULL,
  `avg_rating` decimal(3,2) NOT NULL DEFAULT 0.00,
  `min_hourly_price` decimal(10,2) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `venues`
--

INSERT INTO `venues` (`id`, `owner_id`, `city_id`, `name_ar`, `name_en`, `address_ar`, `address_en`, `area_ar`, `area_en`, `latitude`, `longitude`, `length_m`, `width_m`, `avg_rating`, `min_hourly_price`, `status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 16, 1, 'ملعب بلايفو', 'Playvo Stadium', 'غزة - الشيخ رضوان', 'Gaza - Sheikh Radwan', 'ملعب خماسي', 'Five-a-side football field', 31.5169000, 34.4566000, 40.00, 20.00, 4.50, 100.00, 'inactive', '2026-09-20 11:29:37', '2026-09-27 11:41:57', '2026-09-27 11:41:57'),
(2, 16, 1, 'ملعب غزة الرياضي', 'Gaza Sports Stadium', 'الرمال، غزة', 'Al-Rimal, Gaza', 'الرمال', 'Al-Rimal', 31.5204000, 34.4600000, 40.00, 20.00, 0.00, 100.00, 'inactive', '2026-09-27 11:21:17', '2026-09-27 11:43:22', '2026-09-27 11:43:22');

-- --------------------------------------------------------

--
-- Table structure for table `venue_ratings`
--

CREATE TABLE `venue_ratings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `venue_id` bigint(20) UNSIGNED NOT NULL,
  `booking_id` bigint(20) UNSIGNED NOT NULL,
  `rating` tinyint(3) UNSIGNED NOT NULL,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `venue_sports`
--

CREATE TABLE `venue_sports` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `venue_id` bigint(20) UNSIGNED NOT NULL,
  `sport_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `venue_sports`
--

INSERT INTO `venue_sports` (`id`, `venue_id`, `sport_id`) VALUES
(1, 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `verification_codes`
--

CREATE TABLE `verification_codes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used_at` timestamp NULL DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `verification_codes`
--

INSERT INTO `verification_codes` (`id`, `user_id`, `code`, `type`, `expires_at`, `used_at`, `verified_at`, `reset_token`, `created_at`, `updated_at`) VALUES
(23, 6, '966702', 'registration', '2026-09-17 13:36:08', NULL, NULL, NULL, '2026-09-17 13:26:08', '2026-09-17 13:26:08'),
(24, 6, '328915', 'registration', '2026-09-17 13:37:19', NULL, NULL, NULL, '2026-09-17 13:27:19', '2026-09-17 13:27:19'),
(25, 6, '659460', 'registration', '2026-09-17 13:38:35', NULL, NULL, NULL, '2026-09-17 13:28:35', '2026-09-17 13:28:35'),
(26, 6, '266520', 'registration', '2026-09-17 13:40:32', NULL, NULL, NULL, '2026-09-17 13:30:32', '2026-09-17 13:30:32'),
(27, 6, '978452', 'registration', '2026-09-17 13:47:09', NULL, NULL, NULL, '2026-09-17 13:37:09', '2026-09-17 13:37:09'),
(28, 6, '360321', 'registration', '2026-09-17 13:47:52', NULL, NULL, NULL, '2026-09-17 13:37:52', '2026-09-17 13:37:52'),
(44, 15, '963429', 'registration', '2026-09-21 12:57:21', '2026-09-21 09:57:21', NULL, NULL, '2026-09-21 09:56:45', '2026-09-21 09:57:21'),
(45, 15, '627679', 'password_reset', '2026-09-21 12:58:46', '2026-09-21 09:58:46', '2026-09-21 09:58:32', NULL, '2026-09-21 09:57:54', '2026-09-21 09:58:46'),
(46, 15, '265577', 'password_reset', '2026-09-28 07:23:45', NULL, NULL, NULL, '2026-09-28 07:13:45', '2026-09-28 07:13:45'),
(47, 15, '277211', 'password_reset', '2026-09-28 14:02:21', '2026-09-28 11:02:21', '2026-09-28 11:02:02', NULL, '2026-09-28 11:00:03', '2026-09-28 11:02:21');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assistant_queries`
--
ALTER TABLE `assistant_queries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `assistant_queries_parsed_sport_id_foreign` (`parsed_sport_id`),
  ADD KEY `assistant_queries_suggested_venue_id_foreign` (`suggested_venue_id`),
  ADD KEY `assistant_queries_user_id_index` (`user_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `audit_logs_target_index` (`target_type`,`target_id`),
  ADD KEY `audit_logs_admin_user_id_index` (`admin_user_id`);

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `bookings_time_slot_id_unique` (`time_slot_id`),
  ADD UNIQUE KEY `bookings_share_token_unique` (`share_token`),
  ADD KEY `bookings_captain_user_id_index` (`captain_user_id`),
  ADD KEY `bookings_status_index` (`status`);

--
-- Indexes for table `cache`
--
ALTER TABLE `cache`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_expiration_index` (`expiration`);

--
-- Indexes for table `cache_locks`
--
ALTER TABLE `cache_locks`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_locks_expiration_index` (`expiration`);

--
-- Indexes for table `cities`
--
ALTER TABLE `cities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cities_country_id_name_ar_unique` (`country_id`,`name_ar`),
  ADD UNIQUE KEY `cities_country_id_name_en_unique` (`country_id`,`name_en`);

--
-- Indexes for table `countries`
--
ALTER TABLE `countries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `countries_name_ar_name_en_unique` (`name_ar`,`name_en`);

--
-- Indexes for table `device_tokens`
--
ALTER TABLE `device_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `device_tokens_fcm_token_unique` (`fcm_token`),
  ADD KEY `device_tokens_user_id_foreign` (`user_id`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `favorites`
--
ALTER TABLE `favorites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `favorites_user_id_venue_id_unique` (`user_id`,`venue_id`),
  ADD KEY `favorites_venue_id_foreign` (`venue_id`);

--
-- Indexes for table `images`
--
ALTER TABLE `images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `images_imageable_index` (`imageable_type`,`imageable_id`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `jobs_queue_index` (`queue`);

--
-- Indexes for table `job_batches`
--
ALTER TABLE `job_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `model_has_permissions`
--
ALTER TABLE `model_has_permissions`
  ADD PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  ADD KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`);

--
-- Indexes for table `model_has_roles`
--
ALTER TABLE `model_has_roles`
  ADD PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  ADD KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `notifications_booking_id_foreign` (`booking_id`),
  ADD KEY `notifications_user_id_is_read_index` (`user_id`,`is_read`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `payment_receipts`
--
ALTER TABLE `payment_receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `payment_receipts_receipt_hash_unique` (`receipt_hash`),
  ADD KEY `payment_receipts_verified_by_foreign` (`verified_by`),
  ADD KEY `payment_receipts_booking_id_index` (`booking_id`),
  ADD KEY `payment_receipts_status_index` (`status`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  ADD KEY `personal_access_tokens_expires_at_index` (`expires_at`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`);

--
-- Indexes for table `role_has_permissions`
--
ALTER TABLE `role_has_permissions`
  ADD PRIMARY KEY (`permission_id`,`role_id`),
  ADD KEY `role_has_permissions_role_id_foreign` (`role_id`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sessions_user_id_index` (`user_id`),
  ADD KEY `sessions_last_activity_index` (`last_activity`);

--
-- Indexes for table `sports`
--
ALTER TABLE `sports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sports_name_ar_name_en_unique` (`name_ar`,`name_en`);

--
-- Indexes for table `sync_status`
--
ALTER TABLE `sync_status`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sync_status_device_token_id_data_type_unique` (`device_token_id`,`data_type`);

--
-- Indexes for table `time_slots`
--
ALTER TABLE `time_slots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `time_slot_availability_unique` (`venue_id`,`sport_id`,`slot_date`,`start_time`,`end_time`),
  ADD KEY `time_slots_venue_id_slot_date_index` (`venue_id`,`slot_date`),
  ADD KEY `time_slots_sport_id_slot_date_index` (`sport_id`,`slot_date`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`),
  ADD UNIQUE KEY `users_google_id_unique` (`google_id`);

--
-- Indexes for table `venues`
--
ALTER TABLE `venues`
  ADD PRIMARY KEY (`id`),
  ADD KEY `venues_owner_id_index` (`owner_id`),
  ADD KEY `venues_city_id_index` (`city_id`);

--
-- Indexes for table `venue_ratings`
--
ALTER TABLE `venue_ratings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `venue_ratings_booking_id_unique` (`booking_id`),
  ADD KEY `venue_ratings_venue_id_index` (`venue_id`);

--
-- Indexes for table `venue_sports`
--
ALTER TABLE `venue_sports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `venue_sports_venue_id_sport_id_unique` (`venue_id`,`sport_id`),
  ADD KEY `venue_sports_sport_id_foreign` (`sport_id`);

--
-- Indexes for table `verification_codes`
--
ALTER TABLE `verification_codes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `verification_codes_reset_token_unique` (`reset_token`),
  ADD KEY `verification_codes_user_id_type_index` (`user_id`,`type`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assistant_queries`
--
ALTER TABLE `assistant_queries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `cities`
--
ALTER TABLE `cities`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `countries`
--
ALTER TABLE `countries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `device_tokens`
--
ALTER TABLE `device_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `favorites`
--
ALTER TABLE `favorites`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `images`
--
ALTER TABLE `images`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=59;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment_receipts`
--
ALTER TABLE `payment_receipts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `sports`
--
ALTER TABLE `sports`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sync_status`
--
ALTER TABLE `sync_status`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `time_slots`
--
ALTER TABLE `time_slots`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `venues`
--
ALTER TABLE `venues`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `venue_ratings`
--
ALTER TABLE `venue_ratings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `venue_sports`
--
ALTER TABLE `venue_sports`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `verification_codes`
--
ALTER TABLE `verification_codes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assistant_queries`
--
ALTER TABLE `assistant_queries`
  ADD CONSTRAINT `assistant_queries_parsed_sport_id_foreign` FOREIGN KEY (`parsed_sport_id`) REFERENCES `sports` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `assistant_queries_suggested_venue_id_foreign` FOREIGN KEY (`suggested_venue_id`) REFERENCES `venues` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `assistant_queries_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_admin_user_id_foreign` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_captain_user_id_foreign` FOREIGN KEY (`captain_user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `bookings_time_slot_id_foreign` FOREIGN KEY (`time_slot_id`) REFERENCES `time_slots` (`id`);

--
-- Constraints for table `cities`
--
ALTER TABLE `cities`
  ADD CONSTRAINT `cities_country_id_foreign` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `device_tokens`
--
ALTER TABLE `device_tokens`
  ADD CONSTRAINT `device_tokens_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `favorites`
--
ALTER TABLE `favorites`
  ADD CONSTRAINT `favorites_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `favorites_venue_id_foreign` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `model_has_permissions`
--
ALTER TABLE `model_has_permissions`
  ADD CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `model_has_roles`
--
ALTER TABLE `model_has_roles`
  ADD CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_booking_id_foreign` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_receipts`
--
ALTER TABLE `payment_receipts`
  ADD CONSTRAINT `payment_receipts_booking_id_foreign` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payment_receipts_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `role_has_permissions`
--
ALTER TABLE `role_has_permissions`
  ADD CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sync_status`
--
ALTER TABLE `sync_status`
  ADD CONSTRAINT `sync_status_device_token_id_foreign` FOREIGN KEY (`device_token_id`) REFERENCES `device_tokens` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `time_slots`
--
ALTER TABLE `time_slots`
  ADD CONSTRAINT `time_slots_sport_id_foreign` FOREIGN KEY (`sport_id`) REFERENCES `sports` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `time_slots_venue_id_foreign` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `venues`
--
ALTER TABLE `venues`
  ADD CONSTRAINT `venues_city_id_foreign` FOREIGN KEY (`city_id`) REFERENCES `cities` (`id`),
  ADD CONSTRAINT `venues_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `venue_ratings`
--
ALTER TABLE `venue_ratings`
  ADD CONSTRAINT `venue_ratings_booking_id_foreign` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `venue_ratings_venue_id_foreign` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `venue_sports`
--
ALTER TABLE `venue_sports`
  ADD CONSTRAINT `venue_sports_sport_id_foreign` FOREIGN KEY (`sport_id`) REFERENCES `sports` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `venue_sports_venue_id_foreign` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `verification_codes`
--
ALTER TABLE `verification_codes`
  ADD CONSTRAINT `verification_codes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
