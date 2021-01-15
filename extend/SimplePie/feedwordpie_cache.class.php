<?php

class FeedWordPie_Cache  {
    public static function create( $location, $filename, $extension ) {
        return new WP_Feed_Cache_Transient( $location, $filename, $extension );
    }
}

