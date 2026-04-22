<?php
/**
 * verify_claim.php
 * AI-powered claim verification endpoint
 * Returns JSON responses only
 */

// === CRITICAL: Set JSON header FIRST ===
header('Content-Type: application/json; charset=utf-8');

// === Error handling BEFORE anything else ===
ini_set('display_errors', 0);
error_reporting(E_ALL);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'PHP Error: ' . $errstr,
        'file' => basename($errfile),
        'line' => $errline
    ]);
    exit;
});

try {
    // === REQUEST VALIDATION ===
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Invalid request method');
    }

    session_start();

    // === AUTHENTICATION ===
    if (empty($_SESSION['username']) || empty($_SESSION['role'])) {
        http_response_code(401);
        throw new Exception('Unauthorized');
    }

    if (!in_array($_SESSION['role'], ['Benefits Officer', 'HR3 Admin'])) {
        http_response_code(403);
        throw new Exception('Insufficient permissions');
    }

    // === INPUT VALIDATION ===
    $claim_id = isset($_POST['claim_id']) ? intval($_POST['claim_id']) : 0;
    if ($claim_id < 1) {
        http_response_code(400);
        throw new Exception('Invalid claim ID');
    }

    // === DATABASE CONNECTION ===
    // Try to include connection file
    if (!file_exists(__DIR__ . '/../connection.php')) {
        throw new Exception('Database connection file not found');
    }

    @include_once(__DIR__ . '/../connection.php');

    // Verify connection
    if (!isset($conn) || !$conn) {
        http_response_code(500);
        throw new Exception('Database connection failed');
    }

    // === FETCH CLAIM ===
    $query = "SELECT 
                id, amount, category, vendor, expense_date, description, 
                receipt_path, nlp_suggestions, ocr_text, risk_score, 
                receipt_validity, ai_raw, created_by, status, created_at
              FROM claims 
              WHERE id = ?
              LIMIT 1";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Database prepare error: ' . $conn->error);
    }

    $stmt->bind_param('i', $claim_id);
    if (!$stmt->execute()) {
        throw new Exception('Database execute error: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $claim = $result->fetch_assoc();
    $stmt->close();

    if (!$claim) {
        http_response_code(404);
        throw new Exception('Claim not found');
    }

    // === PARSE NLP DATA ===
    $nlp_data = [];
    if (!empty($claim['nlp_suggestions'])) {
        $nlp_data = json_decode($claim['nlp_suggestions'], true) ?? [];
    }

    $ai_raw = [];
    if (!empty($claim['ai_raw'])) {
        $ai_raw = json_decode($claim['ai_raw'], true) ?? [];
    }

    // === RUN VERIFICATIONS ===
    $verification = [
        'amount_match' => verify_amount($claim, $nlp_data),
        'vendor_match' => verify_vendor($claim, $nlp_data),
        'date_match' => verify_date($claim, $nlp_data),
        'category_match' => verify_category($claim, $nlp_data),
        'receipt_validity' => verify_receipt($claim),
        'duplicate_check' => check_duplicates($claim, $conn),
        'ocr_confidence' => get_ocr_confidence($claim, $nlp_data)
    ];

    // === CALCULATE SCORE ===
    $overall_score = calculate_score($verification);
    $status = determine_status($overall_score, $verification);

    // === RESPONSE ===
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'claim_id' => intval($claim['id']),
        'overall_score' => floatval($overall_score),
        'status' => $status,
        'verification_details' => $verification,
        'claim_data' => [
            'amount' => floatval($claim['amount'] ?? 0),
            'category' => $claim['category'] ?? 'Unknown',
            'vendor' => $claim['vendor'] ?? 'Not specified',
            'expense_date' => $claim['expense_date'] ?? 'N/A',
            'description' => $claim['description'] ?? '',
            'status' => $claim['status'] ?? 'pending'
        ],
        'nlp_data' => $nlp_data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

exit;

// ============================================
// VERIFICATION FUNCTIONS
// ============================================

function verify_amount($claim, $nlp_data) {
    $submitted = floatval($claim['amount'] ?? 0);
    $extracted = isset($nlp_data['amount']) ? floatval($nlp_data['amount']) : null;

    if ($extracted === null) {
        return [
            'status' => 'warning',
            'message' => 'Could not extract amount from receipt',
            'confidence' => 0.5
        ];
    }

    if ($extracted == 0) {
        return [
            'status' => 'warning',
            'message' => 'Receipt amount is zero',
            'confidence' => 0.3
        ];
    }

    $diff_pct = abs($submitted - $extracted) / max($submitted, 0.01) * 100;

    if ($diff_pct < 1) {
        return [
            'status' => 'pass',
            'message' => 'Amount matches exactly',
            'confidence' => 0.95
        ];
    } elseif ($diff_pct < 5) {
        return [
            'status' => 'pass',
            'message' => 'Amount matches closely (' . round($diff_pct, 1) . '% variance)',
            'confidence' => 0.85
        ];
    } elseif ($diff_pct < 15) {
        return [
            'status' => 'warning',
            'message' => 'Amount differs (' . round($diff_pct, 1) . '% variance)',
            'confidence' => 0.60
        ];
    } else {
        return [
            'status' => 'fail',
            'message' => 'Amount significantly differs (' . round($diff_pct, 1) . '% variance)',
            'confidence' => 0.20
        ];
    }
}

function verify_vendor($claim, $nlp_data) {
    $submitted = strtolower(trim($claim['vendor'] ?? ''));
    $extracted = strtolower(trim($nlp_data['vendor'] ?? ''));

    if (empty($submitted)) {
        return [
            'status' => 'warning',
            'message' => 'No vendor name submitted',
            'confidence' => 0.5
        ];
    }

    if (empty($extracted)) {
        return [
            'status' => 'warning',
            'message' => 'Could not extract vendor from receipt',
            'confidence' => 0.6
        ];
    }

    $distance = levenshtein($submitted, $extracted);
    $max_len = max(strlen($submitted), strlen($extracted));
    $similarity = $max_len > 0 ? (1 - ($distance / $max_len)) : 0;

    if ($similarity > 0.9) {
        return [
            'status' => 'pass',
            'message' => 'Vendor matches (' . round($similarity * 100) . '%)',
            'confidence' => 0.95
        ];
    } elseif ($similarity > 0.75) {
        return [
            'status' => 'pass',
            'message' => 'Vendor matches closely (' . round($similarity * 100) . '%)',
            'confidence' => 0.80
        ];
    } elseif ($similarity > 0.5) {
        return [
            'status' => 'warning',
            'message' => 'Vendor partially matches (' . round($similarity * 100) . '%)',
            'confidence' => 0.60
        ];
    } else {
        return [
            'status' => 'fail',
            'message' => 'Vendor does not match (' . round($similarity * 100) . '%)',
            'confidence' => 0.20
        ];
    }
}

function verify_date($claim, $nlp_data) {
    $submitted = $claim['expense_date'];
    $extracted = $nlp_data['date'] ?? null;

    if (empty($submitted)) {
        return [
            'status' => 'warning',
            'message' => 'No expense date provided',
            'confidence' => 0.5
        ];
    }

    if (empty($extracted)) {
        return [
            'status' => 'warning',
            'message' => 'Could not extract date from receipt',
            'confidence' => 0.6
        ];
    }

    try {
        $submitted_dt = new DateTime($submitted);
        $extracted_dt = new DateTime($extracted);
        $diff = $submitted_dt->diff($extracted_dt);
        $days = abs($diff->days);

        if ($days == 0) {
            return [
                'status' => 'pass',
                'message' => 'Date matches exactly',
                'confidence' => 0.95
            ];
        } elseif ($days <= 1) {
            return [
                'status' => 'pass',
                'message' => 'Date matches (1-day variance)',
                'confidence' => 0.90
            ];
        } elseif ($days <= 7) {
            return [
                'status' => 'warning',
                'message' => 'Date differs by ' . $days . ' days',
                'confidence' => 0.70
            ];
        } else {
            return [
                'status' => 'warning',
                'message' => 'Date differs by ' . $days . ' days',
                'confidence' => 0.50
            ];
        }
    } catch (Exception $e) {
        return [
            'status' => 'warning',
            'message' => 'Date format issue',
            'confidence' => 0.50
        ];
    }
}

function verify_category($claim, $nlp_data) {
    $submitted = strtolower(trim($claim['category'] ?? ''));
    $extracted = strtolower(trim($nlp_data['category'] ?? ''));

    if (empty($submitted)) {
        return [
            'status' => 'warning',
            'message' => 'No category submitted',
            'confidence' => 0.5
        ];
    }

    if (empty($extracted)) {
        return [
            'status' => 'warning',
            'message' => 'Could not determine category',
            'confidence' => 0.6
        ];
    }

    if ($submitted === $extracted) {
        return [
            'status' => 'pass',
            'message' => 'Category matches',
            'confidence' => 0.95
        ];
    }

    $compat = is_category_compatible($submitted, $extracted);
    if ($compat) {
        return [
            'status' => 'pass',
            'message' => 'Category is compatible',
            'confidence' => 0.85
        ];
    }

    return [
        'status' => 'warning',
        'message' => 'Category differs from receipt',
        'confidence' => 0.65
    ];
}

function is_category_compatible($cat1, $cat2) {
    $groups = [
        ['meal', 'food', 'restaurant', 'cafe', 'lunch', 'dinner', 'breakfast'],
        ['travel', 'taxi', 'flight', 'transport', 'hotel'],
        ['medical', 'health', 'pharmacy', 'clinic'],
        ['supplies', 'office', 'stationery', 'equipment'],
    ];

    foreach ($groups as $group) {
        if (in_array($cat1, $group) && in_array($cat2, $group)) {
            return true;
        }
    }
    return false;
}

function verify_receipt($claim) {
    $validity = $claim['receipt_validity'] ?? 'unknown';

    $map = [
        'valid' => ['status' => 'pass', 'message' => 'Receipt is valid', 'confidence' => 0.95],
        'invalid' => ['status' => 'fail', 'message' => 'Receipt invalid', 'confidence' => 0.10],
        'unclear' => ['status' => 'warning', 'message' => 'Receipt validity unclear', 'confidence' => 0.60],
        'unknown' => ['status' => 'warning', 'message' => 'Validity not determined', 'confidence' => 0.50]
    ];

    return $map[$validity] ?? $map['unknown'];
}

function check_duplicates($claim, $conn) {
    try {
        $id = intval($claim['id']);
        $amount = floatval($claim['amount'] ?? 0);
        $vendor = $claim['vendor'] ?? '';
        $date = $claim['expense_date'] ?? '';
        $user = $claim['created_by'] ?? '';

        $q = "SELECT COUNT(*) as cnt FROM claims 
              WHERE id != ? AND amount = ? AND vendor = ? 
              AND expense_date = ? AND created_by = ?
              AND status IN ('approved', 'pending')
              LIMIT 1";

        $s = $conn->prepare($q);
        if (!$s) return ['status' => 'warning', 'message' => 'Could not check', 'confidence' => 0.6];

        $s->bind_param('idsss', $id, $amount, $vendor, $date, $user);
        $s->execute();
        $r = $s->get_result()->fetch_assoc();
        $s->close();

        $count = $r['cnt'] ?? 0;

        if ($count > 0) {
            return [
                'status' => 'warning',
                'message' => 'Found ' . $count . ' similar claim(s)',
                'confidence' => 0.70
            ];
        }

        return [
            'status' => 'pass',
            'message' => 'No duplicates found',
            'confidence' => 0.95
        ];

    } catch (Exception $e) {
        return [
            'status' => 'warning',
            'message' => 'Could not check duplicates',
            'confidence' => 0.60
        ];
    }
}

function get_ocr_confidence($claim, $nlp_data) {
    $conf = 0;

    if (!empty($claim['ocr_text']) && !empty($claim['ocr_confidence'])) {
        $conf = floatval($claim['ocr_confidence']);
    } elseif (isset($nlp_data['confidence'])) {
        $conf = floatval($nlp_data['confidence']);
    }

    if ($conf >= 0.8) {
        return [
            'status' => 'pass',
            'message' => 'High OCR confidence',
            'confidence' => $conf
        ];
    } elseif ($conf >= 0.6) {
        return [
            'status' => 'pass',
            'message' => 'Acceptable OCR confidence',
            'confidence' => $conf
        ];
    } elseif ($conf > 0) {
        return [
            'status' => 'warning',
            'message' => 'Low OCR confidence',
            'confidence' => $conf
        ];
    }

    return [
        'status' => 'warning',
        'message' => 'No OCR data available',
        'confidence' => 0.5
    ];
}

function calculate_score($verification) {
    $weights = [
        'amount_match' => 0.25,
        'vendor_match' => 0.20,
        'date_match' => 0.15,
        'category_match' => 0.15,
        'receipt_validity' => 0.10,
        'duplicate_check' => 0.10,
        'ocr_confidence' => 0.05
    ];

    $total = 0;
    $weight_sum = 0;

    foreach ($weights as $key => $weight) {
        if (isset($verification[$key]['confidence'])) {
            $conf = floatval($verification[$key]['confidence']);
            $total += $conf * $weight;
            $weight_sum += $weight;
        }
    }

    return $weight_sum > 0 ? round(($total / $weight_sum) * 100, 1) : 0;
}

function determine_status($score, $verification) {
    if (isset($verification['receipt_validity']['status']) && $verification['receipt_validity']['status'] === 'fail') {
        return 'flagged';
    }

    if (isset($verification['amount_match']['status']) && $verification['amount_match']['status'] === 'fail') {
        return 'flagged';
    }

    if ($score >= 85) {
        return 'approved';
    } elseif ($score >= 70) {
        return 'review_pending';
    } elseif ($score >= 50) {
        return 'manual_review';
    }

    return 'flagged';
}
?>