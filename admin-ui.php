<?php
/**
 * Admin UI Compatibility (admin-ui.php): This is kind of a junk pile of utility functions
 * mostly created to smooth out interactions to make things show up, or behave correctly,
 * within the WordPress admin settings interface. Major chunks of this code that deal with
 * making it easy for FWP, add-on modules, etc. to create new settings panels have since
 * been hived off into class FeedWordPressAdminPage. Many of the functions that remain here
 * were created to handle compatibility across multiple, sometimes very old, versions of
 * WordPress, many of which are no longer supported anymore. It's likely that some of these
 * functions will be re-evaluated, re-organized, deprecated, or clipped out in the next few
 * versions.
 * -cj 2017-10-27
 *
 * @package FeedWordPress
 */

$dir = dirname( __FILE__ );
require_once "${dir}/feedwordpressadminpage.class.php";
require_once "${dir}/feedwordpresssettingsui.class.php";

/**
 * Prints the class="..." attribute (if any) for an HTML form element
 *
 * If there is at least one class to add to the element, escape it for attribute contet and print;
 * if not, omit the attribute entirely.
 *
 * @param string $class_name The name(s) of the HTML class or classes to apply.
 * @param string $before Separator prefix string to print just before class="..." attribute, when printed (usually whitespace).
 * @param string $after Separator suffix string to print just after class="..." attribute, when printed (usually blank).
 */
function fwp_form_class_attr( $class_name, $before = ' ', $after = '' ) {

	if ( is_string( $class_name ) ) :
		if ( strlen( $class_name ) > 0 ) :
			$s_class_name = sanitize_html_class( $class_name );
			printf( '%sclass="%s"%s', esc_html( $before ), esc_attr( $s_class_name ), esc_html( $after ) );
		endif;
	endif;

} /* fwp_form_class_attr() */

/**
 * Prints a flag attribute for selected UI elements (selected="selected" or checked="checked", etc.) when appropriate
 *
 * In HTML form output templates, this allows conditional output of the selected="selected"
 * (or checked="checked", etc.) attribute on input or option controls when appropriate, and
 * omits the element when not appropriate, as measured by a flag that is monitored and updated
 * by the caller.
 *
 * @param mixed  $arg Flag monitoring variable.
 * @param mixed  $key When $arg is an array, $key provides the index within the array to check for the flag.
 * @param string $flag The name of the flag attribute to output when appropriate; default, "selected".
 */
function fwp_selected_flag( /* mixed */ $arg = null, $key = null, $flag = 'selected' ) {

	$is_on = false;

	$s_arg = $arg;
	if ( is_array( $arg ) && ! is_null( $key ) ) :
		if ( array_key_exists( $key, $arg ) ) :
			$s_arg = $arg[ $key ];
		else :
			$s_arg = false;
		endif;
	endif;

	if ( is_string( $s_arg ) ) :
		$is_on = ( strlen( $s_arg ) > 0 );
	else :
		$is_on = (bool) ( $s_arg );
	endif;

	if ( $is_on ) :
		print sprintf( '%s="%s"', esc_attr( $flag ), esc_attr( $flag ) );
	endif;
} /* fwp_selected_flag() */

/**
 * Outputs a flag attribute for checked UI elements (checked="checked") when appropriate
 *
 * In HTML form output templates, this allows conditional output of the check="checked"
 * attribute on input controls (e.g. checkbox, radio) when appropriate, and omits when
 * inappropriate (unchecked), as determined by a flag monitored and updated by caller.
 *
 * @param mixed $arg Flag monitoring variable.
 * @param mixed $key When $arg is an array, $key provides index within the array to check for flag value.
 *
 * @uses fwp_selected_flag()
 */
function fwp_checked_flag( /* mixed */ $arg = null, $key = null ) {
	fwp_selected_flag( $arg, $key, 'checked' );
} /* fwp_checked_flag() */

