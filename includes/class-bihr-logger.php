<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BihrWI_Logger {

    public function log( $message ) {
        $date = gmdate( 'Y-m-d H:i:s' );
        $line = "[$date] $message" . PHP_EOL;

        if ( ! file_exists( BIHRWI_LOG_FILE ) ) {
            $dir = dirname( BIHRWI_LOG_FILE );
            if ( ! file_exists( $dir ) ) {
                wp_mkdir_p( $dir );
            }
            file_put_contents( BIHRWI_LOG_FILE, '' );
        }

        file_put_contents( BIHRWI_LOG_FILE, $line, FILE_APPEND );
    }

    public function get_log_contents() {
        if ( file_exists( BIHRWI_LOG_FILE ) ) {
            return file_get_contents( BIHRWI_LOG_FILE );
        }
        return '';
    }

    public function clear_logs() {
        if ( file_exists( BIHRWI_LOG_FILE ) ) {
            file_put_contents( BIHRWI_LOG_FILE, '' );
        }
    }
}
