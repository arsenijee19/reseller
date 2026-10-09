#!/usr/bin/env python3
"""End-to-end checks that logout really happens: deactivation, token changes, device revocation,
admin-device idle expiry and admin password change. Runs against a real PHP + MariaDB (see run.sh)."""
import json, os, subprocess, sys, uuid, base64, http.cookiejar, urllib.request, urllib.error

BASE = os.environ["BASE"]
SOCK = os.environ["DB_SOCK"]
ADMIN_PW = "AdminPass-12345"
WWW = os.environ["WWW"]
HERE = os.path.dirname(os.path.abspath(__file__))
T1, T2, T3 = "Orbit-Lantern-4821-xq", "Velvet-Harbor-9137-mz", "Cobalt-Meadow-6052-wk"
failures = []

def _run(query):
    r = subprocess.run(["mariadb", f"--socket={SOCK}", "-uroot", "pw", "-N", "-e", query], capture_output=True, text=True)
    if r.returncode != 0: raise RuntimeError(f"SQL failed: {query}\n{r.stderr}")
    return r.stdout.strip()

def sql(query):
    return subprocess.run(["mariadb", f"--socket={SOCK}", "-uroot", "pw", "-N", "-e", query],
                          capture_output=True, text=True).stdout.strip() if False else _run(query)

def php_hash(value):
    return subprocess.run(["php", "-r", "echo password_hash($argv[1], PASSWORD_DEFAULT);", value],
                          capture_output=True, text=True, check=True).stdout

class Client:
    def __init__(self, name):
        self.name, self.csrf = name, ""
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
    def call(self, path, body=None, method=None):
        data = json.dumps(body).encode() if body is not None else None
        req = urllib.request.Request(BASE + path, data=data, method=method or ("POST" if body is not None else "GET"))
        if data is not None: req.add_header("Content-Type", "application/json")
        if self.csrf: req.add_header("X-CSRF-Token", self.csrf)
        try:
            resp = self.opener.open(req, timeout=20); status, text = resp.status, resp.read().decode()
        except urllib.error.HTTPError as e:
            status, text = e.code, e.read().decode()
        try: payload = json.loads(text)
        except Exception: payload = {"_raw": text[:200]}
        if isinstance(payload, dict) and payload.get("csrf_token"): self.csrf = payload["csrf_token"]
        return status, payload

def check(label, condition, detail=""):
    print(("PASS  " if condition else "FAIL  ") + label + ("" if condition else f"   -> {detail}"))
    if not condition: failures.append(label)

def reset_limits():
    sql("DELETE FROM login_attempts")

def totp_enable(who, ident):
    return subprocess.run(["php", f"{HERE}/totp.php", WWW, "enable", who, str(ident)], capture_output=True, text=True, check=True).stdout.strip()

def totp_code(secret):
    return subprocess.run(["php", f"{HERE}/totp.php", WWW, "code", secret], capture_output=True, text=True, check=True).stdout.strip()

def reseller_login(token, name="web"):
    c = Client(name); s, p = c.call("/api/login.php", {"token": token}); return c, s, p

def admin_login():
    c = Client("admin"); s, p = c.call("/api/admin.php?action=login", {"password": ADMIN_PW})
    assert s == 200 and p.get("ok"), f"admin login failed {s} {p}"
    s, p = c.call("/api/admin.php?action=admin_reauth", {"current_password": ADMIN_PW})
    assert s == 200 and p.get("ok"), f"admin reauth failed {s} {p}"
    return c

def update_reseller(adm, rid, status="active", new_token=""):
    body = {"id": rid, "email": "reseller1@example.test", "status": status, "display_name": "Test Reseller",
            "phone": "+381641234567", "balance_rsd": 1000, "discount_percent": 0}
    if new_token: body["new_token"] = new_token
    return adm.call("/api/admin.php?action=update_reseller", body)

def new_code(adm, rid, label):
    s, p = adm.call("/api/admin.php?action=device_activation_create", {"reseller_id": rid, "device_label": label})
    assert s == 200 and p.get("ok"), f"code create failed {s} {p}"
    return p["code"]

def activate(code, name="app"):
    c = Client(name)
    dev, secret = str(uuid.uuid4()), base64.urlsafe_b64encode(os.urandom(32)).decode().rstrip("=")
    s, p = c.call("/api/device_auth.php?action=activate", {"code": code, "device_id": dev, "device_token": secret, "platform": "android", "device_name": "Test phone"})
    return c, dev, secret, s, p

def device_session(dev, secret):
    c = Client("renew"); s, p = c.call("/api/device_auth.php?action=session", {"device_id": dev, "device_token": secret}); return c, s, p

def sessions_ok(c):
    return (c.call("/api/me.php")[0], c.call("/api/orders.php?all=1")[0], c.call("/api/prices.php")[0])

