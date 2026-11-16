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
    'campaign_start' => '2026-01-01 00:00:00', 'smtp' => [], 'mailjet' => [],
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
        'stripeOk' => true,
      ];
      sa_write_cache($cache);
    } elseif ($cache) {
      $cache['checkedAt'] = time() - (int) $config['cache_seconds'] + 60; // ξαναδοκιμή σε 1 λεπτό
      $cache['stripeOk'] = false;
    } else {
      $cache = ['stripe' => 0, 'lastJoinedAt' => null, 'checkedAt' => 0, 'stripeOk' => false];
    }
  }
  return [
    'supporters' => (int) $cache['stripe'] + (int) $config['manual_extra'],
    'lastJoinedAt' => $cache['lastJoinedAt'] ?? null,
    'updatedAt' => gmdate('c', (int) ($cache['checkedAt'] ?? time())),
    'source' => !empty($cache['stripeOk']) ? 'stripe' : 'fallback',
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

/* ============================================================================
   Mailjet: κάθε νέος μηνιαίος υποστηρικτής μπαίνει αυτόματα σε λίστα επαφών.
   - Λίστα «Υποστηρικτές»: όλοι (επικοινωνία για τη στήριξή τους).
   - Λίστα «Ενημερώσεις»: μόνο όσοι απάντησαν «Ναι» στο checkout.
   Οι λίστες δημιουργούνται αυτόματα στην πρώτη εγγραφή αν δεν έχουν οριστεί IDs.
   ========================================================================== */
function sa_mailjet_last_error(): string { return (string) ($GLOBALS['sa_mailjet_last_error'] ?? ''); }

function sa_mailjet_enabled(): bool {
  $m = sa_config()['mailjet'] ?? [];
  return !empty($m['api_key']) && !empty($m['api_secret']);
}

function sa_mailjet_request(string $method, string $path, ?array $body = null): ?array {
  $m = sa_config()['mailjet'];
  $ch = curl_init(rtrim($m['api_base'] ?? 'https://api.mailjet.com', '/') . $path);
  $headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'User-Agent: veganlife-35-supporters-api/1.0',
    'Authorization: Basic ' . base64_encode(trim((string) $m['api_key']) . ':' . trim((string) $m['api_secret'])),
  ];
  $responseHeaders = '';
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) { $responseHeaders .= $line; return strlen($line); },
  ]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
  $raw = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $curlError = curl_error($ch); curl_close($ch);
  if ($raw === false || $status >= 400) {
    $server = preg_match('/^(server|www-authenticate|cf-ray|x-mj-request-guid):\s*(.+)$/mi', $responseHeaders, $mm) ? trim($mm[0]) : '';
    $GLOBALS['sa_mailjet_last_error'] = "HTTP $status " . ($curlError !== '' ? "curl: $curlError " : '') . ($server !== '' ? "[$server] " : '') . 'body: ' . substr(strip_tags((string) $raw), 0, 300);
    sa_log("mailjet $method $path failed: " . $GLOBALS['sa_mailjet_last_error']);
    return null;
  }
  $data = json_decode((string) $raw, true);
  return is_array($data) ? $data : [];
}

/* Επιστρέφει τα IDs των δύο λιστών, δημιουργώντας τες αν λείπουν (μία φορά, με cache). */
function sa_mailjet_lists(): array {
  $m = sa_config()['mailjet'];
  $file = sa_data_dir() . '/mailjet-lists.json';
  $cached = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
  $lists = ['supporters' => (int) ($m['list_supporters'] ?? 0) ?: (int) ($cached['supporters'] ?? 0),
            'updates' => (int) ($m['list_updates'] ?? 0) ?: (int) ($cached['updates'] ?? 0)];
  $names = ['supporters' => 'Vegan Life 35 · Μηνιαίοι υποστηρικτές', 'updates' => 'Vegan Life 35 · Συναίνεση για ενημερώσεις'];
  foreach ($lists as $key => $id) {
    if ($id > 0) continue;
    $existing = sa_mailjet_request('GET', '/v3/REST/contactslist?Name=' . rawurlencode($names[$key]) . '&Limit=1');
    $id = (int) ($existing['Data'][0]['ID'] ?? 0);
    if ($id <= 0) { $created = sa_mailjet_request('POST', '/v3/REST/contactslist', ['Name' => $names[$key]]); $id = (int) ($created['Data'][0]['ID'] ?? 0); }
    $lists[$key] = $id;
  }
  @file_put_contents($file, json_encode($lists), LOCK_EX);
  return $lists;
}

