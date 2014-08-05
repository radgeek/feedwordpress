<?php

class FeedWordPressLocalPost {
	public $post;
	public $link;
	
	public function __construct ($p = NULL) {
		global $post;
		
		if (is_null($p)) :
			$this->post = $post; // current post in loop
		elseif (is_object($p)) :
			$this->post = $p;
		else :
			$this->post = get_post($p);
		endif;		
	}
	
	public function id () {
		return $this->post->ID;
	}
	
	public function meta ($what, $params = array()) {

		// -=-=-= 1. INITIAL SETUP. =-=-=-
		$params = wp_parse_args($params, array(
		"single" => true,
		"default" => NULL,
		"global" => NULL,
		"unproxied setting" => NULL,
		"unproxy" => false,
		));
	
		// This is a little weird, just bear with me here.
		$results = array();
		
		// Has this been left up to the admin setting?
		if (is_null($params['unproxy'])) :
			$params['unproxy'] = FeedWordPress::use_aggregator_source_data();
		endif;
	
		// -=-=-= 2. GET DATA FROM THE PROXIMATE OR THE ULTIMATE SOURCE. =-=-=-

		// Now if we are supposed to look for ultimate source data (e.g. from
		// <atom:source> ... </atom:source> elements), do so here.
		if ($params['unproxy']) :
			if (!is_string($params['unproxied setting'])) :
				// Default pattern for unproxied settings: {$name}_original
				$params['unproxied setting'] = $what . '_original';
			endif;
			
			// Now see if there's anything in postmeta from our ultimate source.
			// If so, then we can cut out the middle man here.
			$results = get_post_meta($this->post->ID, /*key=*/ $params['unproxied setting'], /*single=*/ false);
		endif;

		// If we weren't looking for ultimate source data, or if there wasn't
		// any recorded, then grab this from the data for the proximate source.		
		if (empty($results)) :
			$results = get_post_meta($this->post->ID, /*key=*/ $what, /*single=*/ false);
		endif;
	
		// -=-=-= 3. DEAL WITH THE RESULTS, IF ANY, OR FALLBACK VALUES. =-=-=-
		
		// If we have results now, cool. Just pass them back.
		if (!empty($results)) :
			$ret = ($params['single'] ? $results[0] : $results);
			
		// If we got no results but we have a fallback global setting, cool. Use
		// that. Jam it into a singleton array for queries expecting an array of
		// results instead of a scalar result.
		elseif (is_string($params['global']) and strlen($params['global']) > 0) :
			$opt = get_option($params['global'], $params['default']);
			$ret = ($params['single'] ? $opt : array($opt));
			
		// If we got no results and we have no fallback global setting, pass
		// back a default value for single-result queries, or an empty array for
		// multiple-result queries.
		else :
			$ret = ($params['single'] ? $params['default'] : array());
		endif;
		
		return $ret; 
	}
	
	public function is_syndicated () {
		return (!is_null($this->feed_id(/*single=*/ false))); 
	}

	public function syndication_permalink () {
		return $this->meta('syndication_permalink');
	}

	public function feed () {
		global $feedwordpress;
		$this->link = $feedwordpress->subscription($this->feed_id());
		return $this->link;
	}
	
	public function feed_id () {
		return $this->meta('syndication_feed_id');
	}

	public function syndication_feed ($original = NULL) {
		return $this->meta('syndication_feed', array("unproxy" => $original));
	}
	
	public function syndication_feed_guid ($original = NULL) {
		$ret = $this->meta('syndication_source_id', array("unproxy" => $original));
		
		// If this is blank, fall back to the full URL of the feed
		if (is_null($ret) or strlen(trim($ret))==0) :
			$ret = get_syndication_feed();
		endif;
	
		return $ret;
	}
	
	public function syndication_source ($original = NULL) {
		$ret = $this->meta('syndication_source', array("unproxy" => $original));
		
		// If this is blank, fall back to a prettified URL for the blog.
		if (is_null($ret) or strlen(trim($ret)) == 0) :
			$ret = feedwordpress_display_url($this->syndication_source_link());
		endif;
		
		return $ret;
	}
	
	public function syndication_source_link ($original = NULL) {
		return $this->meta('syndication_source_uri', array("unproxy" => $original));
	}
	
	public function is_exposed_to_formatting_filters () {
		
		return (
			!$this->is_syndicated()
			or (
				'yes' == $this->meta(
					'_feedwordpress_formatting_filters',
					array(
						'global' => 'feedwordpress_formatting_filters',
						'default' => 'no',
					)
				)
			)
		);
		
	} /* FeedWordPressLocalPost::is_exposed_to_formatting_filters () */
	

	public function content () {
		return apply_filters('the_content', $this->post->post_content, $this->post->ID);
	}
	
	public function title () {
		return apply_filters('the_title', $this->post->post_title, $this->post->ID);
	}

	public function guid () {
		return apply_filters('get_the_guid', $this->post->guid);
	}
	
	public function get_categories () {
		$terms = wp_get_object_terms(
			$this->post->ID,
			get_taxonomies(array(
				'public' => true,
			), 'names'),
			'all'
		);
		$rootUrl = get_bloginfo('url');

		$cats = array();
		foreach ($terms as $term) :
			$taxUrl = MyPHP::url($rootUrl, array("taxonomy" => $term->taxonomy));
			//array("taxonomy" => $term->taxonomy ));
			$cats[] = new SimplePie_Category(
				/*term=*/ $term->slug,
				/*scheme=*/ $taxUrl,
				/*label=*/ $term->name
			);
		endforeach;
		return $cats;
	}
	
} /* class FeedWordPressLocalPost */

