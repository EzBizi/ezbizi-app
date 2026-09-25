<?php
/**
 * The chat and form widgets, as things Elementor can find.
 *
 * ── Why this file exists ──
 *
 * `[ezbizi_chat]` and `[ezbizi_form]` have worked since 1.2.0, and on a site
 * built in Elementor that is worth almost nothing: Jose typed "EzBizi" into the
 * Elements panel and got "No widget found". That panel lists registered
 * Elementor widgets and nothing else, so a shortcode is invisible there no
 * matter how well it renders. Somebody looking for our widget in the place
 * widgets live concludes we do not have one.
 *
 * ── What is NOT here, deliberately ──
 *
 * No style controls, no field editor, no colour pickers. Everything about these
 * widgets — fields, palette, font, greeting, which of them are switched on — is
 * chosen in EzBizi under Web Management → Marketing → Widgets, and arrives with
 * the payload. Jose's rule, and it is the whole point: one place to change a
 * thing, and it changes on every site at once. Elementor controls would be a
 * second place, silently disagreeing with the first.
 *
 * So the panel carries one notice saying where the settings are. That is the
 * question somebody has the moment they drop it on a page, and answering it
 * there is cheaper than answering it in support.
 *
 * The presentation widgets added in 1.12.0 carry one more: the NAME of a
 * preset. That is not a second place to set a colour — the bundle it names is
 * still edited only in EzBizi. It exists because the same row of icons has to
 * be small in a footer and large in a contact panel, and a page has to be able
 * to say which. See `widgets.php`.
 */

if (! defined('ABSPATH')) {
    exit;
}

/*
 * Registered on Elementor's own hook, which fires only when Elementor is
 * active — the classes below extend one of its classes and cannot even be
 * declared on a site without it.
 */
add_action('elementor/widgets/register', 'ezbizi_register_elementor_widgets');

function ezbizi_register_elementor_widgets($widgets_manager) {
    if (! class_exists('\Elementor\Widget_Base')) {
        return;
    }

    require_once __DIR__ . '/elementor-widgets.php';
    require_once __DIR__ . '/elementor-media.php';

    $widgets_manager->register(new EzBizi_Elementor_Form());
    $widgets_manager->register(new EzBizi_Elementor_Chat());
    $widgets_manager->register(new EzBizi_Elementor_Social_Links());
    $widgets_manager->register(new EzBizi_Elementor_Google_Map());
    $widgets_manager->register(new EzBizi_Elementor_Post_Title());
    $widgets_manager->register(new EzBizi_Elementor_Hours_Table());
    $widgets_manager->register(new EzBizi_Elementor_City_List());
    $widgets_manager->register(new EzBizi_Elementor_Contact_Info());
    $widgets_manager->register(new EzBizi_Elementor_Cta());
    $widgets_manager->register(new EzBizi_Elementor_Phone_Box());
    $widgets_manager->register(new EzBizi_Elementor_Review_Faces());
    $widgets_manager->register(new EzBizi_Elementor_Gallery());
    $widgets_manager->register(new EzBizi_Elementor_Street_View());
    $widgets_manager->register(new EzBizi_Elementor_Video_Carousel());
}

/**
 * Whether we are being drawn inside the Elementor editor rather than on a page
 * somebody is visiting.
 *
 * The chat widget opens a conversation and polls every five seconds. Rendering
 * it live in the editor would file real conversations against the customer's
 * account every time somebody opened the page to move a heading — so the editor
 * gets a still picture instead.
 */
function ezbizi_in_elementor_editor() {
    return class_exists('\Elementor\Plugin')
        && isset(\Elementor\Plugin::$instance->editor)
        && \Elementor\Plugin::$instance->editor->is_edit_mode();
}

/**
 * What the editor shows in place of a live widget.
 *
 * Named, so it does not read as a broken embed, and it says where the settings
 * are for the second time — the panel is easy to miss once the widget is on the
 * canvas.
 */
function ezbizi_elementor_placeholder($title) {
    return '<div style="border:1px dashed #8c8f94;border-radius:6px;padding:24px;text-align:center;'
        . 'font-family:system-ui,sans-serif;color:#50575e;background:#fff">'
        . '<strong style="display:block;margin-bottom:6px;color:#1d2327">' . esc_html($title) . '</strong>'
        . esc_html__('Shown to visitors on the published page. Its fields and styling come from EzBizi.', 'ezbizi')
        . '</div>';
}
