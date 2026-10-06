#!/usr/bin/env python3
"""Isolated Telegram webhook worker for the PlayWorld reseller admin panel."""
from __future__ import annotations

import difflib
from datetime import datetime
import hmac
import html
import json
import logging
import os
import re
import shlex
import ssl
import threading
import time
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any

BOT_TOKEN = os.environ["BOT_TOKEN"]
WEBHOOK_SECRET = os.environ["WEBHOOK_SECRET"]
PANEL_API_URL = os.environ["PANEL_API_URL"].rstrip("/")
PANEL_API_TOKEN = os.environ["PANEL_API_TOKEN"]
TLS_CERT = os.environ.get("TLS_CERT", "/run/secrets/fullchain.pem")
TLS_KEY = os.environ.get("TLS_KEY", "/run/secrets/privkey.pem")
PORT = int(os.environ.get("PORT", "8443"))
ALLOWED_TELEGRAM_USERNAME = os.environ.get("ALLOWED_TELEGRAM_USERNAME", "arsoarso").lstrip("@").casefold()
logging.basicConfig(level=os.environ.get("LOG_LEVEL", "INFO"), format="%(asctime)s %(levelname)s %(message)s")
API_LOCK = threading.Lock()
LAST_API_CALL = 0.0


def parse_amount(value: str) -> int:
    """Accept Serbian thousands separators and k suffix without accepting negatives."""
    raw = value.strip().lower().replace("rsd", "").replace("din", "").strip()
    if re.fullmatch(r"\d+(?:[.,]\d+)?\s*k", raw):
        number = float(raw[:-1].strip().replace(",", "."))
        amount = int(number * 1000)
    else:
        if not re.fullmatch(r"\+?\d[\d\s.,]*", raw):
            raise ValueError("Iznos mora biti pozitivan broj, npr. 5000, 5.000 ili 5k.")
        digits = re.sub(r"[\s.,]", "", raw.lstrip("+"))
        amount = int(digits)
    if amount <= 0 or amount > 100_000_000:
        raise ValueError("Iznos mora biti između 1 i 100.000.000 RSD.")
    return amount


def format_rsd(value: Any, signed: bool = False) -> str:
    try:
        number = int(value or 0)
    except (TypeError, ValueError):
        number = 0
    formatted = f"{number:+,}" if signed else f"{number:,}"
    return formatted.replace(",", ".") + " RSD"


def format_date(value: Any) -> str:
    raw = str(value or "")
    for pattern in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%dT%H:%M:%S"):
        try:
            date = datetime.strptime(raw[:19], pattern)
            return f"{date.day}. {date.month}. {date.year}. {date:%H:%M}"
        except ValueError:
            pass
    return raw


def normalize(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "", value.lower().replace("š", "s").replace("đ", "dj").replace("č", "c").replace("ć", "c").replace("ž", "z"))


def parse_command(text: str) -> tuple[str, list[str]]:
    try:
        parts = shlex.split(text.strip())
    except ValueError:
        parts = text.strip().split()
    if not parts or not parts[0].startswith("/"):
        return "", []
    command = parts[0][1:].split("@", 1)[0].lower()
    return command, parts[1:]


def is_allowed_username(username: str | None) -> bool:
    return bool(username) and username.lstrip("@").casefold() == ALLOWED_TELEGRAM_USERNAME


def is_allowed_start_message(message: dict[str, Any]) -> bool:
    command, _ = parse_command(str(message.get("text") or ""))
    sender = message.get("from") or {}
    return command == "start" and is_allowed_username(sender.get("username"))


def panel(action: str, chat_id: int, **fields: Any) -> dict[str, Any]:
    global LAST_API_CALL
    data = json.dumps({"action": action, "chat_id": chat_id, **fields}).encode()
    request = urllib.request.Request(PANEL_API_URL, data=data, headers={
        "X-Panel-Token": PANEL_API_TOKEN,
        "Content-Type": "application/json",
        "Accept": "application/json",
    })
    with API_LOCK:
        delay = 0.15 - (time.monotonic() - LAST_API_CALL)
        if delay > 0:
            time.sleep(delay)
        try:
            with urllib.request.urlopen(request, timeout=12) as response:
                result = json.loads(response.read().decode("utf-8"))
        except urllib.error.HTTPError as exc:
            try:
                result = json.loads(exc.read().decode("utf-8"))
            except Exception:
                result = {"ok": False, "error": "Panel API nije dostupan."}
        except Exception as exc:
            logging.warning("Panel API request failed: %s", type(exc).__name__)
            result = {"ok": False, "error": "Panel API trenutno nije dostupan."}
        LAST_API_CALL = time.monotonic()
    return result


