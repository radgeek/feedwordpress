<?php
/**
 * FeedWordPress_Walker_Category_Checklist
 *
 * @version 2010.0531
 *
 * This is the fucking stupidest thing ever.
 */

require_once(ABSPATH.'/wp-admin/includes/template.php');
// Fucking fuck.

class FeedWordPress_Walker_Category_Checklist extends Walker_Category_Checklist {
	var $prefix = ''; var $taxonomy = 'category';
	var $checkbox_name = NULL;
	function FeedWordPress_Walker_Category_Checklist ($params = array()) {
		$this->set_taxonomy('category');

		if (isset($params['checkbox_name'])) :
			$this->checkbox_name = $params['checkbox_name'];
		endif;
	}

	function set_prefix ($prefix) {
		$this->prefix = $prefix;
	}
	function set_taxonomy ($taxonomy) {
		$this->taxonomy = $taxonomy;
	}

	function start_el( &$output, $category, $depth = 0, $args = array(), $current_object_id = 0 ) {
		extract($args);
               if ( empty($taxonomy) ) :
			$taxonomy = 'category';
		endif;

		if (!is_null($this->checkbox_name)) :
			$name = $this->checkbox_name;
		elseif ($taxonomy=='category') :
			$name = 'post_category';
		else :
			$name = 'tax_input['.$taxonomy.']';
		endif;

		$unit = array();
		if (strlen($this->prefix) > 0) :
			$unit[] = $this->prefix;
		endif;
		$unit[] = $taxonomy;
		$unit[] = $category->term_id;
		$unitId = implode("-", $unit);

		$class = in_array( $category->term_id, $popular_cats ) ? ' class="popular-category category-checkbox"' : ' class="category-checkbox"';
		$output .= "\n<li id='{$unitId}'$class>" . '<label class="selectit"><input value="' . $category->term_id . '" type="checkbox" name="'.$name.'[]" id="in-'.$unitId. '"' . checked( in_array( $category->term_id, $selected_cats ), true, false ) . disabled( empty( $args['disabled'] ), false, false ) . ' /> ' . esc_html( apply_filters('the_category', $category->name )) . '</label>';
	} /* FeedWordPress_Walker_Category_Checklist::start_el() */
} /* FeedWordPress_Walker_Category_Checklist */
