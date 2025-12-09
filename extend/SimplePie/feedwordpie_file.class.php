<?php
global $fwp_credentials;

/** @var array|null If required, login and password to connect to RSS feed; NULL otherwise. */
$fwp_credentials = NULL;
/**
 * Our own variant of WP's SimplePie
 *
 * @extends WP_SimplePie_File
 */
class FeedWordPie_File extends WP_SimplePie_File {

	/**
 	* Timeout.
 	*
 	* @var int How long the connection should stay open in seconds.
 	*
 	* @see WP_SimplePie_File
 	*/
	public $timeout = 10;

	/**
	 * Class constructor.
	 *
	 * @param string      $url       URL for the RSS feed.
	 * @param int         $timeout   Timeout to wait for this feed.
	 * @param int         $redirects How many redirects we follow.
	 * @param array|null  $headers   List of HTTP headers to send (i,portant for authentication, etc.)
	 * @param string|null $useragent Identification of the User-Agent making the RSS request.
	 * @param bool        $force_fsockopen @deprecated and always ignored
	 *
	 * @uses FeedWordPress::diagnostic()
	 * @uses MyPHP::val()
	 *
	 * @global $feedwordpress
	 * @global $wp_version
	 */
	public function __construct( $url, $timeout = 10, $redirects = 5, $headers = null, $useragent = null, $force_fsockopen = false) {
		global $feedwordpress;
		global $wp_version;

		if ( $force_fsockopen )	{
			error_log( "deprecated usage of \$force_fsockopen, ignored" );
		}

		$source = NULL;
		if ( !empty( $feedwordpress ) and $feedwordpress->subscribed( $url ) ) :
			$source = $feedwordpress->subscription( $url );
		endif;

		$this->url       = $url;
		$this->timeout   = $timeout;
		$this->redirects = $redirects;
		$this->headers   = $headers;
		$this->useragent = $useragent;

		$this->method    = SIMPLEPIE_FILE_SOURCE_REMOTE;

		global $fwp_credentials;

		if ( preg_match( '/^http(s)?:\/\//i', $url ) ) :
			$args = array( 'timeout' => $this->timeout, 'redirection' => $this->redirects );

			if ( ! empty( $this->headers ) ) :
				$args['headers'] = $this->headers;
			endif;

			// Use default FWP user agent unless custom has been specified
			if ( SIMPLEPIE_USERAGENT != $this->useragent ) :
				$args['user-agent'] = $this->useragent;
			else :
				$args['user-agent'] = apply_filters(
					'feedwordpress_user_agent',
					'FeedWordPress/' . FEEDWORDPRESS_VERSION
					. ' (aggregator:feedwordpress; WordPress/' . $wp_version
					. ' + ' . SIMPLEPIE_NAME . '/' . SIMPLEPIE_VERSION
					. '; Allow like Gecko; +http://feedwordpress.radgeek.com/) at '
					. feedwordpress_display_url( get_bloginfo( 'url' ) ),
					$this
				);
			endif;

			// This is ugly as hell, but communicating up and down the chain
			// in any other way is difficult.
			if ( ! is_null( $fwp_credentials ) ) :
				$args['authentication'] = $fwp_credentials['authentication'];
				$args['username']       = $fwp_credentials['username'];
				$args['password']       = $fwp_credentials['password'];
			elseif ( $source InstanceOf SyndicatedLink ) :
				$args['authentication'] = $source->authentication_method();
				$args['username']       = $source->username();
				$args['password']       = $source->password();
			endif;

			FeedWordPress::diagnostic( 'updated_feeds:http', "HTTP [$url] &lceil; " . esc_html( MyPHP::val( $args ) ) );
			$res = wp_remote_request( $url, $args );
			FeedWordPress::diagnostic( 'updated_feeds:http', "HTTP [$url] &rceil; " . esc_html( MyPHP::val( $res ) ) );

			if ( is_wp_error( $res ) ) :
				$this->error   = 'WP HTTP Error: ' . $res->get_error_message();
				$this->success = false;
			else :
				$this->headers = array();
				$this->body        = wp_remote_retrieve_body( $res );
				$this->status_code = wp_remote_retrieve_response_code( $res );
			endif;

			if ( $source InstanceOf SyndicatedLink ) :
				$source->update_setting( 'link/filesize', strlen( $this->body ) );
				$source->update_setting( 'link/http status', $this->status_code );
				$source->save_settings( /*reload=*/ true );
			endif;

		/* Do not allow schemes other than http(s)? for the time being.
		 * They are unlikely to be used; and unrestricted use of schemes
		 * allows for user to use an unrestricted file:/// scheme, which
		 * may result in exploits by WordPress users against the web
		 * hosting environment.
		 */
		else :
			$this->error = __( 'FeedWordPress only allows HTTP or HTTPS URL schemes' );
			$this->success = false;
		endif;

		/* SimplePie makes a strongly typed check against integers with
		 * this, but WordPress puts a string in. Which causes caching
		 * to break and fall on its ass when SimplePie is getting a 304,
		 * but doesn't realize it because this member is "304" instead.
		 */
		$this->status_code = (int) $this->status_code;
	} /* FeedWordPie_File::__construct () */
} /* class FeedWordPie_File () */

