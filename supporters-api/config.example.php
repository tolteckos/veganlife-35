<?php
/* supporters-api — ρυθμίσεις.
   Αντιγράψτε το ως config.php ΕΞΩ από το public_html (π.χ. /home/uXXXX/domains/<domain>/config.php)
   ή, αν δεν γίνεται, μέσα στον φάκελο supporters-api/ (ο .htaccess δεν το σερβίρει). */
return [
  // Stripe: ΠΕΡΙΟΡΙΣΜΕΝΟ κλειδί (Developers → API keys → Create restricted key)
  // με δικαίωμα μόνο "Subscriptions: Read" (και "Customers: Read" αν θέλετε όνομα στο email).
  'stripe_restricted_key' => 'rk_live_xxx',

  // Stripe: Signing secret του webhook endpoint (Developers → Webhooks → το endpoint → Signing secret).
  'stripe_webhook_secret' => 'whsec_xxx',

  // Μετράμε μόνο συνδρομές που ξεκίνησαν από αυτή την ημερομηνία και μετά (έναρξη καμπάνιας).
  'campaign_start' => '2026-11-15 00:00:00',

  // Ποιος ειδοποιείται με email για κάθε νέο υποστηρικτή (και κάθε ακύρωση).
  'notify_emails' => ['ngo@veganlife.gr'],

  // Αποστολέας των email. Πρέπει να είναι διεύθυνση του domain που φιλοξενεί το API
  // (SPF/DKIM) για να μην πηγαίνουν στα spam.
  'from_email' => 'supporters@veganlife.gr',
  'from_name' => 'Vegan Life · 35',

  // Υποστηρικτές που δεν περνούν από το Stripe (π.χ. τραπεζική κατάθεση). Προστίθενται στο σύνολο.
  'manual_extra' => 0,

  // Κάθε πόσα δευτερόλεπτα ξαναρωτάμε το Stripe στο count.php (το webhook ανανεώνει αμέσως).
  'cache_seconds' => 300,

  // Φάκελος δεδομένων (count.json, log). Προεπιλογή: data/ δίπλα στα αρχεία. Προτιμήστε εκτός public_html.
  'data_dir' => __DIR__ . '/data',

  // Για δοκιμές μόνο: εναλλακτική βάση του Stripe API. Αφήστε το όπως είναι.
  'stripe_api_base' => 'https://api.stripe.com',
];
