<?php
/**
 * FeedWordPie_Cache class. Provides customized feed caching for FeedWordPress.
 * This is derived (currently, just copied and combined) from WordPress Core
 * wp-includes/class-wp-feed-cache.php ("Feed API: WP_Feed_Cache_Transient class")
 * and from wp-includes/SimplePie/Cache.php ("SimplePie_Cache"), pursuant to the
 * terms of the GPL.
 *
 * The wrapper class WP_Feed_Cache was deprecated in WordPress 5.6, but its intended
 * replacement, WP_Feed_Cache_Transient, is currently NOT a subclass of SimplePie_Cache
 * which causes problems registering it as a cache class in some versions of SimplePie.
 * Solution: For now, let's copy the class over under a new name, but this time, inherit
 * from SimplePie_Cache. (I am doing it this way because I might want to make some real
 * changs to the implementation of caching, in order to better support typical FeedWordPress
 * use cases. In the meantime, this should at least stop the PHP warnings and the failure
 * to correctly cache feed contents that users are encountering with WordPress 5.6.)
 *
 * @version 2021.0118
 */

/**
 * Core class used to implement feed cache transients.
 *
 * @since 2.8.0
 */
class FeedWordPie_Cache extends SimplePie_Cache {

	/**
	 * Holds the transient name.
	 *
	 * @since 2.8.0
	 * @var string
	 */
	public $name;

	/**
	 * Holds the transient mod name.
	 *
	 * @since 2.8.0
	 * @var string
	 */
	public $mod_name;

	/**
	 * Holds the cache duration in seconds.
	 *
	 * Defaults to 43200 seconds (12 hours).
	 *
	 * @since 2.8.0
	 * @var int
	 */
	public $lifetime = 43200;

	/**
	 * Constructor.
	 *
	 * @since 2.8.0
	 * @since 3.2.0 Updated to use a PHP5 constructor.
	 *
	 * @param string $location  URL location (scheme is used to determine handler).
	 * @param string $filename  Unique identifier for cache object.
	 * @param string $extension 'spi' or 'spc'.
	 */
	public function __construct( $location, $filename, $extension ) {
		$this->name     = 'feed_' . $filename;
		$this->mod_name = 'feed_mod_' . $filename;

		$lifetime = $this->lifetime;
		/**
		 * Filters the transient lifetime of the feed cache.
		 *
		 * @since 2.8.0
		 *
		 * @param int    $lifetime Cache duration in seconds. Default is 43200 seconds (12 hours).
		 * @param string $filename Unique identifier for the cache object.
		 */
		$this->lifetime = apply_filters( 'wp_feed_cache_transient_lifetime', $lifetime, $filename );
	}

	/**
	 * Sets the transient.
	 *
	 * @since 2.8.0
	 *
	 * @param SimplePie $data Data to save.
	 * @return true Always true.
	 */
	public function save( $data ) {
		if ( $data instanceof SimplePie ) {
			$data = $data->data;
		}

		set_transient( $this->name, $data, $this->lifetime );
		set_transient( $this->mod_name, time(), $this->lifetime );
		return true;
	}

	/**
	 * Gets the transient.
	 *
	 * @since 2.8.0
	 *
	 * @return mixed Transient value.
	 */
	public function load() {
		return get_transient( $this->name );
	}

	/**
	 * Gets mod transient.
	 *
	 * @since 2.8.0
	 *
	 * @return mixed Transient value.
	 */
	public function mtime() {
		return get_transient( $this->mod_name );
	}

	/**
	 * Sets mod transient.
	 *
	 * @since 2.8.0
	 *
	 * @return bool False if value was not set and true if value was set.
	 */
	public function touch() {
		return set_transient( $this->mod_name, time(), $this->lifetime );
	}

	/**
	 * Deletes transients.
	 *
	 * @since 2.8.0
	 *
	 * @return true Always true.
	 */
	public function unlink() {
		delete_transient( $this->name );
		delete_transient( $this->mod_name );
		return true;
	}

	/**
	 * Create a new SimplePie_Cache object
	 *
	 * @param string $location URL location (scheme is used to determine handler)
	 * @param string $filename Unique identifier for cache object
	 * @param string $extension 'spi' or 'spc'
	 * @return SimplePie_Cache_Base Type of object depends on scheme of `$location`
	 */
	public static function get_handler($location, $filename, $extension)
	{

		$type = explode(':', $location, 2);
		$type = $type[0];
		if (!empty(self::$handlers[$type]))
		{
			$class = self::$handlers[$type];
			return $class($location, $filename, $extension);
		}
		
		return new FeedWordPie_Cache($location, $filename, $extension);
	}

	/**
	 * Create a new SimplePie_Cache object
	 *
	 * @deprecated Use {@see get_handler} instead
	 */
	public function create($location, $filename, $extension)
	{
		trigger_error('Cache::create() has been replaced with Cache::get_handler(). Switch to the registry system to use this.', E_USER_DEPRECATED);
		return self::get_handler($location, $filename, $extension);
	}

}