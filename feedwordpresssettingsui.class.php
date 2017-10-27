<?php
/**
 * class FeedWordPressSettingsUI: Module to package several functions related to the
 * WordPress administrative / settings interface.
 */
class FeedWordPressSettingsUI {
	static function is_admin () {
		global $fwp_path;

		$admin_page = false; // Innocent until proven guilty
		if (isset($_REQUEST['page'])) :
			$admin_page = (
				is_admin()
				and preg_match("|^{$fwp_path}/|", $_REQUEST['page'])
			);
		endif;
		return $admin_page;
	}

	static function admin_scripts () {
		global $fwp_path;

		wp_enqueue_script('post'); // for magic tag and category boxes
		wp_enqueue_script('admin-forms'); // for checkbox selection

		wp_register_script('feedwordpress-elements', plugins_url('/' . $fwp_path . '/feedwordpress-elements.js') );
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

			postboxes.add_postbox_toggles('<?php print $context; ?>');
			} );
		</script>
	<?php
	} /* FeedWordPressSettingsUI::fix_toggles_js () */

	static function magic_input_tip_js ($id) {
			if (!preg_match('/^[.#]/', $id)) :
				$id = '#'.$id;
			endif;
		?>
			<script type="text/javascript">
			jQuery(document).ready( function () {
				var inputBox = jQuery("<?php print $id; ?>");
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

