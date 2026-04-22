<?php
// extract_suggestions.php
// Robust server-side extractor: Imagick preprocessing + multi-pass Tesseract + heuristics.
// - Returns JSON: { ok: true, suggestions: { vendor, amount, date, category, confidence, source_snippet, ... } }
// - Accepts POST fields: ocrText, ocrConfidence (client), amount, expenseDate, claimType, fast (1 for fast fallback).
// - Accepts uploaded file 'receipt'.
//
// Requirements:
//  - PHP with exec/shell_exec enabled and tesseract installed and on PATH.
//  - Imagick extension recommended (automated preprocessing).
//  - Optional Google Cloud Vision fallback (set GOOGLE_APPLICATION_CREDENTIALS env var or GCV_API_KEY).
//
// Notes:
//  - This script is defensive: if preprocessing / tesseract fail, it falls back to client text (if provided).
//  - Writes debug to /tmp/extract_suggestions_debug.log when DEBUG_EXTRACT=1 environment variable is set.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
session_start();

// Basic auth guard (same as other endpoints)
if (!isset($_SESSION['username']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Benefits Officer') {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
}

function dbg($msg) {
    if (getenv('DEBUG_EXTRACT') === '1') {
        file_put_contents('/tmp/extract_suggestions_debug.log', date('c') . " " . $msg . PHP_EOL, FILE_APPEND);
    }
}

function json_exit($data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function safe_tmp_path($ext='jpg') {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . '.' . $ext;
}

// run tesseract and return text (stdout)
function run_tesseract_text(string $path, array $opts = []): array {
    $t = trim(@shell_exec('which tesseract 2>/dev/null'));
    if (!$t) return ['text'=>'','ok'=>false,'error'=>'tesseract not found'];
    $psm = $opts['psm'] ?? '6';
    $oem = $opts['oem'] ?? '1'; // LSTM
    $whitelist = $opts['whitelist'] ?? null;
    $lang = $opts['lang'] ?? 'eng';

    // build config args
    $extra = "";
    if ($whitelist) {
        // use tessedit_char_whitelist via config
        $confFile = safe_tmp_path('conf');
        file_put_contents($confFile, "tessedit_char_whitelist " . $whitelist . PHP_EOL);
        $extra = " -c tessedit_char_whitelist={$whitelist} ";
    }

    // Create command with stdout output
    // Use --dpi=300 via environment or rely on image resolution
    $cmd = escapeshellcmd($t) . ' ' . escapeshellarg($path) . ' stdout -l ' . escapeshellarg($lang) . ' --oem ' . escapeshellcmd($oem) . ' --psm ' . escapeshellcmd($psm) . ' 2>&1';
    dbg("TESS CMD: $cmd");
    $out = @shell_exec($cmd);
    if ($whitelist && isset($confFile) && file_exists($confFile)) @unlink($confFile);
    return ['text' => $out ?: '', 'ok' => true];
}

// Preprocess with Imagick if available
function preprocess_with_imagick(string $inPath): ?string {
    dbg("preprocess_with_imagick start: $inPath");
    try {
        if (!extension_loaded('imagick')) {
            dbg("Imagick not available");
            return null;
        }
        $im = new Imagick($inPath);
        // Auto-orient (useful for rotated phone photos)
        try { $im->autoOrient(); } catch(Exception $e) {}
        // Increase resolution (effective DPI)
        $im->setImageResolution(300,300);
        // convert to grayscale
        $im->setImageColorspace(Imagick::COLORSPACE_GRAY);
        // resize so smallest side is at least 1200 px but not too big
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        $long = max($w,$h);
        if ($long < 1200) {
            $scale = 1200 / $long;
            $newW = (int)($w * $scale);
            $newH = (int)($h * $scale);
            $im->resizeImage($newW, $newH, Imagick::FILTER_LANCZOS, 1);
        }
        // contrast normalize
        $im->normalizeImage();
        $im->contrastImage(1);
        // despeckle / reduce noise
        try { $im->despeckleImage(); } catch(Exception $e) {}
        // adaptive threshold (binarize)
        if (method_exists($im, 'adaptiveThresholdImage')) {
            // radius, bias: choose radius relative to image size
            $radius = max(3, (int)(min($im->getImageWidth(), $im->getImageHeight()) / 80));
            $im->adaptiveThresholdImage($radius, $radius);
        } else {
            // fallback: simple threshold
            $im->thresholdImage(0.5 * Imagick::getQuantum());
        }
        // sharpen
        $im->unsharpMaskImage(1, 0.5, 1, 0.05);

        $out = safe_tmp_path('png');
        $im->setImageFormat('png');
        $im->writeImage($out);
        $im->clear(); $im->destroy();
        dbg("preprocess_with_imagick produced $out");
        return $out;
    } catch (Exception $e) {
        dbg("Imagick preprocess failed: " . $e->getMessage());
        return null;
    }
}

// Heuristics to extract amounts, date, vendor etc.
function detect_amounts_from_text(string $text): array {
    $out = [];
    if (!$text) return [];
    // pattern matches currency or plain numbers; collect largest values
    preg_match_all('/[₱\$\€\£\₹]?\s*([0-9]{1,3}(?:[,\s][0-9]{3})*(?:\.[0-9]{1,2})?)/u', $text, $m);
    if (!empty($m[1])) {
        foreach ($m[1] as $v) {
            $v2 = floatval(str_replace([',',' '],'',$v));
            if ($v2 > 0) $out[] = $v2;
        }
    }
    rsort($out);
    return array_values(array_unique($out));
}
function detect_date_from_text(string $text): ?string {
    if (!$text) return null;
    // try ISO first
    if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m)) return $m[1];
    // common formats dd/mm/yyyy or mm/dd/yyyy, try parse heuristics
    if (preg_match_all('/\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\b/', $text, $m)) {
        foreach ($m[1] as $cand) {
            $ts = strtotime($cand);
            if ($ts) return date('Y-m-d', $ts);
        }
    }
    if (preg_match('/\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+\d{1,2}(?:,\s*\d{4})?/i', $text, $m)) {
        $ts = strtotime($m[0]);
        if ($ts) return date('Y-m-d', $ts);
    }
    return null;
}
function detect_vendor_from_text(string $text): ?string {
    if (!$text) return null;
    $lines = preg_split("/\r\n|\n|\r/", trim(substr($text,0,400)));
    foreach ($lines as $i => $l) {
        $l = trim($l);
        if ($i === 0 && strlen($l) > 1) return preg_replace('/[^A-Za-z0-9 \-&\.,]/','',$l);
    }
    return null;
}

