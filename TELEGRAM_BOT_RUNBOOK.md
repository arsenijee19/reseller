# Telegram admin bot: instalacija i operativni vodič

Bot je zaseban Python webhook servis. MySQL ostaje na cPanel-u; bot nema DB pristup, a cPanel API proverava poseban Bearer token i Telegram admin chat whitelist-u. Webhook koristi TLS na zasebnom portu 8443, bez izmene postojećeg Nginx-a ili drugih VPS servisa.

## Preduslovi

- Telegram bot token napravljen kod `@BotFather`.
- cPanel aplikacija povučena na novu verziju i SQL migracije pokrenute.
- PHP admin nalog ima pristup kartici Podešavanja i svež step-up potvrdu.
- VPS ima Docker/Compose, dostupan javni TCP port 8443 i DNS za `vps-03a19c11.vps.ovh.net`.
- Ažuriran server ne mora da otvara inbound portove 80/443. Bot webhook URL je `https://vps-03a19c11.vps.ovh.net:8443/telegram`.

## 1. Napravi Telegram bota

1. U Telegramu otvori `@BotFather`, pošalji `/newbot` i sačuvaj dobijeni token u password manager. Ne šalji ga u chat, Git ili cPanel.
2. Token će biti unet samo u `/etc/reseller-tg-bot/bot.env` na VPS-u.

## 2. Ažuriraj cPanel aplikaciju i bazu

1. Povuci aplikaciju iz Git-a na cPanel live direktorijum `/home/psigrersrs/reseller.psigre.rs`.
2. U phpMyAdmin-u prvo pokreni `sql/2026-10-06_wallet_transaction_types.sql` ako nije već pokrenuta, zatim sve ranije potrebne migracije (profile/security, wallet i order notes).
3. Pokreni `sql/2026-10-06_telegram_admin_bot.sql` tačno jednom. Migracija je samo dodatna: ne briše reseller-e, porudžbine ni postojeće transakcije. ALTER tabele nisu namenjeni ponovnom pokretanju; ako se izvršavanje prekine, proveri postojeće kolone pre nastavka.
4. Otvori Admin → Podešavanja → Telegram admin bot. Izaberi „Generiši / rotiraj API token“ i kopiraj token direktno u password manager/privatnu VPS sesiju. On se prikazuje samo jednom. Ako ga izgubiš, rotiraj ga ponovo i ažuriraj VPS.

## 3. Pripremi izolovan VPS servis

Poveži se SSH ključem i instaliraj kod u zaseban direktorijum, bez menjanja postojećih projekata:

```sh
sudo install -d -m 700 /opt/reseller-tg-bot
sudo install -d -m 700 /etc/reseller-tg-bot
sudo install -d -m 755 /etc/reseller-tg-bot/tls
```

Kopiraj samo sadržaj `telegram-bot/` u `/opt/reseller-tg-bot/` i compose fajl kao `/opt/reseller-tg-bot/compose.yaml`. Napravi `/etc/reseller-tg-bot/bot.env` iz `bot.env.example`, mode `0600`, i popuni:

- `BOT_TOKEN`: tajna koju je izdao BotFather.
- `WEBHOOK_SECRET`: nasumičan hex string, na primer rezultat `openssl rand -hex 32`.
- `PANEL_API_URL`: `https://reseller.psigre.rs/api/telegram_gateway.php`.
- `PANEL_API_TOKEN`: jednokratno prikazani token iz Admin → Podešavanja.
- `PUBLIC_WEBHOOK_URL`: `https://vps-03a19c11.vps.ovh.net:8443/telegram`.

Napravi self-signed server sertifikat sa DNS imenom koje Telegram otvara; javni sertifikat se registruje direktno kod Telegrama, privatni ključ ostaje samo na VPS-u:

```sh
sudo openssl req -x509 -newkey rsa:3072 -sha256 -days 365 \
  -nodes -keyout /etc/reseller-tg-bot/tls/privkey.pem \
  -out /etc/reseller-tg-bot/tls/fullchain.pem \
  -subj "/CN=vps-03a19c11.vps.ovh.net" \
  -addext "subjectAltName=DNS:vps-03a19c11.vps.ovh.net"
sudo chown 10001:10001 /etc/reseller-tg-bot/tls/privkey.pem
sudo chmod 400 /etc/reseller-tg-bot/tls/privkey.pem
sudo chmod 600 /etc/reseller-tg-bot/bot.env
sudo chmod 644 /etc/reseller-tg-bot/tls/fullchain.pem
```

Port 8443 mora biti dozvoljen na OVH firewall/security grupi i host firewall-u. Proveri pravila pre bilo kakve izmene; ne otvaraj širi opseg i ne menjaj postojeće Nginx/Docker konfiguracije. Servis mapira samo port `8443`, ima svoj Docker bridge, read-only root filesystem, bez Linux capabilities i pod ne-root UID-om.

## 4. Pokreni webhook i registruj ga

Pokreni servis iz `/opt/reseller-tg-bot`:

```sh
sudo docker compose -p reseller-tg-bot up -d --build
```

Registruj webhook koristeći javni sertifikat koji je montiran na VPS-u:

