<?php
/**
 * Simple logger for Taxnexcy plugin.
 *
 * @package Taxnexcy
 */
class Taxnexcy_Logger {

    const OPTION_KEY = 'taxnexcy_log';

    /**
     * Add a line to the log.
     *
     * @param string $message Message to log.
     */
    public static function log( $message ) {
        $logs   = get_option( self::OPTION_KEY, array() );
        $logs[] = current_time( 'mysql' ) . ' - ' . $message;
        update_option( self::OPTION_KEY, $logs );
    }

    /**
     * Get all log lines.
     *
     * @return array
     */
    public static function get_logs() {
        return get_option( self::OPTION_KEY, array() );
    }

    /**
     * Clear the log.
     */
    public static function clear() {
        delete_option( self::OPTION_KEY );
    }
}