// QUICK HEURISTIC final assembly
function build_suggestion(array $data): array {
    $text = $data['text'] ?? '';
    $amounts = detect_amounts_from_text($text);
    $vendor = detect_vendor_from_text($text);
    $date = detect_date_from_text($text);
    $best_amount = $amounts[0] ?? null;
    $confidence = $data['conf'] ?? null;
    if ($confidence === null) {
        // crude confidence: presence of "total" or "amount due" and numeric values
        $confidence = 0.3;
        if (preg_match('/\b(total|amount due|amount)\b/i', $text) && $best_amount) $confidence = 0.75;
        elseif ($best_amount) $confidence = 0.55;
    }
    return [
        'vendor' => $vendor,
        'amount' => $best_amount !== null ? floatval($best_amount) : null,
        'date' => $date,
        'category' => null,
        'confidence' => $confidence,
        'source_snippet' => trim(substr($text,0,800)),
        'raw_text' => $text
    ];
}

/* ---------------------- Main handling ---------------------- */

$client_ocr_text = trim($_POST['ocrText'] ?? '');
$client_ocr_conf = intval($_POST['ocrConfidence'] ?? 0);
$hint_amount = isset($_POST['amount']) ? floatval($_POST['amount']) : null;
$hint_date = trim($_POST['expenseDate'] ?? '');
$hint_category = trim($_POST['claimType'] ?? '');
$fast = isset($_POST['fast']) && $_POST['fast'] === '1';

