<?php
require_once(dirname(__FILE__) . '/admin-ui.php');

class FeedWordPressBackendPage extends FeedWordPressAdminPage {
	function FeedWordPressBackendPage () {
		// Set meta-box context name
		FeedWordPressAdminPage::FeedWordPressAdminPage('feedwordpressbackendpage');
		$this->dispatch = 'feedwordpress_backend';
		$this->filename = __FILE__;
	}

	function has_link () { return false; }

	function display () {
		global $wpdb, $wp_db_version, $fwp_path;
		global $fwp_post;
		
		if (FeedWordPress::needs_upgrade()) :
			fwp_upgrade_page();
			return;
		endif;
	
		// If this is a POST, validate source and user credentials
		FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_backend', /*capability=*/ 'manage_options');
	
		if (strtoupper($_SERVER['REQUEST_METHOD'])=='POST') :
			$this->accept_POST($fwp_post);
			do_action('feedwordpress_admin_page_backend_save', $GLOBALS['fwp_post'], $this);
		endif;

		////////////////////////////////////////////////
		// Prepare settings page ///////////////////////
		////////////////////////////////////////////////

		$this->display_update_notice_if_updated('Back End');

		$this->open_sheet('FeedWordPress Back End');
		?>
		<div id="post-body">
		<?php
		$boxes_by_methods = array(
			'performance_box' => __('Performance'),
			'diagnostics_box' => __('Diagnostics'),
		);
	
		foreach ($boxes_by_methods as $method => $title) :
			fwp_add_meta_box(
				/*id=*/ 'feedwordpress_'.$method,
				/*title=*/ $title,
				/*callback=*/ array('FeedWordPressBackendPage', $method),
				/*page=*/ $this->meta_box_context(),
				/*context=*/ $this->meta_box_context()
			);
		endforeach;
		do_action('feedwordpress_admin_page_backend_meta_boxes', $this);
		?>
			<div class="metabox-holder">
			<?php
			fwp_do_meta_boxes($this->meta_box_context(), $this->meta_box_context(), $this);
			?>
			</div> <!-- class="metabox-holder" -->
		</div> <!-- id="post-body" -->

		<?php
		$this->close_sheet();
	} /* FeedWordPressBackendPage::display () */

	function accept_POST ($post) {
		if (isset($post['submit'])
		or isset($post['save'])
		or isset($post['create_index'])
		or isset($post['clear_cache'])) :
			update_option('feedwordpress_update_logging', $post['update_logging']);
			update_option('feedwordpress_debug', $post['feedwordpress_debug']);
			$this->updated = true; // Default update message
		endif;

		if (isset($post['create_index'])) :
			FeedWordPress::create_guid_index();
			$this->updated = __('Index created on database table.');
		endif;
	
		if (isset($_POST['clear_cache'])) :
			FeedWordPress::clear_cache();
			$this->updated = __("Cleared all cached feeds from WordPress database.");
		endif;
	} /* FeedWordPressBackendPage::accept_POST () */

	/*static*/ function performance_box ($page, $box = NULL) {
		// Hey ho, let's go...
		?>
<table class="editform" width="100%" cellspacing="2" cellpadding="5">
<tr style="vertical-align: top">
<th width="33%" scope="row">Feed cache:</th>
<td width="67%"><input class="button" type="submit" name="clear_cache" value="Clear all cached feeds from WordPress database" />
<p>This will clear all cached copies of feed data from the WordPress database
and force FeedWordPress to make a fresh scan for updates on syndicated feeds.</p></td></tr>

<tr style="vertical-align: top">
<th width="33%" scope="row">Guid index:</th>
<td width="67%"><input class="button" type="submit" name="create_index" value="Create index on guid column in posts database table" />
<p>Creating this index may significantly improve performance on some large
FeedWordPress installations.</p></td>
</tr>
</table>
		<?php
	} /* FeedWordPressBackendPage::performance_box () */

	/*static*/ function diagnostics_box ($page, $box = NULL) {
		$settings = array();
		$settings['update_logging'] = (get_option('feedwordpress_update_logging')=='yes');
		$settings['debug'] = (get_option('feedwordpress_debug')=='yes');

		// Hey ho, let's go...
		?>
<table class="editform" width="100%" cellspacing="2" cellpadding="5">
<tr style="vertical-align: top">
<th width="33%" scope="row">Logging:</th>
<td width="67%"><select name="update_logging" size="1">
<option value="yes"<?php echo ($settings['update_logging'] ?' selected="selected"':''); ?>>log updates, new posts, and updated posts in PHP logs</option>
<option value="no"<?php echo ($settings['update_logging'] ?'':' selected="selected"'); ?>>don't log updates</option>
</select></td>
</tr>
<tr style="vertical-align: top">
<th width="33%" scope="row">Debugging mode:</th>
<td width="67%"><select name="feedwordpress_debug" size="1">
<option value="yes"<?php echo ($settings['debug'] ? ' selected="selected"' : ''); ?>>on</option>
<option value="no"<?php echo ($settings['debug'] ? '' : ' selected="selected"'); ?>>off</option>
</select>
<p>When debugging mode is <strong>ON</strong>, FeedWordPress displays many diagnostic error messages,
warnings, and notices that are ordinarily suppressed, and turns off all caching of feeds. Use with
caution: this setting is absolutely inappropriate for a production server.</p>
</td>
</tr>
</table>
		<?php
	} /* FeedWordPressBackendPage::performance_box () */
} /* class FeedWordPressBackendPage */

	$backendPage = new FeedWordPressBackendPage;
	$backendPage->display();

