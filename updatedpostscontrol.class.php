<?php
class UpdatedPostsControl {
	var $page;
	function UpdatedPostsControl (&$page) {
		$this->page =& $page;
	} /* UpdatedPostsControl constructor */

	function display () {
		$settings = array(
			// This is all bass-ackwards because the actual yes/no
			// setting is whether to *freeze* posts out of being
			// updated, not whether to *expose* it to being updated,
			// but in the UI we ask whether the user wants to
			// *expose* it. I have my reasons. Stop judging me!
			'no' => __('Yes, update the syndicated copy to match'),
			'yes' => __('No, leave the syndicated copy unmodified'),
		);
		$params = array(
		'setting-default' => 'default',
		'global-setting-default' => 'no',
		'labels' => array('yes' => 'leave unmodified', 'no' => 'update to match'),
		'default-input-value' => 'default',
		);
		
		if ($this->page->for_feed_settings()) :
			$aFeed = 'this feed';
		else :
			$aFeed = 'a syndicated feed';
		endif;
	?>
		<tr>
		<th scope="row"><?php _e('Updated posts:') ?></th>
		<td><p>When <?php print $aFeed; ?> includes updated content for
		a post that was already syndicated, should the syndicated copy
		of the post be updated to match the revised version?</p>
		
		<?php
			$this->page->setting_radio_control(
				'freeze updates', 'freeze_updates',
				$settings, $params
			);
		?>
		
		</td></tr>
	<?php		
	} /* UpdatedPostsControl::display() */
	
	function accept_POST ($post) {
		if ($this->page->for_feed_settings()) :
			if (isset($post['freeze_updates'])) :
				$this->page->link->settings['freeze updates'] = $post['freeze_updates'];
			endif;
		else :
			// Updated posts
			if (isset($post['freeze_updates'])) :
				update_option('feedwordpress_freeze_updates', $post['freeze_updates']);
			endif;
		endif;
	} /* UpdatedPostsControl::accept_POST() */
} /* class UpdatedPostsControl */


