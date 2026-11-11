<?php // old address: forward to /student/ keeping the link token
$q = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ../student/book.php' . ($q !== '' ? '?' . $q : ''), true, 302);
exit;
