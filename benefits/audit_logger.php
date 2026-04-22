<?php
/**
 * audit_logger.php
 * Helper class for logging claim audit activities
 * Logs all claim-related actions to claims_audit table
 */

class ClaimAuditLogger {
    private $conn;
    private $username;
    private $ip_address;
    private $user_agent;

    public function __construct($mysqli_connection, $username = null) {
        $this->conn = $mysqli_connection;
        $this->username = $username ?? ($_SESSION['username'] ?? 'system');
        $this->ip_address = $this->getClientIP();
        $this->user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }

    /**
     * Log a claim action
     * @param int $claim_id
     * @param string $action (submitted, verified, approved, rejected, flagged, updated, deleted)
     * @param string $old_status
     * @param string $new_status
     * @param array $changes Array of changes made
     * @param string $notes Optional notes
     * @return bool
     */
    public function logAction($claim_id, $action, $old_status = null, $new_status = null, $changes = [], $notes = '') {
        $changes_json = !empty($changes) ? json_encode($changes) : null;
        
        $stmt = $this->conn->prepare("
            INSERT INTO claims_audit (
                claim_id, action, actor, action_timestamp,
                old_status, new_status, notes, ip_address, user_agent, changes_json
            ) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error);
            return false;
        }

        $stmt->bind_param(
            'isssssss',
            $claim_id,
            $action,
            $this->username,
            $old_status,
            $new_status,
            $notes,
            $this->ip_address,
            $this->user_agent
        );

        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    /**
     * Log status change with amount and category
     */
    public function logStatusChange($claim_id, $action, $old_status, $new_status, $old_amount = null, $new_amount = null, $old_category = null, $new_category = null, $notes = '') {
        $changes_json = json_encode([
            'old_status' => $old_status,
            'new_status' => $new_status,
            'old_amount' => $old_amount,
            'new_amount' => $new_amount,
            'old_category' => $old_category,
            'new_category' => $new_category
        ]);

        $stmt = $this->conn->prepare("
            INSERT INTO claims_audit (
                claim_id, action, actor, action_timestamp,
                old_status, new_status, old_amount, new_amount,
                old_category, new_category, notes, ip_address, user_agent, changes_json
            ) VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error);
            return false;
        }

        $stmt->bind_param(
            'issssddssssss',
            $claim_id,
            $action,
            $this->username,
            $old_status,
            $new_status,
            $old_amount,
            $new_amount,
            $old_category,
            $new_category,
            $notes,
            $this->ip_address,
            $this->user_agent,
            $changes_json
        );

        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    /**
     * Get claim audit history
     */
    public function getClaimAuditHistory($claim_id, $limit = 50) {
        $stmt = $this->conn->prepare("
            SELECT 
                id, claim_id, action, actor, action_timestamp,
                old_status, new_status, old_amount, new_amount,
                old_category, new_category, notes
            FROM claims_audit
            WHERE claim_id = ?
            ORDER BY action_timestamp DESC
            LIMIT ?
        ");

        $stmt->bind_param('ii', $claim_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $history = [];
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
        $stmt->close();
        return $history;
    }

    /**
     * Get audit log for user within date range
     */
    public function getUserAuditLog($username, $from_date, $to_date, $limit = 100) {
        $stmt = $this->conn->prepare("
            SELECT 
                id, claim_id, action, actor, action_timestamp,
                old_status, new_status, notes
            FROM claims_audit
            WHERE actor = ? 
            AND DATE(action_timestamp) BETWEEN ? AND ?
            ORDER BY action_timestamp DESC
            LIMIT ?
        ");

        $stmt->bind_param('sssi', $username, $from_date, $to_date, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }
        $stmt->close();
        return $logs;
    }

    /**
     * Get all audit logs within date range
     */
    public function getAllAuditLogs($from_date, $to_date, $action_filter = null, $limit = 500) {
        $query = "
            SELECT 
                id, claim_id, action, actor, action_timestamp,
                old_status, new_status, notes
            FROM claims_audit
            WHERE DATE(action_timestamp) BETWEEN ? AND ?
        ";

        $params = [$from_date, $to_date];
        $types = 'ss';

        if (!empty($action_filter)) {
            $query .= " AND action = ?";
            $params[] = $action_filter;
            $types .= 's';
        }

        $query .= " ORDER BY action_timestamp DESC LIMIT ?";
        $params[] = $limit;
        $types .= 'i';

        $stmt = $this->conn->prepare($query);
        
        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error);
            return [];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }
        $stmt->close();
        return $logs;
    }

    /**
     * Get client IP address
     */
    private function getClientIP() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        }
        return trim($ip);
    }

    /**
     * Get audit statistics
     */
    public function getAuditStats($from_date, $to_date) {
        $stmt = $this->conn->prepare("
            SELECT 
                action,
                COUNT(*) as count,
                COUNT(DISTINCT claim_id) as unique_claims,
                COUNT(DISTINCT actor) as unique_users
            FROM claims_audit
            WHERE DATE(action_timestamp) BETWEEN ? AND ?
            GROUP BY action
            ORDER BY count DESC
        ");

        $stmt->bind_param('ss', $from_date, $to_date);
        $stmt->execute();
        $result = $stmt->get_result();

        $stats = [];
        while ($row = $result->fetch_assoc()) {
            $stats[] = $row;
        }
        $stmt->close();
        return $stats;
    }

    /**
     * Get claims audit report with activity summary
     */
    public function getClaimsAuditReport($from_date, $to_date, $claim_id = null) {
        $query = "
            SELECT 
                ca.id,
                ca.claim_id,
                ca.action,
                ca.actor,
                ca.action_timestamp,
                ca.old_status,
                ca.new_status,
                ca.notes,
                c.amount,
                c.category,
                c.vendor,
                e.fullname as employee_name,
                e.employee_id
            FROM claims_audit ca
            LEFT JOIN claims c ON ca.claim_id = c.id
            LEFT JOIN employees e ON c.created_by = e.username
            WHERE DATE(ca.action_timestamp) BETWEEN ? AND ?
        ";

        $params = [$from_date, $to_date];
        $types = 'ss';

        if (!empty($claim_id)) {
            $query .= " AND ca.claim_id = ?";
            $params[] = $claim_id;
            $types .= 'i';
        }

        $query .= " ORDER BY ca.action_timestamp DESC LIMIT 1000";

        $stmt = $this->conn->prepare($query);
        
        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error);
            return [];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $logs = [];
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }
        $stmt->close();
        return $logs;
    }
}

// Usage example:
// require_once('audit_logger.php');
// $audit = new ClaimAuditLogger($conn, $_SESSION['username']);
// $audit->logStatusChange($claim_id, 'approved', 'pending', 'approved', $old_amount, $new_amount, $old_cat, $new_cat, 'Approved by benefits officer');
// $history = $audit->getClaimAuditHistory($claim_id);
// $report = $audit->getClaimsAuditReport('2026-01-01', '2026-02-07');
?>