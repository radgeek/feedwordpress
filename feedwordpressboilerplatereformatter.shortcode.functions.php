<?php
function add_boilerplate_reformat ($template, $element, $id = NULL) {
	if ('post' == $element and !preg_match('/< (p|div) ( \s [^>]+ )? >/xi', $template)) :
		$template = '<p class="syndicated-attribution">'.$template.'</p>';
	endif;

	$ref = new FeedWordPressBoilerplateReformatter($id, $element);
	return $ref->do_shortcode($template);
}

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
}
function add_boilerplate_title ($title, $id = NULL) {
	return add_boilerplate_simple('title', $title, $id);
}
function add_boilerplate_excerpt ($title, $id = NULL) {
	return add_boilerplate_simple('excerpt', $title, $id);
}
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
}
