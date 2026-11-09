<?php
require __DIR__ . '/auth.php';
require_role('department_requester');
// Only this user's own department (taken from the session, never from the URL)
$stmt = $pdo->prepare("
  SELECT r.req_no, r.req_date, r.status, r.status_remarks, r.status_updated_at, i.item_desc, ri.qty_requested,
   (SELECT COALESCE(SUM(pi.qty_ordered),0) FROM purchase_orders po JOIN po_items pi ON pi.po_id=po.po_id
     WHERE po.req_id=r.req_id AND pi.item_id=ri.item_id) AS qty_ordered,
   (SELECT COALESCE(SUM(di.qty_delivered),0) FROM purchase_orders po JOIN deliveries dl ON dl.po_id=po.po_id
     JOIN delivery_items di ON di.delivery_id=dl.delivery_id
     WHERE po.req_id=r.req_id AND di.item_id=ri.item_id) AS qty_delivered
  FROM requisitions r JOIN requisition_items ri ON ri.req_id=r.req_id JOIN items i ON i.item_id=ri.item_id
  WHERE r.dept_id = ? ORDER BY r.req_date DESC, r.req_no DESC");
$stmt->execute([$_SESSION['dept_id']]);
$rows = $stmt->fetchAll();
$dn = $pdo->prepare('SELECT dept_code, dept_name FROM departments WHERE dept_id=?'); $dn->execute([$_SESSION['dept_id']]); $dept = $dn->fetch();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="refresh" content="30">
  <title>Department Dashboard</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="dash">
  <div class="topbar">
    <h2>Department Dashboard: <?= h($dept['dept_code'] . ' - ' . $dept['dept_name']) ?></h2>
    <span><a href="new_requisition.php">+ New Requisition</a> | <?= h($_SESSION['name']) ?> | <a href="logout.php">Logout</a></span>
  </div>
  <?php if (isset($_GET['created'])): ?><p class="ok">Requisition submitted.</p><?php endif; ?>
  <p style="font-size:13px;color:#6b7280">Status is set by the procurement officer. This page refreshes every 30 seconds.</p>
  <div class="table-wrap"><table>
    <tr><th>Req No</th><th>Date</th><th>Item</th><th>Requested</th><th>Ordered</th><th>Delivered</th><th>Status</th><th>Officer remarks</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr><td><?= h($r['req_no']) ?></td><td><?= h($r['req_date']) ?></td><td><?= h($r['item_desc']) ?></td>
        <td><?= (int)$r['qty_requested'] ?></td><td><?= (int)$r['qty_ordered'] ?></td><td><?= (int)$r['qty_delivered'] ?></td>
        <td><?= status_badge($r['status']) ?></td>
        <td><?= h($r['status_remarks']) ?><?php if ($r['status_updated_at']): ?><br><small><?= h($r['status_updated_at']) ?></small><?php endif; ?></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="8">No requisitions for your department yet.</td></tr><?php endif; ?>
  </table></div>
</body>
</html>