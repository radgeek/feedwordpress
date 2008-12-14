<?php
################################################################################
## LEGACY API: Replicate or mock up functions for legacy support purposes ######
################################################################################

// version testing
function fwp_test_wp_version ($floor, $ceiling = NULL) {
	global $wp_db_version;
	
	$ver = (isset($wp_db_version) ? $wp_db_version : 0);
	$good = ($ver >= $floor);
	if (!is_null($ceiling)) :
		$good = ($good and ($ver < $ceiling));
	endif;
	return $good;
} /* function fwp_test_wp_version () */

if (!function_exists('stripslashes_deep')) {
	function stripslashes_deep($value) {
		$value = is_array($value) ? array_map('stripslashes_deep', $value) : stripslashes($value);
		return $value;
	}
}

if (!function_exists('get_option')) {
	function get_option ($option) {
		return get_settings($option);
	}
}
if (!function_exists('current_user_can')) {
	$fwp_capability['manage_options'] = 6;
	$fwp_capability['manage_links'] = 5;
	function current_user_can ($task) {
		global $user_level;

		$can = false;

		// This is **not** a full replacement for current_user_can. It
		// is only for checking the capabilities we care about via the
		// WordPress 1.5 user levels.
		switch ($task) :
		case 'manage_options':
			$can = ($user_level >= 6);
			break;
		case 'manage_links':
			$can = ($user_level >= 5);
			break;
		endswitch;
		return $can;
	}
} else {
	$fwp_capability['manage_options'] = 'manage_options';
	$fwp_capability['manage_links'] = 'manage_links';
}
if (!function_exists('sanitize_user')) {
	function sanitize_user ($text, $strict = false) {
		return $text; // Don't munge it if it wasn't munged going in...
	}
}
if (!function_exists('wp_insert_user')) {
	function wp_insert_user ($userdata) {
		global $wpdb;

		#-- Help WordPress 1.5.x quack like a duck
		$login = $userdata['user_login'];
		$author = $userdata['display_name'];
		$nice_author = $userdata['user_nicename'];
		$email = $userdata['user_email'];
		$url = $userdata['user_url'];

		$wpdb->query (
			"INSERT INTO $wpdb->users
			 SET
				ID='0',
				user_login='$login',
				user_firstname='$author',
				user_nickname='$author',
				user_nicename='$nice_author',
				user_description='$author',
				user_email='$email',
				user_url='$url'");
		$id = $wpdb->insert_id;
		
		return $id;
	}
}

function fwp_category_checklist ($post_id = 0, $descendents_and_self = 0, $selected_cats = false) {
	if (function_exists('wp_category_checklist')) :
		wp_category_checklist($post_id, $descendents_and_self, $selected_cats);
	else :
		// selected_cats is an array of integer cat_IDs / term_ids for
		// the categories that should be checked
		global $post_ID;

		$cats = get_nested_categories();
		
		// Undo damage from usort() in WP 2.0
		$dogs = array();
		foreach ($cats as $cat) :
			$dogs[$cat['cat_ID']] = $cat;
		endforeach;
		foreach ($selected_cats as $cat_id) :
			$dogs[$cat_id]['checked'] = true;
		endforeach;
		write_nested_categories($dogs);
	endif;
}

function fwp_time_elapsed ($ts) {
	if (function_exists('human_time_diff')) :
		if ($ts >= time()) :
			$ret = __(human_time_diff($ts)." from now");
		else :
			$ret = __(human_time_diff($ts)." ago");
		endif;
	else :
		$ret = strftime('%x %X', $ts);
	endif;
	return $ret;
}

################################################################################
## UPGRADE INTERFACE: Have users upgrade DB from older versions of FWP #########
################################################################################

function fwp_upgrade_page () {
	if (isset($GLOBALS['fwp_post']['action']) and $GLOBALS['fwp_post']['action']=='Upgrade') :
		$ver = get_option('feedwordpress_version');
		if (get_option('feedwordpress_version') != FEEDWORDPRESS_VERSION) :
			echo "<div class=\"wrap\">\n";
			echo "<h2>Upgrading FeedWordPress...</h2>";

			$feedwordpress =& new FeedWordPress;
			$feedwordpress->upgrade_database($ver);
			echo "<p><strong>Done!</strong> Upgraded database to version ".FEEDWORDPRESS_VERSION.".</p>\n";
			echo "<form action=\"\" method=\"get\">\n";
			echo "<div class=\"submit\"><input type=\"hidden\" name=\"page\" value=\"syndication.php\" />";
			echo "<input type=\"submit\" value=\"Continue &raquo;\" /></form></div>\n";
			echo "</div>\n";
			return;
		else :
			echo "<div class=\"updated\"><p>Already at version ".FEEDWORDPRESS_VERSION."!</p></div>";
		endif;
	endif;
?>
<div class="wrap">
<h2>Upgrade FeedWordPress</h2>

<p>It appears that you have installed FeedWordPress
<?php echo FEEDWORDPRESS_VERSION; ?> as an upgrade to an existing installation of
FeedWordPress. That's no problem, but you will need to take a minute out first
to upgrade your database: some necessary changes in how the software keeps
track of posts and feeds will cause problems such as duplicate posts and broken
templates if we were to continue without the upgrade.</p>

<p>Note that most of FeedWordPress's functionality is temporarily disabled
until we have successfully completed the upgrade. Everything should begin
working as normal again once the upgrade is complete. There's extraordinarily
little chance of any damage as the result of the upgrade, but if you're paranoid
like me you may want to back up your database before you proceed.</p>

<p>This may take several minutes for a large installation.</p>

<form action="" method="post">
<div class="submit"><input type="submit" name="action" value="Upgrade" /></div>
</form>
</div>
<?php
} // function fwp_upgrade_page ()

