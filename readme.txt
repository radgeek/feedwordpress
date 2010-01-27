=== FeedWordPress ===
Contributors: Charles Johnson
Donate link: http://feedwordpress.radgeek.com/
Tags: syndication, aggregation, feed, atom, rss
Requires at least: 1.5
Tested up to: 2.9.1
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
because I needed a more flexible replacement for [Planet](http://www.planetplanet.org/)
to use at [Feminist Blogs](http://feministblogs.org/).

FeedWordPress is designed with flexibility, ease of use, and ease of
configuration in mind. You'll need a working installation of WordPress or
WordPress MU (versions [2.8][], [2.7][], [2.6][], [2.5][], [2.3][], [2.2][],
[2.1][], [2.0][] or [1.5][]), and also FTP or SFTP access to your web host. The
ability to create cron jobs on your web host is helpful but not absolutely
necessary. You *don't* need to tweak any plain-text configuration files and you
*don't* need shell access to your web host to make it work. (Although, I should
point out, web hosts that *don't* offer shell access are *bad web hosts*.)

  [2.8]: http://codex.wordpress.org/Version_2.8
  [2.7]: http://codex.wordpress.org/Version_2.7
  [2.6]: http://codex.wordpress.org/Version_2.6
  [2.5]: http://codex.wordpress.org/Version_2.5
  [2.3]: http://codex.wordpress.org/Version_2.3
  [2.2]: http://codex.wordpress.org/Version_2.2
  [2.1]: http://codex.wordpress.org/Version_2.1
  [2.0]: http://codex.wordpress.org/Version_2.0
  [1.5]: http://codex.wordpress.org/Version_1.5

== Installation ==

To use FeedWordPress, you will need:

* 	an installed and configured copy of WordPress version 2.x, or 1.5.x.
	(FeedWordPress will also work with the equivalent versions of WordPress
	MU.)

*	FTP or SFTP access to your web host

= New Installations =

1.	Download the FeedWordPress archive and extract the files on your computer. 

2.	Create a new directory named `feedwordpress` in the `wp-content/plugins`
	directory of your WordPress installation. Use an FTP or SFTP client to
	upload the contents of your FeedWordPress archive to the new directory
	that you just created on your web host.

3.	Upgrade the copy of MagpieRSS packaged with WordPress by installing the
	new copies of `rss.php` and `rss-functions.php` into the `wp-includes`
	directory of your FeedWordPress installation. These files are stored in
	the `MagpieRSS-upgrade` directory of your FeedWordPress	archive. Strictly
	speaking, upgrading MagpieRSS is optional; FeedWordPress will run
	correctly without the upgrade. But if you hope to take advantage of
	numerous bug fixes, or support for Atom 1.0, multiple post categories,
	RSS enclosures,	or multiple character encodings, then you need to
	install the upgrade.

4.	Log in to the WordPress Dashboard and activate the FeedWordPress plugin.

5.	Once the plugin is activated, you can go to **Syndication --> Options**
	and set (1) the link category that FeedWordPress will syndicate links
	from (by default, "Contributors"), and (2) whether FeedWordPress will
	use automatic updates or only manual updates.

5.	Go to the main **Syndication** page to set up the list of sites that
	you want FeedWordPress to syndicate onto your blog.

= Upgrades =

To *upgrade* an existing installation of FeedWordPress to version 2008.1030:

1.	Download the FeedWordPress archive in zip or gzipped tar format and
	extract the files on your computer. 

2.	If you are upgrading from version 0.98 or earlier, then you need to
	create a new directory named `feedwordpress` in the `wp-content/plugins`
	directory of your WordPress installation, and you also need to *delete*
	your existing `wp-content/update-feeds.php` and
	`wp-content/plugins/feedwordpress.php` files. The file structure for
	FeedWordPress has changed and the files from your old version will not
	be overwritten, which could cause conflicts if you leave them in place.

3.	Upload the new PHP files to `wp-content/plugins/feedwordpress`,
	overwriting any existing FeedWordPress files that are there. Also be
	sure to upgrade the MagpieRSS module by uploading `rss.php` and
	`rss-functions.php` from the `MagpieRSS-upgrade` directory in your 
	archive to the `wp-includes` directory of your WordPress installation.

3.	If you are upgrading from version 0.96 or earlier, **immediately** log
	in to the WordPress Dashboard, and go to **Options --> Syndicated**.
	Follow the directions to launch the database upgrade procedure. The new
	versions of FeedWordPress incorporate some long-needed improvements, but
	old meta-data needs to be updated to prevent duplicate posts and other
	possible maladies. If you're upgrading an existing installation, updates
	and FeedWordPress template functions *will not work* until you've done
	the upgrade. Then take a coffee break while the upgrade runs. It should,
	hopefully, finish within a few minutes even on relatively large
	databases.

4.	If you are upgrading from version 0.98 or earlier, note that the old
	`update-feeds.php` has been eliminated in favor of a (hopefully) more
	humane method for automatic updating. If you used a cron job for
	scheduled updates, it will not work anymore, but there is another,
	simpler method which will. See [Setting Up Feed Updates](http://projects.radgeek.com/feedwordpress/install/#setting-up-feed-updates)
	to get scheduled updates back on track.

5.	Enjoy your new installation of FeedWordPress.

== Using and Customizing FeedWordPress ==

FeedWordPress has many options which can be accessed through the WordPress
Dashboard, and a lot of functionality accessible programmatically through
WordPress templates or plugins. For further documentation of the ins and
outs, see the documentation at the [FeedWordPress project homepage][].

  [FeedWordPress project homepage]: http://projects.radgeek.com/feedwordpress/

== License ==

The FeedWordPress plugin is copyright © 2005-2007 by Charles Johnson. It uses
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

