<?php
/**
 * class FeedWordPressDiagnostic: help to organize some diagnostics output functions.
 */
class FeedWordPressDiagnostic {
	public static function feed_error ($error, $old, $link) {
		$wpError = $error['object'];
		$url = $link->uri();
		
		// check for effects of an effective-url filter
		$effectiveUrl = $link->uri(array('fetch' => true));
		if ($url != $effectiveUrl) : $url .= ' | ' . $effectiveUrl; endif;

		$mesgs = $wpError->get_error_messages();
		foreach ($mesgs as $mesg) :
			$mesg = esc_html($mesg);
			FeedWordPress::diagnostic(
				'updated_feeds:errors',
				"Feed Error: [${url}] update returned error: $mesg"
			);

			$hours = get_option('feedwordpress_diagnostics_persistent_errors_hours', 2);
			$span = ($error['ts'] - $error['since']);

			if ($span >= ($hours * 60 * 60)) :
				$since = date('r', $error['since']);
				$mostRecent = date('r', $error['ts']);
				FeedWordPress::diagnostic(
					'updated_feeds:errors:persistent',
					"Feed Update Error: [${url}] returning errors"
					." since ${since}:<br/><code>$mesg</code>",
					$url, $error['since'], $error['ts']
				);
			endif;
		endforeach;
	}

	public static function admin_emails ($id = '') {
		$users = get_users_of_blog($id);
		$recipients = array();
		foreach ($users as $user) :
			$user_id = (isset($user->user_id) ? $user->user_id : $user->ID);
			$dude = new WP_User($user_id);
			if ($dude->has_cap('administrator')) :
				if ($dude->user_email) :
					$recipients[] = $dude->user_email;
				endif;
			endif;
		endforeach;
		return $recipients;
	}

	public static function noncritical_bug ($varname, $var, $line, $file = NULL) {
		if (FEEDWORDPRESS_DEBUG) : // halt only when we are doing debugging
			self::critical_bug($varname, $var, $line, $file);
		endif;
	} /* FeedWordPressDiagnostic::noncritical_bug () */

	public static function critical_bug ($varname, $var, $line, $file = NULL) {
		global $wp_version;

		if (!is_null($file)) :
			$location = "line # ${line} of ".basename($file);
		else :
			$location = "line # ${line}";
		endif;

		print '<p><strong>Critical error:</strong> There may be a bug in FeedWordPress. Please <a href="'.FEEDWORDPRESS_AUTHOR_CONTACT.'">contact the author</a> and paste the following information into your e-mail:</p>';
		print "\n<plaintext>";
		print "Triggered at ${location}\n";
		print "FeedWordPress: ".FEEDWORDPRESS_VERSION."\n";
		print "WordPress:     {$wp_version}\n";
		print "PHP:           ".phpversion()."\n";
		print "Error data:    ";
		print  $varname.": "; var_dump($var); echo "\n";
		die;
	} /* FeedWordPressDiagnostic::critical_bug () */

	public static function is_on ($level) {
		$show = get_option('feedwordpress_diagnostics_show', array());
		return (in_array($level, $show));
	} /* FeedWordPressDiagnostic::is_on () */

} /* class FeedWordPressDiagnostic */
