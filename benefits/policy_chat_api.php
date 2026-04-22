<?php
/**
 * policy_chat_api.php
 * ============================================================
 * Natural Language Policy Q&A API
 * ============================================================
 * Handles chat-style questions about reimbursement policies.
 * Employees and officers can ask questions in natural language
 * and receive policy-based answers.
 *
 * Endpoints:
 *   POST action=ask       — Ask a policy question
 *   POST action=feedback  — Submit feedback on an answer
 *   GET  action=history   — Get chat history for current session
 * ============================================================
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-cache, no-store, must-revalidate");

session_start();
require_once(__DIR__ . '/../connection.php');
require_once(__DIR__ . '/policy_engine.php');

// Authentication: All logged-in users can ask policy questions
if (!isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized. Please log in.']);
    exit;
}

$username  = $_SESSION['username'];
$role      = $_SESSION['role'];
$sessionId = session_id();

// Initialize engine
try {
    $engine = new PolicyEngine($conn);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to initialize policy engine.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // --------------------------------------------------------
    // ASK — Natural language policy question
    // --------------------------------------------------------
    case 'ask':
        $question = trim($_POST['question'] ?? '');
        if (empty($question)) {
            echo json_encode(['ok' => false, 'error' => 'Please enter a question.']);
            exit;
        }

        // Rate limiting: max 30 questions per session per hour
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM policy_chat_logs WHERE session_id = ? AND asked_by = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        if ($stmt) {
            $stmt->bind_param('ss', $sessionId, $username);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (intval($row['cnt'] ?? 0) >= 30) {
                echo json_encode(['ok' => false, 'error' => 'You have reached the question limit. Please try again later.']);
                exit;
            }
        }

        // Get AI answer
        $result = $engine->answerPolicyQuestion($question);

        // Log the interaction
        $engine->logChat(
            $sessionId,
            $username,
            $question,
            $result['answer'],
            $result['matched_rules'],
            $result['confidence']
        );

        echo json_encode([
            'ok'            => true,
            'answer'        => $result['answer'],
            'confidence'    => $result['confidence'],
            'matched_rules' => $result['matched_rules'],
            'intent'        => $result['intent'] ?? null,
            'category'      => $result['category'] ?? null,
        ]);
        break;

    // --------------------------------------------------------
    // FEEDBACK — Rate an answer as helpful/not helpful
    // --------------------------------------------------------
    case 'feedback':
        $chatId  = intval($_POST['chat_id'] ?? 0);
        $helpful = isset($_POST['helpful']) ? intval($_POST['helpful']) : null;

        if ($chatId <= 0 || $helpful === null) {
            echo json_encode(['ok' => false, 'error' => 'Missing chat_id or helpful flag.']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE policy_chat_logs SET helpful = ? WHERE id = ? AND asked_by = ?");
        if ($stmt) {
            $stmt->bind_param('iis', $helpful, $chatId, $username);
            $stmt->execute();
            $stmt->close();
        }

        echo json_encode(['ok' => true, 'message' => 'Feedback recorded. Thank you!']);
        break;

    // --------------------------------------------------------
    // HISTORY — Get recent chat history for this session
    // --------------------------------------------------------
    case 'history':
        $limit = min(50, max(5, intval($_GET['limit'] ?? 20)));

        $stmt = $conn->prepare("SELECT id, question, answer, confidence, matched_rules, helpful, created_at FROM policy_chat_logs WHERE asked_by = ? ORDER BY created_at DESC LIMIT ?");
        if ($stmt) {
            $stmt->bind_param('si', $username, $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            $history = [];
            while ($row = $result->fetch_assoc()) {
                $row['matched_rules'] = $row['matched_rules'] ? json_decode($row['matched_rules'], true) : [];
                $history[] = $row;
            }
            $stmt->close();
            // Reverse so oldest first
            $history = array_reverse($history);
        } else {
            $history = [];
        }

        echo json_encode(['ok' => true, 'history' => $history]);
        break;

    // --------------------------------------------------------
    // SUGGESTED — Return suggested questions
    // --------------------------------------------------------
    case 'suggested':
        $suggestions = [
            'What is the maximum meal reimbursement?',
            'Do I need a receipt for travel claims?',
            'How long do I have to submit a medical claim?',
            'Can I reimburse meals during overtime?',
            'What categories are covered by the policy?',
            'How does the claim approval process work?',
            'What happens if my claim exceeds the limit?',
            'Are weekend expenses reimbursable?',
        ];
        echo json_encode(['ok' => true, 'suggestions' => $suggestions]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid action. Supported: ask, feedback, history, suggested']);
        break;
}
