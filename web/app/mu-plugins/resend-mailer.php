<?php

/**
 * Plugin Name: Resend Mailer
 * Description: Routes wp_mail() through the Resend HTTP API. Configure via .env (RESEND_API_KEY, MAIL_FROM, MAIL_FROM_NAME).
 * Author:      Shop4Trac
 * Version:     1.0.0
 */

namespace Shop4Trac\ResendMailer;

if (!defined('ABSPATH')) {
    exit;
}

const ENDPOINT = 'https://api.resend.com/emails';

/**
 * Short-circuit wp_mail() and send through Resend.
 *
 * Returning null defers to core's PHPMailer path (used when no API key is configured).
 * Returning bool signals success/failure.
 */
add_filter('pre_wp_mail', __NAMESPACE__ . '\\send', 10, 2);

function send($short_circuit, array $atts)
{
    $api_key = ($_ENV['RESEND_API_KEY'] ?? getenv('RESEND_API_KEY')) ?: null;
    if (!$api_key) {
        return $short_circuit;
    }

    try {
        $payload = build_payload($atts);
    } catch (\Throwable $e) {
        fail($atts, 'resend_payload_error: ' . $e->getMessage());
        return false;
    }

    $response = wp_remote_post(ENDPOINT, [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ],
        'body'    => wp_json_encode($payload),
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
        fail($atts, 'resend_transport_error: ' . $response->get_error_message());
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        $body = wp_remote_retrieve_body($response);
        fail($atts, sprintf('resend_api_error: HTTP %d %s', $code, $body));
        return false;
    }

    return true;
}

/**
 * Translate wp_mail() args into Resend's API shape.
 */
function build_payload(array $atts): array
{
    $headers     = parse_headers($atts['headers'] ?? []);
    $from        = $headers['from'] ?? default_from();
    $reply_to    = $headers['reply-to'] ?? [];
    $cc          = $headers['cc'] ?? [];
    $bcc         = $headers['bcc'] ?? [];
    $custom      = $headers['custom'] ?? [];
    $is_html     = ($headers['content-type'] ?? '') === 'text/html'
        || apply_filters('wp_mail_content_type', 'text/plain') === 'text/html';

    $message = (string) ($atts['message'] ?? '');
    $payload = [
        'from'    => $from,
        'to'      => normalize_recipients($atts['to'] ?? []),
        'subject' => (string) ($atts['subject'] ?? ''),
    ];

    $payload[$is_html ? 'html' : 'text'] = $message;

    if ($cc) {
        $payload['cc'] = $cc;
    }
    if ($bcc) {
        $payload['bcc'] = $bcc;
    }
    if ($reply_to) {
        $payload['reply_to'] = $reply_to;
    }
    if ($custom) {
        $payload['headers'] = $custom;
    }

    $attachments = build_attachments($atts['attachments'] ?? []);
    if ($attachments) {
        $payload['attachments'] = $attachments;
    }

    return $payload;
}

/**
 * Parse the headers arg (string with CRLF or array of "Key: value" lines) into a structured form.
 */
function parse_headers($headers): array
{
    $out = [
        'cc'     => [],
        'bcc'    => [],
        'custom' => [],
    ];

    if (empty($headers)) {
        return $out;
    }

    if (is_string($headers)) {
        $headers = preg_split("/\r\n|\n|\r/", $headers) ?: [];
    }

    foreach ($headers as $header) {
        if (!is_string($header) || !str_contains($header, ':')) {
            continue;
        }
        [$name, $value] = array_map('trim', explode(':', $header, 2));
        $key = strtolower($name);

        switch ($key) {
            case 'from':
                $out['from'] = $value;
                break;
            case 'reply-to':
                $out['reply-to'] = split_addresses($value);
                break;
            case 'cc':
                $out['cc'] = array_merge($out['cc'], split_addresses($value));
                break;
            case 'bcc':
                $out['bcc'] = array_merge($out['bcc'], split_addresses($value));
                break;
            case 'content-type':
                $out['content-type'] = strtolower(trim(explode(';', $value)[0]));
                break;
            default:
                $out['custom'][$name] = $value;
        }
    }

    return $out;
}

function normalize_recipients($to): array
{
    if (is_string($to)) {
        return split_addresses($to);
    }
    if (is_array($to)) {
        $flat = [];
        foreach ($to as $entry) {
            foreach (split_addresses((string) $entry) as $addr) {
                $flat[] = $addr;
            }
        }
        return $flat;
    }
    return [];
}

function split_addresses(string $value): array
{
    return array_values(array_filter(array_map('trim', explode(',', $value))));
}

function default_from(): string
{
    $email = ($_ENV['MAIL_FROM'] ?? getenv('MAIL_FROM')) ?: null;
    $name  = ($_ENV['MAIL_FROM_NAME'] ?? getenv('MAIL_FROM_NAME')) ?: null;

    if (!$email) {
        // Match core fallback so DMARC alignment is at least attempted.
        $host  = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'localhost';
        $email = 'wordpress@' . preg_replace('/^www\./i', '', $host);
    }

    $email = apply_filters('wp_mail_from', $email);
    $name  = apply_filters('wp_mail_from_name', $name ?: get_bloginfo('name'));

    return $name ? sprintf('%s <%s>', $name, $email) : $email;
}

/**
 * Resolve wp_mail attachment arg into Resend's [{filename, content(base64)}] form.
 * Accepts: string path, array of paths, or array of filename => path (WP 6.0+).
 */
function build_attachments($attachments): array
{
    if (empty($attachments)) {
        return [];
    }
    if (is_string($attachments)) {
        $attachments = [$attachments];
    }

    $out = [];
    foreach ($attachments as $key => $path) {
        if (!is_string($path) || !is_readable($path)) {
            continue;
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            continue;
        }
        $filename = is_string($key) ? $key : basename($path);
        $out[] = [
            'filename' => $filename,
            'content'  => base64_encode($bytes),
        ];
    }
    return $out;
}

/**
 * Mirror core's wp_mail() failure semantics so callers and logs behave normally.
 */
function fail(array $atts, string $reason): void
{
    error_log('[resend-mailer] ' . $reason);

    $error = new \WP_Error('wp_mail_failed', $reason, $atts);
    do_action('wp_mail_failed', $error);
}