# ---------------------------------------------------------------- setup
Client("boot").call("/api/admin.php?action=session")          # creates admin + security tables/columns
sql("INSERT INTO product_prices (product_id, product_name, account_type, price) VALUES ('p1','Test Game','PS5',1000)")
sql(f"INSERT INTO resellers (id, email, token_hash, status, balance_rsd) VALUES (1,'reseller1@example.test','{php_hash(T1)}','active',5000)")
sql("UPDATE resellers SET display_name='Test Reseller', phone='+381641234567', profile_completed_at=NOW() WHERE id=1")
RID = 1

print("\n== 1. Deactivating a reseller ends web sessions immediately")
reset_limits()
web, s, p = reseller_login(T1)
check("reseller logs in", s == 200 and p.get("ok"), (s, p))
check("me/orders/prices work while active", sessions_ok(web) == (200, 200, 200), sessions_ok(web))
others = (web.call("/api/products.php")[0], web.call("/api/transactions.php")[0], web.call("/api/profile.php")[0])
check("products/transactions/profile work while active", others == (200, 200, 200), others)
s, p = web.call("/api/payment_notice.php", {})
check("'Uplatio sam' answers immediately and records the notice", s == 200 and p.get("ok") and p.get("notice_recorded") is True and p.get("mail_sent") is None, (s, p))
check("the notice is stored in the database", sql("SELECT COUNT(*) FROM payment_notice_requests WHERE reseller_id = 1") == "1")
adm = admin_login()
s, p = update_reseller(adm, RID, status="inactive")
check("admin deactivates reseller", s == 200, (s, p))
res = sessions_ok(web)
check("existing session is rejected on me, orders AND prices (401)", res == (401, 401, 401), res)
reset_limits()
check("ended session is flagged session_ended so the app can log out", web.call("/api/me.php")[1].get("session_ended") is True)
check("deactivated reseller cannot log in again", reseller_login(T1)[1] == 401)
update_reseller(adm, RID, status="active")
reset_limits()
web, s, p = reseller_login(T1)
check("after reactivation a fresh login works", s == 200 and sessions_ok(web) == (200, 200, 200))

print("\n== 2. Admin changes the token: old session dies, old token stops working")
old_session = web
s, p = update_reseller(adm, RID, new_token=T2)
check("admin sets new token", s == 200, (s, p))
res = sessions_ok(old_session)
check("old web session is rejected (401)", res == (401, 401, 401), res)
reset_limits()
check("old token no longer logs in", reseller_login(T1)[1] == 401)
web, s, p = reseller_login(T2)
check("new token logs in", s == 200 and sessions_ok(web) == (200, 200, 200))

print("\n== 3. App device: revoke from admin panel")
code = new_code(adm, RID, "Test A")
app, dev, secret, s, p = activate(code)
check("activation returns role=reseller", s == 200 and p.get("role") == "reseller", (s, p))
check("app session works", sessions_ok(app) == (200, 200, 200))
s, d = adm.call(f"/api/admin.php?action=device_management&reseller_id={RID}")
rec = next(x for x in d["devices"] if x["device_id"] == dev)
s, p = adm.call("/api/admin.php?action=device_revoke", {"reseller_id": RID, "device_record_id": int(rec["id"])})
check("admin revokes device", s == 200 and p.get("ok"), (s, p))
res = sessions_ok(app)
check("live app session is rejected (401)", res == (401, 401, 401), res)
check("device can no longer renew its session (401)", device_session(dev, secret)[1] == 401)

print("\n== 4. App device: reseller deactivated, then reactivated")
reset_limits()
app, dev, secret, s, p = activate(new_code(adm, RID, "Test B"))
check("second device activates", s == 200)
update_reseller(adm, RID, status="inactive")
check("app session rejected while deactivated", sessions_ok(app) == (401, 401, 401))
check("device renew rejected while deactivated (401)", device_session(dev, secret)[1] == 401)
update_reseller(adm, RID, status="active")
c, s, p = device_session(dev, secret)
check("device works again after reactivation (device itself was never revoked)", s == 200 and sessions_ok(c) == (200, 200, 200), (s, p))

print("\n== 5. App device: admin changes the token -> new activation required")
reset_limits()
app, dev, secret, s, p = activate(new_code(adm, RID, "Test C"))
s, p = update_reseller(adm, RID, new_token=T3)
check("token changed", s == 200)
check("app session rejected after token change", sessions_ok(app) == (401, 401, 401))
check("device renew rejected: device was revoked, new code needed", device_session(dev, secret)[1] == 401)

print("\n== 6. Reseller changes own token: other sessions and devices end, current one stays")
reset_limits()
web_b, s, _ = reseller_login(T3, "web-b")
web_c, s2, _ = reseller_login(T3, "web-c")
app_d, dev_d, secret_d, s3, p3 = activate(new_code(adm, RID, "Test D"))
check("three sessions are live", s == 200 and s2 == 200 and s3 == 200 and sessions_ok(web_b) == (200, 200, 200))
s, p = web_b.call("/api/profile.php", {"email": "reseller1@example.test", "phone": "+381641234567", "display_name": "Test Reseller",
                                          "current_token": T3, "new_token": T1, "confirm_token": T1})
