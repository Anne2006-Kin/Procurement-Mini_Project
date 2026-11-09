<?php
require __DIR__ . '/auth.php';
if (!empty($_SESSION['user_id'])) { header('Location: ' . dashboard_for($_SESSION['role'])); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $stmt = $pdo->prepare('SELECT user_id,full_name,password_hash,dept_id,role AS role_name
                           FROM users WHERE email = ?');
    $stmt->execute([trim($_POST['email'] ?? '')]);
    $u = $stmt->fetch();
    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $u['user_id'];
        $_SESSION['name'] = $u['full_name'];
        $_SESSION['role'] = $u['role_name'];
        $_SESSION['dept_id'] = $u['dept_id'];
        header('Location: ' . dashboard_for($u['role_name'])); exit;
    }
    $error = 'Invalid email or password.';
}
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><title>Login</title>
<link rel="stylesheet" href="style.css"></head><body class="auth"><div class="card">
<h2>Procurement System Login</h2>
<?php if (isset($_GET['registered'])): ?><p class="ok">Registered. Please log in.</p><?php endif; ?>
<?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
<form method="post">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <label>Email</label><input type="email" name="email" required>
  <label>Password</label><input type="password" name="password" required>
  <button type="submit">Login</button>
</form>
<p><a href="register.php">Create an account</a></p></div></body></html>