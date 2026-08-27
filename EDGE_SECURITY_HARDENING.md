# Edge Security Hardening

Ovaj projekat nema ugrađenu DDoS zaštitu, WAF ili globalni rate limiter. `.htaccess` i PHP rate limits štite aplikacioni sloj, ali ne mogu zameniti edge zaštitu.

## Preporučena Cloudflare konfiguracija

- DNS proxy uključiti samo za javne web hostove; origin IP držati van javnih DNS zapisa gde je moguće.
- SSL/TLS mode `Full (strict)` i validan origin certificate.
- `Always Use HTTPS`, HSTS tek posle provere da su svi relevantni subdomain-i HTTPS.
- WAF managed rules uključene.
- Rate limit pravila za `/api/login.php`, `/api/admin.php?action=login`, `/api/admin.php?action=passkey_*`, `/api/verification_code.php` i `POST /api/order.php`.
- Challenge/block za anomalne IP adrese, bez cache-iranja privatnih HTML/API odgovora.
- Bot protection uključiti uz proveru da ne blokira WebAuthn browser ceremony.
- Origin firewall ograničiti na Cloudflare IP opsege ako hosting to podržava; SSH/FTP ne izlagati kroz web origin.

## Ako Cloudflare nije dostupan

- Zatražiti od SuperHosting-a WAF/DDoS opciju i network-level rate limiting.
- Ograničiti PHP-FPM worker-e, request body/timeouts i concurrent connections prema hostingu.
- Pratiti access/error logove i imati kontakt za hitno privremeno blokiranje IP/opsega.
- Ne tvrditi da je sajt DDoS-protected samo zato što ima PHP rate limit ili `.htaccess` headers.

## Verification

Posle uključivanja edge zaštite testirati HTTPS, `Origin` zaglavlje, WebAuthn na `reseller.psigre.rs`, no-store API odgovore i da rate-limit ne cache-ira ili ponavlja POST zahteve.
