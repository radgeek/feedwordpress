<?php
require_once(dirname(__FILE__) . '/admin-ui.php');

function fwp_syndication_options_page () {
        global $wpdb, $wp_db_version, $fwp_path;
	
	if (FeedWordPress::needs_upgrade()) :
		fwp_upgrade_page();
		return;
	endif;

	// If this is a POST, validate source and user credentials
	FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_options', /*capability=*/ 'manage_options');

	if (isset($_POST['create_index'])) :
		FeedWordPress::create_guid_index();
?>
<div class="updated">
<p><?php _e('Index created on database table.')?></p>
</div>
<?php
	endif;

	if (isset($_POST['submit']) or isset($_POST['create_index'])) :
		update_option('feedwordpress_cat_id', $_REQUEST['syndication_category']);
		update_option('feedwordpress_update_logging', $_REQUEST['update_logging']);
		update_option('feedwordpress_automatic_updates', ($_POST['automatic_updates']=='yes'));
		update_option('feedwordpress_update_time_limit', ($_POST['update_time_limit']=='yes')?(int) $_POST['time_limit_seconds']:0);

		$freshness_interval = (isset($_POST['freshness_interval']) ? (int) $_POST['freshness_interval'] : 10);
		update_option('feedworidpress_freshness', $freshness_interval*60);

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

	$cat_id = FeedWordPress::link_category_id();
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
	
	if (isset($wp_db_version) and $wp_db_version >= 4772) :
		$results = get_categories(array(
			"type" => 'link',
			"hide_empty" => false,	
		));
		
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
<?php
	FeedWordPressCompatibility::stamp_nonce('feedwordpress_options');
	fwp_linkedit_single_submit();
?>
<div id="post-body">
<?php fwp_option_box_opener('Syndicated Feeds', 'syndicatedfeedsdiv'); ?>
<table class="editform" width="100%" cellspacing="2" cellpadding="5">
<tr>
<th width="33%" scope="row">Syndicated Link category:</th>
<td width="67%"><?php
	echo "\n<select name=\"syndication_category\" size=\"1\">";
	foreach ($results as $row) :
		if (!isset($row->cat_id)) : $row->cat_id = $row->cat_ID; endif;
	
		echo "\n\t<option value=\"$row->cat_id\"";
		if ($row->cat_id == $cat_id) :
			echo " selected='selected'";
		endif;
		echo ">$row->cat_id: ".wp_specialchars($row->cat_name);
		if ('Y' == $row->auto_toggle) :
			echo ' (auto toggle)';
		endif;
		echo "</option>\n";
	endforeach;
	echo "\n</select>\n";
?></td>
</tr>

<tr style="vertical-align: top">
<th width="33%" scope="row">Check for updates:</th>
<td width="67%"><select name="automatic_updates" size="1">
<option value="yes"<?php echo ($automatic_updates)?' selected="selected"':''; ?>>automatically</option>
<option value="no"<?php echo (!$automatic_updates)?' selected="selected"':''; ?>>only when I request</option>
</select>
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
	FeedWordPressSettingsUI::instead_of_posts_box();
	FeedWordPressSettingsUI::instead_of_authors_box();
	FeedWordPressSettingsUI::instead_of_categories_box();
	
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

