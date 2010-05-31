<?php
require_once(dirname(__FILE__) . '/admin-ui.php');

class FeedWordPressAuthorsPage extends FeedWordPressAdminPage {
	var $authorlist = NULL;
	var $rule_count = 0;
	
	function FeedWordPressAuthorsPage ($link) {
		FeedWordPressAdminPage::FeedWordPressAdminPage('feedwordpressauthors', $link);
		$this->authorlist = fwp_author_list();
		$this->dispatch = 'feedwordpress_author_settings';
		$this->filename = __FILE__;
	}
	
	/*static*/ function syndicated_authors_box ($page, $box = NULL) {
		$link = $page->link;
		$unfamiliar = array ('create' => '','default' => '','filter' => '');

		if ($page->for_feed_settings()) :
			$key = $link->setting('unfamiliar author', NULL, 'site-default');
			$unfamiliar['site-default'] = '';
		else :
			$key = FeedWordPress::on_unfamiliar('author');
		endif;
		$unfamiliar[$key] = ' selected="selected"';

		$match_author_by_email = !('yes' == get_option("feedwordpress_do_not_match_author_by_email"));
		$null_emails = FeedWordPress::null_email_set();

		// Hey ho, let's go...
		?>
<table class="form-table">
<tbody>
<tr>
  <th>New authors</th>
  <td><span>Authors who haven't been syndicated before</span>
  <select style="max-width: 27.0em" id="unfamiliar-author" name="unfamiliar_author" onchange="contextual_appearance('unfamiliar-author', 'unfamiliar-author-newuser', 'unfamiliar-author-default', 'newuser', 'inline');">
<?php if ($page->for_feed_settings()) : ?>
    <option value="site-default"<?php print $unfamiliar['site-default']; ?>>are handled according to the default for all feeds</option>
<?php endif; ?>
    <option value="create"<?php print $unfamiliar['create']; ?>>will have a new author account created for them</option>
    <?php foreach ($page->authorlist as $author_id => $author_name) : ?>
      <option value="<?php echo $author_id; ?>"<?php print (isset($unfamiliar[$author_id]) ? $unfamiliar[$author_id] : ''); ?>>will have their posts attributed to <?php echo $author_name; ?></option>
    <?php endforeach; ?>
    <option value="newuser">will have their posts attributed to a new user...</option>
    <option value="filter"<?php print $unfamiliar['filter'] ?>>get filtered out</option>
  </select>

  <span id="unfamiliar-author-newuser">named <input type="text" name="unfamiliar_author_newuser" value="" /></span></p>
  </td>
</tr>

<?php
if ($page->for_feed_settings()) :
?>
<tr><th>Syndicated authors</th>
<td>For attributing posts by specific authors. Blank out a name to delete the rule. Fill in a new name at the bottom to create a new rule.</p>
<table style="width: 100%">
<?php
	if (isset($link->settings['map authors'])) :
?>
<?php
		$page->rule_count=0;
		foreach ($link->settings['map authors'] as $author_rules) :
			foreach ($author_rules as $author_name => $author_action) :
				$page->rule_count++; 
?>
<tr>
<th style="text-align: left; width: 15.0em">Posts by <input type="text" name="author_rules_name[]" value="<?php echo htmlspecialchars($author_name); ?>" size="11" /></th>
  <td>
  <select id="author-rules-<?php echo $page->rule_count; ?>" name="author_rules_action[]" onchange="contextual_appearance('author-rules-<?php echo $page->rule_count; ?>', 'author-rules-<?php echo $page->rule_count; ?>-newuser', 'author-rules-<?php echo $page->rule_count; ?>-default', 'newuser', 'inline');">
    <?php foreach ($page->authorlist as $local_author_id => $local_author_name) : ?>
    <option value="<?php echo $local_author_id; ?>"<?php if ($local_author_id==$author_action) : echo ' selected="selected"'; endif; ?>>are assigned to <?php echo $local_author_name; ?></option>
    <?php endforeach; ?>
    <option value="newuser">will be assigned to a new user...</option>
    <option value="filter"<?php if ('filter'==$author_action) : echo ' selected="selected"'; endif; ?>>get filtered out</option>
  </select>
  
  <span id="author-rules-<?php echo $page->rule_count; ?>-newuser">named <input type="text" name="author_rules_newuser[]" value="" /></span>
  </td>
</tr>
<?php 			endforeach;
		endforeach;
	endif;
?>

<tr>
<th style="text-align: left; width: 15.0em">Posts by <input type="text" name="add_author_rule_name" size="11" /></th>
  <td>
    <select id="add-author-rule" name="add_author_rule_action" onchange="contextual_appearance('add-author-rule', 'add-author-rule-newuser', 'add-author-rule-default', 'newuser', 'inline');">
      <?php foreach ($page->authorlist as $author_id => $author_name) : ?>
      <option value="<?php echo $author_id; ?>">are assigned to <?php echo $author_name; ?></option>
      <?php endforeach; ?>
      <option value="newuser">will be assigned to a new user...</option>
      <option value="filter">get filtered out</option>
    </select>
   
   <span id="add-author-rule-newuser">named <input type="text" name="add_author_rule_newuser" value="" /></span>
   </td>
</tr>
</table>
</td>
</tr>
<?php endif; ?>

<?php if ($page->for_default_settings()) : ?>
<tr>
<th scope="row">Matching Authors</th>
<td><ul style="list-style: none; margin: 0; padding: 0;">
<li><div><label><input id="match-author-by-email" type="checkbox" name="match_author_by_email" value="yes" <?php if ($match_author_by_email) : ?>checked="checked" <?php endif; ?>onchange="contextual_appearance('match-author-by-email', 'unless-null-email', null, 'yes', 'block', /*checkbox=*/ true);" /> Treat syndicated authors with the same e-mail address as the same author.</label></div>
<div id="unless-null-email">
<p>Unless the e-mail address is one of the following anonymous e-mail addresses:</p>
<textarea name="null_emails" rows="3" style="width: 100%">
<?php print implode("\n", $null_emails); ?>
</textarea>
</div></li>
</ul></td>
</tr>
<?php endif; ?>
</tbody>
</table>
		<?php
	} /* FeedWordPressAuthorsPage::syndicated_authors_box () */
	
