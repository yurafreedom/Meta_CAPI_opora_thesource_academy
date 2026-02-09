<?php
$merchantAccount = 'www_thesource_academy';
$merchantSecretKey = '57bc4ca0c366c9b29856c33355987fd1dec6a7db';

$order_id = 'ord_' . bin2hex(random_bytes(8));
$amount   = (float)($_GET['amount'] ?? 1);
$currency = 'UAH';

$productName  = ['Opora course'];
$productCount = [1];
$productPrice = [$amount];

$domain = 'https://secure.wayforpay.com/pay';

$data = [
  'merchantAccount' => $merchantAccount,
  'merchantDomainName' => 'opora.thesource.academy',
  'orderReference' => $order_id,
  'orderDate' => time(),
  'amount' => $amount,
  'currency' => $currency,
  'productName' => $productName,
  'productCount' => $productCount,
  'productPrice' => $productPrice,
  'returnUrl' => 'https://opora.thesource.academy/thanks?order_id=' . $order_id,
  'serviceUrl' => 'https://opora.thesource.academy/wfp-webhook.php'
];

$signature_string = implode(';', [
  $data['merchantAccount'],
  $data['merchantDomainName'],
  $data['orderReference'],
  $data['orderDate'],
  $data['amount'],
  $data['currency'],
  implode(';', $productName),
  implode(';', $productCount),
  implode(';', $productPrice)
]);

$data['merchantSignature'] = hash_hmac('md5', $signature_string, $merchantSecretKey);
?>

<form id="wfp" method="POST" action="<?= $domain ?>">
<?php foreach ($data as $key => $value): ?>
  <?php if (is_array($value)): ?>
    <?php foreach ($value as $v): ?>
      <input type="hidden" name="<?= $key ?>[]" value="<?= htmlspecialchars($v) ?>">
    <?php endforeach; ?>
  <?php else: ?>
    <input type="hidden" name="<?= $key ?>" value="<?= htmlspecialchars($value) ?>">
  <?php endif; ?>
<?php endforeach; ?>
</form>

<script>document.getElementById('wfp').submit();</script>