<?php
/**
 * Contact form configuration.
 *
 * Every value can be overridden with an environment variable of the same
 * name, so on hosts that let you set them (cPanel "Environment Variables",
 * a .htaccess SetEnv, or a php-fpm pool) you can change the settings
 * without editing this file.
 */

if (!function_exists('contact_env')) {
    /**
     * Read an environment variable, falling back to a default.
     */
    function contact_env(string $key, $default = null)
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        if (is_bool($default)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        return $value;
    }
}

return [
    /**
     * Where enquiries are delivered. This is the only address the script
     * will ever send to; nothing a visitor types can redirect it.
     */
    'recipient' => contact_env('CONTACT_RECIPIENT', 'connect@ashshams.co.in'),

    /**
     * The From address on the outgoing mail.
     *
     * IMPORTANT: on shared hosting this MUST be a mailbox on a domain the
     * server is allowed to send for, normally the site's own domain. Using
     * the visitor's address here gets the mail rejected or binned by SPF
     * and DMARC. The visitor's address goes in Reply-To instead, so hitting
     * reply in your mail client still answers them directly.
     */
    'from_email' => contact_env('CONTACT_FROM_EMAIL', 'connect@ashshams.co.in'),
    'from_name'  => contact_env('CONTACT_FROM_NAME', 'AshShams Technologies Website'),

    /** Prefix added to the subject line so enquiries are easy to filter. */
    'subject_prefix' => contact_env('CONTACT_SUBJECT_PREFIX', '[Website] '),

    /**
     * Pass the envelope sender to sendmail as -f. Keeps the mail from being
     * sent as the web server user, which improves deliverability. A few
     * hosts disallow this; set it to false if mail silently stops going out
     * after deployment.
     */
    'set_envelope_sender' => contact_env('CONTACT_SET_ENVELOPE_SENDER', true),

    /**
     * Rate limiting, per IP address. Submissions beyond the limit inside
     * the window are rejected.
     */
    'rate_limit'        => (int) contact_env('CONTACT_RATE_LIMIT', 5),
    'rate_limit_window' => (int) contact_env('CONTACT_RATE_LIMIT_WINDOW', 3600),

    /**
     * Reject anything submitted faster than this many seconds after the
     * page loaded. People do not fill in four fields in two seconds; bots
     * do. Set to 0 to disable.
     */
    'min_seconds_on_page' => (int) contact_env('CONTACT_MIN_SECONDS', 3),

    /** Where the visitor is sent back to after a no-JavaScript submission. */
    'return_url' => contact_env('CONTACT_RETURN_URL', '../index.html'),
];
