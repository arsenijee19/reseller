# Security Audit

Datum pregleda: 27.08.2026.

## Scope

- PHP API, admin owner panel, reseller panel, MySQL upiti, session/authentication tokovi, email/n8n/Inventory integracije, cPanel deploy fajlovi.
- Postoji jedan admin nalog koji je owner. Nisu dodavane role ni RBAC pravila.

## Implemented Controls

- Admin i reseller koriste odvojene session cookie nazive (`PWRSADMINSESSID` i `PWRSRESELLERSESSID`), `HttpOnly`, `Secure` na HTTPS-u, `SameSite=Strict`, idle timeout 60 minuta i apsolutni timeout 8 sati.
- Mutirajući zahtevi imaju CSRF proveru, same-origin proveru, JSON content-type proveru i limit JSON tela od 64 KiB.
- Login, 2FA i passkey rute imaju persisted rate-limit evidenciju i audit događaje. TOTP kod se ne čuva u logu; isti TOTP vremenski korak ne može ponovo da se iskoristi za owner/reseller verifikaciju.
- Admin 2FA koristi TOTP, AES-256-GCM za secret, hash recovery kodova i jednokratno poništavanje pending sesije preko `Nazad`.
- Owner passkey koristi `web-auth/webauthn-lib` 5.3.5. Server generiše jednokratni challenge, proverava origin, RP ID hash, client data, user verification, signature, credential ID i sign counter. Privatni ključ nikada ne ulazi u bazu.
- Passkey registracija zahteva owner session, CSRF, trenutnu admin šifru i, kada je admin 2FA uključen, važeći TOTP kod. Više credentiala je podržano; lista i revocation su dostupni samo autentifikovanom owneru.
- SQL vrednosti idu kroz prepared statements. Dynamic SQL polja admin porudžbine su whitelistovana. Reseller order/notes/paid/missing-game upiti ograničeni su na `reseller_id` iz sesije.
- Frontend za DB/user vrednosti koristi DOM `textContent`/property API umesto HTML interpolacije.
- Outbound webhook/Inventory pozivi ne prate redirect, koriste HTTPS i blokiraju očigledne privatne/localhost adrese. Supplier token ostaje server-side.
- Root i API `.htaccess` dodaju `nosniff`, `DENY`, `no-referrer`, Permissions-Policy, HSTS i CSP; blokirani su SQL/docs/log/dot/private config fajlovi.

## Findings and Residual Risk

### High

- Produkciona baza, migracije, cPanel PHP verzija, PHP ekstenzije, mail i n8n izvršenje nisu dostupni za proveru iz ovog okruženja. Pre korišćenja passkey-a mora se primeniti `sql/2026-08-27_owner_passkeys.sql`, proveriti PHP >= 8.2, `ext-openssl`, `ext-json`, `ext-pdo_mysql`, `ext-curl` i HTTPS origin.
- DDoS zaštita/WAF nije implementirana u aplikaciji. To mora da obezbedi hosting ili Cloudflare prema `EDGE_SECURITY_HARDENING.md`.

### Medium

- WebAuthn browser acceptance nije fizički izvršen jer u ovom okruženju nisu dostupni browser-control alati ni validna owner sesija. Kod koristi stvarnu biblioteku i browser API, ali production acceptance ostaje obavezan.
- `mail()` i n8n su fire-and-forget integracije; aplikacija beleži rezultat gde je moguće, ali ne može garantovati isporuku bez staging/live provere.
- CSP dozvoljava `unsafe-inline` zato što je postojeći panel single-file inline HTML/JS. Nema `unsafe-eval`; dugoročno izdvojiti skripte u zasebne fajlove radi strožeg CSP-a.

### Low

- TOTP setup link/manual key se prikazuje samo tokom setup toka; QR nije dodat jer nema proverene lokalne QR dependency u ovom no-build projektu.
- Runtime schema helpers postoje radi kompatibilnosti sa starim cPanel instalacijama, ali migracije su kanonski način za kontrolisano production ažuriranje.

## Verification Performed

- `php -l` za sve API PHP fajlove.
- `node --check` za inline JavaScript u `admin.html` i `index.html`.
- `composer validate` i Composer lock dependency install.
- `tests/security_static.sh`, uključujući PHP/JS syntax, unsafe DOM pattern, WebAuthn wiring, headers i dependency checks.
- Deterministički HOTP/TOTP testovi, uključujući odbijanje nevažećeg koda.
- `git diff --check`.
- Nije izvršena DB migracija, stvarni browser passkey ceremony, live email, n8n ili production Inventory poziv.
