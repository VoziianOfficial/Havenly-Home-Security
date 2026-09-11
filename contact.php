<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function respond(int $status, bool $success, string $message): never {
    http_response_code($status);
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, false, 'Method not allowed.');
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 20000) respond(413, false, 'Request too large.');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $originHost = parse_url($origin, PHP_URL_HOST);
    $host = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
    if (!$originHost || strcasecmp($originHost, $host) !== 0) respond(403, false, 'Invalid request origin.');
}
foreach (['name', 'email', 'service', 'message', 'privacy_consent', 'website_check', 'property'] as $field) {
    if (isset($_POST[$field]) && !is_string($_POST[$field])) respond(422, false, 'Invalid form fields.');
}
$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$service = trim($_POST['service'] ?? '');
$message = trim($_POST['message'] ?? '');
$property = trim($_POST['property'] ?? '');
if (strlen($property) > 100 || preg_match('/[\r\n]/', $property)) respond(422, false, 'Please enter a valid property type.');
if (trim($_POST['website_check'] ?? '') !== '') respond(422, false, 'Invalid submission.');
if (($_POST['privacy_consent'] ?? '') !== '1') respond(422, false, 'Please accept the privacy policy.');
if (strlen($name) < 2 || strlen($name) > 100 || preg_match('/[\r\n]/', $name)) respond(422, false, 'Please enter a valid name.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || preg_match('/[\r\n]/', $email)) respond(422, false, 'Please enter a valid email address.');
if (!in_array($service, ['cameras', 'alarms', 'smart-access', 'combined', 'general'], true)) respond(422, false, 'Please select a valid topic.');
if (strlen($message) < 10 || strlen($message) > 5000) respond(422, false, 'The message must contain between 10 and 5000 characters.');
$configPath = __DIR__ . '/config/config.js';
if (!is_file($configPath)) $configPath = __DIR__ . '/dist/config/config.js';
$raw = @file_get_contents($configPath);
if (!$raw || !preg_match('/window\.SiteConfig\s*=\s*(\{[\s\S]*\})\s*;?\s*$/', $raw, $matches)) respond(500, false, 'Contact configuration is unavailable.');
$config = json_decode($matches[1], true);
if (!is_array($config)) respond(500, false, 'Contact configuration is invalid.');
$recipient = $config['email'] ?? '';
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $recipient) || str_ends_with(strtolower($recipient), '.example')) respond(503, false, 'The enquiry mailbox is not configured yet.');
$rateKey = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . __DIR__);
$rateFile = @fopen(sys_get_temp_dir() . '/home-security-' . $rateKey . '.rate', 'c+');
if (!$rateFile || !flock($rateFile, LOCK_EX)) respond(503, false, 'Please try again later.');
$lastAttempt = (int) stream_get_contents($rateFile);
if ($lastAttempt && time() - $lastAttempt < 45) { fclose($rateFile); respond(429, false, 'Please wait before sending another enquiry.'); }
ftruncate($rateFile, 0);
rewind($rateFile);
fwrite($rateFile, (string)time());
flock($rateFile, LOCK_UN);
fclose($rateFile);
$company = str_replace(["\r", "\n"], '', (string)($config['companyName'] ?? 'Home Security'));
$subject = 'Home security enquiry: ' . $service;
$body = "Website: $company\nName: $name\nEmail: $email\nService: $service\nProperty: $property\nPrivacy consent: yes\n\n$message";
$headers = ['From' => $recipient, 'Reply-To' => $email, 'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=UTF-8'];
if (!@mail($recipient, $subject, $body, $headers)) respond(503, false, 'The email service could not accept your enquiry. Please try again later.');
respond(200, true, (string)($config['formSuccessMessage'] ?? 'Успешно отправлено'));
