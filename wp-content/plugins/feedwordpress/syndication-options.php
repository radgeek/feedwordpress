<?php
function fwp_syndication_options_page () {
        global $wpdb, $wp_db_version;
	
	if (FeedWordPress::needs_upgrade()) :
		fwp_upgrade_page();
		return;
	endif;

	$caption = 'Save Changes';
	if (isset($_POST['action']) and $_POST['action']==$caption):
		check_admin_referer();

		if (!current_user_can('manage_options')):
			die (__("Cheatin' uh ?"));
		else:
			update_option('feedwordpress_cat_id', $_REQUEST['syndication_category']);
			update_option('feedwordpress_munge_permalink', $_REQUEST['munge_permalink']);
			update_option('feedwordpress_update_logging', $_REQUEST['update_logging']);
			update_option('feedwordpress_unfamiliar_author', $_REQUEST['unfamiliar_author']);
			update_option('feedwordpress_unfamiliar_category', $_REQUEST['unfamiliar_category']);
			update_option('feedwordpress_syndicated_post_status', $_REQUEST['post_status']);
			update_option('feedwordpress_automatic_updates', ($_POST['automatic_updates']=='yes'));
			update_option('feedwordpress_freshness',  ($_POST['freshness_interval']*60));

			// Categories
			$cats = array();
			if (isset($_POST['post_category'])) :
				$cats = array();
				foreach ($_POST['post_category'] as $cat_id) :
					$cats[] = '{#'.$cat_id.'}';
				endforeach;
			endif;

			if (!empty($cats)) :
				update_option('feedwordpress_syndication_cats', implode("\n", $cats));
			else :
				delete_option('feedwordpress_syndication_cats');
			endif;

			if (isset($_REQUEST['comment_status']) and ($_REQUEST['comment_status'] == 'open')) :
				update_option('feedwordpress_syndicated_comment_status', 'open');
			else :
				update_option('feedwordpress_syndicated_comment_status', 'closed');
			endif;

			if (isset($_REQUEST['ping_status']) and ($_REQUEST['ping_status'] == 'open')) :
				update_option('feedwordpress_syndicated_ping_status', 'open');
			else :
				update_option('feedwordpress_syndicated_ping_status', 'closed');
			endif;
			
			if (isset($_REQUEST['hardcode_name']) and ($_REQUEST['hardcode_name'] == 'no')) :
				update_option('feedwordpress_hardcode_name', 'no');
			else :
				update_option('feedwordpress_hardcode_name', 'yes');
			endif;
			
			if (isset($_REQUEST['hardcode_description']) and ($_REQUEST['hardcode_description'] == 'no')) :
				update_option('feedwordpress_hardcode_description', 'no');
			else :
				update_option('feedwordpress_hardcode_description', 'yes');
			endif;

			if (isset($_REQUEST['hardcode_url']) and ($_REQUEST['hardcode_url'] == 'no')) :
				update_option('feedwordpress_hardcode_url', 'no');
			else :
				update_option('feedwordpress_hardcode_url', 'yes');
			endif;
?>
<div class="updated">
<p><?php _e('Options saved.')?></p>
</div>
<?php
		endif;
	endif;

	$cat_id = FeedWordPress::link_category_id();
	$munge_permalink = get_option('feedwordpress_munge_permalink');
	$update_logging = get_option('feedwordpress_update_logging');

	$automatic_updates = get_option('feedwordpress_automatic_updates');

	$freshness_interval = get_option('feedwordpress_freshness');
	if (false === $freshness_interval) :
		$freshness_interval = FEEDWORDPRESS_FRESHNESS_INTERVAL;
	endif;
	$freshness_interval = $freshness_interval / 60; // convert to minutes

	$hardcode_name = get_option('feedwordpress_hardcode_name');
	$hardcode_description = get_option('feedwordpress_hardcode_description');
	$hardcode_url = get_option('feedwordpress_hardcode_url');

	$post_status = get_option("feedwordpress_syndicated_post_status"); // default="publish"
	$comment_status = get_option("feedwordpress_syndicated_comment_status"); // default="closed"
	$ping_status = get_option("feedwordpress_syndicated_ping_status"); // default="closed"

	$unfamiliar_author = array ('create' => '','default' => '','filter' => '');
	$ua = FeedWordPress::on_unfamiliar('author');
	if (is_string($ua) and array_key_exists($ua, $unfamiliar_author)) :
		$unfamiliar_author[$ua] = ' checked="checked"';
	endif;
	$unfamiliar_category = array ('create'=>'','default'=>'','filter'=>'');
	$uc = FeedWordPress::on_unfamiliar('category');
	if (is_string($uc) and array_key_exists($uc, $unfamiliar_category)) :
		$unfamiliar_category[$uc] = ' checked="checked"';
	endif;
	
	if (isset($wp_db_version) and $wp_db_version >= 4772) :
		$results = get_categories('type=link');
		
		// Guarantee that the Contributors category will be in the drop-down chooser, even if it is empty.
		$found_link_category_id = false;
		foreach ($results as $row) :
			if ($row->cat_id == $cat_id) :	$found_link_category_id = true;	endif;
		endforeach;
		
		if (!$found_link_category_id) :	$results[] = get_category($cat_id); endif;
	else :
		$results = $wpdb->get_results("SELECT cat_id, cat_name, auto_toggle FROM $wpdb->linkcategories ORDER BY cat_id");
	endif;

	$cats = get_option('feedwordpress_syndication_cats');
	$dogs = get_nested_categories(-1, 0);
	$cats = array_map('strtolower',
		array_map('trim',
			preg_split(FEEDWORDPRESS_CAT_SEPARATOR_PATTERN, $cats)
		));
	
	foreach ($dogs as $tag => $dog) :
		$found_by_name = in_array(strtolower(trim($dog['cat_name'])), $cats);
		if (isset($dog['cat_ID'])) : $dog['cat_id'] = $dog['cat_ID']; endif;
		$found_by_id = in_array('{#'.trim($dog['cat_id']).'}', $cats);

		if ($found_by_name or $found_by_id) :
			$dogs[$tag]['checked'] = true;
		endif;
	endforeach;

?>
<div class="wrap">
<h2>Syndication Options</h2>
<form action="" method="post">
<fieldset class="options">
<legend>Syndicated Feeds</legend>
<table class="editform" width="100%" cellspacing="2" cellpadding="5">
<tr>
<th width="33%" scope="row">Syndicate links in category:</th>
<td width="67%"><?php
		echo "\n<select name=\"syndication_category\" size=\"1\">";
		foreach ($results as $row) {
			if (!isset($row->cat_id)) { $row->cat_id = $row->cat_ID; }
			
			echo "\n\t<option value=\"$row->cat_id\"";
			if ($row->cat_id == $cat_id)
				echo " selected='selected'";
			echo ">$row->cat_id: ".wp_specialchars($row->cat_name);
			if ('Y' == $row->auto_toggle)
				echo ' (auto toggle)';
			echo "</option>\n";
		}
		echo "\n</select>\n";
?></td>
</tr>

<tr>
<th width="33%" scope="row">Check for new posts:</th>
<td width="67%"><select name="automatic_updates" size="1" onchange="if (this.value=='yes') { disp = 'inline'; } else { disp = 'none'; }; el=document.getElementById('automatic-update-interval-span'); if (el) el.style.display=disp;">
<option value="yes"<?php echo ($automatic_updates)?' selected="selected"':''; ?>>automatically</option>
<option value="no"<?php echo (!$automatic_updates)?' selected="selected"':''; ?>>only when I request</option>
</select>
<span id="automatic-update-interval-span" style="display: <?php echo $automatic_updates?'inline':'none';?>"><label for="automatic-update-interval">every</label> <input id="automatic-update-interval" name="freshness_interval" value="<?php echo $freshness_interval; ?>" size="4" /> minutes.</span>
</td>
</tr>

<tr><th width="33%" scope="row" style="vertical-align:top">Update automatically from feed:</th>
<td width="67%"><ul style="margin:0;list-style:none">
<li><input type="checkbox" name="hardcode_name" value="no"<?php echo (($hardcode_name=='yes')?'':' checked="checked"');?>/> Contributor name (feed title)</li>
<li><input type="checkbox" name="hardcode_description" value="no"<?php echo (($hardcode_description=='yes')?'':' checked="checked"');?>/> Contributor description (feed tagline)</li>
<li><input type="checkbox" name="hardcode_url" value="no"<?php echo (($hardcode_url=='yes')?'':' checked="checked"');?>/> Homepage (feed link)</li>
</ul></td></tr>
</table>
</fieldset>

<fieldset class="options">
<legend>Syndicated Posts</legend>

<?php fwp_category_box($dogs, '<em>all syndicated posts</em>'); ?>

<table class="editform" width="75%" cellspacing="2" cellpadding="5">
<tr style="vertical-align: top"><th width="33%" scope="row">Publication:</th>
<td width="67%"><ul style="margin: 0; padding: 0; list-style:none">
<li><label><input type="radio" name="post_status" value="publish"<?php echo (!$post_status or $post_status=='publish')?' checked="checked"':''; ?> /> Publish syndicated posts immediately</label></li>
<li><label><input type="radio" name="post_status" value="draft"<?php echo ($post_status=='draft')?' checked="checked"':''; ?> /> Hold syndicated posts as drafts</label></li>
<li><label><input type="radio" name="post_status" value="private"<?php echo ($post_status=='private')?' checked="checked"':''; ?> /> Hold syndicated posts as private posts</label></li>
</ul></td></tr>

<tr style="vertical-align: top"><th width="33%" scope="row">Comments:</th>
<td width="67%"><ul style="margin: 0; padding: 0; list-style:none">
<li><label><input type="radio" name="comment_status" value="open"<?php echo ($comment_status=='open')?' checked="checked"':''; ?> /> Allow comments on syndicated posts</label></li>
<li><label><input type="radio" name="comment_status" value="closed"<?php echo ($comment_status!='open')?' checked="checked"':''; ?> /> Don't allow comments on syndicated posts</label></li>
</ul></td></tr>

<tr style="vertical-align: top"><th width="33%" scope="row">Trackback and Pingback:</th>
<td width="67%"><ul style="margin:0; padding: 0; list-style:none">
<li><label><input type="radio" name="ping_status" value="open"<?php echo ($ping_status=='open')?' checked="checked"':''; ?> /> Accept pings on syndicated posts</label></li>
<li><label><input type="radio" name="ping_status" value="closed"<?php echo ($ping_status!='open')?' checked="checked"':''; ?> /> Don't accept pings on syndicated posts</label></li>
</ul></td></tr>

<tr style="vertical-align: top"><th width="33%" scope="row" style="vertical-align:top">Unfamiliar authors:</th>
<td width="67%"><ul style="margin: 0; padding: 0; list-style:none">
<li><label><input type="radio" name="unfamiliar_author" value="create"<?php echo $unfamiliar_author['create']; ?>/> create a new author account</label></li>
<li><label><input type="radio" name="unfamiliar_author" value="default"<?php echo $unfamiliar_author['default']; ?> /> attribute the post to the default author</label></li>
<li><label><input type="radio" name="unfamiliar_author" value="filter"<?php echo $unfamiliar_author['filter']; ?> /> don't syndicate the post</label></li>
</ul></td></tr>
<tr style="vertical-align: top"><th width="33%" scope="row" style="vertical-align:top">Unfamiliar categories:</th>
<td width="67%"><ul style="margin: 0; padding:0; list-style:none">
<li><label><input type="radio" name="unfamiliar_category" value="create"<?php echo $unfamiliar_category['create']; ?>/> create any categories the post is in</label></li>
<li><label><input type="radio" name="unfamiliar_category" value="default"<?php echo $unfamiliar_category['default']; ?>/> don't create new categories</li>
<li><label><input type="radio" name="unfamiliar_category" value="filter"<?php echo $unfamiliar_category['filter']; ?>/> don't create new categories and don't syndicate posts unless they match at least one familiar category</label></li>
</ul></td></tr>

<tr style="vertical-align: top"><th width="33%" scope="row">Permalinks point to:</th>
<td width="67%"><select name="munge_permalink" size="1">
<option value="yes"<?php echo ($munge_permalink=='yes')?' selected="selected"':''; ?>>original website</option>
<option value="no"<?php echo ($munge_permalink=='no')?' selected="selected"':''; ?>>this website</option>
</select></td></tr>
</table>
<div class="submit"><input type="submit" name="action" value="<?php echo $caption; ?>" /></div>
</fieldset>

<fieldset class="options">
<legend>Back-end Options</legend>
<table class="editform" width="100%" cellspacing="2" cellpadding="5">
<th width="33%" scope="row">Write update notices to PHP logs:</th>
<td width="67%"><select name="update_logging" size="1">
<option value="yes"<?php echo (($update_logging=='yes')?' selected="selected"':''); ?>>yes</option>
<option value="no"<?php echo (($update_logging!='yes')?' selected="selected"':''); ?>>no</option>
</select></td>
</tr>
</table>
<div class="submit"><input type="submit" name="action" value="<?php echo $caption; ?>" /></div>
</fieldset>
</form>
</div>
<?php
}

	fwp_syndication_options_page();
?>
