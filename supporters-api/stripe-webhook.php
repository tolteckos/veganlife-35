<?php
/* POST stripe-webhook.php — το Stripe το καλεί σε κάθε νέα/ακυρωμένη συνδρομή.
   Στο Stripe: Developers → Webhooks → Add endpoint → URL αυτού του αρχείου, events:
   checkout.session.completed, customer.subscription.created, customer.subscription.deleted.
   Ελέγχει την υπογραφή, ανανεώνει τον μετρητή και στέλνει email στην ομάδα. */
declare(strict_types=1);
require __DIR__ . '/_lib.php';

header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }

$payload = (string) file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$secret = (string) (sa_config()['stripe_webhook_secret'] ?? '');

/* Επαλήθευση υπογραφής Stripe (t=..., v1=...) με ανοχή 5 λεπτών. */
function sa_verify_signature(string $payload, string $header, string $secret): bool {
  if ($secret === '' || $header === '') return false;
  $timestamp = null; $signatures = [];
  foreach (explode(',', $header) as $part) {
    [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
    if ($key === 't') $timestamp = (int) $value;
    if ($key === 'v1') $signatures[] = $value;
  }
  if (!$timestamp || !$signatures || abs(time() - $timestamp) > 300) return false;
  $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
  foreach ($signatures as $candidate) if (hash_equals($expected, $candidate)) return true;
  return false;
}

if (!sa_verify_signature($payload, $signature, $secret)) {
  sa_log('webhook: invalid signature');
  http_response_code(400); echo '{"ok":false,"error":"invalid signature"}'; exit;
}

$event = json_decode($payload, true);
$type = (string) ($event['type'] ?? '');
$object = $event['data']['object'] ?? [];
$eventId = (string) ($event['id'] ?? '');

/* Κάθε event μία φορά (το Stripe ξαναστέλνει αν αργήσουμε). */
$seenFile = sa_data_dir() . '/events.txt';
$seen = is_file($seenFile) ? file($seenFile, FILE_IGNORE_NEW_LINES) : [];
if ($eventId !== '' && in_array($eventId, $seen, true)) { echo '{"ok":true,"duplicate":true}'; exit; }
@file_put_contents($seenFile, implode("\n", array_slice(array_merge($seen, [$eventId]), -500)) . "\n", LOCK_EX);

$handled = ['checkout.session.completed', 'customer.subscription.created', 'customer.subscription.deleted'];
if (!in_array($type, $handled, true)) { echo '{"ok":true,"ignored":true}'; exit; }

/* Το checkout.session.completed έχει όνομα/email/ποσό. Το subscription.created μόνο ποσό. */
if ($type === 'checkout.session.completed' && ($object['mode'] ?? '') !== 'subscription') { echo '{"ok":true,"ignored":true}'; exit; }

$amount = null; $currency = 'eur'; $name = ''; $email = '';
if ($type === 'checkout.session.completed') {
  $amount = isset($object['amount_total']) ? (int) $object['amount_total'] / 100 : null;
  $currency = strtolower((string) ($object['currency'] ?? 'eur'));
  $name = (string) ($object['customer_details']['name'] ?? '');
  $email = (string) ($object['customer_details']['email'] ?? '');
} else {
  $item = $object['items']['data'][0]['price'] ?? [];
  $amount = isset($item['unit_amount']) ? (int) $item['unit_amount'] / 100 : null;
  $currency = strtolower((string) ($item['currency'] ?? 'eur'));
}

/* Ανανέωση μετρητή αμέσως (όχι cache). Αν το Stripe δεν απαντήσει, θα ανανεωθεί στο επόμενο count.php. */
$current = sa_current(true);
$total = (int) $current['supporters'];
$amountText = $amount !== null ? number_format($amount, 2, ',', '.') . ' ' . strtoupper($currency) . '/μήνα' : 'ποσό άγνωστο';

if ($type === 'customer.subscription.deleted') {
  sa_log("webhook: subscription deleted → total $total");
  sa_notify("Ακύρωση μηνιαίας στήριξης · σύνολο τώρα $total",
    "Ένας μηνιαίος υποστηρικτής ακύρωσε τη συνδρομή του ($amountText).\n\nΣύνολο ενεργών υποστηρικτών τώρα: $total / 35\n\nΛεπτομέρειες στο Stripe Dashboard → Subscriptions.\n");
} else {
  // Το checkout.session.completed και το customer.subscription.created έρχονται και τα δύο για την ίδια εγγραφή·
  // στέλνουμε email μόνο στο checkout.session.completed (έχει όνομα/email). Το subscription.created μόνο ανανεώνει τον μετρητή.
  if ($type === 'checkout.session.completed') {
    sa_log("webhook: new supporter ($amountText) → total $total");
    $who = trim($name . ($email ? " <$email>" : ''));
    sa_notify("🎉 Νέος μηνιαίος υποστηρικτής #$total · $amountText",
      "Κάποιος μόλις έγινε ένας από τους 35!\n\n"
      . "Ποσό: $amountText\n"
      . ($who !== '' ? "Στοιχεία: $who\n" : '')
      . "\nΣύνολο μηνιαίων υποστηρικτών τώρα: $total / 35\n"
      . "Ο μετρητής στη σελίδα veganlife.gr/35 ενημερώθηκε αυτόματα.\n\n"
      . "Λεπτομέρειες στο Stripe Dashboard → Payments / Subscriptions.\n");
  } else {
    sa_log("webhook: subscription created ($amountText) → total $total");
  }
}

echo json_encode(['ok' => true, 'supporters' => $total]);