/**
 * Retrieves a plural or singular form of a string based on the supplied number.
 *
 * Used when you want to use the appropriate form of a string based on whether
 * a number is singular or plural. Very similar to WordPress l10n.php _n(),
 * but designed for a nice short form in the
 * default case (provide "s" when plural, "" otherwise).
 *
 * @param int    $n        The counter to determine singular or plural form.
 * @param string $plural   The text to use if the counter is plural.
 * @param string $singular The text to use if the counter is singular.
 *
 * @return string The text of the singular or plural form.
 */
function _s( $n = 0, $plural = 's', $singular = '' ) {
	$is_singular = ( is_numeric( $n ) && intval( $n ) === 1 );
	return ( $is_singular ? $singular : $plural );
}

/**
 * Outputs a status message for the aggregate results of polling a set of feeds.
 *
 * This will always output the number of new syndicated posts added, even if this is 0.
 * Posts updated and alternate versions stored will be added to the message iff > 0.
 *
 * @param array  $delta  Counters indicating results in "new", "updated" and "stored".
 * @param string $joiner Default ';'. Delimiter used to separate messages about new, updated and stored posts.
 */
function fwp_update_set_results_message( $delta, $joiner = ';' ) {

	$mesg = array();

	$delta = wp_parse_args(
		$delta,
		array(
			'new'     => 0,
			'updated' => 0,
			'stored'  => 0,
		)
	);

	$mesg[] = sprintf( ' %d new post%s syndicated', intval( $delta['new'] ), _s( $delta['new'], 's were', ' was' ) );
	if ( $delta['updated'] > 0 ) :
		$mesg[] = sprintf( ' %d existing post%s updated', intval( $delta['updated'] ), _s( $delta['updated'], 's were', ' was' ) );
	endif;
	if ( $delta['stored'] > 0 ) :
		$mesg[] = sprintf( ' %d alternate version%s of existing post%s stored for reference', intval( $delta['stored'] ), _s( $delta['stored'] ), _s( $delta['stored'], 's were', ' was' ) );
	endif;

	if ( ! is_null( $joiner ) ) :
		$mesg = implode( $joiner, $mesg );
	endif;
	return $mesg;
} /* function fwp_update_set_results_message () */

/**
 * Outputs the HTML template for a "Submit" button on FWP admin pages.
 *
 * @param mixed $link The syndicated link, if any, that we are viewing settings for.
 */
function fwp_authors_single_submit( $link = null ) {
	?>
<div class="submitbox" id="submitlink">
<div id="previewview">
</div>
<div class="inside">
</div>

<p class="submit">
<input type="submit" name="save" value="<?php esc_html_e( 'Save' ); ?>" />
</p>
</div>
	<?php
}

/**
 * Outputs the HTML template for a Tags (or similar taxonomy) add / remove box in FeedWordPress admin UI pages.
 *
 * @param array  $tags    An array of tags already applied to the object.
 * @param string $object The human-readable description of the objects to be tagged ("post", "posts from this feed", etc.).
 * @param array  $params  An array of optional parameters.
 */
