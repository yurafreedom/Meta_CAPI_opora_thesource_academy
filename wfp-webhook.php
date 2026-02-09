<?php
header('Content-Type: application/json');

$secret = '57bc4ca0c366c9b29856c33355987fd1dec6a7db';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
  http_response_code(400);
  exit('bad json');
}

/**
 * 1. Проверяем подпись WFP
 */
$sign_str = implode(';', [
  $data['merchantAccount'],
  $data['orderReference'],
  $data['amount'],
  $data['currency'],
  $data['authCode'],
  $data['cardPan'],
  $data['transactionStatus'],
  $data['reasonCode']
]);

$calc_sign = hash_hmac('md5', $sign_str, $secret);

if ($calc_sign !== $data['merchantSignature']) {
  http_response_code(403);
  exit('bad signature');
}

/**
 * 2. Проверяем успешную оплату
 */
if ($data['transactionStatus'] !== 'Approved') {
  echo json_encode(['ok' => true, 'status' => 'not approved']);
  exit;
}

$order_id = $data['orderReference'];

/**
 * 3. Дедуп в Redis
 */
function redis_set($key, $value) {
  $url   = getenv('UPSTASH_REDIS_REST_URL');
  $token = getenv('UPSTASH_REDIS_REST_TOKEN');

  $ch = curl_init("$url/set/$key/$value");
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
  curl_exec($ch);
  curl_close($ch);
}

redis_set("purchase:$order_id", "1");

/**
 * 4. Meta CAPI Purchase
 */
$pixel  = getenv('META_PIXEL_ID');
$token  = getenv('META_CAPI_TOKEN');

$event = [
  'event_name' => 'Purchase',
  'event_time' => time(),
  'event_id'   => $order_id,
  'action_source' => 'website',
  'user_data' => [],
  'custom_data' => [
    'currency' => $data['currency'],
    'value'    => $data['amount']
  ]
];

$body = json_encode(['data' => [$event]]);

$ch = curl_init("https://graph.facebook.com/v21.0/$pixel/events?access_token=$token");
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS => $body
]);
curl_exec($ch);
curl_close($ch);

/**
 * 5. Ответ WFP
 */
echo json_encode([
  "orderReference" => $order_id,
  "status" => "accept",
  "time" => time()
]);