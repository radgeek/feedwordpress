<?php
/**
 * class FeedWordPressSettingsUI: Module to package several functions related to the
 * WordPress administrative / settings interface.
 */
class FeedWordPressSettingsUI {
	/**
	 * Checks if current user is an administrator with permissions to
	 * see the FWP dashboard.
	 *
	 * @return bool True if current user is an administrator.
	 *
	 * @uses is_admin()
	 * @uses FeedWordPress::path()
	 * @uses MyPHP::request()
	 */
	static function is_admin() {
		$admin_page = false; // Innocent until proven guilty
		if ( ! is_null( MyPHP::request( 'page' ) ) ) :
			$fwp = preg_quote( FeedWordPress::path() );
			$admin_page = (
				is_admin()
				and preg_match( "|^{$fwp}/|", MyPHP::request( 'page' ) )
			);
		endif;
		return $admin_page;
	}

	/**
	 * Enqueues JavaScript for the administration dashboard.
	 */
	static function admin_scripts() {
		wp_enqueue_script( 'post' ); // for magic tag and category boxes
		wp_enqueue_script( 'admin-forms' ); // for checkbox selection

		wp_register_script( 'feedwordpress-elements', plugins_url( 'assets/js/feedwordpress-elements.js', __FILE__ ) );
		wp_enqueue_script( 'feedwordpress-elements' );
	}

	/**
	 * Sets the CSS for the administration dashboard.
	 *
	 * 	@todo At some point in the future, these ought to be changed to modern designs,
	 * 	i.e. either Dashicons or the upcoming SVG icon fonts. (gwyneth 20210717)
	 */
	static function admin_styles() {
		?>
		<style type="text/css">
		#feedwordpress-admin-feeds .link-rss-params-remove .x, .feedwordpress-admin .remove-it .x {
			content: "\f153"; /* dashicons-dismiss */
			color: var(--wp-components-color-foreground,#1e1e1e);
			background-color: var(--wp-components-color-background);
		}
		#feedwordpress-admin-feeds .link-rss-params-remove:hover .x, .feedwordpress-admin .remove-it:hover .x {
			content: "\f153"; /* dashicons-dismiss */
			color: var(--wp-components-color-accent);
			background-color: var(--wp-components-color-background);
		}

		/* Note: the old images referred here were deprecated around 2009 or so and are *not*
			part of the WordPress core any more; see https://core.trac.wordpress.org/ticket/20980
			I have placed these missing images on the images folder instead (gwyneth 20210717) */
		.fwpfs {
			background-image: url(<?php echo esc_url( plugins_url( 'assets/images/fav.png', __FILE__ ) ); ?>);
			background-repeat: repeat-x;
			background-position: left center;
			background-attachment: scroll;
		}
		.fwpfs.slide-down {
			background-image: url(<?php echo esc_url( plugins_url( 'assets/images/fav-top.png', __FILE__ ) ); ?>);
			background-position: 0 top;
			background-repeat: repeat-x;
		}
		.update-results {
			max-width: 100%;
			overflow: auto;
		}
		</style>
		<?php
	} /* FeedWordPressSettingsUI::admin_styles () */

	/**
	 * Makes sure we have nonce active.
	 *
	 * @uses wp_nonce_field()
	 */
	static function ajax_nonce_fields() {
		if ( function_exists( 'wp_nonce_field' ) ) :
			echo "<form style='display: none' method='get' action=''>\n<p>\n";
			wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false );
			wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false );
			echo "</p>\n</form>\n";
		endif;
	} /* FeedWordPressSettingsUI::ajax_nonce_fields () */

	static function fix_toggles_js ($context) {
	?>
		<script type="text/javascript">
			jQuery(document).ready( function($) {
			// In case someone got here first...
			$('.postbox h3, .postbox .handlediv').unbind('click');
			$('.postbox h3 a').unbind('click');
			$('.hide-postbox-tog').unbind('click');
			$('.columns-prefs input[type="radio"]').unbind('click');
			$('.meta-box-sortables').sortable('destroy');

			postboxes.add_postbox_toggles('<?php print esc_attr( $context ); ?>');
			} );
		</script>
	<?php
	} /* FeedWordPressSettingsUI::fix_toggles_js () */

	/**
	 * get_template_part: load a template (usually templated HTML) from the FeedWordPress plugins
	 * directory, in a way similar to the WordPress theme function get_template_part() loads a
	 * template module from the theme directory.
	 *
	 * @param string $slug The slug name for the generic template
	 * @param string $name The name of the specialized template
	 * @param array $args Additional arguments passed to the template.
	 */
	static public function get_template_part( $slug, $name = null, $type = null, $args = array() ) {
		global $feedwordpress;

		do_action( "feedwordpress_get_template_part_{$slug}", $slug, $name, $type, $args );

		$templates = array();
		$name = (string) $name;
		$type = (string) $type;

		$ext = ".php";
		if ( strlen( $type ) > 0 ):
			$ext = ".{$type}{$ext}";
		endif;

		if ( strlen( $name ) > 0 ) :
			$templates[] = "{$slug}-{$name}{$ext}";
		endif;
		$templates[] = "{$slug}{$ext}";

		do_action( "feedwordpress_get_template_part", $slug, $name, $type, $args );

		// locate_template
		$located = '';
		foreach ( $templates as $template_name ) :
			if ( !! $template_name ) :
				$templatePath = $feedwordpress->plugin_dir_path( 'templates/' . $template_name );
				if ( is_readable( $templatePath ) ) :
					$located = $templatePath;
					break;
				endif;
			endif;
		endforeach;

		if ( strlen( $located ) > 0 ) :
			load_template( $located, /*require_once=*/ false, /*args=*/ $args );
		endif;
	} /* FeedWordPressSettingsUI::get_template_part () */

	/**
	 * Generates contextual hovering text with tips for the form fields.
	 *
	 * How exactly this works is beyond myself. (gwyneth 20230916)
	 *
	 * @param string $id Apparently it's the field's id attribute.
	 *
	 */
	static function magic_input_tip_js( $id ) {
			if ( ! preg_match( '/^[.#]/', $id ) ) :
				$id = '#' . $id;
			endif;
		?>
			<script type="text/javascript">
			jQuery( document ).ready( function () {
				var inputBox = jQuery( "<?php print esc_attr( $id ); ?>" );
				var boxEl = inputBox.get( 0 );
				if ( boxEl.value == boxEl.defaultValue ) {
					inputBox.addClass( 'form-input-tip' );
				}
				inputBox.focus(function() {
					if ( this.value == this.defaultValue )
						jQuery( this ).val( '' ).removeClass( 'form-input-tip' );
				});
				inputBox.blur( function() {
					if ( this.value == '' )
						jQuery( this ).val( this.defaultValue ).addClass( 'form-input-tip' );
				});
			} );
			</script>
		<?php
	} /* FeedWordPressSettingsUI::magic_input_tip_js () */
} /* class FeedWordPressSettingsUI */

