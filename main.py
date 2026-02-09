import os, time, secrets, hmac, hashlib
from fastapi import FastAPI, Query
from fastapi.responses import HTMLResponse

app = FastAPI()

MERCHANT_ACCOUNT = os.getenv("WFP_MERCHANT_ACCOUNT", "www_thesource_academy")
MERCHANT_SECRET  = os.getenv("WFP_MERCHANT_SECRET",  "")
MERCHANT_DOMAIN  = os.getenv("WFP_MERCHANT_DOMAIN", "opora.thesource.academy")

WFP_PAY_URL = "https://secure.wayforpay.com/pay"
SERVICE_URL = os.getenv("WFP_SERVICE_URL", "https://opora.thesource.academy/wfp-webhook.php")
RETURN_URL_BASE = os.getenv("WFP_RETURN_URL_BASE", "https://opora.thesource.academy/thanks")

def wfp_signature(data: dict, productName, productCount, productPrice) -> str:
    s = ";".join([
        data["merchantAccount"],
        data["merchantDomainName"],
        data["orderReference"],
        str(data["orderDate"]),
        f'{data["amount"]:.2f}'.rstrip('0').rstrip('.'),  # аккуратно
        data["currency"],
        ";".join(productName),
        ";".join(map(str, productCount)),
        ";".join(map(lambda x: f"{x:.2f}".rstrip('0').rstrip('.'), productPrice)),
    ])
    return hmac.new(MERCHANT_SECRET.encode(), s.encode(), hashlib.md5).hexdigest()

from html import escape

def build_autopost_form(fields: dict) -> str:
    inputs = []
    for k, v in fields.items():
        if isinstance(v, list):
            for item in v:
                inputs.append(
                    f'<input type="hidden" name="{escape(k, quote=True)}[]" value="{escape(str(item), quote=True)}">'
                )
        else:
            inputs.append(
                f'<input type="hidden" name="{escape(k, quote=True)}" value="{escape(str(v), quote=True)}">'
            )

    return f"""<!doctype html>
<html><head><meta charset="utf-8"><title>Redirecting…</title></head>
<body>
<form id="wfp" method="POST" action="{WFP_PAY_URL}">
{''.join(inputs)}
</form>
<script>document.getElementById('wfp').submit();</script>
</body></html>
""".strip()

@app.get("/pay", response_class=HTMLResponse)
def pay(token: str = Query(...), amount: float = Query(1.0)):
    if not MERCHANT_SECRET:
        return HTMLResponse("WFP secret missing in env", status_code=500)

    order_id = "ord_" + secrets.token_hex(8)

    productName  = ["Opora course"]
    productCount = [1]
    productPrice = [float(amount)]

    data = {
        "merchantAccount": MERCHANT_ACCOUNT,
        "merchantDomainName": MERCHANT_DOMAIN,
        "orderReference": order_id,
        "orderDate": int(time.time()),
        "amount": float(amount),
        "currency": "UAH",
        "productName": productName,
        "productCount": productCount,
        "productPrice": productPrice,
        "returnUrl": f"{RETURN_URL_BASE}?order_id={order_id}&token={token}",
        "serviceUrl": SERVICE_URL,
        # полезно пробрасывать токен как "merchantTransactionSecureType" нельзя,
        # но можно в returnUrl/serviceUrl параметрах + хранить mapping на бэке.
    }

    data["merchantSignature"] = wfp_signature(data, productName, productCount, productPrice)

    # ВАЖНО: тут пока мы НЕ сохраняем mapping token<->order_id.
    # Это добавим след шагом, чтобы не было дублей.
    return build_autopost_form(data)

# если хочешь прям /pay.php как URL:
@app.get("/pay.php", response_class=HTMLResponse)
def pay_php(token: str = Query(...), amount: float = Query(1.0)):
    return pay(token=token, amount=amount)