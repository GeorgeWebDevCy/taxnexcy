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
        $line = current_time( 'mysql' ) . ' - ' . $message;

        // Mirror log entries to the PHP error log when debugging is enabled.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( $line );
        }

        $logs   = get_option( self::OPTION_KEY, array() );
        $logs[] = $line;
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
