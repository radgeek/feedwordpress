<?php
class FeedWordPressHTTPAuthenticator {
	var $args = array();
	
	function FeedWordPressHTTPAuthenticator () {
		self::__construct();
	}
	
	function __construct () {
		add_filter('use_curl_transport', array(&$this, 'digest_do_it'), 2, 1000);
		foreach (array('fsockopen', 'fopen', 'streams', 'http_extension') as $transport) :
			add_filter("use_{$transport}_transport", array(&$this, 'digest_dont'), 2, 1000);
		endforeach;
		
		add_filter('pre_http_request', array(&$this, 'pre_http_request'), 10, 3);
		add_action('http_api_curl', array(&$this, 'set_auth_options'), 1000, 1);
	} /* FeedWordPerssHTTPAuthenticator::__construct () */

	function need_curl ($args) {
		$args = wp_parse_args($args, array(
		'authentication' => NULL,
		));
		
		switch ($args['authentication']) :
		case 'digest' :
			$use = true;
			break;
		default :
			$use = false;
		endswitch;
		return $use;
	} /* FeedWordPerssHTTPAuthenticator::need_curl () */
	
	function digest_do_it ($use, $args) {
		if ($this->need_curl($args)) :
			$use = true;
		endif;
		return $use;
	} /* FeedWordPerssHTTPAuthenticator::digest_do_it () */
	
	function digest_dont ($use, $args) {
		if ($this->need_curl($args)) :
			$use = false;
		endif;
		return false;
	} /* FeedWordPerssHTTPAuthenticator::digest_dont () */
	
	function pre_http_request ($pre, $args, $url) {
		$this->args = wp_parse_args($args, array(
		'authentication' => NULL,
		'username' => NULL,
		'password' => NULL,
		));
		return $pre;
	}
	
	function set_auth_options (&$handle) {
		if ('digest'==$this->args['authentication']) :
			curl_setopt($handle, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
		endif;
		
		if (!is_null($this->args['username'])) :
			$userPass = $this->args['username'];
			if (!is_null($this->args['password'])) :
				$userPass .= ':'.$this->args['password'];
			endif;
			
			curl_setopt($handle, CURLOPT_USERPWD, $userPass);
		endif;

	} /* FeedWordPressHTTPAuthenticator::set_auth_options() */
	
} /* class FeedWordPressHTTPAuthenticator */
