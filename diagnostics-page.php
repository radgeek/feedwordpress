<?php
require_once(dirname(__FILE__) . '/admin-ui.php');
require_once(dirname(__FILE__) . '/magpiemocklink.class.php');

class FeedWordPressDiagnosticsPage extends FeedWordPressAdminPage {
	public function __construct() {
		// Set meta-box context name
		parent::__construct('feedwordpressdiagnosticspage');
		$this->dispatch = 'feedwordpress_diagnostics';
		$this->filename = __FILE__;

		$this->test_html = array();
		add_action('feedwordpress_diagnostics_do_http_test', array($this, 'do_http_test'), 10, 1);
	}

	function has_link () { return false; }

	function display () {
		if (FeedWordPress::needs_upgrade()) :
			fwp_upgrade_page();
			return;
		endif;

		// If this is a POST, validate source and user credentials
		FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_diagnostics', /*capability=*/ 'manage_options');

		if (strtoupper($_SERVER['REQUEST_METHOD'])=='POST') :
			$this->accept_POST();
			do_action('feedwordpress_admin_page_diagnostics_save', null, $this);
		endif;

		////////////////////////////////////////////////
		// Prepare settings page ///////////////////////
		////////////////////////////////////////////////

		$this->display_update_notice_if_updated('Diagnostics');

		$this->open_sheet('FeedWordPress Diagnostics');
		?>
		<div id="post-body">
		<?php
		$boxes_by_methods = array(
			'info_box' => __('Diagnostic Information'),
			'diagnostics_box' => __('Display Diagnostics'),
			'updates_box' => __('Diagnostic Messages'),
			'tests_box' => __('Diagnostic Tests'),
		);

		foreach ($boxes_by_methods as $method => $title) :
			add_meta_box(
				/*id=*/ 'feedwordpress_'.$method,
				/*title=*/ $title,
				/*callback=*/ array('FeedWordPressDiagnosticsPage', $method),
				/*page=*/ $this->meta_box_context(),
				/*context=*/ $this->meta_box_context()
			);
		endforeach;
		do_action('feedwordpress_admin_page_diagnostics_meta_boxes', $this);
		?>
			<div class="metabox-holder">
			<?php
			do_meta_boxes($this->meta_box_context(), $this->meta_box_context(), $this);
			?>
			</div> <!-- class="metabox-holder" -->
		</div> <!-- id="post-body" -->

		<?php
		$this->close_sheet();
	} /* FeedWordPressDiagnosticsPage::display () */

	public function do_requested () {
		return ( ! is_null( self::what_requested() ) );
	}

	public function what_requested () {
		return FeedWordPress::post( 'feedwordpress_diagnostics_do' );
	}

	function accept_POST () {
		if ( self::save_requested() || self::do_requested() ) :

			update_option( 'feedwordpress_debug', FeedWordPress::post( 'feedwordpress_debug' ) );
			update_option( 'feedwordpress_secret_key', FeedWordPress::post( 'feedwordpress_secret_key' ) );

			update_option( 'feedwordpress_diagnostics_output', FeedWordPress::post( 'diagnostics_output' ) );
			update_option( 'feedwordpress_diagnostics_show', FeedWordPress::post( 'diagnostics_show' ) );

			if ( FeedWordPress::post( 'diagnostics_show' )
			and in_array( 'updated_feeds:errors:persistent', FeedWordPress::post( 'diagnostics_show' ) ) ) :
				update_option('feedwordpress_diagnostics_persistent_errors_hours', (int) FeedWordPress::post( 'diagnostics_persistent_error_hours' ) );
			else :
				delete_option( 'feedwordpress_diagnostics_persistent_errors_hours' );
			endif;

			if ( in_array( 'email', FeedWordPress::post( 'diagnostics_output' ) ) ) :
				$ded = FeedWordPress::post('diagnostics_email_destination' );
				if ( 'mailto' == $ded ) :
					$ded .= ':' . FeedWordPress::post('diagnostics_email_destination_address' );
				endif;

				update_option( 'feedwordpress_diagnostics_email_destination', $ded );
			else :
				delete_option( 'feedwordpress_diagnostics_email_destination' );
			endif;

			if ( self::do_requested() ) :
				foreach ( self::what_requested() as $do => $value) :
					do_action( 'feedwordpress_diagnostics_do_'.$do );
				endforeach;
			endif;

			$this->updated = true; // Default update message
		endif;
	} /* FeedWordPressDiagnosticsPage::accept_POST () */

