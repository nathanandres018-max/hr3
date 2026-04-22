<?php
/**
 * verification_engine.php
 * ──────────────────────────────────────────────────────────
 * Core AI-Assisted Claim Verification Engine
 * ──────────────────────────────────────────────────────────
 *
 * Compares employee-submitted claim details against data
 * extracted from receipt images via OCR.  Produces a weighted
 * overall verification score and determines a status:
 *
 *   VERIFIED  (≥ 85)  – auto-approve candidate
 *   FLAGGED   (≥ 50)  – needs human review
 *   REJECTED  (< 50)  – significant discrepancies
 *
 * Checks performed:
 *   1. Amount match   (30% weight)
 *   2. Vendor match   (20% weight)
 *   3. Date match     (15% weight)
 *   4. Category match (10% weight)
 *   5. Receipt quality (10% weight)
 *   6. Duplicate check (10% weight)
 *   7. OCR confidence  (5% weight)
 *
 * Dependencies:
 *   - receipt_ocr.php  (ReceiptOCR class)
 *   - audit_logger.php (ClaimAuditLogger class, optional)
 *
 * Usage:
 *   require_once 'verification_engine.php';
 *   $engine = new ClaimVerificationEngine($conn);
 *   $result = $engine->verifyClaim($claimId);
 */

require_once __DIR__ . '/receipt_ocr.php';

class ClaimVerificationEngine
{
    /** @var mysqli */
    private $conn;

    /** @var ReceiptOCR */
    private ReceiptOCR $ocr;

    /** @var string  Current user performing the verification */
    private string $verifiedBy;

    /** @var string  Upload base directory for receipt files */
    private string $uploadDir;

    // ─── Score weights (must sum to 1.0) ───
    private const WEIGHTS = [
        'amount_match'     => 0.30,
        'vendor_match'     => 0.20,
        'date_match'       => 0.15,
        'category_match'   => 0.10,
        'receipt_quality'  => 0.10,
        'duplicate_check'  => 0.10,
        'ocr_confidence'   => 0.05,
    ];

    // ─── Status thresholds ───
    private const THRESHOLD_VERIFIED = 85;
    private const THRESHOLD_FLAGGED  = 50;

    // ─── Amount tolerance bands ───
    private const AMOUNT_EXACT_PCT   = 2;    // Within 2% → perfect
    private const AMOUNT_CLOSE_PCT   = 5;    // Within 5% → close
    private const AMOUNT_WARN_PCT    = 15;   // Within 15% → warning
    private const AMOUNT_FAIL_PCT    = 30;   // Beyond 30% → fail

    // ─── Date tolerance (days) ───
    private const DATE_EXACT_DAYS   = 0;
    private const DATE_CLOSE_DAYS   = 1;
    private const DATE_WARN_DAYS    = 7;
    private const DATE_FAIL_DAYS    = 30;

    // ─── Category compatibility groups ───
    private const CATEGORY_GROUPS = [
        'meal'           => ['meal', 'food', 'restaurant', 'cafe', 'lunch', 'dinner', 'breakfast', 'dining', 'catering', 'snack', 'beverage'],
        'travel'         => ['travel', 'taxi', 'flight', 'transport', 'hotel', 'transportation', 'uber', 'grab', 'fare', 'gas', 'fuel', 'parking', 'toll'],
        'medical'        => ['medical', 'health', 'pharmacy', 'clinic', 'hospital', 'medicine', 'doctor', 'dental', 'optical', 'healthcare'],
        'supplies'       => ['supplies', 'office', 'stationery', 'equipment', 'hardware', 'tools', 'materials'],
        'training'       => ['training', 'seminar', 'workshop', 'conference', 'education', 'certification', 'course'],
        'accommodation'  => ['accommodation', 'hotel', 'lodging', 'airbnb', 'inn', 'boarding'],
        'communication'  => ['communication', 'phone', 'internet', 'mobile', 'data', 'telecom', 'postage'],
    ];

    // ─── Duplicate detection ───
    private const PHASH_THRESHOLD = 6;  // Hamming distance ≤ 6 = likely same image


    public function __construct($conn, string $verifiedBy = '')
    {
        $this->conn = $conn;
        $this->ocr = new ReceiptOCR();
        $this->verifiedBy = $verifiedBy;
        $this->uploadDir = __DIR__ . '/uploads/claims/';
    }

    // ══════════════════════════════════════════════
    //  PUBLIC API
    // ══════════════════════════════════════════════

