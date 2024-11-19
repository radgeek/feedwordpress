<?php
/**
 * feedwordpresssyndicationpage.class.php
 * feedwordpress
 *
 * @author radgeek
 */
require_once dirname(__FILE__) . '/admin-ui.php';
require_once dirname(__FILE__) . '/feedfinder.class.php';

################################################################################
## ADMIN MENU ADD-ONS: implement Dashboard management pages ####################
################################################################################

define( 'FWP_PROJECT_WEBSITE_URL', 'https://fwpplugin.com/' );

define( 'FWP_UPDATE_CHECKED',	'Update Checked' );
define( 'FWP_UNSUB_CHECKED',	'Unsubscribe' );
define( 'FWP_DELETE_CHECKED',	'Delete' );
define( 'FWP_RESUB_CHECKED',	'Re-subscribe' );
define( 'FWP_SYNDICATE_NEW',	'Add →' );
define( 'FWP_UNSUB_FULL',		'Unsubscribe from selected feeds →' );
define( 'FWP_CANCEL_BUTTON',	'× Cancel' );
define( 'FWP_CHECK_FOR_UPDATES', 'Update' );

/**
 * Tab for the admin page where syndication is dealt with.
 *
 * @extends FeedWordPressAdminPage
 *
 * @uses MyPHP
 * @uses FeedFinder
 * @uses FeedWordPress
 * @uses FeedWordPressCompatibility
 * @uses FeedWordPressDiagnostic
 */
class FeedWordPressSyndicationPage extends FeedWordPressAdminPage
{
	public function __construct( $filename = NULL )
	{
		parent::__construct( 'feedwordpresssyndication', /*link=*/ NULL );

		// No over-arching form element
		$this->dispatch = NULL;
		if ( is_null( $filename ) ) :
			$this->filename = __FILE__;
		else :
			$this->filename = $filename;
		endif;
	} /* FeedWordPressSyndicationPage constructor */

	/**
	 * Stub function to comply with parent class.
	 *
	 * @return bool Always returns FALSE in this class.
	 */
	function has_link()
	{
		return false;
	} /* FeedWordPressSyndicationPage::has_link() */

	/** @var array|null List of sources which gets initialised by $this->sources('Y') if it's still NULL */
	var $_sources = NULL;

	/**
	 * Builds _sources (list of visible or invisible links to sources of syndicated links)
	 * or returns existing _sources if it's already built.
	 *
	 * @param  string $visibility Unknown flag which toggles source visibility
	 *
	 * @return array Constructed list of visible/invisible sources
	 *
	 * @uses FeedWordPress::syndicated_links()
	 *
	 */
	function sources( $visibility = 'Y' )
	{
		if ( is_null( $this->_sources) ) :
			$links = FeedWordPress::syndicated_links( array( "hide_invisible" => false ) );
			$this->_sources = array( "Y" => array(), "N" => array() );
			foreach ( $links as $link ) :
				$this->_sources[$link->link_visible][] = $link;
			endforeach;
		endif;
		$ret = (
			array_key_exists( $visibility, $this->_sources )
			? $this->_sources[$visibility]
			: $this->_sources
		);
		return $ret;
	} /* FeedWordPressSyndicationPage::sources() */

	/**
	 * Toggles source visibility, using the side-effect of pseudo-getter $this->sources(...) method-
	 *
	 * @return string
	 *
	 * @uses FeedWordPress::param()
	 */
	function visibility_toggle()
	{
		$sources = $this->sources( '*' );	// return value unnecessary, it seems the code is just using the side-effect of initialising $this->_sources if it's uninitialised. (gwyneth 20230916)

		$defaultVisibility = 'Y';
		if ( ( count( $this->sources( 'N' ) )  > 0 )
		and  ( count( $this->sources( 'Y' ) ) == 0 ) ) :
			$defaultVisibility = 'N';
		endif;
		// this may be output into HTML, and it should really only ever be Y or N...
		$sVisibility = FeedWordPress::param( 'visibility', $defaultVisibility );
		$visibility = preg_replace( '/[^YyNn]+/', '', $sVisibility );

		return ( strlen( $visibility ) > 0 ? $visibility : $defaultVisibility );
	} /* FeedWordPressSyndicationPage::visibility_toggle() */

	/**
	 * Shows source feeds that are currently not visible.
	 *
	 * @return string
	 */
	function show_inactive()
	{
		return ( 'N' == $this->visibility_toggle() );
	}

	/**
	 * sanitize_ids: Protect id numbers from untrusted sources (POST array etc.)
	 * from possibility of SQLi attacks. Runs everything through an intval filter
	 * and then for good measure through esc_sql()
	 *
	 * @param array $link_ids An array of one or more putative link IDs
	 * @return array
	 */
	public function sanitize_ids_sql( $link_ids ) {
		$link_ids = array_map(
			'esc_sql',
			array_map(
				'intval',
				$link_ids
			)
		);
		return $link_ids;
	} /* FeedWordPressSyndicationPage::sanitize_ids_sql () */

	/**
	 * requested_link_ids_sql()
	 *
	 * @return string An SQL list literal containing the link IDs, sanitized
	 *                and escaped for direct use in MySQL queries.
	 *
	 * @uses sanitize_ids_sql()
	 * @uses sanitize_text_field()
	 * @uses MyPHP::post()
	 * @uses MyPHP::request()
	 * @uses FeedWordPress::post()
	 */
	public function requested_link_ids_sql()
	{
		// Multiple link IDs passed in link_ids[]=...

		$link_ids = array_map(
			'sanitize_text_field',
			(array) MyPHP::request( 'link_ids', array() )
		);

		// Or single in link_id=...
		if ( ! is_null( MyPHP::request( 'link_id' ) ) ) :
			array_push( $link_ids, sanitize_text_field( MyPHP::request( 'link_id' ) ) );
		endif;

		// Now use method to sanitize for safe use in MySQL queries.
		$link_ids = $this->sanitize_ids_sql( $link_ids );

		// Convert to MySQL list literal.
		return "('" . implode( "', '", $link_ids ) . "')";
	} /* FeedWordPressSyndicationPage::requested_link_ids_sql () */