/* Οι ιδιότητες επαφής που χρησιμοποιούμε (δημιουργούνται μία φορά). */
function sa_mailjet_ensure_properties(): void {
  $file = sa_data_dir() . '/mailjet-props.ok';
  if (is_file($file)) return;
  $props = ['firstname' => 'str', 'phone' => 'str', 'monthly_amount' => 'str', 'supporter_since' => 'str', 'consent_updates' => 'str', 'support_status' => 'str'];
  $existing = sa_mailjet_request('GET', '/v3/REST/contactmetadata?Limit=200');
  $have = array_map(fn($p) => strtolower((string) $p['Name']), $existing['Data'] ?? []);
  foreach ($props as $name => $type) {
    if (in_array($name, $have, true)) continue;
    sa_mailjet_request('POST', '/v3/REST/contactmetadata', ['Name' => $name, 'Datatype' => $type, 'NameSpace' => 'static']);
  }
  @file_put_contents($file, date('c'));
}

/* Εγγραφή/ενημέρωση ενός υποστηρικτή στις λίστες. $contact: email, name, phone, amount, consent (bool), status. */
function sa_mailjet_sync(array $contact): bool {
  if (!sa_mailjet_enabled() || empty($contact['email'])) return false;
  sa_mailjet_ensure_properties();
  $lists = sa_mailjet_lists();
  if ($lists['supporters'] <= 0) { sa_log('mailjet: no supporters list'); return false; }
  $properties = array_filter([
    'firstname' => trim((string) ($contact['name'] ?? '')) ?: null,
    'phone' => trim((string) ($contact['phone'] ?? '')) ?: null,
    'monthly_amount' => $contact['amount'] ?? null,
    'supporter_since' => ($contact['status'] ?? 'active') === 'active' ? ($contact['since'] ?? gmdate('Y-m-d')) : null,
    'consent_updates' => array_key_exists('consent', $contact) && $contact['consent'] === null ? null : (!empty($contact['consent']) ? 'yes' : 'no'),
    'support_status' => $contact['status'] ?? 'active',
  ], fn($v) => $v !== null && $v !== '');
  $payload = ['Email' => $contact['email'], 'Action' => 'addnoforce', 'Properties' => $properties];
  if (!empty($contact['name'])) $payload['Name'] = (string) $contact['name'];
  $ok = sa_mailjet_request('POST', '/v3/REST/contactslist/' . $lists['supporters'] . '/managecontact', $payload) !== null;
  if (!empty($contact['consent'])) {
    if ($lists['updates'] > 0) $ok = sa_mailjet_request('POST', '/v3/REST/contactslist/' . $lists['updates'] . '/managecontact', $payload) !== null && $ok;
    // Και στην κανονική λίστα Newsletter της Vegan Life (0 = απενεργοποίηση).
    $newsletter = (int) (sa_config()['mailjet']['list_newsletter'] ?? 10427006);
    if ($newsletter > 0) $ok = sa_mailjet_request('POST', "/v3/REST/contactslist/$newsletter/managecontact", $payload) !== null && $ok;
  }
  sa_log('mailjet sync ' . $contact['email'] . ' (' . ($contact['status'] ?? 'active') . '): ' . ($ok ? 'ok' : 'FAILED') . ' consent=' . (array_key_exists('consent', $contact) && $contact['consent'] === null ? 'unchanged' : (!empty($contact['consent']) ? 'yes' : 'no')));
  return $ok;
}