function fwp_tags_box( $tags, $object, $params = array() ) {
	$params = wp_parse_args(
		$params,
		array( // Default values.
			'taxonomy'      => 'post_tag',
			'textarea_name' => null,
			'textarea_id'   => null,
			'input_id'      => null,
			'input_name'    => null,
			'id'            => null,
			'box_title'     => __( 'Post Tags' ),
		)
	);

	if ( ! is_array( $tags ) ) :
		$tags = array();
	endif;

	$tax_name     = $params['taxonomy'];
	$o_tax        = get_taxonomy( $params['taxonomy'] );
	$o_tax_labels = get_taxonomy_labels( $o_tax );
	$is_enabled   = current_user_can( $o_tax->cap->assign_terms );

	if ( is_null( $params['textarea_name'] ) ) :
		$params['textarea_name'] = "tax_input[$tax_name]";
	endif;
	if ( is_null( $params['textarea_id'] ) ) :
		$params['textarea_id'] = "tax-input-${tax_name}";
	endif;
	if ( is_null( $params['input_id'] ) ) :
		$params['input_id'] = "new-tag-${tax_name}";
	endif;
	if ( is_null( $params['input_name'] ) ) :
		$params['input_name'] = "newtag[$tax_name]";
	endif;

	if ( is_null( $params['id'] ) ) :
		$params['id'] = $tax_name;
	endif;

	printf( /* $desc = */ '<p style="font-size:smaller;font-style:bold;margin:0\">Tag %s as...</p>', esc_html( $object ) );
	$helps        = __( 'Separate tags with commas.' );
	$box['title'] = __( 'Tags' );
	?>
<div class="tagsdiv" id="<?php echo esc_attr( $params['id'] ); ?>">
	<div class="jaxtag">
	<div class="nojs-tags hide-if-js">
	<p><?php echo esc_html( $o_tax_labels->add_or_remove_items ); ?></p>
	<textarea name="<?php echo esc_attr( $params['textarea_name'] ); ?>" class="the-tags" id="<?php echo esc_attr( $params['textarea_id'] ); ?>"><?php echo esc_attr( implode( ',', $tags ) ); ?></textarea></div>

	<?php if ( $is_enabled ) : ?>
	<div class="ajaxtag hide-if-no-js">
		<label class="screen-reader-text" for="<?php echo esc_attr( $params['input_id'] ); ?>"><?php echo esc_html( $params['box_title'] ); ?></label>
		<div class="taghint"><?php echo esc_html( $o_tax_labels->add_new_item ); ?></div>
		<p><input type="text" id="<?php print esc_attr( $params['input_id'] ); ?>" name="<?php print esc_attr( $params['input_name'] ); ?>" class="newtag form-input-tip" size="16" autocomplete="off" value="" />
		<input type="button" class="button tagadd" value="<?php esc_attr_e( 'Add' ); ?>" tabindex="3" /></p>
	</div>
	<p class="howto"><?php echo esc_attr( $o_tax_labels->separate_items_with_commas ); ?></p>
	<?php endif; ?>
	</div>

	<div class="tagchecklist"></div>
</div>
	<?php
	if ( $is_enabled ) :
		?>
<p class="hide-if-no-js"><a href="#titlediv" class="tagcloud-link" id="link-<?php echo esc_attr( $tax_name ); ?>"><?php echo esc_html( $o_tax_labels->choose_from_most_used ); ?></a></p>
		<?php
	endif;

}

/**
 * Outputs the HTML template for a Category (or similar taxonomy) add / remove box in FeedWordPress admin UI pages.
 *
 * @param array  $checked    An array of cats already applied to the object.
 * @param string $object The human-readable description of the objects to be tagged ("post", "posts from this feed", etc.).
 * @param array  $tags Not used.
 * @param array  $params  An array of optional parameters.
 */
