<?php
// PHP-send-mail/api/contact.php
declare(strict_types=1);

/* =====================[ CORS ]===================== */
$ALLOW_ORIGINS = [
    'http://localhost:8000',
    'http://127.0.0.1:8000',
    'http://192.168.1.105:8000',
];

// السماح تلقائيًا لعناوين LAN المشابهة 192.168.X.X:port أثناء التطوير:
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && preg_match('#^https?://(localhost|127\.0\.0\.1|192\.168\.\d+\.\d+)(:\d+)?$#', $origin)) {
    $ALLOW_ORIGINS[] = $origin;
}

if ($origin && in_array($origin, array_unique($ALLOW_ORIGINS), true)) {
    header('Access-Control-Allow-Origin: '.$origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept, X-Requested-With');
    header('Access-Control-Max-Age: 600');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

/* ===============[ إعداد الاستجابة ]================ */
header('Content-Type: application/json; charset=UTF-8');
$reqId = bin2hex(substr(hash('sha256', microtime(true).mt_rand(), true), 0, 8));
header('X-Request-Id: '.$reqId);

/* ===========[ حماية بسيطة لحجم الطلب ]============ */
// (إن وُجد العنوان) ارفض أحجامًا كبيرة بشكل غير منطقي
$cl = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($cl > 64 * 1024) { // 64KB
    http_response_code(413);
    echo json_encode(['ok'=>false,'status'=>413,'message'=>'Payload too large','request_id'=>$reqId], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =================[ POST فقط ]================= */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'status'=>405,'message'=>'Method Not Allowed','request_id'=>$reqId], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===========[ قراءة/فك JSON القادم ]============ */
$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'status'=>400,'message'=>'Invalid JSON','request_id'=>$reqId], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===============[ تنظيف المدخلات ]=============== */
$trim = fn($v) => is_string($v) ? trim($v) : '';
$name         = $trim($in['name']  ?? '');
$phone        = preg_replace('/\D+/', '', (string)($in['phone'] ?? ''));
$governorate  = $trim($in['governorate'] ?? '');
$address      = $trim($in['address'] ?? '');
$title        = $trim($in['product_title'] ?? '');
$color        = $trim($in['product_color'] ?? '');
$price        = $trim($in['product_price'] ?? '');
$features     = $trim($in['product_features'] ?? '');
$email        = $trim($in['email'] ?? ''); // اختياري من الواجهة
$isDelivery   = ($governorate !== '' || $address !== '');

/* ===============[ فاليديشن خفيف ]================ */
// (نترك الفحص الأعمق لـ send.php، لكن نفلتر الأخطاء الواضحة لتقليل المحاولات الفاشلة)
$errors = [];
if (mb_strlen($name) < 3)         { $errors['name'] = 'الاسم قصير.'; }
if (!preg_match('/^\d{9}$/', $phone)) { $errors['phone'] = 'الهاتف يجب أن يكون 9 أرقام.'; }
if ($isDelivery) {
    if ($governorate === '') { $errors['governorate'] = 'المحافظة مطلوبة للتوصيل.'; }
    if (mb_strlen($address) < 8) { $errors['address'] = 'اكتب عنوانًا تفصيليًا.'; }
}
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'بريد غير صالح.';
}
if ($errors) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'status'=>422,'message'=>'Validation failed','errors'=>$errors,'request_id'=>$reqId], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =============[ نص الرسالة الموحّد ]============= */
$parts = [
    'الاسم: ' . ($name ?: '—'),
    'الهاتف: ' . ($phone ?: '—'),
    'طريقة الاستلام: ' . ($isDelivery ? 'التوصيل للمنزل' : 'من المركز الرئيسي'),
];
if ($isDelivery) {
    $parts[] = 'المحافظة: ' . ($governorate ?: '—');
    $parts[] = 'العنوان: '   . ($address     ?: '—');
}
$parts[] = '';
$parts[] = 'تفاصيل الطلب';
$parts[] = 'المنتج: ' . ($title ?: '—');
$parts[] = 'اللون: '  . ($color ?: '—');
$parts[] = 'السعر: '  . ($price ?: '—');
if ($features !== '') {
    $parts[] = 'المزايا: ' . $features;
}
$message = implode("\n", $parts);

/* =================[ CSRF Token ]================= */
// استخدم القادم من الواجهة إذا كان 32 هيكس، وإلا ولّد بمرونة
if (isset($in['csrf']) && is_string($in['csrf']) && preg_match('/^[a-f0-9]{32}$/i', $in['csrf'])) {
    $csrf = $in['csrf'];
} else {
    try {
        $bytes = random_bytes(16);
    } catch (\Throwable $e) {
        $bytes = function_exists('openssl_random_pseudo_bytes')
            ? openssl_random_pseudo_bytes(16)
            : substr(hash('sha256', uniqid('', true).microtime(true).mt_rand(), true), 0, 16);
    }
    $csrf = bin2hex($bytes);
}

/* =========[ تجهيز POST لـ send.php ]========== */
// لاحظ أن send.php يشترط email صالح، لذلك نضبط افتراضي صالح الشكل:
$senderEmail = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'no-reply@example.com';

$post = [
    'name'            => $name,
    'email'           => $senderEmail,
    'message'         => $message,
    'website'         => '',          // honeypot فارغ
    'csrf'            => $csrf,

    'phone'           => $phone,
    'governorate'     => $governorate,
    'address'         => $address,
    'product_title'   => $title,
    'product_color'   => $color,
    'product_price'   => $price,
    'product_features'=> $features,
];

/* ===========[ إرسال داخلي إلى send.php ]=========== */
$endpoint = 'http://localhost/PHP-send-mail/send.php';

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST            => true,
    CURLOPT_POSTFIELDS      => http_build_query($post, '', '&', PHP_QUERY_RFC3986),
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_HTTPHEADER      => ['Accept: application/json'],
    CURLOPT_CONNECTTIMEOUT  => 5,
    CURLOPT_TIMEOUT         => 15,
]);
$body = curl_exec($ch);
$curlErr = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 500;
curl_close($ch);

if ($curlErr) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'status'=>500,'message'=>'Gateway error: '.$curlErr,'request_id'=>$reqId], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===============[ تمرير استجابة send.php ]=============== */
$decoded = json_decode((string)$body, true);
if (is_array($decoded)) {
    $decoded['ok'] = ($code >= 200 && $code < 300);
    $decoded['status'] = $code;
    $decoded['request_id'] = $reqId;
    http_response_code($code);
    echo json_encode($decoded, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code($code);
echo json_encode([
    'ok'         => ($code >= 200 && $code < 300),
    'status'     => $code,
    'message'    => ($body !== '' ? $body : 'No content'),
    'request_id' => $reqId,
], JSON_UNESCAPED_UNICODE);
