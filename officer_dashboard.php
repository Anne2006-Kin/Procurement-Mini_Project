<?php
require __DIR__ . '/auth.php';
require_role('procurement_officer');

$tabs = ['dashboard' => 'Dashboard', 'quotations' => 'Quotations', 'po' => 'Purchase Orders',
         'amendments' => 'Amendments', 'deliveries' => 'Deliveries'];
$tab = $_POST['tab'] ?? $_GET['tab'] ?? 'dashboard';
if (!isset($tabs[$tab])) $tab = 'dashboard';
$errors = [];
$req_id = (int)($_GET['req_id'] ?? $_POST['req_id'] ?? 0);
$po_id  = (int)($_GET['po_id']  ?? $_POST['po_id']  ?? 0);
function done($qs) { header('Location: officer_dashboard.php?' . $qs); exit; }

/* ---------- reference lists ---------- */
$sups  = $pdo->query('SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name')->fetchAll();
$items = $pdo->query('SELECT item_id, item_code, item_desc FROM items ORDER BY item_code')->fetchAll();
$reqs  = $pdo->query('SELECT r.req_id, r.req_no, d.dept_code FROM requisitions r JOIN departments d ON d.dept_id=r.dept_id ORDER BY r.req_id DESC')->fetchAll();
$pos   = $pdo->query('SELECT po_id, po_no FROM purchase_orders ORDER BY po_id DESC')->fetchAll();