function fwp_category_box( $checked, $object, $tags = array(), $params = array() ) {
	global $wp_db_version;

	if ( is_string( $params ) ) :
		$prefix   = $params;
		$taxonomy = 'category';
	elseif ( is_array( $params ) ) :
		$params = wp_parse_args(
			$params,
			array(
				'prefix'   => '',
				'taxonomy' => 'category',
			)
		);

		$prefix   = $params['prefix'];
		$taxonomy = $params['taxonomy'];
	endif;

	$o_tax        = get_taxonomy( $taxonomy );
	$o_tax_labels = get_taxonomy_labels( $o_tax );

	if ( strlen( $prefix ) === 0 ) :
		$prefix = 'feedwordpress';
	endif;

	$id_prefix   = $prefix . '-';
	$id_suffix   = '-' . $prefix;
	$name_prefix = $prefix . '_';

	$box_div_id = sanitize_html_class( $id_prefix . 'taxonomy-' . $taxonomy );
	$tabs_ul_id = sanitize_html_class( $id_prefix . $taxonomy . '-tabs' );
	$all_tab_id = sanitize_html_class( $id_prefix . $taxonomy . '-all' );
	$chk_lst_id = sanitize_html_class( $id_prefix . $taxonomy . 'checklist' );
	$add_tax_id = sanitize_html_class( $id_prefix . $taxonomy . '-adder' );
	$add_tog_id = sanitize_html_class( $id_prefix . $taxonomy . '-add-toggle' );
	$add_cat_id = sanitize_html_class( $id_prefix . $taxonomy . '-add' );
	$new_tax_id = sanitize_html_class( $id_prefix . 'new' . $taxonomy );

	$tax_id_add_submit = sanitize_html_class( $id_prefix . $taxonomy . '-add-sumbit' );
	?>
<div id="<?php print esc_attr( $box_div_id ); ?>" class="feedwordpress-category-div">
	<ul id="<?php print esc_attr( $tabs_ul_id ); ?>" class="category-tabs">
	<li class="ui-tabs-selected tabs"><a href="#<?php print esc_attr( $all_tab_id ); ?>" tabindex="3"><?php esc_html_e( 'All posts' ); ?></a>
	<p style="font-size:smaller;font-style:bold;margin:0">Give <?php print esc_html( $object ); ?> these <?php print esc_html( $o_tax_labels->name ); ?></p>
	</li>
	</ul>

<div id="<?php print esc_attr( $all_tab_id ); ?>" class="tabs-panel">
	<input type="hidden" value="0" name="tax_input[<?php print esc_attr( $taxonomy ); ?>][]" />
	<ul id="<?php print esc_attr( $chk_lst_id ); ?>" class="list:<?php print esc_attr( $taxonomy ); ?> categorychecklist form-no-clear">
	<?php fwp_category_checklist( null, false, $checked, $params ); ?>
	</ul>
</div>

<div id="<?php print esc_attr( $add_tax_id ); ?>" class="<?php print esc_attr( $taxonomy ); ?>-adder wp-hidden-children">
	<h4><a id="<?php print esc_attr( $add_tog_id ); ?>" class="category-add-toggle" href="#<?php print esc_attr( $add_cat_id ); ?>" class="hide-if-no-js" tabindex="3"><?php esc_html_e( '+ Add New Category' ); ?></a></h4>.
	<p id="<?php print esc_attr( $add_cat_id ); ?>" class="category-add wp-hidden-child">
	<?php
	$newcat = 'new' . $taxonomy;
	?>
	<label class="screen-reader-text" for="<?php print esc_attr( $new_tax_id ); ?>"><?php esc_html_e( 'Add New Category' ); ?></label>
	<input
		id="<?php print esc_attr( $new_tax_id ); ?>"
		class="<?php print esc_attr( $newcat ); ?> form-required form-input-tip"
		aria-required="true"
		tabindex="3"
		type="text" name="<?php print esc_attr( $newcat ); ?>"
		value="<?php esc_attr_e( 'New category name' ); ?>"
	/>
	<label class="screen-reader-text" for="<?php print esc_attr( $new_tax_id ); ?>-parent"><?php esc_html_e( 'Parent Category:' ); ?></label>
	<?php
	wp_dropdown_categories(
		array(
			'taxonomy'         => $taxonomy,
			'hide_empty'       => 0,
			'id'               => $new_tax_id . '-parent',
			'class'            => $newcat . '-parent',
			'name'             => $newcat . '_parent',
			'orderby'          => 'name',
			'hierarchical'     => 1,
			'show_option_none' => __( 'Parent category' ),
			'tab_index'        => 3,
		)
	);

	$nonce_code = ( 'add-' . $taxonomy );
	?>
	<input type="button" id="<?php print esc_attr( $tax_id_add_submit ); ?>" class="add:<?php print esc_attr( $id_prefix . $taxonomy ); ?>checklist:<?php print esc_attr( $id_prefix . $taxonomy ); ?>-add add-categorychecklist-category-add button category-add-submit" value="<?php esc_attr_e( 'Add' ); ?>" tabindex="3" />
	<?php /* wp_nonce_field currently doesn't let us set an id different from name, but we need a non-unique name and a unique id */ ?>
	<input type="hidden" id="_ajax_nonce<?php print esc_html( $id_suffix ); ?>" name="_ajax_nonce" value="<?php print esc_attr( wp_create_nonce( $nonce_code ) ); ?>" />
	<input type="hidden" id="_ajax_nonce-add-<?php print esc_attr( $taxonomy . $id_suffix ); ?>" name="_ajax_nonce-add-<?php print esc_attr( $taxonomy ); ?>" value="<?php print esc_attr( wp_create_nonce( $nonce_code ) ); ?>" />
	<span id="<?php print esc_attr( $id_prefix . $taxonomy ); ?>-ajax-response" class="<?php print esc_attr( $taxonomy ); ?>-ajax-response"></span>
	</p>
</div>

</div>
	<?php
}

