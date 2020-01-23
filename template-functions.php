<?php
################################################################################
## TEMPLATE API: functions to make your templates syndication-aware ############
################################################################################

/**
 * is_syndicated: Tests whether the current post in a Loop context, or a post
 * given by ID number, was syndicated by FeedWordPress. Useful for templates
 * to determine whether or not to retrieve syndication-related meta-data in
 * displaying a post.
 *
 * @param int $id The post to check for syndicated status. Defaults to the current post in a Loop context.
 * @return bool TRUE if the post's meta-data indicates it was syndicated; FALSE otherwise
 */
function is_syndicated ($id = NULL) {
	$p = new FeedWordPressLocalPost($id);
	return $p->is_syndicated();
} /* function is_syndicated() */

/**
 * feedwordpress_display_url: format a fully-formed URL for display in the
 * FeedWordPress admin interface, abbreviating it (e.g.: input of
 * `http://feedwordpress.radgeek.com/feed/` will be shortened to
 * `feedwordpress.radgeek.com/feed/`)
 *
 * @param string $url provides the URL to display
 * @param int $before a number of characters to preserve from the beginning of the URL if it must be shortened
 * @param int $after a number of characters to preserve from the end of the URL if it must be shortened
 * @return string containing an abbreviated display form of the URL (e.g.: `feedwordpress.radgeek.net/feed`) 
 */
function feedwordpress_display_url ($url, $before = 60, $after = 0) {
	$bits = parse_url($url);

	// Strip out crufty subdomains
	if (isset($bits['host'])) :
  		$bits['host'] = preg_replace('/^www[0-9]*\./i', '', $bits['host']);
  	endif;

  	// Reassemble bit-by-bit with minimum of crufty elements
	$url = (isset($bits['user'])?$bits['user'].'@':'')
		.(isset($bits['host'])?$bits['host']:'')
		.(isset($bits['path'])?$bits['path']:'')
		.(isset($uri_bits['port'])?':'.$uri_bits['port']:'')
		.(isset($bits['query'])?'?'.$bits['query']:'');

	if (strlen($url) > ($before+$after)) :
		$url = substr($url, 0, $before).'.'.substr($url, 0 - $after, $after);
	endif;

	return $url;
} /* feedwordpress_display_url () */

function get_syndication_source_property ($original, $id, $local, $remote = NULL) {
	$p = new FeedWordPressLocalPost($id);
	return $p->meta($local, array("unproxy" => $original, "unproxied setting" => $remote));
} /* function get_syndication_source_property () */

function get_syndication_source_link ($original = NULL, $id = NULL) {
	$p = new FeedWordPressLocalPost($id);
	return $p->syndication_source_link($original);
} /* function get_syndication_source_link() */

function the_syndication_source_link ($original = NULL, $id = NULL) {
	echo get_syndication_source_link($original, $id);
} /* function the_syndication_source_link() */

function get_syndication_source ($original = NULL, $id = NULL) {
	$p = new FeedWordPressLocalPost($id);
	return $p->syndication_source($original);
} /* function get_syndication_source() */

function the_syndication_source ($original = NULL, $id = NULL) {
	echo get_syndication_source($original, $id);
} /* function the_syndication_source () */

function get_syndication_feed ($original = NULL, $id = NULL) {
	$p = new FeedWordPressLocalPost($id);
	return $p->syndication_feed($original);
} /* function get_syndication_feed() */

function the_syndication_feed ($original = NULL, $id = NULL) {
	echo get_syndication_feed($original, $id);
} /* function the_syndication_feed() */

function get_syndication_feed_guid ($original = NULL, $id = NULL) {
	$p = new FeedWordPressLocalPost($id);
	return $p->syndication_feed_guid($original);
} /* function get_syndication_feed_guid () */

function the_syndication_feed_guid ($original = NULL, $id = NULL) {
	echo get_syndication_feed_guid($original, $id);
} /* function the_syndication_feed_guid () */

function get_syndication_feed_id ($id = NULL) {
	$p = new FeedWordPressLocalPost($id);
	return $p->feed_id();
} /* function get_syndication_feed_id () */

function the_syndication_feed_id ($id = NULL) {
	echo get_syndication_feed_id($id);
} /* function the_syndication_feed_id () */

function get_syndication_feed_object ($id = NULL) {
	$p = new FeedWordPressLocalPost($id);
	return $p->feed();
} /* function get_syndication_feed_object() */

function get_feed_meta ($key, $id = NULL) {
	$ret = NULL;

	$link = get_syndication_feed_object($id);
	if (is_object($link) and isset($link->settings[$key])) :
		$ret = $link->settings[$key];
	endif;
	return $ret;
} /* function get_feed_meta() */

function get_syndication_permalink ($id = NULL) {
	$p = new FeedWordPressLocalPost($id);
	return $p->syndication_permalink();
} /* function get_syndication_permalink () */

function the_syndication_permalink ($id = NULL) {
	echo get_syndication_permalink($id);
} /* function the_syndication_permalink () */

/**
 * get_local_permalink: returns a string containing the internal permalink
 * for a post (whether syndicated or not) on your local WordPress installation.
 * This may be useful if you want permalinks to point to the original source of
 * an article for most purposes, but want to retrieve a URL for the local
 * representation of the post for one or two limited purposes (for example,
 * linking to a comments page on your local aggregator site).
 *
 * @param $id The numerical ID of the post to get the permalink for. If empty,
 * 	defaults to the current post in a Loop context.
 * @return string The URL of the local permalink for this post.
 *
 * @uses get_permalink()
 * @global $feedwordpress_the_original_permalink
 *
 * @since 2010.0217
 */
function get_local_permalink ($id = NULL) {
	global $feedwordpress_the_original_permalink;

	// get permalink, and thus activate filter and force global to be filled
	// with original URL.
	$url = get_permalink($id);
	return $feedwordpress_the_original_permalink;
} /* get_local_permalink() */

/**
 * the_original_permalink: displays the contents of get_original_permalink()
 *
 * @param $id The numerical ID of the post to get the permalink for. If empty,
 * 	defaults to the current post in a Loop context.
 *
 * @uses get_local_permalinks()
 * @uses apply_filters
 *
 * @since 2010.0217
 */
function the_local_permalink ($id = NULL) {
	print apply_filters('the_permalink', get_local_permalink($id));
} /* function the_local_permalink() */

