<?php
class FeedWordPress_File extends WP_SimplePie_File {
	function FeedWordPress_File ($url, $timeout = 10, $redirects = 5, $headers = null, $useragent = null, $force_fsockopen = false) {
		self::__construct($url, $timeout, $redirects, $headers, $useragent, $force_fsockopen);
	}
	
	function __construct ($url, $timeout = 10, $redirects = 5, $headers = null, $useragent = null, $force_fsockopen = false) {
		if (is_callable(array('WP_SimplePie_File', 'WP_SimplePie_File'))) : // PHP 4 idiom
			WP_SimplePie_File::WP_SimplePie_File($url, $timeout, $redirects, $headers, $useragent, $force_fsockopen);
		else : // PHP 5+
			parent::__construct($url, $timeout, $redirects, $headers, $useragent, $force_fsockopen);
		endif;
		
		// SimplePie makes a strongly typed check against integers with
		// this, but WordPress puts a string in. Which causes caching
		// to break and fall on its ass when SimplePie is getting a 304,
		// but doesn't realize it because this member is "304" instead.
		$this->status_code = (int) $this->status_code;
	}
} /* class FeedWordPress_File () */

