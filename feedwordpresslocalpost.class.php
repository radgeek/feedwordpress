<?php

class FeedWordPressLocalPost {
	private $post;
	
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
		$params = wp_parse_args($params, array(
		"single" => true,
		"default" => NULL,
		"global" => NULL,
		));
		
		$results = get_post_custom_values($what, $this->id());
		
		if (count($results) > 0) :
			$ret = ($params['single'] ? reset($results) : $results);
		elseif (is_string($params['global']) and strlen($params['global']) > 0) :
			$opt = get_option($params['global'], $params['default']);
			$ret = ($params['single'] ? $opt : array($opt));
		else :
			$ret = ($params['single'] ? $params['default'] : array());
		endif;
		
		return $ret; 
	}
	
	public function syndication_feed_id () {
		return $this->meta('syndication_feed_id');
	}
	
	public function is_syndicated () {
		return (strlen($this->syndication_feed_id()) > 0); 
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
	
} /* class FeedWordPressLocalPost */

