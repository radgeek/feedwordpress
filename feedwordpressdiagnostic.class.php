<?php
/**
 * class FeedWordPressDiagnostic: help to organize some diagnostics output functions.
 *
 * @uses FeedWordPress::diagnostic()
 */
class FeedWordPressDiagnostic {
	/**
	 * Log error on a feed.
	 *
	 * @param  array  $error  A structured array of error fields.
	 * @param  mixex  $old    Unused.
	 * @param  object $link   A link object, containing an URI.	 *
	 */
	public static function feed_error( $error, $old, $link ) {
		$wpError = $error['object'];
		$url = $link->uri();

		// check for effects of an effective-url filter
		$effectiveUrl = $link->uri( array( 'fetch' => true ) );
		if ( $url != $effectiveUrl ) : $url .= ' | ' . $effectiveUrl; endif;

		$mesgs = $wpError->get_error_messages();
		foreach ( $mesgs as $mesg ) :
			$mesg = esc_html( $mesg );
			FeedWordPress::diagnostic(
				'updated_feeds:errors',
				"Feed Error: [${url}] update returned error: $mesg"
			);

			$hours = get_option( 'feedwordpress_diagnostics_persistent_errors_hours', 2 );
			$span = ( $error['ts'] - $error['since'] );

			if ( $span >= ( $hours * 60 * 60 ) ) :
				$since = date( 'r', $error['since'] );
				$mostRecent = date( 'r', $error['ts'] );	// never used?... (gwyneth 20230919)
				FeedWordPress::diagnostic(
					'updated_feeds:errors:persistent',
					"Feed Update Error: [${url}] returning errors"
					." since ${since}:<br/><code>$mesg</code>",
					$url,
					$error['since'],
					$error['ts']
				);
			endif;
		endforeach;
	} /* FeedWordPressDiagnostic::feed_error() */

	public static function admin_emails( $id = '' ) {
		$users = get_users_of_blog( $id );	// Note: this is a deprecated WP function which should be replaced with get_users(), which, however, has quite more intricate syntax (and customisation!). (gwyneth 20230919)
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

		if ( !is_null($file)) :
			$location = "line # ${line} of ".basename($file);
		else :
			$location = "line # ${line}";
		endif;

		print '<p><strong>Critical error:</strong> There may be a bug in FeedWordPress. Please <a href="'.FEEDWORDPRESS_AUTHOR_CONTACT.'">contact the author</a> and paste the following information into your e-mail:</p>';
		print "\n<pre>";
		print "Triggered at " . esc_html($location) . "\n";
		print "FeedWordPress: " . esc_html( FEEDWORDPRESS_VERSION ) . "\n";
		print "WordPress:     " . esc_html( $wp_version ) . "\n";
		print "PHP:           " . esc_html( phpversion() ) . "\n";
		print "Error data:    ";
		print esc_html($varname) . ": " . esc_html( MyPHP::val( $var ) ) . "\n";
		print "\n</pre>";
		die;
	} /* FeedWordPressDiagnostic::critical_bug () */

	public static function is_on ($level) {
		$show = get_option('feedwordpress_diagnostics_show', array());
		return (in_array($level, $show));
	} /* FeedWordPressDiagnostic::is_on () */

} /* class FeedWordPressDiagnostic */
