<?php
/**
 * feedwordpress.wp-admin.post-edit.functions.php
 *
 * @author radgeek
 */

/**
 * Adds edit controls on a post
 *
 * @uses InspectPostMeta
 * @uses FeedWordPressDiagnostic::is_on()
 * @global $feedwordpress
 * @global $inspectPostMeta
 *
 */
function feedwordpress_add_post_edit_controls() {
	global $feedwordpress;
	global $inspectPostMeta;

	// Put in Manual Editing checkbox
	add_action( 'add_meta_boxes', 'feedwordpress_post_add_meta_boxes', 10, 2 );

	add_filter( 'user_can_richedit', array( $feedwordpress, 'user_can_richedit' ), 1000, 1);

	if ( FeedWordPressDiagnostic::is_on( 'syndicated_posts:static_meta_data' ) ) :
		$inspectPostMeta = new InspectPostMeta;
	endif;
} /* function feedwordpress_add_post_edit_controls() */

/**
 * Adds meta box on this post.
 *
 * For a more detailed description of the parameters,
 * @see add_meta_box()
 *
 * @param  string|array|WP_Screen  $post_type  Screen(s) where to show the meta box.
 * @param  int                     $post       Unused.
 */
function feedwordpress_post_add_meta_boxes( $post_type, $post ) {
	add_meta_box(
		'feedwordpress-post-controls',
		__( 'Syndication' ),
		'feedwordpress_post_edit_controls',
		$post_type,
		'side',
		'high'
	);
} /* function feedwordpress_post_add_meta_boxes() */

/**
 * Displays the post edit controls.
 *
 * @uses get_post_custom_values()
 * @global $post
 */
function feedwordpress_post_edit_controls() {
	global $post;

	$frozen_values = get_post_custom_values( '_syndication_freeze_updates', $post->ID );
	$frozen_post = ( null !== $frozen_values and count( $frozen_values ) > 0 and 'yes' == $frozen_values[0] );

	if ( ! empty( $post ) and ! empty( $post->ID ) and is_syndicated( $post->ID ) ) :
	?>
	<p><?php esc_html_e( 'This is a syndicated post, which originally appeared at' ); ?>
	<cite><?php print esc_html( get_syndication_source( NULL, $post->ID ) ); ?></cite>.
	<a href="<?php print esc_html( get_syndication_permalink( $post->ID ) ); ?>"><?php esc_html_e( 'View original post' ); ?></a>.</p>

	<?php do_action( 'feedwordpress_post_edit_controls_pre', $post ); ?>

	<p><input type="hidden" name="feedwordpress_noncename" id="feedwordpress_noncename" value="<?php print esc_attr( wp_create_nonce( plugin_basename( __FILE__ ) ) ); ?>" />
	<label><input type="checkbox" name="freeze_updates" value="yes" <?php if ( $frozen_post ) : ?>checked="checked"<?php endif; ?> /> <strong><?php esc_html_e( 'Manual editing.' ); ?></strong>
	<?php esc_html_e( 'If set, FeedWordPress will not overwrite the changes you make manually to this post, if the syndicated content is updated on the feed.' ); ?></label></p>

	<?php do_action( 'feedwordpress_post_edit_controls', $post ); ?>

	<?php
	else :
	?>
	<p><?php esc_html_e( 'This post was created locally at this website.' ); ?></p>
	<?php
	endif;
} /* function feedwordpress_post_edit_controls() */

/**
 * Displays the saved post edit controls.
 *
 * @param  int   $post_id  WordPress post ID.
 *
 * @return int|mixed|null  Post ID or null if something failed.
 *
 * @global $post
 */
function feedwordpress_save_post_edit_controls( $post_id ) {
	global $post;

	$noncename = FeedWordPress::post( 'feedwordpress_noncename' );

	if ( is_null( $noncename ) || ! wp_verify_nonce( $noncename, plugin_basename( __FILE__ ) ) ) :
		return $post_id;
	endif;

	// Verify if this is an auto save routine. If it is our form has
	// not been submitted, so we don't want to do anything.
	if ( defined( 'DOING_AUTOSAVE' ) and DOING_AUTOSAVE ) :
		return $post_id;
	endif;

	// The data in $_POST is for applying only to the post actually
	// in the edit window, i.e. $post
	if ( $post_id != $post->ID ) :
		return $post_id;
	endif;

	// Check permissions
	$cap[0] = 'edit_post';

	$post_type = FeedWordPress::post( 'post_type' );
	$cap[1] = sanitize_key( 'edit_' . $post_type );

	if (
		    ! current_user_can( $cap[0], $post_id )
		and ! current_user_can( $cap[1], $post_id )
	) :
		return $post_id;
	endif;

	// OK, we're golden. Now let's save some data.
	$freeze_updates = FeedWordPress::post( 'freeze_updates' );
	if ( ! is_null( $freeze_updates ) ) :
		update_post_meta(
			$post_id,
			'_syndication_freeze_updates',
			sanitize_meta( '_syndication_freeze_updates', $freeze_updates, 'post' )
		);
		$ret = $freeze_updates;

		// If you make manual edits through the WordPress editing
		// UI then they should be run through normal WP formatting
		// filters.
		update_post_meta( $post_id, '_feedwordpress_formatting_filters', 'yes' );
	else :
		delete_post_meta( $post_id, '_syndication_freeze_updates' );
		$ret = NULL;
	endif;

	do_action( 'feedwordpress_save_edit_controls', $post_id );

	return $ret;
} /* function feedwordpress_save_edit_controls() */
