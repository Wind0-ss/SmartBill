<?php
/**
 * LINE Login Redirection Handler
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$channelId = getSetting('line_login_channel_id', '');

if (empty($channelId)) {
    redirect(APP_URL . '/login.php', 'ระบบยังไม่ได้ตั้งค่า LINE Login Channel ID กรุณาติดต่อผู้ดูแลระบบ', 'error');
}

// Generate state to protect against CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['line_login_state'] = $state;

// Build LINE Login authorize URL
$params = [
    'response_type' => 'code',
    'client_id'     => $channelId,
    'redirect_uri'  => APP_URL . '/api/line-login-callback.php',
    'state'         => $state,
    'scope'         => 'profile openid',
];

$authorizeUrl = 'https://access.line.me/oauth2/v2.1/authorize?' . http_build_query($params);

// Redirect to LINE
header('Location: ' . $authorizeUrl);
exit;