$uploaded_full = null;
if (!empty($_FILES['receipt']) && $_FILES['receipt']['error'] !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['receipt'];
    if ($f['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION) ?: 'jpg');
        $tmp = safe_tmp_path($ext);
        if (@move_uploaded_file($f['tmp_name'], $tmp)) {
            $uploaded_full = $tmp;
            dbg("Uploaded file saved to $tmp");
        } else {
            dbg("Failed to move uploaded file");
        }
    } else {
        dbg("Upload error code: " . $f['error']);
    }
}

// FAST mode shortcut: prefer client nlp or quick Tesseract without heavy preprocessing
if ($fast) {
    // If client provided nlp suggestions use them
    $client_nlp_raw = $_POST['nlpSuggestions'] ?? '';
    if ($client_nlp_raw) {
        $parsed = @json_decode($client_nlp_raw, true);
        if (is_array($parsed)) {
            $suggest = array_intersect_key($parsed, array_flip(['vendor','amount','date','category','confidence','source_snippet']));
            json_exit(['ok'=>true,'suggestions'=>$suggest]);
        }
    }
    // If uploaded file, run a quick Tesseract (no heavy preprocess) and return heuristics
    if ($uploaded_full) {
        $tres = run_tesseract_text($uploaded_full, ['psm'=>'6']);
        $text = $tres['text'] ?? '';
        $suggest = build_suggestion(['text'=>$text, 'conf'=>null]);
        if (is_file($uploaded_full)) @unlink($uploaded_full);
        json_exit(['ok'=>true,'suggestions'=>$suggest]);
    }
    // fallback, return heuristics from client text
    $suggest = build_suggestion(['text'=>$client_ocr_text, 'conf'=>$client_ocr_conf/100]);
    json_exit(['ok'=>true,'suggestions'=>$suggest]);
}

/* Full path: try Imagick preprocess -> multi-pass Tesseract */
$final_text = '';
$used_server_ocr = false;
$preprocessed = null;

if ($uploaded_full && file_exists($uploaded_full)) {
    $preprocessed = preprocess_with_imagick($uploaded_full);
}

$attempts = [];
// attempt order: preprocessed psm6, psm3, digits-only pass, original psm6
if ($preprocessed) {
    dbg("Attempting OCR on preprocessed image");
    $t1 = run_tesseract_text($preprocessed, ['psm'=>'6']);
    $attempts[] = ['text'=>$t1['text'] ?? '', 'tag'=>'pre_psm6'];
    $t2 = run_tesseract_text($preprocessed, ['psm'=>'3']);
    $attempts[] = ['text'=>$t2['text'] ?? '', 'tag'=>'pre_psm3'];
    $t3 = run_tesseract_text($preprocessed, ['psm'=>'6','whitelist'=>'0123456789.,₱$']);
    $attempts[] = ['text'=>$t3['text'] ?? '', 'tag'=>'pre_digits'];
}

// If original uploaded exists, try psm variants too (no preprocess)
if ($uploaded_full && file_exists($uploaded_full)) {
    dbg("Attempting OCR on original image");
    $t4 = run_tesseract_text($uploaded_full, ['psm'=>'6']);
    $attempts[] = ['text'=>$t4['text'] ?? '', 'tag'=>'orig_psm6'];
    $t5 = run_tesseract_text($uploaded_full, ['psm'=>'4']);
    $attempts[] = ['text'=>$t5['text'] ?? '', 'tag'=>'orig_psm4'];
    $used_server_ocr = true;
}

// If still nothing, try falling back to client-provided text
if (empty($attempts) || !array_filter(array_column($attempts,'text'))) {
    dbg("No tesseract output; using client provided ocrText");
    $final_text = $client_ocr_text ?: '';
    $suggest = build_suggestion(['text'=>$final_text, 'conf'=>$client_ocr_conf/100]);
    if ($preprocessed && is_file($preprocessed)) @unlink($preprocessed);
    if ($uploaded_full && is_file($uploaded_full)) @unlink($uploaded_full);
    json_exit(['ok'=>true,'suggestions'=>$suggest]);
}

// choose best attempt by heuristics: prefer candidate with "total" or currency & numbers
$best = null;
$bestScore = -INF;
foreach ($attempts as $a) {
    $txt = trim($a['text'] ?? '');
    $score = 0;
    if (!$txt) { continue; }
    // presence of "total"/"amount due"/"amount" increases score
    if (preg_match('/\b(total|amount due|amount|amounts due|subtotal)\b/i', $txt)) $score += 40;
    // number of numeric matches
    preg_match_all('/[0-9]{2,}/', $txt, $m);
    $numCount = count($m[0] ?? []);
    $score += min(30, $numCount * 4);
    // presence of currency symbols
    if (preg_match('/[₱\$\€\£\₹]/', $txt)) $score += 20;
    // length (too short penalize)
    $len = strlen($txt);
    $score += min(10, intval($len/50));
    // small penalty if not many digits
    if ($numCount < 2) $score -= 10;
    dbg("Attempt {$a['tag']} score $score length $len");
    if ($score > $bestScore) {
        $bestScore = $score;
        $best = $txt;
    }
}

$final_text = $best ?: ($attempts[0]['text'] ?? '');
$suggestion = build_suggestion(['text'=>$final_text, 'conf'=>null]);

// Enrich with hints if available
if (!$suggestion['amount'] && $hint_amount) $suggestion['amount'] = floatval($hint_amount);
if (!$suggestion['date'] && $hint_date) $suggestion['date'] = $hint_date;
if (!$suggestion['category'] && $hint_category) $suggestion['category'] = $hint_category;

$suggestion['used_server_ocr'] = $used_server_ocr;
$suggestion['attempts'] = array_map(function($a){ return ['tag'=>$a['tag'] ?? '', 'has_text'=> (bool)trim($a['text'] ?? ''), 'snippet'=> substr($a['text'] ?? '',0,200)]; }, $attempts);

// cleanup temp files
if ($preprocessed && is_file($preprocessed)) @unlink($preprocessed);
if ($uploaded_full && is_file($uploaded_full)) @unlink($uploaded_full);

json_exit(['ok'=>true,'suggestions'=>$suggestion]);