    /**
     * Run complete verification on a claim.
     *
     * @param  int   $claimId
     * @param  bool  $forceReOCR  Re-run OCR even if data already exists
     * @return array  Full verification result
     */
    public function verifyClaim(int $claimId, bool $forceReOCR = false): array
    {
      try {
        // 1. Fetch claim from DB
        $claim = $this->fetchClaim($claimId);
        if (!$claim) {
            return $this->errorResult('Claim not found', $claimId);
        }

        // 2. Get or run OCR on the receipt
        $ocrData = $this->getOCRData($claim, $forceReOCR);

        // 3. Run all verification checks
        $checks = [
            'amount_match'    => $this->verifyAmount($claim, $ocrData),
            'vendor_match'    => $this->verifyVendor($claim, $ocrData),
            'date_match'      => $this->verifyDate($claim, $ocrData),
            'category_match'  => $this->verifyCategory($claim, $ocrData),
            'receipt_quality' => $this->verifyReceiptQuality($ocrData),
            'duplicate_check' => $this->checkDuplicates($claim, $ocrData),
            'ocr_confidence'  => $this->assessOCRConfidence($ocrData),
        ];

        // 4. Calculate weighted overall score
        $overallScore = $this->calculateScore($checks);

        // 5. Determine status
        $status = $this->determineStatus($overallScore, $checks);

        // 6. Detect anomalies
        $anomalies = $this->detectAnomalies($claim, $ocrData, $checks);

        // 7. Build receipt analysis summary
        $receiptAnalysis = $this->buildReceiptAnalysis($ocrData);

        // 8. Log verification to DB (non-critical, wrapped safely)
        try { $this->logVerification($claimId, $overallScore, $status, $checks, $ocrData); } catch (\Throwable $e) { /* skip logging */ }

        // 9. Update claim verification columns (non-critical, wrapped safely)
        try { $this->updateClaimVerification($claimId, $overallScore, $status); } catch (\Throwable $e) { /* skip update */ }

        return [
            'success'          => true,
            'claim_id'         => $claimId,
            'overall_score'    => round($overallScore, 1),
            'status'           => $status,
            'verifications'    => $checks,
            'receipt_analysis' => $receiptAnalysis,
            'anomalies'        => $anomalies,
            'claim_data'       => [
                'amount'       => floatval($claim['amount'] ?? 0),
                'category'     => $claim['category'] ?? 'Unknown',
                'vendor'       => $claim['vendor'] ?? 'Not specified',
                'expense_date' => $claim['expense_date'] ?? 'N/A',
                'description'  => $claim['description'] ?? '',
                'status'       => $claim['status'] ?? 'pending',
            ],
            'nlp_data'      => $ocrData['structured'] ?? [],
            'timestamp'     => date('Y-m-d H:i:s'),
        ];
      } catch (\Throwable $e) {
          return $this->errorResult('Engine error: ' . $e->getMessage(), $claimId);
      }
    }

    // ══════════════════════════════════════════════
    //  DATA RETRIEVAL
    // ══════════════════════════════════════════════

    /**
     * Fetch claim row from database.
     */
    private function fetchClaim(int $claimId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT id, amount, category, vendor, expense_date, description,
                    receipt_path, nlp_suggestions, ocr_text, ocr_confidence,
                    risk_score, receipt_validity, tamper_evidence, phash,
                    ai_raw, created_by, status, created_at
             FROM claims WHERE id = ? LIMIT 1"
        );
        if (!$stmt) return null;

        $stmt->bind_param('i', $claimId);
        $stmt->execute();
        $result = $stmt->get_result();
        $claim = $result->fetch_assoc();
        $stmt->close();

