<?php
// reextract_claim.php
// Re-run server-side extraction heuristics for an existing claim.
// Used by the UI "Re-extract AI" button. Returns updated nlp_suggestions and risk_score.
//
// Simple implementation: reads claim row, runs Tesseract quick text extraction if receipt exists,
// runs heuristics (amount/date/vendor), computes phash and duplicate checks and updates nlp_suggestions.
// Returns JSON { ok:true, nlp_suggestions: {...}, risk_score:0.12 }

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['username']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Benefits Officer') {
    http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
}
require_once(__DIR__ . '/../connection.php');

function json_exit($data,$code=200){ http_response_code($code); echo json_encode($data); exit; }
function run_tesseract_text(string $path): string { $t = trim(@shell_exec('which tesseract 2>/dev/null')); if (!$t) return ''; $cmd = escapeshellcmd($t).' '.escapeshellarg($path).' stdout -l eng 2>&1'; $out=@shell_exec($cmd); return $out?:''; }
function detect_amounts($text){ $out=[]; if (!$text) return []; preg_match_all('/[₱\$\€\£\₹]?\s?(\d{1,3}(?:[,\s]\d{3})*(?:\.\d{1,2})?)/u',$text,$m); if(!empty($m[1])){ foreach($m[1] as $v){ $v2=floatval(str_replace([',',' '],'',$v)); if($v2>0)$out[]=$v2; } } rsort($out); return $out; }
function detect_date($text){ if(!$text) return null; if(preg_match('/\b(\d{4}-\d{2}-\d{2})\b/',$text,$m)) return $m[1]; if(preg_match('/\b(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\b/',$text,$m)) return $m[1]; if(preg_match('/\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+\d{1,2}(?:,?\s*\d{4})?/i',$text,$m)){ $t=strtotime($m[0]); if($t) return date('Y-m-d',$t); return $m[0]; } return null; }
function detect_vendor($text){ if(!$text) return null; $lines = preg_split("/\r\n|\n|\r/",trim(substr($text,0,400))); for($i=0;$i<min(6,count($lines));$i++){ $l=trim($lines[$i]); if(strlen($l)<3) continue; if(!preg_match('/receipt|invoice|tax|tin|vat/i',$l)){ if(!preg_match('/^\d+$/',$l) && !preg_match('/^\d{1,2}[\/\-]/',$l)) return preg_replace('/[^A-Za-z0-9 \-&\.,]/','',$l); } } return null; }
function image_phash_file(string $path){ if(!is_file($path)) return null; if(extension_loaded('imagick')){ try{ $im=new Imagick($path); $im->setImageColorspace(Imagick::COLORSPACE_GRAY); $im->resizeImage(16,16,Imagick::FILTER_BOX,1); $pixels=$im->exportImagePixels(0,0,16,16,"I",Imagick::PIXEL_CHAR); $avg=array_sum($pixels)/count($pixels); $bits=''; foreach($pixels as $p)$bits.=($p>$avg)?'1':'0'; $hex=''; for($i=0;$i<strlen($bits);$i+=4)$hex.=dechex(bindec(substr($bits,$i,4))); return $hex;}catch(Exception$e){} } $data=@file_get_contents($path); if($data===false) return null; $img=@imagecreatefromstring($data); if(!$img) return null; $w=imagesx($img); $h=imagesy($img); $tmp=imagecreatetruecolor(16,16); imagecopyresampled($tmp,$img,0,0,0,0,16,16,$w,$h); $vals=[]; for($y=0;$y<16;$y++){ for($x=0;$x<16;$x++){ $rgb=imagecolorat($tmp,$x,$y); $r=($rgb>>16)&0xFF;$g=($rgb>>8)&0xFF;$b=$rgb&0xFF; $lum=(0.2126*$r+0.7152*$g+0.0722*$b); $vals[]=$lum; } } $avg=array_sum($vals)/count($vals); $bits=''; foreach($vals as $v)$bits.=($v>$avg)?'1':'0'; imagedestroy($tmp); imagedestroy($img); $hex=''; for($i=0;$i<strlen($bits);$i+=4)$hex.=dechex(bindec(substr($bits,$i,4))); return $hex; }

