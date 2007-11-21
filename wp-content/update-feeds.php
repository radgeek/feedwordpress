<?php
# update-feeds.php: Instruct FeedWordPress to scan for fresh content
#
# Project: FeedWordPress
# URI: <http://projects.radgeek.com/feedwordpress>
# Author: Charles Johnson <technophilia@radgeek.com>
# License: GPL
# Version: 2005.04.09
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
# 2.	Set up a cron job to run update-feeds.php locally:
#
# 		cd <your-wordpress>/wp-content ; php update-feeds.php
#
#	or to send an HTTP POST request to the appropriate URI:
#
# 		curl http://xyz.com/wp-content/update-feeds.php -d shibboleth=foo
#
# 4.	If you want to update *one* of the feeds rather than *all* of them, then
# 	pass the URI and title as command-line arguments:
#
# 		$ php update-feeds.php http://www.radgeek.com "Geekery Today"
#
# 	or in the POST request:
#
# 		$ curl http://www.xyz.com/wp-content/update-feeds.php -d uri=http://www.radgeek.com\&title=Geekery+Today\&shibboleth=foo
#

require_once ('../wp-config.php');
require_once (ABSPATH . WPINC . '/class-IXR.php');

# -- Don't change these unless you know what you're doing...
define ('RPC_URI', NULL); // Change this setting to ping a URI of your own devising
define ('RPC_MAGIC', 'tag:radgeek.com/projects/feedwordpress/'); // update all

if (is_null(RPC_URI)):
	$rpc_uri = get_settings('siteurl');
	if (substr($rpc_uri,-1)!='/') $rpc_uri .= '/';
	$rpc_uri .= 'xmlrpc.php';
else:
	$rpc_uri = RPC_URI;
endif;

# -- Are we running from an HTTP GET or from the command line?
if (isset($_SERVER['REQUEST_URI'])) {
	$rpc_secret = (isset($_REQUEST['shibboleth'])?$_REQUEST['shibboleth']:'');
	$uri = (isset($_REQUEST['uri']) ? $_REQUEST['uri'] : RPC_MAGIC.$rpc_secret);
	$blog = (isset($_REQUEST['title']) ? $_REQUEST['title'] : 'Refresh');

	echo <<< EOHTML
<html>
<head>
<title>update-feeds :: FeedWordPress</title>
</head>

<body>
<h1>update-feeds: instruct FeedWordPress to look for new syndicated content</h1>

<p>Sending ping to &lt;$rpc_uri&gt;...</p>

<p>
EOHTML;
} else {
	// Query secret word from database
	$rpc_secret = get_settings('feedwordpress_rpc_secret');

	$uri = (isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : RPC_MAGIC.$rpc_secret);
	$blog = (isset($_SERVER['argv'][2]) ? $_SERVER['argv'][2] : 'Refresh');
}

$client =& new IXR_Client($rpc_uri);
$ret = $client->query('weblogUpdates.ping', $blog, $uri);

if (!$ret):
	if (!isset($_SERVER['REQUEST_URI'])) echo "[".date('Y-m-d H:i:s')."][update-feeds] ";
	echo "The XML-RPC ping failed (local): ".wp_specialchars($client->getErrorMessage())."\n";
else:
	$response = $client->getResponse();
	if ($response['flerror']):
		if (!isset($_SERVER['REQUEST_URI'])) echo "[".date('Y-m-d H:i:s')."][update-feeds] ";
		echo "The XML-RPC ping failed (remote):  ".wp_specialchars($response['message'])."\n";
	elseif (isset($_SERVER['REQUEST_URI'])):
		if (!isset($_SERVER['REQUEST_URI'])) echo "[".date('Y-m-d H:i:s')."][update-feeds] ";
		echo "The XML-RPC ping succeeded: ".wp_specialchars($response['message'])."\n";
	endif;
endif;

if (isset($_SERVER['REQUEST_URI'])) {
	echo <<<EOHTML
</p>

<p><a href="../wp-admin">&larr; Return to WordPress Dashboard</a></p>
</body>
</html>
EOHTML;
}
?>
