<?php
class FeedWordPie extends SimplePie {
	var $subscription = NULL;
	
	function set_feed_url ($url) {
		global $fwp_oLinks;
		if ($url InstanceOf SyndicatedLink) :

			// Get URL with relevant parameters attached.
			// Credentials will be handled further down.
			$new_url = $url->uri(array('add_params' => true));
			
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
	
} /* class FeedWordPie */

