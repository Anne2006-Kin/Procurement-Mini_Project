<?php
require __DIR__ . '/auth.php';
require_role('department_requester');
$items = $pdo->query('SELECT item_id, item_code, item_desc, unit FROM items ORDER BY item_code')->fetchAll();
$validIds = array_column($items, 'item_id');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Merge duplicate items and validate each line (BR2: many line items with quantities)
    $lines = [];
    foreach (($_POST['item_id'] ?? []) as $i => $id) {
        $id = (int)$id; $qty = (int)($_POST['qty'][$i] ?? 0);
        if ($id === 0 && $qty === 0) continue;
        if (!in_array($id, $validIds, true)) { $errors[] = 'Invalid item selected.'; break; }
        if ($qty < 1) { $errors[] = 'Each quantity must be at least 1.'; break; }
        $lines[$id] = ($lines[$id] ?? 0) + $qty;
    }
    if (!$lines && !$errors) $errors[] = 'Add at least one item.';
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            // Department and requester come from the session, never from the form
            $pdo->prepare('INSERT INTO requisitions (req_no, req_date, dept_id, requested_by) VALUES (?, CURDATE(), ?, ?)')
                ->execute(['TMP-' . uniqid(), $_SESSION['dept_id'], $_SESSION['user_id']]);
            $rid = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE requisitions SET req_no = ? WHERE req_id = ?')
                ->execute([sprintf('REQ-%s-%04d', date('Y'), $rid), $rid]);
            $ins = $pdo->prepare('INSERT INTO requisition_items (req_id, item_id, qty_requested) VALUES (?, ?, ?)');
            foreach ($lines as $id => $qty) $ins->execute([$rid, $id, $qty]);
            $pdo->commit();
            header('Location: dept_dashboard.php?created=1'); exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Could not save the requisition. Please try again.';
        }
    }
}
$opts = '';
foreach ($items as $it) $opts .= '<option value="'.(int)$it['item_id'].'">'.h($it['item_code'].' - '.$it['item_desc'].' ('.$it['unit'].')').'</option>';
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><title>New Requisition</title>
<link rel="stylesheet" href="style.css"></head><body class="dash">
<div class="topbar"><h2>New Purchase Requisition</h2><span><a href="dept_dashboard.php">Back to dashboard</a> | <a href="logout.php">Logout</a></span></div>
<?php foreach ($errors as $e): ?><p class="err"><?= h($e) ?></p><?php endforeach; ?>
<div class="table-wrap" style="padding:20px">
<form method="post">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <table id="lines"><tr><th>Item</th><th style="width:140px">Quantity</th></tr>
    <tr><td><select name="item_id[]"><option value="0">-- select item --</option><?= $opts ?></select></td>
        <td><input type="number" name="qty[]" min="1"></td></tr>
  </table>
  <p><a href="#" id="add">+ Add another item</a></p>
  <button type="submit" style="max-width:240px">Submit Requisition</button>
</form></div>
<script>
document.getElementById('add').onclick = function(e){
  e.preventDefault();
  var t = document.getElementById('lines');
  t.appendChild(t.rows[1].cloneNode(true));
  var r = t.rows[t.rows.length-1];
  r.querySelector('select').value = '0'; r.querySelector('input').value = '';
};
</script></body></html>