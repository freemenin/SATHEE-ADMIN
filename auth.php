<?php
require 'vendor/autoload.php';

session_start();

$client = new Google_Client();
$client->setApplicationName('Google Contacts API PHP');
$client->setScopes(Google_Service_PeopleService::CONTACTS);
$client->setAuthConfig('credentials.json');
$client->setAccessType('offline'); // so refresh tokens work
$client->setPrompt('select_account consent');

// IMPORTANT: set your redirect URL here (must match in Google Cloud Console)
$client->setRedirectUri('https://app.mysathee.com/auth.php');

$tokenPath = 'token.json';

// STEP 1: If we already have a token, load it
if (file_exists($tokenPath)) {
    $accessToken = json_decode(file_get_contents($tokenPath), true);
    $client->setAccessToken($accessToken);

    // Refresh token if expired
    if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        } else {
            unlink($tokenPath);
            header('Location: ' . filter_var($client->createAuthUrl(), FILTER_SANITIZE_URL));
            exit;
        }
    }

    echo "<h3>✅ Auth successful. Token already exists.</h3>";
    echo "Now you can run <b>save_contact.php</b> to add contacts.";
    exit;
}

// STEP 2: If Google redirects back with ?code=...
if (isset($_GET['code'])) {
    $authCode = $_GET['code'];

    // Exchange code for token
    $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

    if (isset($accessToken['error'])) {
        echo "<h3>❌ Error fetching token:</h3>";
        echo "<pre>" . print_r($accessToken, true) . "</pre>";
        exit;
    }

    $client->setAccessToken($accessToken);

    // Save token to token.json
    if (!file_exists(dirname($tokenPath))) {
        mkdir(dirname($tokenPath), 0700, true);
    }
    file_put_contents($tokenPath, json_encode($client->getAccessToken()));

    echo "<h3>✅ Token saved successfully!</h3>";
    echo "Now you can run <b>save_contact.php</b>.";
    exit;
}

// STEP 3: No token, no code → show login link
$authUrl = $client->createAuthUrl();
echo "<a href='" . htmlspecialchars($authUrl) . "'>Click here to connect your Google Account</a>";
