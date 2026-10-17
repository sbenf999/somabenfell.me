<?php
// contact.php - receives the contact form and emails it to me

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

header('Content-Type: application/json');

// sends a JSON reply and stops the script
function reply($status, $ok, $error = '') {
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'error' => $error]);
    exit;
}

// if anything crashes, write the reason to the error log and send back
// clean JSON instead of a blank 500 page
set_exception_handler(function ($e) {
    error_log('contact form: ' . $e->getMessage());
    reply(500, false, 'Sorry, something went wrong on my end.');
});

// settings (smtp login, my email address etc) live outside public_html
$configFile = __DIR__ . '/../private/config.php';
if (!is_file($configFile)) {
    error_log('contact form: config file not found at ' . $configFile);
    reply(500, false, 'Sorry, something went wrong on my end.');
}
$config = require $configFile;

// simple rate limit: max 5 messages per hour from the same ip
// only a hash of the ip is stored, not the ip itself
function tooManyRequests($ip, $config) {
    $file = __DIR__ . '/../private/rate-limit.json';
    $key = hash('sha256', $config['salt'] . $ip);
    $now = time();
    $window = 3600;
    $limit = 5;

    $fp = fopen($file, 'c+');
    if ($fp === false) {
        throw new Exception('cannot open ' . $file . ' (does the folder exist and is it writable?)');
    }
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp), true) ?: [];

    // forget anything older than the window
    foreach ($data as $k => $times) {
        $data[$k] = array_values(array_filter($times, function ($t) use ($now, $window) {
            return $t > $now - $window;
        }));
        if (!$data[$k]) {
            unset($data[$k]);
        }
    }

    $blocked = count($data[$key] ?? []) >= $limit;
    if (!$blocked) {
        $data[$key][] = $now;
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    flock($fp, LOCK_UN);
    fclose($fp);

    return $blocked;
}

// only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(405, false, 'Method not allowed.');
}

// get the fields (?? '' stops "undefined index" warnings)
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$message = trim($_POST['message'] ?? '');
$honeypot = $_POST['website'] ?? '';
$started = (int) ($_POST['started'] ?? 0);

// honeypot filled in = bot. pretend it worked so it doesn't try again
if ($honeypot !== '') {
    reply(200, true);
}

// form sent less than 3 seconds after the page loaded = bot
if ($started <= 0 || time() - intdiv($started, 1000) < 3) {
    reply(400, false, 'That was a bit quick, please try again.');
}

// validation
if ($name === '' || $email === '' || $message === '') {
    reply(400, false, 'Please fill in every field.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    reply(400, false, 'That email address looks invalid.');
}

if (mb_strlen($name) > 100 || mb_strlen($email) > 254 || mb_strlen($message) > 5000) {
    reply(400, false, 'One of the fields is too long.');
}

if (tooManyRequests($_SERVER['REMOTE_ADDR'] ?? '', $config)) {
    reply(429, false, 'Too many messages, please try again later.');
}

// mail() is switched off on my host, so this sends through a real mailbox
// over SMTP using PHPMailer (files are in private/phpmailer)
$mailerDir = __DIR__ . '/../private/phpmailer/';
if (!is_file($mailerDir . 'PHPMailer.php')) {
    error_log('contact form: PHPMailer files not found in ' . $mailerDir);
    reply(500, false, 'Sorry, something went wrong on my end.');
}
require_once $mailerDir . 'Exception.php';
require_once $mailerDir . 'PHPMailer.php';
require_once $mailerDir . 'SMTP.php';

// no line breaks allowed in the name because it goes in the subject line
$safeName = str_replace(["\r", "\n"], ' ', $name);
$body = "Name: $name\nEmail: $email\n\n$message";

$mail = new PHPMailer(true); // true = throw exceptions when something fails

try {
    $mail->isSMTP();
    $mail->Host = $config['smtp_host'];
    $mail->SMTPAuth = true;
    $mail->Username = $config['smtp_user'];
    $mail->Password = $config['smtp_pass'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;
    $mail->CharSet = 'UTF-8';

    // sent from my own mailbox, and replying goes to the visitor
    $mail->setFrom($config['from'], 'Website contact form');
    $mail->addAddress($config['to']);
    $mail->addReplyTo($email, $safeName);

    $mail->isHTML(false); // plain text only
    $mail->Subject = 'Portfolio message from ' . $safeName;
    $mail->Body = $body;

    $mail->send();
    reply(200, true);
} catch (MailException $e) {
    error_log('contact form: ' . $mail->ErrorInfo);
    reply(500, false, 'Sorry, the message could not be sent. Please try again later.');
}