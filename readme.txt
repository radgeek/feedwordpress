=== FeedWordPress ===
Contributors: Charles Johnson
Donate link: http://feedwordpress.radgeek.com/
Tags: syndication, aggregation, feed, atom, rss
Requires at least: 2.8
Tested up to: 3.0 beta
Stable tag: 2010.0127

FeedWordPress syndicates content from feeds you choose into your WordPress weblog. 

== Description ==

* Author: [Charles Johnson](http://radgeek.com/contact)
* Project URI: <http://projects.radgeek.com/feedwordpress>
* License: GPL 2. See License below for copyright jots and tittles.

FeedWordPress is an Atom/RSS aggregator for WordPress. It syndicates content
from feeds that you choose into your WordPress weblog; if you syndicate several
feeds then you can use WordPress's posts database and templating engine as the
back-end of an aggregation ("planet") website. It was developed, originally,
because I needed a more flexible replacement for [Planet][]
to use at [Feminist Blogs][].

[Planet]: http://www.planetplanet.org/
[Feminist Blogs]: http://feministblogs.org/

FeedWordPress is designed with flexibility, ease of use, and ease of
configuration in mind. You'll need a working installation of WordPress or
WordPress MU (version [2.8] or later), and also FTP or SFTP access to your web
host. The ability to create cron jobs on your web host is helpful but not
required. You *don't* need to tweak any plain-text configuration files and you
*don't* need shell access to your web host to make it work. (Although, I should
point out, web hosts that *don't* offer shell access are *bad web hosts*.)

  [WordPress]: http://wordpress.org/
  [WordPress MU]: http://mu.wordpress.org/
  [2.8]: http://codex.wordpress.org/Version_2.8

== Installation ==

To use FeedWordPress, you will need:

* 	an installed and configured copy of [WordPress][] or [WordPress MU][]
	(version 2.8 or later).

*	FTP, SFTP or shell access to your web host

= New Installations =

1.	Download the FeedWordPress installation package and extract the files on
	your computer. 

2.	Create a new directory named `feedwordpress` in the `wp-content/plugins`
	directory of your WordPress installation. Use an FTP or SFTP client to
	upload the contents of your FeedWordPress archive to the new directory
	that you just created on your web host.

3.	Log in to the WordPress Dashboard and activate the FeedWordPress plugin.

4.	Once the plugin is activated, a new **Syndication** section should
	appear in your WordPress admin menu. Click here to add new syndicated
	feeds, set up configuration options, and determine how FeedWordPress
	will check for updates. For help, see the [FeedWordPress Quick Start][]
	page.
	
[FeedWordPress Quick Start]: http://feedwordpress.radgeek.com/wiki/quick-start

= Upgrades =

To *upgrade* an existing installation of FeedWordPress to the most recent
release:

1.	Download the FeedWordPress installation package and extract the files on
	your computer. 

2.	Upload the new PHP files to `wp-content/plugins/feedwordpress`,
	overwriting any existing FeedWordPress files that are there.
	
3.	Log in to your WordPress administrative interface immediately in order
	to see whether there are any further tasks that you need to perform
	to complete the upgrade.

4.	Enjoy your newer and hotter installation of FeedWordPress

== Using and Customizing FeedWordPress ==

FeedWordPress has many options which can be accessed through the WordPress
Dashboard, and a lot of functionality accessible programmatically through
WordPress templates or plugins. For further documentation of the ins and
outs, see the documentation at the [FeedWordPress project homepage][].

  [FeedWordPress project homepage]: http://feedwordpress.radgeek.com/

== License ==

The FeedWordPress plugin is copyright © 2005-2010 by Charles Johnson. It uses
code derived or translated from:

-	[wp-rss-aggregate.php][] by [Kellan Elliot-McCrea](kellan@protest.net)
-       [MagpieRSS][] by [Kellan Elliot-McCrea](kellan@protest.net)
-	[HTTP Navigator 2][] by [Keyvan Minoukadeh](keyvan@k1m.com)
-	[Ultra-Liberal Feed Finder][] by [Mark Pilgrim](mark@diveintomark.org)

according to the terms of the [GNU General Public License][].

This program is free software; you can redistribute it and/or modify it under
the terms of the [GNU General Public License][] as published by the Free
Software Foundation; either version 2 of the License, or (at your option) any
later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE. See the GNU General Public License for more details.

  [wp-rss-aggregate.php]: http://laughingmeme.org/archives/002203.html
  [MagpieRSS]: http://magpierss.sourceforge.net/
  [HTTP Navigator 2]: http://www.keyvan.net/2004/11/16/http-navigator/
  [Ultra-Liberal Feed Finder]: http://diveintomark.org/projects/feed_finder/
  [GNU General Public License]: http://www.gnu.org/copyleft/gpl.html

