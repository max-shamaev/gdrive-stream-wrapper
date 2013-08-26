<?php

set_include_path(get_include_path() . PATH_SEPARATOR . 'google-api-php-client/src');

require_once 'Google_Client.php';
require_once 'contrib/Google_DriveService.php';

require_once './GoogleDriveStreamWrapper.php';

$client = new Google_Client();
$client->setClientId('Insert Client ID');
$client->setClientSecret('Insert Client Secret');
$client->setRedirectUri('urn:ietf:wg:oauth:2.0:oob');
$client->setScopes(array('https://www.googleapis.com/auth/drive'));
$client->setUseObjects(true);

$service = new Google_DriveService($client);

$authUrl = $client->createAuthUrl();

//Request authorization
print "Please visit:\n$authUrl\n\n";
print "Please enter the auth code:\n";
$authCode = trim(fgets(STDIN));

// Exchange authorization code for access token
$accessToken = $client->authenticate($authCode);
$client->setAccessToken($accessToken);

\GoogleDriveStreamWrapper::setSrvice($service);
\GoogleDriveStreamWrapper::registerWrapper();

var_dump(mkdir('gdrive://aaa'));
var_dump(mkdir('gdrive://aaa/bbb2'));
var_dump(rename('gdrive://aaa/bbb2', 'gdrive://aaa/bbb3'));
var_dump(rmdir('gdrive://aaa/bbb3'));

var_dump(mkdir('gdrive://aaa/bbb4'), is_dir('gdrive://aaa/bbb4'), is_file('gdrive://aaa/bbb4'));

$path = 'gdrive://aaa/bbb4/' . date('Y-m-d-H-i-s') . '.txt';
var_dump(file_put_contents($path, 'test'));
var_dump(file_get_contents($path));
var_dump(file_put_contents($path, ' test2', FILE_APPEND));
var_dump(file_get_contents($path));
var_dump(filesize($path), is_dir($path), is_file($path));
