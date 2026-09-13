<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', '/tmp/wordpress/wp-content/plugins');
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!class_exists('WP_Error')) {
    /**
     * Minimal WP_Error test double.
     */
    class WP_Error
    {
        /** @var array<string, string[]> */
        private array $errors = [];

        public function __construct(string $code = '', string $message = '')
        {
            if ($code !== '') {
                $this->errors[$code][] = $message;
            }
        }

        public function get_error_code(): string
        {
            return (string) array_key_first($this->errors);
        }

        public function get_error_message(): string
        {
            $code = $this->get_error_code();

            return $this->errors[$code][0] ?? '';
        }
    }
}

if (!class_exists('WP_Filesystem_Base')) {
    /**
     * Minimal WP_Filesystem_Base test double.
     */
    class WP_Filesystem_Base
    {
    }
}
