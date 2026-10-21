<?php
/** FreightKit contact/suggestion form handler for PHP shared hosting. */
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

// Redirect relative to this script so the site also works when installed in a subfolder.
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$successUrl = $basePath . '/contact-success/';

// Quietly reject bot submissions. This field should remain empty for people.
if (!empty($_POST['website'] ?? '')) {
    header('Location: ' . $successUrl);
    exit;
}

$configPath = __DIR__ . '/contact-config.php';
if (!is_file($configPath)) {
    http_response_code(503);
    exit('Contact is not configured yet. The site owner must create contact-config.php.');
}
$config = require $configPath;
$to = filter_var((string)($config['to_email'] ?? ''), FILTER_VALIDATE_EMAIL);
$from = filter_var((string)($config['from_email'] ?? ''), FILTER_VALIDATE_EMAIL);
if (!$to || !$from) {
    http_response_code(503);
    exit('Contact email settings are invalid. Please notify the site owner.');
}

function field(string $key, int $max = 5000): string {
    $value = trim((string)($_POST[$key] ?? ''));
    $value = str_replace("\0", '', $value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

$name = field('name', 120);
$email = filter_var(field('email', 254), FILTER_VALIDATE_EMAIL);
$company = field('company', 200);
$topic = field('topic', 200);
$message = field('message', 5000);
$type = field('form_type', 40);
$details = field('details', 5000);
$isSuggestion = ($type === 'suggestion' || isset($_POST['details']));

if ($name === '' || !$email || ($isSuggestion ? $details === '' : ($topic === '' || $message === ''))) {
    http_response_code(400);
    exit('Please complete all required fields with a valid email address, then go back and try again.');
}

$subject = $isSuggestion ? 'FreightKit website suggestion' : 'FreightKit enquiry: ' . $topic;
$body = "A new message was submitted on FreightKit.\n\n";
$body .= "Name: {$name}\nEmail: {$email}\nCompany: {$company}\n";
if ($isSuggestion) {
    $body .= "Request type: " . field('type', 200) . "\nDetails:\n{$details}\n";
} else {
    $body .= "Topic: {$topic}\nMessage:\n{$message}\n";
}
$body .= "\nSubmitted at (UTC): " . gmdate('c') . "\n";
$headers = [
    'From' => $from,
    'Reply-To' => $email,
    'MIME-Version' => '1.0',
    'Content-Type' => 'text/plain; charset=UTF-8',
    'X-Content-Type-Options' => 'nosniff',
];

// PHP mail() requires a working mail service on the hosting account. SMTP is more reliable.
$sent = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
if (!$sent) {
    http_response_code(502);
    exit('Your message could not be sent. Please try again later or contact the site owner by email.');
}
header('Location: ' . $successUrl, true, 303);
exit;