```sh
sudo sh -c 'set -a; . /etc/reseller-tg-bot/bot.env; set +a; \
  TLS_CERT=/etc/reseller-tg-bot/tls/fullchain.pem; export TLS_CERT; \
  /opt/reseller-tg-bot/set-webhook.sh'
```

U produkciji pokreni skriptu iz same bot fascikle (`telegram-bot/set-webhook.sh`) koja je kopirana u `/opt/reseller-tg-bot`; potrebno je da joj se dodeli executable bit ili da se pozove preko `sh`.

Pošalji botu `/start`. Neovlašćen odgovor prikazuje samo chat ID. Unesi taj ID u Admin → Podešavanja → Telegram admin bot, dodaj oznaku i sačuvaj. Klikni „Pošalji test poruku“.

## 5. Komande

- `/help`
- `/reseller ime|ID|email`, `/reselleri`
- `/dopuna ime|ID iznos [razlog]`, `/oduzmi ime|ID iznos [razlog]`
- `/transakcije ime|ID [broj]`, `/ponisti transakcija_ID`
- `/uplate`, `/porudzbine [nepla]`, `/placeno porudzbina_ID`
- `/dug`, `/cena product_id nova_cena`, `/proizvod product_id on|off`, `/danas`

Iznosi podržavaju `5000`, `5.000`, `5,000` i `5k`. Fuzzy pretraga nikada ne bira između više kandidata bez izbora admina. Sve finansijske promene zahtevaju pregled i izričitu potvrdu; potvrda ističe za 5 minuta. Promene čiji je apsolutni iznos veći od podešenog praga zahtevaju svež admin Authenticator kod; dozvoljeno je najviše pet pogrešnih kodova po akciji. Storno dodaje obrnutu transakciju; originalni red ne briše.

Komanda `/uplate` prikazuje prijave iz „Uplatio sam“. Trenutni portal ne traži iznos: admin unosi stvarno primljeni iznos u botu, osim ako je reseller kasnije dostavio iznos kroz kompatibilan API payload. Bot nikada ne pretpostavlja iznos.

## 6. Provera i održavanje

- Status poslednjeg webhook/outbox kontakta i poslednja greška vide se u Admin → Podešavanja.
- VPS logovi: `sudo docker compose -p reseller-tg-bot logs --tail=100 bot`.
- Restart: `sudo docker compose -p reseller-tg-bot restart bot`.
- Za webhook proveru koristi Telegram Bot API `getWebhookInfo` iz zaštićene sesije; ne objavljuj URL sa tokenom.
- Rotacija panel API tokena: generiši novi u adminu, promeni `PANEL_API_TOKEN`, restartuj bot.
- Ne koristi Telegram polling (`getUpdates`).
- Webhook se deduplikuje po Telegram `update_id`; ako obrada pukne, zapis se oslobađa i Telegram dobija HTTP 500 za ponovni pokušaj. Novčane akcije imaju zasebnu idempotency zaštitu u SQL-u.

## Ručni test-checklist

- Nepoznat chat pošalje `/start`: dobije samo poruku bez pristupa i svoj chat ID; ostale poruke se ignorišu.
- Poznat chat: `/reselleri`, fuzzy ime sa jednim i više pogodaka, `/reseller`, `/transakcije`.
- `/dopuna` odbija negativan, nulu i nevalidan iznos; proba `5000`, `5.000`, `5,000`, `5k`.
- Počni dopunu/oduzimanje, pa klikni potvrdu dva puta: balans i wallet transakcija se promene samo jednom.
- Potvrdi nakon pet minuta: akcija je istekla bez promene balansa.
- Potvrdi iznos iznad praga bez TOTP, sa pet pogrešnih kodova (akcija se zaključava), pa ponovi sa novom akcijom i ispravnim svežim TOTP kodom.
- Potvrdi uplatu: iznos, balans i payment notice status menjaju se u jednoj DB transakciji; drugi admin ne može da potvrdi istu prijavu.
- Odbij uplatu i proveri da odbijena prijava više nije u `/uplate`.
- Storniraj transakciju i pokušaj ponovo: nastaje najviše jedan obrnuti zapis; original ostaje.
- Istovremeno izvrši admin panel dopunu i bot dopunu: oba koriste row lock i saldo predstavlja zbir obe promene.
- `/placeno` zahteva pregled/potvrdu i ne menja red koji je već plaćen.
- Testiraj cenu/proizvod akciju, prijavu nedostavljene igre, zahtev za novu igru, low-balance i Inventory grešku.
- Isključi svako obaveštenje u admin podešavanjima i potvrdi da odgovarajući outbox događaj ne šalje poruku.
- Namerno pošalji pogrešan webhook secret: endpoint odbije zahtev HTTP 403.
- Zaustavi VPS bot: portal, login, baze i porudžbine i dalje rade; outbox šalje zaostala obaveštenja nakon povratka servisa.

## Ograničenja koja treba znati

- Email i uplata su odvojene stvari: „Uplatio sam“ prijava sama po sebi nije potvrda bankovne uplate.
- Promena cene iz Telegrama menja cenu katalog proizvoda, ne posebne reseller popuste.
- Telefonski webhook port zavisi od OVH/host firewall pravila. Ovaj runbook ne menja postojeće firewall/proxy konfiguracije.
- SQL migraciju, cPanel deploy, BotFather token i API token treba pripremiti pre aktiviranja webhooka.
