<?php
/**
 * receipt_ocr.php
 * ──────────────────────────────────────────────────────────
 * Core OCR Module for Receipt Image Processing
 * ──────────────────────────────────────────────────────────
 *
 * Provides the ReceiptOCR class which:
 *   1. Preprocesses receipt images (sharpen/contrast via GD or Imagick)
 *   2. Runs Tesseract OCR (server-side) to extract raw text
 *   3. Parses extracted text into structured receipt data:
 *      - Merchant/vendor name
 *      - Total amount, subtotal, tax
 *      - Receipt date
 *      - Receipt/transaction number
 *      - Line items (best-effort)
 *   4. Computes OCR confidence score
 *   5. Computes file hash (SHA-256) and perceptual hash (pHash)
 *   6. Assesses image quality (resolution, blur)
 *
 * Requirements:
 *   - Tesseract OCR binary on PATH  (apt install tesseract-ocr)
 *   - PHP GD extension (required)
 *   - PHP Imagick extension (optional, improves preprocessing)
 *
 * Usage:
 *   require_once 'receipt_ocr.php';
 *   $ocr = new ReceiptOCR();
 *   $result = $ocr->processReceipt('/path/to/receipt.jpg');
 *   // $result = ['success'=>true, 'raw_text'=>'...', 'structured'=>[...], ...]
 */

class ReceiptOCR
{
    /** @var string  Path to the Tesseract binary */
    private string $tesseractBin;

    /** @var bool  Whether Tesseract is available on this server */
    private bool $tesseractAvailable;

    /** @var bool  Whether Imagick extension is loaded */
    private bool $imagickAvailable;

    /** @var int  Max image dimension for preprocessing (px) */
    private int $maxDimension = 2000;

    /** @var string  Temp directory for preprocessed images */
    private string $tmpDir;

    // ─── Philippine-peso patterns ──────────────────
    // Matches: ₱1,234.56  PHP 1234.56  P1,234  Php1234.56  etc.
    private const AMOUNT_PATTERNS = [
        '/(?:₱|PHP|Php|php|P)\s*(\d{1,3}(?:,\d{3})*(?:\.\d{1,2})?)/u',
        '/(?:TOTAL|Total|GRAND\s*TOTAL|Amount\s*Due|Amount\s*Paid|NET|AMOUNT|Balance)\s*[:\-]?\s*(?:₱|PHP|P)?\s*(\d{1,3}(?:,\d{3})*(?:\.\d{1,2}))/i',
        '/(\d{1,3}(?:,\d{3})*\.\d{2})\s*(?:PHP|TOTAL|Total)/i',
    ];

    private const DATE_PATTERNS = [
        // ISO: 2026-02-26
        '/(\d{4}[-\/]\d{1,2}[-\/]\d{1,2})/',
        // US: 02/26/2026 or 02-26-2026
        '/(\d{1,2}[-\/]\d{1,2}[-\/]\d{4})/',
        // Short year: 02/26/26
        '/(\d{1,2}[-\/]\d{1,2}[-\/]\d{2})(?!\d)/',
        // Text: Feb 26, 2026  |  26 Feb 2026
        '/(\d{1,2}\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+\d{2,4})/i',
        '/((?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+\d{1,2},?\s+\d{2,4})/i',
    ];

    private const RECEIPT_NO_PATTERNS = [
        '/(?:Receipt|Rcpt|Transaction|Trans|Invoice|Inv|OR|SI|Ref|Reference)\s*(?:No\.?|#|Number)?\s*[:\-]?\s*([A-Z0-9][\w\-]{3,20})/i',
        '/(?:No|#)\s*[:\-]?\s*(\d{4,15})/i',
    ];

    /** Keywords that indicate a total line (not a line item) */
    private const TOTAL_KEYWORDS = [
        'TOTAL', 'GRAND TOTAL', 'AMOUNT DUE', 'AMOUNT PAID', 'NET TOTAL',
        'SUBTOTAL', 'SUB-TOTAL', 'SUB TOTAL', 'BALANCE', 'CASH', 'CHANGE',
        'VAT', 'TAX', 'DISCOUNT', 'TENDERED', 'PAYMENT', 'VATABLE',
    ];

