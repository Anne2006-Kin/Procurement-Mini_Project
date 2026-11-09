<?php
// Run once from CLI: php seed.php  (creates the procurement officer account only)
require __DIR__ . '/config.php';
$ins = $pdo->prepare('INSERT INTO users (full_name,email,password_hash,role,dept_id) VALUES (?,?,?,?,?)');
$ins->execute(['Procurement Officer', 'officer@gov.ph', password_hash('Officer@123', PASSWORD_DEFAULT), 'procurement_officer', null]);
echo "Seeded. Officer: officer@gov.ph / Officer@123\n";