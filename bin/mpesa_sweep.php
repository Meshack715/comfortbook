<?php
// Optional (recommended): run every minute from cPanel Cron Jobs so a prompt nobody is watching is still settled:
//   * * * * *  php /home/USER/public_html/bin/mpesa_sweep.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/mpesa.php';
mpesa_sweep($pdo);
echo "ok\n";
