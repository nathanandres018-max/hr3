<?php
// filepath: admin/duplicate_scan.php
// JSON API to scan enrolled employees' templates and return similar pairs.
// Requires HR3 Admin session. Decrypts encrypted templates when ENCRYPTION_KEY exists.
// Query params:
//  - threshold (float, default 0.6) — only return pairs with dist <= threshold
//  - limit (int, default 200) — max number of pairs returned
//  - top (int, optional) — return top N closest pairs instead of threshold-based
// Response: { pairs: [ { emp_a, name_a, emp_b, name_b, dist } ... ], meta: {...} }

declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['username']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'HR3 Admin') {
    http_response_code(401);
    echo json_encode(['error'=>'unauthorized']);
    exit;
}

require_once(__DIR__ . '/../connection.php');
@require_once('/home/hr3.viahale.com/public_html/secret.php');

function euclidean_distance(array $a, array $b) {
    if (count($a)!==count($b)) return INF;
    $sum=0.0;
    for ($i=0,$n=count($a);$i<$n;$i++){ $d=$a[$i]-$b[$i]; $sum += $d*$d; }
    return sqrt($sum);
}

$threshold = isset($_GET['threshold']) ? (float)$_GET['threshold'] : 0.6;
$limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 200;
$top = isset($_GET['top']) ? max(1, (int)$_GET['top']) : null;

if (!isset($conn) || !($conn instanceof mysqli)) { http_response_code(500); echo json_encode(['error'=>'db']); exit; }

// collect templates
$templates = [];
$keyBin = (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '') ? hash('sha256', ENCRYPTION_KEY, true) : null;

// attempt to use face_template_encrypted first
$sql = "SELECT employee_id, fullname, face_template_encrypted, face_embedding, face_template FROM employees WHERE face_enrolled = 1";
$res = $conn->query($sql);
if (!$res) { http_response_code(500); echo json_encode(['error'=>'db_query']); exit; }

while ($r = $res->fetch_assoc()) {
    $emp = $r['employee_id'];
    $name = $r['fullname'];
    $tpl = null;
    if (!empty($r['face_template_encrypted']) && $keyBin) {
        $parts = explode(':', $r['face_template_encrypted']);
        if (count($parts) === 2) {
            $iv = base64_decode($parts[0]); $cipher = base64_decode($parts[1]);
            if ($iv !== false && $cipher !== false) {
                $plain = openssl_decrypt($cipher, 'AES-256-CBC', $keyBin, OPENSSL_RAW_DATA, $iv);
                if ($plain !== false) {
                    $decoded = json_decode($plain, true);
                    if (is_array($decoded)) $tpl = array_map('floatval', $decoded);
                }
            }
        }
    }
    // fallback to face_template (plain) or face_embedding
    if ($tpl === null && !empty($r['face_template'])) {
        $decoded = json_decode($r['face_template'], true);
        if (is_array($decoded)) $tpl = array_map('floatval', $decoded);
    }
    if ($tpl === null && !empty($r['face_embedding'])) {
        $decoded = json_decode($r['face_embedding'], true);
        if (is_array($decoded)) $tpl = array_map('floatval', $decoded);
    }
    if (is_array($tpl) && count($tpl) > 0) {
        $templates[] = ['employee_id'=>$emp,'fullname'=>$name,'template'=>$tpl];
    }
}
$res->free();

$n = count($templates);
$pairs = [];

if ($n < 2) {
    echo json_encode(['pairs'=>[],'meta'=>['total_templates'=>$n]]);
    exit;
}

// compute pairwise distances (triangular)
for ($i=0;$i<$n;$i++) {
    for ($j=$i+1;$j<$n;$j++) {
        $a = $templates[$i]['template'];
        $b = $templates[$j]['template'];
        if (count($a) !== count($b)) continue;
        $dist = euclidean_distance($a, $b);
        $pairs[] = [
            'emp_a' => $templates[$i]['employee_id'],
            'name_a' => $templates[$i]['fullname'],
            'emp_b' => $templates[$j]['employee_id'],
            'name_b' => $templates[$j]['fullname'],
            'dist' => $dist
        ];
    }
}

// sort ascending by distance (closest first)
usort($pairs, function($x,$y){ return ($x['dist'] <=> $y['dist']); });

if ($top !== null) {
    $pairs = array_slice($pairs, 0, $top);
} else {
    // filter by threshold and apply limit
    $pairs = array_filter($pairs, function($p) use ($threshold){ return $p['dist'] <= $threshold; });
    $pairs = array_slice($pairs, 0, $limit);
}

echo json_encode(['pairs'=>array_values($pairs),'meta'=>['total_templates'=>$n,'returned'=>count($pairs),'threshold'=>$threshold,'limit'=>$limit,'top'=>$top]]);
exit;
?>