	/**
	 * Returns the list of requested updates.
	 *
	 * @return array List of requested updates
	 *
	 * @uses MyPHP::post()
	 * @uses MyPHP::request()
	 * @uses FeedWordPress::post()
	 * @uses FeedWordPressDiagnostic::critical_bug()
	 */
	function updates_requested()
	{
		global $wpdb;

		if ( FeedWordPress::post( 'update' ) || FeedWordPress::post( 'action' ) || FeedWordPress::post( 'update_uri' ) ) :
			// Only do things with side-effects for HTTP POST or command line
			$fwp_update_invoke = 'post';
		else :
			$fwp_update_invoke = 'get';
		endif;

		$update_set = array();
		if ( $fwp_update_invoke != 'get' ) :
			if  ( is_array( MyPHP::post( 'link_ids' ) )
			and ( MyPHP::post( 'action' ) == FWP_UPDATE_CHECKED ) ) :
				// Get single link ID or multiple link IDs from REQUEST parameters
				// if available. Sanitize values for MySQL.
				$link_list = $this->requested_link_ids_sql();

				// $link_list has previously been sanitized for html by self::requested_link_ids_sql
				$targets = $wpdb->get_results("
				SELECT * FROM $wpdb->links
				WHERE link_id IN {$link_list}
				");
				if ( is_array( $targets ) ) :
					foreach ($targets as $target) :
						$update_set[] = $target->link_rss;
					endforeach;
				else : // This should never happen
					FeedWordPressDiagnostic::critical_bug( 'fwp_syndication_manage_page::targets', $targets, __LINE__, __FILE__ );
				endif;
			elseif ( !is_null( FeedWordPress::post( 'update_uri' ) ) ) :
				$targets = FeedWordPress::post( 'update_uri' );
				if ( !is_array( $targets ) ) :
					$targets = array( $targets );
				endif;

				$targets_keys = array_keys( $targets );
				$first_key = reset( $targets_keys );
				if ( !is_numeric( $first_key) ) : // URLs in keys
					$targets = $targets_keys;
				endif;
				$update_set = $targets;
			endif;
		endif;
		return $update_set;
	}

	/**
	 * Cancels the request.
	 *
	 * @return bool Success
	 *
	 * @uses FeedWordPress::post()
	 */
	public function cancel_requested()
	{
		$cancel = FeedWordPress::post( 'cancel' );
		return ( $cancel === __( FWP_CANCEL_BUTTON ) );
	}

	/**
	 * Adds multiple requests.
	 *
	 * @return bool Success
	 *
	 * @uses FeedWordPress::post()
	 */
	public function multiadd_requested()
	{
		$multiadd = FeedWordPress::post( 'multiadd' );
		return ( $multiadd === FWP_SYNDICATE_NEW );
	}

	/**
	 * Confirms that multiple requests were added.
	 *
	 * @return bool Success
	 *
	 * @uses FeedWordPress::post()
	 */
	public function multiadd_confirm_requested()
	{
		$confirm = FeedWordPress::post( 'confirm' );
		return ( $confirm === 'multiadd' );
	}

	/**
	 * Accepts multiple requests that were added.
	 *
	 * @return bool Always true
	 *
	 * @uses FeedWordPress::post()
	 * @uses FeedWordPress::syndicate_link()
	 * @uses FeedWordPressCompatibility::validate_http_request()
	 */
	function accept_multiadd()
	{
		if ( $this->cancel_requested() ) :
			return true; // Continue ....
		endif;

		// If this is a POST, validate source and user credentials
		FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_feeds', /*capability=*/ 'manage_links');

		$in = FeedWordPress::post( 'multilookup', '' )
			. FeedWordPress::post( 'opml_lookup', '' );
		if ( $this->multiadd_confirm_requested() ) :
			$chex = FeedWordPress::post( 'multilookup' );
			$added = array(); $errors = array();
			foreach ( $chex as $feed ) :
				if ( isset( $feed['add'] ) and $feed['add'] == 'yes' ) :
					// Then, add in the URL.
					$link_id = FeedWordPress::syndicate_link(
						$feed['title'],
						$feed['link'],
						$feed['url']
					);
					if ( !empty( $link_id ) and !is_wp_error( $link_id ) ):
						$added[] = $link_id;
					else :
						$errors[] = array( $feed['url'], $link_id );
					endif;
				endif;
			endforeach;

			print "<div class='updated'>\n";
			print "<p>Added " . count( $added ) . " new syndicated sources.</p>";
			if ( count( $errors ) > 0 ) :
				print "<p>FeedWordPress encountered errors trying to add the following sources:</p>
				<ul>\n";
				foreach ($errors as $err) :
					$url = $err[0];
					$short = feedwordpress_display_url($url);

					printf(
						'<li><a href="%s">%s</a>',
						esc_url( $url ),
						esc_html( $short )
					);

					if ( is_wp_error( $err[1] ) ) :
						$error = $err[1];
						printf( ' (<code>%s</code>)', esc_html( $error->get_error_messages() ) );
					endif;

					print "</li>\n";

				endforeach;
				print "</ul>\n";
			endif;
			print "</div>\n";

		elseif ( is_array( $in ) or strlen( $in ) > 0 ) :
			add_meta_box(
				/*id=*/ 'feedwordpress_multiadd_box',
				/*title=*/ __( 'Add Feeds' ),
				/*callback=*/ array( $this, 'multiadd_box' ),
				/*page=*/ $this->meta_box_context(),
				/*context =*/ $this->meta_box_context()
			);
		endif;
		return true; // Continue...
	}

	/**
	 * Emits HTML for multiple added lines.
	 *
	 * @param array $line	Line item to be displayed.
	 */
	function display_multiadd_line( $line )
	{
		$short_feed = feedwordpress_display_url( $line['feed'] );
		$feed  = $line['feed'];
		$link  = $line['link'];
		$title = $line['title'];
		$i = $line['i'];

		print "<li><label><input type='checkbox' name='multilookup[" . esc_attr( $i ) . "][add]' value='yes'";
		if ( strlen( $line['checked'] ) > 0 ) :
			print ' checked="checked" ';
		endif;
		print "/> " . esc_html( $title ) . "</label> &middot; <a href='"
			. esc_url($feed) . "'>" . esc_html( $short_feed ) . "</a>";

		if ( isset( $line['extra']) ) :
			print " &middot; " . esc_html( $line['extra'] );
		endif;

		print
			"<input type='hidden' name='multilookup[" . esc_attr( $i ) . "][url]' value='"   . esc_attr( $feed )  . "' />
			 <input type='hidden' name='multilookup[" . esc_attr( $i ) . "][link]' value='"  . esc_attr( $link )  . "' />
			 <input type='hidden' name='multilookup[" . esc_attr( $i ) . "][title]' value='" . esc_attr( $title ) . "' />
		</li>\n";

		flush();
	}

	/**
	 * Emits HTML for the box that allows adding multiple sources.
	 *
	 * @param  int 			$page	Unknown and unused.
	 * @param  string|null  $box    Unknown and unused.
	 *
	 * @return bool 		Always true
	 *
	 * @uses file_get_contents()
	 * @uses FeedFinder
	 * @uses FeedWordPress::fetch()
	 * @uses FeedWordPress::post()
	 * @uses FeedWordPressCompatibility::stamp_nonce()
	 */
	function multiadd_box($page, $box = NULL)
	{
		$localData = NULL;

		if  ( isset(  $_FILES['opml_upload']['name'] )
		and ( strlen( $_FILES['opml_upload']['name'] ) > 0 ) ) :
			$in = 'tag:localhost';

			/*FIXME: check whether $_FILES['opml_upload']['error'] === UPLOAD_ERR_OK or not...*/
			$localData = file_get_contents( $_FILES['opml_upload']['tmp_name'] );
			$merge_all = true;
		elseif ( ! is_null( FeedWordPress::post( 'multilookup' ) ) ) :
			$in = FeedWordPress::post( 'multilookup' );
			$merge_all = false;
		elseif ( ! is_null( FeedWordPress::post( 'opml_lookup' ) ) ) :
			$in = FeedWordPress::post( 'opml_lookup' );
			$merge_all = true;
		else :
			$in = '';
			$merge_all = false;
		endif;

		if ( strlen( $in ) > 0 ) :
			$lines = preg_split(
				"/\s+/",
				$in,
				/*no limit soldier*/ -1,
				PREG_SPLIT_NO_EMPTY
			);

			$i = 0;
			?>
			<!-- Page: <? echo $page; ?> Box: <? echo $box ?: '(empty)'; ?> -->
			<form id="multiadd-form" action="<?php print esc_attr( $this->form_action() ); ?>" method="post">
			<div><?php FeedWordPressCompatibility::stamp_nonce( 'feedwordpress_feeds' ); ?>
			<input type="hidden" name="multiadd" value="<?php print esc_attr( FWP_SYNDICATE_NEW ); ?>" />
			<input type="hidden" name="confirm" value="multiadd" />

			<input type="hidden" name="multiadd" value="<?php print esc_attr( FWP_SYNDICATE_NEW ); ?>" />
			<input type="hidden" name="confirm" value="multiadd" /></div>

			<div id="multiadd-status">
			<p><img src="<?php print esc_url( admin_url( 'images/wpspin_light.gif' ) ); ?>" alt="" />
			<?php esc_html_e( 'Looking up feed information...' ); ?></p>
			</div>

			<div id="multiadd-buttons">
			<input type="submit" class="button" name="cancel" value="<?php esc_html_e( FWP_CANCEL_BUTTON ); ?>" />
			<input type="submit" class="button-primary" value="<?php esc_html_e( 'Subscribe to selected sources →' ); ?>" />
			</div>

			<p><?php esc_html_e( 'Here are the feeds that FeedWordPress has discovered from the addresses that you provided. To opt out of a subscription, unmark the checkbox next to the feed.' ); ?></p>

			<?php
			print "<ul id=\"multiadd-list\">\n"; flush();
			foreach ( $lines as $line ) :
				$url = trim( $line );
				if ( strlen( $url ) > 0) :
					// First, use FeedFinder to check the URL.
					if ( is_null( $localData ) ) :
						$finder = new FeedFinder( $url, /*verify=*/ false, /*fallbacks=*/ 1 );
					else :
						$finder = new FeedFinder( 'tag:localhost', /*verify=*/ false, /*fallbacks=*/ 1 );
						$finder->upload_data( $localData );
					endif;

					$feeds = array_values(
						array_unique(
							$finder->find()
						)
					);

					$found = false;
					if ( count( $feeds ) > 0 ) :
						foreach ( $feeds as $feed ) :
							$pie = FeedWordPress::fetch( $feed );
							if ( !is_wp_error( $pie ) ) :
								$found = true;

								$this->display_multiadd_line(array(
								'feed' => $feed,
								'title' => $pie->get_title(),
								'link' => $pie->get_link(),
								'checked' => ' checked="checked"',
								'i' => $i,
								));

								$i++; // Increment field counter

								if ( ! $merge_all ) : // Break out after first find
									break;
								endif;
							endif;
						endforeach;
					endif;

					if ( ! $found ) :
						$this->display_multiadd_line( array(
							'feed' => $url,
							'title' => feedwordpress_display_url( $url ),
							'extra' => __(" [FeedWordPress couldn't detect any feeds for this URL.]" ),
							'link' => NULL,
							'checked' => '',
							'i' => $i,
						) );
						$i++; // Increment field counter
					endif;
				endif;
			endforeach;
			print "</ul>\n";
			?>
			</form>

			<script type="text/javascript">
				jQuery( document ).ready( function () {
					// Hide it now that we're done.
					jQuery( '#multiadd-status' ).fadeOut( 500 /*ms*/ );
				} );
			</script>
			<?php
		endif;

		$this->_sources = NULL; // Force reload of sources list
		return true; // Continue
	}

	/**
	 * Displays the main syndication page.
	 *
	 * @uses FeedWordPress::needs_upgrade()
	 * @uses FeedWordPress::param()
	 */
	function display()
	{
		if ( FeedWordPress::needs_upgrade() ) :
			fwp_upgrade_page();
			return;
		endif;

		$cont = true;
		$dispatcher = array(
			"feedfinder" => 'feedfinder_page',
			FWP_SYNDICATE_NEW => 'feedfinder_page',
			"switchfeed" => 'switchfeed_page',
			FWP_UNSUB_CHECKED => 'multidelete_page',
			FWP_DELETE_CHECKED => 'multidelete_page',
			'Unsubscribe' => 'multidelete_page',
			FWP_RESUB_CHECKED => 'multiundelete_page',
		);

		$act = FeedWordPress::param( 'action' );
		if ( isset( $dispatcher[ $act ] ) ) :
			$method = $dispatcher[ $act ];
			if ( method_exists( $this, $method ) ) :
				$cont = $this->{$method}();
			else :
				$cont = call_user_func( $method );
			endif;
		elseif ( $this->multiadd_requested() ) :
			$cont = $this->accept_multiadd();
		endif;

		if ( $cont ) :
			$links = $this->sources( 'Y' );	// side-effect of getting _sources instantiated... (gwyneth 20230916)
			$potential_updates = ( ! $this->show_inactive() and ( count( $this->sources( 'Y' ) ) > 0 ) );

			$this->open_sheet( 'Syndicated Sites' );
			?>
			<div id="post-body">
			<?php
			if ( $potential_updates
			or ( count( $this->updates_requested() ) > 0 ) ) :
				add_meta_box(
					/*id=*/ 'feedwordpress_update_box',
					/*title=*/ __( 'Update feeds now' ),
					/*callback=*/ 'fwp_syndication_manage_page_update_box',
					/*page=*/ $this->meta_box_context(),
					/*context =*/ $this->meta_box_context()
				);
			endif;
			add_meta_box(
				/*id=*/ 'feedwordpress_feeds_box',
				/*title=*/ __( 'Syndicated sources' ),
				/*callback=*/ array( $this, 'syndicated_sources_box' ),
				/*page=*/ $this->meta_box_context(),
				/*context =*/ $this->meta_box_context()
			);

			do_action( 'feedwordpress_admin_page_syndication_meta_boxes', $this );
		?>
			<div class="metabox-holder">
			<?php
				do_meta_boxes( $this->meta_box_context(), $this->meta_box_context(), $this );
			?>
			</div> <!-- class="metabox-holder" -->
			</div> <!-- id="post-body" -->

			<?php $this->close_sheet( /*dispatch=*/ NULL ); ?>

			<div style="display: none">
			<div id="tags-input"></div> <!-- avoid JS error from WP 2.5 bug -->
			</div>
		<?php
		endif;
	} /* FeedWordPressSyndicationPage::display () */

	/**
	 * Displays the dashboard box.
	 *
	 * @param  int			$page	Unknown usage.
	 * @param  array|null   $box    Unknown usage.
	 */
	function dashboard_box($page, $box = NULL)
	{
		$links = FeedWordPress::syndicated_links( array( "hide_invisible" => false ) );	// what is $links for? (gwyneth 20230916)
		$sources = $this->sources( '*' );	// uses side-effects to initialise _sources (gwyneth 20230916)

		/** @var string  what is this used for? (gwyneth 20230915) */
		$visibility   = 'Y';
		$hrefPrefix   = $this->form_action();
		$activeHref   = $hrefPrefix . '&visibility=' . $visibility;
		$inactiveHref = $hrefPrefix . '&visibility=N';

		$lastUpdate = get_option( 'feedwordpress_last_update_all', NULL );
		$automatic_updates = get_option( 'feedwordpress_automatic_updates', NULL );

		/** @var string  default value set here, to avoid having a else clause, but also to init the variable in the right scope. (gwyneth 20230915) */
		$update_setting = __( 'using a cron job or manual check-ins' );
		if ( 'init' == $automatic_updates ) :
			$update_setting = __( 'automatically before page loads' );
		elseif ( 'shutdown' == $automatic_updates ) :
			$update_setting = __( 'automatically after page loads' );
		endif;
		// Hey ho, let's go...
		?>
		<div style="float: left; background: /* #F5F5F5 */ white; padding-top: 5px; padding-right: 5px;"><a href="<?php print esc_url( $this->form_action() ); ?>"><img src="<?php print esc_url( plugins_url( /* "feedwordpress.png" */ "assets/images/icon.svg", __FILE__ ) ); ?>" width="36px" height="36px" alt="FeedWordPress Logo" /></a></div>
		<p class="info" style="margin-bottom: 0px; border-bottom: 1px dotted black;"><?php esc_html_e( 'Managed by' ); ?><a href="<?php print esc_url( FWP_PROJECT_WEBSITE_URL ); ?>">FeedWordPress</a>
		<?php print esc_html( FEEDWORDPRESS_VERSION ); ?>.</p>
		<?php if ( FEEDWORDPRESS_BLEG ) : ?>
		<p class="info" style="margin-top: 0px; font-style: italic; font-size: 75%; color: #666;"><?php esc_html_e( 'If you find this tool useful for your daily work, you can
		contribute to ongoing support and development with '); ?>
		<a href="<?php print esc_url( FWP_PROJECT_WEBSITE_URL ); ?>donate/"><?php esc_html_e('a modest donation'); ?></a>.</p>
		<br style="clear: left;" />
		<?php endif; ?>

		<div class="feedwordpress-actions">
		<h4>Updates</h4>
		<ul class="options">
		<li><strong><?php esc_html_e( 'Scheduled:' ); ?></strong> <?php print esc_html($update_setting); ?>
		(<a href="<?php print esc_url($this->form_action('feeds-page.php')); ?>"><?php esc_html_e( 'change setting' ); ?></a>)</li>

		<li><?php if ( !is_null($lastUpdate)) : ?>
		<strong><?php esc_html_e( 'Last checked:' );?></strong> <?php print esc_html(fwp_time_elapsed($lastUpdate)); ?>
		<?php else : ?>
		<strong><?php esc_html_e( 'Last checked:' );?>&nbsp;</strong><?php esc_html_e( 'none yet' ); ?>
		<?php endif; ?>	</li>

		</ul>
		</div>

		<div class="feedwordpress-stats">
		<h4><?php esc_html_e( 'Subscriptions' ); ?></h4>
		<table>
		<tbody>
		<tr class="first">
		<td class="first b b-active"><a href="<?php print esc_url($activeHref); ?>"><?php print esc_html(count($sources['Y'])); ?></a></td>
		<td class="t active"><a href="<?php print esc_url($activeHref); ?>"><?php esc_html_e( 'Active' ); ?></a></td>
		</tr>

		<tr>
		<td class="b b-inactive"><a href="<?php print esc_url($inactiveHref); ?>"><?php print esc_html(count($sources['N'])); ?></a></td>
		<td class="t inactive"><a href="<?php print esc_url($inactiveHref); ?>"><?php esc_html_e( 'Inactive' ); ?></a></td>
		</tr>
		</table>
		</div>

		<div id="add-single-uri">
			<?php if (count($sources['Y']) > 0) : ?>
			<form id="check-for-updates" action="<?php print esc_url( $this->form_action() ); ?>" method="POST">
			<div class="container"><input type="submit" class="button-primary" name"update" value="<?php print esc_attr(FWP_CHECK_FOR_UPDATES); ?>" />
			<?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?>
			<input type="hidden" name="update_uri" value="*" /></div>
			</form>
			<?php endif; ?>

			<form id="syndicated-links" action="<?php print esc_url( $this->form_action() ); // TODO: needs to be checked, because it doesn't seem to be defined properly (gwyneth 20230915) ?>" method="post">
			<div class="container"><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?>
			<label for="add-uri">Add:
			<input type="text" name="lookup" id="add-uri" placeholder="Source URL"
			value="Source URL" style="width: 55%;" /></label>

			<?php FeedWordPressSettingsUI::magic_input_tip_js('add-uri'); ?>
			<input type="hidden" name="action" value="<?php print esc_attr( FWP_SYNDICATE_NEW ); ?>" />
			<input style="vertical-align: middle;" type="image" src="<?php print esc_url(plugins_url('plus.png', __FILE__)); ?>" alt="<?php print esc_html(FWP_SYNDICATE_NEW); ?>" /></div>
			</form>
		</div> <!-- id="add-single-uri" -->

		<br style="clear: both;" />

		<?php
	} /* FeedWordPressSyndicationPage::dashboard_box () */

	/**
	 * One of the status boxes for the FWP dashboard.
	 *
	 * @param  mixed $page Unused
	 * @param  mixed|null $box Unused
	 *	 *
	 * @uses FeedWordPress::syndicated_links()
	 * @uses FeedWordPressCompatibility::stamp_nonce()
	 * @uses FeedWordPressSettingsUI::magic_input_tip_js()
	 */
	function syndicated_sources_box ($page, $box = NULL) {

		$links = FeedWordPress::syndicated_links(array("hide_invisible" => false));	// what is $links for? (gwyneth 20230916)
		$sources = $this->sources('*');

		$visibility = $this->visibility_toggle();
		$showInactive = $this->show_inactive();

		$hrefPrefix = $this->form_action();
		$formHref = sprintf( '%s&amp;visibility=%s', $hrefPrefix, urlencode($visibility) );
		?>
		<div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?></div>
		<div class="tablenav">

		<div id="add-multiple-uri" class="hide-if-js">
		<form action="<?php print esc_url( $formHref ); ?>" method="post">
		  <div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?></div>
		  <h4><?php esc_html_e( 'Add Multiple Sources' ); ?></h4>
		  <div><?php esc_html_e( 'Enter one feed or website URL per line. If a URL links to a website which provides multiple feeds, FeedWordPress will use the first one listed.' ); ?></div>
		  <div><textarea name="multilookup" rows="8" cols="60"
		  style="vertical-align: top"></textarea></div>
		  <div style="border-top: 1px dotted black; padding-top: 10px">
		  <div class="alignright"><input type="submit" class="button-primary" name="multiadd" value="<?php print esc_attr(FWP_SYNDICATE_NEW); ?>" /></div>
		  <div class="alignleft"><input type="button" class="button-secondary" name="action" value="<?php print esc_attr(FWP_CANCEL_BUTTON); ?>" id="turn-off-multiple-sources" /></div>
		  </div>
		</form>
		</div> <!-- id="add-multiple-uri" -->

		<div id="upload-opml" style="float: right" class="hide-if-js">
		<h4><?php esc_html_e( 'Import source list' ); ?></h4>
		<p><?php esc_html_e( 'You can import a list of sources in OPML format, either by providing
		a URL for the OPML document, or by uploading a copy from your
		computer.' ); ?></p>

		<form enctype="multipart/form-data" action="<?php print esc_url( $formHref ); ?>" method="post">
		  <div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?><input type="hidden" name="MAX_FILE_SIZE" value="100000" /></div>
		<div style="clear: both"><label for="opml-lookup" style="float: left; width: 8.0em; margin-top: 5px;"><?php esc_html_e( 'From URL:' ); ?></label> <input type="text" id="opml-lookup" name="opml_lookup" value="OPML document" /></div>
		<div style="clear: both"><label for="opml-upload" style="float: left; width: 8.0em; margin-top: 5px;"><?php esc_html_e( 'From file:' ); ?></label> <input type="file" id="opml-upload" name="opml_upload" /></div>

		<div style="border-top: 1px dotted black; padding-top: 10px">
		<div class="alignright"><input type="submit" class="button-primary" name="action" value="<?php print esc_html(FWP_SYNDICATE_NEW); ?>" /></div>
		<div class="alignleft"><input type="button" class="button-secondary" name="action" value="<?php print esc_html(FWP_CANCEL_BUTTON); ?>" id="turn-off-opml-upload" /></div>
		</div>
		</form>
		</div> <!-- id="upload-opml" -->

		<div id="add-single-uri" class="alignright">
		  <form id="syndicated-links" action="<?php print esc_url( $formHref ); ?>" method="post">
		  <div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?></div>
		  <ul class="subsubsub">
		  <li><label for="add-uri"><?php esc_html_e( 'New source:' ); ?></label>
		  <input type="text" name="lookup" id="add-uri" value="Website or feed URI" />

		  <?php FeedWordPressSettingsUI::magic_input_tip_js('add-uri'); FeedWordPressSettingsUI::magic_input_tip_js('opml-lookup'); ?>

		  <input type="hidden" name="action" value="feedfinder" />
		  <input type="submit" class="button-secondary" name="action" value="<?php print esc_html( FWP_SYNDICATE_NEW ); ?>" />
		  <div style="text-align: right; margin-right: 2.0em">
			<!-- Using WP Dashicon plus and down-arrow symbols below (gwyneth 20210717) -->
			<a id="turn-on-multiple-sources" href="#add-multiple-uri"><span class="dashicons feedwordpress-dashicons dashicons-list-view"></span>&nbsp;<?php esc_html_e( 'add multiple' ); ?></a>
			<span class="screen-reader-text"> or </span>
			<a id="turn-on-opml-upload" href="#upload-opml"><span class="dashicons feedwordpress-dashicons dashicons-upload"></span>&nbsp;<?php esc_html_e( 'import source list' ); ?></a>
		  </div>
		  </li>
		  </ul>
		  </form>
		</div> <!-- class="alignright" -->

		<div class="alignleft">
		<?php
		if (count($sources[$visibility]) > 0) :
			$this->manage_page_links_subsubsub($sources, $showInactive);
		endif;
		?>
		</div> <!-- class="alignleft" -->

		</div> <!-- class="tablenav" -->

		<form id="syndicated-links" action="<?php print esc_url( $formHref ); ?>" method="post">
		<div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?></div>

		<?php if ($showInactive) : ?>
		<div style="clear: right" class="alignright">
		<p style="font-size: smaller; font-style: italic"><?php esc_html_e( 'FeedWordPress used to syndicate
		posts from these sources, but you have unsubscribed from them.' ); ?></p>
		</div>
		<?php
		endif;
		?>

		<?php
		if (count($sources[$visibility]) > 0) :
			$this->display_button_bar($showInactive);
		else :
			$this->manage_page_links_subsubsub($sources, $showInactive);
		endif;

		fwp_syndication_manage_page_links_table_rows($sources[$visibility], $this, $visibility);
		$this->display_button_bar($showInactive);
		?>
		</form>
		<?php
	} /* FeedWordPressSyndicationPage::syndicated_sources_box() */

	/**
	 * Handles subpages on syndication dashboard (showing active/inactive feeds).
	 *
	 * @param  array $sources      List of feed URLs (active or inactive).
	 * @param  bool  $showInactive True if we're showing the inactive feeds.
	 *
	 */
	function manage_page_links_subsubsub( $sources, $showInactive ) {
		$hrefPrefix = $this->admin_page_href( "syndication.php" );
		$hrefY = sprintf( "%s&amp;visibility=%s", $hrefPrefix, "Y" );
		$hrefN = sprintf( "%s&amp;visibility=%s", $hrefPrefix, "N" );
?>
	<ul class="subsubsub">
	<li><a <?php if ( ! $showInactive ) : ?>class="current" <?php endif; ?>href="<?php print esc_url( $hrefY ); ?>"><?php esc_html_e( 'Subscribed' ); ?>
	<span class="count">(<?php print count( $sources['Y'] ); ?>)</span></a></li>
	<?php if ( $showInactive or ( count( $sources['N'] ) > 0 ) ) : ?>
	<li><a <?php if ( $showInactive ) : ?>class="current" <?php endif; ?>href="<?php print esc_url( $hrefN ); ?>"><?php esc_html_e( 'Inactive' ); ?></a>
	<span class="count">(<?php print count( $sources['N'] ); ?>)</span></a></li>
	<?php endif; ?>

	</ul> <!-- class="subsubsub" -->
<?php
	} /* FeedWordPressSyndicationPage::manage_page_links_subsubsub() */

	/**
	 * Displays the button bar showing options per feed.
	 *
	 * @param  bool  $showInactive  True if we're showing inactive feeds.
	 */
	function display_button_bar( $showInactive ) {
		?>
		<div style="clear: left" class="alignleft">
		<?php if ( $showInactive ) : ?>
		<input class="button-secondary" type="submit" name="action" value="<?php print esc_attr( FWP_RESUB_CHECKED ); ?>" />
		<input class="button-secondary" type="submit" name="action" value="<?php print esc_attr( FWP_DELETE_CHECKED ); ?>" />
		<?php else : ?>
		<input class="button-secondary" type="submit" name="action" value="<?php print esc_attr( FWP_UPDATE_CHECKED ); ?>" />
		<input class="button-secondary delete" type="submit" name="action" value="<?php print esc_attr( FWP_UNSUB_CHECKED ); ?>" />
		<?php endif ; ?>
		</div> <!-- class="alignleft" -->

		<br class="clear" />
		<?php
	}

	/**
	 * Displays page to thank user for donation.
	 *
	 * @param  mixed      $page Unused.
	 * @param  mixed|null $box  Unused.
	 */
	function bleg_thanks( $page, $box = NULL ) {
		?>
		<div class="donation-thanks">
		<h4><?php esc_html_e( 'Thank you!' ); ?></h4>
		<p><strong><?php esc_html_e( 'Thank you' ); ?></strong> <?php esc_html_e( ' for your contribution to '); ?>
		<a href="<?php print esc_url( FWP_PROJECT_WEBSITE_URL ); ?>"><?php esc_html_e( 'FeedWordPress development' ); ?></a>.
		<?php esc_html_e( 'Your generous gifts make ongoing support and development for
		FeedWordPress possible.' ); ?></p>
		<p><?php esc_html_e( 'If you have any questions about FeedWordPress, or if there
		is anything I can do to help make FeedWordPress more useful for
		you, please '); ?><a href="<?php print esc_url( FWP_PROJECT_WEBSITE_URL ); ?>contact"><?php esc_html_e( 'contact me' ); ?></a>
		<?php esc_html_e(' and let me know what you&rsquo;re thinking about.' ); ?></p>
		<p class="signature">&mdash;<a href="<?php print esc_url( FWP_PROJECT_WEBSITE_URL ); ?>">Charles Johnson</a>, <?php esc_html_e(' Developer' ); ?>, <a href="<?php print esc_url( FWP_PROJECT_WEBSITE_URL ); ?>">FeedWordPress</a>.</p>
		</div>
		<?php
	} /* FeedWordPressSyndicationPage::bleg_thanks () */

	/**
	 * Displays a donation form.
	 *
	 * @note Flattr unfortunately changed their business model :-(
	 * (gwyneth 20230917)
	 *
	 * @param  mixed      $page Unused.
	 * @param  mixed|null $box  Unused.
	 */
	function bleg_box ($page, $box = NULL) {
		?>
<div class="donation-form">
<h4><?php esc_html_e( 'Consider a Donation to FeedWordPress' ); ?></h4>
<form action="https://www.paypal.com/cgi-bin/webscr" accept-charset="UTF-8" method="post"><div>
<p><a href="<?php print esc_url( FWP_PROJECT_WEBSITE_URL ); ?>">FeedWordPress</a> <?php esc_html_e( 'makes syndication
simple and empowers you to stream content from all over the web into your
WordPress hub. If you&rsquo;re finding FWP useful, ' ); ?>
<a href="<?php print esc_url( FWP_PROJECT_WEBSITE_URL ); ?>donate/"><?php esc_html_e( 'a modest gift' ); ?></a>
<?php esc_html_e( ' is the best way to support steady progress on development, enhancements,
support, and documentation.' ); ?></p>

<div class="donate" style="vertical-align: middle">

<div id="flattr-paypal">

<div class="hovered-component" style="display: inline-block; vertical-align: bottom">
<a href="bitcoin:<?php print esc_attr( FEEDWORDPRESS_BLEG_BTC ); ?>"><img src="<?php print esc_url( plugins_url('/'.FeedWordPress::path('assets/images/btc-qr-128px.png') ) ); ?>" alt="<?php esc_html_e( 'Donate' ); ?>" /></a>
<div><a href="bitcoin:<?php print esc_attr( FEEDWORDPRESS_BLEG_BTC ); ?>"><?php esc_html_e( 'via' ); ?> bitcoin<span class="hover-on pop-over" style="background-color: #ddffdd; padding: 5px; color: black; border-radius: 5px;">bitcoin:<?php print esc_html( FEEDWORDPRESS_BLEG_BTC ); ?></span></a></div>
</div>

<div style="display: inline-block; vertical-align: bottom">
<input type="image" name="submit" src="<?php print esc_url( plugins_url( '/' . FeedWordPress::path('assets/images/paypal-donation-64px.png' ) ) ); ?>" style="width: 128px; height: 128px;" alt="<?php esc_html_e( 'Donate via PayPal' ); ?>" />
<input type="hidden" name="business" value="<?php print esc_attr( FEEDWORDPRESS_BLEG_PAYPAL ); ?>"  />
<input type="hidden" name="cmd" value="_xclick"  />
<input type="hidden" name="item_name" value="<?php esc_html_e( 'FeedWordPress donation' ); ?>"  />
<input type="hidden" name="no_shipping" value="1"  />
<input type="hidden" name="return" value="<?php print esc_attr( $this->admin_page_href( basename( $this->filename ), array( 'paid' => 'yes' ) ) ); ?>"  />
<input type="hidden" name="currency_code" value="USD" />
<input type="hidden" name="notify_url" value="<?php print esc_url( FWP_PROJECT_WEBSITE_URL ); ?>/ipn/donation"  />
<input type="hidden" name="custom" value="1"  />
<div><?php esc_html_e( 'via PayPal' ); ?></div>
</div> <!-- style="display: inline-block" -->

</div> <!-- id="flattr-paypal" -->
</div> <!-- class="donate" -->

</div> <!-- class="donation-form" -->
</form>

<p><?php esc_html_e( 'You can make a gift online (or ' ); ?><a href="<?php print esc_url( FWP_PROJECT_WEBSITE_URL ) ;?>donation"><?php esc_html_e( 'set up an automatic
regular donation' ); ?></a><?php esc_html_e( ' using an existing PayPal account or any major credit card.' ); ?></p>

<div class="sod-off">
<form style="text-align: center" action="<?php print esc_url( $this->form_action() ); ?>" method="POST"><div>
<input class="button" type="submit" name="maybe_later" value="<?php esc_attr_e( 'Maybe Later' ); ?>"/>
<input class="button" type="submit" name="go_away" value="<?php esc_attr_e( 'Dismiss' ); ?>"/>
</div></form>
</div>
</div> <!-- class="donation-form" -->
		<?php
	} /* FeedWordPressSyndicationPage::bleg_box() */

	/**
	 * Override the default display of a save-settings button and replace
	 * it with nothing.
	 */
	function interstitial() {
		/* NOOP */
	} /* FeedWordPressSyndicationPage::interstitial() */

	function multidelete_page() {
		global $wpdb;

		// If this is a POST, validate source and user credentials
		FeedWordPressCompatibility::validate_http_request( /*action=*/ 'feedwordpress_feeds', /*capability=*/ 'manage_links' );

		if ( MyPHP::post( 'submit' ) == FWP_CANCEL_BUTTON ) :
			return true; // Continue without further ado.
		endif;

		// Get single link ID or multiple link IDs from REQUEST parameters
		// if available. Sanitize values for MySQL.
		$link_list = $this->requested_link_ids_sql();

		if (MyPHP::post('confirm')=='Delete'):
			$actions = array();	// avoids "else" complaint _and_ guarantees that we don't have any scoping issues (gwyneth 20230916)
			if ( is_array(MyPHP::post('link_action')) ) :
				$actions = MyPHP::post('link_action');
			endif;

			$do_it = array(
				'hide' => array(),
				'nuke' => array(),
				'delete' => array(),
			);

			foreach ($actions as $link_id => $what) :
				$do_it[$what][] = $link_id;
			endforeach;

			$alter = array();
			if (count($do_it['hide']) > 0) :
				$hidem = "(".implode(', ', $do_it['hide']).")";
				$alter[] = "
				UPDATE $wpdb->links
				SET link_visible = 'N'
				WHERE link_id IN {$hidem}
				";
			endif;

			if (count($do_it['nuke']) > 0) :
				$nukem = "(".implode(', ', $do_it['nuke']).")";

				// Make a list of the items syndicated from this feed...
				$post_ids = $wpdb->get_col("
					SELECT post_id FROM $wpdb->postmeta
					WHERE meta_key = 'syndication_feed_id'
					AND meta_value IN {$nukem}
				");

				// ... and kill them all
				if (count($post_ids) > 0) :
					foreach ($post_ids as $post_id) :
						// Force scrubbing of deleted post
						// rather than sending to Trashcan
						wp_delete_post(
							/*postid=*/ $post_id,
							/*force_delete=*/ true
						);
					endforeach;
				endif;

				$alter[] = "
				DELETE FROM $wpdb->links
				WHERE link_id IN {$nukem}
				";
			endif;

			if (count($do_it['delete']) > 0) :
				$deletem = "(".implode(', ', $do_it['delete']).")";

				// Make the items syndicated from this feed appear to be locally-authored
				$alter[] = "
					DELETE FROM $wpdb->postmeta
					WHERE meta_key = 'syndication_feed_id'
					AND meta_value IN {$deletem}
				";

				// ... and delete the links themselves.
				$alter[] = "
				DELETE FROM $wpdb->links
				WHERE link_id IN {$deletem}
				";
			endif;

			$errs = array();
			foreach ($alter as $sql) :
				$result = $wpdb->query($sql);
				if ( ! $result):
					$errs[] = $wpdb->last_error;
				endif;
			endforeach;

			if (count($alter) > 0) :
				echo "<div class=\"updated\">\n";
				if (count($errs) > 0) :
					echo "There were some problems processing your unsubscribe request. [SQL: ";
					$sep = '';
					foreach ( $errs as $err ) :
						print esc_html($sep);
						print esc_html($err);
						$sep = '; ';
					endforeach;
					echo "]";
				else :
					echo "Your unsubscribe request(s) have been processed.";
				endif;
				echo "</div>\n";
			endif;

			return true; // Continue on to Syndicated Sites listing
		else :
			// $link_list has previously been sanitized for html by self::requested_link_ids_sql
			$targets = $wpdb->get_results("
				SELECT * FROM $wpdb->links
				WHERE link_id IN {$link_list}
				");
	?>
	<form action="<?php print esc_url( $this->form_action() ); ?>" method="post">
	<div class="wrap">
	<?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?>
	<input type="hidden" name="action" value="Unsubscribe" />
	<input type="hidden" name="confirm" value="Delete" />

	<h2><?php esc_html_e( 'Unsubscribe from Syndicated Links:' ); ?></h2>
	<?php	foreach ($targets as $link) :
			$subscribed = ('Y' == strtoupper($link->link_visible));
	?>
	<fieldset>
	<legend><?php echo esc_html($link->link_name); ?></legend>
	<table class="editform" width="100%" cellspacing="2" cellpadding="5">
	<tr><th scope="row" width="20%"><?php esc_html_e( 'Feed URI:' ) ?></th>
	<td width="80%"><a href="<?php echo esc_url($link->link_rss); ?>"><?php echo esc_html( $link->link_rss ); ?></a></td></tr>
	<tr><th scope="row" width="20%"><?php esc_html_e( 'Short description:' ) ?></th>
	<td width="80%"><?php echo esc_html( $link->link_description ); ?></span></td></tr>
	<tr><th width="20%" scope="row"><?php esc_html_e( 'Homepage:' ) ?></th>
	<td width="80%"><a href="<?php echo esc_url($link->link_url); ?>"><?php echo esc_html( $link->link_url ); ?></a></td></tr>
	<tr style="vertical-align:top"><th width="20%" scope="row"><?php esc_html_e( 'Subscription ' ); ?><?php esc_html_e( 'Options' ); ?>:</th>
	<td width="80%"><ul style="margin:0; padding: 0; list-style: none">
	<?php if ($subscribed) : ?>
	<li><input type="radio" id="hide-<?php echo esc_attr($link->link_id); ?>"
	name="link_action[<?php echo esc_attr($link->link_id); ?>]" value="hide" checked="checked" />
	<label for="hide-<?php echo esc_attr($link->link_id); ?>"><?php esc_html_e( 'Turn off the subscription for this
	syndicated link<br/><span style="font-size:smaller">(Keep the feed information
	and all the posts from this feed in the database, but don&rsquo;t syndicate any
	new posts from the feed.)' ); ?></span></label></li>
	<?php endif; ?>
	<li><input type="radio" id="nuke-<?php echo esc_attr($link->link_id); ?>"<?php if ( ! $subscribed) : ?> checked="checked"<?php endif; ?>
	name="link_action[<?php echo esc_attr($link->link_id); ?>]" value="nuke" />
	<label for="nuke-<?php echo esc_attr($link->link_id); ?>"><?php esc_html_e( 'Delete this syndicated link and all the
	posts that were syndicated from it' ); ?></label></li>
	<li><input type="radio" id="delete-<?php echo esc_attr($link->link_id); ?>"
	name="link_action[<?php echo esc_attr($link->link_id); ?>]" value="delete" />
	<label for="delete-<?php echo esc_attr($link->link_id); ?>"><?php esc_html_e( 'Delete this syndicated link, but
	<em>keep</em> posts that were syndicated from it (as if they were authored
	locally).' ); ?></label></li>
	<li><input type="radio" id="nothing-<?php echo esc_attr( $link->link_id ); ?>"
	name="link_action[<?php echo esc_attr( $link->link_id ); ?>]" value="nothing" />
	<label for="nothing-<?php echo esc_attr( $link->link_id ); ?>"><?php esc_html_e( 'Keep this feed as it is. I changed
	my mind.' ); ?></label></li>
	</ul>
	</table>
	</fieldset>
	<?php	endforeach; ?>

	<div class="submit">
	<input type="submit" name="submit" value="<?php esc_html_e( FWP_CANCEL_BUTTON ); ?>" />
	<input class="delete" type="submit" name="submit" value="<?php esc_html_e( FWP_UNSUB_FULL ) ?>" />
	</div>
	</div>
	<?php
			return false; // Don't continue on to Syndicated Sites listing
		endif;
	} /* FeedWordPressSyndicationPage::multidelete_page() */

	function multiundelete_page () {
		global $wpdb;

		// If this is a POST, validate source and user credentials
		FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_feeds', /*capability=*/ 'manage_links');

		// Get single link ID or multiple link IDs from REQUEST parameters
		// if available. Sanitize values for MySQL.
		$link_list = $this->requested_link_ids_sql();

		if (MyPHP::post('confirm')=='Undelete'):
			if ( is_array(MyPHP::post('link_action')) ) :
				$actions = MyPHP::post('link_action');
			else :
				$actions = array();
			endif;

			$do_it = array(
				'unhide' => array(),
			);

			foreach ($actions as $link_id => $what) :
				$do_it[$what][] = $link_id;
			endforeach;

			$alter = array();
			if (count($do_it['unhide']) > 0) :
				$unhiddem = "(".implode(', ', $do_it['unhide']).")";
				$alter[] = "
				UPDATE $wpdb->links
				SET link_visible = 'Y'
				WHERE link_id IN {$unhiddem}
				";
			endif;

			$errs = array();
			foreach ($alter as $sql) :
				$result = $wpdb->query($sql);
				if ( ! $result):
					$errs[] = $wpdb->last_error;
				endif;
			endforeach;

			if (count($alter) > 0) :
				echo "<div class=\"updated\">\n";
				if (count($errs) > 0) :
					esc_html_e( 'There were some problems processing your re-subscribe request. ' );
					echo esc_html( "[SQL: ".implode('; ', $errs)."]" );
				else :
					esc_html_e( 'Your re-subscribe request(s) have been processed.' );
				endif;
				echo "</div>\n";
			endif;

			return true; // Continue on to Syndicated Sites listing
		else :
			// $link_list has previously been sanitized for html by self::requested_link_ids_sql
			$targets = $wpdb->get_results("
				SELECT * FROM $wpdb->links
				WHERE link_id IN {$link_list}
				");
	?>
	<form action="<?php print esc_url( $this->form_action() ); ?>" method="post">
	<div class="wrap">
	<?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?>
	<input type="hidden" name="action" value="<?php print esc_attr( FWP_RESUB_CHECKED ); ?>" />
	<input type="hidden" name="confirm" value="Undelete" />

	<h2><?php esc_html_e( 'Re-subscribe to Syndicated Links:' ); ?></h2>
	<?php
		foreach ($targets as $link) :
			$subscribed = ( 'Y' == strtoupper( $link->link_visible ) );
			if ( ! $subscribed ) :
	?>
	<fieldset>
	<legend><?php echo esc_html( $link->link_name ); ?></legend>
	<table class="editform" width="100%" cellspacing="2" cellpadding="5">
	<tr><th scope="row" width="20%"><?php esc_html_e( 'Feed URI:' ) ?></th>
	<td width="80%"><a href="<?php echo esc_url( $link->link_rss ); ?>"><?php echo esc_html( $link->link_rss ); ?></a></td></tr>
	<tr><th scope="row" width="20%"><?php esc_html_e( 'Short description:' ) ?></th>
	<td width="80%"><?php echo esc_html($link->link_description); ?></span></td></tr>
	<tr><th width="20%" scope="row"><?php esc_html_e( 'Homepage:' ) ?></th>
	<td width="80%"><a href="<?php echo esc_url($link->link_url); ?>"><?php echo esc_html($link->link_url); ?></a></td></tr>
	<tr style="vertical-align:top"><th width="20%" scope="row"><?php esc_html_e( 'Subscription' ); ?> <?php esc_html_e( 'Options' ); ?>:</th>
	<td width="80%"><ul style="margin:0; padding: 0; list-style: none">
	<li><input type="radio" id="unhide-<?php echo esc_attr($link->link_id); ?>"
	name="link_action[<?php echo esc_attr($link->link_id); ?>]" value="unhide" checked="checked" />
	<label for="unhide-<?php echo esc_attr($link->link_id); ?>"><?php esc_html_e( 'Turn back on the subscription
	for this syndication source.' ); ?></label></li>
	<li><input type="radio" id="nothing-<?php echo esc_attr($link->link_id); ?>"
	name="link_action[<?php echo esc_attr($link->link_id); ?>]" value="nothing" />
	<label for="nothing-<?php echo esc_attr($link->link_id); ?>"><?php esc_html_e( 'Leave this feed as it is.
	I changed my mind.' ); ?></label></li>
	</ul>
	</table>
	</fieldset>
	<?php
			endif;
		endforeach;
	?>

	<div class="submit">
	<input class="button-primary delete" type="submit" name="submit" value="<?php esc_html_e( 'Re-subscribe to selected feeds &raquo;' ) ?>" />
	</div>
	</div>
	<?php
			return false; // Don't continue on to Syndicated Sites listing
		endif;
	} /* FeedWordPressSyndicationPage::multiundelete_page() */

	public function switchfeed_page () {
		global $wpdb;

		// If this is a POST, validate source and user credentials
		FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_switchfeed', /*capability=*/ 'manage_links');

		$changed = false;
		if ( is_null( FeedWordPress::post( 'Cancel' ) ) ):
			$save_link_id = FeedWordPress::post( 'save_link_id' );

			if ( $save_link_id == '*' ) :
				$changed = true;

				$feed_title = FeedWordPress::post( 'feed_title' );
				$feed_link  = FeedWordPress::post( 'feed_link' );
				$feed       = FeedWordPress::post( 'feed' );

				$link_id = FeedWordPress::syndicate_link( $feed_title, $feed_link, $feed );
				if ($link_id):
					$existingLink = new SyndicatedLink($link_id);
					$adminPageHref = $this->admin_page_href( 'feeds-page.php', array( "link_id" => $link_id ) );
					?>
<div class="updated"><p><a href="<?php print esc_url($feed_link); ?>"><?php print esc_html($feed_title); ?></a>
<?php esc_html_e( 'has been added as a contributing site, using the feed at' ); ?>
&lt;<a href="<?php print esc_url( $feed ); ?>"><?php print esc_html( $feed ); ?></a>&gt;.
| <a href="<?php print esc_url( $adminPageHref ); ?>"><?php esc_html_e( 'Configure settings' ); ?></a>.</p></div>
					<?php
				else:
					?>
<div class="updated"><p><?php esc_html_e( 'There was a problem adding the feed.' ); ?> [SQL: <?php echo esc_html($wpdb->last_error); ?>]</p></div>
					<?php
				endif;
			elseif ( ! is_null( $save_link_id ) ):
				$feed         = FeedWordPress::post( 'feed' );
				$existingLink = new SyndicatedLink( $save_link_id );

				$changed = $existingLink->set_uri($feed);

				if ($changed):
					$home = $existingLink->homepage(/*from feed=*/ false);
					$name = $existingLink->name(/*from feed=*/ false);
					?>
<div class="updated"><p><?php esc_html_e( 'Feed for ' ); ?><a href="<?php echo esc_html($home); ?>"><?php echo esc_html($name); ?></a>
<?php esc_html_e( 'updated to ' ); ?>&lt;<a href="<?php echo esc_html( $feed ); ?>"><?php echo esc_html( $feed ); ?></a>&gt;.</p></div>
					<?php
				endif;
			endif;
		endif;

		if (isset($existingLink)) :
			$auth = FeedWordPress::post('link_rss_auth_method');
			if ( !is_null($auth) and (strlen($auth) > 0) and ($auth != '-')) :
				$existingLink->update_setting('http auth method', $auth);
				$existingLink->update_setting('http username',
					FeedWordPress::post('link_rss_username')
				);
				$existingLink->update_setting('http password',
					FeedWordPress::post('link_rss_password')
				);
			else :
				$existingLink->update_setting('http auth method', NULL);
				$existingLink->update_setting('http username', NULL);
				$existingLink->update_setting('http password', NULL);
			endif;
			do_action('feedwordpress_admin_switchfeed', FeedWordPress::post( 'feed' ), $existingLink);
			$existingLink->save_settings(/*reload=*/ true);
		endif;

		if ( ! $changed) :
			?>
	<div class="updated"><p><?php esc_html_e( 'Nothing was changed.' ); ?></p></div>
			<?php
		endif;
		return true; // Continue.
	}

	function feedfinder_page () {
		global $post_source;

		if ( FeedWordPress::post( 'opml_lookup' ) or isset( $_FILES['opml_upload'] ) ) :
			$this->accept_multiadd();
			return true;
		else :
			$post_source = 'feedwordpress_feeds';

			// With action=feedfinder, this goes directly to the feedfinder page
			include_once(dirname(__FILE__) . '/feeds-page.php');
			return false;
		endif;
	} /* function feedfinder_page () */

} /* class FeedWordPressSyndicationPage */

function fwp_dashboard_update_if_requested ($object) {
	global $crash_dt;

	$update_set = $object->updates_requested();

	if (count($update_set) > 0) :
		shuffle($update_set); // randomize order for load balancing purposes...

		$feedwordpress = new FeedWordPress;
		add_action('feedwordpress_check_feed', 'update_feeds_mention');
		add_action('feedwordpress_check_feed_complete', 'update_feeds_finish', 10, 3);

		$crash_ts = $feedwordpress->crash_ts();

		echo "<div class=\"update-results\">\n";
		echo "<ul>\n";
		$tdelta = NULL;
		foreach ($update_set as $uri) :
			if ( !is_null($crash_ts) and (time() > $crash_ts)) :
				echo "<li><p><strong>" . esc_html__( "Further updates postponed:" ) . "</strong> "
				. esc_html__( "update time limit of " ) . esc_html( $crash_dt ) . esc_html__( " second"
				. ( ( 1 == $crash_dt ) ? "" : "s" ) ) . esc_html__( " exceeded." ) . "</p></li>";
				break;
			endif;

			if ($uri == '*') : $uri = NULL; endif;
			$delta = $feedwordpress->update($uri, $crash_ts);
			if ( !is_null($delta)) :
				if (is_null($tdelta)) :
					$tdelta = $delta;
				else :
					$tdelta['new'] += $delta['new'];
					$tdelta['updated'] += $delta['updated'];
				endif;
			else :
				$display_uri = esc_html(feedwordpress_display_url($uri));
				$uri = esc_html($uri);
				echo '<li><p><strong>' . esc_html__( "Error:" ) . "</strong> ". esc_html__( 'There was a problem updating ' )
				. '<code><a href="' . esc_url( $uri ) . '">' . esc_html( $display_uri ) . '</a></code></p></li>' . "\n";
			endif;
		endforeach;
		echo "</ul>\n";

		if ( !is_null($tdelta)) :
			echo '<p><strong>'; esc_html_e( 'Update complete.' ); echo '</strong>'; print esc_html( fwp_update_set_results_message($delta) ); print '</p>';
			echo "\n"; flush();
		endif;
		echo "</div> <!-- class=\"updated\" -->\n";
	endif;
}

define('FEEDWORDPRESS_BLEG_MAYBE_LATER_OFFSET', (60 /*sec/min*/ * 60 /*min/hour*/ * 24 /*hour/day*/ * 31 /*days*/));
define('FEEDWORDPRESS_BLEG_ALREADY_PAID_OFFSET', (60 /*sec/min*/ * 60 /*min/hour*/ * 24 /*hour/day*/ * 183 /*days*/));
function fwp_syndication_manage_page_update_box ($object = NULL, $box = NULL) {
	$bleg_box_hidden = null;

	if ( FeedWordPress::post( 'maybe_later' ) ) :
		$bleg_box_hidden = time() + FEEDWORDPRESS_BLEG_MAYBE_LATER_OFFSET;
	elseif ( FeedWordPress::post( 'paid' ) )  :
		$bleg_box_hidden = time() + FEEDWORDPRESS_BLEG_ALREADY_PAID_OFFSET;
	elseif ( FeedWordPress::post( 'go_away' ) ) :
		$bleg_box_hidden = 'permanent';
	endif;

	if ( !is_null($bleg_box_hidden)) :
		update_option('feedwordpress_bleg_box_hidden', $bleg_box_hidden);
	else :
		$bleg_box_hidden = get_option('feedwordpress_bleg_box_hidden');
	endif;
?>
	<?php
	$bleg_box_ready = (FEEDWORDPRESS_BLEG and (
		! $bleg_box_hidden
		or (is_numeric($bleg_box_hidden) and $bleg_box_hidden < time())
	));

	$bleg_box_ready = apply_filters( 'feedwordpress_bleg_box_ready', $bleg_box_ready );
	if ( FeedWordPress::post( 'paid' ) || ( FeedWordPress::param( 'test' ) == 'thanks' ) ) :
		$object->bleg_thanks($object, $box);
	elseif ($bleg_box_ready || ( FeedWordPress::param( 'test' ) == 'bleg' ) ) :
		$object->bleg_box($object, $box);
	endif;
	?>

	<form
		action="<?php print esc_url( $object->form_action() ); ?>"
		method="POST"
		class="update-form<?php if ($bleg_box_ready) : ?> with-donation<?php endif; ?>"
	>
	<div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?></div>
	<p><?php esc_html_e( 'Check currently scheduled feeds for new and updated posts.' ); ?></p>

	<?php
	fwp_dashboard_update_if_requested($object);

	if ( !get_option('feedwordpress_automatic_updates')) :
	?>
		<p class="heads-up"><strong><?php esc_html_e( 'Note:' ); ?></strong> <?php esc_html_e( 'Automatic updates are currently turned
		<strong>off</strong>. New posts from your feeds will not be syndicated
		until you manually check for them here. You can turn on automatic
		updates under' ); ?> <a href="<?php print esc_url( $object->admin_page_href('feeds-page.php') ); ?>"><?php esc_html_e( 'Feed &amp; Update Settings' ); ?></a>.</p>
	<?php
	endif;
	?>

	<div class="submit"><?php if ($object->show_inactive()) : ?>
	<?php foreach ($object->updates_requested() as $req) : ?>
	<input type="hidden" name="update_uri[]" value="<?php print esc_html($req); ?>" />
	<?php endforeach; ?>
	<?php else : ?>
	<input type="hidden" name="update_uri" value="*" />
	<?php endif; ?>
	<input class="button-primary" type="submit" name="update" value="<?php esc_html_e( FWP_CHECK_FOR_UPDATES ); ?>" /></div>

	<br style="clear: both" />
	</form>
<?php
} /* function fwp_syndication_manage_page_update_box () */
