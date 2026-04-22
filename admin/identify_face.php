<?php
// filepath: admin/identify_face.php
// Lightweight face identification endpoint for real-time employee recognition
// Accepts a face descriptor, matches against enrolled employees, returns identity
// Does NOT record attendance — used only for live preview identification

declare(strict_types=1);
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');

// Require logged-in user
if (!isset($_SESSION['username']) || empty($_SESSION['username'])) {
    ob_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Include database connection
$connFile = __DIR__ . '/../connection.php';
if (!file_exists($connFile)) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server config error']);
    exit;
}
require_once($connFile);

// Include encryption secret (optional)
$secretFile = '/home/hr3.viahale.com/public_html/secret.php';
if (file_exists($secretFile)) {
    @require_once($secretFile);
}

// === Helper: Euclidean distance ===
function euclidean_distance(array $a, array $b): float {
    if (count($a) !== count($b)) return INF;
    $sum = 0.0;
    for ($i = 0, $n = count($a); $i < $n; $i++) {
        $d = $a[$i] - $b[$i];
        $sum += $d * $d;
    }
    return sqrt($sum);
}

// === Helper: Decrypt payload ===
function decrypt_payload(string $payload_b64, string $keyBin) {
    $parts = explode(':', $payload_b64);
    if (count($parts) !== 2) return false;
    $iv = base64_decode($parts[0]);
    $cipher = base64_decode($parts[1]);
    if ($iv === false || $cipher === false) return false;
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $keyBin, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? false : $plain;
}

// === Configuration ===
$MATCH_THRESHOLD = 0.50;

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'DB error']);
        exit;
    }

    // Parse input
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Empty request']);
        exit;
    }

    $input = json_decode($raw, true);
    if (!is_array($input)) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    $descriptor = isset($input['descriptor']) && is_array($input['descriptor']) ? $input['descriptor'] : null;
    if (!$descriptor || count($descriptor) === 0) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Missing descriptor']);
        exit;
    }

    $probeArr = array_map('floatval', $descriptor);

    // Encryption key
    $keyBin = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '') ? hash('sha256', ENCRYPTION_KEY, true) : null;

    // Check which face columns exist
    $checkCol = function(string $col) use ($conn): bool {
        $stmt = $conn->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = ? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('s', $col);
        $stmt->execute();
        $res = $stmt->get_result();
        $has = (bool)$res->fetch_row();
        $stmt->close();
        return $has;
    };

    $selectCols = ['id', 'employee_id', 'fullname', 'profile_photo'];

    $hasEncrypted = $checkCol('face_template_encrypted');
    $hasPlain     = $checkCol('face_template');
    $hasEmbedding = $checkCol('face_embedding');

    if ($hasEncrypted) $selectCols[] = 'face_template_encrypted';
    if ($hasPlain)     $selectCols[] = 'face_template';
    if ($hasEmbedding) $selectCols[] = 'face_embedding';

    $sql = "SELECT " . implode(', ', $selectCols) . " FROM employees WHERE face_enrolled = 1 AND status = 'Active'";
    $rows = $conn->query($sql);

    if (!$rows || $rows->num_rows === 0) {
        ob_clean();
        echo json_encode(['success' => false, 'identified' => false, 'error' => 'No enrolled employees']);
        exit;
    }

    $bestMatch = null;
    $bestDist  = INF;

    while ($r = $rows->fetch_assoc()) {
        $tpl = null;

        // Try encrypted template first
        if ($hasEncrypted && !empty($r['face_template_encrypted']) && $keyBin) {
            $plain = decrypt_payload($r['face_template_encrypted'], $keyBin);
            if ($plain !== false) {
                $decoded = json_decode($plain, true);
                if (is_array($decoded) && count($decoded) > 0) {
                    $tpl = array_map('floatval', $decoded);
                }
            }
        }

        // Fallback: plain face_template
        if ($tpl === null && $hasPlain && !empty($r['face_template'])) {
            $decoded = json_decode($r['face_template'], true);
            if (is_array($decoded) && count($decoded) > 0) {
                $tpl = array_map('floatval', $decoded);
            }
        }

        // Fallback: face_embedding
        if ($tpl === null && $hasEmbedding && !empty($r['face_embedding'])) {
            $decoded = json_decode($r['face_embedding'], true);
            if (is_array($decoded) && count($decoded) > 0) {
                $tpl = array_map('floatval', $decoded);
            }
        }

        if (!is_array($tpl) || count($tpl) === 0) continue;
        if (count($tpl) !== count($probeArr)) continue;

        $dist = euclidean_distance($probeArr, $tpl);

        if ($dist < $bestDist) {
            $bestDist  = $dist;
            $bestMatch = [
                'employee_id'   => $r['employee_id'],
                'fullname'      => $r['fullname'],
                'profile_photo' => $r['profile_photo'] ?? null,
                'dist'          => $dist
            ];
        }
    }
    $rows->free();

    // Check threshold
    if ($bestMatch === null || $bestDist > $MATCH_THRESHOLD) {
        ob_clean();
        echo json_encode([
            'success'    => true,
            'identified' => false,
            'message'    => 'Face not recognized'
        ]);
        exit;
    }

    // Calculate match percentage (inverse of distance, capped)
    $matchScore = max(0, min(100, round((1 - $bestDist / $MATCH_THRESHOLD) * 100)));

    ob_clean();
    echo json_encode([
        'success'       => true,
        'identified'    => true,
        'employee_id'   => $bestMatch['employee_id'],
        'fullname'      => $bestMatch['fullname'],
        'profile_photo' => $bestMatch['profile_photo'],
        'match_score'   => $matchScore,
        'distance'      => round($bestDist, 4)
    ]);

} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
} catch (Error $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
?>
