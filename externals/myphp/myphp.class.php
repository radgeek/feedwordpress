<?php
if (!class_exists('MyPHP')) :
	class MyPHP {
		// For dealing with HTTP GET/POST parameters sent to us as input
		static public function param ($key, $default = NULL, $type = 'REQUEST') {
			// PHP 5.4 introduces "just-in-time" initialization of
			// $GLOBALS. Which seems to me largely to defeat the
			// purpose of the $GLOBALS array. But whatever. Force
			// $_GET, $_POST, and $_REQUEST into the array.
			global $_GET, $_POST, $_REQUEST;
			
			$where = '_'.strtoupper($type);
			$ret = $default;
			if (isset($GLOBALS[$where]) and is_array($GLOBALS[$where])) :
				if (isset($GLOBALS[$where][$key])) :
					$ret = $GLOBALS[$where][$key];
					
					// Magic quotes are like the stupidest
					// thing ever.
					if (get_magic_quotes_gpc()) :
						$ret = self::stripslashes_deep($ret);
					endif;
				endif;
			endif;

			return $ret;
		} /* MyPHP::param () */
		
		static function post ($key, $default = NULL) {
			return self::param($key, $default, 'POST');
		} /*MyPHP::post () */
		
		static function get ($key, $default = NULL) {
			return self::param($key, $default, "GET");
		} /* MyPHP::get () */
		
		static function request ($key, $default = NULL) {
			return self::param($key, $default, "REQUEST");
		} /* MyPHP::request () */
		
		static function stripslashes_deep ($value) {
			if ( is_array($value) ) {
				$value = array_map(array(__CLASS__, 'stripslashes_deep'), $value);
			} elseif ( is_object($value) ) {
				$vars = get_object_vars( $value );
				foreach ($vars as $key=>$data) {
					$value->{$key} = stripslashes_deep( $data );
				}
			} else {
				$value = stripslashes($value);
			}
			return $value;
		} /* MyPHP::stripslashes_deep () */
		
		static function url ($url, $params = array()) {
			$sep = '?';
			if (strpos($url, '?') !== false) :
				$sep = '&';
			endif;
			$url .= $sep . self::to_http_post($params);

			return $url;
		} /* MyPHP::url () */
		
		/**
		 * MyPHP::to_http_post () -- convert a hash table or object into
		 * an application/x-www-form-urlencoded query string
		 */
		static function to_http_post ($params) {
			$getQuery = array();
			foreach ($params as $key => $value) :
				if (is_scalar($value)) :
					$getQuery[] = urlencode($key) . '=' . urlencode($value);
				else :
					// Make some attempt to deal with array and
					// object members. Really we should descend
					// recursively to get the whole thing....
					foreach ((array) $value as $k => $v) :
						$getQuery[] = urlencode($key.'['.$k.']') . '=' . urlencode($v);
					endforeach;
				endif;
			endforeach;
			return implode("&", $getQuery);
		} /* MyPHP::to_http_post () */
	}
endif;


