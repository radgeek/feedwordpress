<?php
/**
 * function add_boilerplate_reformat: reformat boilerplate template to fit into post elements; return reformatted text
 *
 * @uses FeedWordPressBoilerplateReformatter
 * @uses FeedWordPressBoilerplateReformatter::do_shortcode()
 *
 * @param string $template The boilerplate template text, mostly in text/html, with any inline [shortcode] elements
 * @param string $element the wp_post element being processed, e.g. 'post' (= post_content), 'excerpt', 'title'
 * @param int|null $id the numeric id of the post to be reformatted, or NULL for the current post in the WP loop
 * @return string The reformatted text, in text/html format.
 */
function add_boilerplate_reformat ($template, $element, $id = NULL) {
	if ('post' == $element and !preg_match('/< (p|div) ( \s [^>]+ )? >/xi', $template)) :
		$template = '<p class="syndicated-attribution">'.$template.'</p>';
	endif;

	$ref = new FeedWordPressBoilerplateReformatter($id, $element);
	return $ref->do_shortcode($template);
} /* add_boilerplate_reformat() */

/**
 * function add_boilerplate_simple: look for any relevant Boilerplate / Credits template text to add
 * to elements (post body, excerpt, title...) of a post being displayed in WordPress theme code.
 *
 * @uses is_syndicated()
 * @uses get_feed_meta()
 * @uses get_option()
 * @uses add_boilerplate_reformat()
 *
 * @param string $element indicates the element of the post 'post' (= main body), 'excerpt', or 'title'
 * @param string $title provides the text of the element waiting for boilerplate to be inserted
 * @param int|null $id provides the numeric ID of the post being displayed (null = current post in WP loop)
 * @return string provides the reformatted text, including any boilerplate text that has been inserted
 */
function add_boilerplate_simple ($element, $title, $id = NULL) {
	if (is_syndicated($id)) :
		$meta = get_feed_meta('boilerplate rules', $id);
		if ($meta and !is_array($meta)) : $meta = unserialize($meta); endif;

		if (!is_array($meta) or empty($meta)) :
			$meta = get_option('feedwordpress_boilerplate');
		endif;

		if (is_array($meta) and !empty($meta)) :
			foreach ($meta as $rule) :
				if ($element==$rule['element']) :
					$rule['template'] = add_boilerplate_reformat($rule['template'], $element, $id);

					if ('before'==$rule['placement']) :
						$title = $rule['template'] . ' ' . $title;
					else :
						$title = $title . ' ' . $rule['template'];
					endif;
				endif;
			endforeach;
		endif;
	endif;
	return $title;
} /* function add_boilerplate_simple() */

/**
 * function add_boilerplate_title: filter hook for the_title to add Boilerplate / Credit text,
 * if any is set, for the title of syndicated posts
 *
 * @uses add_boilerplate_simple()
 *
 * @param string $title contains the text of the title of the post being displayed
 * @param int|null $id provides the numeric ID of the post being displayed (null = current post in WP loop)
 * @return string provides the text of the title, reformatted to include any relevant boilerplate text
 */
function add_boilerplate_title ($title, $id = NULL) {
	return add_boilerplate_simple('title', $title, $id);
} /* function add_boilerplate_title () */

/**
 * function add_boilerplate_excerpt: filter hook for the_excerpt to add Boilerplate / Credit text,
 * if any is set, for the excerpt of syndicated posts
 *
 * @uses add_boilerplate_simple()
 *
 * @param string $excerpt contains the text of the excerpt of the post being displayed
 * @param int|null $id provides the numeric ID of the post being displayed (null = current post in WP loop)
 * @return string provides the text of the excerpt, reformatted to include any relevant boilerplate text
 */
function add_boilerplate_excerpt ($title, $id = NULL) {
	return add_boilerplate_simple('excerpt', $title, $id);
} /* function add_boilerplate_excerpt () */

/**
 * function add_boilerplate_content: filter hook for the_content to add Boilerplate / Credit text,
 * if any is set, for the excerpt of syndicated posts
 *
 * @uses is_syndicated()
 * @uses get_feed_meta()
 * @uses get_option()
 * @uses add_boilerplate_reformat()
 *
 * @param string $content contains the text content of the post being displayed
 * @return string provides the text content, reformatted to include any relevant boilerplate text
 */
function add_boilerplate_content ($content) {
	if (is_syndicated()) :
		$meta = get_feed_meta('boilerplate rules');
		if ($meta and !is_array($meta)) : $meta = unserialize($meta); endif;

		if (!is_array($meta) or empty($meta)) :
			$meta = get_option('feedwordpress_boilerplate');
		endif;

		if (is_array($meta) and !empty($meta)) :
			foreach ($meta as $rule) :
				if ('post'==$rule['element']) :
					$rule['template'] = add_boilerplate_reformat($rule['template'], 'post');

					if ('before'==$rule['placement']) :
						$content = $rule['template'] . "\n" . $content;
					else :
						$content = $content . "\n" . $rule['template'];
					endif;
				endif;
			endforeach;
		endif;
	endif;
	return $content;	
} /* add_boilerplate_content () */
