<?php
require_once dirname(__FILE__) . '/admin-ui.php';
require_once dirname(__FILE__) . '/magpiemocklink.class.php';
require_once dirname(__FILE__) . '/feedfinder.class.php';
require_once dirname(__FILE__) . '/updatedpostscontrol.class.php';

/**
 * List of feeds for FWP
 *
 * @extends FeedWordPressAdminPage
 * @global 	$post_source
 */
class FeedWordPressFeedsPage extends FeedWordPressAdminPage {
	/**
	 * List of possible HTTP status messages.
	 *
	 * @var array
	 *
	 * @note Added a few more, renamed a few to stick to contemporary standard. (gwyneth 20230918)
	 */
	var $HTTPStatusMessages = array(
		100 => 'Continue',
		101 => 'Switching Protocols',
		102 => 'Processing',				// WebDAV; RFC 2518
		103 => 'Early Hints',				// RFC 8297
		110 => 'Response is Stale',			// Obsoleted in 2022 by RFC 9111 (caching-related)
		111 => 'Revalidation Failed',		// Obsoleted in 2022 by RFC 9111 (caching-related)
		112 => 'Disconnected Operation',	// Obsoleted in 2022 by RFC 9111 (caching-related)
		113 => 'Heuristic Expiration',		// Obsoleted in 2022 by RFC 9111 (caching-related)
		199 => 'Miscellaneous Warning',		// Obsoleted in 2022 by RFC 9111 (caching-related)
		200 => 'OK. FeedWordPress had no problems retrieving the content at this URL but the content does not seem to be a feed, and does not seem to include links to any feeds.',
		201 => 'Created',
		202 => 'Accepted',
		203 => 'Non-Authoritative information',
		204 => 'No Content',
		205 => 'Reset Content',
		206 => 'Partial Content',
		207 => 'Multi-Status',				// WebDAV; RFC 4918
		208 => 'Already Reported',			// WebDAV; RFC 5842
		214 => 'Transformation Applied',	// Obsoleted in 2022 by RFC 9111 (caching-related)
		218 => 'This is fine', 				// Non-standard; used by the Apache HTTP Serve)
		226 => 'IM Used',					// RFC 3229
		299 => 'Miscellaneous Persistent Warning',	// Obsoleted in 2022 by RFC 9111 (caching-related)
		300 => 'Multiple Choices',
		301 => 'Moved Permanently',
		302 => 'Found',						// Previously known as 'Moved temporarily'
		303 => 'See Other',
		304 => 'Not Modified',
		305 => 'Use Proxy',
		306 => 'Switch Proxy',
		307 => 'Temporary Redirect',
		308 => 'Permanent Redirect',
		400 => 'Bad Request',
		401 => 'Unauthorized. This URL probably needs a username and password for you to access it.',
		402 => 'Payment Required',
		403 => 'Forbidden. The URL is not made available for the machine that FeedWordPress is running on.',
		404 => 'Not Found. There is nothing at this URL. Have you checked the address for typos?',
		405 => 'Method Not Allowed',
		406 => 'Not Acceptable',
		407 => 'Proxy Authentication Required',
		408 => 'Request Timeout',
		409 => 'Conflict',
		410 => 'Gone. This URL is no longer available on this server and no forwarding address is known.',
		411 => 'Length Required',
		412 => 'Precondition Failed',
		413 => 'Payload Too Large',		// Previously known as 'Request Entity Too Large'
		414 => 'URI Too Long',			// Previously known as 'Request URI Too Long'
		415 => 'Unsupported Media Type',
		416 => 'Range Not Satisfiable',	// Previously known as 'Requested Range Not Satisfiable'
		417 => 'Expectation Failed',
		418 => 'I\'m a teapot', 		// RFC 2324, RFC 7168
		419 => 'Page Expired', 			// Non-standard; used by the Laravel Framework
		420 => 'Enhance Your Calm',		// Non-standard; used by version 1.0 of the Twitter API
		421 => 'Misdirected Request',
		422 => 'Unprocessable Entity',
		423 => 'Locked', 				// WebDAV; RFC 4918
		424 => 'Failed Dependency',		// WebDAV; RFC 4918
		425 => 'Too Early',				// RFC 8470
		426 => 'Upgrade Required',
		428 => 'Precondition Required',	// RFC 6585
		429 => 'Too Many Requests', 	// RFC 6585
		431 => 'Request Header Fields Too Large',		// RFC 6585
		440 => 'Login Time-out',						// Non-standard; used by Microsoft on IIS
		444 => 'No Response',							// Non-standard; used internally by the nginx web server
		449 => 'Retry With',							// Non-standard; used by Microsoft on IIS
		450 => 'Blocked by Windows Parental Controls',	// Non-standard; used by Microsoft
		451 => 'Unavailable For Legal Reasons', 		// RFC 7725; also: used by Microsoft IIS for a redirect
		460	=> 'Too Soon',								// Non-standard; used by Amazon on AWS
		463	=> 'Too Many IP Addresses',					// Non-standard; used by Amazon on AWS
		464	=> 'Incompatible Protocol Version',			// Non-standard; used by Amazon on AWS
		494 => 'Request Header Too Large',				// Non-standard; used by the nginx web server
		495 => 'SSL Certificate Error',					// Non-standard; used by the nginx web server
		496 => 'SSL Certificate Required',				// Non-standard; used by the nginx web server
		498 => 'Invalid Token',			// Non-standard; used by Esri on their ArcGIS for Server product
		499 => 'Token Required',		// Non-standard; used by Esri on their ArcGIS for Server product; also used as 'Client Closed Request' by the nginx web server
		500 => 'Internal Server Error. Something unexpected went wrong with the configuration of the server that hosts this URL. You might try again later to see if this issue has been resolved.',
		501 => 'Not Implemented',
		502 => 'Bad Gateway',
		503 => 'Service Unavailable. The server is currently unable to handle the request due to a temporary overloading or maintenance of the server that hosts this URL. This is probably a temporary condition and you should try again later to see if the issue has been resolved.',
		504 => 'Gateway Timeout',
		505 => 'HTTP Version Not Supported',
		506 => 'Variant Also Negotiates',				// RFC 2295
		507 => 'Insufficient Storage', 					// WebDAV; RFC 4918
		508 => 'Loop Detected',							// WebDAV; RFC 5842
		509 => 'Bandwidth Limit Exceeded',				// Non-standard; used by the Apache Web Server as well as cPanel
		510 => 'Not Extended',							// RFC 2774
		511 => 'Network Authentication Required',		// RFC 6585
		520 => 'Web Server Returned an Unknown Error',	// Non-Standard; used by Cloudflare
		521 => 'Web Server Is Down',					// Non-Standard; used by Cloudflare
		522 => 'Connection Timed Out',					// Non-Standard; used by Cloudflare
		523 => 'Origin Is Unreachable',					// Non-Standard; used by Cloudflare
		524 => 'A Timeout Occurred',					// Non-Standard; used by Cloudflare
		525 => 'SSL Handshake Failed',					// Non-Standard; used by Cloudflare
		526 => 'Invalid SSL Certificate',				// Non-Standard; used by Cloudflare
		527 => 'Railgun Error',							// Non-Standard; used by Cloudflare
		529 => 'Site is overloaded',					// Non-standard; used by Qualys on its SSLLabs server testing API
		530 => 'Site is frozen',						// Non-standard; used by the Pantheon Systems web platform
		561 => 'Unauthorized',							// Non-standard; used by Amazon on AWS
		598 => 'Network Read Timeout Error',			// Non-standard; informal convention used by some HTTP procoes
		599 => 'Network Connect Timeout Error',			// Non-standard; informal convention used by some HTTP procoes
	);

	/**
	 * List of updated posts.
	 *
	 * @var mixed
	 */
	var $updatedPosts = NULL;

	/**
	 * List of special settings.
	 *
	 * Regular expression syntax is OK here.
	 *
	 * @var Array
	 */
	var $special_settings = array(
		'cats',
		'cat_split',
		'fetch timeout',
		'freeze updates',
		'hardcode name',
		'hardcode url',
		'hardcode description',
		'hardcode categories', /* Deprecated */
		'comment status',
		'terms',
		'map authors',
		'munge permalink',
		'ping status',
		'post status',
		'postmeta',
		'query parameters',
		'resolve relative',
		'syndicated post type',
		'tags',
		'unfamiliar author',
		'unfamliar categories', /* Deprecated */
		'unfamiliar category',
		'unfamiliar post_tag',
		'add/.*',
		'update/.*',
		'feed/.*',
		'link/.*',
		'match/.*',
	);