	/*static*/ function fix_authors_box ($page, $box = NULL) {
		?>
		<table class="form-table">
		<tbody>
		<tr>
		<th scope="row">Fixing mis-matched authors:</th>
		<td><p style="margin: 0.5em 0px">Take all the posts from this feed attributed to
		<select name="fix_mismatch_from">
		<?php foreach ($page->authorlist as $author_id => $author_name) : ?>
		      <option value="<?php echo $author_id; ?>"><?php echo $author_name; ?></option>
		<?php endforeach; ?>
		</select>
		and instead
		<select id="fix-mismatch-to" name="fix_mismatch_to" onchange="contextual_appearance('fix-mismatch-to', 'fix-mismatch-to-newuser', null, 'newuser', 'inline');">
		<?php foreach ($page->authorlist as $author_id => $author_name) : ?>
		      <option value="<?php echo $author_id; ?>">re-assign them to <?php echo $author_name; ?></option>
		<?php endforeach; ?>
		      <option value="newuser">re-assign them to a new user...</option>
		      <option value="filter">delete them</option>
		</select>

		   <span id="fix-mismatch-to-newuser">named <input type="text" name="fix_mismatch_to_newuser" value="" /></span>
		   <input type="submit" class="button" name="fix_mismatch" value="Fix it!" />
		</td>
		</tr>
		</tbody>
		</table>
		<?php
	} /* FeedWordPressAuthorsPage::fix_authors_box () */
} /* class FeedWordPressAuthorsPage */

