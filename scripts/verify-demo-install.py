"""Check authenticated reads against the local, synthetic installation prepared by CI."""
import http.cookiejar
import json
import time
import urllib.error
import urllib.parse
import urllib.request


ORIGIN = "http://localhost:5173"
cookies = http.cookiejar.CookieJar()
browser = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))


def request(path, payload=None):
    headers = {"Accept": "text/html" if path == "/" else "application/json",
               "Origin": ORIGIN, "Referer": ORIGIN + "/"}
    data = None
    if payload is not None:
        token = next(cookie.value for cookie in cookies if cookie.name == "XSRF-TOKEN")
        headers.update({"X-XSRF-TOKEN": urllib.parse.unquote(token), "Content-Type": "application/json"})
        data = json.dumps(payload).encode()
    with browser.open(urllib.request.Request(ORIGIN + path, data, headers), timeout=15) as response:
        return response.read()


def api(path):
    return json.loads(request("/api/v1/" + path))["data"]


for attempt in range(60):
    try:
        if b"Revenia" in request("/"):
            break
    except (OSError, urllib.error.URLError):
        pass
    time.sleep(1)
else:
    raise RuntimeError("El frontend de la instalación nueva no respondió.")

request("/sanctum/csrf-cookie")
request("/login", {"email": "owner@alfa.example.test", "password": "FacturlyLocal2026!"})
for route in ["dashboard", "documents", "receivables", "receivables/agenda?scope=team",
              "bank-receipts", "incidents", "subscription"]:
    api(route)
    print("GET", route, "OK")
customer = api("customers")[0]["id"]
statement = api("customers/" + customer + "/statement?currency=PEN")
assert statement["customer"]["id"] == customer
assert api("integrations/odoo")["available"] is False
print("Estado de cuenta del cliente, sesión y Odoo desactivado: OK")