check("reseller changes own token", s == 200 and p.get("ok"), (s, p))
check("session that changed it stays logged in", sessions_ok(web_b) == (200, 200, 200), sessions_ok(web_b))
check("other web session is logged out", sessions_ok(web_c) == (401, 401, 401))
check("app device session is logged out", sessions_ok(app_d) == (401, 401, 401))
check("app device cannot renew (new code needed)", device_session(dev_d, secret_d)[1] == 401)

print("\n== 7. Admin app device: revoke, idle expiry, password change")
reset_limits()
def admin_code(label):
    s, p = adm.call("/api/admin.php?action=admin_device_activation_create", {"device_label": label})
    assert s == 200 and p.get("ok"), (s, p); return p["code"]
a_app, a_dev, a_secret, s, p = activate(admin_code("Admin phone 1"), "admin-app")
check("admin code activates with role=admin", s == 200 and p.get("role") == "admin", (s, p))
check("admin device has an admin session", a_app.call("/api/admin.php?action=2fa_status")[0] == 200)
check("reseller endpoints are NOT available to the admin device", a_app.call("/api/me.php")[0] == 401)
s, d = adm.call("/api/admin.php?action=admin_device_management")
rec = next(x for x in d["devices"] if x["device_id"] == a_dev)
s, p = adm.call("/api/admin.php?action=admin_device_revoke", {"device_record_id": int(rec["id"])})
check("admin revokes admin device", s == 200 and p.get("ok"), (s, p))
check("live admin-device session is rejected (401)", a_app.call("/api/admin.php?action=2fa_status")[0] == 401)
check("admin-device status endpoint reports logged_out", a_app.call("/api/admin.php?action=session")[1].get("logged_in") is False)
check("revoked admin device cannot renew (401)", device_session(a_dev, a_secret)[1] == 401)

reset_limits()
i_app, i_dev, i_secret, s, p = activate(admin_code("Admin idle"), "admin-idle")
sql(f"UPDATE admin_app_devices SET last_seen_at = NOW() - INTERVAL 31 DAY WHERE device_id = '{i_dev}'")
check("admin device idle for 31 days cannot renew (401)", device_session(i_dev, i_secret)[1] == 401)
s, d = adm.call("/api/admin.php?action=admin_device_management")
rec = next(x for x in d["devices"] if x["device_id"] == i_dev)
check("idle device is shown as revoked in the panel", bool(rec["revoked_at"]), rec)

reset_limits()
x_app, x_dev, x_secret, s, p = activate(admin_code("Admin pw test"), "admin-pw")
other = admin_login()
s, p = adm.call("/api/admin.php?action=change_password", {"current_password": ADMIN_PW, "new_password": "NewAdminPass-98765", "confirm_password": "NewAdminPass-98765"})
check("admin changes password", s == 200 and p.get("ok"), (s, p))
check("session that changed it stays logged in", adm.call("/api/admin.php?action=2fa_status")[0] == 200)
check("other admin web session is logged out", other.call("/api/admin.php?action=2fa_status")[0] == 401)
check("admin app device is logged out", x_app.call("/api/admin.php?action=2fa_status")[0] == 401)
check("admin app device cannot renew (new code needed)", device_session(x_dev, x_secret)[1] == 401)

print("\n== 8. Logins that use 2-step verification are bound to the credential too")
ADMIN_PW = "NewAdminPass-98765"
reset_limits()
r_secret = totp_enable("reseller", RID)
c = Client("2fa-reseller"); s, p = c.call("/api/login.php", {"token": T1})
check("reseller with 2FA gets a pending login", s == 200 and p.get("requires_2fa") is True, (s, p))
s, p = c.call("/api/2fa.php?action=challenge", {"code": totp_code(r_secret)})
check("reseller 2FA code completes the login", s == 200 and p.get("ok"), (s, p))
check("2FA reseller session works", sessions_ok(c) == (200, 200, 200))
update_reseller(adm2 := admin_login(), RID, new_token=T3)
check("token change ends the 2FA reseller session", sessions_ok(c) == (401, 401, 401))

reset_limits()
a_secret = totp_enable("admin", 1)
c = Client("2fa-admin"); s, p = c.call("/api/admin.php?action=login", {"password": ADMIN_PW})
check("admin with 2FA gets a pending login", s == 200 and p.get("requires_2fa") is True, (s, p))
s, p = c.call("/api/admin.php?action=2fa_challenge", {"code": totp_code(a_secret)})
check("admin 2FA code completes the login", s == 200 and p.get("ok"), (s, p))
check("2FA admin session works", c.call("/api/admin.php?action=2fa_status")[0] == 200)
s, p = adm2.call("/api/admin.php?action=change_password", {"current_password": ADMIN_PW, "new_password": "ThirdAdminPass-55555", "confirm_password": "ThirdAdminPass-55555"})
check("password change from another session succeeds", s == 200 and p.get("ok"), (s, p))
check("password change ends the 2FA admin session", c.call("/api/admin.php?action=2fa_status")[0] == 401)

print()
if failures:
    print(f"{len(failures)} FAILED: " + "; ".join(failures)); sys.exit(1)
print("ALL SESSION TESTS PASSED")