def telegram(method: str, data: dict[str, Any]) -> dict[str, Any]:
    url = f"https://api.telegram.org/bot{BOT_TOKEN}/{method}"
    request = urllib.request.Request(url, data=json.dumps(data).encode(), headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            return json.loads(response.read().decode("utf-8"))
    except Exception as exc:
        logging.warning("Telegram %s failed: %s", method, type(exc).__name__)
        return {"ok": False}


def send(chat_id: int, text: str, keyboard: list[list[dict[str, str]]] | None = None, edit: int | None = None) -> int | None:
    payload: dict[str, Any] = {"chat_id": chat_id, "text": text, "parse_mode": "HTML", "disable_web_page_preview": True}
    if keyboard:
        payload["reply_markup"] = {"inline_keyboard": keyboard}
    if edit:
        payload["message_id"] = edit
        payload["reply_markup"] = {"inline_keyboard": keyboard or []}
        telegram("editMessageText", payload)
        return edit
    else:
        response = telegram("sendMessage", payload)
        return int(response.get("result", {}).get("message_id", 0)) or None if response.get("ok") else None


def answer_callback(callback_id: str, message: str = "") -> None:
    telegram("answerCallbackQuery", {"callback_query_id": callback_id, "text": message[:180], "show_alert": bool(message)})


def safe(value: Any) -> str:
    return html.escape(str(value or ""), quote=True)


def name_of(reseller: dict[str, Any]) -> str:
    return str(reseller.get("display_name") or reseller.get("email") or f"#{reseller.get('id')}")


def begin_balance(chat_id: int, reseller_id: int, amount: int, reason: str, kind: str = "balance_adjust", extra: dict[str, Any] | None = None, edit_id: int | None = None) -> None:
    payload = {"reseller_id": reseller_id, "amount_rsd": amount, "reason": reason}
    if extra:
        payload.update(extra)
    result = panel("begin_action", chat_id, kind=kind, payload=payload)
    if not result.get("ok"):
        send(chat_id, safe(result.get("error", "Akcija nije mogla da se pripremi.")))
        return
    preview = result["preview"]
    text = (f"<b>{safe(preview.get('title'))}</b>\nBalans: {format_rsd(preview.get('before'))} → "
            f"<b>{format_rsd(preview.get('after'))}</b>\nPromena: {format_rsd(preview.get('amount'))}\nRazlog: {safe(preview.get('reason'))}\n\n"
            "Potvrdite u narednih 5 minuta.")
    message_id = send(chat_id, text, [[{"text": "Potvrdi", "callback_data": f"ok:{result['action_id']}"}, {"text": "Otkaži", "callback_data": f"no:{result['action_id']}"}]], edit=edit_id)
    if message_id: panel("set_action_message", chat_id, action_id=result["action_id"], message_id=message_id)


def begin_order_action(chat_id: int, kind: str, payload: dict[str, Any], label: str, edit_id: int | None = None) -> None:
    result = panel("begin_action", chat_id, kind=kind, payload=payload)
    if not result.get("ok"):
        send(chat_id, safe(result.get("error", "Akcija nije mogla da se pripremi.")))
        return
    preview = result["preview"]
    details = [f"<b>{safe(preview.get('title', label))}</b>"]
    for key, title in (("before", "Trenutna cena"), ("after", "Nova vrednost"), ("amount", "Promena"), ("reason", "Detalji")):
        if key in preview:
            value = format_rsd(preview[key], signed=key == "amount") if key in ("before", "after", "amount") and isinstance(preview[key], int) else safe(preview[key])
            details.append(f"{title}: {value}")
    details.append("Potvrdite u narednih 5 minuta.")
    message_id = send(chat_id, "\n".join(details), [[{"text": "Potvrdi", "callback_data": f"ok:{result['action_id']}"}, {"text": "Otkaži", "callback_data": f"no:{result['action_id']}"}]], edit=edit_id)
    if message_id: panel("set_action_message", chat_id, action_id=result["action_id"], message_id=message_id)


def reseller_matches(chat_id: int, query: str) -> None:
    result = panel("list_resellers", chat_id)
    if not result.get("ok"):
        send(chat_id, safe(result.get("error", "Lista nije dostupna.")))
        return
    rows = result.get("resellers", [])
    exact = [r for r in rows if query.isdigit() and int(r["id"]) == int(query)]
    if not exact:
        target = normalize(query)
        scored = []
        for row in rows:
            hay = [normalize(str(row.get("display_name") or "")), normalize(str(row.get("email") or ""))]
            score = max((difflib.SequenceMatcher(None, target, name).ratio() for name in hay if name), default=0)
            if target and any(target in name for name in hay):
                score = max(score, 0.93)
            if score >= 0.42:
                scored.append((score, row))
        exact = [row for _, row in sorted(scored, key=lambda pair: pair[0], reverse=True)[:8]]
    if not exact:
        send(chat_id, "Nisam pronašao resellera. Probajte ime, email ili ID.")
    elif len(exact) == 1:
        show_reseller(chat_id, int(exact[0]["id"]))
    else:
        keyboard = [[{"text": f"{name_of(row)} · #{row['id']}", "callback_data": f"r:{row['id']}"}] for row in exact]
        send(chat_id, "Izaberite odgovarajućeg resellera:", keyboard)


def show_reseller(chat_id: int, reseller_id: int) -> None:
    result = panel("reseller", chat_id, reseller_id=reseller_id)
    if not result.get("ok"):
        send(chat_id, safe(result.get("error", "Reseller nije pronađen.")))
        return
    row = result["reseller"]
    balance = int(row.get("balance_rsd") or 0)
    text = (f"<b>{safe(name_of(row))} · #{row['id']}</b>\nEmail: {safe(row.get('email'))}\n"
            f"Telefon: {safe(row.get('phone') or 'nije unet')}\nBalans: {'<b>' if balance < 0 else ''}{format_rsd(balance)}{'</b>' if balance < 0 else ''}\n"
            f"Status: {safe(row.get('status'))} · 2FA: {safe(result.get('two_factor'))} · Popust: {safe(row.get('discount_percent', 0))}%")
    keyboard = [[{"text": "Dopuni", "callback_data": f"adjust:{row['id']}:plus"}, {"text": "Oduzmi", "callback_data": f"adjust:{row['id']}:minus"}],
                [{"text": "Transakcije", "callback_data": f"tx:{row['id']}"}, {"text": "Porudžbine", "callback_data": f"orders:{row['id']}"}]]
    send(chat_id, text, keyboard)


def show_transactions(chat_id: int, reseller_id: int, limit: int = 10) -> None:
    result = panel("transactions", chat_id, reseller_id=reseller_id, limit=limit)
    if not result.get("ok"):
        send(chat_id, safe(result.get("error", "Transakcije nisu dostupne.")))
        return
    entries = result.get("transactions", [])
    send(chat_id, "<b>Poslednje transakcije</b>\n" + ("\n".join(f"#{r['id']} · {safe(r['type'])} · {format_rsd(r['amount_rsd'], signed=True)}\n{safe(r.get('description'))} · {safe(format_date(r.get('created_at')))}" for r in entries) or "Nema transakcija."))


def handle_message(message: dict[str, Any]) -> None:
    chat_id = int(message.get("chat", {}).get("id", 0))
    text = str(message.get("text") or "").strip()
    sender = message.get("from") or {}
    if not chat_id or not text or not is_allowed_username(sender.get("username")):
        return
    command, args = parse_command(text)
    if command == "start":
        send(chat_id, f"Chat ID ovog naloga: <code>{chat_id}</code>. Dodajte ga u Admin → Podešavanja → Telegram admin bot.")
        return
    authorized = panel("authorized", chat_id, chat_id=chat_id)
    is_admin = bool(authorized.get("authorized"))
    if not is_admin:
        return
    panel("heartbeat", chat_id, service="webhook")
    conversation = panel("get_conversation", chat_id, chat_id=chat_id).get("conversation")
    if conversation and not command:
        state = conversation.get("state")
        payload = conversation.get("payload") or {}
        if state == "await_amount":
            try:
                amount = parse_amount(text)
            except ValueError as exc:
                send(chat_id, safe(exc))
                return
            panel("clear_conversation", chat_id, chat_id=chat_id)
            if payload.get("notice_id"):
                begin_balance(chat_id, int(payload["reseller_id"]), amount, f"Potvrđena uplata preko Telegrama · obaveštenje #{payload['notice_id']}", "payment_confirm", {"notice_id": int(payload["notice_id"]), "amount_rsd": amount}, int(payload.get("message_id") or 0) or None)
            else:
                signed = amount if payload.get("operation") == "plus" else -amount
                begin_balance(chat_id, int(payload["reseller_id"]), signed, str(payload.get("reason") or "Promena balansa preko Telegrama"), edit_id=int(payload.get("message_id") or 0) or None)
            return
        if state == "await_totp":
            result = panel("confirm_action", chat_id, action_id=payload.get("action_id"), totp_code=text)
            finish_action(chat_id, result, int(payload.get("message_id") or 0))
            return
        if state == "await_reject_reason":
            panel("clear_conversation", chat_id, chat_id=chat_id)
            result = panel("reject_payment", chat_id, notice_id=payload.get("notice_id"), reason=text)
            send(chat_id, "Uplata je odbijena." if result.get("ok") else safe(result.get("error", "Nije uspelo.")), edit=int(payload.get("message_id") or 0) or None)
            return
        if state == "await_reseller_action":
            send(chat_id, "Izaberite resellera pomoću dugmeta ispod.")
            return

    if command in ("start", "help", "pomoc"):
        send(chat_id, "<b>PlayWorld admin bot</b>\n/reselleri · /reseller ime|ID|email\n/dopuna ime|ID iznos razlog\n/oduzmi ime|ID iznos razlog\n/transakcije ime|ID [broj]\n/ponisti transakcija_ID\n/uplate · /porudzbine [nepla] · /placeno porudžbina_ID\n/dug · /cena product_ID nova_cena · /proizvod product_ID on|off · /danas")
    elif command == "reselleri":
        result = panel("list_resellers", chat_id)
        rows = result.get("resellers", [])
        send(chat_id, "<b>Reselleri</b> · izaberite nalog:", [[{"text": f"{name_of(r)} · {format_rsd(r.get('balance_rsd'))}", "callback_data": f"r:{r['id']}"}] for r in rows[:50]])
    elif command == "reseller" and args:
        reseller_matches(chat_id, " ".join(args))
    elif command in ("dopuna", "oduzmi") and len(args) >= 2:
        try:
            amount = parse_amount(args[1])
        except ValueError as exc:
            send(chat_id, safe(exc)); return
        reason = " ".join(args[2:]) or ("Dopuna preko Telegrama" if command == "dopuna" else "Oduzimanje preko Telegrama")
        choose_reseller_for_adjustment(chat_id, args[0], amount if command == "dopuna" else -amount, reason)
    elif command == "transakcije" and args:
        limit = int(args[1]) if len(args) > 1 and args[1].isdigit() else 10
        find_reseller_and(chat_id, args[0], lambda row: show_transactions(chat_id, int(row["id"]), limit))
    elif command == "ponisti" and args and args[0].isdigit():
        begin_order_action(chat_id, "reverse_transaction", {"transaction_id": int(args[0])}, "Storno")
    elif command == "uplate":
        payments = panel("payments", chat_id).get("payments", [])
        if not payments: send(chat_id, "Nema uplata koje čekaju potvrdu.")
        for p in payments:
            title = p.get("display_name") or p.get("reseller_email")
            amount = p.get("amount_rsd")
            amount_text = format_rsd(amount) if amount else "iznos nije prijavljen"
            keyboard = [[{"text": f"Potvrdi {amount_text}" if amount else "Unesi iznos", "callback_data": f"pay:{p['id']}"}, {"text": "Drugi iznos", "callback_data": f"other:{p['id']}"}, {"text": "Odbij", "callback_data": f"reject:{p['id']}"}]]
            send(chat_id, f"<b>Nova uplata · {safe(title)} · #{p['reseller_id']}</b>\nPrijavljeno: {amount_text}\nBalans: {format_rsd(p.get('balance_rsd'))}\nVreme: {safe(format_date(p.get('clicked_at')))}", keyboard)
    elif command == "porudzbine":
        orders = panel("orders", chat_id, unpaid=bool(args and args[0].lower().startswith("nepla"))).get("orders", [])
        if not orders: send(chat_id, "Nema porudžbina za prikaz.")
        for order in orders:
            title = f"{order.get('display_name') or order.get('email')} · #{order['id']}"
            desc = f"{order.get('product_name') or 'Igra'} · {order.get('account_type') or ''} · {format_rsd(order.get('price_rsd'))} · {order.get('created_at')}"
            keyboard = [[{"text": "Označi plaćeno", "callback_data": f"paid:{order['id']}"}]] if not order.get("reseller_paid") else None
            send(chat_id, f"<b>{safe(title)}</b>\n{safe(desc)}\nStatus plaćanja: {'plaćeno' if order.get('reseller_paid') else 'neplaćeno'}", keyboard)
    elif command == "placeno" and args and args[0].isdigit():
        begin_order_action(chat_id, "mark_order_paid", {"order_id": int(args[0])}, "Označi porudžbinu plaćenom")
    elif command == "dug":
        result = panel("debt", chat_id)
        entries = result.get("resellers", [])
        send(chat_id, "<b>Reselleri u minusu</b>\n" + "\n".join(f"#{r['id']} {safe(name_of(r))}: <b>{format_rsd(r['balance_rsd'])}</b>" for r in entries) + f"\n\nUkupan dug: <b>{format_rsd(result.get('total_debt_rsd'))}</b>")
    elif command == "cena" and len(args) == 2:
        try: price = parse_amount(args[1])
        except ValueError as exc: send(chat_id, safe(exc)); return
        begin_order_action(chat_id, "product_price", {"product_id": args[0], "price": price}, "Promena cene")
    elif command == "proizvod" and len(args) == 2 and args[1].lower() in ("on", "off"):
        begin_order_action(chat_id, "product_status", {"product_id": args[0], "active": args[1].lower() == "on"}, "Status proizvoda")
    elif command == "danas":
        result = panel("today", chat_id)
        orders, payments = result.get("orders") or {}, result.get("payments") or {}
        send(chat_id, f"<b>Danas</b>\nPorudžbine: {orders.get('count', 0)} · {format_rsd(orders.get('volume'))}\nPotvrđene uplate: {payments.get('count', 0)} · {format_rsd(payments.get('volume'))}")
    else:
        send(chat_id, "Nepoznata komanda. Pošaljite /help za spisak.")


def find_reseller_and(chat_id: int, query: str, callback: Any) -> None:
    result = panel("list_resellers", chat_id)
    rows = result.get("resellers", [])
    if query.isdigit():
        found = [r for r in rows if int(r["id"]) == int(query)]
    else:
        target = normalize(query)
        ranked = sorted(rows, key=lambda r: max(difflib.SequenceMatcher(None, target, normalize(name_of(r))).ratio(), difflib.SequenceMatcher(None, target, normalize(str(r.get("email")))).ratio()), reverse=True)
        found = [r for r in ranked if max(difflib.SequenceMatcher(None, target, normalize(name_of(r))).ratio(), difflib.SequenceMatcher(None, target, normalize(str(r.get("email")))).ratio()) >= 0.65][:1]
    if len(found) == 1: callback(found[0])
    else: reseller_matches(chat_id, query)


def match_resellers_with_callback(chat_id: int, query: str, callback: Any) -> None:
    result = panel("list_resellers", chat_id)
    rows = result.get("resellers", [])
    target = normalize(query)
    matches = [r for r in rows if (query.isdigit() and int(r["id"]) == int(query)) or (target and (target in normalize(name_of(r)) or target in normalize(str(r.get("email")))))]
    if len(matches) == 1:
        callback(matches[0])
    elif matches:
        send(chat_id, "Više resellera odgovara. Izaberite jedan:", [[{"text": f"{name_of(r)} · #{r['id']}", "callback_data": f"r:{r['id']}"}] for r in matches[:8]])
    else:
        reseller_matches(chat_id, query)


def choose_reseller_for_adjustment(chat_id: int, query: str, amount: int, reason: str) -> None:
    result = panel("list_resellers", chat_id)
    rows = result.get("resellers", [])
    target = normalize(query)
    ranked = []
    for row in rows:
        terms = [normalize(str(row.get("display_name") or "")), normalize(str(row.get("email") or ""))]
        score = 1.0 if query.isdigit() and int(row["id"]) == int(query) else max((difflib.SequenceMatcher(None, target, term).ratio() for term in terms if term), default=0)
        if target and any(target in term for term in terms): score = max(score, 0.95)
        if score >= 0.45: ranked.append((score, row))
    matches = [r for _, r in sorted(ranked, key=lambda x: x[0], reverse=True)[:8]]
    if len(matches) == 1:
        begin_balance(chat_id, int(matches[0]["id"]), amount, reason)
    elif matches:
        panel("set_conversation", chat_id, chat_id=chat_id, state="await_reseller_action", payload={"amount_rsd": amount, "reason": reason})
        send(chat_id, "Izaberite resellera:", [[{"text": f"{name_of(r)} · #{r['id']}", "callback_data": f"adj:{r['id']}"}] for r in matches])
    else:
        send(chat_id, "Reseller nije pronađen. Koristite /dopuna ime 5000 razlog.")


def finish_action(chat_id: int, result: dict[str, Any], message_id: int = 0) -> None:
    if result.get("requires_totp"):
        panel("set_conversation", chat_id, chat_id=chat_id, state="await_totp", payload={"action_id": result.get("action_id"), "message_id": message_id})
        message = "Kod nije ispravan. Pokušajte ponovo sa svežim šestocifrenim kodom." if result.get("totp_error") else "Iznos zahteva svež admin 2FA kod. Pošaljite šestocifreni kod iz Authenticator aplikacije."
        send(chat_id, message, edit=message_id or None)
        return
    panel("clear_conversation", chat_id, chat_id=chat_id)
    if not result.get("ok"):
        send(chat_id, safe(result.get("error", "Akcija nije uspela.")), edit=message_id or None)
        return
    info = result.get("result") or {}
    if info.get("balance_after_rsd") is not None:
        message = f"Potvrđeno. Novi balans: <b>{format_rsd(info['balance_after_rsd'])}</b> · transakcija #{info.get('transaction_id')}"
    elif info.get("order_id"):
        message = f"Porudžbina #{info['order_id']} je označena kao plaćena."
    elif info.get("product_id"):
        message = "Promena proizvoda je sačuvana."
    else:
        message = "Akcija je potvrđena."
    send(chat_id, message, edit=message_id or None)


def handle_callback(query: dict[str, Any]) -> None:
    callback_id = str(query.get("id", ""))
    message = query.get("message") or {}
    chat_id = int(message.get("chat", {}).get("id", 0))
    message_id = int(message.get("message_id", 0))
    data = str(query.get("data", ""))
    if not is_allowed_username((query.get("from") or {}).get("username")):
        return
    auth = panel("authorized", chat_id, chat_id=chat_id)
    if not auth.get("authorized"):
        answer_callback(callback_id, "Nemaš pristup."); return
    parts = data.split(":")
    if parts[0] == "r" and len(parts) == 2:
        show_reseller(chat_id, int(parts[1]))
    elif parts[0] == "tx" and len(parts) == 2:
        show_transactions(chat_id, int(parts[1]))
    elif parts[0] == "orders" and len(parts) == 2:
        entries = panel("orders", chat_id).get("orders", [])
        for row in entries:
            if int(row.get("reseller_id", 0)) == int(parts[1]):
                send(chat_id, f"<b>#{row['id']} · {safe(row.get('product_name'))}</b>\n{safe(row.get('account_type'))} · {format_rsd(row.get('price_rsd'))} · {safe(row.get('created_at'))}")
    elif parts[0] == "adjust" and len(parts) == 3:
        panel("set_conversation", chat_id, chat_id=chat_id, state="await_amount", payload={"reseller_id": int(parts[1]), "operation": parts[2], "reason": "Dopuna preko Telegrama" if parts[2] == "plus" else "Oduzimanje preko Telegrama", "message_id": message_id})
        send(chat_id, "Unesite iznos (npr. 5000, 5.000 ili 5k). Posle toga dobićete pregled za potvrdu.", edit=message_id)
    elif parts[0] == "select" and len(parts) == 3:
        send(chat_id, "Izbor je istekao. Pokrenite komandu ponovo.")
    elif parts[0] == "adj" and len(parts) == 2:
        conversation = panel("get_conversation", chat_id, chat_id=chat_id).get("conversation")
        payload = (conversation or {}).get("payload") or {}
        if not conversation or conversation.get("state") != "await_reseller_action":
            send(chat_id, "Izbor je istekao. Pokrenite komandu ponovo.")
        else:
            panel("clear_conversation", chat_id, chat_id=chat_id)
            begin_balance(chat_id, int(parts[1]), int(payload["amount_rsd"]), str(payload.get("reason") or "Promena balansa preko Telegrama"), edit_id=message_id)
    elif parts[0] == "ok" and len(parts) == 2:
        result = panel("confirm_action", chat_id, action_id=parts[1])
        finish_action(chat_id, result, message_id)
    elif parts[0] == "no" and len(parts) == 2:
        panel("cancel_action", chat_id, action_id=parts[1])
        send(chat_id, "Akcija je otkazana.", edit=message_id)
    elif parts[0] == "pay" and len(parts) == 2:
        payments = panel("payments", chat_id).get("payments", [])
        notice = next((p for p in payments if int(p["id"]) == int(parts[1])), None)
        if not notice:
            send(chat_id, "Uplata je već obrađena ili ne postoji.", edit=message_id); return
        if notice.get("amount_rsd"):
            begin_balance(chat_id, int(notice["reseller_id"]), int(notice["amount_rsd"]), f"Potvrđena uplata preko Telegrama · obaveštenje #{notice['id']}", "payment_confirm", {"notice_id": int(notice["id"]), "amount_rsd": int(notice["amount_rsd"])}, message_id)
        else:
            panel("set_conversation", chat_id, chat_id=chat_id, state="await_amount", payload={"notice_id": int(notice["id"]), "reseller_id": int(notice["reseller_id"]), "message_id": message_id})
            send(chat_id, f"Uplata #{notice['id']} · {safe(notice.get('display_name') or notice.get('reseller_email'))}. Unesite primljeni iznos u RSD.", edit=message_id)
    elif parts[0] == "other" and len(parts) == 2:
        payments = panel("payments", chat_id).get("payments", [])
        notice = next((p for p in payments if int(p["id"]) == int(parts[1])), None)
        if not notice:
            send(chat_id, "Uplata je već obrađena ili ne postoji.", edit=message_id)
        else:
            panel("set_conversation", chat_id, chat_id=chat_id, state="await_amount", payload={"notice_id": int(notice["id"]), "reseller_id": int(notice["reseller_id"]), "message_id": message_id})
            send(chat_id, f"Unesite stvarni iznos primljen od {safe(notice.get('display_name') or notice.get('reseller_email'))}.", edit=message_id)
    elif parts[0] == "reject" and len(parts) == 2:
        panel("set_conversation", chat_id, chat_id=chat_id, state="await_reject_reason", payload={"notice_id": int(parts[1]), "message_id": message_id})
        send(chat_id, "Unesite razlog odbijanja ili pošaljite — ako ne želite razlog.", edit=message_id)
    elif parts[0] == "paid" and len(parts) == 2:
        begin_order_action(chat_id, "mark_order_paid", {"order_id": int(parts[1])}, "Označi porudžbinu plaćenom", edit_id=message_id)
    elif parts[0] == "solve" and len(parts) == 3:
        result = panel("resolve_report", chat_id, type=parts[1], id=int(parts[2]))
        send(chat_id, "Prijava je označena kao rešena." if result.get("ok") else safe(result.get("error", "Nije uspelo.")), edit=message_id)
    answer_callback(callback_id)


def deliver_outbox() -> None:
    last_heartbeat = 0.0
    while True:
        try:
            # Outbox processing is system-level, but delivery is still restricted to the configured admins.
            result = panel("poll_outbox", 0)
            if result.get("ok"):
                if time.monotonic() - last_heartbeat >= 30:
                    panel("heartbeat", 0, service="outbox")
                    last_heartbeat = time.monotonic()
                for item in result.get("items", []):
                    payload = item.get("payload", {})
                    targets = [int(payload["chat_id"])] if payload.get("chat_id") else result.get("chat_ids", [])
                    success = True
                    for target in targets:
                        identity_result = telegram("getChat", {"chat_id": target})
                        identity = identity_result.get("result", {})
                        if not identity_result.get("ok"):
                            success = False
                            continue
                        if not is_allowed_username(identity.get("username")):
                            continue
                        if item["event_type"] == "test":
                            body = f"Test poruka: Telegram admin bot je povezan sa panelom, {safe(payload.get('admin_name'))}."
                        else:
                            body = format_event(str(item["event_type"]), payload)
                        if body and not telegram("sendMessage", {"chat_id": target, "text": body, "parse_mode": "HTML", "reply_markup": event_keyboard(str(item["event_type"]), payload)}).get("ok"):
                            success = False
                    panel("outbox_ack", 0, id=item["id"], sent=success, error="Telegram nije prihvatio poruku.")
        except Exception as exc:
            logging.warning("Outbox worker iteration failed: %s", type(exc).__name__)
        time.sleep(2)


def format_event(kind: str, p: dict[str, Any]) -> str:
    name = safe(p.get("reseller_name") or p.get("reseller_email") or f"Reseller #{p.get('reseller_id')}")
    if kind == "payment_notice":
        amount = format_rsd(p["amount_rsd"]) if p.get("amount_rsd") else "iznos nije unet"
        return f"<b>Nova uplata: {name} (#{p.get('reseller_id')})</b>\nPrijavljeno: {amount}\nTrenutni balans: {format_rsd(p.get('balance_rsd'))}"
    if kind == "new_order":
        return f"<b>Nova porudžbina · {name}</b>\n{safe(p.get('product_name'))} · {safe(p.get('account_type'))}\nCena: {format_rsd(p.get('price_rsd'))} · novi balans: {format_rsd(p.get('balance_rsd'))}\nPorudžbina #{p.get('order_id')}"
    if kind == "missing_game":
        return f"<b>Igra nije stigla · {name}</b>\n{safe(p.get('product_name'))} · {safe(p.get('account_type'))}\nPorudžbina #{p.get('order_id')} · prijava #{p.get('report_id')}"
    if kind == "game_request":
        return f"<b>Zahtev za igru · {name}</b>\n{safe(p.get('suggestion'))}\nPrijava #{p.get('request_id')}"
    if kind == "low_balance":
        return f"<b>Balans ispod limita · {name}</b>\nTrenutni balans: {format_rsd(p.get('balance_rsd'))} · prag {format_rsd(p.get('threshold_rsd'))}"
    if kind == "inventory_error":
        return f"<b>Inventory API greška · {name}</b>\nRezultat: {safe(p.get('result'))} · HTTP {safe(p.get('http_status'))}\nZahtev #{p.get('request_id')}"
    return ""


def event_keyboard(kind: str, payload: dict[str, Any]) -> dict[str, Any] | None:
    if kind == "payment_notice":
        return {"inline_keyboard": [[{"text": f"Potvrdi {format_rsd(payload['amount_rsd'])}" if payload.get("amount_rsd") else "Unesi iznos", "callback_data": f"pay:{payload['notice_id']}"}, {"text": "Drugi iznos", "callback_data": f"other:{payload['notice_id']}"}, {"text": "Odbij", "callback_data": f"reject:{payload['notice_id']}"}]]}
    if kind == "missing_game":
        return {"inline_keyboard": [[{"text": "Označi rešeno", "callback_data": f"solve:missing_game:{payload['report_id']}"}]]}
    if kind == "game_request":
        return {"inline_keyboard": [[{"text": "Označi rešeno", "callback_data": f"solve:game_request:{payload['request_id']}"}]]}
    return None


class WebhookHandler(BaseHTTPRequestHandler):
    def do_POST(self) -> None:  # noqa: N802
        if self.path != "/telegram" or not hmac.compare_digest(self.headers.get("X-Telegram-Bot-Api-Secret-Token", ""), WEBHOOK_SECRET):
            self.send_error(403)
            return
        update_id = None
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if length < 2 or length > 1_000_000:
                self.send_error(413)
                return
            update = json.loads(self.rfile.read(length).decode("utf-8"))
            update_id = int(update.get("update_id", -1))
            message = update.get("message") or {}
            if is_allowed_start_message(message):
                handle_message(message)
                self.send_response(200); self.end_headers(); self.wfile.write(b"ok"); return
            panel("heartbeat", 0, service="webhook")
            # Telegram's chat id scopes every update; the gateway claims the update id before side effects.
            chat_id = int((update.get("message") or update.get("callback_query", {}).get("message") or {}).get("chat", {}).get("id", 0))
            if chat_id:
                claim = panel("claim_update", chat_id, update_id=update_id)
                if not claim.get("ok") or claim.get("duplicate"):
                    self.send_response(200); self.end_headers(); self.wfile.write(b"ok"); return
                if "message" in update:
                    handle_message(update["message"])
                elif "callback_query" in update:
                    handle_callback(update["callback_query"])
            self.send_response(200)
            self.send_header("Content-Type", "text/plain")
            self.end_headers()
            self.wfile.write(b"ok")
        except Exception as exc:
            logging.exception("Webhook update failed: %s", type(exc).__name__)
            if update_id is not None and update_id >= 0:
                panel("release_update", 0, update_id=update_id)
            self.send_response(500); self.end_headers(); self.wfile.write(b"retry")

    def log_message(self, fmt: str, *args: Any) -> None:
        logging.info("webhook %s", fmt % args)


def main() -> None:
    server = ThreadingHTTPServer(("0.0.0.0", PORT), WebhookHandler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(TLS_CERT, TLS_KEY)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    threading.Thread(target=deliver_outbox, daemon=True, name="telegram-outbox").start()
    logging.info("Telegram webhook listening on 0.0.0.0:%s", PORT)
    server.serve_forever()


if __name__ == "__main__":
    main()
