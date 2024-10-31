<?php
/**
* This is a storage handler specified for WordPress.
* It uses wordpress built-in function like get_option() and add_option() for saving and retrieving tokens.
*/

namespace Dropbox\OAuth\Storage;



class Wordpress extends Session {

	//options name into wordpress
	
	//private $optionName_accessToken = 'dbox_accessToken';
	//private $optionName_requestToken = 'dbox_requestToken';


	protected $namespace = 'dropbox_api';

	public function __construct(Encrypter $encrypter = null) {
		if (!function_exists("get_option")) {
			throw new \Dropbox\Exception("It seems wordpress is not installed/initiated", 1);	
		}

        if ($encrypter instanceof Encrypter) {
            $this->encrypter = $encrypter;
        }


	}

	public function get($type) {
		if ($type != "access_token" AND $type != "request_token") {
			throw new \Dropbox\Exception("Expected a type of either 'request_token' or 'access_token', got '$type'");
		} else {
			if ($token = get_option("pvd_".$type)) {
				$token = $this->decrypt($token);
				return $token;
			}
			return false;
		}
	}

	public function set($token, $type) {
		if ($type != "access_token" AND $type != "request_token") {
			throw new \Dropbox\Exception("Expected a type of either 'request_token' or 'access_token', got '$type'");	
		} else {
			$token = $this->encrypt($token);
			update_option("pvd_".$type, $token);
		}
	}


}

?>