/* ---------- lines for the selected requisition / PO ---------- */
$lines = [];
if ($tab === 'po' && $req_id) {                       // requested vs already ordered
    $st = $pdo->prepare('SELECT ri.item_id, i.item_code, i.item_desc, ri.qty_requested,
        (SELECT COALESCE(SUM(pi.qty_ordered),0) FROM purchase_orders po JOIN po_items pi ON pi.po_id=po.po_id
          WHERE po.req_id=ri.req_id AND pi.item_id=ri.item_id) AS ordered
        FROM requisition_items ri JOIN items i ON i.item_id=ri.item_id WHERE ri.req_id=?');
    $st->execute([$req_id]); $lines = $st->fetchAll();
}
if ($tab === 'amendments' && $po_id) {                // items already on the PO
    $st = $pdo->prepare('SELECT pi.item_id, i.item_code, i.item_desc, pi.qty_ordered
        FROM po_items pi JOIN items i ON i.item_id=pi.item_id WHERE pi.po_id=?');
    $st->execute([$po_id]); $lines = $st->fetchAll();
}
if ($tab === 'deliveries' && $po_id) {                // ordered vs received so far
    $st = $pdo->prepare('SELECT pi.item_id, i.item_code, i.item_desc, pi.qty_ordered,
        (SELECT COALESCE(SUM(di.qty_delivered),0) FROM deliveries d JOIN delivery_items di ON di.delivery_id=d.delivery_id
          WHERE d.po_id=pi.po_id AND di.item_id=pi.item_id) AS received
        FROM po_items pi JOIN items i ON i.item_id=pi.item_id WHERE pi.po_id=?');
    $st->execute([$po_id]); $lines = $st->fetchAll();
}

/* ---------- form handling ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($tab === 'dashboard') {                       // officer sets the requisition status
        $st = $_POST['status'] ?? ''; $rm = trim($_POST['remarks'] ?? '');
        if (!in_array($st, REQ_STATUSES, true)) $errors[] = 'Invalid status.';
        $ck = $pdo->prepare('SELECT 1 FROM requisitions WHERE req_id=?'); $ck->execute([$req_id]);
        if (!$ck->fetch()) $errors[] = 'Requisition not found.';
        if (!$errors) {
            $pdo->prepare('UPDATE requisitions SET status=?, status_remarks=?, status_updated_at=NOW() WHERE req_id=?')
                ->execute([$st, $rm === '' ? null : $rm, $req_id]);
            done('tab=dashboard&ok=1');
        }
    }

    if ($tab === 'quotations') {                      // BR3: a supplier may quote many items
        $sid = (int)($_POST['supplier_id'] ?? 0);
        $ok = $pdo->prepare('SELECT 1 FROM suppliers WHERE supplier_id=?'); $ok->execute([$sid]);
        if (!$ok->fetch()) $errors[] = 'Select a supplier.';
        $valid = array_column($items, 'item_id'); $q = [];
        foreach (($_POST['item_id'] ?? []) as $i => $id) {
            $id = (int)$id; $price = (float)($_POST['price'][$i] ?? 0);
            if ($id === 0 && $price == 0) continue;
            if (!in_array($id, $valid, true) || $price <= 0) { $errors[] = 'Each line needs an item and a price above 0.'; break; }
            $q[$id] = $price;
        }
        if (!$q && !$errors) $errors[] = 'Add at least one quoted item.';
        if (!$errors) {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO quotations (supplier_id, quote_date) VALUES (?, CURDATE())')->execute([$sid]);
            $qid = $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO quotation_items (quotation_id,item_id,quoted_price) VALUES (?,?,?)');
            foreach ($q as $id => $p) $ins->execute([$qid, $id, $p]);
            $pdo->commit(); done('tab=quotations&ok=1');
        }
    }

    if ($tab === 'po' && $lines) {                    // BR3 one supplier per PO, BR5 quantity limit
        $sid = (int)($_POST['supplier_id'] ?? 0);
        $ok = $pdo->prepare('SELECT 1 FROM suppliers WHERE supplier_id=?'); $ok->execute([$sid]);
        if (!$ok->fetch()) $errors[] = 'Select ONE supplier for this PO.';
        $pr = $pdo->prepare('SELECT qi.quoted_price FROM quotation_items qi JOIN quotations q ON q.quotation_id=qi.quotation_id
            WHERE q.supplier_id=? AND qi.item_id=? ORDER BY q.quote_date DESC, q.quotation_id DESC LIMIT 1');
        $order = [];
        foreach ($lines as $l) {
            $q = (int)($_POST['qty'][$l['item_id']] ?? 0); if ($q <= 0) continue;
            if ($q > max(0, $l['qty_requested'] - $l['ordered'])) { $errors[] = $l['item_code'] . ': quantity exceeds the approved requisition (BR5). Issue the PO for the approved quantity, then document an amendment.'; continue; }
            $pr->execute([$sid, $l['item_id']]); $price = $pr->fetchColumn();
            if (!$price) { $errors[] = $l['item_code'] . ': this supplier has no quotation for the item.'; continue; }
            $order[$l['item_id']] = [$q, $price];
        }
        if (!$order && !$errors) $errors[] = 'Enter at least one quantity.';
        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO purchase_orders (po_no,po_date,req_id,supplier_id,issued_by) VALUES (?,CURDATE(),?,?,?)')
                    ->execute(['TMP-' . uniqid(), $req_id, $sid, $_SESSION['user_id']]);
                $pid = (int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE purchase_orders SET po_no=? WHERE po_id=?')->execute([sprintf('PO-%s-%04d', date('Y'), $pid), $pid]);
                $ins = $pdo->prepare('INSERT INTO po_items (po_id,item_id,qty_ordered,unit_price) VALUES (?,?,?,?)');
                foreach ($order as $iid => [$q, $p]) $ins->execute([$pid, $iid, $q, $p]);   // DB trigger also checks BR5
                $pdo->commit(); done('tab=po&ok=1');
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = str_contains($e->getMessage(), 'BR5') ? 'BR5: quantity exceeds the approved requisition without an amendment.' : 'Could not save the purchase order.';
            }
        }
    }

    if ($tab === 'amendments' && $lines) {            // BR5: documented amendment
        $iid = (int)($_POST['item_id'] ?? 0); $extra = (int)($_POST['extra_qty'] ?? 0); $why = trim($_POST['reason'] ?? '');
        if (!in_array($iid, array_column($lines, 'item_id'), true)) $errors[] = 'Select an item on this PO.';
        if ($extra < 1) $errors[] = 'Extra quantity must be at least 1.';
        if ($why === '') $errors[] = 'A documented reason is required (BR5).';
        if (!$errors) {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO po_amendments (po_id,item_id,extra_qty,reason,approved_by,approved_date) VALUES (?,?,?,?,?,CURDATE())')
                ->execute([$po_id, $iid, $extra, $why, $_SESSION['user_id']]);
            $pdo->prepare('UPDATE po_items SET qty_ordered = qty_ordered + ? WHERE po_id=? AND item_id=?')->execute([$extra, $po_id, $iid]);
            $pdo->commit(); done('tab=amendments&ok=1&po_id=' . $po_id);
        }
    }

    if ($tab === 'deliveries' && $lines) {            // BR4: several deliveries per PO
        $rec = [];
        foreach ($lines as $l) {
            $q = (int)($_POST['qty'][$l['item_id']] ?? 0); if ($q <= 0) continue;
            if ($q > $l['qty_ordered'] - $l['received']) { $errors[] = $l['item_code'] . ': more than the quantity still due.'; continue; }
            $rec[$l['item_id']] = $q;
        }
        if (!$rec && !$errors) $errors[] = 'Enter at least one received quantity.';
        if (!$errors) {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO deliveries (delivery_no,po_id,delivery_date) VALUES (?,?,CURDATE())')->execute(['TMP-' . uniqid(), $po_id]);
            $did = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE deliveries SET delivery_no=? WHERE delivery_id=?')->execute([sprintf('DEL-%s-%04d', date('Y'), $did), $did]);
            $ins = $pdo->prepare('INSERT INTO delivery_items (delivery_id,item_id,qty_delivered) VALUES (?,?,?)');
            foreach ($rec as $iid => $q) $ins->execute([$did, $iid, $q]);
            $pdo->commit(); done('tab=deliveries&ok=1&po_id=' . $po_id);
        }
    }
}

/* ---------- data for display ---------- */
function status($o, $d) {
    if ($o == 0) return ['Not ordered', 'b-none'];
    if ($d == 0) return ['Ordered', 'b-ord'];
    return $d >= $o ? ['Fully delivered', 'b-full'] : ['Partially delivered', 'b-part'];
}
$rows = $reqRows = $quotes = $poList = $amends = [];
if ($tab === 'dashboard') {
    $reqRows = $pdo->query('SELECT r.req_id, r.req_no, r.req_date, d.dept_code, r.status, r.status_remarks
        FROM requisitions r JOIN departments d ON d.dept_id=r.dept_id ORDER BY r.req_id DESC')->fetchAll();
    $rows = $pdo->query("SELECT r.req_no, d.dept_code, i.item_code, i.item_desc, ri.qty_requested,
        (SELECT COALESCE(SUM(pi.qty_ordered),0) FROM purchase_orders po JOIN po_items pi ON pi.po_id=po.po_id
          WHERE po.req_id=r.req_id AND pi.item_id=ri.item_id) AS qty_ordered,
        (SELECT COALESCE(SUM(di.qty_delivered),0) FROM purchase_orders po JOIN deliveries dl ON dl.po_id=po.po_id
          JOIN delivery_items di ON di.delivery_id=dl.delivery_id
          WHERE po.req_id=r.req_id AND di.item_id=ri.item_id) AS qty_delivered
        FROM requisitions r JOIN departments d ON d.dept_id=r.dept_id
        JOIN requisition_items ri ON ri.req_id=r.req_id JOIN items i ON i.item_id=ri.item_id
        GROUP BY r.req_id, ri.item_id ORDER BY r.req_date DESC, r.req_no")->fetchAll();
}
if ($tab === 'quotations') {
    $quotes = $pdo->query('SELECT q.quotation_id, q.quote_date, s.supplier_name, i.item_desc, qi.quoted_price
        FROM quotations q JOIN suppliers s ON s.supplier_id=q.supplier_id
        JOIN quotation_items qi ON qi.quotation_id=q.quotation_id JOIN items i ON i.item_id=qi.item_id
        ORDER BY q.quotation_id DESC, i.item_desc')->fetchAll();
}
if ($tab === 'po') {
    $poList = $pdo->query('SELECT po.po_no, po.po_date, r.req_no, s.supplier_name,
        (SELECT COUNT(*) FROM po_items WHERE po_id=po.po_id) AS n
        FROM purchase_orders po JOIN requisitions r ON r.req_id=po.req_id
        JOIN suppliers s ON s.supplier_id=po.supplier_id ORDER BY po.po_id DESC')->fetchAll();
}
if ($tab === 'amendments') {
    $amends = $pdo->query('SELECT po.po_no, i.item_desc, a.extra_qty, a.reason, a.approved_date, u.full_name
        FROM po_amendments a JOIN purchase_orders po ON po.po_id=a.po_id JOIN items i ON i.item_id=a.item_id
        JOIN users u ON u.user_id=a.approved_by ORDER BY a.amendment_id DESC')->fetchAll();
}
$itemOpts = '';
foreach ($items as $it) $itemOpts .= '<option value="' . (int)$it['item_id'] . '">' . h($it['item_code'] . ' - ' . $it['item_desc']) . '</option>';
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Officer Dashboard</title>
  <link rel="stylesheet" href="style.css">
</head>
<body class="dash">
  <div class="topbar">
    <h2>Procurement Officer</h2>
    <span><?= h($_SESSION['name']) ?> | <a href="logout.php">Logout</a></span>
  </div>

  <div class="tabs">
    <?php foreach ($tabs as $key => $label): ?>
      <a href="officer_dashboard.php?tab=<?= $key ?>" class="<?= $key === $tab ? 'active' : '' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (isset($_GET['ok'])): ?><p class="ok">Saved successfully.</p><?php endif; ?>
  <?php foreach ($errors as $e): ?><p class="err"><?= h($e) ?></p><?php endforeach; ?>

<?php if ($tab === 'dashboard'): ?>
  <h3>Requisition status (visible to the department)</h3>
  <div class="table-wrap" style="margin-bottom:24px"><table>
    <tr><th>Req No</th><th>Dept</th><th>Date</th><th>Current status</th><th>Change status</th></tr>
    <?php foreach ($reqRows as $q): ?>
    <tr><td><?= h($q['req_no']) ?></td><td><?= h($q['dept_code']) ?></td><td><?= h($q['req_date']) ?></td>
        <td><?= status_badge($q['status']) ?><?php if ($q['status_remarks']): ?><br><small><?= h($q['status_remarks']) ?></small><?php endif; ?></td>
        <td><form method="post" class="inline-form">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="tab" value="dashboard"><input type="hidden" name="req_id" value="<?= (int)$q['req_id'] ?>">
          <select name="status"><?php foreach (REQ_STATUSES as $s): ?><option <?= $s === $q['status'] ? 'selected' : '' ?>><?= h($s) ?></option><?php endforeach; ?></select>
          <input name="remarks" placeholder="Remarks (optional)" maxlength="255" value="<?= h($q['status_remarks']) ?>">
          <button>Update</button></form></td></tr>
    <?php endforeach; if (!$reqRows): ?><tr><td colspan="5">No requisitions yet.</td></tr><?php endif; ?>
  </table></div>

  <h3>Item progress (requested / ordered / delivered)</h3>
  <div class="table-wrap"><table>
    <tr><th>Req No</th><th>Dept</th><th>Item</th><th>Requested</th><th>Ordered</th><th>Delivered</th><th>Progress</th></tr>
    <?php foreach ($rows as $r): [$label, $cls] = status($r['qty_ordered'], $r['qty_delivered']); ?>
    <tr><td><?= h($r['req_no']) ?></td><td><?= h($r['dept_code']) ?></td><td><?= h($r['item_code'] . ' ' . $r['item_desc']) ?></td>
        <td><?= (int)$r['qty_requested'] ?></td><td><?= (int)$r['qty_ordered'] ?></td><td><?= (int)$r['qty_delivered'] ?></td>
        <td><span class="badge <?= $cls ?>"><?= h($label) ?></span></td></tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="7">No requisitions yet.</td></tr><?php endif; ?>
  </table></div>

<?php elseif ($tab === 'quotations'): ?>
  <div class="table-wrap" style="padding:20px;margin-bottom:20px"><h3>Record a quotation</h3>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="tab" value="quotations">
      <label>Supplier</label>
      <select name="supplier_id"><option value="0">-- select --</option>
        <?php foreach ($sups as $s): ?><option value="<?= (int)$s['supplier_id'] ?>"><?= h($s['supplier_name']) ?></option><?php endforeach; ?>
      </select>
      <table id="lines"><tr><th>Item</th><th style="width:160px">Quoted price</th></tr>
        <tr><td><select name="item_id[]"><option value="0">-- select item --</option><?= $itemOpts ?></select></td>
            <td><input type="number" step="0.01" min="0" name="price[]"></td></tr></table>
      <p><a href="#" id="add">+ Add another item</a></p>
      <button style="max-width:240px">Save quotation</button>
    </form></div>
  <div class="table-wrap"><table>
    <tr><th>Quote #</th><th>Date</th><th>Supplier</th><th>Item</th><th>Price</th></tr>
    <?php foreach ($quotes as $r): ?><tr><td><?= (int)$r['quotation_id'] ?></td><td><?= h($r['quote_date']) ?></td><td><?= h($r['supplier_name']) ?></td><td><?= h($r['item_desc']) ?></td><td><?= number_format($r['quoted_price'], 2) ?></td></tr>
    <?php endforeach; if (!$quotes): ?><tr><td colspan="5">No quotations yet.</td></tr><?php endif; ?>
  </table></div>
  <script>
    document.getElementById('add').onclick = function (e) {
      e.preventDefault();
      var t = document.getElementById('lines'); t.appendChild(t.rows[1].cloneNode(true));
      var r = t.rows[t.rows.length - 1]; r.querySelector('select').value = '0'; r.querySelector('input').value = '';
    };
  </script>

<?php elseif ($tab === 'po'): ?>
  <div class="table-wrap" style="padding:20px;margin-bottom:20px"><h3>Create a purchase order</h3>
    <form method="get"><input type="hidden" name="tab" value="po"><label>Requisition</label>
      <select name="req_id" onchange="this.form.submit()"><option value="0">-- select requisition --</option>
        <?php foreach ($reqs as $r): ?><option value="<?= (int)$r['req_id'] ?>" <?= $r['req_id'] == $req_id ? 'selected' : '' ?>><?= h($r['req_no'] . ' (' . $r['dept_code'] . ')') ?></option><?php endforeach; ?>
      </select></form>
    <?php if ($lines): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="tab" value="po"><input type="hidden" name="req_id" value="<?= $req_id ?>">
      <label>Supplier (one per PO)</label>
      <select name="supplier_id"><option value="0">-- select --</option>
        <?php foreach ($sups as $s): ?><option value="<?= (int)$s['supplier_id'] ?>"><?= h($s['supplier_name']) ?></option><?php endforeach; ?>
      </select>
      <table><tr><th>Item</th><th>Requested</th><th>Already ordered</th><th>Remaining</th><th style="width:120px">Order qty</th></tr>
        <?php foreach ($lines as $l): $rem = max(0, $l['qty_requested'] - $l['ordered']); ?>
        <tr><td><?= h($l['item_code'] . ' ' . $l['item_desc']) ?></td><td><?= (int)$l['qty_requested'] ?></td><td><?= (int)$l['ordered'] ?></td><td><?= $rem ?></td>
            <td><input type="number" min="0" name="qty[<?= (int)$l['item_id'] ?>]"></td></tr>
        <?php endforeach; ?></table>
      <p style="font-size:13px">Unit price comes from the supplier's latest quotation.</p>
      <button style="max-width:240px">Issue PO</button>
    </form>
    <?php endif; ?></div>
  <div class="table-wrap"><table>
    <tr><th>PO No</th><th>Date</th><th>Requisition</th><th>Supplier</th><th>Lines</th></tr>
    <?php foreach ($poList as $p): ?><tr><td><?= h($p['po_no']) ?></td><td><?= h($p['po_date']) ?></td><td><?= h($p['req_no']) ?></td><td><?= h($p['supplier_name']) ?></td><td><?= (int)$p['n'] ?></td></tr>
    <?php endforeach; if (!$poList): ?><tr><td colspan="5">No purchase orders yet.</td></tr><?php endif; ?>
  </table></div>

<?php elseif ($tab === 'amendments'): ?>
  <div class="table-wrap" style="padding:20px;margin-bottom:20px"><h3>Document an amendment</h3>
    <form method="get"><input type="hidden" name="tab" value="amendments"><label>Purchase order</label>
      <select name="po_id" onchange="this.form.submit()"><option value="0">-- select PO --</option>
        <?php foreach ($pos as $p): ?><option value="<?= (int)$p['po_id'] ?>" <?= $p['po_id'] == $po_id ? 'selected' : '' ?>><?= h($p['po_no']) ?></option><?php endforeach; ?>
      </select></form>
    <?php if ($lines): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="tab" value="amendments"><input type="hidden" name="po_id" value="<?= $po_id ?>">
      <label>Item</label>
      <select name="item_id"><?php foreach ($lines as $l): ?><option value="<?= (int)$l['item_id'] ?>"><?= h($l['item_code'] . ' ' . $l['item_desc'] . ' (ordered ' . $l['qty_ordered'] . ')') ?></option><?php endforeach; ?></select>
      <label>Extra quantity</label><input type="number" min="1" name="extra_qty">
      <label>Reason</label><input name="reason" maxlength="255">
      <button style="max-width:240px">Approve amendment</button>
    </form>
    <?php endif; ?></div>
  <div class="table-wrap"><table>
    <tr><th>PO</th><th>Item</th><th>Extra qty</th><th>Reason</th><th>Approved by</th><th>Date</th></tr>
    <?php foreach ($amends as $a): ?><tr><td><?= h($a['po_no']) ?></td><td><?= h($a['item_desc']) ?></td><td><?= (int)$a['extra_qty'] ?></td><td><?= h($a['reason']) ?></td><td><?= h($a['full_name']) ?></td><td><?= h($a['approved_date']) ?></td></tr>
    <?php endforeach; if (!$amends): ?><tr><td colspan="6">No amendments yet.</td></tr><?php endif; ?>
  </table></div>

<?php elseif ($tab === 'deliveries'): ?>
  <div class="table-wrap" style="padding:20px"><h3>Record a delivery</h3>
    <form method="get"><input type="hidden" name="tab" value="deliveries"><label>Purchase order</label>
      <select name="po_id" onchange="this.form.submit()"><option value="0">-- select PO --</option>
        <?php foreach ($pos as $p): ?><option value="<?= (int)$p['po_id'] ?>" <?= $p['po_id'] == $po_id ? 'selected' : '' ?>><?= h($p['po_no']) ?></option><?php endforeach; ?>
      </select></form>
    <?php if ($lines): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="tab" value="deliveries"><input type="hidden" name="po_id" value="<?= $po_id ?>">
      <table><tr><th>Item</th><th>Ordered</th><th>Received so far</th><th>Still due</th><th style="width:120px">Received now</th></tr>
        <?php foreach ($lines as $l): $due = $l['qty_ordered'] - $l['received']; ?>
        <tr><td><?= h($l['item_code'] . ' ' . $l['item_desc']) ?></td><td><?= (int)$l['qty_ordered'] ?></td><td><?= (int)$l['received'] ?></td><td><?= (int)$due ?></td>
            <td><input type="number" min="0" max="<?= (int)$due ?>" name="qty[<?= (int)$l['item_id'] ?>]" <?= $due > 0 ? '' : 'disabled' ?>></td></tr>
        <?php endforeach; ?></table>
      <button style="max-width:240px">Save delivery</button>
    </form>
    <?php endif; ?></div>
<?php endif; ?>
</body>
</html>