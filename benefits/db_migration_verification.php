<?php
/**
 * db_migration_verification.php
 * Run this ONCE to create/update tables for the AI Claim Verification system.
 * Usage: php db_migration_verification.php  OR  visit in browser as admin.
 *
 * Tables created:
 *   - claim_receipts       (receipt file metadata, hash, OCR text)
 *   - claim_verification_logs  (verification action audit trail)
 *
 * Tables altered:
 *   - claims  (adds missing columns if not present)
 */

include_once(__DIR__ . '/../connection.php');

if (!isset($conn) || !$conn) {
    die("Database connection failed.");
}

$executed = [];
$errors   = [];

// ─────────────────────────────────────────────
// 1. claim_receipts — receipt file metadata
// ─────────────────────────────────────────────
$sql = "CREATE TABLE IF NOT EXISTS `claim_receipts` (
    `id`              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `claim_id`        BIGINT(20) NOT NULL,
    `file_path`       VARCHAR(1024) NOT NULL,
    `original_name`   VARCHAR(255) DEFAULT NULL,
    `mime_type`       VARCHAR(100) DEFAULT NULL,
    `file_size`       INT UNSIGNED DEFAULT 0,
    `file_hash`       VARCHAR(64) DEFAULT NULL COMMENT 'SHA-256 of file contents',
    `phash`           VARCHAR(64) DEFAULT NULL COMMENT 'Perceptual hash for duplicate detection',
    `ocr_raw_text`    LONGTEXT DEFAULT NULL COMMENT 'Raw OCR output',
    `ocr_confidence`  DECIMAL(5,2) DEFAULT NULL COMMENT 'OCR confidence 0-100',
    `extracted_vendor`  VARCHAR(255) DEFAULT NULL,
    `extracted_amount`  DECIMAL(12,2) DEFAULT NULL,
    `extracted_date`    VARCHAR(64) DEFAULT NULL,
    `extracted_tax`     DECIMAL(12,2) DEFAULT NULL,
    `extracted_subtotal` DECIMAL(12,2) DEFAULT NULL,
    `extracted_receipt_no` VARCHAR(128) DEFAULT NULL,
    `extracted_items`   TEXT DEFAULT NULL COMMENT 'JSON array of line items',
    `image_width`     INT UNSIGNED DEFAULT NULL,
    `image_height`    INT UNSIGNED DEFAULT NULL,
    `quality_score`   DECIMAL(4,3) DEFAULT NULL COMMENT '0.000-1.000',
    `is_blurry`       TINYINT(1) DEFAULT 0,
    `tampering_score` DECIMAL(4,3) DEFAULT NULL COMMENT '0=clean, 1=tampered',
    `document_type`   VARCHAR(50) DEFAULT 'unknown' COMMENT 'receipt, invoice, handwritten, unknown',
    `uploaded_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_claim_id` (`claim_id`),
    INDEX `idx_file_hash` (`file_hash`),
    INDEX `idx_phash` (`phash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if (mysqli_query($conn, $sql)) {
    $executed[] = "Created table `claim_receipts`";
} else {
    $errors[] = "claim_receipts: " . mysqli_error($conn);
}

// ─────────────────────────────────────────────
// 2. claim_verification_logs — verification audit trail
// ─────────────────────────────────────────────
$sql2 = "CREATE TABLE IF NOT EXISTS `claim_verification_logs` (
    `id`               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `claim_id`         BIGINT(20) NOT NULL,
    `receipt_id`       BIGINT(20) UNSIGNED DEFAULT NULL,
    `verified_by`      VARCHAR(128) NOT NULL COMMENT 'Username of verifier',
    `verification_type` VARCHAR(50) NOT NULL DEFAULT 'auto' COMMENT 'auto, manual, re-verify',
    `overall_score`    DECIMAL(5,2) DEFAULT NULL COMMENT '0-100',
    `amount_score`     DECIMAL(4,3) DEFAULT NULL COMMENT '0.000-1.000',
    `vendor_score`     DECIMAL(4,3) DEFAULT NULL COMMENT '0.000-1.000',
    `date_score`       DECIMAL(4,3) DEFAULT NULL COMMENT '0.000-1.000',
    `category_score`   DECIMAL(4,3) DEFAULT NULL COMMENT '0.000-1.000',
    `receipt_quality_score` DECIMAL(4,3) DEFAULT NULL,
    `duplicate_check`  VARCHAR(20) DEFAULT 'pass' COMMENT 'pass, warning, fail',
    `result_status`    VARCHAR(30) NOT NULL COMMENT 'verified, flagged, rejected',
    `result_message`   TEXT DEFAULT NULL,
    `details_json`     LONGTEXT DEFAULT NULL COMMENT 'Full verification breakdown as JSON',
    `submitted_amount` DECIMAL(12,2) DEFAULT NULL,
    `extracted_amount` DECIMAL(12,2) DEFAULT NULL,
    `submitted_vendor` VARCHAR(255) DEFAULT NULL,
    `extracted_vendor` VARCHAR(255) DEFAULT NULL,
    `submitted_date`   VARCHAR(64) DEFAULT NULL,
    `extracted_date`   VARCHAR(64) DEFAULT NULL,
    `ip_address`       VARCHAR(45) DEFAULT NULL,
    `user_agent`       VARCHAR(512) DEFAULT NULL,
    `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_claim_id` (`claim_id`),
    INDEX `idx_receipt_id` (`receipt_id`),
    INDEX `idx_verified_by` (`verified_by`),
    INDEX `idx_result_status` (`result_status`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

if (mysqli_query($conn, $sql2)) {
    $executed[] = "Created table `claim_verification_logs`";
} else {
    $errors[] = "claim_verification_logs: " . mysqli_error($conn);
}

// ─────────────────────────────────────────────
// 3. Ensure claims table has all needed columns
// ─────────────────────────────────────────────
$columns_to_add = [
    'ocr_text'                => "ALTER TABLE `claims` ADD COLUMN `ocr_text` LONGTEXT DEFAULT NULL",
    'ocr_confidence'          => "ALTER TABLE `claims` ADD COLUMN `ocr_confidence` FLOAT DEFAULT NULL",
    'nlp_suggestions'         => "ALTER TABLE `claims` ADD COLUMN `nlp_suggestions` LONGTEXT DEFAULT NULL",
    'risk_score'              => "ALTER TABLE `claims` ADD COLUMN `risk_score` DECIMAL(4,2) DEFAULT NULL",
    'receipt_validity'        => "ALTER TABLE `claims` ADD COLUMN `receipt_validity` VARCHAR(32) DEFAULT 'unknown'",
    'tamper_evidence'         => "ALTER TABLE `claims` ADD COLUMN `tamper_evidence` LONGTEXT DEFAULT NULL",
    'phash'                   => "ALTER TABLE `claims` ADD COLUMN `phash` VARCHAR(64) DEFAULT NULL",
    'ai_raw'                  => "ALTER TABLE `claims` ADD COLUMN `ai_raw` LONGTEXT DEFAULT NULL",
    'receipt_is_invoice'      => "ALTER TABLE `claims` ADD COLUMN `receipt_is_invoice` TINYINT(1) DEFAULT 0",
    'receipt_type_confidence' => "ALTER TABLE `claims` ADD COLUMN `receipt_type_confidence` DECIMAL(4,3) DEFAULT NULL",
    'verification_status'     => "ALTER TABLE `claims` ADD COLUMN `verification_status` VARCHAR(30) DEFAULT NULL COMMENT 'verified, flagged, rejected'",
    'verification_score'      => "ALTER TABLE `claims` ADD COLUMN `verification_score` DECIMAL(5,2) DEFAULT NULL",
    'last_verified_at'        => "ALTER TABLE `claims` ADD COLUMN `last_verified_at` DATETIME DEFAULT NULL",
    'last_verified_by'        => "ALTER TABLE `claims` ADD COLUMN `last_verified_by` VARCHAR(128) DEFAULT NULL",
];

foreach ($columns_to_add as $col_name => $alter_sql) {
    // Check if column exists
    $check = mysqli_query($conn, "SHOW COLUMNS FROM `claims` LIKE '$col_name'");
    if ($check && mysqli_num_rows($check) === 0) {
        if (mysqli_query($conn, $alter_sql)) {
            $executed[] = "Added column `claims`.`$col_name`";
        } else {
            $err = mysqli_error($conn);
            if (strpos($err, 'Duplicate column') === false) {
                $errors[] = "claims.$col_name: $err";
            }
        }
    }
}

// ─────────────────────────────────────────────
// Output
// ─────────────────────────────────────────────
echo "<h2>DB Migration — Claim Verification System</h2>";
echo "<h4 style='color:green;'>Executed (" . count($executed) . "):</h4><ul>";
foreach ($executed as $msg) echo "<li>$msg</li>";
echo "</ul>";

if ($errors) {
    echo "<h4 style='color:red;'>Errors (" . count($errors) . "):</h4><ul>";
    foreach ($errors as $msg) echo "<li>$msg</li>";
    echo "</ul>";
} else {
    echo "<p><strong>All migrations completed successfully.</strong></p>";
}

echo "<p><em>You can safely run this script multiple times — it will skip existing tables/columns.</em></p>";
