<?php
require 'config.php';
$stmt = $pdo->query("SHOW COLUMNS FROM members");
$cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo implode(', ', $cols);