	/**
	 * Constructs the Feeds page object
	 *
	 * @param mixed $link An object of class {@link SyndicatedLink} if created for one feed's settings, NULL if created for global default settings
	 * @uses UpdatedPostsControl
	 */
	public function __construct( $link = -1 ) {
		if ( is_numeric( $link ) and -1 == $link ) :
			$link = $this->submitted_link();
		endif;

		parent::__construct( 'feedwordpressfeeds', $link );

		$this->dispatch = 'feedwordpress_admin_page_feeds';
		$this->pagenames = array(
			'default' => 'Feeds',
			'settings-update' => 'Syndicated feed',
			'open-sheet' => 'Feed and Update',
		);
		$this->filename = __FILE__;
		$this->updatedPosts = new UpdatedPostsControl( $this );

		$this->special_settings = apply_filters( 'syndicated_feed_special_settings', $this->special_settings, $this );
	} /* FeedWordPressFeedsPage constructor */

	/**
	 * Prints out the feeds page.
	 *
	 * @return bool  TRUE to continue printing out, FALSE to stop.
	 * @uses FeedWordPress::param()
	 */
	function display() {
		global $post_source;

		$this->boxes_by_methods = array(
			'feed_information_box'	=> __( 'Feed Information' ),
			'global_feeds_box'		=> __( 'Update Scheduling' ),
			'updated_posts_box'		=> __( 'Updated Posts' ),
			'custom_settings_box'	=> __( 'Custom Feed Settings (for use in templates)' ),
			'advanced_settings_box' => __( 'Advanced Settings' ),
		);
		if ($this->for_default_settings()) :
			unset( $this->boxes_by_methods['custom_settings_box'] );
		endif;

		// Allow overriding of normal source for FeedFinder, which may
		// be called from multiple points.
		if ( isset( $post_source ) and ! is_null( $post_source ) ) :
			$source = $post_source;
		else :
			$source = $this->dispatch;
		endif;

		$feedfinder = FeedWordPress::param( 'feedfinder' );
		$action = FeedWordPress::param( 'action' );
		if ( $feedfinder || in_array( $action, array( 'feedfinder', FWP_SYNDICATE_NEW ) ) ) :

			// If this is a POST, validate source and user credentials
			FeedWordPressCompatibility::validate_http_request(/*action=*/ $source, /*capability=*/ 'manage_links');

			return $this->display_feedfinder(); // re-route to Feed Finder page
		endif;

		parent::display();
		return false; // Don't continue
	} /* FeedWordPressFeedsPage::display() */

	/**
	 * Interface with JavaScript calls.
	 *
	 */
	function ajax_interface_js() {
		parent::ajax_interface_js();
		?>
		jQuery( document ).ready( function () {
			contextual_appearance( 'automatic-updates-selector', 'cron-job-explanation', null, 'no' );
			contextual_appearance( 'time-limit', 'time-limit-box', null, 'yes' );
			contextual_appearance( 'use-default-update-window-no', 'update-scheduling-note', null, null, 'block', true );
			jQuery( '#use-default-update-window-yes, #use-default-update-window-no' ).click( function () {
				contextual_appearance( 'use-default-update-window-no', 'update-scheduling-note', null, null, 'block', true );
			} );

			var els = [ 'name', 'description', 'url' ];
			for ( var i = 0; i < els.length; i++ ) {
				contextual_appearance(
					/*item=*/      'basics-hardcode-' + els[i],
					/*appear=*/    'basics-'          + els[i] + '-view',
					/*disappear=*/ 'basics-'          + els[i] + '-edit',
					/*value=*/      'no',
					/*visibleStyle=*/ 'block',
					/*checkbox=*/   true
				);
			} /* for */

			// Let's make the interface for the PAUSE/RESUME a bit better
			jQuery( '<tr id="reveal-pause-resume"><th></th><td><button id="pause-resume-reveal-button">&mdash; PAUSE or RESUME UPDATES &mdash;</button></td></tr>' ).insertBefore( '#pause-resume' );
			jQuery( '#pause-resume' ).hide();
			jQuery( '#pause-resume-reveal-button' ).click( function( ev ) {
				ev.preventDefault();

				jQuery( '#pause-resume' ).toggle();
				return false;
			});
		} );
		<?php
	} /* FeedWordPressFeedsPage::ajax_interface_js() */

	/**
	 * Displays updated feeds.
	 *
	 * @param  object     $page  Current page object (?).
	 * @param  mixed|null $box   A box inside this object.
	 */
	/*static*/ function updated_posts_box( $page, $box = NULL ) {
		?>
		<table class="edit-form">
		<?php $page->updatedPosts->display(); ?>
		</table>
		<?php
	} /* FeedWordPressFeedsPage::updated_posts_box() */

