# Automatska beleška o isporuci

Kada n8n uspešno pošalje Telegram poruku `Automatski isporučeno`, prosleđuje login email i interni ID porudžbine u `api/auto_delivery_note.php`. Portal dopisuje `Login mail (automatska isporuka): adresa@example.com` u reseller beleške baš te porudžbine. Postojeće beleške se čuvaju, a ponovljeni poziv ne duplira sadržaj.

Callback se potpisuje po porudžbini pomoću postojeće server-side encryption konfiguracije. Potpis važi sedam dana; nema novog API ključa, SQL migracije, niti trajnog tajnog podatka u n8n export-u. Endpoint proverava porudžbinu, reseller ID, request ID, potpis i validan email. Nema upisa kada n8n grana automatske isporuke ne pošalje Telegram potvrdu, ili kada email/porudžbina nedostaju.

## Aktiviranje

1. Deploy-ovati najnoviji `main` na cPanel.
2. Uvesti `reseller-auto-delivery-notes.json` u n8n kao ažuriranu verziju postojećeg `reseller` workflow-a, sa postojećim credential-ima.
3. Aktivirati workflow i probati jednu porudžbinu koja se automatski isporuči.
4. Proveriti da se login email pojavio u belešci odgovarajuće porudžbine. Ručna/standardna isporuka bez Telegram potvrde ne menja beleške.

HTTP callback greška ne poništava već uspešnu isporuku niti menja balans; proveriti n8n execution log i pokušati ponovo u roku od sedam dana.
