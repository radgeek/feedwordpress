<?php
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'feedwordpress update', function( $args, $assoc_args ) {
		$uri = empty( $args ) ? null : $args[0];
		if ( isset( $assoc_args['url'] ) ) {
			$uri = $assoc_args['url'];
		}

		global $feedwordpress;
		if ( ! isset( $feedwordpress ) ) {
			$feedwordpress = new FeedWordPress;
		}
		$feedwordpress->update( $uri );
	} );
}