/**
 * Outputs a text/html status message indicating that FWP has started polling a feed.
 *
 * @param array $feed An associative array containing meta-data about the feed being polled.
 */
function update_feeds_mention( $feed ) {
	printf(
		'<li>Updating <cite>%s</cite> from &lt;<a href="%s">%s</a>&gt; ...',
		esc_html( $feed['link/name'] ),
		esc_url( $feed['link/uri'] ),
		esc_html( $feed['link/uri'] )
	);
	flush();
}

/**
 * Outputs a text/html status message indicating that FWP has completed polling a feed.
 *
 * @param array $feed  An associative array containing meta-data about the feed being polled.
 * @param mixed $added a WP_Error object with error codes and messages, if there was an error in polling.
 * @param int   $dt      seconds it took to complete the poll.
 */
function update_feeds_finish( $feed, $added, $dt ) {
	if ( is_wp_error( $added ) ) :
		$mesgs = $added->get_error_messages();
		foreach ( $mesgs as $mesg ) :
			printf( '<br/><strong>Feed error:</strong> <code>%s</code>', esc_html( $mesg ) );
		endforeach;
		echo "</li>\n";
	else :
		printf( " completed in %d second%s</li>\n", esc_html( $dt ), esc_html( _s( $dt ) ) );
	endif;
	flush();
}

/**
 * Retrieves a list of users via the WordPress API.
 *
 * @return array List of users, by numeric ID => display_name
 */
function fwp_author_list() {
	global $wpdb;
	$ret = array();

	$users = get_users();
	if ( is_array( $users ) ) :
		foreach ( $users as $user ) :
			$id         = (int) $user->ID;
			$ret[ $id ] = $user->display_name;

			if ( strlen( trim( $ret[ $id ] ) ) === 0 ) :
				$ret[ $id ] = $user->user_login;
			endif;
		endforeach;
	endif;
	return $ret;
}

/**
 * Insert a new user into the WordPress database, with some FeedWordPress-specific default behaviors.
 *
 * @param string $newuser_name The "Display Name" for the new user; user logins and the like will be determined by a formula.
 * @return mixed Either a numeric ID or a WP_Error object.
 */
function fwp_insert_new_user( $newuser_name ) {
	global $wpdb;

	$ret = null;
	if ( strlen( $newuser_name ) > 0 ) :
		$userdata                  = array();
		$userdata['ID']            = null;
		$userdata['user_login']    = apply_filters( 'pre_user_login', sanitize_user( $newuser_name ) );
		$userdata['user_nicename'] = apply_filters( 'pre_user_nicename', sanitize_title( $newuser_name ) );
		$userdata['display_name']  = $newuser_name;
		$userdata['user_pass']     = substr( md5( uniqid( microtime() ) ), 0, 6 ); // just something random to lock it up.

		$blah_url = get_bloginfo( 'url' );
		$url      = wp_parse_url( $blah_url );

		$userdata['user_email'] = substr( md5( uniqid( microtime() ) ), 0, 6 ) . '@' . $url['host'];

		$newuser_id = wp_insert_user( $userdata );

		$ret = $newuser_id; // Either a numeric ID or a WP_Error object.

	else :

		$ret = new WP_Error( 'empty_username', 'Provide a non-empty string for the Display Name to fwp_insert_new_user' );

	endif;
	return $ret;
} /* fwp_insert_new_user ( ) */

