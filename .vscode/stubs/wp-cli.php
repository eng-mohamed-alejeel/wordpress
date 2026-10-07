<?php
/**
 * Analysis-only declarations for the WP-CLI API used by this project.
 * Signatures reviewed against https://github.com/wp-cli/wp-cli/blob/main/php/class-wp-cli.php.
 * Never included by WordPress or CLI commands; the false branch also prevents runtime declarations.
 * Remove this file from indexing if the complete WP-CLI source is added to includePaths.
 */
if ( false ) {
    class WP_CLI {
        /**
         * @param string $name
         * @param callable|object|string $callable
         * @param array $args
         * @return bool
         */
        public static function add_command( $name, $callable, $args = array() ) {}
        /**
         * @param string $message
         * @return void
         */
        public static function log( $message ) {}
        /**
         * @param string $message
         * @return void
         */
        public static function warning( $message ) {}
        /**
         * @param string $message
         * @return void
         */
        public static function success( $message ) {}
        /**
         * @param string|\WP_Error|\Exception|\Throwable $message
         * @param bool|int $exit
         * @return void
         */
        public static function error( $message, $exit = true ) {}
    }
}
