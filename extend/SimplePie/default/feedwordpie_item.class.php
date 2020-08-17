<?php

class FeedWordPie_Item extends SimplePie_Item {

	function get_id ($hash = false, $fn = 'md5') {
		return apply_filters('feedwordpie_item_get_id', parent::get_id($hash, $fn), $hash, $this, $fn);
	}
	
	function get_title () {
		return apply_filters('feedwordpie_item_get_title', parent::get_title(), $this);
	}
	
	function get_description ($description_only = false) {
		return apply_filters('feedwordpie_item_get_description', parent::get_description($description_only), $description_only, $this);
	}

	function get_content ($content_only = false) {
		return apply_filters('feedwordpie_item_get_content', parent::get_content($content_only), $content_only, $this);
	}
	
	function get_categories () {
		return apply_filters('feedwordpie_item_get_categories', parent::get_categories(), $this);
	}
	
	function get_authors () {
		return apply_filters('feedwordpie_item_get_authors', parent::get_authors(), $this);
	}
	function get_contributors () {
		return apply_filters('feedwordpie_item_get_contributors', parent::get_contributors(), $this);
	}
	function get_copyright () {
		return apply_filters('feedwordpie_item_get_copyright', parent::get_copyright(), $this);
	}
	function get_date ($date_format = 'j F Y, g:i a') {
		return apply_filters('feedwordpie_item_get_date', parent::get_date($date_format), $date_format, $this);
	}
	function get_local_date ($date_format = '%c') {
		return apply_filters('feedwordpie_item_get_local_date', parent::get_local_date($date_format), $date_format, $this);
	}
	function get_links ($rel = 'alternate') {
		return apply_filters('feedwordpie_item_get_links', parent::get_links($rel), $rel, $this);
	}
	function get_enclosures () {
		return apply_filters('feedwordpie_item_get_enclosures', parent::get_enclosures(), $this);
	}
	function get_latitude () {
		return apply_filters('feedwordpie_item_get_lattitude', parent::get_lattitude(), $this);
	}
	function get_longitude () {
		return apply_filters('feedwordpie_item_get_longitude', parent::get_longtidue(), $this);
	}
	function get_source () {
		return apply_filters('feedwordpie_item_get_source', parent::get_source(), $this);
	}
} /* class FeedWordPie_Item */