/**
 * Output HTML for table row in FeedWordPress Syndicated Sources list.
 *
 * @param array  $links   array of WordPress link objects.
 * @param object $page    FeedWordPress admin page object.
 * @param string $visible 'Y' or 'N', whether to display syndicated sources marked as visible or as hidden.
 */
function fwp_syndication_manage_page_links_table_rows( $links, $page, $visible = 'Y' ) {

	$fwp_syndicated_sources_columns = array( __( 'Name' ), __( 'Feed' ), __( 'Updated' ) );

	$subscribed = ( 'Y' === strtoupper( $visible ) );
	if ( $subscribed || ( count( $links ) > 0 ) ) :
		$table_classes = array( 'widefat' );
		if ( ! $subscribed ) :
			$table_classes[] = 'unsubscribed';
		endif;
		$table_classes = implode( ' ', $table_classes );
		?>
	<table class="<?php print esc_attr( $table_classes ); ?>">
	<thead>
	<tr>
	<th class="check-column" scope="col"><input type="checkbox" /></th>
		<?php
		foreach ( $fwp_syndicated_sources_columns as $col ) :
			printf( "\t<th scope='col'>%s</th>\n", esc_html( $col ) );
		endforeach;
		print "</tr>\n";
		print "</thead>\n";
		print "\n";
		print "<tbody>\n";

		$alt_row = true;
		if ( count( $links ) > 0 ) :
			foreach ( $links as $link ) :
				$tr_class = array();

				$o_s_link = new SyndicatedLink( $link->link_id );

				if ( is_null( $o_s_link->setting( 'update/error' ) ) ) :

					$the_error = null;

				else :
					$tr_class[] = 'feed-error';
					$the_error  = unserialize( $o_s_link->setting( 'update/error' ) );
				endif;

				$ttl = $o_s_link->setting( 'update/ttl' );

				$alt_row = ! $alt_row;

				if ( $alt_row ) :
					$tr_class[] = 'alternate';
				endif;
				?>
	<tr<?php fwp_form_class_attr( implode( ' ', $tr_class ) ); ?>>
	<th class="check-column" scope="row"><input type="checkbox" name="link_ids[]" value="<?php echo esc_attr( $link->link_id ); ?>" /></th>
				<?php
				$caption = (
					( strlen( $link->link_rss ) > 0 )
					? __( 'Switch Feed' )
					: __( 'Find Feed' )
				);
				?>
	<td>
	<strong><a href="<?php print esc_url( $page->admin_page_href( 'feeds-page.php', array(), $link ) ); ?>"><?php print esc_html( $link->link_name ); ?></a></strong>
	<div class="row-actions">
				<?php
				if ( $subscribed ) :
					$page->display_feed_settings_page_links(
						array(
							'before'       => '<div><strong>Settings &gt;</strong> ',
							'after'        => '</div>',
							'subscription' => $link,
						)
					);
				endif;
				?>

	<div><strong>Actions &gt;</strong>
				<?php if ( $subscribed ) : ?>
	<a href="<?php print esc_url( $page->admin_page_href( 'syndication.php', array( 'action' => 'feedfinder' ), $link ) ); ?>"><?php echo esc_html( $caption ); ?></a>
				<?php else : ?>
	<a href="<?php print esc_url( $page->admin_page_href( 'syndication.php', array( 'action' => FWP_RESUB_CHECKED ), $link ) ); ?>"><?php esc_html_e( 'Re-subscribe' ); ?></a>
				<?php endif; ?>
	| <a href="<?php print esc_url( $page->admin_page_href( 'syndication.php', array( 'action' => 'Unsubscribe' ), $link ) ); ?>">
				<?php
				if ( $subscribed ) :
					esc_html_e( 'Unsubscribe' );
				else :
					esc_html_e( 'Delete permanently' );
				endif;
				?>
				</a>
	| <a href="<?php print esc_url( $link->link_url ); ?>"><?php esc_html_e( 'View' ); ?></a></div>
	</div>
	</td>
				<?php if ( strlen( $link->link_rss ) > 0 ) : ?>
	<td><div><a href="<?php echo esc_html( $link->link_rss ); ?>"><?php echo esc_html( feedwordpress_display_url( $link->link_rss, 32 ) ); ?></a></div></td>
				<?php else : ?>
	<td class="feed-missing"><p><strong>no feed assigned</strong></p></td>
				<?php endif; ?>

	<td><div style="float: right; padding-left: 10px">
	<input type="submit" class="button" name="update_uri[<?php print esc_html( $link->link_rss ); ?>]" value="<?php esc_html_e( 'Update Now' ); ?>" />
	</div>
				<?php
				fwp_links_table_rows_last_updated( $o_s_link );
				fwp_links_table_rows_file_size( $o_s_link );
				fwp_links_table_rows_errors_since( $the_error );
				fwp_links_table_rows_next_update( $o_s_link );
				?>
	</td>
	</tr>
				<?php
				unset( $o_s_link );

			endforeach;
		else :
			?>
<tr><td colspan="4"><p>There are no websites currently listed for syndication.</p></td></tr>
			<?php

		endif;

		?>

</tbody>
</table>

		<?php

	endif;
} /* function fwp_syndication_manage_page_links_table_rows ( ) */

