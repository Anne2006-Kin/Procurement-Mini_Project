<?php
session_start();
require __DIR__ . '/db.php';

function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_check() {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403); exit('Invalid CSRF token');
    }
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Server-side access control: call at top of every protected page
function require_role($role) {
    if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
    if ($_SESSION['role'] !== $role) { http_response_code(403); exit('403 Forbidden: access denied.'); }
}
function dashboard_for($role) {
    return $role === 'procurement_officer' ? 'officer_dashboard.php' : 'dept_dashboard.php';
}

// Statuses the officer can set on a requisition (shown to the department)
const REQ_STATUSES = ['Pending','Approved','Rejected','Ordered','Partially Delivered','Delivered','Cancelled'];
function status_badge($s) {
    $m = ['Pending'=>'b-ord','Approved'=>'b-part','Rejected'=>'b-none','Ordered'=>'b-part',
          'Partially Delivered'=>'b-part','Delivered'=>'b-full','Cancelled'=>'b-none'];
    return '<span class="badge ' . ($m[$s] ?? 'b-ord') . '">' . h($s) . '</span>';
}