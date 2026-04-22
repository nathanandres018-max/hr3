<?php
/**
 * PATCH FOR verify_attendance.php
 * Add this validation after parsing the input JSON
 * (around line 55 in the original file)
 */

// === NEW: LIVENESS VERIFICATION CHECK ===
// Ensure liveness flag is present
$isLive = isset($input['isLive']) && $input['isLive'] === true ? true : false;
$blinkCount = isset($input['blinkCount']) ? (int)$input['blinkCount'] : 0;
$faceConfidence = isset($input['confidence']) ? (float)$input['confidence'] : 0;

// Block attendance if liveness not verified
if (!$isLive) {
    vlog("Attendance rejected: Liveness verification failed");
    echo json_encode([
        'success' => false,
        'error' => 'Liveness verification failed. Please complete face detection and blink verification.',
        'code' => 'liveness_failed'
    ]);
    exit;
}

// Log liveness confidence metrics
vlog("Liveness verified: isLive={$isLive}, blinkCount={$blinkCount}, confidence={$faceConfidence}%");

// === END LIVENESS CHECK ===

// Continue with existing attendance logic...
?>