<?php
// pay.php

function envv($k, $default = '') {
  $v = getenv($k);
  return ($v !== false && $v !== '') ? $v : $default;
}

function upstash_cmd(array $cmd) {
  $url = envv('UPSTASH_REDIS_REST_URL');
  $token = envv('UPSTASH_REDIS_REST_TOKEN');
  if (!$url || !$token) { throw new Exception("Upstash env missing"); }

  $ch = curl_init(rtrim($url, '/') . '/pipeline');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $token,
      'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode([$cmd], JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
  ]);
  $res = curl_exec($ch);
  $err = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($res === false) throw new Exception("Upstash curl error: $err");
  if ($code < 200 || $code >= 300) throw new Exception("Upstash HTTP $code: $res");

  $j = json_decode($res, true);
  return $j[0]['result'] ?? null;
}

$merchantAccount   = envv('WFP_MERCHANT', 'www_thesource_academy');
$merchantSecretKey = envv('WFP_SECRET');

function fmt_money($x) { return number_format((float)$x, 2, '.', ''); }

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
if ($token === '' || strlen($token) > 80) { http_response_code(400); exit('Token missing/bad'); }

$amount      = (float) envv('PRICE_UAH', '290');
if ($amount < 1 || $amount > 100000) { http_response_code(400); exit('Bad amount'); }

$amount_str  = fmt_money($amount);
$currency    = envv('CURRENCY', 'UAH');

$order_id   = 'ord_' . bin2hex(random_bytes(8));
$orderDate  = time();

$siteDomain = envv('SITE_DOMAIN', 'opora.thesource.academy');
$returnBase = rtrim(envv('RETURN_URL', 'https://opora.thesource.academy/thanks'), '/');
$webhookUrl = envv('WEBHOOK_URL', 'https://metacapioporathesourceacademy-production.up.railway.app/wfp-webhook.php');

$productName  = ['Opora course'];
$productCount = [1];
$productPrice = [$amount_str];

// 1) mapping order_id -> token
$payload = [
  'order_id'    => $order_id,
  'token'       => $token,
  'amount'      => $amount_str,
  'currency'    => $currency,
  'created_at'  => $orderDate,
];

$k = "wfp:order:$order_id";
upstash_cmd(["SET", $k, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
upstash_cmd(["EXPIRE", $k, 60 * 60 * 24 * 30]);

// 2) WFP fields
$data = [
  'merchantAccount'     => $merchantAccount,
  'merchantDomainName'  => $siteDomain,
  'orderReference'      => $order_id,
  'orderDate'           => $orderDate,
  'amount'              => $amount_str,
  'currency'            => $currency,
  'productName'         => $productName,
  'productCount'        => $productCount,
  'productPrice'        => $productPrice,
  'returnUrl'           => $returnBase . '?order_id=' . urlencode($order_id) . '&token=' . urlencode($token),
  'serviceUrl'          => $webhookUrl,
];

// signature
$signature_string = implode(';', [
  $data['merchantAccount'],
  $data['merchantDomainName'],
  $data['orderReference'],
  $data['orderDate'],
  $data['amount'],
  $data['currency'],
  implode(';', $productName),
  implode(';', $productCount),
  implode(';', $productPrice),
]);

$data['merchantSignature'] = hash_hmac('md5', $signature_string, $merchantSecretKey);

// 3) отправляем на WFP
$wfpDomain = 'https://secure.wayforpay.com/pay';
?>
<!doctype html><html><head><meta charset="utf-8"><title>Redirecting…</title></head>
<body>
<form id="wfp" method="POST" action="<?= htmlspecialchars($wfpDomain) ?>">
<?php foreach ($data as $key => $value): ?>
  <?php if (is_array($value)): ?>
    <?php foreach ($value as $v): ?>
      <input type="hidden" name="<?= htmlspecialchars($key) ?>[]" value="<?= htmlspecialchars((string)$v) ?>">
    <?php endforeach; ?>
  <?php else: ?>
    <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars((string)$value) ?>">
  <?php endif; ?>
<?php endforeach; ?>
</form>
<script>document.getElementById('wfp').submit();</script>
</body></html>