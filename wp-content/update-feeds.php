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

// Are we running from a web request or from the command line?
if (!isset($_SERVER['REQUEST_URI'])) :
	$update_feeds_display = 'text/plain';
	$update_feeds_invoke = 'cmd';
elseif (isset($_POST['update'])) :
	if ($_POST['update'] == 'quiet') :
		$update_feeds_display = 'text/plain';
		$update_feeds_invoke = 'post';
		$update_feeds_verbose = false;
	elseif ($_POST['update'] == 'verbose') :
		$update_feeds_display = 'text/plain';
		$update_feeds_invoke = 'post';
		$update_feeds_verbose = true;
	else :
		$update_feeds_display = 'text/html';
		$update_feeds_invoke = 'post';
	endif;
else :
	$update_feeds_display = 'text/html';
	$update_feeds_invoke = 'get';
endif;

require_once ('../wp-blog-header.php');

function update_feeds_mention ($feed) {
	global $update_feeds_display;
	
	if ($update_feeds_display=='text/html') :
		echo "<li>Updating <cite>".$feed['link/name']."</cite> from &lt;<a href=\""
			.$feed['link/uri']."\">".$feed['link/uri']."</a>&gt; ...</li>\n";
	else :
		echo "* Updating ".$feed['link/name']." from <".$feed['link/uri']."> ...\n";
	endif;
	flush();
}

# -- Don't change these unless you know what you're doing...
define ('RPC_MAGIC', 'tag:radgeek.com/projects/feedwordpress/'); // update all

// Query secret word from database
$rpc_secret = get_settings('feedwordpress_rpc_secret');

header("Content-Type: {$update_feeds_display}; charset=utf-8");

# -- Are we running from an HTTP GET, HTTP POST, or from the command line?
if ($update_feeds_invoke != 'cmd') :  // We're acessing this from HTTP GET or HTTP POST
	// Authenticate the user, if possible ...
	if (isset($_SERVER['PHP_AUTH_USER']) and isset($_SERVER['PHP_AUTH_PW'])) : // try HTTP authentication
		$login = $_SERVER['PHP_AUTH_USER']; $pass = $_SERVER['PHP_AUTH_PW'];
	elseif (isset($_POST['log']) and isset($_POST['pwd'])) : // try POST data
		$login = $_REQUEST['log']; $pass = $_REQUEST['pwd'];
	endif;
	
	if (empty($login) or empty($pass) or !wp_login($login, $pass)) :
		if ($update_feeds_display=='text/html') :
			auth_redirect(); // try authentication cookies; if all else fails, redirect to wp-login.php
		else :
			echo "update-feeds (".date('Y-m-d H:i:s')."): ERROR: Could not log in as '$login' (password: '$pass')\n";
			die;
		endif;
	endif;

	// Henceforward, we can proceed on the assumption that we have an authenticated user
	$uri = (isset($_REQUEST['uri']) ? $_REQUEST['uri'] : RPC_MAGIC.$rpc_secret);

	if ($update_feeds_display=='text/html') :
		echo <<<EOHTML
<?xml version="1.0" encoding="utf-8"?>
<html>
<head>
<title>update-feeds :: FeedWordPress</title>
</head>

<body>
<h1>update-feeds: make FeedWordPress check for new syndicated content</h1>

EOHTML;
	endif;
else :
	// update-feeds has been invoked from the command line; no further
	// authentication is necessary. (If PAM hasn't already done the
	// necessary screening for you, you have bigger problems than their
	// access to FeedWordPress...)
	$uri = (isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : RPC_MAGIC.$rpc_secret);
endif;

$feedwordpress =& new FeedWordPress;

if ($update_feeds_display=='text/html' or $update_feeds_verbose) :
	add_action('feedwordpress_check_feed', 'update_feeds_mention');
endif;

if ($update_feeds_display=='text/html') : // HTTP GET or HTTP POST: add some web niceties

	echo "<form action=\"\" method=\"POST\">\n";
	echo "<select name=\"uri\">\n";
	echo "<option value=\"".RPC_MAGIC.$rpc_secret."\">All feeds</option>\n";
	foreach ($feedwordpress->feeds as $feed) :
		echo "<option value=\"{$feed['link/uri']}\"";
		if ($feed['link/uri']==$_REQUEST['uri']) : echo ' selected="selected"'; endif;
		echo ">{$feed['link/name']}</option>\n";
	endforeach;
	echo "</select> ";
	echo "<input type=\"submit\" name=\"update\" value=\"Update\" />\n";
	echo "</form>\n";
endif;

if ($update_feeds_invoke != 'get') : // Only do things with side-effects for HTTP POST or command line
	if ($update_feeds_display == 'text/html') : echo "<ul>\n"; endif;
	$delta = @$feedwordpress->update($uri);
	if ($update_feeds_display == 'text/html') : echo "</ul>\n"; endif;

	if (is_null($delta)) :
		if ($update_feeds_invoke == 'cmd') :
			$stderr = fopen('php://stderr', 'w');
			fputs($stderr, "update-feeds (".date('Y-m-d H:i:s')."): ERROR: I don't syndicate <$uri>\n");
		elseif ($update_feeds_display == 'text/plain') :
			echo "update-feeds (".date('Y-m-d H:i:s')."): ERROR: I don't syndicate <$uri>\n";
		else :
			echo "<p><strong>Error:</strong> I don't syndicate <a href=\"$uri\">$uri</a></p>\n";
		endif;
	elseif ($update_feeds_display=='text/html' or $update_feeds_verbose) :
		$mesg = array();
		if (isset($delta['new'])) : $mesg[] = ' '.$delta['new'].' new posts were syndicated'; endif;
		if (isset($delta['updated'])) : $mesg[] = ' '.$delta['updated'].' existing posts were updated'; endif;
		if ($update_feeds_display=='text/html') : echo "<p>"; endif;
		echo "Update complete.".implode(' and', $mesg);
		if ($update_feeds_display=='text/html') : echo "</p>"; endif;
		echo "\n"; flush();
	endif;
endif;

if ($update_feeds_display=='text/html') : // HTTP GET or HTTP POST: close off web niceties
	echo <<<EOHTML

<p><a href="../wp-admin">&larr; Return to WordPress Dashboard</a></p>
</body>
</html>
EOHTML;
endif;
?>
