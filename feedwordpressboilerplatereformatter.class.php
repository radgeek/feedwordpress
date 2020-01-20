<?php
/**
 * class FeedWordPressBoilerplateReformatter: processes shortcodes in Boilerplate / Credits
 * settings text from Syndication > Posts & Links > Boilerplate / Credits
 *
 * @author C. Johnson <development@fwpplugin.com>
 *
 * @see feedwordpressboilerplatereformatter.shortcode.functions.php
 *
 * @version 2020.0120
 */
 
class FeedWordPressBoilerplateReformatter {
	var $id, $element;

	public function __construct ($id = NULL, $element = 'post') {
		$this->id = $id;
		$this->element = $element;
	}

	function shortcode_methods () {
		return array(
			'source' => 'source_link',
			'source-name' => 'source_name',
			'source-url' => 'source_url',
			'original-link' => 'original_link',
			'original-url' => 'original_url',
			'author' => 'source_author_link',
			'author-name' => 'source_author',
			'feed-setting' => 'source_setting',
		);
	}
	function do_shortcode ($template) {
		$codes = $this->shortcode_methods();

		// Register shortcodes relative to this object/post ID/element.
		foreach ($codes as $code => $method) :
			add_shortcode($code, array($this, $method));
		endforeach;

		$template = do_shortcode($template);
		
		// Unregister shortcodes.
		foreach ($codes as $code => $method) :
			remove_shortcode($code);
		endforeach;
		
		return $template;
	}
	
	function source_name ($atts) {
		$param = shortcode_atts(array(
		'original' => NULL,
		), $atts);
		return get_syndication_source($param['original'], $this->id);
	}
	function source_url ($atts) {
		$param = shortcode_atts(array(
		'original' => NULL,
		), $atts);
		return get_syndication_source_link($param['original'], $this->id);
	}
	function source_link ($atts) {
		switch (strtolower($atts[0])) :
		case '-name' :
			$ret = $this->source_name($atts);
			break;
		case '-url' :
			$ret = $this->source_url($atts);
			break;
		default :
			$param = shortcode_atts(array(
			'original' => NULL,
			), $atts);
			if ('title' == $this->element) :
				$ret = $this->source_name($atts);
			else :
				$ret = '<a href="'.htmlspecialchars($this->source_url($atts)).'">'.htmlspecialchars($this->source_name($atts)).'</a>';
			endif;
		endswitch;
		return $ret;
	}
	function source_setting ($atts) {
		$param = shortcode_atts(array(
		'key' => NULL,
		), $atts);
		return get_feed_meta($param['key'], $this->id);
	}
	function original_link ($atts, $text) {
		$url = $this->original_url($atts);
		return '<a href="'.esc_url($url).'">'.do_shortcode($text).'</a>';
	}
	function original_url ($atts) {
		return get_syndication_permalink($this->id);
	}
	function source_author ($atts) {
		return get_the_author();
	}
	function source_author_link ($atts) {
		switch (strtolower($atts[0])) :
		case '-name' :
			$ret = $this->source_author($atts);
			break;
		default :
			global $authordata; // Janky.
			if ('title' == $this->element) :
				$ret = $this->source_author($atts);
			else :
				$ret = get_the_author();
				$url = get_author_posts_url((int) $authordata->ID, (int) $authordata->user_nicename);
				if ($url) :
					$ret = '<a href="'.$url.'" '
						.'title="Read other posts by '.esc_html($authordata->display_name).'">'
						.$ret
						.'</a>';
				endif;			
			endif;
		endswitch;
		return $ret;
	}
}

