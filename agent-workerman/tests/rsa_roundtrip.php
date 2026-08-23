<?php
// 本地 RSA-OAEP 往返测试：确认 openssl_public_encrypt/decrypt 自洽
$config = [
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
];
$res = openssl_pkey_new($config);
if ($res === false) {
    echo "pkey_new FAIL\n";
    exit(1);
}
$details = openssl_pkey_get_details($res);
$pub = $details['key'];
$priv = '';
openssl_pkey_export($res, $priv);

$secret = "root\x00"; // 4字节口令 + NUL
$enc = '';
$ok1 = openssl_public_encrypt($secret, $enc, $pub, OPENSSL_PKCS1_OAEP_PADDING);
$dec = '';
$ok2 = openssl_private_decrypt($enc, $dec, $priv, OPENSSL_PKCS1_OAEP_PADDING);
echo 'encrypt=' . var_export($ok1, true) . ' (' . strlen($enc) . 'B) decrypt=' . var_export($ok2, true)
    . ' match=' . var_export($dec === $secret, true) . ' dec_hex=' . bin2hex($dec) . PHP_EOL;