/**
 * Output HTML message indicating feed errors, if a feed has been returning errors, in Syndicated Sites table.
 *
 * @param mixed $the_error If last poll on this feed was successful, this is null.
 *                         If last poll returned an error, contains an array with 'since' (timestamp of first time an error was an encountered), 'ts' (timestamp of most recent time an error was encountered), and 'object' (a WP_Error object representing the most recent error when you polled the feed).
 */
function fwp_links_table_rows_errors_since( $the_error ) {
	if ( ! is_null( $the_error ) ) :

		$s_elapsed = fwp_time_elapsed( $the_error['since'] );
		$s_recent  = fwp_time_elapsed( $the_error['ts'] );
		?>

<div class="returning-errors"><p><strong>Returning errors</strong> since <?php print esc_html( $s_elapsed ); ?></p>
<p>Most recent (<?php print esc_html( $s_recent ); ?>):
		<?php
		foreach ( $the_error['object']->get_error_messages() as $mesg ) :
			printf( '<br/><code>%s</code>', esc_html( $mesg ) );
		endforeach;
		?>
</p>
</div>

		<?php
	endif;
}

/**
 * Output the "Last checked..." status message in Syndicated Sites table.
 *
 * @param SyndicatedLink $o_s_link The SyndicatedLink object representing the feed displayed on this row.
 */
function fwp_links_table_rows_last_updated( $o_s_link ) {
	if ( ! is_null( $o_s_link->setting( 'update/last' ) ) ) :
		print esc_html( 'Last checked ' . fwp_time_elapsed( $o_s_link->setting( 'update/last' ) ) );
	else :
		esc_html_e( 'None yet' );
	endif;

	$ttl = $o_s_link->setting( 'update/ttl' );
	if ( is_numeric( $ttl ) ) :
		$next = $o_s_link->setting( 'update/last' ) + $o_s_link->setting( 'update/fudge' ) + ( (int) $ttl * 60 );

		if ( 'automatically' !== $o_s_link->setting( 'update/timed' ) ) :

			print ' &middot; Next ';
			print esc_html( fwp_relative_time_string( $next ) );

		endif;
	endif;

}

