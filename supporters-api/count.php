<?php
/* GET count.php → {"supporters": 7, "lastJoinedAt": "2026-11-20T18:02:11+00:00", "updatedAt": "..."}
   Δημόσιο, μόνο ανάγνωση. Το embed της σελίδας /35 το διαβάζει κατά το φόρτωμα. */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

header('Access-Control-Allow-Origin: *');   // το embed τρέχει σε sandboxed iframe (origin "null")
header('Access-Control-Allow-Methods: GET');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); echo '{"ok":false}'; exit; }

$current = sa_current(isset($_GET['refresh']) && $_GET['refresh'] === '1');
echo json_encode(['ok' => true] + $current, JSON_UNESCAPED_UNICODE);
