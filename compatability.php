<?php
/**
 * LEGACY API: Replicate or mock up functions for legacy support purposes.
 *
 * @author radgeek
 */

/**
 * Implements legacy functionality no longer present in the current WordPress
 * versions
 */
class FeedWordPressCompatibility {

	/**
	 * FeedWordPressCompatibility::test_version: test version of WordPress
	 * based on the database schema version.
	 *
	 * @param int   $floor   The minimum version necessary.
	 * @param mixed $ceiling The first version that is too high. If omitted
	 * 	or NULL, no version is too high.
	 * @return      bool     TRUE if within the range of versions, FALSE if too low
	 * 	or too high.
	 */
	static function test_version( $floor, $ceiling = null ) {
		global $wp_db_version;

		$ver = ( isset( $wp_db_version ) ? $wp_db_version : 0 );
		$good = ( $ver >= $floor );
		if ( ! is_null( $ceiling ) ) :
			$good = ( $good and ( $ver < $ceiling ) );
		endif;
		return $good;
	} /* FeedWordPressCompatibility::test_version() */

	/**
	 * Creates a new category for the Links.
	 *
	 * WP dropped support for "Links".
	 *
	 * @param  string $name New link category name.
	 *
	 * @return mixed  Most of the time should return an array with `term_id`
	 *                and `term_taxonomy_id`, unless there was an error.
	 */
	static function insert_link_category( $name ) {
		// WordPress 2.3+ term/taxonomy API
		$term = wp_insert_term( $name, 'link_category' );

		// OK: returned array('term_id' => $term_id, 'term_taxonomy_id' => $tt_id)
		if ( ! is_wp_error( $term ) ) :
			$cat_id = $term['term_id'];

		// Error: term with this name already exists. Well, let's use that then.
		elseif ( 'term_exists' == $term->get_error_code() ) :
			// Already-existing term ID is returned in data field
			$cat_id = $term->get_error_data( 'term_exists' );

		// Error: another kind of error, harder to recover from. Return WP_Error.
		else :
			$cat_id = $term;
		endif;

		// Return newly-created category ID
		return $cat_id;
	} /* FeedWordPressCompatibility::insert_link_category () */

	/**
	 * Returns category id for a Links category.
	 *
	 * "Links" became optional in WP.
	 *
	 * For the explanation of the values, see @see term_exists()
	 *
	 * @param  int|string $value @see term_exists()
	 * @param  string     $key   Unused.
	 * @return mixed             @see term_exists()
	 */
	static function link_category_id( $value, $key = 'cat_name' ) {
		$cat_id = NULL;

		$the_term = term_exists( $value, 'link_category' );

		// Sometimes, in some versions, we get a row
		if ( is_array( $the_term ) ) :
			$cat_id = $the_term['term_id'];

		// other times we get an integer result
		else :
			$cat_id = $the_term;
		endif;

		return $cat_id;
	} /* FeedWordPressCompatibility::link_category_id () */

	/**
	 * Validate if the user has the right capability for doing this request.
	 *
	 * @see check_admin_referer()
	 * @see current_user_can()
	 *
	 * @param  int|string   $action     The nonce action.
	 * @param  string|null  $capability Capability name.
	 */
	static function validate_http_request( $action = -1, $capability = null ) {
		// Only worry about this if we're using a method with significant side-effects
		if ( 'POST' == strtoupper( $_SERVER['REQUEST_METHOD'] ) ) :
			// Limit post by user capabilities
			if ( ! is_null( $capability ) and ! current_user_can( $capability ) ) :
				wp_die( esc_html__( 'Cheatin&rsquo; uh?' ) );
			endif;

			// If check_admin_referer() checks a nonce.
			if ( function_exists( 'wp_verify_nonce' ) ) :
				check_admin_referer( $action );

			// No nonces means no checking nonces.
			else :
				check_admin_referer();
			endif;
		endif;
	} /* FeedWordPressCompatibility::validate_http_request() */

