# veganlife.gr/35 — «Become one of the 35»

Η σελίδα της καμπάνιας μηνιαίων υποστηρικτών της Vegan Life, φιλοξενημένη στο **https://35.veganlife.gr** (Hostinger hosting, Git deploy) και εμφανιζόμενη μέσα στο **https://veganlife.gr/35** (Hostinger Website Builder) με ένα iframe.

| Αρχείο | Τι είναι |
|---|---|
| `index.html` | Η σελίδα. Κείμενα EN μέσα στο HTML, EL στο αντικείμενο `translations.el` στο τέλος. Ρυθμίσεις καμπάνιας (φάση, μετρητής) στην αρχή του `<script>`. |
| `builder-embed.html` | Το snippet που επικολλάται **μία φορά** στο στοιχείο Embed code της σελίδας /35 στον builder. Iframe προς το 35.veganlife.gr και relay μηνυμάτων (γλώσσα, scroll, ύψος). |
| `supporters-api/count.php` | Δημόσιο JSON με τον αριθμό μηνιαίων υποστηρικτών (από το Stripe, cache 5 λεπτών). |
| `supporters-api/stripe-webhook.php` | Το Stripe το καλεί σε κάθε νέα/ακυρωμένη συνδρομή: ανανεώνει τον μετρητή και στέλνει email στην ομάδα. |
| `supporters-api/setup.php` | Φόρμα ρύθμισης (κλειδιά Stripe, email, SMTP). Γράφει το `config.php` εκτός public_html. Θέλει τον κωδικό ρύθμισης. |
| `.htaccess` | HTTPS, άδεια framing μόνο από veganlife.gr, no-cache για το HTML. |

## Πώς δημοσιεύεται μια αλλαγή

Push στο branch `main`. Το Hostinger τραβάει το repository (Git deploy) και η σελίδα ενημερώνεται στο 35.veganlife.gr και, αυτόματα, μέσα στο veganlife.gr/35. Ο builder δεν αγγίζεται ξανά.

Αν το αυτόματο deploy δεν έχει ενεργοποιηθεί: Hostinger → Websites → 35.veganlife.gr → Advanced → GIT → Deploy.

## Ρυθμίσεις καμπάνιας (`index.html`, αρχή του `<script>`)

```js
const campaignPhases = [ { id: "internal", target: 5 }, { id: "prelaunch", target: 8 }, { id: "public2", target: 18 }, { id: "public3", target: 35 } ];
const campaignPhase = "internal";   // "internal" | "prelaunch" | "public2" | "public3" | "auto"
const currentSupporters = 0;        // εφεδρικός αριθμός αν το API δεν απαντήσει
const liveCountUrl = "supporters-api/count.php";
const peopleGridMode = "phase";     // "phase" ή "all"
```

## Ρύθμιση ειδοποιήσεων και μετρητή (μία φορά)

1. Stripe → Developers → API keys → **Create restricted key** → μόνο *Subscriptions: Read*.
2. Stripe → Developers → Webhooks → Add endpoint → `https://35.veganlife.gr/supporters-api/stripe-webhook.php` → events `checkout.session.completed`, `customer.subscription.created`, `customer.subscription.deleted` → αντιγραφή του **Signing secret**.
3. Άνοιγμα `https://35.veganlife.gr/supporters-api/setup.php`, κωδικός ρύθμισης, τα δύο κλειδιά, email ειδοποίησης, προαιρετικά SMTP του Titan (ngo@veganlife.gr και ο κωδικός του γραμματοκιβωτίου) για σίγουρη παράδοση. Αποθήκευση με «δοκιμαστικό email».
4. Έλεγχος: `https://35.veganlife.gr/supporters-api/count.php` επιστρέφει JSON με `supporters`.

Το `config.php` γράφεται στον φάκελο **πάνω** από το `public_html` και δεν ανεβαίνει ποτέ στο GitHub.

## Τοπική δοκιμή

```bash
php -S 127.0.0.1:8080
```

Άνοιγμα http://127.0.0.1:8080/ . Χωρίς `config.php` το `count.php` επιστρέφει σφάλμα και η σελίδα δείχνει τον εφεδρικό αριθμό.