$claim_id = $_POST['claim_id'] ?? null;
if (!$claim_id) json_exit(['ok'=>false,'error'=>'claim_id required'], 400);

$claim_id = intval($claim_id);
if (!isset($conn) || !$conn) json_exit(['ok'=>false,'error'=>'db missing'],500);

// fetch claim
$stmt = $conn->prepare("SELECT id, receipt_path, nlp_suggestions FROM claims WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $claim_id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();
if (!$row) json_exit(['ok'=>false,'error'=>'claim not found'],404);

$receipt_path = $row['receipt_path'] ?? null;
$nlp_existing = [];
if (!empty($row['nlp_suggestions'])) {
    $tmp = json_decode($row['nlp_suggestions'], true);
    if (is_array($tmp)) $nlp_existing = $tmp;
}

// Attempt server OCR if receipt exists as file
$ocr_text = '';
if ($receipt_path && is_file(__DIR__ . '/' . $receipt_path)) {
    $ocr_text = run_tesseract_text(__DIR__ . '/' . $receipt_path);
}

// heuristics
$vendor = detect_vendor($ocr_text) ?? ($nlp_existing['vendor'] ?? null);
$amount = (detect_amounts($ocr_text)[0] ?? ($nlp_existing['amount'] ?? null));
$date = detect_date($ocr_text) ?? ($nlp_existing['date'] ?? null);
$category = $nlp_existing['category'] ?? null;
$source_snippet = substr($ocr_text,0,400);

// phash compute
$phash = null;
if ($receipt_path && is_file(__DIR__ . '/' . $receipt_path)) $phash = image_phash_file(__DIR__ . '/' . $receipt_path);

// duplicate checks (simple DB scan)
$duplicateMatches = [];
if ($phash && isset($conn)) {
    $stmt2 = $conn->prepare("SELECT id, phash, created_by, amount, expense_date, receipt_path FROM claims WHERE id != ? AND created_at > DATE_SUB(NOW(), INTERVAL 180 DAY)");
    $stmt2->bind_param('i', $claim_id);
    if ($stmt2->execute()) {
        $r2 = $stmt2->get_result();
        while ($rrow = $r2->fetch_assoc()) {
            if (!empty($rrow['phash'])) {
                // simple hex hamming (approx)
                $h = 0;
                $a = $phash; $b = $rrow['phash'];
                $len = min(strlen($a), strlen($b));
                for ($i=0;$i<$len;$i++) if ($a[$i] !== $b[$i]) $h++;
                $h += abs(strlen($a)-strlen($b));
                if ($h <= 6) $duplicateMatches[] = ['id'=>$rrow['id'],'reason'=>'phash_similar','hamming'=>$h,'receipt_path'=>$rrow['receipt_path'],'created_by'=>$rrow['created_by'],'amount'=>$rrow['amount'],'date'=>$rrow['expense_date']];
            }
        }
        $r2->free();
    }
    $stmt2->close();
}

// assemble nlp suggestions to store
$newnlp = array_merge($nlp_existing, [
    'vendor'=>$vendor,
    'amount'=>$amount,
    'date'=>$date,
    'category'=>$category,
    'confidence'=> $nlp_existing['confidence'] ?? 0.5,
    'source_snippet'=>$source_snippet,
    'phash'=>$phash,
    'duplicateMatches'=>$duplicateMatches
]);

$nlp_json = json_encode($newnlp, JSON_UNESCAPED_UNICODE);

// compute a simple risk score
$risk = 0;
if (!empty($newnlp['confidence'])) $risk += max(0, 1 - floatval($newnlp['confidence']));
if (!empty($duplicateMatches)) $risk += 0.6;
$risk_score = min(1.0, round($risk,2));

// update claim row
$update = $conn->prepare("UPDATE claims SET nlp_suggestions = ?, phash = ?, risk_score = ?, updated_at = NOW() WHERE id = ?");
$phash_db = $phash ?? null;
$update->bind_param('ssdi', $nlp_json, $phash_db, $risk_score, $claim_id);
if (!$update->execute()) {
    json_exit(['ok'=>false,'error'=>'update failed: '.$update->error],500);
}
$update->close();

json_exit(['ok'=>true,'nlp_suggestions'=>json_decode($nlp_json,true),'risk_score'=>$risk_score]);