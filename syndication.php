<?php
	require_once(dirname(__FILE__).'/feedwordpresssyndicationpage.class.php');

	$syndicationPage = new FeedWordPressSyndicationPage(__FILE__);
	$syndicationPage->display();

