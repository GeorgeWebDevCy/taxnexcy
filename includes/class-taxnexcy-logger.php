<?php
/**
 * Simple logger for Taxnexcy plugin.
 *
 * @package Taxnexcy
 */
class Taxnexcy_Logger {

    const LEGACY_OPTION_KEY   = 'taxnexcy_log';
    const SNAPSHOT_OPTION_KEY = 'taxnexcy_log_snapshot';
    const DEBUG_OPTION_KEY    = 'taxnexcy_debug_enabled';
    const LOG_FILENAME        = 'taxnexcy-debug.log';
    const DEFAULT_MAX_BYTES   = 100000; // ~100KB cap for file based storage.
    const DEFAULT_MAX_ENTRIES = 200; // Snapshot is capped to protect the database.

    /**
     * Add a line to the log.
     *
     * @param string $message Message to log.
     * @param array  $context Optional context data to append to the line.
     *
     * @return bool Whether the message was recorded.
     */
    public static function log( $message, $context = array() ) {
        if ( ! self::is_debug_enabled() ) {
            return false;
        }

        $line = self::format_line( $message, $context );

        $written = self::write_raw_line( $line );

        return $written;
    }

    /**
     * Determine if debug logging is enabled for the plugin.
     *
     * @return bool
     */
    public static function is_debug_enabled() {
        $enabled = false;

        if ( defined( 'TAXNEXCY_DEBUG' ) ) {
            $enabled = (bool) TAXNEXCY_DEBUG;
        }

        if ( ! $enabled ) {
            $option = get_option( self::DEBUG_OPTION_KEY, null );
            if ( null !== $option ) {
                $enabled = (bool) $option;
            }
        }

        /**
         * Filter whether Taxnexcy debug logging is enabled.
         *
         * @since 1.0.0
         *
         * @param bool $enabled Current enabled state.
         */
        return (bool) apply_filters( 'taxnexcy_debug_enabled', $enabled );
    }

    /**
     * Get all log lines.
     *
     * @return array
     */
    public static function get_logs() {
        $lines = self::read_log_file();

        if ( ! empty( $lines ) ) {
            return $lines;
        }

        $snapshot = get_option( self::SNAPSHOT_OPTION_KEY, array() );
        if ( is_array( $snapshot ) ) {
            return $snapshot;
        }

        return array();
    }

    /**
     * Clear the log.
     *
     * @return void
     */
    public static function clear() {
        $path = self::get_log_file_path();

        if ( $path && file_exists( $path ) ) {
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $path );
            } else {
                @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }

