<?php
/*
 * Inspect Post Meta
 * Creates a post widget to help you inspect some meta fields for posts
 * Version: 2010.1104
 * Author: Charles Johnson
 * Author URI: http://projects.radgeek.com
*/

class InspectPostMeta {
	function InspectPostMeta ($in_hook = true) {
		add_action('add_meta_boxes', array($this, 'add_meta_boxes'), 10, 2);
	}
	
	function add_meta_boxes ($post_type, $post) {
		add_meta_box(
			/*id=*/ 'inspect_post_guid_box',
			/*title=*/ 'Post GUID and Meta Data',
			/*callback=*/ array($this, 'meta_box'),
			/*page=*/ $post_type,
			/*context=*/ 'normal',
			/*priority=*/ 'default'
		);
	}
	
	function meta_box () {
		global $post;
		?>
		<table>
		<tbody>
		<tr>
		<th style="text-align: left" scope="row">ID:</th>
		<td><code><?php print esc_html($post->ID); ?></code></td>
		</tr>

		<tr>
		<th style="text-align: left" scope="row">GUID:</th>
		<td><code><?php print esc_html($post->guid); ?></code></td>
		</tr>

		<tr>
		<th colspan="2" style="text-align: center"><h4>Custom Fields</h4></th>
		</tr>

		<?php
		$custom = get_post_custom($post->ID);
		if (count($custom) > 0) :
			foreach ($custom as $key => $values) :
				$idx = 1;
				foreach ($values as $value) :
					print "<tr><th style='text-align: left' scope='row'>".esc_html($key);
					if ($idx > 1) :
						print "[$idx]";
					endif;
					print ":</th> ";
					print "<td><pre><code>".esc_html($value)."</code></pre>";
					print "</td></tr>\n";
					$idx++;
				endforeach;
			endforeach;
		else :
			print "<tr><td colspan='2'><p><em>No custom fields for this post.</em></p></td></tr>\n";
		endif;
		print "</table>\n";
	} /* InspectPostMeta::meta_box() */
} /* class InspectPostMeta */