	/**
	 * Stamps form with hidden fields for a nonce in WP 2.0.3 & later.
	 *
	 * Basically just checks if the function wp_nonce_field() exists, and, if so,
	 * uses it; on earlier WP versions, just skips it.
	 *
	 * @todo What about the HTML which might get returned by wp_nonce_field()?
	 * (gwyneth 20230917)
	 *
	 * @see wp_nonce_field()
	 *
	 * @param  int|string  $action  Optional action name, defaults to -1.
	 *
	 */
	static function stamp_nonce( $action = -1 ) {
		if ( function_exists( 'wp_nonce_field' ) ) :
			wp_nonce_field( $action );
		endif;
	} /* FeedWordPressCompatibility::stamp_nonce() */

	static function bottom_script_hook ($filename) {
		global $fwp_path;

		$hook = 'admin_footer-'.$fwp_path.'/'.basename($filename);
		return $hook;
	} /* FeedWordPressCompatibility::bottom_script_hook() */
} /* class FeedWordPressCompatibility */

// Compat

if ( ! function_exists( 'set_post_field' ) ) {
	/**
	 * Update data from a post field based on Post ID.
	 *
	 * Examples of the post field will be, 'post_type', 'post_status', 'post_content', etc.
	 *
	 * The context values are based off of the taxonomy filter functions and
	 * supported values are found within those functions.
	 *
	 * @uses sanitize_post_field()
	 *
	 * @param string $field Post field name.
	 * @param mixed  $value New value for post field.
	 * @param id     $post  Post ID.
	 * @return bool  Result of UPDATE query.
	 *
	 * Included under terms of GPL from WordPress Ticket #10946 <http://core.trac.wordpress.org/attachment/ticket/10946/post.php.diff>
	 */
	function set_post_field ($field, $value, $post_id) {
		global $wpdb;

		$post_id = absint($post_id);
		// sigh ... when FWP is active, I need to avoid avoid_kses_munge
		// $value = sanitize_post_field($field, $value, $post_id, 'db');
		return $wpdb->update($wpdb->posts, array($field => $value), array('ID' => $post_id));
	} /* function set_post_field () */

} /* if */

if ( ! function_exists( 'is_countable' ) ) {
	/* Copied from WordPress 5.3.2 wp-includes/compat.php pursuant to terms
	 * of GPL. Provide support for is_countable() for versions of PHP < 7.3
	 * and for versions of WordPress < 4.9.6. -C.J. 2020/01/24
     */

	/**
	 * Polyfill for is_countable() function added in PHP 7.3.
	 *
	 * Verify that the content of a variable is an array or an object
	 * implementing the Countable interface.
	 *
	 * @since 4.9.6
	 *
	 * @param mixed $var The value to check.
	 *
	 * @return bool True if `$var` is countable, false otherwise.
	 */
	function is_countable( $var ) {
			return ( is_array( $var )
					|| $var instanceof Countable
					|| $var instanceof SimpleXMLElement
					|| $var instanceof ResourceBundle
			);
	}
} /* if */

require_once dirname(__FILE__) . '/feedwordpress-walker-category-checklist.class.php';

/**
 * Checks if we have all the categories we need.
 *
 * @see wp_terms_checklist()
 *
 * @param  int          $post_id               Optional, defaults to zero.
 * @param  int          $descendents_and_self  @see wp_terms_checklist()
 * @param  bool         $selected_cats         @see wp_terms_checklist()
 * @param  string|array $params                @see wp_terms_checklist()
 *
 * @uses wp_terms_checklist()
 * @uses FeedWordPress_Walker_Category_Checklist()
 */
function fwp_category_checklist( $post_id = 0, $descendents_and_self = 0, $selected_cats = false, $params = array() ) {
	if ( is_string( $params ) ) :
		$prefix = $params;
		$taxonomy = 'category';
	elseif ( is_array( $params ) ) :
		$prefix   = ( isset( $params['prefix'] )   ? $params['prefix']   : '' );
		$taxonomy = ( isset( $params['taxonomy'] ) ? $params['taxonomy'] : 'category' );
	endif;

	$walker = new FeedWordPress_Walker_Category_Checklist( $params );
	$walker->set_prefix( $prefix );
	$walker->set_taxonomy( $taxonomy );
	wp_terms_checklist( /*post_id=*/ $post_id, array(
		'taxonomy'             => $taxonomy,
		'descendents_and_self' => $descendents_and_self,
		'selected_cats'        => $selected_cats,
		'popular_cats'         => false,
		'walker'               => $walker,
		'checked_ontop'        => true,
	) );
} /* function fwp_category_checklist() */

