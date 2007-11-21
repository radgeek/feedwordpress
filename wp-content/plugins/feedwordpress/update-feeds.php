<?php
# update-feeds.php: Instruct FeedWordPress to scan for fresh content
#
# Project: FeedWordPress
# URI: <http://projects.radgeek.com/feedwordpress>
# Author: Charles Johnson <technophilia@radgeek.com>
# License: GPL
# Version: 2007.09.16
#
# USAGE
# -----
# update-feeds.php implements a Dashboard page for the FeedWordPress plugin
# which allows you to manually instruct FeedWordPress to check for new posts on
# the feeds it syndicates. This script depends on WordPress and the
# FeedWordPress plugin and should not be invoked directly. Instead, you
# should log in to the WordPress Dashboard and go to Syndication --> Update
#
# If you are interested in setting up automatic updates rather than using the
# checking for new posts manually, see the instructions for automatic updates
# in README.text.

if (isset($_POST['update'])) :
	$fwp_update_invoke = 'post';
else :
	$fwp_update_invoke = 'get';
endif;

function update_feeds_mention ($feed) {
	echo "<li>Updating <cite>".$feed['link/name']."</cite> from &lt;<a href=\""
		.$feed['link/uri']."\">".$feed['link/uri']."</a>&gt; ...</li>\n";
	flush();
}

# -- Don't change these unless you know what you're doing...
define ('RPC_MAGIC', 'tag:radgeek.com/projects/feedwordpress/'); // update all

$uri = (isset($_REQUEST['uri']) ? $_REQUEST['uri'] : '-');
?>
<div class="wrap">
<h2>Update Feeds Now</h2>

<?php
$feedwordpress =& new FeedWordPress;
add_action('feedwordpress_check_feed', 'update_feeds_mention');
?>

<form action="" method="POST">
<select name="uri">
<option value="-">All feeds</option>
<?php foreach ($feedwordpress->feeds as $feed) :
	echo '<option value="'.$feed->uri().'"';
	if ($feed->uri()==$_REQUEST['uri']) : echo ' selected="selected"'; endif;
	echo ">".$feed->settings['link/name']."</option>\n";
endforeach; ?>
</select>
<input type="submit" name="update" value="Update" />
</form>

<?php
if ($uri == '-') : $uri = NULL; endif;

if ($fwp_update_invoke != 'get') : // Only do things with side-effects for HTTP POST or command line
	echo "<ul>\n";
	$delta = $feedwordpress->update($uri);
	echo "</ul>\n";

	if (is_null($delta)) :
		echo "<p><strong>Error:</strong> I don't syndicate <a href=\"$uri\">$uri</a></p>\n";
	else :
		$mesg = array();
		if (isset($delta['new'])) : $mesg[] = ' '.$delta['new'].' new posts were syndicated'; endif;
		if (isset($delta['updated'])) : $mesg[] = ' '.$delta['updated'].' existing posts were updated'; endif;
		echo "<p>Update complete.".implode(' and', $mesg)."</p>";
		echo "\n"; flush();
	endif;
endif;
?>
</div> <!-- class="wrap" -->