    /** Keywords to skip when detecting vendor (first prominent line) */
    private const SKIP_KEYWORDS = [
        'RECEIPT', 'INVOICE', 'OFFICIAL', 'TAX INVOICE', 'SALES INVOICE',
        'DATE', 'TIME', 'CASHIER', 'TERMINAL', 'STORE', 'BRANCH',
        'TIN', 'VAT', 'REG', 'MIN', 'PTU', 'ACCREDITATION', 'BIR',
        'SERIAL', 'PERMIT', 'TEL', 'PHONE', 'FAX', 'EMAIL', 'ADDRESS',
    ];


    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir();
        $this->imagickAvailable = extension_loaded('imagick');

        // Detect Tesseract binary (safely — may fail on restricted servers)
        try {
            $this->tesseractBin = $this->findTesseract();
            $this->tesseractAvailable = !empty($this->tesseractBin);
        } catch (\Throwable $e) {
            $this->tesseractBin = '';
            $this->tesseractAvailable = false;
        }
    }

    // ══════════════════════════════════════════════
    //  PUBLIC API
    // ══════════════════════════════════════════════

    /**
     * Process a receipt image end-to-end.
     *
     * @param  string $imagePath  Absolute path to the receipt image file
     * @return array  Structured result with raw_text, structured data, scores
     */
    public function processReceipt(string $imagePath): array
    {
        if (!file_exists($imagePath)) {
            return $this->errorResult("File not found: $imagePath");
        }

        $mime = mime_content_type($imagePath);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/tiff', 'application/pdf'])) {
            return $this->errorResult("Unsupported file type: $mime");
        }

        // 1. Image quality assessment
        $quality = $this->assessImageQuality($imagePath);

        // 2. Preprocess for better OCR
        $preprocessed = $this->preprocessImage($imagePath);
        $ocrPath = $preprocessed ?: $imagePath;

        // 3. Run OCR
        $ocrResult = $this->runOCR($ocrPath);
        $rawText = $ocrResult['text'] ?? '';
        $ocrConfidence = $ocrResult['confidence'] ?? 0;

        // Clean up temp file
        if ($preprocessed && $preprocessed !== $imagePath && file_exists($preprocessed)) {
            @unlink($preprocessed);
        }

        // 4. Parse structured fields from raw text
        $structured = $this->parseReceiptText($rawText);

        // 5. Compute hashes
        $fileHash = hash_file('sha256', $imagePath);
        $pHash = $this->computePerceptualHash($imagePath);

        // 6. Adjust confidence based on what we extracted
        $extractionConfidence = $this->calculateExtractionConfidence($structured, $ocrConfidence);

        return [
            'success'    => true,
            'raw_text'   => $rawText,
            'structured' => $structured,
            'ocr_confidence'        => round($ocrConfidence, 2),
            'extraction_confidence' => round($extractionConfidence, 2),
            'image_quality' => $quality,
            'file_hash'  => $fileHash,
            'phash'      => $pHash,
            'tesseract_available' => $this->tesseractAvailable,
        ];
    }

    /**
     * Quick text-only extraction (no quality/hash analysis).
     */
    public function extractTextOnly(string $imagePath): array
    {
        if (!file_exists($imagePath)) {
            return ['success' => false, 'error' => 'File not found'];
        }

        $preprocessed = $this->preprocessImage($imagePath);
        $ocrPath = $preprocessed ?: $imagePath;
        $ocrResult = $this->runOCR($ocrPath);

        if ($preprocessed && $preprocessed !== $imagePath && file_exists($preprocessed)) {
            @unlink($preprocessed);
        }

        $rawText = $ocrResult['text'] ?? '';
        $structured = $this->parseReceiptText($rawText);

        return [
            'success'    => true,
            'raw_text'   => $rawText,
            'structured' => $structured,
            'confidence' => $ocrResult['confidence'] ?? 0,
        ];
    }

    // ══════════════════════════════════════════════
    //  TESSERACT OCR
    // ══════════════════════════════════════════════

    /**
     * Run Tesseract OCR on an image file.
     * Falls back to an empty result if Tesseract is not installed.
     */
    private function runOCR(string $imagePath): array
    {
        if (!$this->tesseractAvailable) {
            return [
                'text'       => '',
                'confidence' => 0,
                'error'      => 'Tesseract OCR is not installed on this server.',
            ];
        }

        $outBase = $this->tmpDir . '/ocr_' . uniqid();
        $escaped = escapeshellarg($imagePath);
        $outEscaped = escapeshellarg($outBase);

        // Check if exec() is available
        if (!$this->isShellAvailable('exec')) {
            return [
                'text'       => '',
                'confidence' => 0,
                'error'      => 'exec() function is disabled on this server.',
            ];
        }

        // Multi-pass OCR for best results
        $passes = [
            // Pass 1: Page segmentation mode 6 (uniform block of text)
            "$this->tesseractBin $escaped $outEscaped --psm 6 --oem 3 2>&1",
            // Pass 2: PSM 3 (fully automatic) — may capture more
            "$this->tesseractBin $escaped $outEscaped --psm 3 --oem 3 2>&1",
        ];

        $bestText = '';
        $bestScore = 0;

        foreach ($passes as $cmd) {
            try {
                $output = [];
                $ret = 0;
                @exec($cmd, $output, $ret);

                $txtFile = $outBase . '.txt';
                if (file_exists($txtFile)) {
                    $text = file_get_contents($txtFile);
                    @unlink($txtFile);

                    $score = $this->scoreOCRText($text);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestText = $text;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        // Also try to get confidence from Tesseract TSV output
        $confidence = $this->estimateConfidence($bestText, $bestScore);

        return [
            'text'       => trim($bestText),
            'confidence' => $confidence,
        ];
    }

    /**
     * Score OCR text quality (higher = more useful receipt text).
     */
    private function scoreOCRText(string $text): float
    {
        if (empty(trim($text))) return 0;

        $score = 0;
        $text = trim($text);

        // Length bonus (receipts typically 200–2000 chars)
        $len = mb_strlen($text);
        if ($len > 50) $score += 10;
        if ($len > 200) $score += 10;
        if ($len > 500) $score += 5;

        // Contains currency amounts
        if (preg_match('/\d+\.\d{2}/', $text)) $score += 15;

        // Contains peso sign or PHP
        if (preg_match('/[₱]|PHP|Php/u', $text)) $score += 10;

        // Contains date-like patterns
        if (preg_match('/\d{1,4}[-\/]\d{1,2}[-\/]\d{1,4}/', $text)) $score += 10;

        // Contains total/subtotal words
        if (preg_match('/total|subtotal|amount|balance/i', $text)) $score += 15;

        // Contains receipt keywords
        if (preg_match('/receipt|invoice|cashier|store|branch/i', $text)) $score += 10;

        // Penalize garbage (lots of non-printable or symbols)
        $garbageRatio = preg_match_all('/[^a-zA-Z0-9\s.,₱\-\/:#@&()%]/', $text) / max($len, 1);
        if ($garbageRatio > 0.3) $score -= 20;

        return max(0, $score);
    }

    /**
     * Estimate OCR confidence from text quality score.
     */
    private function estimateConfidence(string $text, float $qualityScore): float
    {
        if (empty(trim($text))) return 0;

        // Base confidence from quality score (max ~75 from scoreOCRText)
        $confidence = min(95, ($qualityScore / 75) * 85 + 10);

        // Bonus: high proportion of alphanumeric chars
        $alphaRatio = preg_match_all('/[a-zA-Z0-9]/', $text) / max(mb_strlen($text), 1);
        if ($alphaRatio > 0.5) $confidence = min(95, $confidence + 5);

        return round($confidence, 1);
    }

    // ══════════════════════════════════════════════
    //  TEXT PARSING — Extract structured receipt data
    // ══════════════════════════════════════════════

    /**
     * Parse raw OCR text into structured receipt fields.
     */
    public function parseReceiptText(string $rawText): array
    {
        $result = [
            'vendor'     => null,
            'amount'     => null,
            'subtotal'   => null,
            'tax'        => null,
            'date'       => null,
            'receipt_no' => null,
            'items'      => [],
            'amounts_found' => [],
        ];

        if (empty(trim($rawText))) return $result;

        $lines = preg_split('/\r?\n/', $rawText);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines, fn($l) => $l !== '');

        // --- Extract vendor (first prominent non-keyword line) ---
        $result['vendor'] = $this->extractVendor($lines);

        // --- Extract all amounts ---
        $allAmounts = $this->extractAllAmounts($rawText);
        $result['amounts_found'] = $allAmounts;

        // --- Determine total, subtotal, tax ---
        $this->classifyAmounts($rawText, $allAmounts, $result);

        // --- Extract date ---
        $result['date'] = $this->extractDate($rawText);

        // --- Extract receipt number ---
        $result['receipt_no'] = $this->extractReceiptNumber($rawText);

        // --- Extract line items (best-effort) ---
        $result['items'] = $this->extractLineItems($lines);

        return $result;
    }

    /**
     * Extract the vendor/merchant name from the top lines of the receipt.
     */
    private function extractVendor(array $lines): ?string
    {
        // Vendor is typically in the first 5 non-trivial lines
        $candidates = array_slice($lines, 0, min(8, count($lines)));

        foreach ($candidates as $line) {
            $upper = strtoupper($line);

            // Skip very short lines
            if (mb_strlen($line) < 3) continue;

            // Skip lines that are purely numeric / date-like
            if (preg_match('/^\d[\d\-\/,:.\s]+$/', $line)) continue;

            // Skip known header keywords
            $skip = false;
            foreach (self::SKIP_KEYWORDS as $kw) {
                if (stripos($upper, $kw) === 0 || $upper === $kw) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            // Skip lines with TIN numbers, phone numbers, addresses with numbers
            if (preg_match('/^\d{3}[\-\s]\d{3}/', $line)) continue;
            if (preg_match('/^TIN|^VAT|^REG/i', $line)) continue;

            // This line is likely the vendor name
            // Clean up: remove surrounding symbols
            $vendor = preg_replace('/^[\*\-=\s]+|[\*\-=\s]+$/', '', $line);
            if (mb_strlen($vendor) >= 2) {
                return $vendor;
            }
        }

        return null;
    }

    /**
     * Extract all monetary amounts from the text.
     */
    private function extractAllAmounts(string $text): array
    {
        $amounts = [];

        foreach (self::AMOUNT_PATTERNS as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[1] as $match) {
                    $value = (float) str_replace(',', '', $match);
                    if ($value > 0 && $value < 10000000) { // Sanity cap
                        $amounts[] = $value;
                    }
                }
            }
        }

        // Also find standalone decimal amounts (xx.xx pattern without currency marker)
        if (preg_match_all('/(?<!\d)(\d{1,6}\.\d{2})(?!\d)/', $text, $m)) {
            foreach ($m[1] as $val) {
                $v = (float) $val;
                if ($v > 0 && $v < 10000000) {
                    $amounts[] = $v;
                }
            }
        }

        // Remove duplicates and sort descending
        $amounts = array_values(array_unique($amounts));
        rsort($amounts);

        return $amounts;
    }

    /**
     * Classify amounts as total, subtotal, tax based on context keywords.
     */
    private function classifyAmounts(string $text, array $allAmounts, array &$result): void
    {
        $lines = preg_split('/\r?\n/', $text);

        // Try to find labeled amounts
        foreach ($lines as $line) {
            $upper = strtoupper(trim($line));

            // Total / Grand Total
            if (preg_match('/(?:GRAND\s*TOTAL|TOTAL\s*(?:DUE|AMOUNT|SALE)?|AMOUNT\s*DUE|AMOUNT\s*PAID)\s*[:\-]?\s*(?:₱|PHP|P)?\s*([\d,]+\.\d{2})/i', $line, $m)) {
                $result['amount'] = (float) str_replace(',', '', $m[1]);
            }

            // Subtotal
            if (preg_match('/(?:SUBTOTAL|SUB[\-\s]?TOTAL)\s*[:\-]?\s*(?:₱|PHP|P)?\s*([\d,]+\.\d{2})/i', $line, $m)) {
                $result['subtotal'] = (float) str_replace(',', '', $m[1]);
            }

            // Tax / VAT
            if (preg_match('/(?:VAT|TAX|EVAT|OUTPUT\s*TAX)\s*[:\-]?\s*(?:₱|PHP|P)?\s*([\d,]+\.\d{2})/i', $line, $m)) {
                $result['tax'] = (float) str_replace(',', '', $m[1]);
            }
        }

        // Fallback: if no labeled total found, use the largest amount
        if ($result['amount'] === null && !empty($allAmounts)) {
            $result['amount'] = $allAmounts[0]; // Largest
        }
    }

    /**
     * Extract date from receipt text.
     */
    private function extractDate(string $text): ?string
    {
        foreach (self::DATE_PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $raw = $m[1];

                // Try to parse into standard format
                $parsed = $this->normalizeDate($raw);
                if ($parsed) return $parsed;

                return $raw; // Return raw if can't normalize
            }
        }

        return null;
    }

    /**
     * Normalize a date string to YYYY-MM-DD.
     */
    private function normalizeDate(string $raw): ?string
    {
        // Remove extra spaces
        $raw = trim(preg_replace('/\s+/', ' ', $raw));

        // Try various formats
        $formats = [
            'Y-m-d', 'Y/m/d',
            'm-d-Y', 'm/d/Y',
            'd-m-Y', 'd/m/Y',
            'm-d-y', 'm/d/y',
            'd M Y', 'd M y',
            'M d, Y', 'M d Y',
            'd F Y', 'F d, Y', 'F d Y',
            'M. d, Y', 'd M. Y',
        ];

        foreach ($formats as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $raw);
            if ($dt && $dt->format($fmt) === $raw) {
                return $dt->format('Y-m-d');
            }
        }

        // Loose try with strtotime
        $ts = strtotime($raw);
        if ($ts && $ts > strtotime('2000-01-01') && $ts < strtotime('2030-12-31')) {
            return date('Y-m-d', $ts);
        }

        return null;
    }

    /**
     * Extract receipt / transaction number.
     */
    private function extractReceiptNumber(string $text): ?string
    {
        foreach (self::RECEIPT_NO_PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return trim($m[1]);
            }
        }
        return null;
    }

    /**
     * Extract line items from receipt (best-effort).
     * Looks for lines with a quantity, description, and price.
     */
    private function extractLineItems(array $lines): array
    {
        $items = [];

        foreach ($lines as $line) {
            $upper = strtoupper($line);

            // Skip header/footer lines
            $isTotal = false;
            foreach (self::TOTAL_KEYWORDS as $kw) {
                if (stripos($upper, $kw) !== false) {
                    $isTotal = true;
                    break;
                }
            }
            if ($isTotal) continue;

            // Pattern: qty x description ... price  OR  description ... price
            if (preg_match('/^(\d+)\s*[xX×]\s*(.+?)\s+([\d,]+\.\d{2})\s*$/', $line, $m)) {
                $items[] = [
                    'qty' => (int) $m[1],
                    'description' => trim($m[2]),
                    'price' => (float) str_replace(',', '', $m[3]),
                ];
            } elseif (preg_match('/^(.{3,40}?)\s{2,}([\d,]+\.\d{2})\s*$/', $line, $m)) {
                $desc = trim($m[1]);
                // Skip if desc looks like a keyword
                $skipLine = false;
                foreach (self::SKIP_KEYWORDS as $kw) {
                    if (stripos($desc, $kw) !== false) {
                        $skipLine = true;
                        break;
                    }
                }
                if (!$skipLine && mb_strlen($desc) > 2) {
                    $items[] = [
                        'qty' => 1,
                        'description' => $desc,
                        'price' => (float) str_replace(',', '', $m[2]),
                    ];
                }
            }
        }

        return $items;
    }

    // ══════════════════════════════════════════════
    //  IMAGE PREPROCESSING
    // ══════════════════════════════════════════════

    /**
     * Preprocess image for better OCR results.
     * Returns path to preprocessed temporary file, or null on failure.
     */
    private function preprocessImage(string $imagePath): ?string
    {
        if ($this->imagickAvailable) {
            return $this->preprocessWithImagick($imagePath);
        }

        return $this->preprocessWithGD($imagePath);
    }

    /**
     * Preprocess using Imagick (better quality).
     */
    private function preprocessWithImagick(string $imagePath): ?string
    {
        try {
            $img = new Imagick($imagePath);

            // Auto-orient (only available in newer Imagick versions)
            if (method_exists($img, 'autoOrientImage')) {
                $img->autoOrientImage();
            }

            // Convert to grayscale
            $img->setImageColorspace(Imagick::COLORSPACE_GRAY);

            // Resize if too large
            $w = $img->getImageWidth();
            $h = $img->getImageHeight();
            if (max($w, $h) > $this->maxDimension) {
                $img->resizeImage($this->maxDimension, $this->maxDimension, Imagick::FILTER_LANCZOS, 1, true);
            }

            // Enhance contrast
            $img->normalizeImage();

            // Adaptive threshold for text clarity
            $img->adaptiveThresholdImage(
                max(1, (int) ($img->getImageWidth() / 15)),
                max(1, (int) ($img->getImageHeight() / 15)),
                0
            );

            // Sharpen
            $img->sharpenImage(0, 1);

            // Despeckle
            $img->despeckleImage();

            // Write to temp
            $tmpPath = $this->tmpDir . '/ocr_preprocessed_' . uniqid() . '.png';
            $img->setImageFormat('png');
            $img->writeImage($tmpPath);
            $img->destroy();

            return $tmpPath;
        } catch (\Exception $e) {
            // Fall back to GD
            return $this->preprocessWithGD($imagePath);
        }
    }

    /**
     * Preprocess using GD (more widely available).
     */
    private function preprocessWithGD(string $imagePath): ?string
    {
        $info = @getimagesize($imagePath);
        if (!$info) return null;

        $mime = $info['mime'];
        switch ($mime) {
            case 'image/jpeg': $src = @imagecreatefromjpeg($imagePath); break;
            case 'image/png':  $src = @imagecreatefrompng($imagePath);  break;
            case 'image/gif':  $src = @imagecreatefromgif($imagePath);  break;
            case 'image/webp': $src = @imagecreatefromwebp($imagePath); break;
            default: return null;
        }

        if (!$src) return null;

        $w = imagesx($src);
        $h = imagesy($src);

        // Resize if needed
        if (max($w, $h) > $this->maxDimension) {
            $ratio = $this->maxDimension / max($w, $h);
            $newW = (int) ($w * $ratio);
            $newH = (int) ($h * $ratio);
            $resized = imagecreatetruecolor($newW, $newH);
            imagecopyresampled($resized, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($src);
            $src = $resized;
            $w = $newW;
            $h = $newH;
        }

        // Convert to grayscale
        imagefilter($src, IMG_FILTER_GRAYSCALE);

        // Increase contrast
        imagefilter($src, IMG_FILTER_CONTRAST, -30);

        // Increase brightness slightly
        imagefilter($src, IMG_FILTER_BRIGHTNESS, 10);

        // Sharpen using convolution
        $sharpen = [
            [0, -1, 0],
            [-1, 5, -1],
            [0, -1, 0],
        ];
        imageconvolution($src, $sharpen, 1, 0);

        // Write to temp
        $tmpPath = $this->tmpDir . '/ocr_preprocessed_' . uniqid() . '.png';
        imagepng($src, $tmpPath);
        imagedestroy($src);

        return $tmpPath;
    }

    // ══════════════════════════════════════════════
    //  IMAGE QUALITY ASSESSMENT
    // ══════════════════════════════════════════════

    /**
     * Assess image quality for OCR suitability.
     */
    public function assessImageQuality(string $imagePath): array
    {
        $info = @getimagesize($imagePath);
        $fileSize = @filesize($imagePath);

        $result = [
            'width'       => $info[0] ?? 0,
            'height'      => $info[1] ?? 0,
            'resolution'  => ($info[0] ?? 0) . 'x' . ($info[1] ?? 0),
            'file_size'   => $fileSize,
            'mime_type'   => $info['mime'] ?? 'unknown',
            'score'       => 0.5,
            'level'       => 'medium',
            'is_blurry'   => false,
            'issues'      => [],
        ];

        if (!$info) {
            $result['score'] = 0.1;
            $result['level'] = 'unreadable';
            $result['issues'][] = 'Cannot read image dimensions';
            return $result;
        }

        $w = $info[0];
        $h = $info[1];
        $score = 0.5;

        // Resolution scoring
        $minDim = min($w, $h);
        if ($minDim >= 1000) {
            $score += 0.2;
        } elseif ($minDim >= 600) {
            $score += 0.1;
        } elseif ($minDim < 300) {
            $score -= 0.2;
            $result['issues'][] = 'Very low resolution';
        }

        // File size check
        if ($fileSize < 10000) { // < 10KB suspicious
            $score -= 0.15;
            $result['issues'][] = 'Very small file size';
        } elseif ($fileSize > 100000) { // > 100KB good detail
            $score += 0.1;
        }

        // Aspect ratio (receipts are typically tall/portrait)
        $aspect = $h > 0 ? $w / $h : 1;
        if ($aspect >= 0.3 && $aspect <= 0.8) {
            $score += 0.1; // Portrait, typical receipt
        } elseif ($aspect >= 0.8 && $aspect <= 1.5) {
            $score += 0.05; // Near-square, could be receipt photo
        }

        // Blur detection via GD (variance of Laplacian approximation)
        $blur = $this->detectBlur($imagePath);
        if ($blur !== null) {
            $result['is_blurry'] = $blur < 50;
            if ($blur < 30) {
                $score -= 0.2;
                $result['issues'][] = 'Image appears very blurry';
            } elseif ($blur < 50) {
                $score -= 0.1;
                $result['issues'][] = 'Image may be slightly blurry';
            } else {
                $score += 0.1;
            }
        }

        $score = max(0.0, min(1.0, $score));

        $result['score'] = round($score, 3);
        if ($score >= 0.7) {
            $result['level'] = 'good';
        } elseif ($score >= 0.4) {
            $result['level'] = 'medium';
        } else {
            $result['level'] = 'poor';
        }

        return $result;
    }

    /**
     * Simple blur detection using Laplacian variance via GD.
     * Returns a score — higher = sharper, lower = blurrier.
     */
    private function detectBlur(string $imagePath): ?float
    {
        $info = @getimagesize($imagePath);
        if (!$info) return null;

        switch ($info['mime']) {
            case 'image/jpeg': $src = @imagecreatefromjpeg($imagePath); break;
            case 'image/png':  $src = @imagecreatefrompng($imagePath);  break;
            default: return null;
        }

        if (!$src) return null;

        // Downsample to 200px wide for speed
        $w = imagesx($src);
        $h = imagesy($src);
        $newW = 200;
        $newH = (int) ($h * ($newW / $w));
        $small = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($small, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($src);

        // Convert to grayscale array
        imagefilter($small, IMG_FILTER_GRAYSCALE);

        // Compute Laplacian variance
        $sum = 0;
        $sumSq = 0;
        $count = 0;

        for ($y = 1; $y < $newH - 1; $y++) {
            for ($x = 1; $x < $newW - 1; $x++) {
                $c  = (imagecolorat($small, $x, $y) & 0xFF);
                $n  = (imagecolorat($small, $x, $y - 1) & 0xFF);
                $s  = (imagecolorat($small, $x, $y + 1) & 0xFF);
                $e  = (imagecolorat($small, $x + 1, $y) & 0xFF);
                $w2 = (imagecolorat($small, $x - 1, $y) & 0xFF);

                $laplacian = abs($n + $s + $e + $w2 - 4 * $c);
                $sum += $laplacian;
                $sumSq += $laplacian * $laplacian;
                $count++;
            }
        }

        imagedestroy($small);

        if ($count === 0) return null;

        $mean = $sum / $count;
        $variance = ($sumSq / $count) - ($mean * $mean);

        return round(sqrt(max(0, $variance)), 2);
    }

    // ══════════════════════════════════════════════
    //  PERCEPTUAL HASH (for duplicate detection)
    // ══════════════════════════════════════════════

    /**
     * Compute a perceptual hash (aHash algorithm) for duplicate detection.
     * Returns a 16-char hex string (64-bit hash).
     */
    public function computePerceptualHash(string $imagePath): ?string
    {
        if ($this->imagickAvailable) {
            return $this->phashImagick($imagePath);
        }

        return $this->phashGD($imagePath);
    }

    private function phashImagick(string $imagePath): ?string
    {
        try {
            $img = new Imagick($imagePath);
            $img->resizeImage(8, 8, Imagick::FILTER_LANCZOS, 1, true);
            $img->setImageColorspace(Imagick::COLORSPACE_GRAY);

            // Get pixel values
            $pixels = [];
            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 8; $x++) {
                    $pixel = $img->getImagePixelColor($x, $y);
                    $colors = $pixel->getColor();
                    $pixels[] = $colors['r'];
                }
            }

            $img->destroy();
            return $this->hashFromPixels($pixels);
        } catch (\Exception $e) {
            return $this->phashGD($imagePath);
        }
    }

    private function phashGD(string $imagePath): ?string
    {
        $info = @getimagesize($imagePath);
        if (!$info) return null;

        switch ($info['mime']) {
            case 'image/jpeg': $src = @imagecreatefromjpeg($imagePath); break;
            case 'image/png':  $src = @imagecreatefrompng($imagePath);  break;
            case 'image/gif':  $src = @imagecreatefromgif($imagePath);  break;
            case 'image/webp': $src = @imagecreatefromwebp($imagePath); break;
            default: return null;
        }

        if (!$src) return null;

        // Resize to 8x8
        $small = imagecreatetruecolor(8, 8);
        imagecopyresampled($small, $src, 0, 0, 0, 0, 8, 8, imagesx($src), imagesy($src));
        imagedestroy($src);
        imagefilter($small, IMG_FILTER_GRAYSCALE);

        $pixels = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $pixels[] = imagecolorat($small, $x, $y) & 0xFF;
            }
        }

        imagedestroy($small);
        return $this->hashFromPixels($pixels);
    }

    /**
     * Convert pixel array to hex hash string.
     */
    private function hashFromPixels(array $pixels): string
    {
        $avg = array_sum($pixels) / max(count($pixels), 1);
        $bits = '';
        foreach ($pixels as $v) {
            $bits .= ($v >= $avg) ? '1' : '0';
        }

        // Convert 64 bits to 16-char hex
        $hex = '';
        for ($i = 0; $i < 64; $i += 4) {
            $hex .= dechex(bindec(substr($bits, $i, 4)));
        }

        return $hex;
    }

    /**
     * Compute Hamming distance between two hex hash strings.
     * Lower distance = more similar images.
     */
    public static function hammingDistance(string $hash1, string $hash2): int
    {
        if (strlen($hash1) !== strlen($hash2)) return PHP_INT_MAX;

        $dist = 0;
        for ($i = 0; $i < strlen($hash1); $i++) {
            $xor = hexdec($hash1[$i]) ^ hexdec($hash2[$i]);
            $dist += substr_count(decbin($xor), '1');
        }

        return $dist;
    }

    // ══════════════════════════════════════════════
    //  HELPERS
    // ══════════════════════════════════════════════

    /**
     * Calculate overall extraction confidence based on what fields were found.
     */
    private function calculateExtractionConfidence(array $structured, float $ocrConfidence): float
    {
        $score = $ocrConfidence * 0.4; // 40% weight from OCR confidence

        // Did we find an amount?
        if ($structured['amount'] !== null) $score += 20;
        // Did we find a vendor?
        if ($structured['vendor'] !== null) $score += 15;
        // Did we find a date?
        if ($structured['date'] !== null) $score += 15;
        // Did we find a receipt number?
        if ($structured['receipt_no'] !== null) $score += 5;
        // Did we find line items?
        if (!empty($structured['items'])) $score += 5;

        return min(100, round($score, 1));
    }

    /**
     * Locate the Tesseract binary.
     */
    /**
     * Check if a shell function is available (not disabled).
     */
    private function isShellAvailable(string $func = 'shell_exec'): bool
    {
        if (!function_exists($func)) return false;
        $disabled = explode(',', ini_get('disable_functions') ?: '');
        $disabled = array_map('trim', $disabled);
        return !in_array($func, $disabled);
    }

    private function findTesseract(): string
    {
        // If shell functions are disabled, we can't detect Tesseract
        if (!$this->isShellAvailable('shell_exec')) {
            return '';
        }

        // Try common locations
        $candidates = [
            'tesseract',                                  // In PATH
            '/usr/bin/tesseract',                         // Linux default
            '/usr/local/bin/tesseract',                   // macOS Homebrew
            'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',  // Windows default
            'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
        ];

        foreach ($candidates as $path) {
            try {
                $check = @shell_exec(escapeshellarg($path) . ' --version 2>&1');
                if ($check && stripos($check, 'tesseract') !== false) {
                    return $path;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        // Try bare 'tesseract' with which/where
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                $which = @shell_exec('where tesseract 2>&1');
            } else {
                $which = @shell_exec('which tesseract 2>&1');
            }

            if ($which && stripos($which, 'not found') === false && stripos($which, 'Could not find') === false) {
                return trim(explode("\n", $which)[0]);
            }
        } catch (\Throwable $e) {
            // Shell not available
        }

        return '';
    }

    /**
     * Check if Tesseract is available.
     */
    public function isTesseractAvailable(): bool
    {
        return $this->tesseractAvailable;
    }

    /**
     * Return a standard error result.
     */
    private function errorResult(string $msg): array
    {
        return [
            'success'    => false,
            'error'      => $msg,
            'raw_text'   => '',
            'structured' => [
                'vendor' => null, 'amount' => null, 'subtotal' => null,
                'tax' => null, 'date' => null, 'receipt_no' => null,
                'items' => [], 'amounts_found' => [],
            ],
            'ocr_confidence' => 0,
            'extraction_confidence' => 0,
            'image_quality' => ['score' => 0, 'level' => 'unknown'],
            'file_hash' => null,
            'phash' => null,
            'tesseract_available' => $this->tesseractAvailable,
        ];
    }
}