/* Διαβάζει συναίνεση και κινητό από τα custom fields του Stripe Checkout.
   Συναίνεση: το πρώτο dropdown (τιμή που ξεκινά με "yes"/"nai"/"ναι") ή το πεδίο consent_collection.
   Κινητό: το πρώτο πεδίο κειμένου/αριθμού, αλλιώς customer_details.phone. */
function sa_checkout_extras(array $session): array {
  $consent = null; $phone = trim((string) ($session['customer_details']['phone'] ?? ''));
  foreach ($session['custom_fields'] ?? [] as $field) {
    $type = (string) ($field['type'] ?? '');
    if ($type === 'dropdown' && $consent === null) {
      $value = strtolower((string) ($field['dropdown']['value'] ?? ''));
      $consent = $value !== '' && (str_starts_with($value, 'yes') || str_starts_with($value, 'nai') || str_starts_with($value, 'ναι') || str_starts_with($value, 'y'));
    } elseif (in_array($type, ['text', 'numeric'], true) && $phone === '') {
      $phone = trim((string) ($field[$type]['value'] ?? ''));
    }
  }
  if ($consent === null && isset($session['consent']['promotions'])) $consent = $session['consent']['promotions'] === 'opt_in';
  return ['consent' => (bool) $consent, 'phone' => $phone];
}

/* Διαγνωστικό αυθεντικοποίησης Mailjet: δοκιμάζει τρεις τρόπους αποστολής των κλειδιών
   και έναν ουδέτερο έλεγχο ότι το Authorization header φεύγει από τον server. */
function sa_mailjet_diagnose(): string {
  $m = sa_config()['mailjet']; $key = trim((string) $m['api_key']); $secret = trim((string) $m['api_secret']);
  $base = rtrim($m['api_base'] ?? 'https://api.mailjet.com', '/');
  $run = function (string $url, array $opts, string $label) {
    $ch = curl_init($url); $verbose = fopen('php://temp', 'w+');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_VERBOSE => true, CURLOPT_STDERR => $verbose] + $opts);
    $raw = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
    rewind($verbose); $log = stream_get_contents($verbose); fclose($verbose);
    $sent = [];
    foreach (explode("\n", $log) as $line) {
      if (preg_match('/^> (Authorization|Host|Proxy-Authorization|User-Agent):\s*(.*)$/i', trim($line), $mm)) $sent[] = $mm[1] . ': ' . (stripos($mm[1], 'authorization') !== false ? substr($mm[2], 0, 10) . '…(' . strlen(trim($mm[2])) . ')' : trim($mm[2]));
      if (preg_match('/^\* (Connected to|Trying|Uses proxy|Establish HTTP proxy|CONNECT)/i', trim($line))) $sent[] = trim($line, "* ");
    }
    return "$label → HTTP $status" . ($err ? " curl:$err" : '') . ' | ' . implode(' | ', array_slice($sent, 0, 5)) . ' | body: ' . substr(strip_tags((string) $raw), 0, 80);
  };
  $lines = [];
  $lines[] = $run("$base/v3/REST/apikey", [CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_USERPWD => "$key:$secret"], 'A userpwd');
  $lines[] = $run("$base/v3/REST/apikey", [CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode("$key:$secret"), 'User-Agent: veganlife-35']], 'B header');
  $lines[] = $run(preg_replace('#^https://#', "https://$key:$secret@", $base) . '/v3/REST/apikey', [], 'C url');
  $lines[] = $run('https://httpbin.org/basic-auth/user/pass', [CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode('user:pass')]], 'D httpbin (expect 200)');
  $lines[] = 'key len ' . strlen($key) . ' hex=' . (ctype_xdigit($key) ? 'yes' : 'NO') . ', secret len ' . strlen($secret) . ' hex=' . (ctype_xdigit($secret) ? 'yes' : 'NO') . ', php ' . PHP_VERSION . ', curl ' . (curl_version()['version'] ?? '?') . ', proxy env: ' . (getenv('https_proxy') ?: getenv('HTTPS_PROXY') ?: 'none');
  return implode("\n", $lines);
}
