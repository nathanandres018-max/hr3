<?php
/**
 * policy_checker_api.php
 * ============================================================
 * API endpoint for the AI-Powered Policy Checker
 * ============================================================
 * Handles all policy checking requests from the frontend:
 *   - check_compliance   — Full compliance check for a claim
 *   - validate_draft     — Pre-submission validation
 *   - risk_score         — Risk/fraud scoring
 *   - violation_reasons  — Human-readable violation explanations
 *   - run_audit          — Full policy audit
 *   - override           — Admin override of AI decision
 *   - get_policy_info    — Get policy info for a category
 *
 * All responses are JSON.
 * Requires authenticated session (Benefits Officer / HR3 Admin / Regular).
 * ============================================================
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-cache, no-store, must-revalidate");

session_start();
require_once(__DIR__ . '/../connection.php');
require_once(__DIR__ . '/policy_engine.php');

// ============================================================
// Authentication: Allow Benefits Officer, HR3 Admin, AND Regular employees
// (Regular employees need pre-submission validation access)
// ============================================================
$allowedRoles = ['Benefits Officer', 'HR3 Admin', 'Regular'];

if (!isset($_SESSION['username']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowedRoles)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized. Please log in.']);
    exit;
}

$username = $_SESSION['username'];
$role     = $_SESSION['role'];

// ============================================================
// Initialize Policy Engine
// ============================================================
try {
    $engine = new PolicyEngine($conn);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to initialize policy engine.']);
    exit;
}

// ============================================================
// Route by action
// ============================================================
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // --------------------------------------------------------
    // 1. COMPLIANCE CHECK — Full check against policies
    // --------------------------------------------------------
    case 'check_compliance':
        $claim = [
            'claim_id'     => intval($_POST['claim_id'] ?? 0),
            'category'     => trim($_POST['category'] ?? ''),
            'amount'       => floatval($_POST['amount'] ?? 0),
            'vendor'       => trim($_POST['vendor'] ?? ''),
            'expense_date' => trim($_POST['expense_date'] ?? ''),
            'description'  => trim($_POST['description'] ?? ''),
            'has_receipt'  => !empty($_POST['has_receipt']),
            'receipt_path' => trim($_POST['receipt_path'] ?? ''),
            'created_by'   => trim($_POST['created_by'] ?? $username),
        ];

        $result = $engine->checkCompliance($claim);
        $riskResult = $engine->calculateRiskScore($claim);

        // Log the check
        $engine->logCheck([
            'claim_id'          => $claim['claim_id'] ?: null,
            'checked_by'        => $username,
            'check_type'        => 'compliance',
            'claim_category'    => $result['category'],
            'claim_amount'      => $claim['amount'],
            'claim_vendor'      => $claim['vendor'],
            'claim_date'        => $claim['expense_date'],
            'compliance_status' => $result['compliance_status'],
            'risk_score'        => $riskResult['risk_score'],
            'risk_level'        => $riskResult['risk_level'],
            'violations'        => $result['violations'],
            'recommendations'   => $result['recommendations'],
            'explanation'       => $result['explanation'],
        ]);

        echo json_encode([
            'ok'                  => true,
            'compliance_status'   => $result['compliance_status'],
            'violations'          => $result['violations'],
            'recommendations'     => $result['recommendations'],
            'explanation'         => $result['explanation'],
            'policy_limit'        => $result['policy_limit'],
            'risk_score'          => $riskResult['risk_score'],
            'risk_level'          => $riskResult['risk_level'],
            'risk_factors'        => $riskResult['risk_factors'],
            'auto_flag'           => $riskResult['auto_flag'],
        ]);
        break;

    // --------------------------------------------------------
    // 2. PRE-SUBMISSION VALIDATION — Real-time draft check
    // --------------------------------------------------------
    case 'validate_draft':
        $draft = [
            'category'     => trim($_POST['category'] ?? ''),
            'amount'       => floatval($_POST['amount'] ?? 0),
            'vendor'       => trim($_POST['vendor'] ?? ''),
            'expense_date' => trim($_POST['expense_date'] ?? ''),
            'description'  => trim($_POST['description'] ?? ''),
            'has_receipt'  => !empty($_POST['has_receipt']),
            'created_by'   => $username,
        ];

        $result = $engine->validatePreSubmission($draft);

        echo json_encode([
            'ok'          => true,
            'can_submit'  => $result['can_submit'],
            'errors'      => $result['errors'],
            'warnings'    => $result['warnings'],
            'info'        => $result['info'],
            'policy_info' => $result['policy_info'],
        ]);
        break;

    // --------------------------------------------------------
    // 3. RISK SCORING — Calculate risk/fraud score
    // --------------------------------------------------------
    case 'risk_score':
        // Only officers/admin for existing claims
        if ($role === 'Regular' && !empty($_POST['claim_id'])) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Employees cannot run risk scoring on existing claims.']);
            exit;
        }

        $claim = [
            'id'           => intval($_POST['claim_id'] ?? 0),
            'category'     => trim($_POST['category'] ?? ''),
            'amount'       => floatval($_POST['amount'] ?? 0),
            'vendor'       => trim($_POST['vendor'] ?? ''),
            'expense_date' => trim($_POST['expense_date'] ?? ''),
            'receipt_path' => trim($_POST['receipt_path'] ?? ''),
            'has_receipt'  => !empty($_POST['has_receipt']) || !empty($_POST['receipt_path']),
            'created_by'   => trim($_POST['created_by'] ?? $username),
        ];

        // If claim_id provided, load from DB
        if ($claim['id'] > 0) {
            $stmt = $conn->prepare("SELECT * FROM claims WHERE id = ?");
            $stmt->bind_param('i', $claim['id']);
            $stmt->execute();
            $dbClaim = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($dbClaim) {
                $claim = array_merge($claim, [
                    'category'     => $dbClaim['category'] ?? $claim['category'],
                    'amount'       => floatval($dbClaim['amount'] ?? $claim['amount']),
                    'vendor'       => $dbClaim['vendor'] ?? $claim['vendor'],
                    'expense_date' => $dbClaim['expense_date'] ?? $claim['expense_date'],
                    'receipt_path' => $dbClaim['receipt_path'] ?? '',
                    'created_by'   => $dbClaim['created_by'] ?? $claim['created_by'],
                    'has_receipt'  => !empty($dbClaim['receipt_path']),
                ]);
            }
        }

        $result = $engine->calculateRiskScore($claim);

        // Log the check
        $engine->logCheck([
            'claim_id'          => $claim['id'] ?: null,
            'checked_by'        => $username,
            'check_type'        => 'risk_score',
            'claim_category'    => $claim['category'],
            'claim_amount'      => $claim['amount'],
            'claim_vendor'      => $claim['vendor'],
            'claim_date'        => $claim['expense_date'],
            'compliance_status' => $result['compliance'],
            'risk_score'        => $result['risk_score'],
            'risk_level'        => $result['risk_level'],
            'violations'        => $result['risk_factors'],
            'recommendations'   => [$result['recommendation']],
            'explanation'       => $result['recommendation'],
        ]);

        echo json_encode([
            'ok'              => true,
            'risk_score'      => $result['risk_score'],
            'risk_level'      => $result['risk_level'],
            'risk_factors'    => $result['risk_factors'],
            'auto_flag'       => $result['auto_flag'],
            'compliance'      => $result['compliance'],
            'recommendation'  => $result['recommendation'],
        ]);
        break;

    // --------------------------------------------------------
    // 4. VIOLATION REASONS — Explain why a claim is non-compliant
    // --------------------------------------------------------
    case 'violation_reasons':
        $claim = [
            'id'           => intval($_POST['claim_id'] ?? 0),
            'category'     => trim($_POST['category'] ?? ''),
            'amount'       => floatval($_POST['amount'] ?? 0),
            'vendor'       => trim($_POST['vendor'] ?? ''),
            'expense_date' => trim($_POST['expense_date'] ?? ''),
            'receipt_path' => trim($_POST['receipt_path'] ?? ''),
            'has_receipt'  => !empty($_POST['has_receipt']) || !empty($_POST['receipt_path']),
            'created_by'   => trim($_POST['created_by'] ?? $username),
        ];

        // Load claim from DB if ID given
        if ($claim['id'] > 0) {
            $stmt = $conn->prepare("SELECT * FROM claims WHERE id = ?");
            $stmt->bind_param('i', $claim['id']);
            $stmt->execute();
            $dbClaim = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($dbClaim) {
                $claim = array_merge($claim, [
                    'category'     => $dbClaim['category'] ?? $claim['category'],
                    'amount'       => floatval($dbClaim['amount'] ?? $claim['amount']),
                    'vendor'       => $dbClaim['vendor'] ?? $claim['vendor'],
                    'expense_date' => $dbClaim['expense_date'] ?? $claim['expense_date'],
                    'receipt_path' => $dbClaim['receipt_path'] ?? '',
                    'created_by'   => $dbClaim['created_by'] ?? $claim['created_by'],
                    'has_receipt'  => !empty($dbClaim['receipt_path']),
                ]);
            }
        }

        $result = $engine->generateViolationReasons($claim);

        echo json_encode([
            'ok'                => true,
            'compliance_status' => $result['compliance_status'],
            'reasons'           => $result['reasons'],
            'summary'           => $result['summary'],
            'recommendations'   => $result['recommendations'],
        ]);
        break;

    // --------------------------------------------------------
    // 5. POLICY AUDIT — Run comprehensive audit
    // --------------------------------------------------------
    case 'run_audit':
        // Officers/Admin only
        if ($role === 'Regular') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Policy audit is restricted to Benefits Officers and Admins.']);
            exit;
        }

        $result = $engine->runPolicyAudit();

        // Log the audit
        $engine->logCheck([
            'checked_by'        => $username,
            'check_type'        => 'policy_audit',
            'compliance_status' => ($result['overall'] === 'ALL_PASS') ? 'COMPLIANT' : 'REQUIRES_REVIEW',
            'explanation'       => "Policy audit: {$result['passed']}/{$result['total_policies']} passed. Issues: " . implode('; ', $result['issues'] ?: ['None']),
        ]);

        echo json_encode([
            'ok'      => true,
            'audit'   => $result,
        ]);
        break;

    // --------------------------------------------------------
    // 6. ADMIN OVERRIDE — Override an AI decision
    // --------------------------------------------------------
    case 'override':
        // Officers/Admin only
        if ($role === 'Regular') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Only Benefits Officers or Admins can override AI decisions.']);
            exit;
        }

        $checkLogId = intval($_POST['check_log_id'] ?? 0);
        $reason     = trim($_POST['override_reason'] ?? '');

        if ($checkLogId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing check_log_id.']);
            exit;
        }
        if (empty($reason)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Override reason is required.']);
            exit;
        }

        $success = $engine->logOverride($checkLogId, $username, $reason);

        echo json_encode([
            'ok'      => $success,
            'message' => $success ? 'AI decision overridden successfully.' : 'Failed to override.',
        ]);
        break;

    // --------------------------------------------------------
    // 7. GET POLICY INFO — Return policy data for a category
    // --------------------------------------------------------
    case 'get_policy_info':
        $category = trim($_POST['category'] ?? $_GET['category'] ?? '');

        if (empty($category)) {
            // Return all policies
            $policies = $engine->getAllPolicies();
            $allRules = $engine->getAllRules();
            echo json_encode([
                'ok'       => true,
                'policies' => $policies,
                'rules'    => $allRules,
            ]);
        } else {
            $limit = $engine->getPolicyLimit($category);
            $rules = $engine->getRulesForCategory($category);
            echo json_encode([
                'ok'       => true,
                'category' => $category,
                'limit'    => $limit,
                'rules'    => $rules,
            ]);
        }
        break;

    // --------------------------------------------------------
    // 8. RECENT CHECK LOGS — Get recent policy check history
    // --------------------------------------------------------
    case 'recent_checks':
        if ($role === 'Regular') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Access denied.']);
            exit;
        }

        $limit = min(50, max(5, intval($_GET['limit'] ?? $_POST['limit'] ?? 10)));
        $stmt = $conn->prepare("SELECT * FROM policy_check_logs ORDER BY created_at DESC LIMIT ?");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $row['violations_json'] = $row['violations_json'] ? json_decode($row['violations_json'], true) : [];
            $row['recommendations_json'] = $row['recommendations_json'] ? json_decode($row['recommendations_json'], true) : [];
            $logs[] = $row;
        }
        $stmt->close();

        echo json_encode(['ok' => true, 'logs' => $logs]);
        break;

    // --------------------------------------------------------
    // DEFAULT — Invalid action
    // --------------------------------------------------------
    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid action. Supported: check_compliance, validate_draft, risk_score, violation_reasons, run_audit, override, get_policy_info, recent_checks']);
        break;
}
