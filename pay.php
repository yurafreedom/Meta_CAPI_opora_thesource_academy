<?php
// pay.php

function envv($k, $default = '') {
  $v = getenv($k);
  return ($v !== false && $v !== '') ? $v : $default;
}

function _upstash_exec(string $fullUrl, string $token, string $payload) {
  $attempt = 0;
  $maxAttempts = 2;

  while (++$attempt <= $maxAttempts) {
    $ch = curl_init($fullUrl);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
      ],
      CURLOPT_POSTFIELDS => $payload,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 2, // H3 FIX
      CURLOPT_TIMEOUT => 5,       // H3 FIX
    ]);
    $res   = curl_exec($ch);
    $err   = curl_error($ch);
    $errno = curl_errno($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno === 28 && $attempt < $maxAttempts) {
      usleep(200000);
      continue;
    }
    break;
  }

  if ($res === false) throw new Exception("Upstash curl error: $err");
  if ($code < 200 || $code >= 300) throw new Exception("Upstash HTTP $code: $res");

  return $res;
}

function upstash_cmd(array $cmd) {
  $url = envv('UPSTASH_REDIS_REST_URL');
  $token = envv('UPSTASH_REDIS_REST_TOKEN');
  if (!$url || !$token) { throw new Exception("Upstash env missing"); }

  $res = _upstash_exec(
    rtrim($url, '/') . '/pipeline',
    $token,
    json_encode([$cmd], JSON_UNESCAPED_UNICODE)
  );

  $j = json_decode($res, true);
  return $j[0]['result'] ?? null;
}

function upstash_pipeline(array $cmds) {
  $url = envv('UPSTASH_REDIS_REST_URL');
  $token = envv('UPSTASH_REDIS_REST_TOKEN');
  if (!$url || !$token) { throw new Exception("Upstash env missing"); }

  $res = _upstash_exec(
    rtrim($url, '/') . '/pipeline',
    $token,
    json_encode($cmds, JSON_UNESCAPED_UNICODE)
  );

  $decoded = json_decode($res, true);
  if (!is_array($decoded)) throw new Exception("Upstash bad JSON: $res");
  return $decoded;
}

$merchantAccount   = envv('WFP_MERCHANT', 'www_thesource_academy');
$merchantSecretKey = envv('WFP_SECRET');
if (!$merchantSecretKey) { http_response_code(500); exit('WFP secret missing'); }

function fmt_money($x) { return number_format((float)$x, 2, '.', ''); }

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
// жёсткая защита от плейсхолдеров и мусора
if ($token === '' || strlen($token) > 120 || strpos($token, '{') !== false || strpos($token, '}') !== false) {
  http_response_code(400);
  exit('Token missing/bad');
}

$amount_raw = envv('PRICE_UAH', '290');
$amount     = (float)$amount_raw;

if ($amount < 1 || $amount > 100000) {
  http_response_code(400);
  exit('Bad amount');
}

$amount_str = fmt_money($amount); // "290.00"
$currency    = envv('CURRENCY', 'UAH');

// $amount_raw = envv('PRICE_UAH', '1');               // тест = 1 грн через ENV
// $amount     = (float)$amount_raw;


$order_id   = 'ord_' . bin2hex(random_bytes(8));
$orderDate  = time();

$siteDomain = envv('SITE_DOMAIN', 'opora.thesource.academy');
$siteDomain = preg_replace('~^https?://~', '', $siteDomain);
$siteDomain = rtrim($siteDomain, '/');

$returnBase = rtrim(envv('RETURN_URL', 'https://metacapioporathesourceacademy-production.up.railway.app/wfp-return.php'), '/');

$webhookUrl = envv('WEBHOOK_URL', 'https://metacapioporathesourceacademy-production.up.railway.app/wfp-webhook.php');

$productName  = ['Оплата «Я-Моя Опора»'];
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
upstash_pipeline([
  ["SET", $k, json_encode($payload, JSON_UNESCAPED_UNICODE)],
  ["EXPIRE", $k, 60 * 60 * 24 * 90],
]);

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

  'returnUrl'           => $returnBase . '?orderReference=' . urlencode($order_id),
  'serviceUrl'          => $webhookUrl,
];

// signature
$signature_string = implode(';', [
  $data['merchantAccount'],
  $data['merchantDomainName'],
  $data['orderReference'],
  $data['orderDate'],
  $data['amount'],          // "1.00"
  $data['currency'],
  implode(';', $productName),
  implode(';', $productCount),
  implode(';', $productPrice), // "1.00"
]);

// C3/C4 FIX: removed sign.txt/debug_amount.txt file writes and ?dbg block
// Signature info is logged to stderr only for debugging
$data['merchantSignature'] = hash_hmac('md5', $signature_string, $merchantSecretKey);

error_log("pay.php order={$order_id} token={$token} amount={$amount_str} currency={$currency}");

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