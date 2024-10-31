<?php
include($_SERVER['DOCUMENT_ROOT']."/wp/wp-load.php");


spl_autoload_register(function($class){
	$class = end(explode("\\", $class));
	require_once($class . '.php');
});


$key      = 't9t7qihagiisilm';
$secret   = 'bggahqzom9g29r2';

$protocol = (!empty($_SERVER['HTTPS'])) ? 'https' : 'http';
$callback = $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

$encrypter = new \Dropbox\OAuth\Storage\Encrypter('XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX');
$storage = new \Dropbox\OAuth\Storage\Wordpress($encrypter);

$OAuth = new \Dropbox\OAuth\Consumer\Curl($key, $secret, $storage, $callback);
$dropbox = new \Dropbox\API($OAuth);

echo $dropbox->accountInfo()['body']->email;


?>