        delete_option( self::SNAPSHOT_OPTION_KEY );
        delete_option( self::LEGACY_OPTION_KEY );
    }

    /**
     * Migrate legacy option based logs into the new storage, then purge the option.
     *
     * @return void
     */
    public static function migrate_legacy_storage() {
        $legacy = get_option( self::LEGACY_OPTION_KEY, null );

        if ( null === $legacy ) {
            return;
        }

        if ( empty( $legacy ) ) {
            delete_option( self::LEGACY_OPTION_KEY );
            return;
        }

        $entries = is_array( $legacy ) ? $legacy : array( $legacy );

        foreach ( $entries as $entry ) {
            $entry = is_scalar( $entry ) ? (string) $entry : wp_json_encode( $entry );
            if ( empty( $entry ) ) {
                continue;
            }

            self::write_raw_line( trim( $entry ) );
        }

        delete_option( self::LEGACY_OPTION_KEY );
    }

    /**
     * Convert a message/context pair into a log line.
     *
     * @param mixed $message Message to log.
     * @param array $context Additional context.
     *
     * @return string
     */
    protected static function format_line( $message, $context = array() ) {
        $timestamp = current_time( 'mysql' );
        $text      = self::stringify( $message );
        $line      = sprintf( '[%s] %s', $timestamp, $text );

        if ( ! empty( $context ) ) {
            $encoded = wp_json_encode( $context );
            if ( false === $encoded ) {
                $encoded = print_r( $context, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
            }

            $line .= ' ' . self::normalise_line( $encoded );
        }

        return self::normalise_line( $line );
    }

    /**
     * Ensure the provided value can be safely stored as part of a single line log entry.
     *
     * @param mixed $value Value to format.
     *
     * @return string
     */
    protected static function stringify( $value ) {
        if ( is_string( $value ) ) {
            return self::normalise_line( $value );
        }

        if ( is_scalar( $value ) || null === $value ) {
            return self::normalise_line( (string) $value );
        }

        $encoded = wp_json_encode( $value );
        if ( false === $encoded ) {
            $encoded = print_r( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
        }

        return self::normalise_line( $encoded );
    }

    /**
     * Remove new lines and condense whitespace for a log line.
     *
     * @param string $line Line to normalise.
     *
     * @return string
     */
    protected static function normalise_line( $line ) {
        $line = str_replace( array( "\r", "\n" ), ' ', (string) $line );

        return trim( preg_replace( '/\s+/', ' ', $line ) );
    }

    /**
     * Append a line to the backing store and snapshot without any additional formatting.
     *
     * @param string $line Line to write.
     *
     * @return bool
     */
    protected static function write_raw_line( $line ) {
        $line = self::normalise_line( $line );

        if ( '' === $line ) {
            return false;
        }

        $written = self::append_to_file( $line );

        if ( $written ) {
            self::update_snapshot( $line );
        }

        return $written;
    }

    /**
     * Append a line to the capped log file.
     *
     * @param string $line Line to append.
     *
     * @return bool
     */
    protected static function append_to_file( $line ) {
        $path = self::get_log_file_path();

        if ( ! $path ) {
            return false;
        }

        $directory = dirname( $path );

        if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
            return false;
        }

        $line .= PHP_EOL;

        $result = file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );

        if ( false === $result ) {
            return false;
        }

        self::cap_log_file( $path );

        return true;
    }

    /**
     * Cap the log file to a maximum number of bytes.
     *
     * @param string $path Path to the log file.
     *
     * @return void
     */
    protected static function cap_log_file( $path ) {
        $max_bytes = (int) apply_filters( 'taxnexcy_log_max_bytes', self::DEFAULT_MAX_BYTES );

        clearstatcache( true, $path );
        $size = @filesize( $path );

        if ( ! $size || $size <= $max_bytes ) {
            return;
        }

        $handle = fopen( $path, 'r+' );

        if ( ! $handle ) {
            return;
        }

        $offset = max( $size - $max_bytes, 0 );

        if ( $offset > 0 ) {
            fseek( $handle, $offset );
            $data = fread( $handle, $max_bytes );
        } else {
            $data = fread( $handle, $max_bytes );
        }

        if ( false === $data ) {
            fclose( $handle );
            return;
        }

        $newline_position = strpos( $data, "\n" );
        if ( false !== $newline_position ) {
            $data = substr( $data, $newline_position + 1 );
        }

        ftruncate( $handle, 0 );
        rewind( $handle );
        fwrite( $handle, $data );
        fflush( $handle );
        fclose( $handle );
    }

    /**
     * Persist a snapshot of the latest log lines in an option.
     *
     * @param string $line Line to append to the snapshot.
     *
     * @return void
     */
    protected static function update_snapshot( $line ) {
        $existing = get_option( self::SNAPSHOT_OPTION_KEY, null );
        $entries  = array();
        $exists   = false;

        if ( null !== $existing ) {
            $exists  = true;
            $entries = is_array( $existing ) ? $existing : array( (string) $existing );
        }

        $entries[] = $line;

        $max_entries = (int) apply_filters( 'taxnexcy_log_snapshot_size', self::DEFAULT_MAX_ENTRIES );

        if ( $max_entries > 0 && count( $entries ) > $max_entries ) {
            $entries = array_slice( $entries, -1 * $max_entries );
        }

        if ( $exists ) {
            update_option( self::SNAPSHOT_OPTION_KEY, $entries, false );
        } else {
            add_option( self::SNAPSHOT_OPTION_KEY, $entries, '', 'no' );
        }
    }

    /**
     * Retrieve the log file contents as an array of lines.
     *
     * @return array
     */
    protected static function read_log_file() {
        $path = self::get_log_file_path();

        if ( ! $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
            return array();
        }

        $lines = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

        if ( false === $lines ) {
            return array();
        }

        return array_map( 'trim', $lines );
    }

    /**
     * Resolve the absolute path to the log file.
     *
     * @return string|false
     */
    protected static function get_log_file_path() {
        $uploads = wp_upload_dir();

        if ( ! empty( $uploads['error'] ) ) {
            return false;
        }

        $directory = trailingslashit( $uploads['basedir'] ) . 'taxnexcy';

        return trailingslashit( $directory ) . self::LOG_FILENAME;
    }
}
