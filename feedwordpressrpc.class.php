<?php
################################################################################
## XML-RPC HOOKS: accept XML-RPC update pings from Contributors ################
################################################################################

class FeedWordPressRPC {
	function FeedWordPressRPC () {
		add_filter('xmlrpc_methods', array($this, 'xmlrpc_methods'));
	}
	
	function xmlrpc_methods ($args = array()) {
		$args['weblogUpdates.ping'] = array($this, 'ping');
		$args['feedwordpress.subscribe'] = array($this, 'subscribe');
		$args['feedwordpress.deactivate'] = array($this, 'deactivate');
		$args['feedwordpress.delete'] = array($this, 'delete');
		$args['feedwordpress.nuke'] = array($this, 'nuke');
		return $args;
	}
	
	function ping ($args) {
		global $feedwordpress;
		
		$delta = @$feedwordpress->update($args[1]);
		if (is_null($delta)):
			return array('flerror' => true, 'message' => "Sorry. I don't syndicate <$args[1]>.");
		else:
		$mesg = array();
			return array('flerror' => false, 'message' => "Thanks for the ping.".fwp_update_set_results_message($delta));
		endif;
	}
	
	function validate (&$args) {
		global $wp_xmlrpc_server;

		// First two params are username/password
		$username = $wp_xmlrpc_server->escape(array_shift($args));
		$password = $wp_xmlrpc_server->escape(array_shift($args));

		$ret = array();
		if ( !$user = $wp_xmlrpc_server->login($username, $password) ) :
			$ret = $wp_xmlrpc_server->error;
		elseif (!current_user_can('manage_links')) :
			$ret = new IXR_Error(401, 'Sorry, you cannot change the subscription list.');
		endif;
		return $ret;
	}

	function subscribe ($args) {
		$ret = $this->validate($args);
		if (is_array($ret)) : // Success
			// The remaining params are feed URLs
			foreach ($args as $arg) :
				$finder = new FeedFinder($arg, /*verify=*/ false, /*fallbacks=*/ 1);
				$feeds = array_values(array_unique($finder->find()));
				
				if (count($feeds) > 0) :
					$link_id = FeedWordPress::syndicate_link(
						/*title=*/ feedwordpress_display_url($feeds[0]),
						/*homepage=*/ $feeds[0],
						/*feed=*/ $feeds[0]
					);
					$ret[] = array(
						'added',
						$feeds[0],
						$arg,
					);
				else :
					$ret[] = array(
						'error',
						$arg
					);
				endif;
			endforeach;
		endif;
		return $ret;
	} /* FeedWordPressRPC::subscribe () */
	
	function unsubscribe ($method, $args) {
		$ret = $this->validate($args);
		if (is_array($ret)) : // Success
			// The remaining params are feed URLs
			foreach ($args as $arg) :
				$link_id = FeedWordPress::find_link($arg);
				
				if (!$link_id) :
					$link_id = FeedWordPress::find_link($arg, 'link_url');
				endif;
				
				if ($link_id) :
					$link = new SyndicatedLink($link_id);
					
					$link->{$method}();
					$ret[] = array(
						'deactivated',
						$arg,
					);
				else :
					$ret[] = array(
						'error',
						$arg,
					);
				endif;
			endforeach;
		endif;
		return $ret;
	} /* FeedWordPress::unsubscribe () */
	
	function deactivate ($args) {
		return $this->unsubscribe('deactivate', $args);
	} /* FeedWordPressRPC::deactivate () */
	
	function delete ($args) {
		return $this->unsubscribe('delete', $args);
	} /* FeedWordPressRPC::delete () */
	
	function nuke ($args) {
		return $this->unsubscribe('nuke', $args);
	} /* FeedWordPressRPC::nuke () */
} /* class FeedWordPressRPC */

