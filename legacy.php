<?php
################################################################################
## LEGACY API: Replicate or mock up functions for legacy support purposes ######
################################################################################

if (!function_exists('get_option')) {
	function get_option ($option) {
		return get_settings($option);
	}
}
if (!function_exists('current_user_can')) {
	$legacy_capability_hack = true;
	function current_user_can ($task) {
		global $user_level;

		$can = false;

		// This is **not** a full replacement for current_user_can. It
		// is only for checking the capabilities we care about via the
		// WordPress 1.5 user levels.
		switch ($task) {
		case 'manage_options':
			$can = ($user_level >= 6);
			break;
		case 'manage_links':
			$can = ($user_level >= 5);
			break;
		} /* switch */
		return $can;
	}
} else {
	$legacy_capability_hack = false;
}
if (!function_exists('sanitize_user')) {
	function sanitize_user ($text, $strict) {
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
		global $checked_categories;

		// selected_cats is an array of integer cat_IDs / term_ids for
		// the categories that should be checked
		$cats = array();
		if ($post_id) : $cats = wp_get_post_categories($post_id);
		else : $cats = $selected_cats;
		endif;
		
		$checked_categories = $cats;
		dropdown_categories();
	endif;
}