	static function info_box ($page, $box = NULL) {
		global $feedwordpress;
		global $wp_version;
		$link_category_id = FeedWordPress::link_category_id();
	?>
		<table class="edit-form narrow">
		<thead style="display: none">
		<th scope="col">Topic</th>
		<th scope="col">Information</th>
		</thead>

		<tbody>
		<tr>
		<th scope="row"><?php esc_html_e( 'Version:' ); ?></th>
		<td><?php esc_html_e( 'You are using FeedWordPress version' ); ?> <strong><?php print esc_html( FEEDWORDPRESS_VERSION ); ?></strong>.</td>
		</tr>

		<tr>
		<th scope="row"><?php esc_html_e( 'Hosting Environment:' ); ?></th>
		<td><ul style="margin-top: 0; padding-top: 0;">
		<li><em>WordPress:</em> <?php esc_html_e( 'version' ); ?> <?php print esc_html( $wp_version ); ?></li>
		<li><em>SimplePie:</em> <?php esc_html_e( 'version' ); ?> <?php print esc_html( SIMPLEPIE_VERSION ); ?></li>
		<?php if ( function_exists( 'phpversion' ) ) : ?>
		<li><em><?php esc_html_e( 'PHP:' ); ?></em> <?php esc_html_e( 'version' ); ?> <?php print esc_html( phpversion() ); ?></li>
		<?php endif; ?>
		<?php if ( function_exists( 'apache_get_version' ) ) : ?>
		<li><em><?php esc_html_e( 'Web Server:' ); ?></em> <?php print esc_html( apache_get_version() ); ?></li>
		<?php endif; ?>
		<?php if ( ! empty( $_SERVER['SERVER_SIGNATURE'] ) ) : ?>
		<li><em><?php esc_html_e( 'Web Server signature:' ); ?></em> <?php print esc_html( $_SERVER['SERVER_SIGNATURE'] ); ?></li>
		<?php endif; ?>
		<li><em><?php esc_html_e( 'Hosted on:' ); ?></em> <?php print esc_html( php_uname( 'a' ) ); ?></li>
		</ul>
		</td>
		</tr>

		<tr>
		<th scope="row"><?php esc_html_e( 'Link Category:' ); ?></th>
		<td><?php if ( ! is_wp_error( $link_category_id ) ) :
			$term = get_term( $link_category_id, 'link_category' );
		?><p><?php esc_html_e( 'Syndicated feeds are kept in link category' ); ?> #<?php print esc_html( $term->term_id ); ?>, <strong><?php print esc_html( $term->name ); ?></strong>.</p>
		<?php else : ?>
		<p><strong><?php esc_html_e( 'FeedWordPress has been unable to set up a valid Link Category for syndicated feeds.' ); ?></strong> <?php esc_html_e( 'Attempting to set one up returned an' ); ?>
		<code><?php $link_category_id->get_error_code(); ?></code> <?php esc_html_e( 'error with this additional data:' ); ?></p>
		<table>
		<tbody>
		<tr>
		<th scope="row"><?php esc_html_e( 'Message:' ); ?></th>
		<td><?php print esc_html( $link_category_id->get_error_message() ); ?></td>
		</tr>
		<?php $data = $link_category_id->get_error_data(); if ( ! empty( $data ) ) : ?>
		<tr>
		<th scope="row"><?php esc_html_e( 'Auxiliary Data:' ); ?></th>
		<td><pre><?php print esc_html( MyPHP::val( $link_category_id->get_error_data() ) ); ?></pre></td>
		</tr>
		<?php endif; ?>
		</table>
		<?php endif; ?></td>
		</tr>

		<tr>
		<th scope="row"><?php esc_html_e('Secret Key:'); ?></th>
		<td><input type="text" name="feedwordpress_secret_key" value="<?php print esc_attr( $feedwordpress->secret_key() ); ?>" />
		<p class="setting-description"><?php esc_html_e( 'This is used to control access to some diagnostic testing functions. You can change it to any string you want, but only tell it to people you trust to help you troubleshoot your FeedWordPress installation. Keep it secret&mdash;keep it safe.' ); ?></p></td>
		</tr>
		</table>

		<?php
	} /* FeedWordPressDiagnosticsPage::info_box () */