/**
 * Return a status message indicating when a feed will next be polled, based on a next-update timestamp.
 *
 * @param int  $ts  Timestamp for next updrate.
 * @param bool $ago Display "[relative time] ago", or "ASAP", if the timestamp is in the past.
 */
function fwp_relative_time_string( $ts, $ago = false ) {

	$dt = ( $ts - time() );

	if ( $dt < 0 && ! $ago ) :
		$ret = 'ASAP';
	elseif ( $dt < 60 * 60 ) :
		$ret = fwp_time_elapsed( $ts );
	elseif ( $dt < 60 * 60 * 24 ) :
		$ret = wp_date( 'g:ia', $ts );
	else :
		$ret = wp_date( 'F j', $ts );
	endif;
	return $ret;
}

/**
 * Output the File Size / Format status message in Syndicated Sites table.
 *
 * @param SyndicatedLink $o_s_link Object representing the feed displayed on this row.
 */
function fwp_links_table_rows_file_size( $o_s_link ) {

	$mesg_file_size_lines = array();

	$feed_type = $o_s_link->get_feed_type();

	if ( ! is_null( $o_s_link->setting( 'link/item count' ) ) ) :
		$n = $o_s_link->setting( 'link/item count' );

		// translators: %1$d is the item count; %2$s is the plural marker (if any) for item.
		$mesg_file_size_lines[] = sprintf( __( '%1$d item%2$s' ), $n, _s( $n ) ) . ', ' . $feed_type;

	endif;

	if ( is_null( $o_s_link->setting( 'update/error' ) ) ) :

		if ( ! is_null( $o_s_link->setting( 'link/filesize' ) ) ) :
			$mesg_file_size_lines[] = size_format( $o_s_link->setting( 'link/filesize' ) ) . ' total';
		endif;

	endif;

	if ( count( $mesg_file_size_lines ) > 0 ) :

		print '<div>';
		$sep = '';
		foreach ( $mesg_file_size_lines as $line ) :
			print esc_html( $sep );
			print esc_html( $line );
			$sep = ' / ';
		endforeach;
		print '</div>';

	endif;

}

/**
 * Output the Scheduled For Next Update status message in Syndicated Sites table.
 *
 * @param SyndicatedLink $o_s_link Object representing the feed displayed on this row.
 */
function fwp_links_table_rows_next_update( $o_s_link ) {
	$ttl = $o_s_link->setting( 'update/ttl' );
	?>
	<div style="max-width: 30.0em; font-size: 0.9em;"><div style="font-style:italic;">
	<?php
	if ( is_numeric( $ttl ) ) :
		$next = $o_s_link->setting( 'update/last' ) + $o_s_link->setting( 'update/fudge' ) + ( (int) $ttl * 60 );
		if ( 'automatically' === $o_s_link->setting( 'update/timed' ) ) :
			if ( $next < time() ) :
				print 'Ready and waiting to be updated since ';
			else :
				print 'Scheduled for next update ';
			endif;

			print esc_html( fwp_time_elapsed( $next ) );
			if ( FEEDWORDPRESS_DEBUG ) :
				$interval = ( ( $next - time() ) / 60 );
				printf( ' [%d minute%s]', intval( $interval ), esc_html( _s( $interval ) ) );
			endif;
		else :
			printf( '. Scheduled to be checked for updates every %d minute%s', intval( $ttl ), esc_html( _s( $ttl ) ) );
			?>
			</div>

			<div style="size:0.9em; margin-top: 0.5em">This update schedule was requested by the feed provider
			<?php
			if ( $o_s_link->setting( 'update/xml' ) ) :
				?>
				 using a standard <code style="font-size: inherit; padding: 0; background: transparent">&lt;<?php print esc_html( $o_s_link->setting( 'update/xml' ) ); ?>&gt;</code> element
				<?php
			endif;
		endif;
	else :
		print 'Scheduled for update as soon as possible';
	endif;
	print '.';
	?>
	</div></div>
	<?php
}
