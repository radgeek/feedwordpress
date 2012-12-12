<?php
class FeedWordPressHTTPAuthenticator {
	var $args = array();
	
	function __construct () {
		add_filter('use_curl_transport', array($this, 'digest_do_it'), 2, 1000);
		foreach (array('fsockopen', 'fopen', 'streams', 'http_extension') as $transport) :
			add_filter("use_{$transport}_transport", array($this, 'digest_dont'), 2, 1000);
		endforeach;
		
		add_filter('pre_http_request', array($this, 'pre_http_request'), 10, 3);
		add_action('http_api_curl', array($this, 'set_auth_options'), 1000, 1);
	} /* FeedWordPressHTTPAuthenticator::__construct () */

	function methods_available () {
		$methods = array('-' => 'None');
		
		if ($this->have_curl(array('authentication' => 'digest'))) :
			$methods = array_merge(array(
				'digest' => 'Digest',
			), $methods);
		endif;
		
		if (
			$this->have_curl(array('authentication' => 'basic'))
			or $this->have_streams(array('authentication' => 'basic'))
		) :
			$methods = array_merge(array(
				'basic' => 'Basic',
			), $methods);
		endif;
		
		return $methods;
	}

	function pre_http_request ($pre, $args, $url) {
		$this->args = wp_parse_args($args, array(
		'authentication' => NULL,
		'username' => NULL,
		'password' => NULL,
		));
		
		// Ruh roh...
		$auth = $this->args['authentication'];
		if (is_null($auth) or (strlen($auth) == 0)) :
			$this->args['authentication'] = '-';
		endif;
		
		switch ($this->args['authentication']) :
		case '-' :
			// No HTTP Auth method. Remove this stuff.
			$this->args['authentication'] = NULL;
			$this->args['username'] = NULL;
			$this->args['password'] = NULL;
			break;
		case 'basic' :
			if ($this->have_curl($args, $url)) :
				// Don't need to do anything. http_api_curl hook takes care
				// of it.
				break;
			elseif ($this->have_streams($args, $url)) :
				// curl has a nice native way to jam in the username and
				// passwd but streams and fsockopen do not. So we have to
				// make a recursive call with the credentials in the URL.
				// Wee ha!
				$method = $this->args['authentication'];
				$credentials = $this->args['username'];
				if (!is_null($this->args['password'])) :
					$credentials .= ':'.$args['password'];
				endif;
				
				// Remove these so we don't recurse all the way down
				unset($this->args['authentication']);
				unset($this->args['username']);
				unset($this->args['password']);
				
				$url = preg_replace('!(https?://)!', '$1'.$credentials.'@', $url);
				
				// Subsidiary request
				$pre = wp_remote_request($url, $this->args);
				break;
			endif;
		case 'digest' :
			if ($this->have_curl($args, $url)) :
				// Don't need to do anything. http_api_curl hook takes care
				// of it.
				break;
			endif;
		default :
			if (is_callable('WP_Http', '_get_first_available_transport')) :
				$trans = WP_Http::_get_first_available_transport($args, $url);
				if (!$trans) :
					$trans = WP_Http::_get_first_available_transport(array(), $url);
				endif;
			elseif (is_callable('WP_Http', '_getTransport')) :
				$transports = WP_Http::_getTransport($args);
				$trans = get_class(reset($transports));
			else :
				$trans = 'HTTP';
			endif;
			
			$pre = new WP_Error('http_request_failed',
				sprintf(
					__('%s cannot use %s authentication with the %s transport.'),
					__CLASS__,
					$args['authentication'],
					$trans
				)
			);
		endswitch;

		return $pre;
	} /* FeedWordPressHTTPAuthenticator::pre_http_request () */

	function have_curl ($args, $url = NULL) {
		return WP_Http_Curl::test($args);
	}
	
	function have_streams ($args, $url = NULL) {
		return WP_Http_Streams::test($args);
	}
	
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
	} /* FeedWordPressHTTPAuthenticator::need_curl () */
	
	function digest_do_it ($use, $args) {
		return $this->if_curl($use, $args, true);
	} /* FeedWordPerssHTTPAuthenticator::digest_do_it () */
	
	function digest_dont ($use, $args) {
		return $this->if_curl($use, $args, false);
	} /* FeedWordPressHTTPAuthenticator::digest_dont () */
	
	function if_curl ($use, $args, $what) {
		if ($this->need_curl($args)) :
			$use = $what;
		endif;
		return $use;
	} /* FeedWordPressHTTPAuthenticator::if_curl () */
	
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
