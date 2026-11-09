<?php
require __DIR__ . '/auth.php';
$errors = [];
$depts = $pdo->query('SELECT dept_id, dept_code, dept_name FROM departments ORDER BY dept_code')->fetchAll();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';
    $dept = (int)($_POST['dept_id'] ?? 0);
    if ($name === '') $errors[] = 'Name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';
    if (strlen($pass) < 8) $errors[] = 'Password must be at least 8 characters.';
    $chk = $pdo->prepare('SELECT 1 FROM departments WHERE dept_id = ?'); $chk->execute([$dept]);
    if (!$chk->fetch()) $errors[] = 'Select a valid department.';
    $chk = $pdo->prepare('SELECT 1 FROM users WHERE email = ?'); $chk->execute([$email]);
    if ($chk->fetch()) $errors[] = 'Email already registered.';
    if (!$errors) {
        // Self-registration can ONLY create the department_requester role
        $pdo->prepare('INSERT INTO users (full_name,email,password_hash,role,dept_id) VALUES (?,?,?,?,?)')
            ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), 'department_requester', $dept]);
        header('Location: login.php?registered=1'); exit;
    }
}
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><title>Register</title>
<link rel="stylesheet" href="style.css"></head><body class="auth"><div class="card">
<h2>Register (Department Requester)</h2>
<?php foreach ($errors as $e): ?><p class="err"><?= h($e) ?></p><?php endforeach; ?>
<form method="post">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <label>Full name</label><input name="full_name" value="<?= h($_POST['full_name'] ?? '') ?>" required>
  <label>Email</label><input type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required>
  <label>Password</label><input type="password" name="password" required>
  <label>Department</label>
  <select name="dept_id" required>
    <?php foreach ($depts as $d): ?><option value="<?= $d['dept_id'] ?>"><?= h($d['dept_code'].' - '.$d['dept_name']) ?></option><?php endforeach; ?>
  </select>
  <button type="submit">Register</button>
</form>
<p><a href="login.php">Back to login</a></p></div></body></html>