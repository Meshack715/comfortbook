<?php
// php bin/migrate.php — upgrade an existing ComfortBook database (safe to run many times).
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/migrations/001_immersive.php';
require_once __DIR__ . '/../includes/migrations/002_payments_stepup.php';
require_once __DIR__ . '/../includes/migrations/003_ai_workspace.php';
require_once __DIR__ . '/../includes/migrations/004_payments_hardening.php';
foreach (cb_migrate_001($pdo) as $l) echo " - $l\n";
foreach (cb_migrate_002($pdo) as $l) echo " - $l\n";
foreach (cb_migrate_003($pdo) as $l) echo " - $l\n";
foreach (cb_migrate_004($pdo) as $l) echo " - $l\n";
echo "Done.\n";