	static function diagnostics_box( $page, $box = NULL ) {
		$settings = array();
		$settings['debug'] = ( get_option( 'feedwordpress_debug' ) == 'yes' );

		$diagnostics_output = get_option( 'feedwordpress_diagnostics_output', array() );
		if ( ! is_array( $diagnostics_output ) ) {
			$diagnostics_output = array( $diagnostics_output );
		}
		
		$users = fwp_author_list();

		$ded = get_option( 'feedwordpress_diagnostics_email_destination', 'admins' );

		if (preg_match( '/^mailto:(.*)$/', $ded, $ref ) ) :
			$ded_addy = $ref[1];
		else :
			$ded_addy = NULL;
		endif;

		// Hey ho, let's go...
		?>
<table class="edit-form">
<tr style="vertical-align: top">
<th scope="row"><?php esc_html_e( 'Debugging mode:' ); ?></th>
<td><select name="feedwordpress_debug" size="1">
<option value="yes"<?php echo ( $settings['debug'] ? ' selected="selected"' : '' ); ?>><?php esc_html_e( 'on' ); ?></option>
<option value="no"<?php echo ( $settings['debug'] ? '' : ' selected="selected"' ); ?>><?php esc_html_e( 'off' ); ?></option>
</select>

<p><?php esc_html_e( 'When debugging mode is <strong>ON</strong>, FeedWordPress displays many
diagnostic error messages, warnings, and notices that are ordinarily suppressed,
and turns off all caching of feeds. Use with caution: this setting is useful for
testing but absolutely inappropriate for a production server.' ); ?></p>

</td>
</tr>
<tr>
<th scope="row">Diagnostics output:</th>
<td><ul class="options">
<li><input type="checkbox" name="diagnostics_output[]" value="error_log" <?php print ( in_array( 'error_log', $diagnostics_output ) ? ' checked="checked"' : ''); ?> /> Log in PHP error logs</label></li>
<li><input type="checkbox" name="diagnostics_output[]" value="admin_footer" <?php print (in_array('admin_footer', $diagnostics_output) ? ' checked="checked"' : ''); ?> /> Display in WordPress admin footer</label></li>
<li><input type="checkbox" name="diagnostics_output[]" value="echo" <?php print (in_array('echo', $diagnostics_output) ? ' checked="checked"' : ''); ?> /> Echo in web browser as they are issued</label></li>
<li><input type="checkbox" name="diagnostics_output[]" value="echo_in_cronjob" <?php print (in_array('echo_in_cronjob', $diagnostics_output) ? ' checked="checked"' : ''); ?> /> Echo to output when they are issued during an update cron job</label></li>
<li><input type="checkbox" name="diagnostics_output[]" value="email" <?php print (in_array('email', $diagnostics_output) ? ' checked="checked"' : ''); ?> /> Send a daily email digest to:</label> <select name="diagnostics_email_destination" id="diagnostics-email-destination" size="1">
<option value="admins"<?php if ( 'admins' == $ded ) : ?> selected="selected"<?php endif; ?>>the site administrators</option>
<?php foreach ($users as $id => $name) : ?>
<option value="user:<?php print (int) $id; ?>"<?php if (sprintf('user:%d', (int) $id)==$ded) : ?> selected="selected"<?php endif; ?>><?php print esc_html($name); ?></option>
<?php endforeach; ?>
<option value="mailto"<?php if ( !is_null($ded_addy)) : ?> selected="selected"<?php endif; ?>>another e-mail address...</option>
</select>
<input type="email" id="diagnostics-email-destination-address" name="diagnostics_email_destination_address" value="<?php print esc_attr( $ded_addy ); ?>" placeholder="email address" /></li>
</ul></td>
</tr>
</table>

<script type="text/javascript">
	contextual_appearance(
		'diagnostics-email-destination',
		'diagnostics-email-destination-address',
		'diagnostics-email-destination-default',
		'mailto',
		'inline'
	);
	jQuery( '#diagnostics-email-destination' ).change ( function () {
		contextual_appearance(
			'diagnostics-email-destination',
			'diagnostics-email-destination-address',
			'diagnostics-email-destination-default',
			'mailto',
			'inline'
		);
	} );
</script>
		<?php
	} /* FeedWordPressDiagnosticsPage::diagnostics_box () */

	/**
	 * Shows the box for the many possible update options.
	 *
	 * @param  type $page description
	 * @param  type $box description
	 *
	 * @return void  description
	 */
	static function updates_box ($page, $box = NULL) {
		$hours = get_option( 'feedwordpress_diagnostics_persistent_errors_hours', 2 );
		$fields = apply_filters( 'feedwordpress_diagnostics', array(
			'Update Diagnostics' => array(
				'update_schedule:check' => 'whenever a FeedWordPress checks in on the update schedule',
				'updated_feeds' => 'as each feed is checked for updates',
				'syndicated_posts' => 'as each syndicated post is added to the database',
				'feed_items' => 'as each syndicated item is considered on the feed',
				'memory_usage' => 'indicating how much memory was used',
			),
			// Note: the embedded HTML gets filtered out, so this might require some refactoring. (gwyneth 20230917)
			'Feed Retrieval' => array(
				'updated_feeds:errors:persistent' => 'when attempts to update a feed have resulted in errors</label> <label>for at least <input type="number" min="1" max="360" step="1" name="diagnostics_persistent_error_hours" value="'.$hours.'" /> hours',
				'updated_feeds:errors' => 'any time FeedWordPress encounters any errors while checking a feed for updates',
				'updated_feeds:http' => "displaying the raw HTTP data passed to and from the feed being checked for updates",
			),
			'Syndicated Post Details' => array(
				'feed_items:freshness' => 'as FeedWordPress decides whether to treat an item as a new post, an update, or a duplicate of an existing post',
				'feed_items:rejected' => 'when FeedWordPress rejects a post without syndicating it',
				'syndicated_posts:categories' => 'as categories, tags, and other terms are added on the post',
				'syndicated_posts:meta_data' => 'as syndication meta-data is added on the post',
				'syndicated_posts:do_pings' => 'when FeedWordPress holds or releases the pings WordPress sends out when new posts are created',
			),
			'Advanced Diagnostics' => array(
				'feed_items:freshness:reasons' => 'explaining the reason that a post was treated as an update to an existing post',
				'feed_items:freshness:sql' => 'when FeedWordPress issues the SQL query it uses to decide whether to treat items as new, updates, or duplicates',
				'syndicated_posts:categories:test' => 'as FeedWordPress checks for the familiarity of feed categories and tags',
				'syndicated_posts:static_meta_data' => 'providing meta-data about syndicated posts in the Edit Posts interface',
			),
		), $page );

		foreach ( $fields as $section => $items ) :
			foreach ( $items as $key => $label ) :
				$checked[$key] = '';
			endforeach;
		endforeach;

		$diagnostics_show = get_option( 'feedwordpress_diagnostics_show', array() );
		if ( is_array( $diagnostics_show ) ) : foreach ( $diagnostics_show as $thingy ) :
			$checked[$thingy] = ' checked="checked"';
		endforeach; endif;

		// Hey ho, let's go...
		?>
<table class="edit-form">
	<?php foreach ($fields as $section => $ul) : ?>
	  <tr>
	  <th scope="row"><?php print esc_html($section); ?>:</th>
	  <td><p><?php esc_html_e( 'Show a diagnostic message...' ); ?></p>
	  <ul class="options">
	  <?php foreach ($ul as $key => $label) : ?>
	    <li><label><input
	    	type="checkbox" name="diagnostics_show[]"
	    	value="<?php print esc_html( $key ); ?>"
	    	<?php fwp_selected_flag( $checked, $key, "checked" ); ?> />
	    <?php print esc_html( $label ); ?></label></li>
	  <?php endforeach; ?>
	  </ul></td>
	  </tr>
	<?php endforeach; ?>
</table>
		<?php
	} /* FeedWordPressDiagnosticsPage::updates_box () */

	static function tests_box ($page, $box = NULL) {
		$url = FeedWordPress::param( 'http_test_url' );
		$method = FeedWordPress::param( 'http_test_method' );
		$xpath = FeedWordPress::param( 'http_test_xpath' );

		$aMethods = array(
			'wp_remote_request',
			'FeedWordPie_File',
			'FeedWordPress::fetch',
		);
?>
<script type="text/javascript">
function clone_http_test_args_keyvalue_prototype () {
	var next = jQuery('#http-test-args').find('.http-test-args-keyvalue').length;
	var newRow = jQuery('#http-test-args-keyvalue-prototype').clone().attr('id', 'http-test-args-keyvalue-' + next);
	newRow.find('.http_test_args_key').attr('name', 'http_test_args_key['+next+']').val('');
	newRow.find('.http_test_args_value').attr('name', 'http_test_args_value['+next+']').val('');

	newRow.appendTo('#http-test-args');
	return false;
}
</script>

<table class="edit-form">
	<tr>
	<th scope="row">HTTP:</th>
	<td><div><input type="url" name="http_test_url" value="<?php print esc_attr($url);?>" placeholder="http://www.example.com/" size="127" style="width: 80%; max-width: 80.0em;" />
	<input type="submit" name="feedwordpress_diagnostics_do[http_test]" value="Test &raquo;" /></div>
	<div><select name="http_test_method" size="1">
	<?php foreach ($aMethods as $sMethod) :?>
		<option value="<?php
			print esc_attr($sMethod);
		?>"<?php
			if ($method==$sMethod) :
				print ' selected="selected"';
			endif;
		?>><?php
			print esc_html( $sMethod );
		?></option>
	<?php endforeach; ?>
	</select></div>
	<table>
	<tr>
	<td>
	<div id="http-test-args">
	<div id="http-test-args-keyvalue-prototype" class="http-test-args-keyvalue"><label><?php esc_html_e( 'Args' ); ?>:
	<input type="text" class='http_test_args_key' name="http_test_args_key[0]" value="" placeholder="key" /></label>
	<label>= <input type="text" class='http_test_args_value' name="http_test_args_value[0]" value="" placeholder="value" /></label>
	</div>
	</div>
	</td>
	<td><a href="#http-test-args" onclick="return clone_http_test_args_keyvalue_prototype();"><span class="dashicons dashicons-plus fwp-no-underline"></span> <?php esc_html_e( 'Add' ); ?></a></td>
	</tr>
	</table>
	</td>
	</tr>

	<tr>
	<th>XPath:</th>
	<td><div><input type="text" name="http_test_xpath" value="<?php print esc_attr($xpath); ?>" placeholder="xpath-like query" /></div>
	<div><p><?php esc_html_e( 'Leave blank to test HTTP, fill in to test a query.' ); ?></p></div>
	</td>
	</tr>

	<?php if (isset($page->test_html['http_test'])) : ?>
	<tr>
	<th scope="row"><?php esc_html_e( 'RESULTS:' ); ?></th>
	<td>
	<div>URL: <code><?php print esc_html($page->test_html['url']); ?></code></div>
	<div style="position: relative">
	<div style="width: 100%; overflow: scroll; background-color: #eed">
	<pre style="white-space: pre-wrap; white-space: -moz-pre-wrap; white-space: -o-pre-wrap;"><?php print esc_html($page->test_html['http_test']); ?></pre>
	</div>
	</div>
	</td>
	</tr>
	<?php endif; ?>
</table>

<?php
	} /* FeedWordPressDiagnosticsPage::tests_box () */

	private $test_html;
	public function do_http_test () {
		$url = FeedWordPress::post( 'http_test_url' );
		$method = FeedWordPress::post( 'http_test_method' );
		if ( ! ( is_null( $url ) || is_null( $method ) ) ) :

			$args = array();
			$keys = FeedWordPress::post( 'http_test_args_key' );
			if ( ! is_null( $keys ) ) :
				foreach ( $keys as $idx => $name ) :
					$name = trim($name);
					if (strlen($name) > 0) :
						$values = FeedWordPress::post('http_test_args_value', array() );

						$value = null;
						if ( isset( $values[ $idx ] ) ) :
							$value = $values[$idx];
						endif;

						if ( preg_match('/^javascript:(.*)$/i', $value, $refs) ) :
							if ( function_exists('json_decode') ) :
								$json_value = json_decode( $refs[1] );
								if ( ! is_null($json_value) ) :
									$value = $json_value;
								endif;
							endif;
						endif;

						$args[ $name ] = $value;
					endif;
				endforeach;
			endif;

			switch ( $method ) :
			case 'wp_remote_request' :
				$out = wp_remote_request($url, $args);
				unset( $out[ 'http_response' ] );
				break;
			case 'FeedWordPie_File' :
				$out = new FeedWordPie_File($url);
				break;
			case 'FeedWordPress::fetch' :
				$out = FeedWordPress::fetch($url);

				$s_xpath = FeedWordPress::post( 'http_test_xpath', '' );
				if ( strlen( $s_xpath ) > 0 ) :

					$xpath = FeedWordPress::post( 'http_test_xpath' );

					if ( !is_wp_error($out) ) :
						$expr = new FeedWordPressParsedPostMeta($xpath);

						$feed = new MagpieMockLink( $out, $url );
						$posts = $feed->live_posts();

						$post = new SyndicatedPost($posts[0], $feed);
						$meta = $expr->do_substitutions($post);

						$out = array(
						"post_title" => $post->entry->get_title(),
						"post_link" => $post->permalink(),
						"guid" => $post->guid(),
						"expression" => $xpath,
						"results" => $meta,
						"pie" => $out,
						);
					endif;
				endif;
				break;
			endswitch;

			$this->test_html['url']       = $url;
			$this->test_html['http_test'] = esc_html( MyPHP::val($out) );
		endif;
	} /* FeedWordPressDiagnosticsPage::do_http_test () */

} /* class FeedWordPressDiagnosticsPage */

	$diagnosticsPage = new FeedWordPressDiagnosticsPage;
	$diagnosticsPage->display();

