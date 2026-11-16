<?php
/* setup.php — ρύθμιση των κλειδιών Stripe και των email ειδοποίησης, μία φορά, από τον browser.
   Γράφει το config.php ΕΞΩ από το public_html (ένα επίπεδο πάνω), ώστε να μη σερβίρεται ποτέ
   και να μην ανεβαίνει στο GitHub. Χρειάζεται τον κωδικό ρύθμισης (setup token): μόνο το SHA-256
   hash του βρίσκεται στο αρχείο setup-token.php. */
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

$tokenHash = is_file(__DIR__ . '/setup-token.php') ? (string) (require __DIR__ . '/setup-token.php') : '';
$target = dirname(__DIR__, 2) . '/config.php';
if (!is_writable(dirname($target))) $target = __DIR__ . '/config.php';
$existing = is_file($target) ? (require $target) : [];
if (!is_array($existing)) $existing = [];

$message = ''; $ok = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $token = (string) ($_POST['token'] ?? '');
  if ($tokenHash === '' || !hash_equals($tokenHash, hash('sha256', $token))) {
    usleep(500000);
    $message = 'Λάθος κωδικός ρύθμισης.';
  } else {
    $emails = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['notify_emails'] ?? ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    $config = [
      'stripe_restricted_key' => trim((string) ($_POST['stripe_restricted_key'] ?? '')) ?: ($existing['stripe_restricted_key'] ?? ''),
      'stripe_webhook_secret' => trim((string) ($_POST['stripe_webhook_secret'] ?? '')) ?: ($existing['stripe_webhook_secret'] ?? ''),
      'campaign_start' => trim((string) ($_POST['campaign_start'] ?? '')) ?: ($existing['campaign_start'] ?? date('Y-m-d 00:00:00')),
      'notify_emails' => $emails ?: ($existing['notify_emails'] ?? []),
      'from_email' => trim((string) ($_POST['from_email'] ?? '')) ?: ($existing['from_email'] ?? ('no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'))),
      'from_name' => 'Vegan Life · 35',
      'manual_extra' => (int) ($_POST['manual_extra'] ?? ($existing['manual_extra'] ?? 0)),
      'cache_seconds' => 300,
      'data_dir' => dirname($target) . '/supporters-data',
      'stripe_api_base' => 'https://api.stripe.com',
      'smtp' => [
        'host' => trim((string) ($_POST['smtp_host'] ?? '')) ?: ($existing['smtp']['host'] ?? ''),
        'port' => 465,
        'user' => trim((string) ($_POST['smtp_user'] ?? '')) ?: ($existing['smtp']['user'] ?? ''),
        'pass' => (string) ($_POST['smtp_pass'] ?? '') !== '' ? (string) $_POST['smtp_pass'] : ($existing['smtp']['pass'] ?? ''),
      ],
      'mailjet' => [
        'api_key' => trim((string) ($_POST['mailjet_key'] ?? '')) ?: ($existing['mailjet']['api_key'] ?? ''),
        'api_secret' => (string) ($_POST['mailjet_secret'] ?? '') !== '' ? trim((string) $_POST['mailjet_secret']) : ($existing['mailjet']['api_secret'] ?? ''),
        'list_supporters' => (int) ($_POST['mailjet_list_supporters'] ?? ($existing['mailjet']['list_supporters'] ?? 0)),
        'list_updates' => (int) ($_POST['mailjet_list_updates'] ?? ($existing['mailjet']['list_updates'] ?? 0)),
        'api_base' => $existing['mailjet']['api_base'] ?? 'https://api.mailjet.com',
      ],
    ];
    if ($config['stripe_restricted_key'] === '' || !str_starts_with($config['stripe_restricted_key'], 'rk_')) {
      $message = 'Το κλειδί Stripe πρέπει να είναι περιορισμένο κλειδί (ξεκινά με rk_).';
    } else {
      $php = "<?php\n// Γράφτηκε από setup.php στις " . date('c') . "\nreturn " . var_export($config, true) . ";\n";
      if (@file_put_contents($target, $php, LOCK_EX) === false) {
        $message = 'Δεν ήταν δυνατή η εγγραφή στο ' . htmlspecialchars($target) . '.';
      } else {
        @chmod($target, 0600);
        $ok = true;
        $message = 'Αποθηκεύτηκε στο ' . htmlspecialchars($target) . '. Δοκιμή: ανοίξτε το count.php.';
        if (!empty($_POST['test_mailjet']) && $config['mailjet']['api_key'] !== '') {
          require_once __DIR__ . '/_lib.php';
          $lists = sa_mailjet_lists();
          if (hash_equals($tokenHash, hash('sha256', (string) $config['mailjet']['api_secret']))) $message .= ' ΠΡΟΣΟΧΗ: το Mailjet secret που αποθηκεύτηκε είναι ίδιο με τον κωδικό ρύθμισης, ο browser το συμπλήρωσε αυτόματα. Ξαναγράψτε το secret.';
          if (hash_equals($tokenHash, hash('sha256', (string) $config['mailjet']['api_key']))) $message .= ' ΠΡΟΣΟΧΗ: το Mailjet API key είναι ίδιο με τον κωδικό ρύθμισης.';
          $message .= ($lists['supporters'] > 0) ? " Mailjet OK: λίστα υποστηρικτών #{$lists['supporters']}, λίστα ενημερώσεων #{$lists['updates']}." : ' Mailjet: ΑΠΟΤΥΧΙΑ σύνδεσης. Απάντηση: ' . htmlspecialchars(sa_mailjet_last_error() ?: 'καμία') . ' · αποθηκευμένο key ' . substr($config['mailjet']['api_key'], 0, 6) . '…' . substr($config['mailjet']['api_key'], -4) . ' (' . strlen($config['mailjet']['api_key']) . ' χαρ.), secret ' . substr($config['mailjet']['api_secret'], 0, 4) . '…' . substr($config['mailjet']['api_secret'], -2) . ' (' . strlen($config['mailjet']['api_secret']) . ' χαρ.).';
        }
        if (!empty($_POST['send_test']) && $config['notify_emails']) {
          require_once __DIR__ . '/_lib.php';
          sa_notify('Δοκιμή ειδοποιήσεων · Vegan Life 35', "Αυτό είναι δοκιμαστικό email από το supporters-api.\nΑν το βλέπετε, οι ειδοποιήσεις δουλεύουν.\n");
          $message .= ' Στάλθηκε δοκιμαστικό email.';
        }
      }
    }
  }
}
$v = fn(string $k, $d = '') => htmlspecialchars((string) ($existing[$k] ?? $d));
?><!DOCTYPE html>
<html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ρύθμιση supporters-api</title>
<style>body{font:16px/1.5 system-ui,sans-serif;max-width:640px;margin:40px auto;padding:0 20px;color:#1E2421}label{display:block;margin:18px 0 6px;font-weight:600}input{width:100%;padding:10px;border:1px solid #bbb;border-radius:8px;font:inherit}button{margin-top:24px;padding:12px 20px;background:#0B4F43;color:#fff;border:0;border-radius:8px;font:inherit;font-weight:600}small{color:#53635B}.msg{padding:12px 16px;border-radius:8px;background:#EAF3EE;border:1px solid #B9D83B}.err{background:#fde8ea;border-color:#E75B69}fieldset{border:1px solid #ddd;border-radius:10px;padding:6px 16px 16px;margin-top:24px}</style></head>
<body>
<h1>Ρύθμιση ειδοποιήσεων και μετρητή</h1>
<p>Τα στοιχεία αποθηκεύονται στον server, εκτός του δημόσιου φακέλου. <?= $existing ? '<strong>Υπάρχει ήδη ρύθμιση·</strong> τα κενά πεδία κρατούν την τρέχουσα τιμή.' : 'Πρώτη ρύθμιση.' ?></p>
<?php if ($message): ?><p class="msg<?= $ok ? '' : ' err' ?>"><?= $message ?></p><?php endif; ?>
<form method="post" autocomplete="off">
  <label>Κωδικός ρύθμισης (setup token)</label><input type="password" autocomplete="off" name="token" required>
  <label>Stripe restricted key (rk_live_…)</label><input type="text" autocomplete="off" spellcheck="false" name="stripe_restricted_key" placeholder="<?= $existing ? '•••••• (υπάρχει)' : 'rk_live_...' ?>"><small>Developers → API keys → Create restricted key → μόνο Subscriptions: Read.</small>
  <label>Stripe webhook signing secret (whsec_…)</label><input type="text" autocomplete="off" spellcheck="false" name="stripe_webhook_secret" placeholder="<?= $existing ? '•••••• (υπάρχει)' : 'whsec_...' ?>"><small>Developers → Webhooks → endpoint <code><?= htmlspecialchars('https://' . ($_SERVER['HTTP_HOST'] ?? '') . '/supporters-api/stripe-webhook.php') ?></code> → Signing secret.</small>
  <label>Έναρξη καμπάνιας (μετράμε συνδρομές από τότε)</label><input type="text" name="campaign_start" value="<?= $v('campaign_start', date('Y-m-d') . ' 00:00:00') ?>">
  <label>Email ειδοποίησης (χωρισμένα με κόμμα)</label><input type="text" name="notify_emails" value="<?= htmlspecialchars(implode(', ', $existing['notify_emails'] ?? ['ngo@veganlife.gr'])) ?>">
  <label>Υποστηρικτές εκτός Stripe (π.χ. κατάθεση)</label><input type="number" name="manual_extra" min="0" value="<?= $v('manual_extra', '0') ?>">
  <fieldset><legend>Αποστολή email (προαιρετικό, για να μην πάνε στα spam)</legend>
    <label>Αποστολέας</label><input type="text" name="from_email" value="<?= $v('from_email', 'ngo@veganlife.gr') ?>">
    <label>SMTP host (Titan: smtp.titan.email)</label><input type="text" name="smtp_host" value="<?= htmlspecialchars((string) ($existing['smtp']['host'] ?? 'smtp.titan.email')) ?>">
    <label>SMTP χρήστης (η διεύθυνση email)</label><input type="text" name="smtp_user" value="<?= htmlspecialchars((string) ($existing['smtp']['user'] ?? 'ngo@veganlife.gr')) ?>">
    <label>SMTP κωδικός (του γραμματοκιβωτίου)</label><input type="password" autocomplete="new-password" name="smtp_pass" placeholder="<?= !empty($existing['smtp']['pass']) ? '•••••• (υπάρχει)' : '' ?>"><small>Αν μείνει κενό και δεν υπάρχει, τα email φεύγουν με το mail() του Hostinger.</small>
  </fieldset>
  <fieldset><legend>Mailjet (προαιρετικό): κάθε νέος υποστηρικτής μπαίνει αυτόματα σε λίστα επαφών</legend>
    <label>Mailjet API Key</label><input type="text" autocomplete="off" spellcheck="false" name="mailjet_key" value="<?= htmlspecialchars((string) ($existing['mailjet']['api_key'] ?? '')) ?>"><small>Mailjet → Account settings → API Key Management (Primary and Sub-account) → API Key.</small>
    <label>Mailjet Secret Key</label><input type="text" autocomplete="off" spellcheck="false" name="mailjet_secret" placeholder="<?= !empty($existing['mailjet']['api_secret']) ? '•••••• (υπάρχει)' : '' ?>"><small>Από την ίδια σελίδα. Αν δεν φαίνεται, «Generate secret key».</small>
    <label>ID λίστας υποστηρικτών (προαιρετικό)</label><input type="number" name="mailjet_list_supporters" min="0" value="<?= (int) ($existing['mailjet']['list_supporters'] ?? 0) ?: '' ?>"><small>Κενό = δημιουργείται αυτόματα η λίστα «Vegan Life 35 · Μηνιαίοι υποστηρικτές».</small>
    <label>ID λίστας ενημερώσεων (προαιρετικό)</label><input type="number" name="mailjet_list_updates" min="0" value="<?= (int) ($existing['mailjet']['list_updates'] ?? 0) ?: '' ?>"><small>Κενό = δημιουργείται αυτόματα η λίστα «Vegan Life 35 · Συναίνεση για ενημερώσεις». Εδώ μπαίνουν μόνο όσοι απάντησαν «Ναι» στο checkout.</small>
  </fieldset>
  <label><input type="checkbox" name="send_test" value="1" style="width:auto"> Στείλε δοκιμαστικό email μετά την αποθήκευση</label>
  <label><input type="checkbox" name="test_mailjet" value="1" style="width:auto"> Δοκίμασε τη σύνδεση Mailjet (δημιουργεί τις λίστες αν λείπουν)</label>
  <button type="submit">Αποθήκευση</button>
</form>
</body></html>
