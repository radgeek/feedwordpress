<?php
# update-feeds.php: Instruct FeedWordPress to scan for fresh content
#
# Project: FeedWordPress
# URI: <http://projects.radgeek.com/feedwordpress>
# Author: Charles Johnson <technophilia@radgeek.com>
# License: GPL
# Version: 2005.11.06
#
# USAGE
# -----
# update-feeds.php is a useful script for instructing the FeedWordPress plugin
# to scan for fresh content on the feeds that it syndicates. This is handy if
# you want your syndication site to actually syndicate content, and you can't
# rely on all your contributors to send you an XML-RPC ping when they update.
# 
# 1. 	Install FeedWordPress and activate the FeedWordPress plugin. (See
# 	<http://projects.radgeek.com/feedwordpress/install> if you need a guide
# 	for the perplexed.)
#
# 2.	If you want to manually update one or more of your feeds, you can do
#	so by pointing your web browser to the URI
# 	<http://xyz.com/wp-content/update-feeds.php>, where `http://xyz.com/` is
#	replaced by the URI to your installation of WordPress. Log in as any
#	user in your WordPress database and use the form to update.
#
# 3.	To keep content up-to-date automatically, set up a cron job to run
#	update-feeds.php locally:
#
# 		cd /path/to/wordpress/wp-content ; php update-feeds.php
#
#	where `/path/to/wordpress` is replaced by the filesystem path to your
#	installation of WordPress; or, if you don't have, or don't want to use,
#	command-line PHP, you can send an HTTP POST request to the appropriate
#	URI:
#
# 		curl --user user:pass http://xyz.com/wp-content/update-feeds.php -d update=quiet
#
#	`user` and `pass` should be replaced by the username and password of
#	a user in your WordPress database (you can create a dummy user for
#	updates if you want; that's what I do). `http://xyz.com/` should be
#	replaced by the URI to your installation of WordPress.
#
#	Don't be afraid to run this cron job frequently. FeedWordPress staggers
#	updates over time rather than checking all of the feeds every time the
#	cron job runs, so even if the cron job runs every 10 minutes, each feed
#	will, on average only be polled for updates once an hour or so (or less
#	frequently if the feed author requests less frequent updates using
#	the RSS <ttl> element or the syndication module elements).
#
# 4.	If you want to update *one* of the feeds rather than *all* of them, then
# 	pass the URI and title as command-line arguments:
#
# 		$ php update-feeds.php http://radgeek.com
#
# 	or in the POST request:
#
# 		$ curl --user login:password http://xyz.com/wp-content/update-feeds.php -d uri=http://www.radgeek.com\&update=quiet
#
// Help us to pick out errors, if any.
ini_set('error_reporting', E_ALL & ~E_NOTICE);
ini_set('display_errors', true);
define('MAGPIE_DEBUG', true);

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