function fwp_authors_page () {
	global $wpdb, $wp_db_version;

	if (FeedWordPress::needs_upgrade()) :
		fwp_upgrade_page();
		return;
	endif;

	FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_author_settings', /*capability=*/ 'manage_links');

	$link = FeedWordPressAdminPage::submitted_link();
	$authorsPage = new FeedWordPressAuthorsPage($link);

	$mesg = null;

	if (isset($GLOBALS['fwp_post']['fix_mismatch'])) :
		if ('newuser'==$GLOBALS['fwp_post']['fix_mismatch_to']) :
			$newuser_name = trim($GLOBALS['fwp_post']['fix_mismatch_to_newuser']);
			if (strlen($newuser_name) > 0) :
				$userdata = array();
				$userdata['ID'] = NULL;
				
				$userdata['user_login'] = sanitize_user($newuser_name);
				$userdata['user_login'] = apply_filters('pre_user_login', $userdata['user_login']);
				
				$userdata['user_nicename'] = sanitize_title($newuser_name);
				$userdata['user_nicename'] = apply_filters('pre_user_nicename', $userdata['user_nicename']);
				
				$userdata['display_name'] = $wpdb->escape($newuser_name);

				$newuser_id = wp_insert_user($userdata);
				if (is_numeric($newuser_id)) :
					$fix_mismatch_to_id = $newuser_id;
				else :
					// TODO: Add some error detection and reporting
				endif;
			else :
				// TODO: Add some error reporting
			endif;			
		else :
			$fix_mismatch_to_id = $GLOBALS['fwp_post']['fix_mismatch_to'];
		endif;
		$fix_mismatch_from_id = (int) $GLOBALS['fwp_post']['fix_mismatch_from'];
		if (is_numeric($fix_mismatch_from_id)) :
			// Make a list of all the items by this author syndicated from this feed...
			$post_ids = $wpdb->get_col("
			SELECT {$wpdb->posts}.id
			FROM {$wpdb->posts}, {$wpdb->postmeta}
			WHERE ({$wpdb->posts}.id = {$wpdb->postmeta}.post_id)
			AND {$wpdb->postmeta}.meta_key = 'syndication_feed_id'
			AND {$wpdb->postmeta}.meta_value = '{$link->id}'
			AND {$wpdb->posts}.post_author = '{$fix_mismatch_from_id}'
			");
			
			if (count($post_ids) > 0) :
				// Re-assign them all to the correct author
				if (is_numeric($fix_mismatch_to_id)) : // re-assign to a particular user
					$post_set = "(".implode(",", $post_ids).")";
					
					// Getting the revisions too, if there are any
					if (fwp_test_wp_version(FWP_SCHEMA_26)) :
						$parent_in_clause = "OR {$wpdb->posts}.post_parent IN $post_set";
					else :
						$parent_in_clause = '';
					endif;
					
					$wpdb->query("
					UPDATE {$wpdb->posts}
					SET post_author='{$fix_mismatch_to_id}'
					WHERE ({$wpdb->posts}.id IN $post_set
					$parent_in_clause)
					");
					$mesg = "Re-assigned ".count($post_ids)." post".((count($post_ids)==1)?'':'s').".";

				// ... and kill them all
				elseif ($fix_mismatch_to_id=='filter') :
					foreach ($post_ids as $post_id) :
						wp_delete_post($post_id);
					endforeach;						
					$mesg = "Deleted ".count($post_ids)." post".((count($post_ids)==1)?'':'s').".";
				endif;
			else :
				$mesg = "Couldn't find any posts that matched your criteria.";
			endif;
		endif;
	elseif (isset($GLOBALS['fwp_post']['save'])) :
		if (is_object($link) and $link->found()) :
			$alter = array ();

			// Unfamiliar author rule
			if (isset($GLOBALS['fwp_post']["unfamiliar_author"])) :
				if ('site-default'==$GLOBALS['fwp_post']["unfamiliar_author"]) :
					unset($link->settings["unfamiliar author"]);
				elseif ('newuser'==$GLOBALS['fwp_post']["unfamiliar_author"]) :
					$newuser_name = trim($GLOBALS['fwp_post']["unfamiliar_author_newuser"]);
					$link->map_name_to_new_user(/*name=*/ NULL, $newuser_name);
				else :
					$link->settings["unfamiliar author"] = $GLOBALS['fwp_post']["unfamiliar_author"];
				endif;
			endif;
			
			// Handle author mapping rules
			if (isset($GLOBALS['fwp_post']['author_rules_name']) and isset($GLOBALS['fwp_post']['author_rules_action'])) :
				unset($link->settings['map authors']);
				foreach ($GLOBALS['fwp_post']['author_rules_name'] as $key => $name) :
					// Normalize for case and whitespace
					$name = strtolower(trim($name));
					$author_action = strtolower(trim($GLOBALS['fwp_post']['author_rules_action'][$key]));
					
					if (strlen($name) > 0) :
						if ('newuser' == $author_action) :
							$newuser_name = trim($GLOBALS['fwp_post']['author_rules_newuser'][$key]);
							$link->map_name_to_new_user($name, $newuser_name);
						else :
							$link->settings['map authors']['name'][$name] = $author_action;
						endif;
					endif;
				endforeach;
			endif;

			if (isset($GLOBALS['fwp_post']['add_author_rule_name']) and isset($GLOBALS['fwp_post']['add_author_rule_action'])) :
				$name = strtolower(trim($GLOBALS['fwp_post']['add_author_rule_name']));
				$author_action = strtolower(trim($GLOBALS['fwp_post']['add_author_rule_action']));
				if (strlen($name) > 0) :
					if ('newuser' == $author_action) :
						$newuser_name = trim($GLOBALS['fwp_post']['add_author_rule_newuser']);
						$link->map_name_to_new_user($name, $newuser_name);
					else :
						$link->settings['map authors']['name'][$name] = $author_action;
					endif;
				endif;
			endif;
			
			// Save settings
			$link->save_settings(/*reload=*/ true);
			$updated_link = true;
			
			// Reset, reload
			$link_id = $link->id;
			unset($link);
			$link = new SyndicatedLink($link_id);
		else :
			if ('newuser'==$GLOBALS['fwp_post']['unfamiliar_author']) :
				$newuser_name = trim($GLOBALS['fwp_post']['unfamiliar_author_newuser']);
				if (strlen($newuser_name) > 0) :
					$userdata = array();
					$userdata['ID'] = NULL;
					
					$userdata['user_login'] = sanitize_user($newuser_name);
					$userdata['user_login'] = apply_filters('pre_user_login', $userdata['user_login']);
					
					$userdata['user_nicename'] = sanitize_title($newuser_name);
					$userdata['user_nicename'] = apply_filters('pre_user_nicename', $userdata['user_nicename']);
					
					$userdata['display_name'] = $wpdb->escape($newuser_name);

					$newuser_id = wp_insert_user($userdata);
					if (is_numeric($newuser_id)) :
						update_option('feedwordpress_unfamiliar_author', $newuser_id);
					else :
						// TODO: Add some error detection and reporting
					endif;
				else :
					// TODO: Add some error reporting
				endif;			
			else :
				update_option('feedwordpress_unfamiliar_author', $GLOBALS['fwp_post']['unfamiliar_author']);
			endif;

			if (isset($GLOBALS['fwp_post']['match_author_by_email']) and $GLOBALS['fwp_post']['match_author_by_email']=='yes') :
				update_option('feedwordpress_do_not_match_author_by_email', 'no');
			else :
				update_option('feedwordpress_do_not_match_author_by_email', 'yes');
			endif;

			if (isset($GLOBALS['fwp_post']['null_emails'])) :
				update_option('feedwordpress_null_email_set', $GLOBALS['fwp_post']['null_emails']);
			endif;
			
			$updated_link = true;
		endif;

		do_action('feedwordpress_admin_page_authors_save', $GLOBALS['fwp_post'], $authorsPage);
	else :
		$updated_link = false;
	endif;
	
	////////////////////////////////////////////////
	// Prepare settings page ///////////////////////
	////////////////////////////////////////////////

	if ($updated_link) :
?>
<div class="updated"><p>Syndicated author settings updated.</p></div>
<?php elseif (!is_null($mesg)) : ?>
<div class="updated"><p><?php print esc_html($mesg); ?></p></div>
<?php endif;

	if (function_exists('add_meta_box')) :
		add_action(
			FeedWordPressCompatibility::bottom_script_hook(__FILE__),
			/*callback=*/ array($authorsPage, 'fix_toggles'),
			/*priority=*/ 10000
		);
		FeedWordPressSettingsUI::ajax_nonce_fields();
	endif;

	$authorsPage->open_sheet('Syndicated Author');
	?>
	<div id="post-body">
	<?php
	////////////////////////////////////////////////
	// Display settings boxes //////////////////////
	////////////////////////////////////////////////

	$boxes_by_methods = array(
		'syndicated_authors_box' => __('Syndicated Authors'),
		'fix_authors_box' => __('Reassign Authors'),
	);
	if ($authorsPage->for_default_settings()) :
		unset($boxes_by_methods['fix_authors_box']);
	endif;

	foreach ($boxes_by_methods as $method => $row) :
		if (is_array($row)) :
			$id = $row['id'];
			$title = $row['title'];
		else :
			$id = 'feedwordpress_'.$method;
			$title = $row;
		endif;

		fwp_add_meta_box(
			/*id=*/ $id,
			/*title=*/ $title,
			/*callback=*/ array('FeedWordPressAuthorsPage', $method),
			/*page=*/ $authorsPage->meta_box_context(),
			/*context=*/ $authorsPage->meta_box_context()
		);
	endforeach;
	do_action('feedwordpress_admin_page_authors_meta_boxes', $authorsPage);
?>
	<div class="metabox-holder">
<?php
	fwp_do_meta_boxes($authorsPage->meta_box_context(), $authorsPage->meta_box_context(), $authorsPage);
?>
	</div> <!-- class="metabox-holder" -->
</div> <!-- id="post-body" -->
<?php $authorsPage->close_sheet(); ?>

<script type="text/javascript">
	contextual_appearance('unfamiliar-author', 'unfamiliar-author-newuser', 'unfamiliar-author-default', 'newuser', 'inline');
<?php if (is_object($link) and $link->found()) : ?>
<?php 	for ($j=1; $j<=$authorsPage->rule_count; $j++) : ?>
	contextual_appearance('author-rules-<?php echo $j; ?>', 'author-rules-<?php echo $j; ?>-newuser', 'author-rules-<?php echo $j; ?>-default', 'newuser', 'inline');
<?php 	endfor; ?>
	contextual_appearance('add-author-rule', 'add-author-rule-newuser', 'add-author-rule-default', 'newuser', 'inline');
	contextual_appearance('fix-mismatch-to', 'fix-mismatch-to-newuser', null, 'newuser', 'inline');
<?php else : ?>
	contextual_appearance('match-author-by-email', 'unless-null-email', null, 'yes', 'block', /*checkbox=*/ true);
<?php endif; ?>
</script>
<?php
} /* function fwp_authors_page () */

	fwp_authors_page();

