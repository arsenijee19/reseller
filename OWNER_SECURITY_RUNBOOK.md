# Owner Security Runbook

## 1. Production prerequisites

1. U cPanel MultiPHP Manager proverite da `reseller.psigre.rs` koristi PHP 8.4.1 ili noviji ako želite Passkey. Password + TOTP fallback ne učitava WebAuthn dependency.
2. Proverite ekstenzije `pdo_mysql`, `curl`, `openssl` i `json`.
3. U `api/config.local.php` dodajte u `security`:

```php
'origin' => 'https://reseller.psigre.rs',
'webauthn' => [
  'rp_id' => 'reseller.psigre.rs',
  'origin' => 'https://reseller.psigre.rs',
  'rp_name' => 'PlayWorld.rs Admin',
],
```

4. Postavite jaku random `security.encryption_key`. Ne čuvajte je u Git-u, ticketima ili chatu.
5. Pokrenite `sql/2026-08-27_owner_passkeys.sql` i prethodne migracije na pravoj reseller bazi.

## 2. Admin 2FA

1. Otvorite `/admin.html` i prijavite se admin šifrom.
2. U panelu otvorite `Admin 2-step zaštita` → `Aktiviraj 2FA`.
3. Unesite trenutnu admin šifru, kliknite `Generiši setup ključ`, dodajte ključ u Authenticator i unesite trenutni šestocifreni kod.
4. Recovery kodove sačuvajte van servera. Prikazuju se samo nakon generisanja.
5. Na sledećem loginu šifra otvara samo pending ekran; pun pristup dolazi tek posle TOTP/recovery potvrde.
6. `Nazad` poništava pending login na serveru i rotira session ID; ne vraća se na panel.

## 3. Owner passkey

1. Posle login-a otvorite `Passkey prijava vlasnika`.
2. Kliknite `Dodaj Passkey`, unesite trenutnu admin šifru i, ako je 2FA uključen, trenutni TOTP kod.
3. Kliknite `Nastavi na uređaj` i završite browser/platform authenticator ceremony.
4. Dodajte drugi pouzdan uređaj kao rezervu. Naziv je samo label, privatni ključ ne napušta uređaj.
5. Na login ekranu koristite `Prijavi se Passkey-em`; šifra + TOTP ostaju fallback.
6. Za uklanjanje koristite `Ukloni`, potvrdite dijalog, pa unesite šifru i TOTP. Ne uklanjajte sve pouzdane uređaje dok fallback nije provereno dostupan.

Kritične admin akcije (Inventory podešavanja, reseller balance/token izmene i poništavanje porudžbine) traže svežu owner reautentikaciju. Ona važi 15 minuta; kada je admin 2FA uključen, potrebni su trenutna šifra i novi TOTP kod.

## 4. Incident actions

- Ako je kompromitovana admin šifra: odmah promenite šifru, regenerišite recovery kodove, uklonite nepoznate passkey-e i pregledajte Security audit.
- Ako je kompromitovan `security.encryption_key`: planirajte kontrolisanu rotaciju uz re-encryption postojećih TOTP secret-a; nemojte samo promeniti ključ jer postojeći 2FA secret-i više neće moći da se dešifruju.
- Ako se pojavi `Profile kolone nisu dostupne`, primenite account-security migraciju i proverite DB privilegije.
- Ako passkey vrati origin/RP grešku, proverite da browser otvara tačno `https://reseller.psigre.rs` i da se `rp_id` ne razlikuje od produkcionog hosta.

## 5. Deployment

- Git/cPanel deploy kopira root `vendor/` zajedno sa PHP API-jem. Ne briše `api/config.local.php`.
- Posle pull/deploy-a očistite samo browser cache ako je potrebno; server šalje no-store/no-cache za HTML/PHP.
- Obavezno uradite owner acceptance test: admin login bez 2FA, admin login sa 2FA, Back invalidacija, passkey register/login/revoke, jedan normalan reseller order, jedan missing-game report i pregled audit zapisa.
