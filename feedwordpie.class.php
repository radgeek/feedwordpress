<?php
global $fwp_oLinks;

class FeedWordPie extends SimplePie {
	var $subscription = NULL;
	
	function set_feed_url ($url) {
		global $fwp_oLinks;
		if ($url InstanceOf SyndicatedLink) :
			// Store data for later retrieval.
			$this->subscription = $url;

			// Get URL with relevant parameters attached.
			$url = $this->subscription->uri(array('add_params' => true));

			// Keep for access elsewhere. God this sucks.
			if (!is_array($fwp_oLinks)) :
				$fwp_oLinks = array();
			endif;
			
			$fwp_oLinks[$url] = $this->subscription;
		else :
			$this->subscription = NULL;
		endif;

		// OK, let's go.
		return parent::set_feed_url($url);
	} /* class SimplePie */
	
} /* class FeedWordPie */

