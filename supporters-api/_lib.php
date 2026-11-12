<?php
/* supporters-api — κοινές συναρτήσεις (μέτρημα από το Stripe, cache, email, log). */
declare(strict_types=1);

function sa_config(): array {
  static $config = null;
  if ($config !== null) return $config;
  $candidates = [
    dirname(__DIR__, 2) . '/config.php',   // ένα επίπεδο πάνω από το public_html
    dirname(__DIR__) . '/config.php',      // μέσα στο public_html, δίπλα στον φάκελο
    __DIR__ . '/config.php',               // μέσα στον φάκελο supporters-api
  ];
  foreach ($candidates as $file) {
    if (is_file($file)) { $config = require $file; break; }
  }
  if (!is_array($config)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'config.php missing']);
    exit;
  }
  $config += [
    'manual_extra' => 0, 'cache_seconds' => 300, 'notify_emails' => [],
    'from_email' => 'noreply@localhost', 'from_name' => 'Vegan Life · 35',
    'data_dir' => __DIR__ . '/data', 'stripe_api_base' => 'https://api.stripe.com',
    'campaign_start' => '2026-01-01 00:00:00', 'smtp' => [],
  ];
  return $config;
}

function sa_data_dir(): string {
  $dir = rtrim(sa_config()['data_dir'], '/');
  if (!is_dir($dir)) @mkdir($dir, 0750, true);
  if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\n");
  return $dir;
}

