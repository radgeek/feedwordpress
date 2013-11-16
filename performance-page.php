<?php
require_once(dirname(__FILE__) . '/admin-ui.php');

class FeedWordPressPerformancePage extends FeedWordPressAdminPage {
	function FeedWordPressPerformancePage () {
		// Set meta-box context name
		FeedWordPressAdminPage::FeedWordPressAdminPage('feedwordpressperformancepage');
		$this->dispatch = 'feedwordpress_performance';
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
		FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_performance', /*capability=*/ 'manage_options');
	
		if (strtoupper($_SERVER['REQUEST_METHOD'])=='POST') :
			$this->accept_POST($fwp_post);
			do_action('feedwordpress_admin_page_performance_save', $fwp_post, $this);
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
			fwp_do_meta_boxes($this->meta_box_context(), $this->meta_box_context(), $this);
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
		
		if (isset($post['optimize_in'])) :
			update_option('feedwordpress_optimize_in_clauses', true);
			$this->updated = sprintf(__("Enabled optimizing inefficient IN clauses in SQL queries."));
		elseif (isset($post['optimize_out'])) :
			update_option('feedwordpress_optimize_in_clauses', false);
			$this->updated = sprintf(__("Disabled optimizing inefficient IN clauses in SQL queries."));
		endif;
	} /* FeedWordPressPerformancePage::accept_POST () */

	/*static*/ function performance_box ($page, $box = NULL) {
		$optimize_in = get_option('feedwordpress_optimize_in_clauses', false);
		
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

<tr style="vertical-align: top">
<th width="33%" scope="row">Optimize IN clauses:</th>
<td width="67%"><?php if (!$optimize_in) : ?>
<input class="button" type="submit" name="optimize_in" value="Optimize inefficient IN clauses in SQL queries" />

<p><strong>Advanced setting.</strong> As of releases up to 3.3.2, WordPress
still generates many SQL queries with an extremely inefficient use of the IN
operator (for example, <code>SELECT user_id, meta_key, meta_value FROM
wp_usermeta WHERE user_id IN (1)</code>). When there is only one item in the
set, the IN operator is unnecessary; and inefficient, because it prevents SQL
from making use of indexes on the table being queried. Activating this setting
will cause these queries to get rewritten to use a simple equality operator when
there is only one item in the set (for example, the example query above would be
rewritten as <code>SELECT user_id, meta_key, meta_value FROM wp_usermeta WHERE
user_id = 1</code>).</p>

<p><strong>Note.</strong> This is an advanced setting, which affects WordPress's
database queries at a very low level. The change should be harmless, but
proceed with caution, and only if you are confident in your ability to restore
your WordPress installation from backups if something important should stop
working.</p>

<?php else : ?>
<input class="button" type="submit" name="optimize_out" value="Disable optimizing inefficient IN clauses" />
<p>You can use this setting to disable any attempts by FeedWordPress to optimize
or rewrite WordPress's SQL queries.</p>
<?php endif; ?></td>
</tr>

</td>
</tr>
</table>
		<?php
	} /* FeedWordPressPerformancePage::performance_box () */
} /* class FeedWordPressPerformancePage */

	$performancePage = new FeedWordPressPerformancePage;
	$performancePage->display();

