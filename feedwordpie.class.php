<?php
define('FEEDWORDPIE_TYPE_CUSTOM_XML', ~SIMPLEPIE_TYPE_NONE & ~SIMPLEPIE_TYPE_ALL);

class FeedWordPie extends SimplePie {
	var $subscription = NULL;
	
	function set_feed_url ($url) {
		global $fwp_oLinks;
		if ($url InstanceOf SyndicatedLink) :

			// Get URL with relevant parameters attached.
			// Credentials will be handled further down.
			$new_url = $url->uri(array('add_params' => true, 'fetch' => true));
			
			// Store for reference.
			$this->subscription = $url->id();
			
			// Pass it along down the line.
			$url = $new_url;
			
		else :
			$this->subscription = NULL;
		endif;
		
		// OK, let's go.
		return parent::set_feed_url($url);
	} /* class SimplePie */
	
	function get_type () {
		// Allow filters to pre-empt a type determination from SimplePie
		$ret = apply_filters(
			'feedwordpie_get_type',
			NULL,
			$this
		);
				
		// If not preempted by a filter, fall back on SimplePie
		if (is_null($ret)) :
			$ret = parent::get_type();
		endif;

		return $ret;
	}
	
	function get_feed_tags ($namespace, $tag) {
		$tags = parent::get_feed_tags($namespace, $tag);
		
		// Allow filters to filter SimplePie handling
		return apply_filters(
			'feedwordpie_get_feed_tags',
			$tags,
			$namespace,
			$tag,
			$this
		);
	}
	
	function get_items ($start = 0, $end = 0) {
		// Allow filters to set up for, or pre-empt, SimplePie handling
		$ret = apply_filters(
			'feedwordpie_get_items',
			NULL,
			$start,
			$end,
			$this
		);
		
		if (is_null($ret)) :
			$ret = parent::get_items();
		endif;
		return $ret;
	}
	
} /* class FeedWordPie */

