<?php
/**
 * class FeedWordPressSettingsUI: Module to package several functions related to the
 * WordPress administrative / settings interface.
 */
class FeedWordPressSettingsUI {
	static function is_admin () {
		
		$admin_page = false; // Innocent until proven guilty
		if (!is_null(MyPHP::request('page'))) :
			$fwp = preg_quote(FeedWordPress::path());
			$admin_page = (
				is_admin()
				and preg_match("|^${fwp}/|", MyPHP::request('page'))
			);
		endif;
		return $admin_page;
	}

	static function admin_scripts () {
		wp_enqueue_script('post'); // for magic tag and category boxes
		wp_enqueue_script('admin-forms'); // for checkbox selection

		wp_register_script('feedwordpress-elements', plugins_url( 'assets/js/feedwordpress-elements.js', __FILE__ ) );
		wp_enqueue_script('feedwordpress-elements');
	}

	static function admin_styles () {
		?>
		<style type="text/css">
		#feedwordpress-admin-feeds .link-rss-params-remove .x, .feedwordpress-admin .remove-it .x {
			background: url(<?php print admin_url('images/xit.gif') ?>) no-repeat scroll 0 0 transparent;
		}

		#feedwordpress-admin-feeds .link-rss-params-remove:hover .x, .feedwordpress-admin .remove-it:hover .x {
			background: url(<?php print admin_url('images/xit.gif') ?>) no-repeat scroll -10px 0 transparent;
		}

		.fwpfs {
			background-image: url(<?php print admin_url('images/fav.png'); ?>);
			background-repeat: repeat-x;
			background-position: left center;
			background-attachment: scroll;
		}
		.fwpfs.slide-down {
			background-image:url(<?php print admin_url('images/fav-top.png'); ?>);
			background-position:0 top;
			background-repeat:repeat-x;
		}

		.update-results {
			max-width: 100%;
			overflow: auto;
		}

		</style>
		<?php
	} /* FeedWordPressSettingsUI::admin_styles () */

	static function ajax_nonce_fields () {
		if (function_exists('wp_nonce_field')) :
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
	static public function get_template_part ( $slug, $name = null, $type = null, $args = array() ) {
		global $feedwordpress;
		
		do_action( "feedwordpress_get_template_part_${slug}", $slug, $name, $type, $args );

		$templates = array();
		$name = (string) $name;
		$type = (string) $type;
		
		$ext = ".php";
		if ( strlen($type) > 0 ):
			$ext = ".${type}${ext}";
		endif;
		
		if ( strlen($name) > 0 ) :
			$templates[] = "${slug}-${name}${ext}";
		endif;
		$templates[] = "${slug}${ext}";
		
		do_action( "feedwordpress_get_template_part", $slug, $name, $type, $args );
		
		// locate_template
		$located = '';
		foreach ( $templates as $template_name ) :
			if ( !! $template_name ) :
				$templatePath = $feedwordpress->plugin_dir_path('templates/' . $template_name);
				if ( is_readable( $templatePath ) ) :
					$located = $templatePath;
					break;
				endif;
			endif;
		endforeach;
		
		if ( strlen($located) > 0 ) :
			load_template( $located, /*require_once=*/ false, /*args=*/ $args );
		endif;
	} /* FeedWordPressSettingsUI::get_template_part () */
	
	static function magic_input_tip_js ($id) {
			if (!preg_match('/^[.#]/', $id)) :
				$id = '#'.$id;
			endif;
		?>
			<script type="text/javascript">
			jQuery(document).ready( function () {
				var inputBox = jQuery("<?php print esc_attr( $id ); ?>");
				var boxEl = inputBox.get(0);
				if (boxEl.value==boxEl.defaultValue) { inputBox.addClass('form-input-tip'); }
				inputBox.focus(function() {
					if ( this.value == this.defaultValue )
						jQuery(this).val( '' ).removeClass( 'form-input-tip' );
				});
				inputBox.blur(function() {
					if ( this.value == '' )
						jQuery(this).val( this.defaultValue ).addClass( 'form-input-tip' );
				});
			} );
			</script>
		<?php
	} /* FeedWordPressSettingsUI::magic_input_tip_js () */
} /* class FeedWordPressSettingsUI */

