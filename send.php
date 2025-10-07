<?php
// send.php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

// ===== إعدادات بسيطة قابلة للتعديل =====
$MAIL_HOST     = 'smtp.gmail.com';
$MAIL_PORT     = 587;
$MAIL_USERNAME = 'your@gmail.com';        // بريد الجيميل المُرسل
$MAIL_PASSWORD = 'your-app-password';     // Gmail App Password (وليس كلمة المرور العادية)
$MAIL_FROM     = 'your@gmail.com';
$MAIL_FROMNAME = 'Website Form';

$recipients = [
    // استخدم BCC لحماية الخصوصية (لن يرى المستلمون بعضهم)
    'hakimahmed123321@gmail.com',
    'example1@gmail.com',
    'example2@hotmail.com',
    'example3@yahoo.com',
];

// صفحة الشكر عند النجاح (للوضع غير AJAX)
$THANK_YOU_PAGE = 'thanks.html';

// ===== أدوات =====
function wantsJson(): bool {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return stripos($accept, 'application/json') !== false;
}

function jsonResponse(int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status' => $status, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function abort(int $code, string $msg) {
    if (wantsJson()) {
        jsonResponse($code, $msg);
    } else {
        http_response_code($code);
        echo htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        exit;
    }
}

// ===== السماح فقط بـ POST =====
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    abort(405, 'Method Not Allowed');
}

// ===== HoneyPot بسيط ضد البوتات =====
$honeypot = trim($_POST['website'] ?? '');
if ($honeypot !== '') {
    // تجاهل بصمت أو أعطِ نجاحاً مزيّفاً
    if (wantsJson()) jsonResponse(200, 'OK');
    header("Location: {$THANK_YOU_PAGE}");
    exit;
}

// ===== CSRF بسيط (اختياري) =====
// ملاحظة: بما أننا أنشأنا توكن عشوائي في الفورم مباشرة، يمكن أيضاً تخزينه في جلسة للمطابقة.
// هنا نكتفي بالتحقق من وجوده وشكله:
$csrf = $_POST['csrf'] ?? '';
if (!is_string($csrf) || !preg_match('/^[a-f0-9]{32}$/i', $csrf)) {
    abort(400, 'Invalid token.');
}

// ===== جلب/تنظيف المدخلات =====
$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($name === '' || $email === '' || $message === '') {
    abort(422, 'يرجى تعبئة جميع الحقول.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    abort(422, 'بريد إلكتروني غير صالح.');
}

// دالة مساعدة للتأمين في HTML
$e = fn(string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// ===== (اختياري) حدّة بسيطة للمعدل Rate-limit لكل IP خلال دقائق =====
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$tmpFile = sys_get_temp_dir() . '/formrate_' . md5($ip);
$now = time();
$window = 60;  // نافذة 60 ثانية
$maxReq = 5;   // 5 رسائل لكل دقيقة كحد أقصى البسيط
$hits = [];
if (is_file($tmpFile)) {
    $hits = array_filter(array_map('intval', explode(',', (string)file_get_contents($tmpFile))), fn($t) => $t > $now - $window);
}
$hits[] = $now;
file_put_contents($tmpFile, implode(',', $hits));
if (count($hits) > $maxReq) {
    abort(429, 'لقد أرسلت طلبات كثيرة. يرجى المحاولة لاحقاً.');
}

// ===== إرسال البريد =====
$mail = new PHPMailer(true);

try {
    // إعداد SMTP
    $mail->isSMTP();
    $mail->Host       = $MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = $MAIL_USERNAME;
    $mail->Password   = $MAIL_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $MAIL_PORT;

    // المرسل
    $mail->setFrom($MAIL_FROM, $MAIL_FROMNAME);

    // استخدم BCC لحماية الخصوصية
    foreach ($recipients as $r) {
        $r = trim($r);
        if ($r !== '' && filter_var($r, FILTER_VALIDATE_EMAIL)) {
            $mail->addBCC($r);
        }
    }

    // للردّ: إلى الشخص الذي أرسل النموذج
    $mail->addReplyTo($email, $name);

    // المحتوى
    $mail->isHTML(true);
    $mail->Subject = 'New Contact Message from ' . $name;

    $mail->Body = '
        <div style="font-family:Arial,Helvetica,sans-serif; line-height:1.6; color:#222;">
            <h2 style="margin:0 0 10px;">New Contact Message</h2>
            <hr style="border:none; border-top:1px solid #eee; margin:10px 0;">
            <p><strong>Name:</strong> ' . $e($name) . '</p>
            <p><strong>Email:</strong> ' . $e($email) . '</p>
            <p><strong>Message:</strong><br>' . nl2br($e($message)) . '</p>
            <hr style="border:none; border-top:1px solid #eee; margin:10px 0;">
            <p style="font-size:12px;color:#666;">This email was sent from your website contact form.</p>
        </div>
    ';

    $mail->AltBody =
        "New Contact Message\n" .
        "-------------------\n" .
        "Name: {$name}\n" .
        "Email: {$email}\n\n" .
        "Message:\n{$message}\n";

    $mail->send();

    if (wantsJson()) {
        jsonResponse(200, '✅ تم إرسال رسالتك بنجاح. سنعاود التواصل قريباً.');
    } else {
        // تحويل إلى صفحة شكر نظيفة
        header("Location: {$THANK_YOU_PAGE}");
        exit;
    }

} catch (Exception $ex) {
    $err = $mail->ErrorInfo ?: $ex->getMessage();
    abort(500, '❌ تعذر إرسال البريد. السبب: ' . $err);
}