        return $claim ?: null;
    }

    /**
     * Get OCR data — either from existing claim fields or by running OCR.
     */
    private function getOCRData(array $claim, bool $forceReOCR): array
    {
        $hasExistingOCR = !empty($claim['ocr_text']) && !$forceReOCR;

        // Try to use existing NLP/OCR data from the claim
        if ($hasExistingOCR) {
            $nlp = [];
            if (!empty($claim['nlp_suggestions'])) {
                $nlp = json_decode($claim['nlp_suggestions'], true) ?? [];
            }

            return [
                'source'     => 'existing',
                'raw_text'   => $claim['ocr_text'] ?? '',
                'structured' => [
                    'vendor'     => $nlp['vendor'] ?? null,
                    'amount'     => isset($nlp['amount']) ? floatval($nlp['amount']) : null,
                    'date'       => $nlp['date'] ?? null,
                    'receipt_no' => $nlp['receipt_no'] ?? null,
                    'subtotal'   => isset($nlp['subtotal']) ? floatval($nlp['subtotal']) : null,
                    'tax'        => isset($nlp['tax']) ? floatval($nlp['tax']) : null,
                    'items'      => $nlp['items'] ?? [],
                    'amounts_found' => $nlp['amounts'] ?? [],
                ],
                'ocr_confidence'        => floatval($claim['ocr_confidence'] ?? 0),
                'extraction_confidence' => floatval($nlp['confidence'] ?? $claim['ocr_confidence'] ?? 0),
                'image_quality' => [
                    'score' => 0.7,
                    'level' => 'unknown',
                    'issues' => [],
                ],
                'file_hash'   => null,
                'phash'       => $claim['phash'] ?? null,
                'tesseract_available' => $this->ocr->isTesseractAvailable(),
            ];
        }

        // Run fresh OCR on the receipt image
        $receiptPath = $this->resolveReceiptPath($claim['receipt_path'] ?? '');

        if (!$receiptPath || !file_exists($receiptPath)) {
            return [
                'source'     => 'none',
                'raw_text'   => '',
                'structured' => [
                    'vendor' => null, 'amount' => null, 'date' => null,
                    'receipt_no' => null, 'subtotal' => null, 'tax' => null,
                    'items' => [], 'amounts_found' => [],
                ],
                'ocr_confidence' => 0,
                'extraction_confidence' => 0,
                'image_quality' => ['score' => 0, 'level' => 'none', 'issues' => ['No receipt image found']],
                'file_hash' => null,
                'phash' => null,
                'tesseract_available' => $this->ocr->isTesseractAvailable(),
                'error' => 'Receipt file not found',
            ];
        }

        // Run OCR
        $ocrResult = $this->ocr->processReceipt($receiptPath);

        if ($ocrResult['success']) {
            // Save OCR results back to the claim
            $this->saveOCRResults($claim['id'], $ocrResult);
        }

        $ocrResult['source'] = 'fresh_ocr';
        return $ocrResult;
    }

    /**
     * Resolve receipt path from the stored value.
     */
    private function resolveReceiptPath(string $storedPath): ?string
    {
        if (empty($storedPath)) return null;

        // If it's already an absolute path
        if (file_exists($storedPath)) return $storedPath;

        // Try relative to upload dir
        $candidate = $this->uploadDir . basename($storedPath);
        if (file_exists($candidate)) return $candidate;

        // Try relative to benefits dir
        $candidate = __DIR__ . '/' . $storedPath;
        if (file_exists($candidate)) return $candidate;

        // Try relative to public_html
        $candidate = __DIR__ . '/../' . $storedPath;
        if (file_exists($candidate)) return $candidate;

        return null;
    }

    /**
     * Save OCR results back to the claim record.
     */
    private function saveOCRResults(int $claimId, array $ocrResult): void
    {
        $structured = $ocrResult['structured'] ?? [];

        $nlpJson = json_encode([
            'vendor'     => $structured['vendor'] ?? null,
            'amount'     => $structured['amount'] ?? null,
            'date'       => $structured['date'] ?? null,
            'receipt_no' => $structured['receipt_no'] ?? null,
            'subtotal'   => $structured['subtotal'] ?? null,
            'tax'        => $structured['tax'] ?? null,
            'items'      => $structured['items'] ?? [],
            'amounts'    => $structured['amounts_found'] ?? [],
            'confidence' => $ocrResult['extraction_confidence'] ?? 0,
        ], JSON_UNESCAPED_UNICODE);

        $ocrText = $ocrResult['raw_text'] ?? '';
        $ocrConfidence = $ocrResult['ocr_confidence'] ?? 0;
        $phash = $ocrResult['phash'] ?? null;

        $stmt = $this->conn->prepare(
            "UPDATE claims 
             SET ocr_text = ?, ocr_confidence = ?, nlp_suggestions = ?, phash = ?
             WHERE id = ?"
        );
        if ($stmt) {
            $stmt->bind_param('sdssi', $ocrText, $ocrConfidence, $nlpJson, $phash, $claimId);
            $stmt->execute();
            $stmt->close();
        }
    }

    // ══════════════════════════════════════════════
    //  VERIFICATION CHECKS
    // ══════════════════════════════════════════════

    /**
     * 1. Amount verification — compare submitted vs extracted amount.
     */
    private function verifyAmount(array $claim, array $ocrData): array
    {
        $submitted = floatval($claim['amount'] ?? 0);
        $extracted = floatval($ocrData['structured']['amount'] ?? 0);

        if ($submitted <= 0) {
            return ['status' => 'warning', 'message' => 'No amount submitted', 'confidence' => 0.3,
                    'submitted' => $submitted, 'extracted' => $extracted];
        }

        if ($extracted <= 0) {
            return ['status' => 'warning', 'message' => 'Could not extract amount from receipt', 'confidence' => 0.5,
                    'submitted' => $submitted, 'extracted' => $extracted];
        }

        $diffPct = abs($submitted - $extracted) / max($submitted, 0.01) * 100;

        if ($diffPct <= self::AMOUNT_EXACT_PCT) {
            return ['status' => 'pass', 'message' => 'Amount matches exactly', 'confidence' => 0.95,
                    'submitted' => $submitted, 'extracted' => $extracted, 'diff_pct' => round($diffPct, 1)];
        }
        if ($diffPct <= self::AMOUNT_CLOSE_PCT) {
            return ['status' => 'pass', 'message' => "Amount matches closely ({$this->fmt($diffPct)}% variance)", 'confidence' => 0.85,
                    'submitted' => $submitted, 'extracted' => $extracted, 'diff_pct' => round($diffPct, 1)];
        }
        if ($diffPct <= self::AMOUNT_WARN_PCT) {
            return ['status' => 'warning', 'message' => "Amount differs ({$this->fmt($diffPct)}% variance)", 'confidence' => 0.60,
                    'submitted' => $submitted, 'extracted' => $extracted, 'diff_pct' => round($diffPct, 1)];
        }
        if ($diffPct <= self::AMOUNT_FAIL_PCT) {
            return ['status' => 'fail', 'message' => "Amount significantly differs ({$this->fmt($diffPct)}% variance)", 'confidence' => 0.30,
                    'submitted' => $submitted, 'extracted' => $extracted, 'diff_pct' => round($diffPct, 1)];
        }

        return ['status' => 'fail', 'message' => "Amount does not match ({$this->fmt($diffPct)}% variance)", 'confidence' => 0.10,
                'submitted' => $submitted, 'extracted' => $extracted, 'diff_pct' => round($diffPct, 1)];
    }

    /**
     * 2. Vendor verification — compare submitted vs extracted vendor name.
     */
    private function verifyVendor(array $claim, array $ocrData): array
    {
        $submitted = strtolower(trim($claim['vendor'] ?? ''));
        $extracted = strtolower(trim($ocrData['structured']['vendor'] ?? ''));

        if (empty($submitted)) {
            return ['status' => 'warning', 'message' => 'No vendor name submitted', 'confidence' => 0.5];
        }
        if (empty($extracted)) {
            return ['status' => 'warning', 'message' => 'Could not extract vendor from receipt', 'confidence' => 0.5];
        }

        // Exact match
        if ($submitted === $extracted) {
            return ['status' => 'pass', 'message' => 'Vendor matches exactly', 'confidence' => 0.98,
                    'submitted' => $claim['vendor'], 'extracted' => $ocrData['structured']['vendor']];
        }

        // Substring match (one contains the other)
        if (strpos($submitted, $extracted) !== false || strpos($extracted, $submitted) !== false) {
            return ['status' => 'pass', 'message' => 'Vendor name contained in receipt text', 'confidence' => 0.90,
                    'submitted' => $claim['vendor'], 'extracted' => $ocrData['structured']['vendor']];
        }

        // Levenshtein similarity
        $distance = levenshtein($submitted, $extracted);
        $maxLen = max(strlen($submitted), strlen($extracted));
        $similarity = $maxLen > 0 ? (1 - ($distance / $maxLen)) : 0;

        if ($similarity > 0.85) {
            return ['status' => 'pass', 'message' => 'Vendor matches (' . round($similarity * 100) . '% similar)', 'confidence' => 0.90,
                    'submitted' => $claim['vendor'], 'extracted' => $ocrData['structured']['vendor']];
        }
        if ($similarity > 0.70) {
            return ['status' => 'pass', 'message' => 'Vendor close match (' . round($similarity * 100) . '% similar)', 'confidence' => 0.75,
                    'submitted' => $claim['vendor'], 'extracted' => $ocrData['structured']['vendor']];
        }
        if ($similarity > 0.50) {
            return ['status' => 'warning', 'message' => 'Vendor partial match (' . round($similarity * 100) . '% similar)', 'confidence' => 0.55,
                    'submitted' => $claim['vendor'], 'extracted' => $ocrData['structured']['vendor']];
        }

        // Also check if submitted vendor appears anywhere in raw OCR text
        $rawText = strtolower($ocrData['raw_text'] ?? '');
        if (!empty($rawText) && strpos($rawText, $submitted) !== false) {
            return ['status' => 'pass', 'message' => 'Vendor name found in receipt text', 'confidence' => 0.80,
                    'submitted' => $claim['vendor'], 'extracted' => $ocrData['structured']['vendor']];
        }

        return ['status' => 'fail', 'message' => 'Vendor does not match (' . round($similarity * 100) . '% similar)', 'confidence' => 0.20,
                'submitted' => $claim['vendor'], 'extracted' => $ocrData['structured']['vendor']];
    }

    /**
     * 3. Date verification — compare submitted expense date vs extracted receipt date.
     */
    private function verifyDate(array $claim, array $ocrData): array
    {
        $submitted = $claim['expense_date'] ?? '';
        $extracted = $ocrData['structured']['date'] ?? '';

        if (empty($submitted)) {
            return ['status' => 'warning', 'message' => 'No expense date submitted', 'confidence' => 0.5];
        }
        if (empty($extracted)) {
            return ['status' => 'warning', 'message' => 'Could not extract date from receipt', 'confidence' => 0.5];
        }

        try {
            $subDt = new DateTime($submitted);
            $extDt = new DateTime($extracted);
            $diff = abs($subDt->diff($extDt)->days);

            if ($diff === self::DATE_EXACT_DAYS) {
                return ['status' => 'pass', 'message' => 'Date matches exactly', 'confidence' => 0.95,
                        'submitted' => $submitted, 'extracted' => $extracted];
            }
            if ($diff <= self::DATE_CLOSE_DAYS) {
                return ['status' => 'pass', 'message' => "Date matches within {$diff} day(s)", 'confidence' => 0.90,
                        'submitted' => $submitted, 'extracted' => $extracted];
            }
            if ($diff <= self::DATE_WARN_DAYS) {
                return ['status' => 'warning', 'message' => "Date differs by {$diff} days", 'confidence' => 0.65,
                        'submitted' => $submitted, 'extracted' => $extracted];
            }
            if ($diff <= self::DATE_FAIL_DAYS) {
                return ['status' => 'fail', 'message' => "Date differs significantly ({$diff} days)", 'confidence' => 0.30,
                        'submitted' => $submitted, 'extracted' => $extracted];
            }

            return ['status' => 'fail', 'message' => "Date does not match ({$diff} days apart)", 'confidence' => 0.10,
                    'submitted' => $submitted, 'extracted' => $extracted];

        } catch (\Exception $e) {
            return ['status' => 'warning', 'message' => 'Could not parse date formats', 'confidence' => 0.5,
                    'submitted' => $submitted, 'extracted' => $extracted];
        }
    }

    /**
     * 4. Category verification — check if claim category matches receipt content.
     */
    private function verifyCategory(array $claim, array $ocrData): array
    {
        $submitted = strtolower(trim($claim['category'] ?? ''));
        if (empty($submitted)) {
            return ['status' => 'warning', 'message' => 'No category submitted', 'confidence' => 0.5];
        }

        $rawText = strtolower($ocrData['raw_text'] ?? '');

        // Check if any keywords for the submitted category appear in the OCR text
        $matchedGroup = null;
        $keywordMatches = 0;

        foreach (self::CATEGORY_GROUPS as $group => $keywords) {
            // Does the submitted category belong to this group?
            $submittedInGroup = false;
            foreach ($keywords as $kw) {
                if (strpos($submitted, $kw) !== false || $submitted === $kw) {
                    $submittedInGroup = true;
                    break;
                }
            }

            if ($submittedInGroup) {
                $matchedGroup = $group;
                // Count how many keywords from this group appear in OCR text
                foreach ($keywords as $kw) {
                    if (!empty($rawText) && strpos($rawText, $kw) !== false) {
                        $keywordMatches++;
                    }
                }
                break;
            }
        }

        if ($matchedGroup === null) {
            // Category not in any known group — can't verify
            return ['status' => 'warning', 'message' => 'Category not in known groups', 'confidence' => 0.6,
                    'submitted_category' => $claim['category']];
        }

        if ($keywordMatches >= 3) {
            return ['status' => 'pass', 'message' => "Category strongly supported ({$keywordMatches} keyword matches)", 'confidence' => 0.95,
                    'submitted_category' => $claim['category'], 'keyword_matches' => $keywordMatches];
        }
        if ($keywordMatches >= 1) {
            return ['status' => 'pass', 'message' => 'Category supported by receipt text', 'confidence' => 0.80,
                    'submitted_category' => $claim['category'], 'keyword_matches' => $keywordMatches];
        }

        // No keywords found — check via NLP-extracted category if available
        if (!empty($rawText)) {
            return ['status' => 'warning', 'message' => 'No category keywords found in receipt text', 'confidence' => 0.50,
                    'submitted_category' => $claim['category'], 'keyword_matches' => 0];
        }

        return ['status' => 'warning', 'message' => 'No receipt text to verify category', 'confidence' => 0.5,
                'submitted_category' => $claim['category']];
    }

    /**
     * 5. Receipt quality assessment.
     */
    private function verifyReceiptQuality(array $ocrData): array
    {
        $quality = $ocrData['image_quality'] ?? [];
        $score = floatval($quality['score'] ?? 0);
        $level = $quality['level'] ?? 'unknown';
        $issues = $quality['issues'] ?? [];

        if ($score >= 0.7) {
            return ['status' => 'pass', 'message' => 'Receipt image quality is good', 'confidence' => 0.90,
                    'quality_score' => $score, 'quality_level' => $level, 'issues' => $issues];
        }
        if ($score >= 0.4) {
            return ['status' => 'warning', 'message' => 'Receipt image quality is acceptable', 'confidence' => 0.70,
                    'quality_score' => $score, 'quality_level' => $level, 'issues' => $issues];
        }

        return ['status' => 'fail', 'message' => 'Receipt image quality is poor', 'confidence' => 0.30,
                'quality_score' => $score, 'quality_level' => $level, 'issues' => $issues];
    }

    /**
     * 6. Duplicate detection — check for matching receipts by content and perceptual hash.
     */
    private function checkDuplicates(array $claim, array $ocrData): array
    {
        $claimId = intval($claim['id']);
        $amount = floatval($claim['amount'] ?? 0);
        $vendor = $claim['vendor'] ?? '';
        $date = $claim['expense_date'] ?? '';
        $createdBy = $claim['created_by'] ?? '';
        $phash = $ocrData['phash'] ?? $claim['phash'] ?? null;

        $duplicates = [];

        // --- Check 1: Same amount + vendor + date (exact content match) ---
        try {
            $stmt = $this->conn->prepare(
                "SELECT id, amount, vendor, expense_date, created_by, status, phash
                 FROM claims
                 WHERE id != ? AND status NOT IN ('rejected','deleted')
                   AND ABS(amount - ?) < 0.01
                   AND vendor = ?
                   AND expense_date = ?
                 LIMIT 5"
            );
            if ($stmt) {
                $stmt->bind_param('idss', $claimId, $amount, $vendor, $date);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $duplicates[] = [
                        'type' => 'exact_match',
                        'claim_id' => $row['id'],
                        'details' => "Same amount (₱{$amount}), vendor ({$vendor}), date ({$date})",
                    ];
                }
                $stmt->close();
            }
        } catch (\Throwable $e) { /* silent */ }

        // --- Check 2: Perceptual hash similarity (same receipt image) ---
        if (!empty($phash)) {
            try {
                $stmt = $this->conn->prepare(
                    "SELECT id, phash, amount, vendor FROM claims
                     WHERE id != ? AND phash IS NOT NULL AND phash != ''
                       AND status NOT IN ('rejected','deleted')
                     LIMIT 100"
                );
                if ($stmt) {
                    $stmt->bind_param('i', $claimId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $distance = ReceiptOCR::hammingDistance($phash, $row['phash']);
                        if ($distance <= self::PHASH_THRESHOLD) {
                            // Check it's not already in duplicates
                            $alreadyFound = false;
                            foreach ($duplicates as $d) {
                                if ($d['claim_id'] == $row['id']) {
                                    $alreadyFound = true;
                                    break;
                                }
                            }
                            if (!$alreadyFound) {
                                $duplicates[] = [
                                    'type' => 'image_match',
                                    'claim_id' => $row['id'],
                                    'distance' => $distance,
                                    'details' => "Similar receipt image (Hamming distance: {$distance})",
                                ];
                            }
                        }
                    }
                    $stmt->close();
                }
            } catch (\Throwable $e) { /* silent */ }
        }

        // --- Check 3: Same user, same amount within 7 days ---
        try {
            $stmt = $this->conn->prepare(
                "SELECT id, amount, vendor, expense_date FROM claims
                 WHERE id != ? AND created_by = ?
                   AND ABS(amount - ?) < 0.01
                   AND ABS(DATEDIFF(expense_date, ?)) <= 7
                   AND status NOT IN ('rejected','deleted')
                 LIMIT 5"
            );
            if ($stmt) {
                $stmt->bind_param('isds', $claimId, $createdBy, $amount, $date);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $alreadyFound = false;
                    foreach ($duplicates as $d) {
                        if ($d['claim_id'] == $row['id']) {
                            $alreadyFound = true;
                            break;
                        }
                    }
                    if (!$alreadyFound) {
                        $duplicates[] = [
                            'type' => 'near_match',
                            'claim_id' => $row['id'],
                            'details' => "Same user, same amount, within 7 days (vendor: {$row['vendor']})",
                        ];
                    }
                }
                $stmt->close();
            }
        } catch (\Throwable $e) { /* silent */ }

        if (count($duplicates) > 0) {
            $msg = 'Found ' . count($duplicates) . ' potential duplicate(s)';
            $hasExact = false;
            foreach ($duplicates as $d) {
                if ($d['type'] === 'exact_match' || $d['type'] === 'image_match') {
                    $hasExact = true;
                    break;
                }
            }

            return [
                'status' => $hasExact ? 'fail' : 'warning',
                'message' => $msg,
                'confidence' => $hasExact ? 0.20 : 0.55,
                'duplicates' => $duplicates,
            ];
        }

        return ['status' => 'pass', 'message' => 'No duplicates found', 'confidence' => 0.95];
    }

    /**
     * 7. OCR confidence assessment.
     */
    private function assessOCRConfidence(array $ocrData): array
    {
        $conf = floatval($ocrData['extraction_confidence'] ?? $ocrData['ocr_confidence'] ?? 0);

        if ($conf >= 80) {
            return ['status' => 'pass', 'message' => 'High OCR confidence (' . round($conf) . '%)', 'confidence' => 0.95];
        }
        if ($conf >= 60) {
            return ['status' => 'pass', 'message' => 'Acceptable OCR confidence (' . round($conf) . '%)', 'confidence' => 0.75];
        }
        if ($conf >= 30) {
            return ['status' => 'warning', 'message' => 'Low OCR confidence (' . round($conf) . '%)', 'confidence' => 0.50];
        }
        if ($conf > 0) {
            return ['status' => 'warning', 'message' => 'Very low OCR confidence (' . round($conf) . '%)', 'confidence' => 0.30];
        }

        if (!($ocrData['tesseract_available'] ?? true)) {
            return ['status' => 'warning', 'message' => 'Tesseract OCR not available on server', 'confidence' => 0.40];
        }

        return ['status' => 'warning', 'message' => 'No OCR data available', 'confidence' => 0.40];
    }

    // ══════════════════════════════════════════════
    //  SCORING & STATUS
    // ══════════════════════════════════════════════

    /**
     * Compute weighted overall score (0–100).
     */
    private function calculateScore(array $checks): float
    {
        $total = 0;
        $weightSum = 0;

        foreach (self::WEIGHTS as $key => $weight) {
            if (isset($checks[$key]['confidence'])) {
                $conf = floatval($checks[$key]['confidence']);
                $total += $conf * $weight;
                $weightSum += $weight;
            }
        }

        return $weightSum > 0 ? round(($total / $weightSum) * 100, 1) : 0;
    }

    /**
     * Determine verification status from score and individual checks.
     */
    private function determineStatus(float $score, array $checks): string
    {
        // Hard-fail overrides: any 'fail' status on critical checks
        $criticalFails = ['amount_match', 'duplicate_check'];
        foreach ($criticalFails as $key) {
            if (isset($checks[$key]['status']) && $checks[$key]['status'] === 'fail') {
                return 'flagged';
            }
        }

        if ($score >= self::THRESHOLD_VERIFIED) {
            return 'verified';
        }
        if ($score >= self::THRESHOLD_FLAGGED) {
            return 'review_pending';
        }

        return 'flagged';
    }

    // ══════════════════════════════════════════════
    //  ANOMALY DETECTION
    // ══════════════════════════════════════════════

    /**
     * Detect anomalies that warrant human attention.
     */
    private function detectAnomalies(array $claim, array $ocrData, array $checks): array
    {
        $anomalies = [];

        // High amount
        $amount = floatval($claim['amount'] ?? 0);
        if ($amount > 10000) {
            $anomalies[] = [
                'type' => 'high_amount',
                'severity' => 'warning',
                'message' => "High claim amount: ₱" . number_format($amount, 2),
            ];
        }

        // Amount exceeds policy limit
        $category = $claim['category'] ?? '';
        $policyLimit = $this->getPolicyLimit($category);
        if ($policyLimit > 0 && $amount > $policyLimit) {
            $anomalies[] = [
                'type' => 'exceeds_policy',
                'severity' => 'critical',
                'message' => "Amount exceeds policy limit for {$category} (₱" . number_format($policyLimit, 2) . ")",
            ];
        }

        // Weekend/holiday expense
        $expDate = $claim['expense_date'] ?? '';
        if (!empty($expDate)) {
            $dow = date('N', strtotime($expDate));
            if ($dow >= 6) {
                $anomalies[] = [
                    'type' => 'weekend_expense',
                    'severity' => 'info',
                    'message' => 'Expense date falls on a weekend',
                ];
            }
        }

        // Multiple amounts detected on receipt
        $amountsFound = $ocrData['structured']['amounts_found'] ?? [];
        if (count($amountsFound) > 5) {
            $anomalies[] = [
                'type' => 'multiple_amounts',
                'severity' => 'info',
                'message' => count($amountsFound) . ' different amounts found on receipt',
            ];
        }

        // Submitted amount matches subtotal, not total (common error)
        $subtotal = floatval($ocrData['structured']['subtotal'] ?? 0);
        $total = floatval($ocrData['structured']['amount'] ?? 0);
        if ($subtotal > 0 && $total > 0 && $amount > 0) {
            if (abs($amount - $subtotal) < 0.01 && abs($amount - $total) > 1) {
                $anomalies[] = [
                    'type' => 'subtotal_match',
                    'severity' => 'warning',
                    'message' => 'Submitted amount matches subtotal, not total (difference: ₱' . number_format(abs($total - $subtotal), 2) . ')',
                ];
            }
        }

        // Poor image quality
        $qualityScore = floatval($ocrData['image_quality']['score'] ?? 0);
        if ($qualityScore < 0.3) {
            $anomalies[] = [
                'type' => 'poor_image',
                'severity' => 'warning',
                'message' => 'Very poor receipt image quality — OCR may be unreliable',
            ];
        }

        // Duplicate receipt detected
        if (isset($checks['duplicate_check']['duplicates']) && !empty($checks['duplicate_check']['duplicates'])) {
            foreach ($checks['duplicate_check']['duplicates'] as $dup) {
                $anomalies[] = [
                    'type' => 'duplicate_' . $dup['type'],
                    'severity' => $dup['type'] === 'exact_match' ? 'critical' : 'warning',
                    'message' => $dup['details'] . ' (Claim #' . $dup['claim_id'] . ')',
                ];
            }
        }

        return $anomalies;
    }

    /**
     * Get policy limit for a category from DB.
     */
    private function getPolicyLimit(string $category): float
    {
        if (empty($category)) return 0;

        $stmt = $this->conn->prepare(
            "SELECT limit_amount FROM reimbursement_policies WHERE category = ? LIMIT 1"
        );
        if (!$stmt) return 0;

        $stmt->bind_param('s', $category);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return floatval($row['limit_amount'] ?? 0);
    }

    // ══════════════════════════════════════════════
    //  RECEIPT ANALYSIS SUMMARY (for frontend)
    // ══════════════════════════════════════════════

    /**
     * Build receipt analysis data structure for the frontend modal.
     */
    private function buildReceiptAnalysis(array $ocrData): array
    {
        $quality = $ocrData['image_quality'] ?? [];
        $structured = $ocrData['structured'] ?? [];

        return [
            'image_quality' => [
                'score'      => $quality['score'] ?? 0,
                'level'      => $quality['level'] ?? 'unknown',
                'resolution' => $quality['resolution'] ?? 'Unknown',
                'blur_detected' => $quality['is_blurry'] ?? false,
            ],
            'receipt_type' => [
                'type'       => $this->detectDocumentType($ocrData['raw_text'] ?? ''),
                'confidence' => min(0.95, ($ocrData['extraction_confidence'] ?? 0) / 100),
            ],
            'tampering_score' => [
                'score' => 0.05,   // Low by default unless receipt_analyzer detects issues
                'risk'  => 'low',
                'indicators' => [],
            ],
            'text_extraction' => [
                'full_text'   => substr($ocrData['raw_text'] ?? '', 0, 500),
                'lines_count' => substr_count($ocrData['raw_text'] ?? '', "\n") + 1,
                'confidence'  => ($ocrData['extraction_confidence'] ?? 0) / 100,
                'detected_patterns' => [
                    'vendor'  => $structured['vendor'] ?? 'Not detected',
                    'amounts' => !empty($structured['amount']) ? [$structured['amount']] : ($structured['amounts_found'] ?? []),
                    'dates'   => !empty($structured['date']) ? [$structured['date']] : [],
                    'items'   => $structured['items'] ?? [],
                ],
            ],
            'anomalies'       => [],
            'overall_quality' => ($quality['score'] ?? 0.5),
        ];
    }

    /**
     * Simple document type detection from OCR text.
     */
    private function detectDocumentType(string $text): string
    {
        $text = strtoupper($text);
        $receiptMarkers = 0;
        $invoiceMarkers = 0;

        $receiptKeywords = ['RECEIPT', 'CASHIER', 'TOTAL', 'CHANGE', 'TENDERED', 'CASH', 'THANK YOU', 'COME AGAIN'];
        $invoiceKeywords = ['INVOICE', 'BILL TO', 'SHIP TO', 'DUE DATE', 'PAYMENT TERMS', 'INVOICE NO'];

        foreach ($receiptKeywords as $kw) {
            if (strpos($text, $kw) !== false) $receiptMarkers++;
        }
        foreach ($invoiceKeywords as $kw) {
            if (strpos($text, $kw) !== false) $invoiceMarkers++;
        }

        if ($invoiceMarkers > $receiptMarkers) return 'invoice';
        if ($receiptMarkers > 0) return 'receipt';
        return 'unknown';
    }

    // ══════════════════════════════════════════════
    //  LOGGING
    // ══════════════════════════════════════════════

    /**
     * Log verification to claim_verification_logs table.
     */
    private function logVerification(int $claimId, float $score, string $status, array $checks, array $ocrData): void
    {
        // Check if the table exists before logging
        $tableCheck = $this->conn->query("SHOW TABLES LIKE 'claim_verification_logs'");
        if (!$tableCheck || $tableCheck->num_rows === 0) {
            return; // Table doesn't exist yet — skip logging
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO claim_verification_logs (
                claim_id, verified_by, verification_type, overall_score,
                amount_score, vendor_score, date_score, category_score,
                receipt_quality_score, duplicate_check, result_status,
                result_message, details_json,
                submitted_amount, extracted_amount,
                submitted_vendor, extracted_vendor,
                submitted_date, extracted_date,
                ip_address, user_agent
            ) VALUES (?, ?, 'automated', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if (!$stmt) return;

        $amountScore = round(($checks['amount_match']['confidence'] ?? 0) * 100, 1);
        $vendorScore = round(($checks['vendor_match']['confidence'] ?? 0) * 100, 1);
        $dateScore   = round(($checks['date_match']['confidence'] ?? 0) * 100, 1);
        $catScore    = round(($checks['category_match']['confidence'] ?? 0) * 100, 1);
        $qualScore   = round(($checks['receipt_quality']['confidence'] ?? 0) * 100, 1);
        $dupCheck    = $checks['duplicate_check']['status'] ?? 'unknown';
        $resultMsg   = $this->buildResultMessage($checks);
        $detailsJson = json_encode($checks, JSON_UNESCAPED_UNICODE);

        $subAmount = strval($checks['amount_match']['submitted'] ?? '');
        $extAmount = strval($checks['amount_match']['extracted'] ?? '');
        $subVendor = strval($checks['vendor_match']['submitted'] ?? '');
        $extVendor = strval($checks['vendor_match']['extracted'] ?? '');
        $subDate   = strval($checks['date_match']['submitted'] ?? '');
        $extDate   = strval($checks['date_match']['extracted'] ?? '');

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        $stmt->bind_param(
            'isddddddssssssssssss',
            $claimId, $this->verifiedBy, $score,
            $amountScore, $vendorScore, $dateScore, $catScore, $qualScore,
            $dupCheck, $status, $resultMsg, $detailsJson,
            $subAmount, $extAmount, $subVendor, $extVendor,
            $subDate, $extDate, $ip, $ua
        );

        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update the claims table with verification results.
     */
    private function updateClaimVerification(int $claimId, float $score, string $status): void
    {
        // Try to update verification columns (they may not exist yet)
        $stmt = $this->conn->prepare(
            "UPDATE claims SET verification_status = ?, verification_score = ?,
                    last_verified_at = NOW(), last_verified_by = ?
             WHERE id = ?"
        );
        if ($stmt) {
            $stmt->bind_param('sdsi', $status, $score, $this->verifiedBy, $claimId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Build a human-readable result message from check results.
     */
    private function buildResultMessage(array $checks): string
    {
        $failCount = 0;
        $warnCount = 0;
        $passCount = 0;

        foreach ($checks as $check) {
            switch ($check['status'] ?? '') {
                case 'pass':    $passCount++; break;
                case 'warning': $warnCount++; break;
                case 'fail':    $failCount++; break;
            }
        }

        $parts = [];
        if ($passCount) $parts[] = "{$passCount} passed";
        if ($warnCount) $parts[] = "{$warnCount} warning(s)";
        if ($failCount) $parts[] = "{$failCount} failed";

        return implode(', ', $parts);
    }

    // ══════════════════════════════════════════════
    //  HELPERS
    // ══════════════════════════════════════════════

    private function fmt(float $val): string
    {
        return number_format($val, 1);
    }

    private function errorResult(string $msg, int $claimId): array
    {
        return [
            'success'       => false,
            'error'         => $msg,
            'claim_id'      => $claimId,
            'overall_score' => 0,
            'status'        => 'error',
            'verifications' => [],
            'receipt_analysis' => [],
            'anomalies'     => [],
            'timestamp'     => date('Y-m-d H:i:s'),
        ];
    }
}