	/**
	 * Displays form to deal with global feeds.
	 *
	 * @param  object     $page  Current page object (?).
	 * @param  mixed|null $box   A box inside this object.
	 *
	 * @uses FeedWordPress::automatic_update_hook()
	 */
	/*static*/ function global_feeds_box( $page, $box = NULL ) {
		global $feedwordpress;
		$automatic_updates = $feedwordpress->automatic_update_hook( array( 'setting only' => true ) );
		$update_time_limit = (int) get_option( 'feedwordpress_update_time_limit' );

		// Hey, ho, let's go...
		?>

		<table class="edit-form"
		<?php if ( $page->for_default_settings() )  : ?>

		<tr>
		<th scope="row"><?php esc_html_e( 'Update Method:' ); ?></th>
		<td><select id="automatic-updates-selector" name="automatic_updates" size="1" onchange="contextual_appearance('automatic-updates-selector', 'cron-job-explanation', null, 'no');">
		<option value="shutdown"<?php fwp_selected_flag( 'shutdown' == $automatic_updates ); ?>><?php esc_html_e( 'automatically check for updates after pages load' ); ?></option>
		<option value="init"<?php fwp_selected_flag( 'init' == $automatic_updates ); ?>><?php esc_html_e( 'automatically check for updates before pages load' ); ?></option>
		<option value="no"<?php fwp_selected_flag( ! $automatic_updates ); ?>><?php esc_html_e( 'cron job or manual updates' ); ?></option>
		</select>
		<div id="cron-job-explanation" class="setting-description">
		<p><?php
		/* Do we have shell_exec() available from here, or is it disabled for security reasons?
		 * If it's available, use it to execute `which` to try to get a realistic path to curl,
		 * or to wget. If everything fails or shell_exec() isn't available, then just make
		 * up something for the sake of example.
		 */
		/** var string|null Path to either cURL or wget executables. */
		$curlOrWgetPath = NULL;

		/** var bool True if this WP installation can call shell commands. */
		$shellExecAvailable = ( is_callable( 'shell_exec' ) && false === stripos( ini_get( 'disable_functions' ), 'shell_exec') );

		if ( $shellExecAvailable ) :
			$curlOrWgetPath = `which curl`; $opts = '--silent %s';
		endif;

		if ( $shellExecAvailable and ( is_null( $curlOrWgetPath ) or strlen( trim( $curlOrWgetPath ) ) == 0 ) ) :
			$curlOrWgetPath = `which wget`; $opts = '-q -O - %s';
		endif;

		if ( is_null( $curlOrWgetPath ) or strlen( trim( $curlOrWgetPath ) ) == 0 ) :
			$curlOrWgetPath = '/usr/bin/curl'; $opts = '--silent %s';
		endif;

		$curlOrWgetPath = preg_replace( '/\n+$/', '', $curlOrWgetPath );

		/** var string Constructed shell command line to call cURL/wget for updating FeedWordPress feeds. */
		$cmdline = $curlOrWgetPath . ' ' . sprintf( $opts, get_bloginfo( 'url' ) . '?update_feedwordpress=1' );

		?><?php esc_html_e( 'If you want to use a cron job, you can perform scheduled updates by sending regularly-scheduled requests to ' ); ?><a href="<?php bloginfo('url'); ?>?update_feedwordpress=1"><code><?php bloginfo('url'); ?>?update_feedwordpress=1</code></a>
		<?php esc_html_e( 'For example, inserting the following line in your crontab:' ); ?></p>
		<pre style="font-size: 0.80em"><code>*/10 * * * * <?php print esc_html($cmdline); ?></code></pre>
		<p class="setting-description"><?php esc_html_e( 'will check in every 10 minutes and check for updates on any feeds that are ready to be polled for updates.' ); ?></p>
		</div>
		</td>
		</tr>

		<?php else : /* Feed-specific settings */ ?>

		<tr>
		<th scope="row"><?php esc_html_e( 'Last update' ) ?>:</th>
		<td><?php
			if ( isset( $page->link->settings['update/last'] ) ) :
				echo esc_html( fwp_time_elapsed( $page->link->settings['update/last'] ) . " " );
			else :
				esc_html_e( " none yet" );
			endif;
		?></td></tr>

		<tr><th><?php esc_html_e( 'Next update' ) ?>:</th>
		<td><?php
			$holdem = ( isset( $page->link->settings['update/hold'] ) ? $page->link->settings['update/hold'] : 'scheduled' );
		?>
		<select name="update_schedule">
		<option value="scheduled"<?php echo ( 'scheduled' == $holdem ) ? ' selected="selected"' : ''; ?>><?php esc_html_e( 'update on schedule' ); ?> <?php
			echo " (";
			if ( isset( $page->link->settings['update/ttl'] ) and is_numeric( $page->link->settings['update/ttl'] ) ) :
				if ( isset( $page->link->settings['update/timed'] ) and 'automatically' == $page->link->settings['update/timed'] ) :
					esc_html_e( 'next: ');
					$next = $page->link->settings['update/last'] + ( (int) $page->link->settings['update/ttl'] * 60 );
					// Note: strftime() is deprecated; use date() instead. (gwyneth 20230918)
					if ( strftime( '%x', time() ) != strftime( '%x', $next) ) :
						echo esc_html( strftime( '%x', $next ) . ' ' );
					endif;
					echo esc_html( strftime( '%X', $page->link->settings['update/last'] + ( (int) $page->link->settings['update/ttl'] * 60 ) ) );
				else :
					echo esc_html__( "every " ) . esc_html( $page->link->settings['update/ttl'] ) . esc_html__( " minute" )
						. ( ( 1 != $page->link->settings['update/ttl'] ) ? esc_html__( "s" ) : "" );
				endif;
			else:
				esc_html_e( "next scheduled update" );
			endif;
			echo ")";
		?></option>
		<option value="next"<?php echo ( 'next' == $holdem ) ? ' selected="selected"' : ''; ?>><?php esc_html_e( 'update ASAP' ); ?></option>
		<option value="ping"<?php echo ( 'ping' == $holdem ) ? ' selected="selected"' : ''; ?>><?php esc_html_e( 'update only when pinged' ); ?></option>
		</select></td></tr>

		<?php endif; ?>

		<tr id="pause-resume">
		<th scope="row"><?php esc_html_e( 'Pause/Resume:'); ?></th>
		<td><?php
		$this->setting_radio_control(
			'update/pause',
			'update_pause',
			/*options=*/ array(
				'no' => __( '<strong>UPDATE:</strong> import new posts as normal' ),
				'yes' => __( '<strong>PAUSE:</strong> do not import new posts until I unpause updates' ),
			),
			/*params=*/ array(
				'setting-default' => NULL,
				'global-setting-default' => 'no',
				'default-input-value' => 'default',
			),
		);
		?></td>
		</tr>

		<tr>
		<th scope="row"><?php esc_html_e( 'Update scheduling:') ?></th>
		<td><p style="margin-top:0px"><?php esc_html_e( 'How long should FeedWordPress wait between updates before it considers this feed ready to be polled for updates again?' ); ?></p>
		<?php
			$this->setting_radio_control(
				'update/window',
				'update_window',
				array( $this, 'update_window_edit_box' ),
				array(
					'global-setting-default' => DEFAULT_UPDATE_PERIOD,
					'default-input-name'     => 'use_default_update_window',
					'default-input-id'       => 'use-default-update-window-yes',
					'default-input-id-no'    => 'use-default-update-window-no',
					'labels' => array($this, 'update_window_currently' ),
				)
			);
			?></td>
		</tr>

		<tr>
		<th scope="row"><?php esc_html_e( 'Minimum Interval:'); ?></th>
		<td><p style="margin-top:0px"><?php esc_html_e( 'Some feeds include standard elements that
		request a specific update schedule. If the interval requested by the
		feed provider is <em>longer</em> than FeedWordPress&rsquo;s normal scheduling,
		FeedWordPress will always respect their request to slow down. But what
		should it do if the update interval is <em>shorter</em> than the schedule set above?' ); ?></p>
		<?php
			$this->setting_radio_control(
				'update/minimum', 'update_minimum',
				/*options=*/ array(
					'no'  => __( 'Speed up and accept the interval from the feed provider' ),
					'yes' => __( 'Keep pace and use the longer scheduling from FeedWordPress' ),
				),
				/*params=*/ array(
					'setting-default' => NULL,
					'global-setting-default' => 'no',
					'default-input-value' => 'default',
				)
			);
		?>
		</td>
		</tr>

		<?php if ($this->for_default_settings()) : ?>

		<tr>
		<th scope="row"><?php esc_html_e( 'Time limit on updates' ); ?>:</th>
		<td><select id="time-limit" name="update_time_limit" size="1" onchange="contextual_appearance( 'time-limit', 'time-limit-box', null, 'yes' );">
		<option value="no"<?php fwp_selected_flag( $update_time_limit <= 0 ); ?>><?php esc_html_e( 'no time limit on updates' ); ?></option>
		<option value="yes"<?php fwp_selected_flag( $update_time_limit > 0 ); ?>><?php esc_html_e( 'limit updates to no more than...' ); ?></option>
		</select>
		<span id="time-limit-box"><label><input type="text" name="time_limit_seconds" value="<?php print esc_attr( $update_time_limit ); ?>" size="5" /> <?php esc_html_e( 'seconds' ); ?></label></span>
		</tr>

		<?php endif; ?>

		</table>

		<?php
	} /* FeedWordPressFeedsPage::global_feeds_box() */

	/**
	 * Updates the window edit box.
	 *
	 * @param  int   $updateWindow  Minutes between polling.
	 * @param  bool  $defaulted     Unused.
	 * @param  array $params        Unused.
	 */
	function update_window_edit_box( $updateWindow, $defaulted, $params ) {
			if ( ! is_numeric( $updateWindow) ) :
				$updateWindow = DEFAULT_UPDATE_PERIOD;
			endif;
		?>
		<p><?php esc_html_e( 'Wait' ); ?> <input type="text" name="update_window" value="<?php print esc_attr( $updateWindow ); ?>" size="4" /> <?php esc_html_e( 'minutes between polling.' ); ?></p>
		<div class="setting-description" id="update-scheduling-note">
		<p<?php if ( $updateWindow < 50 ) : ?> style="color: white; background-color: #703030; padding: 1.0em;"<?php endif; ?>><?php esc_html_e( '<strong>Recommendation.</strong> Unless you are positive that you have the webmaster&rsquo;s permission, you generally should not set FeedWordPress to poll feeds more frequently than once every 60 minutes. Many webmasters consider more frequent automated polling to be abusive, and may complain to your web host, or ban your IP address, as retaliation for hammering their servers too hard.' ); ?></p>
		<p><?php esc_html_e( '<strong>Note.</strong> This is a default setting that FeedWordPress uses to schedule updates when the feed does not provide any scheduling requests. If this feed does provide update scheduling information (through elements such as <code>&lt;rss:ttl&gt;</code> or <code>&lt;sy:updateFrequency&gt;</code>), FeedWordPress will respect the feed&rsquo;s request.' ); ?></p>
		</div>
		<?php
	} /* FeedWordPressFeedsPage::update_window_edit_box() */

	/**
	 * Auxiliary function to update the window with current polling waiting period.
	 *
	 * @param  int   $updateWindow  Minutes between polling.
	 * @param  bool  $defaulted     Unused.
	 * @param  array $params        Unused.
	 */
	function update_window_currently( $updateWindow, $defaulted, $params ) {
		$updateWindow = (int) $updateWindow;
		if ( 1 == $updateWindow ) :
			$caption = __( 'wait %d minute between polling' );
		else :
			$caption = __( 'wait %d minutes between polling' );
		endif;
		return sprintf( $caption, $updateWindow );
	} /* FeedWordPressFeedsPage::update_window_currently() */

	/**
	 * Fetches the timeout setting.
	 *
	 * Nothing is returned, this just emits the HTML.
	 *
	 * @param  int   $setting     Unused.
	 * @param  bool  $defaulted   Unused.
	 * @param  array $params      Unused.
	 */
	function fetch_timeout_setting( $setting, $defaulted, $params ) {
		/** var int Fetch feed timeout in seconds. */
		$timeout = intval( $this->setting( 'fetch timeout', FEEDWORDPRESS_FETCH_TIMEOUT_DEFAULT ) );

		if ( $this->for_feed_settings() ) :
			$article = __( 'this' );
		else :
			$article = __( 'a' );
		endif;
		?>
		<p><?php esc_html_e( 'Wait no more than' ); ?> <input name="fetch_timeout" type="number" min="0" size="3" value="<?php print esc_attr( $timeout ); ?>" />
		<?php esc_html_e( 'second(s) when trying to fetch '); print esc_html( $article ); esc_html_e(' feed to check for updates. '); ?></p>
		<p><?php esc_html_e( 'If ' ); print esc_html( $article ); esc_html_e(' source&rsquo;s web server does not respond before time runs
		out, FeedWordPress will skip over the source and try again during the next update cycle.' ); ?></p>
		<?php
	} /* FeedWordPressFeedsPage::fetch_timeout_setting() */

	/**
	 * Properly formats the feed timeout setting, with plurals if required.
	 *
	 * Nothing is returned, this just emits the HTML.
	 *
	 * @param  int   $setting     Value for the feed timeout, in seconds.
	 * @param  bool  $defaulted   Unused.
	 * @param  array $params      Unused.
	 */
	function fetch_timeout_setting_value( $setting, $defaulted, $params ) {
		print number_format( intval( $setting) ) . " " . ( ( 1 == $setting ) ? "second" : "seconds" );
	}

	/**
	 * Displays the advanced settings box on the FWP dashboard page.
	 *
	 * @param  FeedWordPressFeedsPage  $page  A page object, unused.
	 * @param  mixed|null              $box   A box inside this object, unused.
	 */
	function advanced_settings_box( $page, $box = NULL ) {
		?>
		<table class="edit-form">
		<tr>
		<th><?php esc_html_e( 'Fetch Timeout:' ); ?></th>
		<td>
		<?php
		$this->setting_radio_control(
			'fetch timeout', 'fetch_timeout',
			array($this, 'fetch_timeout_setting'),
			array(
				'global-setting-default' => FEEDWORDPRESS_FETCH_TIMEOUT_DEFAULT,
				'input-name' => 'fetch_timeout',
				'default-input-name' => 'fetch_timeout_default',
				'labels' => array($this, 'fetch_timeout_setting_value'),
			)
		);
		?>
		</td>
		</tr>
		<tr>
		<th><?php esc_html_e( 'Feed Update Type:' ); ?></th>
		<td><?php
		$this->setting_radio_control( 'update_incremental', 'update_incremental',
			/*options=*/ array(
				'incremental' => __( '**Incremental.** When items no longer appear on the feed, keep them in the WordPress posts table.' ),
				'complete' => __( '**Complete.** When items no longer appear on the feed, they are obsolete; retire them from the WordPress posts table.' ),
			),
			/*params=*/ array(
				'setting-default' => NULL,
				'global-setting-default' => 'incremental',
				'default-input-value' => 'default',
			)
		); ?></td>
		</tr>
		<tr>
		<th><?php esc_html_e( 'Allow Feeds to Delete Posts:' ); ?></th>
		<td><?php
		$this->setting_radio_control(
			'tombstones',
			'tombstones',
			/*options=*/ array(
				'yes' => __( 'Yes. If a feed indicates that one of its posts has been deleted, delete the local copy syndicated to this website.' ),
				'no' => __( 'No. Even if a feed indicates that one of its posts has been deleted, retain the local copy on this website.' ),
			),
			/*params=*/ array(
				'setting-default' => NULL,
				'global-setting-default' => 'yes',
				'default-input-value' => 'default',
			)
		); ?></td>
		</tr>
		</table>
		<?php
	} /* FeedWordPressFeedsPage::advanced_settings_box() */

	/**
	 * Shows a box for authentication credentials for a feed.
	 *
	 * @param  array  $params  Array of parameters to parse.
	 */
	function display_authentication_credentials_box( $params = array() ) {
		static $count = 0;

		$params = wp_parse_args(
			$params,
			array(
				'username' => NULL,
				'password' => NULL,
				'method' => '-',
			),
		);

		// Equivalents
		if ( is_null( $params['method'] ) ) : $params['method'] = '-'; endif;

		$count++;
		/** var string Constructed slug for each feed credential. */
		$slug = ( $count > 1 ? '-' . $count : '' );

		global $feedwordpress;
		$authMethods = apply_filters(
			'feedwordpress_http_auth_methods',
			$feedwordpress->httpauth->methods_available(),
		);

		$linkRssAuthId        = sanitize_html_class( 'link-rss-authentication' . $slug );
		$linkRssCredentialsId = sanitize_html_class( 'link-rss-authentication-credentials' . $slug );
		$linkRssAuthMethodId  = sanitize_html_class( 'link-rss-authentication-method' . $slug );

		if ( count( $authMethods ) > 1 ) : /* More than '-' */
		?>
		<div class="link-rss-authentication" id="<?php print esc_attr( $linkRssAuthId ); ?>">
		<table>
		<tbody>
		<tr class="link-rss-authentication-credentials" id="<?php print esc_attr( $linkRssCredentialsId ); ?>">
		<td><label><?php esc_html_e( 'user:' ); ?> <input type="text" name="link_rss_username"
			value="<?php print esc_attr( $params['username'] ); ?>" size="16"
			placeholder="username to access this feed" /></label></td>
		<td><label><?php esc_html_e( 'pass:' ); ?> <input type="text" name="link_rss_password"
			value="<?php print esc_attr( $params['password'] ); ?>" size="16"
			placeholder=<?php esc_html_e( 'password to access this feed' ); ?> /></label></td>
		<td class="link-rss-authentication-method" id="<?php print esc_attr( $linkRssAuthMethodId ); ?>"><label><?php esc_html_e( 'method' ); ?>: <select class="link-rss-auth-method" id="link-rss-auth-method" name="link_rss_auth_method" size="1">
<?php foreach ( $authMethods as $value => $label ) : ?>
		  <option value="<?php print esc_attr( $value ); ?>"<?php
			fwp_selected_flag( $value == $params['method'] );
		  ?>><?php print esc_html( $label ); ?></option>
<?php endforeach; ?>
		</select></label></td>
		</tr>
		</tbody>
		</table>
		</div>

		<script type="text/javascript">
		jQuery('<td><a class="add-remove remove-it" id="link-rss-userpass-remove<?php print esc_attr( $slug ); ?>" href="#"><span class="x">(X)</span> <?php esc_html_e( 'Remove' ); ?></a></td>')
			.appendTo('#<?php print esc_attr( $linkRssCredentialsId ); ?>' )
			.click( feedAuthenticationMethodUnPress );
		jQuery( '#link-rss-auth-method<?php print esc_attr( $slug ); ?>' ).change( feedAuthenticationMethod );
		feedAuthenticationMethod( {
			init: true,
			node: jQuery( '#<?php print esc_attr( $linkRssAuthId ); ?>' )
		} );
		</script>

		<?php
		endif;
	} /* FeedWordPressFeedsPage::display_authentication_credentials_box() */

	/**
	 * Displays a box with information about a feed.
	 *
	 * @param  object     $page  Current page object (?).
	 * @param  mixed|null $box   A box inside this object.
	 *
	 * @uses get_categories()
	 * @uses get_category()
	 * @uses get_option()
	 * @uses maybe_unserialize()
	 * @uses FeedWordPress::link_category_id()
     */
	function feed_information_box( $page, $box = NULL ) {
		$link_rss_params = maybe_unserialize( $page->setting( 'query parameters', '' ) );
		if ( ! is_array( $link_rss_params ) ) :
			$link_rss_params = array();
		endif;

		if ( $page->for_feed_settings() ) :
			$info['name']        = esc_html( $page->link->link->link_name );
			$info['description'] = esc_html( $page->link->link->link_description );
			$info['url']         = esc_html( $page->link->link->link_url );
			$rss_url = $page->link->link->link_rss;

			$hardcode['name']        = $page->link->hardcode( 'name' );
			$hardcode['description'] = $page->link->hardcode( 'description' );
			$hardcode['url']         = $page->link->hardcode( 'url' );
		else :
			$cat_id = FeedWordPress::link_category_id();

			$params = array();
			$params['taxonomy']   = 'link_category';
			$params['hide_empty'] = false;
			$results = get_categories( $params );

			/** var bool Guarantee that the Contributors category will be in the drop-down chooser, even if it is empty. */
			$found_link_category_id = false;
			foreach ( $results as $row ) :
				// Normalize case
				if ( ! isset( $row->cat_id ) ) : $row->cat_id = $row->cat_ID; endif;

				if ( $row->cat_id == $cat_id) :	$found_link_category_id = true;	endif;
			endforeach;

			if ( ! $found_link_category_id ) :
				$results[] = get_category( $cat_id );
			endif;

			$info = array();
			$rss_url = null;

			$hardcode['name']        = get_option( 'feedwordpress_hardcode_name' );
			$hardcode['description'] = get_option( 'feedwordpress_hardcode_description' );
			$hardcode['url']         = get_option( 'feedwordpress_hardcode_url' );
		endif;

		/** @var bool  Hide authentication (unused). */
		$hideAuth = false;

		$username = $this->setting( 'http username', NULL );

		if ( is_null( $username ) ) :
			$username = '';
			$hideAuth = true;
		endif;

		$password = $this->setting( 'http password', NULL );
		if ( is_null( $password ) ) :
			$password = '';
		endif;

		$auth = $this->setting( 'http auth method', NULL );

		if ( is_null( $auth ) or ( strlen( $auth ) == 0 ) ) :
			$auth = '-';
			$hideAuth = true;
		endif;

		// Hey ho, let's go

		?>
		<table class="edit-form">

		<?php if ( $page->for_feed_settings() ) : ?>

		<tr>
		<th scope="row"><?php esc_html_e( 'Feed URL:' ) ?></th>
		<td><a href="<?php echo esc_html( $rss_url ); ?>"><?php echo esc_html( $rss_url ); ?></a>
		(<a href="<?php echo esc_url( FEEDVALIDATOR_URI ); ?>?url=<?php echo urlencode( $rss_url ); ?>"
		title="<?php esc_html_e( 'Check feed' ); ?> &lt;<?php echo esc_html( $rss_url ); ?>&gt; <?php esc_html_e( 'for validity' ); ?>">validate</a>)
		<input type="submit" name="feedfinder" value="switch &rarr;" style="font-size:smaller" />

		<?php $this->display_authentication_credentials_box( array(
			'username' => $username,
			'password' => $password,
			'method'   => $auth,
			)
		);
		?>
		<table id="link-rss-params">
		<tbody>
		<?php
		$link_rss_params['new'] = array( '', '' );
		$i = 0;
		foreach ( $link_rss_params as $index => $pair ) :
		?>
		<tr class="link-rss-params-row" id="link-rss-params-<?php print esc_attr($index); ?>">
		<td><label><?php esc_html_e( 'Parameter' ); ?>: <input type="text" class="link_params_key"
		name="link_rss_params_key[<?php print esc_attr( $index ); ?>]" value="<?php print esc_html( $pair[0] ); ?>"
		size="5" style="width: 5em" placeholder="name" /></label></td>
		<td class="link-rss-params-value-cell"><label class="link_params_value_label">= <input type="text" class="link_params_value"
		name="link_rss_params_value[<?php print esc_attr( $index ); ?>]" value="<?php print esc_html( $pair[1] ); ?>"
		size="8" placeholder="value" /></label></td>
		</tr>
		<?php
			$i++;
		endforeach;
		?>
		</tbody>
		</table>

		<div><input type="hidden" id="link-rss-params-num" name="link_rss_params_num" value="<?php print esc_attr($i); ?>" /></div>

		<script>
		function linkParamsRowRemove( element ) {
			jQuery( element ).closest( 'tr' ).fadeOut(
				'slow',
				function () {
					jQuery( this ).remove();
				}
			);
		}

		jQuery('<td><a href="#" class="add-remove link-rss-params-remove"><span class="x">(X)</span> <?php esc_html_e( 'Remove' ); ?></a></td>' ).insertAfter( '.link-rss-params-value-cell' );

		jQuery( '#link-rss-params-new' ).hide();
		jQuery( '<a class="add-remove" id="link-rss-params-add" href="#"><span class="dashicons dashicons-plus fwp-no-underline"></span> <?php esc_html_e( 'Add a query parameter' ); ?></a>').insertAfter( '#link-rss-params' );
		jQuery( '#link-rss-params-add' ).click( function () {
			var next = jQuery( '#link-rss-params-num' ).val();
			var newRow = jQuery( '#link-rss-params-new' ).clone().attr( 'id', 'link-rss-params-' + next );
			newRow.find( '.link_params_key' ).attr( 'name', 'link_rss_params_key[' + next + ']' );
			newRow.find( '.link_params_value' ).attr( 'name', 'link_rss_params_value[' + next + ']' );

			newRow.find( '.link-rss-params-remove' ).click(
				function () {
					linkParamsRowRemove( this );
					return false;
				}
			);

			newRow.appendTo( '#link-rss-params' );
			newRow.show();

			// Update counter for next row.
			next++;
			jQuery( '#link-rss-params-num' ).val( next );

			return false;
		} );
		jQuery( '.link-rss-params-remove' ).click( function () {
			linkParamsRowRemove( this );
			return false;
		} );
		</script>
		</td>
		</tr>

		<?php
		$rows = array(
			"name"        => __('Link Name'),
			"description" => __('Short Description'),
			"url"         => __('Homepage'),
		);
		foreach ( $rows as $what => $label ) :
			$hardcode_name    = sanitize_html_class( sprintf( 'hardcode_%s', $what ) );
			$hardcode_id      = sanitize_html_class( sprintf( 'basics-hardcode-%s', $what ) );
			$hardcode_view_id = sanitize_html_class( sprintf( 'basics-%s-view', $what ) );
			$hardcode_edit_id = sanitize_html_class( sprintf( 'basics-%s-edit', $what ) );
			?>
			<tr>
			<th scope="row"><?php print esc_html( $label ); ?></th>
			<td>
			<div id="<?php print esc_attr( $hardcode_edit_id ); ?>"><input type="text" name="link<?php print esc_attr( $what ); ?>"
			value="<?php echo esc_attr( $info[$what] ); ?>" style="width: 95%" /></div>
			<div id="<?php print esc_attr( $hardcode_view_id ); ?>">
			<?php if ( 'url' == $what ) : ?><a href="<?php print esc_url( $info[$what] ); ?>"><?php else : ?><strong><?php endif; ?>
			<?php print ( strlen( trim( $info[$what] ) ) > 0 ) ? esc_attr( $info[$what] ) : esc_attr__( '(none provided)' ); ?>
			<?php if ( 'url' == $what ) : ?></a><?php else : ?></strong><?php endif; ?></div>

			<div>
			<label><input id="<?php print esc_attr( $hardcode_id ); ?>"
				type="radio" name="<?php print esc_attr( $hardcode_name ); ?>" value="no"
				<?php echo ( ('yes' == $hardcode[$what] ) ? '' : ' checked="checked"');?>
				onchange="contextual_appearance( 'basics-hardcode-<?php print esc_attr( $what ); ?>', '<?php print esc_attr( $hardcode_view_id ); ?>', '<?php print esc_attr( $hardcode_edit_id ); ?>', 'no', 'block', /*checkbox=*/ true )"
			/> <?php esc_html_e( 'Update automatically from feed' ); ?></label>
			<label><input type="radio" name="<?php print esc_attr( $hardcode_name ); ?>" value="yes"
			<?php echo ( ( 'yes' != $hardcode[$what] ) ? '' : ' checked="checked"' );?>
			onchange="contextual_appearance('<?php print esc_attr( $hardcode_id ); ?>', '<?php print esc_attr( $hardcode_view_id ); ?>', '<?php print esc_attr( $hardcode_edit_id ); ?>', 'no', 'block', /*checkbox=*/ true)"
			/> <?php esc_html_e( 'Edit manually' ); ?></label>
			</div>
			</td>
			</tr>
			<?php
		endforeach;
		?>

		<?php else : ?>

		<tr>
		<th scope="row"><?php esc_html_e( 'Syndicated Link category:' ); ?></th>
		<td><p><select name="syndication_category" size="1">
		<?php
			foreach ( $results as $row ) :
				// Normalize case
				if ( ! isset( $row->cat_id ) ) : $row->cat_id = $row->cat_ID; endif;

				echo "\n\t" . '<option value="' . esc_attr( $row->cat_id ) . '"';
				if ( $row->cat_id == $cat_id)  :
					echo ' selected="selected"';
				endif;
				echo '>' . esc_attr( $row->cat_id ) . ': ' . esc_html( $row->cat_name );
				echo '</option>' . "\n";
			endforeach;
		?></select></p>
		<p class="setting-description"><?php esc_html_e( 'FeedWordPress will syndicate the
		links placed under this link category.' ); ?></p>
		</td>
		</tr>

		<tr>
		<th scope="row"><?php esc_html_e( 'Link Names:' ); ?></th>
		<td><label><input type="checkbox" name="hardcode_name" value="no"<?php echo ( ( 'yes' == $hardcode['name'] )? '' : ' checked="checked"' );?>/> <?php esc_html_e( 'Update contributor titles automatically when the feed title changes' ); ?></label></td>
		</tr>

		<tr>
		<th scope="row"><?php esc_html_e( 'Short descriptions:' ); ?></th>
		<td><label><input type="checkbox" name="hardcode_description" value="no"<?php echo ( ( 'yes' == $hardcode['description'] ) ? '' : ' checked="checked"' );?>/> <?php esc_html_e( 'Update contributor descriptions automatically when the feed tagline changes' ); ?></label></td>
		</tr>

		<tr>
		<th scope="row"><?php esc_html_e( 'Homepages:' ); ?></th>
		<td><label><input type="checkbox" name="hardcode_url" value="no"<?php echo ( ( 'yes' == $hardcode['url'] ) ? '' : ' checked="checked"' );?>/> <?php esc_html_e( 'Update contributor homepages automatically when the feed link changes' ); ?></label></td>
		</tr>

		<?php endif; ?>

		</table>
		<?php
	} /* FeedWordPressFeedsPage::feed_information_box() */


	function custom_settings_box( $page, $box = NULL ) {
		$postsSettings = $this->admin_page_href('posts-page.php', array(), $page->link);
		?>
	<p class="setting-description">These custom settings are special fields for the <strong>feed</strong> you are
	syndicating, to be retrieved in templates using the <code>get_feed_meta()</code> function. They do not create
	custom fields on syndicated <strong>posts</strong>. If you want to create custom fields that are applied to each
	individual post from this feed, set up the settings in <a href="<?php print esc_url($postsSettings); ?>">Syndicated Posts</a>.</p>
	<div id="postcustomstuff">
	<table id="meta-list" cellpadding="3">
		<tr>
		<th>Key</th>
		<th>Value</th>
		<th>Action</th>
		</tr>

	<?php
		$i = 0;
		foreach ($page->link->settings as $key => $value) :
			if ( !preg_match("\007^((".implode(')|(', $page->special_settings)."))$\007i", $key)) :
	?>
				<tr style="vertical-align:top">
				<th width="30%" scope="row"><input type="hidden" name="notes[<?php echo esc_attr($i); ?>][key0]" value="<?php echo esc_html($key); ?>" />
				<input id="notes-<?php echo esc_attr($i); ?>-key" name="notes[<?php echo esc_attr($i); ?>][key1]" value="<?php echo esc_html($key); ?>" /></th>
				<td width="60%"><textarea rows="2" cols="40" id="notes-<?php echo esc_attr($i); ?>-value" name="notes[<?php echo esc_attr($i); ?>][value]"><?php echo esc_html($value); ?></textarea></td>
				<td width="10%"><select name="notes[<?php echo esc_attr($i); ?>][action]">
				<option value="update">save changes</option>
				<option value="delete">delete this setting</option>
				</select></td>
				</tr>
	<?php
				$i++;
			endif;
		endforeach;
	?>
		<tr>
		<th scope="row"><input type="text" size="10" name="notes[<?php echo esc_attr( $i ); ?>][key1]" value="" /></th>
		<td><textarea name="notes[<?php echo esc_attr( $i ); ?>][value]" rows="2" cols="40"></textarea></td>
		<td><em>add new setting...</em><input type="hidden" name="notes[<?php echo esc_attr( $i ); ?>][action]" value="update" /></td>
		</tr>
	</table>
	</div> <!-- id="postcustomstuff" -->
		<?php
	}

	function url_for_401 ($err) {
		$ret = NULL;
		if (is_wp_error($err)) :
			if ($err->get_error_code()=='http_request_failed') :
				$data = $err->get_error_data('http_request_failed');

				if (is_array($data) and isset($data['status'])) :
					if (401==$data['status']) :
						$ret = $data['uri'];
					endif;
				endif;
			endif;
		endif;
		return $ret;
	}

	function display_feedfinder () {
		$lookup = FeedWordPress::param( 'lookup' );

		$auth = FeedWordPress::param( 'link_rss_auth_method' );
		$username = FeedWordPress::param( 'link_rss_username' );
		$password = FeedWordPress::param( 'link_rss_password' );
		$credentials = array(
				"authentication" => $auth,
				"username" => $username,
				"password" => $password,
		);

		$feeds = array(); $feedSwitch = false; $current = null;
		if ($this->for_feed_settings()) : // Existing feed?
			$feedSwitch = true;
			if (is_null($lookup)) :
				// Switch Feed without a specific feed yet suggested
				// Go to the human-readable homepage to look for
				// auto-detection links

				$lookup = $this->link->link->link_url;
				$auth = $this->link->setting('http auth method');
				$username = $this->link->setting('http username');
				$password = $this->link->setting('http password');

				// Guarantee that you at least have the option to
				// stick with what works.
				$current = $this->link->link->link_rss;
				$feeds[] = $current;
			endif;
			$name = $this->link->link->link_name;
		else: // Or a new subscription to add?
			$name = "Subscribe to ".feedwordpress_display_url($lookup);
		endif;
		?>
		<div class="wrap" id="feed-finder">
		<h2>Feed Finder: <?php echo esc_html($name); ?></h2>

		<?php
		if ($feedSwitch) :
			$this->display_alt_feed_box($lookup);
		endif;

		$finder = array();
		if ( !is_null($current)) :
			$finder[$current] = new FeedFinder($current);
		endif;
		$finder[$lookup] = new FeedFinder($lookup);

		foreach ($finder as $url => $ff) :
			$feeds = array_merge($feeds, $ff->find(
			/*url=*/ NULL,
			/*params=*/ $credentials));
		endforeach;

		$feeds = array_values( // Renumber from 0..(N-1)
			$feeds
		);

		// Allow for some simple FeedFinder results filtering
		$feeds = apply_filters(
			'feedwordpress_feedfinder_results',
			$feeds,
			array(
				"url" => $lookup,
				"auth" => $auth,
				"username" => $username,
				"password" => $password,
				"finder" => $finder,
			),
			$this
		);

		if (count($feeds) > 0):
			if ($feedSwitch) :
				?>
				<h3>Feeds Found</h3>
				<?php
			endif;

			if (count($feeds) > 1) :
				$option_template = 'Option %d: ';
				$form_class = 'multi';
				?>
				<p><strong>This web page provides at least <?php print count($feeds); ?> different feeds.</strong> These feeds may provide the same information
				in different formats, or may track different items. (You can check the Feed Information and the
				Sample Item for each feed to get an idea of what the feed provides.) Please select the feed that you'd like to subscribe to.</p>
				<?php
			else :
				$option_template = '';
				$form_class = '';
			endif;

			global $fwp_credentials;

			foreach ($feeds as $key => $f):
				$ofc = $fwp_credentials;
				$fwp_credentials = $credentials; // Set
				$pie = FeedWordPress::fetch($f, array("cache" => false));
				$fwp_credentials = $ofc; // Re-Set

				$rss = (is_wp_error($pie) ? $pie : new MagpieFromSimplePie($pie));

				if ($this->url_for_401($pie)) :
					$this->display_alt_feed_box($lookup, array(
						"err" => $pie,
						"auth" => $auth,
						"username" => $username,
						"password" => $password
					));
					continue;
				endif;

				if ($rss and !is_wp_error($rss)):
					$feed_link = (isset($rss->channel['link'])?$rss->channel['link']:'');
					$feed_title = (isset($rss->channel['title'])?$rss->channel['title']:$feed_link);
					$feed_type = ($rss->feed_type ? $rss->feed_type : 'Unknown');
					$feed_version_template = '%.1f';
					$feed_version = $rss->feed_version;
				else :
					// Give us some sucky defaults
					$feed_title = feedwordpress_display_url($lookup);
					$feed_link = $lookup;
					$feed_type = 'Unknown';
					$feed_version_template = '';
					$feed_version = '';
				endif;
				?>
					<form <?php fwp_form_class_attr($form_class); ?> action="<?php print esc_url( $this->form_action( 'syndication.php' ) ); ?>" method="post">
					<div class="inside"><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_switchfeed'); ?>

					<?php
					$classes = array('feed-found'); $currentFeed = '';
					if ( !is_null($current) and $current==$f) :
						$classes[] = 'current';
						$currentFeed = ' (currently subscribed)';
					endif;
					if ($key%2) :
						$classes[] = 'alt';
					endif;
					?>
					<fieldset class="<?php print esc_attr( implode(" ", $classes) ); ?>">
					<legend><?php print esc_html( sprintf( $option_template, ( $key + 1 ) ) ); print esc_html($feed_type) . " "; print esc_html( sprintf( $feed_version_template, $feed_version ) ); ?> feed<?php print esc_html( $currentFeed ); ?></legend>

					<?php
					$this->stamp_link_id();

					// No feed specified = add new feed; we
					// need to pass along a starting title
					// and homepage URL for the new Link.
					if ( ! $this->for_feed_settings()):
						?>
						<input type="hidden" name="feed_title" value="<?php echo esc_html($feed_title); ?>" />
						<input type="hidden" name="feed_link" value="<?php echo esc_html($feed_link); ?>" />
						<?php
					endif;
					?>

					<input type="hidden" name="feed" value="<?php echo esc_html($f); ?>" />
					<input type="hidden" name="action" value="switchfeed" />

					<div>
					<div class="feed-sample">
					<?php
					$link = null;
					$post = null;
					if ( !is_wp_error($rss) && !empty($rss->items) && is_array($rss->items) ):
						// Prepare to display Sample Item
						$link = new MagpieMockLink(array('simplepie' => $pie, 'magpie' => $rss), $f);
						$post = new SyndicatedPost(array('simplepie' => $rss->originals[0], 'magpie' => $rss->items[0]), $link);
						?>
						<h3>Sample Item</h3>
						<ul>
						<li><strong>Title:</strong> <a href="<?php echo esc_url( $post->post['meta']['syndication_permalink'] ); ?>"><?php echo esc_html( $post->post['post_title'] ); ?></a></li>
						<li><strong>Date:</strong> <?php print esc_html( date('d-M-y g:i:s a', $post->published() ) ); ?></li>
						</ul>
						<div class="entry">
						<?php print wp_kses( $post->post['post_content'], 'post' ); ?>
						</div>
						<?php
						do_action('feedwordpress_feed_finder_sample_item', $f, $post, $link);
					else:
						if (is_wp_error($rss)) :
							print '<div class="feed-problem">';
							print "<h3>Problem:</h3>\n";
							print "<p>FeedWordPress encountered the following error
							when trying to retrieve this feed:</p>";
							print '<p style="margin: 1.0em 3.0em"><code>' . esc_html( $rss->get_error_message() ) . '</code></p>';
							print "<p>If you think this is a temporary problem, you can still force FeedWordPress to add the subscription. FeedWordPress will not be able to find any syndicated posts until this problem is resolved.</p>";
							print "</div>";
						endif;
						?>
						<h3>No Items</h3>
						<p>FeedWordPress found no posts on this feed.</p>
						<?php
					endif;
					?>
					</div>

					<div>
					<h3>Feed Information</h3>
					<ul>
					<li><strong>Homepage:</strong> <a href="<?php echo esc_url( $feed_link ); ?>"><?php echo is_null( $feed_title )?'<em>Unknown</em>': esc_html( $feed_title ); ?></a></li>
					<li><strong>Feed URL:</strong> <a title="<?php echo esc_html( $f ); ?>" href="<?php echo esc_url( $f ); ?>"><?php echo esc_html( feedwordpress_display_url($f, 40, 10) ); ?></a> (<a title="Check feed &lt;<?php echo esc_html( $f ); ?>&gt; for validity" href="http://feedvalidator.org/check.cgi?url=<?php echo urlencode( $f ); ?>">validate</a>)</li>
					<li><strong>Encoding:</strong> <?php echo isset($rss->encoding) ? esc_html($rss->encoding) : "<em>Unknown</em>"; ?></li>
					<li><strong>Description:</strong> <?php echo isset($rss->channel['description']) ? esc_html($rss->channel['description']) : "<em>Unknown</em>"; ?></li>
					</ul>
					<?php $this->display_authentication_credentials_box(array(
					'username' => $username,
					'password' => $password,
					'method' => $auth,
					)); ?>
					<?php do_action('feedwordpress_feedfinder_form', $f, $post, $link, $this->for_feed_settings()); ?>
					<div class="submit"><input type="submit" class="button-primary" name="Use" value="&laquo; Use this feed" />
					<input type="submit" class="button" name="Cancel" value="× Cancel" /></div>
					</div>
					</div>
					</fieldset>
					</div> <!-- class="inside" -->
					</form>
					<?php
				unset($link);
				unset($post);
			endforeach;
		else:
			foreach ($finder as $url => $ff) :
				print '<h3>Searched for feeds at '. esc_html( $url ) . '</h3>' . "\n";
				print '<p><strong>' . esc_html__( 'Error' ) . ':</strong> ' . esc_html__( 'FeedWordPress couldn\'t find any feeds at' ).' <code><a href="' . esc_url( $lookup ) . '">' . esc_html( $lookup ) . '</a></code>';
				print '. ' . esc_html__( 'Try another URL' ) . '.</p>';

				// Diagnostics
				print "<div class=\"updated\" style=\"margin-left: 3.0em; margin-right: 3.0em;\">\n";
				print '<h3>' . esc_html__( 'Diagnostic information' ) . '</h3>' . "\n";
				if ( !is_null($ff->error()) and strlen($ff->error()) > 0) :
					print '<h4>'. esc_html__( 'HTTP request failure' ) . '</h4>' . "\n";
					print '<p>' . esc_html( $ff->error() ) ."</p>\n";
				else :
					print '<h4>' . esc_html__( 'HTTP request completed' ) . '</h4>' . "\n";
					print '<p><strong>Status ' . esc_html( $ff->status() ) . ':</strong> '. esc_html( $this->HTTPStatusMessages[ (int) $ff->status() ] ) ."</p>\n";
				endif;

				// Do some more diagnostics if the API for it is available.
				// Note to @radgeek: consider using a hook instead: https://developer.wordpress.org/reference/hooks/http_api_transports/ (gwyneth 20230920)
				if ( function_exists( '_wp_http_get_object' ) ) :
					$httpObject = _wp_http_get_object();

					/*
					// _getTransport() was totally deprecated (gwyneth 20230920)
					if (is_callable(array($httpObject, '_getTransport'))) :

						$transports = $httpObject->_getTransport();

						print "<h4>".__('HTTP Transports available').":</h4>\n";
						print "<ol>\n";
						print "<li>".implode("</li>\n<li>", array_map('get_class', $transports))."</li>\n";
						print "</ol>\n";
					else
					*/
					if ( is_callable( array( $httpObject, '_get_first_available_transport' ) ) ) :
						$transport = $httpObject->_get_first_available_transport(
							array(),
							$url
						);

						print "<h4>" . esc_html__("HTTP Transport") . ":</h4>\n";
						print "<ol>\n";
						print "<li>" . esc_html( MyPHP::val($transport) ) . "</li>\n";
						print "</ol>\n";
					endif;

					print "</div>\n";
				endif;
			endforeach;
		endif;

		if ( ! $feedSwitch) :
			$this->display_alt_feed_box($lookup, /*alt=*/ true);
		endif;
		?>
	</div> <!-- class="wrap" -->
		<?php
		return false; // Don't continue
	} /* FeedWordPressFeedsPage::display_feedfinder() */

	function display_alt_feed_box ($lookup, $params = false)
	{
		if (is_bool($params)) :
			$params = array("alt" => $params);
		endif;

		$params = wp_parse_args($params, array( // Defaults
		"alt" => false,
		"err" => NULL,
		"auth" => NULL,
		"password" => NULL,
		"username" => NULL,
		));
		$alt = $params['alt'];

		?>
		<form action="<?php print esc_url( $this->form_action('syndication.php') ); ?>" method="post">
		<div class="inside"><?php
			FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds');
		?>
		<fieldset class="alt"
		<?php if ( ! $alt): ?>style="margin: 1.0em 3.0em; font-size: smaller;"<?php endif; ?>>
		<legend><?php if ($alt) : ?>Alternative feeds<?php else: ?>Find feeds<?php endif; ?></legend>
		<?php if ($alt) : ?><h3>Use a different feed</h3><?php endif; ?>
		<?php if (is_wp_error($params['err'])) :
		?>
		<p><em><strong>401 Not Authorized.</strong> This URL may require
		a username and password to access it.</em> You may want to add login
		credentials below and check it again.</p>
		<?php
			endif;
		?>
		<div><label>Address:
		<input type="text" name="lookup" id="use-another-feed"
		placeholder="URL"
 		<?php if (is_null($lookup)) : ?>
			value="URL"
		<?php else : ?>
			value="<?php print esc_html($lookup); ?>"
		<?php endif; ?>
		size="64" style="max-width: 80%" /></label>
		<?php if (is_null($lookup)) : ?>
		<?php FeedWordPressSettingsUI::magic_input_tip_js('use-another-feed'); ?>
		<?php endif; ?>

		<?php $this->stamp_link_id('link_id'); ?>
		<input type="hidden" name="action" value="feedfinder" />
		<input type="submit" class="button<?php if ($alt): ?>-primary<?php endif; ?>" value="Check &raquo;" /></div>

		<?php $this->display_authentication_credentials_box(array(
		'username' => $params['username'],
		'password' => $params['password'],
		'method' => $params['auth'],
		)); ?>

		<p>This can be the address of a feed, or of a website. FeedWordPress
		will try to automatically detect any feeds associated with a
		website.</p>
		</div> <!-- class="inside" -->
		</fieldset></form>

		<?php
	} /* FeedWordPressFeedsPage::display_alt_feed_box() */

	function save_settings () {
		if ($this->for_feed_settings()) :
			$params_key = FeedWordPress::post( 'link_rss_params_key', array() );

			if ( is_array( $params_key ) && count($params_key) > 0 ) :
				$params_values = FeedWordPress::post( 'link_rss_params_value', array() );
				$qp = array();
				foreach ( $params_key as $index => $key ) :
					if (strlen($key) > 0) :

						if ( isset( $params_values[ $index ] ) ) :

							$params_value = $params_values[ $index ];
							if ( strlen($params_value) > 0 ) :
								$qp[] = array( $key, $params_value );
							endif;
						endif;
					endif;
				endforeach;
				$this->update_setting('query parameters', serialize($qp));

			endif;

			// custom feed settings first
			$param_notes = FeedWordPress::post( 'notes', array() );
			foreach ( $param_notes as $mn ) :
				$mn['key0'] = ( isset($mn['key0']) ? trim($mn['key0']) : null );
				$mn['key1'] = trim($mn['key1']);
				if (preg_match("\007^(("
						.implode(')|(',$this->special_settings)
						."))$\007i",
						$mn['key1'])) :
					$mn['key1'] = 'user/'.$mn['key1'];
				endif;

				if (strlen($mn['key0']) > 0) :
					unset($this->link->settings[$mn['key0']]); // out with the old
				endif;

				if (($mn['action']=='update') and (strlen($mn['key1']) > 0)) :
					$this->link->settings[$mn['key1']] = $mn['value']; // in with the new
				endif;
			endforeach;

			// now stuff through the web form
			// hardcoded feed info

			foreach (array('name', 'description', 'url') as $what) :
				// We have a checkbox for "No," so if it's unchecked, mark as "Yes."
				$this->link->settings["hardcode {$what}"] = FeedWordPress::post( "hardcode_{$what}", 'yes' );
				if (FeedWordPress::affirmative($this->link->settings, "hardcode {$what}")) :
					$this->link->link->{'link_'.$what} = FeedWordPress::post( 'link' . $what );
				endif;
			endforeach;

			// Update scheduling
			$update_schedule = FeedWordPress::post( 'update_schedule' );
			if ( ! is_null( $update_schedule ) ) :
				$this->link->settings['update/hold'] = $update_schedule;
			endif;

			$use_default_update_window = FeedWordPress::post( 'use_default_update_window' );
			$update_window = FeedWordPress::post( 'update_window' );
			if ( FeedWordPress::affirmative( $use_default_update_window ) ) :
				unset($this->link->settings['update/window']);
			elseif ( ! is_null( $update_window ) ) :
				$update_window = intval( $update_window );
				if ( $update_window > 0 ) :
					$this->link->settings['update/window'] = $update_window;
				endif;
			endif;

		else :
			// Global
			update_option('feedwordpress_cat_id', FeedWordPress::post( 'syndication_category' ) );

			$automatic_updates = FeedWordPress::post( 'automatic_updates' );
			if ( ! in_array( $automatic_updates, array( 'init', 'shutdown' ) ) ) :
				$automatic_updates = null;
			endif;
			update_option('feedwordpress_automatic_updates', $automatic_updates);

			$update_window = FeedWordPress::post( 'update_window' );
			if ( ! is_null( $update_window ) ) :
				$update_window = intval( $update_window );
				if ( $update_window > 0 ) :
					update_option( 'feedwordpress_update_window', $update_window );
				endif;
			endif;

			$update_time_limit = FeedWordPress::post( 'update_time_limit' );
			$time_limit_seconds = 0;
			if ( FeedWordPress::affirmative( $update_time_limit ) ) :
				$time_limit_seconds = intval( FeedWordPress::post( 'time_limit_seconds' ) );
			endif;
			update_option( 'feedwordpress_update_time_limit', $time_limit_seconds );

			foreach (array('name', 'description', 'url') as $what) :
				// We have a checkbox for "No," so if it's unchecked, mark as "Yes."
				$hardcode = FeedWordPress::post( "hardcode_{$what}", 'yes' );
				update_option( "feedwordpress_hardcode_{$what}", $hardcode );
			endforeach;

		endif;

		$update_pause = FeedWordPress::post( 'update_pause' );
		if ( ! is_null( $update_pause ) ) :
			$this->update_setting( 'update/pause', $update_pause );
		endif;

		$fetch_timeout = FeedWordPress::post( 'fetch_timeout' );
		if ( ! is_null( $fetch_timeout ) ) :
			$fetch_timeout_default = FeedWordPress::post( 'fetch_timeout_default' );
			if ( FeedWordPress::affirmative( $fetch_timeout_default ) ) :
				$fetch_timeout = null;
			endif;

			if ( is_int( $fetch_timeout ) ) :
				$fetch_timeout = intval( $fetch_timeout );
			endif;
			$this->update_setting( 'fetch timeout', $fetch_timeout );
		endif;

		$update_incremental = FeedWordPress::post( 'update_incremental' );
		if ( ! is_null( $update_incremental ) ) :
			$this->update_setting( 'update_incremental', $update_incremental );
		endif;

		$tombstones = FeedWordPress::post( 'tombstones' );
		if ( ! is_null( $tombstones ) ) :
			$this->update_setting('tombstones', $tombstones );
		endif;

		$update_minimum = FeedWordPress::post( 'update_minimum' );
		if ( ! is_null( $update_minimum ) ) :
			$this->update_setting( 'update/minimum', $update_minimum );
		endif;

		$link_rss_auth_method = FeedWordPress::post( 'link_rss_auth_method' );
		if (
			! $link_rss_auth_method
			|| '-' == $link_rss_auth_method
		) :
			$link_rss_auth_method = null;
		endif;
		$this->update_setting( 'http auth method', $link_rss_auth_method );

		$link_rss_username = FeedWordPress::post( 'link_rss_username' );
		if (
			! $link_rss_username
			|| is_null( $link_rss_auth_method )
		) :
			$link_rss_username = null;
		endif;
		$this->update_setting( 'http username', $link_rss_username );

		$link_rss_password = FeedWordPress::post( 'link_rss_password' );
		if (
			strlen( $link_rss_password ) == 0
			|| is_null( $link_rss_auth_method )
		) :
			$link_rss_password = null;
		endif;
		$this->update_setting( 'http password', $link_rss_password );

		$this->updatedPosts->accept_POST();

		parent::save_settings();
	} /* FeedWordPressFeedsPage::save_settings() */

} /* class FeedWordPressFeedsPage */

	$feedsPage = new FeedWordPressFeedsPage;
	$feedsPage->display();

