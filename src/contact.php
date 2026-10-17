<?php

header('Content-Type: application/json');

// settings (recaptcha secret, my email address etc) live outside public_html
$config = require __DIR__ . '/../private/config.php';

// sends a JSON reply and stops the script
function reply($status, $ok, $error = '') {
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'error' => $error]);
    exit;
}

// checks the recaptcha token with google
function recaptchaOk($token, $config) {
    if ($token === '') {
        return false;
    }

    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'secret' => $config['recaptcha_secret'],
            'response' => $token,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    if ($result === false) {
        return false;
    }

    $data = json_decode($result, true);
    return !empty($data['success']) && ($data['hostname'] ?? '') === $config['hostname'];
}

// simple rate limit: max 5 messages per hour from the same ip
// only a hash of the ip is stored, not the ip itself
function tooManyRequests($ip, $config) {
    $file = __DIR__ . '/../private/rate-limit.json';
    $key = hash('sha256', $config['salt'] . $ip);
    $now = time();
    $window = 3600;
    $limit = 5;

    $fp = fopen($file, 'c+');
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
$token = $_POST['g-recaptcha-response'] ?? '';

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

if (!recaptchaOk($token, $config)) {
    reply(400, false, 'Please complete the captcha.');
}

// build the email. plain text only, and no line breaks allowed in the name
// because it goes in the subject line
$safeName = str_replace(["\r", "\n"], ' ', $name);
$subject = mb_encode_mimeheader('Portfolio message from ' . $safeName, 'UTF-8');
$body = "Name: $name\nEmail: $email\n\n$message";

// sent from my own domain, and replying goes to the visitor
$headers = [
    'From' => $config['from'],
    'Reply-To' => $email,
    'Content-Type' => 'text/plain; charset=UTF-8',
];

if (mail($config['to'], $subject, $body, $headers)) {
    reply(200, true);
}

error_log('contact form: mail() failed');
reply(500, false, 'Sorry, the message could not be sent. Please try again later.');

?>