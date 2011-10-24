<?php
global $fwp_oLinks;

class FeedWordPie extends SimplePie {
	var $subscription = NULL;
	
	function set_feed_url ($url) {
		global $fwp_oLinks;
		if ($url InstanceOf SyndicatedLink) :

			// Get URL with relevant parameters attached.
			$new_url = $url->uri(array('add_params' => true));

			// Keep for access elsewhere. God this sucks.
			if (!is_array($fwp_oLinks)) :
				$fwp_oLinks = array();
			endif;
			
			// Store data for later retrieval.
			# $this->subscription = $url;
			$fwp_oLinks[$new_url] = $url;
			
			$url = $new_url;
			
		else :
			$this->subscription = NULL;
		endif;

		// OK, let's go.
		return parent::set_feed_url($url);
	} /* class SimplePie */
	
} /* class FeedWordPie */

