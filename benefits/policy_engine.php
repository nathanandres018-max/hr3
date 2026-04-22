<?php
/**
 * policy_engine.php
 * ============================================================
 * AI-Powered Policy Checker Engine
 * ============================================================
 * Core engine for reimbursement policy compliance checking,
 * risk scoring, violation generation, and NLP Q&A.
 *
 * Features:
 *   1. Policy Compliance Checker
 *   2. Pre-Submission Validation
 *   3. Natural Language Policy Explanation
 *   4. Policy Violation Reason Generator
 *   5. Risk / Fraud Scoring (0-100)
 *
 * Requires: ../connection.php ($conn — mysqli)
 * ============================================================
 */

declare(strict_types=1);

class PolicyEngine
{
    private $conn;
    private $policies = [];
    private $rules = [];

    // ========================================================
    // Constructor — initialize with DB connection
    // ========================================================
    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
        $this->loadPolicies();
        $this->loadRules();
    }

    // ========================================================
    // Load policies from reimbursement_policies table
    // ========================================================
    private function loadPolicies(): void
    {
        $result = $this->conn->query("SELECT * FROM reimbursement_policies ORDER BY category ASC");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $this->policies[$row['category']] = $row;
            }
        }
    }

    // ========================================================
    // Load detailed rules from policy_rules table
    // ========================================================
    private function loadRules(): void
    {
        $result = $this->conn->query("SELECT * FROM policy_rules WHERE is_active = 1 ORDER BY category, rule_code");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $row['rule_json_parsed'] = !empty($row['rule_json']) ? json_decode($row['rule_json'], true) : [];
                $this->rules[$row['category']][] = $row;
            }
        }

        // If no rules in DB, use built-in defaults
        if (empty($this->rules)) {
            $this->loadDefaultRules();
        }
    }

    // ========================================================
    // Default rules (fallback if DB table not yet populated)
    // ========================================================
    private function loadDefaultRules(): void
    {
        $defaults = [
            'Meal' => [
                ['rule_code' => 'MEAL_MAX_AMOUNT', 'rule_type' => 'limit', 'rule_description' => 'Maximum meal reimbursement per claim is ₱500.00', 'rule_value' => 500.00, 'severity' => 'critical', 'section_ref' => 'Section 3.1', 'rule_json_parsed' => ['per' => 'claim']],
                ['rule_code' => 'MEAL_RECEIPT_REQUIRED', 'rule_type' => 'requirement', 'rule_description' => 'Official receipt is required for meal claims', 'rule_value' => null, 'severity' => 'critical', 'section_ref' => 'Section 3.2', 'rule_json_parsed' => []],
                ['rule_code' => 'MEAL_WINDOW', 'rule_type' => 'restriction', 'rule_description' => 'Meal claims must be submitted within 30 days of expense date', 'rule_value' => null, 'severity' => 'critical', 'section_ref' => 'Section 3.5', 'rule_json_parsed' => ['max_days_after' => 30]],
            ],
            'Travel' => [
                ['rule_code' => 'TRAVEL_MAX_AMOUNT', 'rule_type' => 'limit', 'rule_description' => 'Maximum travel reimbursement per trip is ₱2,000.00', 'rule_value' => 2000.00, 'severity' => 'critical', 'section_ref' => 'Section 2.1', 'rule_json_parsed' => ['per' => 'trip']],
                ['rule_code' => 'TRAVEL_RECEIPT_REQUIRED', 'rule_type' => 'requirement', 'rule_description' => 'Transportation receipts are required', 'rule_value' => null, 'severity' => 'critical', 'section_ref' => 'Section 2.2', 'rule_json_parsed' => []],
                ['rule_code' => 'TRAVEL_WINDOW', 'rule_type' => 'restriction', 'rule_description' => 'Travel claims must be submitted within 30 days', 'rule_value' => null, 'severity' => 'critical', 'section_ref' => 'Section 2.4', 'rule_json_parsed' => ['max_days_after' => 30]],
            ],
            'Medical' => [
                ['rule_code' => 'MEDICAL_MAX_AMOUNT', 'rule_type' => 'limit', 'rule_description' => 'Annual medical cap is ₱15,000.00', 'rule_value' => 15000.00, 'severity' => 'critical', 'section_ref' => 'Section 4.1', 'rule_json_parsed' => ['per' => 'year']],
                ['rule_code' => 'MEDICAL_RECEIPT_REQUIRED', 'rule_type' => 'requirement', 'rule_description' => 'Medical receipt or billing statement required', 'rule_value' => null, 'severity' => 'critical', 'section_ref' => 'Section 4.2', 'rule_json_parsed' => []],
                ['rule_code' => 'MEDICAL_WINDOW', 'rule_type' => 'restriction', 'rule_description' => 'Medical claims must be submitted within 60 days', 'rule_value' => null, 'severity' => 'warning', 'section_ref' => 'Section 4.4', 'rule_json_parsed' => ['max_days_after' => 60]],
            ],
            'Supplies' => [
                ['rule_code' => 'SUPPLIES_MAX_AMOUNT', 'rule_type' => 'limit', 'rule_description' => 'Maximum office supply reimbursement per claim is ₱2,500.00', 'rule_value' => 2500.00, 'severity' => 'critical', 'section_ref' => 'Section 5.1', 'rule_json_parsed' => ['per' => 'claim']],
                ['rule_code' => 'SUPPLIES_WINDOW', 'rule_type' => 'restriction', 'rule_description' => 'Supply claims must be submitted within 30 days', 'rule_value' => null, 'severity' => 'critical', 'section_ref' => 'Section 5.4', 'rule_json_parsed' => ['max_days_after' => 30]],
            ],
            'Training' => [
                ['rule_code' => 'TRAINING_MAX_AMOUNT', 'rule_type' => 'limit', 'rule_description' => 'Maximum training reimbursement per event is ₱5,000.00', 'rule_value' => 5000.00, 'severity' => 'critical', 'section_ref' => 'Section 6.1', 'rule_json_parsed' => ['per' => 'event']],
                ['rule_code' => 'TRAINING_WINDOW', 'rule_type' => 'restriction', 'rule_description' => 'Training claims must be submitted within 30 days', 'rule_value' => null, 'severity' => 'critical', 'section_ref' => 'Section 6.4', 'rule_json_parsed' => ['max_days_after' => 30]],
            ],
            'Transportation' => [
                ['rule_code' => 'TRANSPORT_MAX_AMOUNT', 'rule_type' => 'limit', 'rule_description' => 'Maximum transportation per claim is ₱1,500.00', 'rule_value' => 1500.00, 'severity' => 'critical', 'section_ref' => 'Section 7.1', 'rule_json_parsed' => ['per' => 'claim']],
                ['rule_code' => 'TRANSPORT_WINDOW', 'rule_type' => 'restriction', 'rule_description' => 'Transportation claims must be submitted within 30 days', 'rule_value' => null, 'severity' => 'critical', 'section_ref' => 'Section 7.4', 'rule_json_parsed' => ['max_days_after' => 30]],
            ],
            'Accommodation' => [
                ['rule_code' => 'ACCOM_MAX_AMOUNT', 'rule_type' => 'limit', 'rule_description' => 'Maximum accommodation per night is ₱3,000.00', 'rule_value' => 3000.00, 'severity' => 'critical', 'section_ref' => 'Section 8.1', 'rule_json_parsed' => ['per' => 'night']],
                ['rule_code' => 'ACCOM_WINDOW', 'rule_type' => 'restriction', 'rule_description' => 'Accommodation claims must be submitted within 30 days', 'rule_value' => null, 'severity' => 'critical', 'section_ref' => 'Section 8.4', 'rule_json_parsed' => ['max_days_after' => 30]],
            ],
            'Communication' => [
                ['rule_code' => 'COMM_MAX_AMOUNT', 'rule_type' => 'limit', 'rule_description' => 'Maximum communication expense per month is ₱500.00', 'rule_value' => 500.00, 'severity' => 'critical', 'section_ref' => 'Section 9.1', 'rule_json_parsed' => ['per' => 'month']],
                ['rule_code' => 'COMM_WINDOW', 'rule_type' => 'restriction', 'rule_description' => 'Communication claims must be submitted within 30 days', 'rule_value' => null, 'severity' => 'critical', 'section_ref' => 'Section 9.4', 'rule_json_parsed' => ['max_days_after' => 30]],
            ],
            'Other' => [
                ['rule_code' => 'OTHER_MAX_AMOUNT', 'rule_type' => 'limit', 'rule_description' => 'Maximum miscellaneous claim is ₱5,000.00', 'rule_value' => 5000.00, 'severity' => 'critical', 'section_ref' => 'Section 10.1', 'rule_json_parsed' => ['per' => 'claim']],
                ['rule_code' => 'OTHER_WINDOW', 'rule_type' => 'restriction', 'rule_description' => 'Miscellaneous claims must be submitted within 30 days', 'rule_value' => null, 'severity' => 'critical', 'section_ref' => 'Section 10.4', 'rule_json_parsed' => ['max_days_after' => 30]],
            ],
        ];
        $this->rules = $defaults;
    }

    // ================================================================
    //  1. POLICY COMPLIANCE CHECKER
    //     Evaluate a claim (or draft) against all applicable rules.
    //     Returns: compliance_status, violations, recommendations
    // ================================================================
    public function checkCompliance(array $claim): array
    {
        $category    = trim($claim['category'] ?? $claim['claim_type'] ?? '');
        $amount      = floatval($claim['amount'] ?? 0);
        $vendor      = trim($claim['vendor'] ?? '');
        $expenseDate = trim($claim['expense_date'] ?? $claim['claim_date'] ?? '');
        $description = trim($claim['description'] ?? '');
        $hasReceipt  = !empty($claim['receipt_path']) || !empty($claim['has_receipt']);
        $claimId     = intval($claim['id'] ?? $claim['claim_id'] ?? 0);
        $createdBy   = trim($claim['created_by'] ?? '');

        $violations      = [];
        $recommendations = [];
        $checkedRules    = [];

        // --- A. Check if category is recognized ---
        $normalizedCat = $this->normalizeCategory($category);
        if (empty($normalizedCat)) {
            $violations[] = [
                'type'     => 'unknown_category',
                'severity' => 'critical',
                'rule'     => 'CATEGORY_VALID',
                'message'  => "The claim category '{$category}' is not recognized in the reimbursement policies.",
                'section'  => 'General Policy',
            ];
            $recommendations[] = 'Select a valid claim category from the approved list.';
        }

        // --- B. Get policy limit for this category ---
        $policyLimit = $this->getPolicyLimit($normalizedCat);
        $categoryRules = $this->rules[$normalizedCat] ?? $this->rules['Other'] ?? [];

        // --- C. Check each applicable rule ---
        foreach ($categoryRules as $rule) {
            $ruleCode = $rule['rule_code'];
            $checkedRules[] = $ruleCode;

            switch ($rule['rule_type']) {
                case 'limit':
                    // Amount limit check
                    if ($rule['rule_value'] !== null && $amount > 0) {
                        if ($amount > floatval($rule['rule_value'])) {
                            $excess = $amount - floatval($rule['rule_value']);
                            $violations[] = [
                                'type'     => 'exceeds_limit',
                                'severity' => $rule['severity'],
                                'rule'     => $ruleCode,
                                'message'  => "This claim of ₱" . number_format($amount, 2) . " exceeds the maximum allowance of ₱" . number_format(floatval($rule['rule_value']), 2) . " as stated in {$rule['section_ref']} of the reimbursement policy. Excess: ₱" . number_format($excess, 2) . ".",
                                'section'  => $rule['section_ref'],
                                'limit'    => floatval($rule['rule_value']),
                                'excess'   => $excess,
                            ];
                            $recommendations[] = "Reduce the claimed amount to ₱" . number_format(floatval($rule['rule_value']), 2) . " or below to comply with {$rule['section_ref']}.";
                        }
                    }
                    break;

                case 'requirement':
                    // Receipt requirement check
                    if (stripos($ruleCode, 'RECEIPT') !== false && !$hasReceipt) {
                        $violations[] = [
                            'type'     => 'missing_receipt',
                            'severity' => $rule['severity'],
                            'rule'     => $ruleCode,
                            'message'  => "{$rule['rule_description']} ({$rule['section_ref']}). No receipt has been uploaded.",
                            'section'  => $rule['section_ref'],
                        ];
                        $recommendations[] = 'Upload a valid receipt or invoice before submitting this claim.';
                    }
                    // Justification/description requirement
                    if (stripos($ruleCode, 'JUSTIFICATION') !== false && empty($description)) {
                        $violations[] = [
                            'type'     => 'missing_description',
                            'severity' => $rule['severity'],
                            'rule'     => $ruleCode,
                            'message'  => "{$rule['rule_description']} ({$rule['section_ref']}). No description provided.",
                            'section'  => $rule['section_ref'],
                        ];
                        $recommendations[] = 'Provide a written justification or description for this claim.';
                    }
                    break;

                case 'restriction':
                    // Submission window check
                    $ruleJson = $rule['rule_json_parsed'] ?? [];
                    $maxDays  = intval($ruleJson['max_days_after'] ?? 30);
                    if (!empty($expenseDate) && strtotime($expenseDate)) {
                        $daysSince = (int)((time() - strtotime($expenseDate)) / 86400);
                        if ($daysSince > $maxDays) {
                            $violations[] = [
                                'type'     => 'late_submission',
                                'severity' => $rule['severity'],
                                'rule'     => $ruleCode,
                                'message'  => "This claim is {$daysSince} days past the expense date, exceeding the {$maxDays}-day submission window ({$rule['section_ref']}).",
                                'section'  => $rule['section_ref'],
                                'days_late' => $daysSince - $maxDays,
                            ];
                            $recommendations[] = "Submit claims within {$maxDays} days of the expense date. This claim is " . ($daysSince - $maxDays) . " day(s) overdue.";
                        }
                    }
                    break;

                case 'frequency':
                    // Frequency check (how many claims per day)
                    $ruleJson = $rule['rule_json_parsed'] ?? [];
                    $maxPerDay = intval($ruleJson['max_claims_per_day'] ?? 0);
                    if ($maxPerDay > 0 && !empty($createdBy) && !empty($expenseDate)) {
                        $count = $this->getClaimCountForDate($createdBy, $normalizedCat, $expenseDate, $claimId);
                        if ($count >= $maxPerDay) {
                            $violations[] = [
                                'type'     => 'frequency_exceeded',
                                'severity' => $rule['severity'],
                                'rule'     => $ruleCode,
                                'message'  => "You already have {$count} {$normalizedCat} claim(s) for this date. Maximum allowed: {$maxPerDay} per day ({$rule['section_ref']}).",
                                'section'  => $rule['section_ref'],
                            ];
                            $recommendations[] = "You have reached the daily limit for {$normalizedCat} claims on this date.";
                        }
                    }
                    break;
            }
        }

        // --- D. Additional general checks ---

        // Empty amount
        if ($amount <= 0) {
            $violations[] = [
                'type'     => 'invalid_amount',
                'severity' => 'critical',
                'rule'     => 'AMOUNT_VALID',
                'message'  => 'Claim amount must be greater than zero.',
                'section'  => 'General Policy',
            ];
        }

        // Future date check
        if (!empty($expenseDate) && strtotime($expenseDate) > time()) {
            $violations[] = [
                'type'     => 'future_date',
                'severity' => 'critical',
                'rule'     => 'DATE_VALID',
                'message'  => 'Expense date cannot be in the future.',
                'section'  => 'General Policy',
            ];
            $recommendations[] = 'Correct the expense date to a date on or before today.';
        }

        // Empty vendor
        if (empty($vendor)) {
            $violations[] = [
                'type'     => 'missing_vendor',
                'severity' => 'warning',
                'rule'     => 'VENDOR_REQUIRED',
                'message'  => 'No vendor/merchant name provided. This may delay claim processing.',
                'section'  => 'General Policy',
            ];
            $recommendations[] = 'Provide the vendor or merchant name for the expense.';
        }

        // --- E. Determine compliance status ---
        $criticalCount = count(array_filter($violations, fn($v) => $v['severity'] === 'critical'));
        $warningCount  = count(array_filter($violations, fn($v) => $v['severity'] === 'warning'));

        if ($criticalCount > 0) {
            $complianceStatus = 'NON-COMPLIANT';
        } elseif ($warningCount > 0) {
            $complianceStatus = 'REQUIRES_REVIEW';
        } else {
            $complianceStatus = 'COMPLIANT';
        }

        // --- Generate summary explanation ---
        $explanation = $this->generateExplanation($complianceStatus, $violations, $normalizedCat, $amount, $policyLimit);

        return [
            'compliance_status' => $complianceStatus,
            'violations'        => $violations,
            'recommendations'   => array_unique($recommendations),
            'explanation'       => $explanation,
            'policy_limit'      => $policyLimit,
            'checked_rules'     => $checkedRules,
            'category'          => $normalizedCat,
            'amount'            => $amount,
        ];
    }

    // ================================================================
    //  2. PRE-SUBMISSION VALIDATION
    //     Real-time validation for employees before submitting.
    //     Returns field-specific warnings and blocking errors.
    // ================================================================
    public function validatePreSubmission(array $draft): array
    {
        $category    = trim($draft['category'] ?? $draft['claim_type'] ?? '');
        $amount      = floatval($draft['amount'] ?? 0);
        $vendor      = trim($draft['vendor'] ?? '');
        $expenseDate = trim($draft['expense_date'] ?? '');
        $description = trim($draft['description'] ?? '');
        $hasReceipt  = !empty($draft['has_receipt']);

        $errors   = []; // Blocking — prevent submission
        $warnings = []; // Advisory — allow submission
        $info     = []; // Informational tips

        $normalizedCat = $this->normalizeCategory($category);
        $policyLimit   = $this->getPolicyLimit($normalizedCat ?: 'Other');

        // Field: Category
        if (empty($category)) {
            $errors['category'] = 'Please select a claim category.';
        } elseif (empty($normalizedCat)) {
            $warnings['category'] = "Category '{$category}' may not have a specific policy. It will be evaluated as 'Other'.";
        }

        // Field: Amount
        if ($amount <= 0) {
            $errors['amount'] = 'Amount must be greater than zero.';
        } elseif ($policyLimit > 0 && $amount > $policyLimit) {
            $excess = $amount - $policyLimit;
            $errors['amount'] = "Amount ₱" . number_format($amount, 2) . " exceeds the policy limit of ₱" . number_format($policyLimit, 2) . " for {$normalizedCat}. Excess: ₱" . number_format($excess, 2) . ".";
        } elseif ($policyLimit > 0 && $amount > ($policyLimit * 0.9)) {
            $warnings['amount'] = "This amount is close to the policy limit of ₱" . number_format($policyLimit, 2) . " for {$normalizedCat}.";
        }

        // Field: Vendor
        if (empty($vendor)) {
            $warnings['vendor'] = 'Providing a vendor name helps speed up claim verification.';
        }

        // Field: Expense Date
        if (empty($expenseDate)) {
            $errors['expense_date'] = 'Expense date is required.';
        } else {
            $ts = strtotime($expenseDate);
            if (!$ts) {
                $errors['expense_date'] = 'Invalid date format.';
            } elseif ($ts > time()) {
                $errors['expense_date'] = 'Expense date cannot be in the future.';
            } else {
                $daysSince = (int)((time() - $ts) / 86400);
                $maxDays = $this->getMaxSubmissionDays($normalizedCat ?: 'Other');
                if ($daysSince > $maxDays) {
                    $errors['expense_date'] = "This expense is {$daysSince} days old, exceeding the {$maxDays}-day submission window.";
                } elseif ($daysSince > ($maxDays * 0.8)) {
                    $remaining = $maxDays - $daysSince;
                    $warnings['expense_date'] = "Only {$remaining} day(s) remaining to submit this claim.";
                }
            }
        }

        // Field: Receipt
        if (!$hasReceipt) {
            $warnings['receipt'] = 'A receipt is required for most claim categories. Please upload one.';
        }

        // Field: Description (for Other/misc)
        if (($normalizedCat === 'Other' || $normalizedCat === 'Others') && empty($description)) {
            $warnings['description'] = 'A justification is recommended for miscellaneous claims.';
        }

        // Overall status
        $canSubmit = empty($errors);
        $policyInfo = [];
        if ($policyLimit > 0) {
            $policyInfo['category'] = $normalizedCat;
            $policyInfo['limit'] = $policyLimit;
            $policyInfo['remaining'] = max(0, $policyLimit - $amount);
        }

        return [
            'can_submit'  => $canSubmit,
            'errors'      => $errors,
            'warnings'    => $warnings,
            'info'        => $info,
            'policy_info' => $policyInfo,
        ];
    }

    // ================================================================
    //  3. NATURAL LANGUAGE POLICY Q&A
    //     Answer questions about reimbursement policies using
    //     keyword matching and rule lookup.
    // ================================================================
    public function answerPolicyQuestion(string $question): array
    {
        $question = trim($question);
        if (empty($question)) {
            return [
                'answer'       => 'Please ask a question about our reimbursement policies.',
                'matched_rules' => [],
                'confidence'   => 0,
            ];
        }

        $qLower = mb_strtolower($question);
        $matchedRules = [];
        $bestScore    = 0;
        $answers      = [];

        // --- Keyword -> Category mapping ---
        $categoryKeywords = [
            'Meal'           => ['meal', 'food', 'lunch', 'dinner', 'breakfast', 'snack', 'eating', 'restaurant', 'dining', 'overtime meal', 'kain', 'pagkain'],
            'Travel'         => ['travel', 'trip', 'airfare', 'flight', 'plane', 'bus', 'train', 'commute', 'biyahe', 'lakbay'],
            'Medical'        => ['medical', 'doctor', 'hospital', 'medicine', 'health', 'clinic', 'prescription', 'checkup', 'dental', 'optical', 'surgery', 'gamot'],
            'Supplies'       => ['supply', 'supplies', 'office', 'stationery', 'paper', 'ink', 'pen', 'equipment'],
            'Training'       => ['training', 'seminar', 'workshop', 'course', 'certification', 'conference', 'education', 'learning'],
            'Transportation' => ['transportation', 'taxi', 'grab', 'uber', 'fare', 'ride', 'jeepney', 'tricycle', 'gas', 'fuel', 'parking'],
            'Accommodation'  => ['accommodation', 'hotel', 'lodging', 'stay', 'room', 'booking', 'inn', 'airbnb'],
            'Communication'  => ['communication', 'phone', 'internet', 'data', 'call', 'mobile', 'sim', 'wifi', 'load', 'postpaid'],
            'Other'          => ['other', 'misc', 'miscellaneous', 'general'],
        ];

        // --- Intent patterns ---
        $intents = [
            'limit'       => ['how much', 'maximum', 'limit', 'max', 'allowance', 'cap', 'allowed amount', 'magkano', 'hanggang', 'ceiling'],
            'receipt'     => ['receipt', 'document', 'proof', 'attach', 'upload', 'resibo', 'invoice', 'require', 'need'],
            'deadline'    => ['deadline', 'when', 'how long', 'days', 'submission window', 'late', 'submit', 'time limit', 'expir', 'kailan'],
            'eligible'    => ['can i', 'eligible', 'allowed', 'reimburs', 'cover', 'claim', 'may i', 'qualify', 'pwede', 'file', 'get back'],
            'frequency'   => ['how many', 'how often', 'per day', 'per month', 'times', 'frequency', 'ilang beses'],
            'process'     => ['process', 'long does it take', 'approval', 'status', 'when will', 'review', 'approved', 'how does'],
            'category'    => ['category', 'categories', 'type', 'types', 'kinds', 'what expenses'],
        ];

        // --- Detect relevant categories ---
        $detectedCategories = [];
        foreach ($categoryKeywords as $cat => $keywords) {
            foreach ($keywords as $kw) {
                if (stripos($qLower, $kw) !== false) {
                    $detectedCategories[$cat] = ($detectedCategories[$cat] ?? 0) + 1;
                }
            }
        }
        arsort($detectedCategories);

        // --- Detect intent ---
        $detectedIntents = [];
        foreach ($intents as $intent => $keywords) {
            foreach ($keywords as $kw) {
                if (stripos($qLower, $kw) !== false) {
                    $detectedIntents[$intent] = ($detectedIntents[$intent] ?? 0) + 1;
                }
            }
        }
        arsort($detectedIntents);

        $primaryIntent    = !empty($detectedIntents) ? array_key_first($detectedIntents) : 'eligible';
        $primaryCategory  = !empty($detectedCategories) ? array_key_first($detectedCategories) : null;

        // --- Generate answer based on intent + category ---

        // If asking about categories in general
        if ($primaryIntent === 'category' && empty($primaryCategory)) {
            $catList = array_keys($this->policies);
            if (empty($catList)) $catList = array_keys($categoryKeywords);
            $answers[] = "Our reimbursement policy covers the following categories: " . implode(', ', $catList) . ". Each category has its own maximum amount and specific requirements.";
            $confidence = 0.90;
        }
        // If asking about process
        elseif ($primaryIntent === 'process') {
            $answers[] = "Here's how the reimbursement claim process works:\n1. Submit your claim with the required receipt/documentation through the employee portal.\n2. The AI system automatically checks your claim against company policies.\n3. A Benefits Officer reviews the claim (typically within 3-5 business days).\n4. You'll be notified once the claim is approved, flagged, or requires additional information.";
            $confidence = 0.85;
        }
        // Category-specific answers
        elseif ($primaryCategory) {
            $rules = $this->rules[$primaryCategory] ?? [];
            $policy = $this->policies[$primaryCategory] ?? null;
            $limit = $policy ? floatval($policy['limit_amount']) : 0;

            switch ($primaryIntent) {
                case 'limit':
                    $answers[] = "The maximum reimbursement for {$primaryCategory} claims is ₱" . number_format($limit, 2) . ".";
                    foreach ($rules as $r) {
                        if ($r['rule_type'] === 'limit') {
                            $matchedRules[] = $r['rule_code'];
                            $per = $r['rule_json_parsed']['per'] ?? 'claim';
                            $answers[] = "{$r['rule_description']} (per {$per}, {$r['section_ref']}).";
                        }
                    }
                    $confidence = 0.95;
                    break;

                case 'receipt':
                    foreach ($rules as $r) {
                        if (stripos($r['rule_code'], 'RECEIPT') !== false) {
                            $matchedRules[] = $r['rule_code'];
                            $answers[] = $r['rule_description'] . " ({$r['section_ref']}).";
                        }
                    }
                    if (empty($answers)) {
                        $answers[] = "Yes, an official receipt or valid proof of purchase is required for {$primaryCategory} claims.";
                    }
                    $confidence = 0.92;
                    break;

                case 'deadline':
                    $maxDays = $this->getMaxSubmissionDays($primaryCategory);
                    $answers[] = "{$primaryCategory} claims must be submitted within {$maxDays} days of the expense date.";
                    foreach ($rules as $r) {
                        if ($r['rule_type'] === 'restriction' && isset($r['rule_json_parsed']['max_days_after'])) {
                            $matchedRules[] = $r['rule_code'];
                            $answers[] = $r['rule_description'] . " ({$r['section_ref']}).";
                        }
                    }
                    $confidence = 0.93;
                    break;

                case 'eligible':
                    $answers[] = "Yes, {$primaryCategory} expenses are reimbursable under our company policy.";
                    if ($limit > 0) {
                        $answers[] = "The maximum allowance is ₱" . number_format($limit, 2) . " per claim.";
                    }
                    $answers[] = "Make sure to provide a valid receipt and submit within the required timeframe.";
                    foreach ($rules as $r) {
                        if ($r['rule_type'] === 'restriction' && !isset($r['rule_json_parsed']['max_days_after'])) {
                            $matchedRules[] = $r['rule_code'];
                            $answers[] = "Note: " . $r['rule_description'] . " ({$r['section_ref']}).";
                        }
                    }
                    $confidence = 0.88;
                    break;

                case 'frequency':
                    $found = false;
                    foreach ($rules as $r) {
                        if ($r['rule_type'] === 'frequency') {
                            $matchedRules[] = $r['rule_code'];
                            $answers[] = $r['rule_description'] . " ({$r['section_ref']}).";
                            $found = true;
                        }
                    }
                    if (!$found) {
                        $answers[] = "There is no specific frequency limit for {$primaryCategory} claims, but each claim is individually reviewed against the policy limit of ₱" . number_format($limit, 2) . ".";
                    }
                    $confidence = 0.85;
                    break;

                default:
                    $answers[] = "Here's what you need to know about {$primaryCategory} reimbursement:";
                    if ($limit > 0) {
                        $answers[] = "• Maximum claim amount: ₱" . number_format($limit, 2);
                    }
                    $maxDays = $this->getMaxSubmissionDays($primaryCategory);
                    $answers[] = "• Submission deadline: {$maxDays} days after expense date";
                    $answers[] = "• A valid receipt is required";
                    foreach ($rules as $r) {
                        $matchedRules[] = $r['rule_code'];
                    }
                    $confidence = 0.80;
                    break;
            }
        }
        // General question — no category detected
        else {
            switch ($primaryIntent) {
                case 'limit':
                    $answers[] = "Here are the reimbursement limits by category:";
                    foreach ($this->policies as $cat => $p) {
                        $answers[] = "• {$cat}: ₱" . number_format(floatval($p['limit_amount']), 2);
                    }
                    $confidence = 0.90;
                    break;

                case 'receipt':
                    $answers[] = "Yes, official receipts or proof of purchase are required for all reimbursement claims. The receipt should show the vendor name, amount paid, and date of transaction.";
                    $confidence = 0.90;
                    break;

                case 'deadline':
                    $answers[] = "Most claims must be submitted within 30 days of the expense date. Medical claims have an extended window of 60 days. Always submit as early as possible to avoid issues.";
                    $confidence = 0.88;
                    break;

                default:
                    $answers[] = "I can help you with questions about our reimbursement policies. You can ask about:\n• Claim limits (e.g., \"What's the maximum for meal claims?\")\n• Receipt requirements (e.g., \"Do I need a receipt for travel?\")\n• Submission deadlines (e.g., \"How long do I have to submit?\")\n• Eligible expenses (e.g., \"Can I claim overtime meals?\")\n• Claim categories and procedures";
                    $confidence = 0.50;
                    break;
            }
        }

        // Compile final answer
        $finalAnswer = implode("\n", $answers);
        $confidence  = $confidence ?? 0.50;

        return [
            'answer'        => $finalAnswer,
            'matched_rules' => array_unique($matchedRules),
            'confidence'    => round($confidence, 2),
            'intent'        => $primaryIntent,
            'category'      => $primaryCategory,
        ];
    }

    // ================================================================
    //  4. POLICY VIOLATION REASON GENERATOR
    //     Generate human-readable explanations for flagged/rejected claims.
    // ================================================================
    public function generateViolationReasons(array $claim): array
    {
        $compliance = $this->checkCompliance($claim);
        $reasons = [];

        foreach ($compliance['violations'] as $v) {
            $reasons[] = [
                'violation'    => $v['type'],
                'severity'     => $v['severity'],
                'explanation'  => $v['message'],
                'policy_rule'  => $v['rule'] ?? 'N/A',
                'section'      => $v['section'] ?? 'General Policy',
            ];
        }

        return [
            'compliance_status' => $compliance['compliance_status'],
            'reasons'           => $reasons,
            'summary'           => $compliance['explanation'],
            'recommendations'   => $compliance['recommendations'],
        ];
    }

    // ================================================================
    //  5. RISK / FRAUD SCORING (0-100)
    //     Assign a risk score considering multiple factors.
    // ================================================================
    public function calculateRiskScore(array $claim): array
    {
        $category    = trim($claim['category'] ?? $claim['claim_type'] ?? '');
        $amount      = floatval($claim['amount'] ?? 0);
        $vendor      = trim($claim['vendor'] ?? '');
        $expenseDate = trim($claim['expense_date'] ?? $claim['claim_date'] ?? '');
        $createdBy   = trim($claim['created_by'] ?? '');
        $hasReceipt  = !empty($claim['receipt_path']) || !empty($claim['has_receipt']);
        $claimId     = intval($claim['id'] ?? $claim['claim_id'] ?? 0);

        $normalizedCat = $this->normalizeCategory($category);
        $policyLimit   = $this->getPolicyLimit($normalizedCat ?: 'Other');

        $riskFactors = [];
        $totalScore  = 0;

        // --- Factor 1: Amount vs Policy Limit (0-25 pts) ---
        if ($policyLimit > 0 && $amount > 0) {
            $ratio = $amount / $policyLimit;
            if ($ratio > 1.5) {
                $pts = 25;
                $riskFactors[] = ['factor' => 'amount_exceeds_limit', 'score' => $pts, 'severity' => 'high', 'detail' => "Claim exceeds policy limit by " . round(($ratio - 1) * 100) . "%."];
            } elseif ($ratio > 1.0) {
                $pts = 18;
                $riskFactors[] = ['factor' => 'amount_above_limit', 'score' => $pts, 'severity' => 'high', 'detail' => "Claim is above the policy limit of ₱" . number_format($policyLimit, 2) . "."];
            } elseif ($ratio > 0.9) {
                $pts = 8;
                $riskFactors[] = ['factor' => 'amount_near_limit', 'score' => $pts, 'severity' => 'medium', 'detail' => "Claim is close to the policy limit."];
            } else {
                $pts = 0;
            }
            $totalScore += $pts;
        }

        // --- Factor 2: Frequent Claims in Short Period (0-20 pts) ---
        if (!empty($createdBy)) {
            $recentCount = $this->getRecentClaimCount($createdBy, 7, $claimId);
            if ($recentCount >= 5) {
                $pts = 20;
                $riskFactors[] = ['factor' => 'high_frequency', 'score' => $pts, 'severity' => 'high', 'detail' => "{$recentCount} claims in the last 7 days."];
            } elseif ($recentCount >= 3) {
                $pts = 12;
                $riskFactors[] = ['factor' => 'moderate_frequency', 'score' => $pts, 'severity' => 'medium', 'detail' => "{$recentCount} claims in the last 7 days."];
            } elseif ($recentCount >= 2) {
                $pts = 5;
                $riskFactors[] = ['factor' => 'some_frequency', 'score' => $pts, 'severity' => 'low', 'detail' => "{$recentCount} claims in the last 7 days."];
            }
            $totalScore += ($pts ?? 0);
        }

        // --- Factor 3: Duplicate / Similar Claims (0-20 pts) ---
        if (!empty($createdBy) && $amount > 0) {
            $duplicates = $this->checkDuplicates($createdBy, $amount, $category, $expenseDate, $claimId);
            if ($duplicates['exact_match']) {
                $pts = 20;
                $riskFactors[] = ['factor' => 'exact_duplicate', 'score' => $pts, 'severity' => 'high', 'detail' => "Exact duplicate claim found (same amount, vendor, date)."];
            } elseif ($duplicates['similar_count'] > 0) {
                $pts = min(15, $duplicates['similar_count'] * 5);
                $riskFactors[] = ['factor' => 'similar_claims', 'score' => $pts, 'severity' => 'medium', 'detail' => "{$duplicates['similar_count']} similar claim(s) found in the last 30 days."];
            }
            $totalScore += ($pts ?? 0);
        }

        // --- Factor 4: Missing Receipt (0-10 pts) ---
        if (!$hasReceipt) {
            $pts = 10;
            $totalScore += $pts;
            $riskFactors[] = ['factor' => 'no_receipt', 'score' => $pts, 'severity' => 'medium', 'detail' => "No receipt uploaded."];
        }

        // --- Factor 5: Late Submission (0-10 pts) ---
        if (!empty($expenseDate) && strtotime($expenseDate)) {
            $daysSince = (int)((time() - strtotime($expenseDate)) / 86400);
            $maxDays = $this->getMaxSubmissionDays($normalizedCat ?: 'Other');
            if ($daysSince > $maxDays) {
                $pts = 10;
                $riskFactors[] = ['factor' => 'late_submission', 'score' => $pts, 'severity' => 'medium', 'detail' => "Claim is {$daysSince} days past expense date (max: {$maxDays} days)."];
                $totalScore += $pts;
            } elseif ($daysSince > ($maxDays * 0.8)) {
                $pts = 3;
                $riskFactors[] = ['factor' => 'borderline_late', 'score' => $pts, 'severity' => 'low', 'detail' => "Near the submission deadline ({$daysSince}/{$maxDays} days)."];
                $totalScore += $pts;
            }
        }

        // --- Factor 6: Weekend/Holiday Expense (0-5 pts) ---
        if (!empty($expenseDate) && strtotime($expenseDate)) {
            $dow = date('N', strtotime($expenseDate)); // 1=Mon, 7=Sun
            if ($dow >= 6) {
                $pts = 5;
                $totalScore += $pts;
                $riskFactors[] = ['factor' => 'weekend_expense', 'score' => $pts, 'severity' => 'low', 'detail' => "Expense incurred on a weekend."];
            }
        }

        // --- Factor 7: Empty/Missing Vendor (0-5 pts) ---
        if (empty($vendor)) {
            $pts = 5;
            $totalScore += $pts;
            $riskFactors[] = ['factor' => 'missing_vendor', 'score' => $pts, 'severity' => 'low', 'detail' => "No vendor/merchant name provided."];
        }

        // --- Factor 8: Borderline Policy Violations (0-5 pts) ---
        $compliance = $this->checkCompliance($claim);
        $warningViolations = count(array_filter($compliance['violations'], fn($v) => $v['severity'] === 'warning'));
        if ($warningViolations > 0) {
            $pts = min(5, $warningViolations * 2);
            $totalScore += $pts;
            $riskFactors[] = ['factor' => 'policy_warnings', 'score' => $pts, 'severity' => 'low', 'detail' => "{$warningViolations} policy warning(s) detected."];
        }

        // Clamp to 0-100
        $totalScore = min(100, max(0, $totalScore));

        // Determine risk level
        if ($totalScore >= 60) {
            $riskLevel = 'HIGH';
        } elseif ($totalScore >= 30) {
            $riskLevel = 'MEDIUM';
        } else {
            $riskLevel = 'LOW';
        }

        // Auto-flag recommendation
        $autoFlag = ($riskLevel === 'HIGH');

        return [
            'risk_score'     => $totalScore,
            'risk_level'     => $riskLevel,
            'risk_factors'   => $riskFactors,
            'auto_flag'      => $autoFlag,
            'compliance'     => $compliance['compliance_status'],
            'recommendation' => $this->getRiskRecommendation($riskLevel, $totalScore),
        ];
    }

    // ================================================================
    //  6. FULL POLICY AUDIT
    //     Run verification on all active policies.
    // ================================================================
    public function runPolicyAudit(): array
    {
        $results = [];
        $issues  = [];
        $passed  = 0;
        $failed  = 0;

        foreach ($this->policies as $cat => $policy) {
            $catRules = $this->rules[$cat] ?? [];
            $check = [
                'category'    => $cat,
                'limit'       => floatval($policy['limit_amount']),
                'has_rules'   => !empty($catRules),
                'rule_count'  => count($catRules),
                'has_limit'   => floatval($policy['limit_amount']) > 0,
                'issues'      => [],
                'status'      => 'PASS',
            ];

            // Check: has a limit defined
            if ($check['limit'] <= 0) {
                $check['issues'][] = "No limit amount defined for '{$cat}'.";
                $check['status'] = 'FAIL';
            }

            // Check: has rules loaded
            if (!$check['has_rules']) {
                $check['issues'][] = "No detailed rules found for '{$cat}'. Using default rules.";
                $check['status'] = ($check['status'] === 'FAIL') ? 'FAIL' : 'WARN';
            }

            // Check: has at least a limit rule
            $hasLimitRule = false;
            $hasReceiptRule = false;
            $hasWindowRule = false;
            foreach ($catRules as $r) {
                if ($r['rule_type'] === 'limit') $hasLimitRule = true;
                if (stripos($r['rule_code'], 'RECEIPT') !== false) $hasReceiptRule = true;
                if (stripos($r['rule_code'], 'WINDOW') !== false || (isset($r['rule_json_parsed']['max_days_after']))) $hasWindowRule = true;
            }

            if (!$hasLimitRule && !empty($catRules)) {
                $check['issues'][] = "Missing explicit limit rule for '{$cat}'.";
                $check['status'] = ($check['status'] === 'FAIL') ? 'FAIL' : 'WARN';
            }
            if (!$hasReceiptRule && !empty($catRules)) {
                $check['issues'][] = "Missing receipt requirement rule for '{$cat}'.";
                $check['status'] = ($check['status'] === 'FAIL') ? 'FAIL' : 'WARN';
            }

            if ($check['status'] === 'PASS') $passed++;
            else $failed++;

            if (!empty($check['issues'])) {
                $issues = array_merge($issues, $check['issues']);
            }

            $results[] = $check;
        }

        $overall = ($failed === 0) ? 'ALL_PASS' : 'HAS_ISSUES';

        return [
            'overall'       => $overall,
            'total_policies'=> count($this->policies),
            'passed'        => $passed,
            'failed'        => $failed,
            'results'       => $results,
            'issues'        => $issues,
            'checked_at'    => date('Y-m-d H:i:s'),
        ];
    }

    // ================================================================
    //  LOGGING: Save policy check results to DB
    // ================================================================
    public function logCheck(array $data): ?int
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO policy_check_logs
             (claim_id, checked_by, check_type, claim_category, claim_amount, claim_vendor, claim_date,
              compliance_status, risk_score, risk_level, violations_json, recommendations_json, explanation)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) return null;

        $claimId        = $data['claim_id'] ?? null;
        $checkedBy      = $data['checked_by'] ?? 'system';
        $checkType      = $data['check_type'] ?? 'compliance';
        $claimCategory  = $data['claim_category'] ?? null;
        $claimAmount    = $data['claim_amount'] ?? null;
        $claimVendor    = $data['claim_vendor'] ?? null;
        $claimDate      = $data['claim_date'] ?? null;
        $compStatus     = $data['compliance_status'] ?? null;
        $riskScore      = $data['risk_score'] ?? null;
        $riskLevel      = $data['risk_level'] ?? null;
        $violationsJson = isset($data['violations']) ? json_encode($data['violations']) : null;
        $recsJson       = isset($data['recommendations']) ? json_encode($data['recommendations']) : null;
        $explanation    = $data['explanation'] ?? null;

        $stmt->bind_param(
            'isssdsssissss',
            $claimId, $checkedBy, $checkType, $claimCategory, $claimAmount,
            $claimVendor, $claimDate, $compStatus, $riskScore, $riskLevel,
            $violationsJson, $recsJson, $explanation
        );

        if ($stmt->execute()) {
            $id = $stmt->insert_id;
            $stmt->close();
            return $id;
        }
        $stmt->close();
        return null;
    }

    // ========================================================
    // Log NLP chat interaction
    // ========================================================
    public function logChat(string $sessionId, string $askedBy, string $question, string $answer, array $matchedRules, float $confidence): ?int
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO policy_chat_logs (session_id, asked_by, question, answer, matched_rules, confidence) VALUES (?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) return null;

        $rulesJson = json_encode($matchedRules);
        $stmt->bind_param('sssssd', $sessionId, $askedBy, $question, $answer, $rulesJson, $confidence);

        if ($stmt->execute()) {
            $id = $stmt->insert_id;
            $stmt->close();
            return $id;
        }
        $stmt->close();
        return null;
    }

    // ========================================================
    // Log admin override
    // ========================================================
    public function logOverride(int $checkLogId, string $overrideBy, string $reason): bool
    {
        $stmt = $this->conn->prepare(
            "UPDATE policy_check_logs SET override_by = ?, override_reason = ?, override_at = NOW() WHERE id = ?"
        );
        if (!$stmt) return false;
        $stmt->bind_param('ssi', $overrideBy, $reason, $checkLogId);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    // ========================================================
    //  HELPER METHODS
    // ========================================================

    /**
     * Normalize a category string to match policy table keys.
     */
    private function normalizeCategory(string $cat): string
    {
        $cat = trim($cat);
        if (empty($cat)) return '';

        // Direct match
        if (isset($this->policies[$cat])) return $cat;

        // Case-insensitive match
        foreach ($this->policies as $key => $p) {
            if (strtolower($key) === strtolower($cat)) return $key;
        }

        // Alias mappings
        $aliases = [
            'meals' => 'Meal', 'food' => 'Meal', 'dining' => 'Meal',
            'travels' => 'Travel', 'trip' => 'Travel',
            'medicine' => 'Medical', 'health' => 'Medical', 'hospital' => 'Medical',
            'office supplies' => 'Supplies', 'supply' => 'Supplies',
            'training' => 'Training', 'seminar' => 'Training', 'workshop' => 'Training',
            'transport' => 'Transportation', 'taxi' => 'Transportation', 'fare' => 'Transportation',
            'hotel' => 'Accommodation', 'lodging' => 'Accommodation',
            'phone' => 'Communication', 'internet' => 'Communication',
            'others' => 'Other', 'misc' => 'Other', 'miscellaneous' => 'Other', 'general' => 'Other',
            'equipment' => 'Supplies',
            'utilities' => 'Other', 'office maintenance' => 'Other',
            'professional services' => 'Other', 'insurance' => 'Other',
            'subscriptions' => 'Other',
        ];

        $lower = strtolower($cat);
        if (isset($aliases[$lower])) return $aliases[$lower];

        // Partial match
        foreach ($aliases as $alias => $target) {
            if (strpos($lower, $alias) !== false) return $target;
        }

        return 'Other'; // Default fallback
    }

    /**
     * Get the policy limit for a given category.
     */
    public function getPolicyLimit(string $category): float
    {
        $cat = $this->normalizeCategory($category);
        if (isset($this->policies[$cat])) {
            return floatval($this->policies[$cat]['limit_amount']);
        }
        // Fallback: use 'Other' limit
        if (isset($this->policies['Other'])) {
            return floatval($this->policies['Other']['limit_amount']);
        }
        return 5000.00; // Hard default
    }

    /**
     * Get max submission days for a category.
     */
    private function getMaxSubmissionDays(string $category): int
    {
        $rules = $this->rules[$category] ?? [];
        foreach ($rules as $r) {
            if (isset($r['rule_json_parsed']['max_days_after'])) {
                return intval($r['rule_json_parsed']['max_days_after']);
            }
        }
        // Medical default 60, everything else 30
        if (strtolower($category) === 'medical') return 60;
        return 30;
    }

    /**
     * Count claims for a user on a specific date/category (for frequency check).
     */
    private function getClaimCountForDate(string $username, string $category, string $date, int $excludeId = 0): int
    {
        $sql = "SELECT COUNT(*) as cnt FROM claims WHERE created_by = ? AND category = ? AND expense_date = ? AND status NOT IN ('rejected','cancelled')";
        if ($excludeId > 0) $sql .= " AND id != {$excludeId}";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return 0;
        $stmt->bind_param('sss', $username, $category, $date);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return intval($row['cnt'] ?? 0);
    }

    /**
     * Count recent claims for a user (frequency scoring).
     */
    private function getRecentClaimCount(string $username, int $days = 7, int $excludeId = 0): int
    {
        $sql = "SELECT COUNT(*) as cnt FROM claims WHERE created_by = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND status NOT IN ('rejected','cancelled')";
        if ($excludeId > 0) $sql .= " AND id != {$excludeId}";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return 0;
        $stmt->bind_param('si', $username, $days);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return intval($row['cnt'] ?? 0);
    }

    /**
     * Check for duplicate/similar claims.
     */
    private function checkDuplicates(string $username, float $amount, string $category, string $date, int $excludeId = 0): array
    {
        $result = ['exact_match' => false, 'similar_count' => 0];

        // Exact duplicate check (same amount, category, date)
        $sql = "SELECT COUNT(*) as cnt FROM claims WHERE created_by = ? AND ROUND(amount,2) = ROUND(?,2) AND category = ? AND expense_date = ? AND status NOT IN ('rejected','cancelled')";
        if ($excludeId > 0) $sql .= " AND id != {$excludeId}";
        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('sdss', $username, $amount, $category, $date);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $result['exact_match'] = (intval($row['cnt'] ?? 0) > 0);
            $stmt->close();
        }

        // Similar claims (same amount within 30 days)
        $sql2 = "SELECT COUNT(*) as cnt FROM claims WHERE created_by = ? AND ABS(amount - ?) < 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND status NOT IN ('rejected','cancelled')";
        if ($excludeId > 0) $sql2 .= " AND id != {$excludeId}";
        $stmt2 = $this->conn->prepare($sql2);
        if ($stmt2) {
            $stmt2->bind_param('sd', $username, $amount);
            $stmt2->execute();
            $row2 = $stmt2->get_result()->fetch_assoc();
            $result['similar_count'] = intval($row2['cnt'] ?? 0);
            $stmt2->close();
        }

        return $result;
    }

    /**
     * Generate a human-readable summary explanation.
     */
    private function generateExplanation(string $status, array $violations, string $category, float $amount, float $limit): string
    {
        switch ($status) {
            case 'COMPLIANT':
                $msg = "This {$category} claim of ₱" . number_format($amount, 2) . " is fully compliant with company reimbursement policies.";
                if ($limit > 0) {
                    $msg .= " It is within the ₱" . number_format($limit, 2) . " limit.";
                }
                return $msg;

            case 'REQUIRES_REVIEW':
                $warnCount = count(array_filter($violations, fn($v) => $v['severity'] === 'warning'));
                return "This {$category} claim of ₱" . number_format($amount, 2) . " has {$warnCount} policy warning(s) that require attention. The claim can proceed but may be flagged for additional review.";

            case 'NON-COMPLIANT':
                $critCount = count(array_filter($violations, fn($v) => $v['severity'] === 'critical'));
                $reasons = [];
                foreach ($violations as $v) {
                    if ($v['severity'] === 'critical') {
                        $reasons[] = $v['type'];
                    }
                }
                return "This {$category} claim of ₱" . number_format($amount, 2) . " is NOT compliant. {$critCount} critical violation(s) found: " . implode(', ', $reasons) . ". This claim cannot be approved without resolving these issues.";

            default:
                return "Policy check completed.";
        }
    }

    /**
     * Get risk recommendation text.
     */
    private function getRiskRecommendation(string $level, int $score): string
    {
        switch ($level) {
            case 'HIGH':
                return "HIGH RISK (score: {$score}/100). This claim should be automatically flagged for manual review by a Benefits Officer. Multiple risk factors have been identified.";
            case 'MEDIUM':
                return "MEDIUM RISK (score: {$score}/100). This claim has some risk indicators and may warrant a closer look during the review process.";
            case 'LOW':
                return "LOW RISK (score: {$score}/100). This claim appears normal. Standard review process is recommended.";
            default:
                return "Risk assessment completed.";
        }
    }

    /**
     * Get all policies for frontend display.
     */
    public function getAllPolicies(): array
    {
        return $this->policies;
    }

    /**
     * Get all rules for a category.
     */
    public function getRulesForCategory(string $category): array
    {
        $cat = $this->normalizeCategory($category);
        return $this->rules[$cat] ?? [];
    }

    /**
     * Get all loaded rules.
     */
    public function getAllRules(): array
    {
        return $this->rules;
    }
}
