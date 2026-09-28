<?php
/**
 * The website's own Elementor forms, passed on to EzBizi as leads.
 *
 * Jose, 2026-09-28: the sites' contact forms should arrive in Leads, like the
 * EzBizi form does. They are Elementor Pro forms that email the business; that
 * email still goes, and a copy of the enquiry now also becomes a lead.
 *
 * ── Why from the server, and after the visitor has their answer ──
 *
 * Elementor has already validated the submission (required fields, the spam
 * trap) when `new_record` fires, and the visitor is waiting for its thank-you.
 * The call to EzBizi waits for `shutdown`, after the response has been flushed,
 * so a slow EzBizi never slows a customer's form down.
 *
 * It authenticates with this site's token, not the public key: every one of
 * these comes from the web host's one address, and the public form endpoint
 * limits per address.
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('elementor_pro/forms/new_record', 'ezbizi_elementor_form_record', 20, 2);

/**
 * @param object $record  Elementor's form record: `get('fields')`, `get('form_settings')`
 */
function ezbizi_elementor_form_record($record, $handler = null) {
    $settings = ezbizi_settings();

    if ($settings['token'] === '' || ! is_object($record) || ! method_exists($record, 'get')) {
        return;
    }

    $lead = ezbizi_elementor_form_lead((array) $record->get('fields'));

    if ($lead === null) {
        return;
    }

    $lead['host'] = ezbizi_host();
    $lead['page_url'] = isset($_POST['referrer']) ? esc_url_raw(wp_unslash($_POST['referrer'])) // phpcs:ignore WordPress.Security.NonceVerification -- Elementor verified the form
        : (isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '');
    $url = rtrim($settings['api'], '/') . '/api/v1/web/plugin/form';
    $token = $settings['token'];

    add_action('shutdown', function () use ($url, $token, $lead) {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort flush
        }

        wp_remote_post($url, array(
            'timeout' => 10,
            'headers' => array('Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'),
            'body' => $lead,
        ));
    });
}

/**
 * An Elementor form's fields as EzBizi's form body, or null when nothing was
 * filled in. The question labels are kept as asked, and the name, email and
 * phone are picked out the way the EzBizi form picks them.
 *
 * @param array<string, array{type?: string, title?: string, value?: mixed, id?: string}> $fields
 * @return array<string, mixed>|null
 */
function ezbizi_elementor_form_lead(array $fields) {
    $out = array('fields' => array());
    $first = '';
    $last = '';

    foreach ($fields as $id => $field) {
        $type = isset($field['type']) ? (string) $field['type'] : 'text';

        // Machinery, not answers.
        if (in_array($type, array('recaptcha', 'recaptcha_v3', 'honeypot', 'step', 'html', 'acceptance'), true)) {
            continue;
        }

        $value = isset($field['value']) ? $field['value'] : '';
        $value = trim(is_array($value) ? implode(', ', $value) : (string) $value);
        $label = isset($field['title']) && trim((string) $field['title']) !== '' ? trim((string) $field['title']) : (string) $id;
        $low = strtolower($label . ' ' . $id);

        if (count($out['fields']) < 40) {
            $out['fields'][mb_substr($label, 0, 120)] = mb_substr($value, 0, 4000);
        }

        if ($value === '') {
            continue;
        }

        if ($type === 'email' && ! isset($out['email']) && is_email($value)) {
            $out['email'] = $value;
        } elseif (($type === 'tel' || strpos($low, 'phone') !== false) && ! isset($out['phone'])) {
            $out['phone'] = mb_substr($value, 0, 40);
        } elseif (strpos($low, 'first') !== false && strpos($low, 'name') !== false) {
            $first = $value;
        } elseif (strpos($low, 'last') !== false && strpos($low, 'name') !== false) {
            $last = $value;
        } elseif (strpos($low, 'name') !== false && ! isset($out['name'])) {
            $out['name'] = mb_substr($value, 0, 191);
        }
    }

    if (! isset($out['name']) && ($first !== '' || $last !== '')) {
        $out['name'] = mb_substr(trim($first . ' ' . $last), 0, 191);
    }

    return array_filter($out['fields'], 'strlen') === array() ? null : $out;
}