/**
 * Calculates the difference between a timestamp and the current time.
 *
 * @param  int $ts Timestamp.
 *
 * @return string A human-readable indication of the elapsed time.
 */
function fwp_time_elapsed( $ts ) {
	if ( ! is_int( $ts ) ) :

	endif;
	if ( function_exists( 'human_time_diff' ) ) :
		if ( $ts >= time() ) :
			$ret = human_time_diff( $ts ) . __( " from now" );
		else :
			$ret = human_time_diff( $ts ) . __( " ago" );
		endif;
	else :
	//	$ret = strftime( '%x %X', $ts );  // deprecated
		$ret = date( "Y-m-d H:i:s", $ts );
	endif;
	return $ret;
} /* function fwp_time_elapsed() */

/**
 * UPGRADE INTERFACE: Have users upgrade DB from older versions of FWP.
 *
 * @uses FeedWordPress
 * @uses FeedWordPress::upgrade_database()
 * @uses get_option()
 * @uses MyPHP::post()
 */
function fwp_upgrade_page() {
	if ( 'Upgrade' == MyPHP::post( 'action' ) ) :
		/** @var string Current FeedWordPress version. */
		$ver = get_option( 'feedwordpress_version' );
		if ( ! empty( $ver ) and FEEDWORDPRESS_VERSION != $ver ) :
			echo "<div class=\"wrap\">\n";
			echo "<h2>" . esc_html__( "Upgrading FeedWordPress..." ) . "</h2>";

			$feedwordpress = new FeedWordPress;
			$feedwordpress->upgrade_database( $ver );
			echo "<p><strong>" . esc_html__( "Done!" ) . "</strong> " . esc_html__( "Upgraded database to version " ) . esc_html( FEEDWORDPRESS_VERSION ) . ".</p>\n";
			echo "<form action=\"\" method=\"get\">\n";
			echo "<div class=\"submit\"><input type=\"hidden\" name=\"page\" value=\"syndication.php\" />";
			echo "<input type=\"submit\" value=\"Continue &raquo;\" /></form></div>\n";
			echo "</div>\n";
			return;
		else :
			echo "<div class=\"updated\"><p>" . esc_html__( "Already at version " ) . esc_html( FEEDWORDPRESS_VERSION ) . "!</p></div>";
		endif;
	endif;
?>
<div class="wrap">
<h2><?php esc_html_e( 'Upgrade FeedWordPress' ); ?></h2>

<p><?php esc_html_e( 'It appears that you have installed FeedWordPress' ); ?>
<?php echo esc_html( FEEDWORDPRESS_VERSION ); ?> <?php esc_html_e( 'as an upgrade to an existing installation of
FeedWordPress. That\'s no problem, but you will need to take a minute out first
to upgrade your database: some necessary changes in how the software keeps
track of posts and feeds will cause problems such as duplicate posts and broken
templates if we were to continue without the upgrade.' ); ?></p>

<p><?php esc_html_e( 'Note that most of FeedWordPress\'s functionality is temporarily disabled
until we have successfully completed the upgrade. Everything should begin
working as normal again once the upgrade is complete. There\'s extraordinarily
little chance of any damage as the result of the upgrade, but if you\'re paranoid
like me, you may want to back up your database before you proceed.' ); ?></p>

<p><?php esc_html_e( 'This may take several minutes for a large installation.' ); ?></p>

<form action="" method="post">
<?php FeedWordPressCompatibility::stamp_nonce( 'feedwordpress_upgrade' ); ?>
<div class="submit"><input type="submit" name="action" value="Upgrade" /></div>
</form>
</div>
<?php
} /* function fwp_upgrade_page() */

/**
 * Filter action for removing dummy zeroes.
 *
 * @todo Requires a better explanation! (gwyneth 20230917)
 *
 * @param  mixed $var  Variable to test.
 *
 * @return int   Possibly returns filtered result
 */
function remove_dummy_zero( $var ) {
	return ! ( is_numeric( $var ) and ( 0 == (int) $var ) );
}
