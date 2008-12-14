<?php
require_once(dirname(__FILE__) . '/admin-ui.php');

function fwp_syndication_options_page () {
        global $wpdb, $wp_db_version, $fwp_path;
	
	if (FeedWordPress::needs_upgrade()) :
		fwp_upgrade_page();
		return;
	endif;

	if (isset($_POST['create_index'])) :
		check_admin_referer();
		if (!current_user_can('manage_options')) :
			die (__("Cheatin' uh ?"));
		else :
			FeedWordPress::create_guid_index();
?>
<div class="updated">
<p><?php _e('Index created on database table.')?></p>
</div>
<?php
		endif;
	endif;

	if (isset($_POST['submit']) or isset($_POST['create_index'])) :
		check_admin_referer();

		if (!current_user_can('manage_options')):
			die (__("Cheatin' uh ?"));
		else:
			update_option('feedwordpress_cat_id', $_REQUEST['syndication_category']);
			update_option('feedwordpress_munge_permalink', $_REQUEST['munge_permalink']);
			update_option('feedwordpress_use_aggregator_source_data', $_REQUEST['use_aggregator_source_data']);
			update_option('feedwordpress_formatting_filters', $_REQUEST['formatting_filters']);
			update_option('feedwordpress_update_logging', $_REQUEST['update_logging']);
			
			if ('newuser'==$_REQUEST['unfamiliar_author']) :
				$newuser_name = trim($_REQUEST['unfamiliar_author_newuser']);
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
				update_option('feedwordpress_unfamiliar_author', $_REQUEST['unfamiliar_author']);
			endif;

			if (isset($_REQUEST['match_author_by_email']) and $_REQUEST['match_author_by_email']=='yes') :
				update_option('feedwordpress_do_not_match_author_by_email', 'no');
			else :
				update_option('feedwordpress_do_not_match_author_by_email', 'yes');
			endif;

			if (isset($_REQUEST['null_emails'])) :
				update_option('feedwordpress_null_email_set', $_REQUEST['null_emails']);
			endif;

			update_option('feedwordpress_unfamiliar_category', $_REQUEST['unfamiliar_category']);
			update_option('feedwordpress_syndicated_post_status', $_REQUEST['post_status']);
			update_option('feedwordpress_automatic_updates', ($_POST['automatic_updates']=='yes'));
			update_option('feedwordpress_update_time_limit', ($_POST['update_time_limit']=='yes')?(int) $_POST['time_limit_seconds']:0);
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
				update_option('feedwordpress_syndication_cats', implode(FEEDWORDPRESS_CAT_SEPARATOR, $cats));
			else :
				delete_option('feedwordpress_syndication_cats');
			endif;

			// Tags
			if (isset($_REQUEST['tags_input'])) :
				$tags = explode(",", $_REQUEST['tags_input']);
			else :
				$tags =  array();
			endif;
			
			if (!empty($tags)) :
				update_option('feedwordpress_syndication_tags', implode(FEEDWORDPRESS_CAT_SEPARATOR, $tags));
			else :
				delete_option('feedwordpress_syndication_tags');
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
	$use_aggregator_source_data = get_option('feedwordpress_use_aggregator_source_data');
	$formatting_filters = get_option('feedwordpress_formatting_filters');
	$update_logging = get_option('feedwordpress_update_logging');

	$update_time_limit = (int) get_option('feedwordpress_update_time_limit');

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
	
	$match_author_by_email = !('yes' == get_option("feedwordpress_do_not_match_author_by_email"));
	$null_emails = FeedWordPress::null_email_set();
	
	$unfamiliar_category = array ('create'=>'','default'=>'','filter'=>'', 'tag'=>'');
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

	$cats = array_map('trim',
			preg_split(FEEDWORDPRESS_CAT_SEPARATOR_PATTERN, get_option('feedwordpress_syndication_cats'))
	);
	$dogs = SyndicatedPost::category_ids($cats, /*unfamiliar=*/ NULL);

	$tags = array_map('trim',
			preg_split(FEEDWORDPRESS_CAT_SEPARATOR_PATTERN, get_option('feedwordpress_syndication_tags'))
	);
	
	if (fwp_test_wp_version(FWP_SCHEMA_27)) :
		$icon = '<div class="icon32"><img src="'.htmlspecialchars(WP_PLUGIN_URL.'/'.$fwp_path.'/feedwordpress.png').'" alt="" /></div>';
	else :
		$icon = '';
	endif;

	if (fwp_test_wp_version(FWP_SCHEMA_26)) :
		$options = __('Settings');
	else :
		$options = __('Options');
	endif;
?>
<script type="text/javascript">
	function contextual_appearance (item, appear, disappear, value, checkbox) {
		var rollup=document.getElementById(item);
		var newuser=document.getElementById(appear);
		var sitewide=document.getElementById(disappear);
		if (rollup) {
			if ((checkbox && rollup.checked) || (!checkbox && value==rollup.value)) {
				if (newuser) newuser.style.display='block';
				if (sitewide) sitewide.style.display='none';
			} else {
				if (newuser) newuser.style.display='none';
				if (sitewide) sitewide.style.display='block';
			}
		}
	}
</script>

<div class="wrap">
<?php print $icon; ?>
<h2>Syndication <?php print $options; ?></h2>
<div id="poststuff">
<form action="" method="post">
<?php fwp_linkedit_single_submit(); ?>
<div id="post-body">
<?php fwp_option_box_opener('Syndicated Feeds', 'syndicatedfeedsdiv'); ?>
<table class="editform" width="100%" cellspacing="2" cellpadding="5">
<tr>
<th width="33%" scope="row">Syndicated Link category:</th>
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

<tr style="vertical-align: top">
<th width="33%" scope="row">Check for feeds ready to be polled for updates:</th>
<td width="67%"><select name="automatic_updates" size="1" onchange="if (this.value=='yes') { disp = 'inline'; } else { disp = 'none'; }; el=document.getElementById('automatic-update-interval-span'); if (el) el.style.display=disp;">
<option value="yes"<?php echo ($automatic_updates)?' selected="selected"':''; ?>>automatically</option>
<option value="no"<?php echo (!$automatic_updates)?' selected="selected"':''; ?>>only when I request</option>
</select>
<span id="automatic-update-interval-span" style="display: <?php echo $automatic_updates?'inline':'none';?>"><label for="automatic-update-interval">every</label> <input id="automatic-update-interval" name="freshness_interval" value="<?php echo $freshness_interval; ?>" size="4" /> minutes.</span>
</td>
</tr>

<tr style="vertical-align: top">
<th width="33%" scope="row"><?php print __('Time limit on updates'); ?>:</th>
<td width="67%"><select id="time-limit" name="update_time_limit" size="1" onchange="contextual_appearance('time-limit', 'time-limit-box', null, 'yes');">
<option value="no"<?php echo ($update_time_limit>0)?'':' selected="selected"'; ?>>no time limit on updates</option>
<option value="yes"<?php echo ($update_time_limit>0)?' selected="selected"':''; ?>>limit updates to no more than...</option>
</select>
<span id="time-limit-box"><label><input type="text" name="time_limit_seconds" value="<?php print $update_time_limit; ?>" size="5" /> seconds</label></span>
</tr>

<script type="text/javascript">
	contextual_appearance('time-limit', 'time-limit-box', null, 'yes');
</script>

<tr><th width="33%" scope="row" style="vertical-align:top">Feed information:</th>
<td width="67%"><ul style="margin:0;padding:0;list-style:none">
<li><input type="checkbox" name="hardcode_name" value="no"<?php echo (($hardcode_name=='yes')?'':' checked="checked"');?>/> Update the contributor title when the feed title changes</li>
<li><input type="checkbox" name="hardcode_description" value="no"<?php echo (($hardcode_description=='yes')?'':' checked="checked"');?>/> Update when contributor description if the feed tagline changes</li>
<li><input type="checkbox" name="hardcode_url" value="no"<?php echo (($hardcode_url=='yes')?'':' checked="checked"');?>/> Update the contributor homepage when the feed link changes</li>
</ul></td></tr>
</table>
<?php fwp_linkedit_periodic_submit(); ?>
<?php fwp_option_box_closer(); ?>

<?php
	fwp_option_box_opener(__('Syndicated Posts'), 'syndicatedpostsdiv');
?>
<table class="editform" width="75%" cellspacing="2" cellpadding="5">
<tr style="vertical-align: top"><th width="44%" scope="row">Publication:</th>
<td width="56%"><ul style="margin: 0; padding: 0; list-style:none">
<li><label><input type="radio" name="post_status" value="publish"<?php echo (!$post_status or $post_status=='publish')?' checked="checked"':''; ?> /> Publish syndicated posts immediately</label></li>
<?php if (SyndicatedPost::use_api('post_status_pending')) : ?>
<li><label><input type="radio" name="post_status" value="pending"<?php echo ($post_status=='pending')?' checked="checked"':''; ?> /> Hold syndicated posts for review; mark as Pending</label></li>
<?php endif; ?>
<li><label><input type="radio" name="post_status" value="draft"<?php echo ($post_status=='draft')?' checked="checked"':''; ?> /> Save syndicated posts as drafts</label></li>
<li><label><input type="radio" name="post_status" value="private"<?php echo ($post_status=='private')?' checked="checked"':''; ?> /> Save syndicated posts as private posts</label></li>
</ul></td></tr>

<tr style="vertical-align: top"><th width="44%" scope="row">Permalinks point to:</th>
<td width="56%"><select name="munge_permalink" size="1">
<option value="yes"<?php echo ($munge_permalink=='yes')?' selected="selected"':''; ?>>original website</option>
<option value="no"<?php echo ($munge_permalink=='no')?' selected="selected"':''; ?>>this website</option>
</select></td></tr>

<tr style="vertical-align: top"><th width="44%" scope="row">Posts from aggregator feeds:</th>
<td width="56%"><ul style="margin: 0; padding: 0; list-style: none;">
<li><label><input type="radio" name="use_aggregator_source_data" value="no"<?php echo ($use_aggregator_source_data!="yes")?' checked="checked"':''; ?>> Give the aggregator itself as the source of posts from an aggregator feed.</label></li>
<li><label><input type="radio" name="use_aggregator_source_data" value="yes"<?php echo ($use_aggregator_source_data=="yes")?' checked="checked"':''; ?>> Give the original source of the post as the source, not the aggregator.</label></li>
</ul>
<p>Some feeds (for example, those produced by FeedWordPress) aggregate content from several different sources, and include information about the original source of the post.
This setting controls what FeedWordPress will give as the source of posts from
such an aggregator feed.</p>
</td></tr>

<tr style="vertical-align:top"><th width="44%" scope="row">Formatting filters:</th>
<td width="56%">
<select name="formatting_filters" size="1">
<option value="no"<?php echo ($formatting_filters!='yes')?' selected="selected"':''; ?>>Protect syndicated posts from formatting filters</option>
<option value="yes"<?php echo ($formatting_filters=='yes')?' selected="selected"':''; ?>>Expose syndicated posts to formatting filters</option>
</select>
<p>If you have trouble getting plugins to work that are supposed to insert
elements after posts (like relevant links or a social networking
<q>Share This</q> button), try changing this option to see if it fixes your
problem.</p>
</td></tr>
</table>
<?php
	fwp_option_box_closer();
	fwp_linkedit_periodic_submit();

	fwp_option_box_opener(__('Categories for syndicated posts'), 'categorydiv', 'postbox');
	fwp_category_box($dogs, '<em>all syndicated posts</em>');
?>
<table class="editform" width="75%" cellspacing="2" cellpadding="5">
<tr style="vertical-align: top"><th width="27%" scope="row" style="vertical-align:top">Unfamiliar categories:</th>
<td><p>When one of the categories on a syndicated post is a category that FeedWordPress has not encountered before ...</p>
<ul style="margin: 0; padding:0; list-style:none">
<li><label><input type="radio" name="unfamiliar_category" value="create"<?php echo $unfamiliar_category['create']; ?>/> create a new category</label></li>
<?php if (fwp_test_wp_version(FWP_SCHEMA_23)) : ?>
<li><label><input type="radio" name="unfamiliar_category" value="tag"<?php echo $unfamiliar_category['tag']; ?>/> create a new tag</label></li>
<?php endif; ?>
<li><label><input type="radio" name="unfamiliar_category" value="default"<?php echo $unfamiliar_category['default']; ?>/> don't create new categories<?php if (fwp_test_wp_version(FWP_SCHEMA_23)) : ?> or tags<?php endif; ?></li>
<li><label><input type="radio" name="unfamiliar_category" value="filter"<?php echo $unfamiliar_category['filter']; ?>/> don't create new categories<?php if (fwp_test_wp_version(FWP_SCHEMA_23)) : ?> or tags<?php endif; ?> and don't syndicate posts unless they match at least one familiar category</label></li>
</ul></td></tr>
</table>
<?php
	fwp_option_box_closer();
	fwp_linkedit_periodic_submit();

if (isset($wp_db_version) and $wp_db_version >= FWP_SCHEMA_25) :
	fwp_tags_box($tags);
	fwp_linkedit_periodic_submit();
endif;

	fwp_option_box_opener(__('Comments & Pings'), 'commentstatus', 'postbox');
?>
<table class="editform" width="75%" cellspacing="2" cellpadding="5">
<tr style="vertical-align: top"><th width="44%" scope="row"><?php print __('Comments') ?>:</th>
<td width="56%"><ul style="margin: 0; padding: 0; list-style:none">
<li><label><input type="radio" name="comment_status" value="open"<?php echo ($comment_status=='open')?' checked="checked"':''; ?> /> Allow comments on syndicated posts</label></li>
<li><label><input type="radio" name="comment_status" value="closed"<?php echo ($comment_status!='open')?' checked="checked"':''; ?> /> Don't allow comments on syndicated posts</label></li>
</ul></td></tr>

<tr style="vertical-align: top"><th width="44%" scope="row"><?php print __('Pings') ?>:</th>
<td width="56%"><ul style="margin:0; padding: 0; list-style:none">
<li><label><input type="radio" name="ping_status" value="open"<?php echo ($ping_status=='open')?' checked="checked"':''; ?> /> Accept pings on syndicated posts</label></li>
<li><label><input type="radio" name="ping_status" value="closed"<?php echo ($ping_status!='open')?' checked="checked"':''; ?> /> Don't accept pings on syndicated posts</label></li>
</ul></td></tr>
</table>
<?php
	fwp_option_box_closer();
	fwp_linkedit_periodic_submit();

	fwp_option_box_opener('Syndicated Authors', 'authordiv', 'postbox');

	$unfamiliar_author = FeedWordPress::on_unfamiliar('author');
	$authorlist = fwp_author_list();
?>
<table>
<tr><th colspan="3" style="text-align: left; padding-top: 1.0em; border-bottom: 1px dotted black;">For posts by authors that haven't been syndicated before:</th></tr>
<tr style="vertical-align: top">
  <th style="text-align: left">Posts by new authors</th>
  <td> 
  <select style="max-width: 16.0em;" id="unfamiliar-author" name="unfamiliar_author" onchange="contextual_appearance('unfamiliar-author', 'unfamiliar-author-newuser', 'unfamiliar-author-default', 'newuser');">
    <option value="create"<?php if ('create'==$unfamiliar_author) : ?>selected="selected"<?php endif; ?>>create a new author account</option>
    <?php foreach ($authorlist as $author_id => $author_name) : ?>
      <option value="<?php echo $author_id; ?>"<?php if ($author_id==$unfamiliar_author) : ?>selected="selected"<?php endif; ?>>are assigned to <?php echo $author_name; ?></option>
    <?php endforeach; ?>
    <option value="newuser">will be assigned to a user named...</option>
    <option value="filter"<?php if ('filter'==$unfamiliar_author) : ?>selected="selected"<?php endif; ?>>get filtered out</option>
  </select>
  </td>
  <td>
  <div id="unfamiliar-author-newuser"><input type="text" name="unfamiliar_author_newuser" value="" /></div>
  </td>
</tr>
<tr><td></td>
<td colspan="2">
<p>This is a default setting. You can override it for one or more particular feeds using the Edit link in <a href="admin.php?page=<?php print $GLOBALS['fwp_path']; ?>/syndication.php">Syndicated Sites</a></p>
</td>
</table>

<script type="text/javascript">
	contextual_appearance('unfamiliar-author', 'unfamiliar-author-newuser', 'unfamiliar-author-default', 'newuser');
</script>

<h4>Matching Authors</h4>
<ul style="list-style: none; margin: 0; padding: 0;">
<li><div><label><input id="match-author-by-email" type="checkbox" name="match_author_by_email" value="yes" <?php if ($match_author_by_email) : ?>checked="checked" <?php endif; ?>onchange="contextual_appearance('match-author-by-email', 'unless-null-email', null, 'yes', /*checkbox=*/ true);" /> Treat syndicated authors with the same e-mail address as the same author.</label></div>
<div id="unless-null-email">
<p>Unless the e-mail address is one of the following anonymous e-mail addresses:</p>
<textarea name="null_emails" rows="3" style="width: 100%">
<?php print implode("\n", $null_emails); ?>
</textarea>
</div></li>
</ul>

<script type="text/javascript">
contextual_appearance('match-author-by-email', 'unless-null-email', null, 'yes', /*checkbox=*/ true);
</script>

<?php
	fwp_option_box_closer();
	fwp_linkedit_periodic_submit();
	
	fwp_option_box_opener('Back End', 'backenddiv', 'postbox');
?>
<table class="editform" width="100%" cellspacing="2" cellpadding="5">
<tr style="vertical-align: top">
<th width="33%" scope="row">Update notices:</th>
<td width="67%"><select name="update_logging" size="1">
<option value="yes"<?php echo (($update_logging=='yes')?' selected="selected"':''); ?>>write to PHP logs</option>
<option value="no"<?php echo (($update_logging!='yes')?' selected="selected"':''); ?>>don't write to PHP logs</option>
</select></td>
</tr>
<tr style="vertical-align: top">
<th width="33%" scope="row">Guid index:</th>
<td width="67%"><input class="button" type="submit" name="create_index" value="Create index on guid column in posts database table" />
<p>Creating this index may significantly improve performance on some large
FeedWordPress installations.</p></td>
</tr>
</table>
<?php
	fwp_option_box_closer();
	fwp_linkedit_periodic_submit();
	fwp_linkedit_single_submit_closer();
?>
</div>
</form>

</div> <!-- id="poststuff" -->
</div> <!-- class="wrap" -->
<?php
}

	fwp_syndication_options_page();