function sa_log(string $line): void {
  @file_put_contents(sa_data_dir() . '/log.txt', date('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

function sa_read_cache(): ?array {
  $file = sa_data_dir() . '/count.json';
  if (!is_file($file)) return null;
  $data = json_decode((string) file_get_contents($file), true);
  return is_array($data) ? $data : null;
}

function sa_write_cache(array $data): void {
  $file = sa_data_dir() . '/count.json';
  @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/* Μία κλήση GET στο Stripe API με το περιορισμένο κλειδί. */
function sa_stripe_get(string $path, array $query = []): ?array {
  $config = sa_config();
  $url = rtrim($config['stripe_api_base'], '/') . $path . ($query ? '?' . http_build_query($query) : '');
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['stripe_restricted_key'], 'Stripe-Version: 2024-06-20'],
  ]);
  $body = curl_exec($ch);
  $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  if ($body === false || $status !== 200) { sa_log("stripe GET $path failed: HTTP $status"); return null; }
  $data = json_decode($body, true);
  return is_array($data) ? $data : null;
}

/* Μετράει τις ενεργές μηνιαίες συνδρομές που ξεκίνησαν μετά την έναρξη της καμπάνιας.
   Επιστρέφει null αν το Stripe δεν απάντησε. */
function sa_count_from_stripe(): ?array {
  $config = sa_config();
  $since = strtotime($config['campaign_start']) ?: 0;
  $count = 0; $latest = null; $startingAfter = null;
  foreach (['active', 'trialing', 'past_due'] as $status) {
    $startingAfter = null;
    for ($page = 0; $page < 20; $page++) {
      $query = ['status' => $status, 'limit' => 100, 'created[gte]' => $since];
      if ($startingAfter) $query['starting_after'] = $startingAfter;
      $data = sa_stripe_get('/v1/subscriptions', $query);
      if ($data === null) return null;
      foreach ($data['data'] ?? [] as $subscription) {
        $count++;
        $created = (int) ($subscription['created'] ?? 0);
        if ($latest === null || $created > $latest) $latest = $created;
        $startingAfter = $subscription['id'] ?? null;
      }
      if (empty($data['has_more'])) break;
    }
  }
  return ['stripe' => $count, 'lastJoinedAt' => $latest ? gmdate('c', $latest) : null];
}

/* Επιστρέφει τον τρέχοντα αριθμό (cache ή φρέσκο από το Stripe). */
function sa_current(bool $force = false): array {
  $config = sa_config();
  $cache = sa_read_cache();
  $fresh = $force || !$cache || (time() - (int) ($cache['checkedAt'] ?? 0)) > (int) $config['cache_seconds'];
  if ($fresh) {
    $result = sa_count_from_stripe();
    if ($result !== null) {
      $cache = [
        'stripe' => $result['stripe'],
        'lastJoinedAt' => $result['lastJoinedAt'] ?? ($cache['lastJoinedAt'] ?? null),
        'checkedAt' => time(),
      ];
      sa_write_cache($cache);
    } elseif ($cache) {
      $cache['checkedAt'] = time() - (int) $config['cache_seconds'] + 60; // ξαναδοκιμή σε 1 λεπτό
    } else {
      $cache = ['stripe' => 0, 'lastJoinedAt' => null, 'checkedAt' => 0, 'stale' => true];
    }
  }
  return [
    'supporters' => (int) $cache['stripe'] + (int) $config['manual_extra'],
    'lastJoinedAt' => $cache['lastJoinedAt'] ?? null,
    'updatedAt' => gmdate('c', (int) ($cache['checkedAt'] ?? time())),
  ];
}

/* Email στην ομάδα: μέσω SMTP (Titan/Hostinger, TLS 465) αν έχει ρυθμιστεί, αλλιώς PHP mail(). */
function sa_notify(string $subject, string $body): void {
  $config = sa_config();
  $from = $config['from_email'];
  $fromHeader = mb_encode_mimeheader($config['from_name'], 'UTF-8') . " <$from>";
  $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8');
  $smtp = $config['smtp'] ?? [];
  foreach ($config['notify_emails'] as $to) {
    $ok = false;
    if (!empty($smtp['host']) && !empty($smtp['user']) && !empty($smtp['pass'])) {
      $ok = sa_smtp_send($smtp, $from, $fromHeader, $to, $encodedSubject, $body);
      sa_log("smtp to $to: " . ($ok ? 'sent' : 'FAILED, falling back to mail()'));
    }
    if (!$ok) {
      $headers = "From: $fromHeader\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
      $ok = @mail($to, $encodedSubject, $body, $headers);
      sa_log("mail to $to: " . ($ok ? 'sent' : 'FAILED'));
    }
  }
}

/* Ελάχιστος SMTP client: implicit TLS, AUTH LOGIN, ένα μήνυμα. Επιστρέφει true σε επιτυχία. */
function sa_smtp_send(array $smtp, string $from, string $fromHeader, string $to, string $subject, string $body): bool {
  $host = (string) $smtp['host']; $port = (int) ($smtp['port'] ?? 465);
  $sock = @stream_socket_client("ssl://$host:$port", $errno, $errstr, 15);
  if (!$sock) { sa_log("smtp connect failed: $errstr"); return false; }
  stream_set_timeout($sock, 15);
  $read = function () use ($sock): string {
    $out = '';
    while (($line = fgets($sock, 1024)) !== false) { $out .= $line; if (strlen($line) < 4 || $line[3] !== '-') break; }
    return $out;
  };
  $send = function (string $cmd, array $okCodes) use ($sock, $read): bool {
    fwrite($sock, $cmd . "\r\n");
    $reply = $read();
    return in_array((int) substr($reply, 0, 3), $okCodes, true);
  };
  try {
    if ((int) substr($read(), 0, 3) !== 220) return false;
    if (!$send('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), [250])) return false;
    if (!$send('AUTH LOGIN', [334])) return false;
    if (!$send(base64_encode((string) $smtp['user']), [334])) return false;
    if (!$send(base64_encode((string) $smtp['pass']), [235])) return false;
    if (!$send("MAIL FROM:<$from>", [250])) return false;
    if (!$send("RCPT TO:<$to>", [250, 251])) return false;
    if (!$send('DATA', [354])) return false;
    $message = "From: $fromHeader\r\nTo: <$to>\r\nSubject: $subject\r\nDate: " . date('r') . "\r\n"
      . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
      . preg_replace('/^\./m', '..', str_replace("\n", "\r\n", $body));
    if (!$send($message . "\r\n.", [250])) return false;
    $send('QUIT', [221]);
    return true;
  } finally { fclose($sock); }
}
