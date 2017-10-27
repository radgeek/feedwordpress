<?php
require_once(dirname(__FILE__) . '/admin-ui.php');

class FeedWordPressPerformancePage extends FeedWordPressAdminPage {
	public function __construct() {
		// Set meta-box context name
		parent::__construct('feedwordpressperformancepage');
		$this->dispatch = 'feedwordpress_performance';
		$this->filename = __FILE__;
	}

	public function has_link () { return false; }

	function display () {
		if (FeedWordPress::needs_upgrade()) :
			fwp_upgrade_page();
			return;
		endif;

		// If this is a POST, validate source and user credentials
		FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_performance', /*capability=*/ 'manage_options');

		if (strtoupper($_SERVER['REQUEST_METHOD'])=='POST') :
			$this->accept_POST($_POST);
			do_action('feedwordpress_admin_page_performance_save', $_POST, $this);
		endif;

		////////////////////////////////////////////////
		// Prepare settings page ///////////////////////
		////////////////////////////////////////////////

		$this->display_update_notice_if_updated('Performance');

		$this->open_sheet('FeedWordPress Performance');
		?>
		<div id="post-body">
		<?php
		$boxes_by_methods = array(
			'performance_box' => __('Performance'),
		);

		foreach ($boxes_by_methods as $method => $title) :
			add_meta_box(
				/*id=*/ 'feedwordpress_'.$method,
				/*title=*/ $title,
				/*callback=*/ array('FeedWordPressPerformancePage', $method),
				/*page=*/ $this->meta_box_context(),
				/*context=*/ $this->meta_box_context()
			);
		endforeach;
		do_action('feedwordpress_admin_page_performance_meta_boxes', $this);
		?>
			<div class="metabox-holder">
			<?php
			do_meta_boxes($this->meta_box_context(), $this->meta_box_context(), $this);
			?>
			</div> <!-- class="metabox-holder" -->
		</div> <!-- id="post-body" -->

		<?php
		$this->close_sheet();
	} /* FeedWordPressPerformancePage::display () */

	function accept_POST ($post) {
		if (isset($post['create_index'])) :
			FeedWordPress::create_guid_index();
			$this->updated = __('guid column index created on database table.');
		endif;
		if (isset($post['remove_index'])) :
			FeedWordPress::remove_guid_index();
			$this->updated = __('guid column index removed from database table.');
		endif;

		if (isset($post['clear_cache'])) :
			$N = FeedWordPress::clear_cache();
			$feeds = (($N == 1) ? __("feed") : __("feeds"));
			$this->updated = sprintf(__("Cleared %d cached %s from WordPress database."), $N, $feeds);
		endif;

	} /* FeedWordPressPerformancePage::accept_POST () */

	static function performance_box ($page, $box = NULL) {

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
<td width="67%"><?php if (!FeedWordPress::has_guid_index()) : ?>
<input class="button" type="submit" name="create_index" value="Create index on guid column in posts database table" />
<p>Creating this index may significantly improve performance on some large
FeedWordPress installations.</p>
<?php else : ?>

<p>You have already created an index on the guid column in the WordPress posts
table. If you'd like to remove the index for any reason, you can do so here.</p>

<input class="button" type="submit" name="remove_index" value="Remove index on guid column in posts database table" />

<?php endif; ?>

</tr>

</td>
</tr>
</table>
		<?php
	} /* FeedWordPressPerformancePage::performance_box () */
} /* class FeedWordPressPerformancePage */

	$performancePage = new FeedWordPressPerformancePage;
	$performancePage->display();

