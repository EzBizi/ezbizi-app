<?php
/**
 * The two Elementor widget classes.
 *
 * Loaded from `elementor.php` only once Elementor has announced itself, because
 * both extend `\Elementor\Widget_Base` and a site without Elementor would
 * fatal on the class declaration alone.
 *
 * Each one renders the shortcode that already exists. That is on purpose: one
 * implementation, one set of behaviour, and a site that mixes Elementor pages
 * with plain ones shows the same widget on both.
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Everything the two share: no settings of their own, and a notice saying so.
 */
abstract class EzBizi_Elementor_Widget extends \Elementor\Widget_Base {
    public function get_categories() {
        return array('general');
    }

    /**
     * What somebody types when they are looking for this.
     *
     * "ezbizi" is the one that failed, and the rest are what people search for
     * when they do not know the plugin's name — they want a contact form, not a
     * brand.
     */
    public function get_keywords() {
        return array('ezbizi', 'lead', 'enquiry', 'inquiry', 'contact');
    }

    public function get_custom_help_url() {
        return 'https://wordpress.org/plugins/ezbizi/';
    }

    /** Where the settings actually are, said at the moment they are looked for. */
    protected function register_controls() {
        $this->start_controls_section('ezbizi_where', array(
            'label' => esc_html__('EzBizi', 'ezbizi'),
        ));

        $this->add_control('ezbizi_notice', array(
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw' => esc_html__(
                'Fields, colours, font and greeting are set in EzBizi, under Web Management → Marketing → Widgets. They apply to every site at once, so there is nothing to configure here.',
                'ezbizi'
            ),
            'content_classes' => 'elementor-descriptor',
        ));

        $this->end_controls_section();
    }

    /**
     * Elementor caches rendered output for its own preview; these widgets read
     * live values from EzBizi and must not be frozen into a saved page.
     */
    protected function is_dynamic_content(): bool {
        return true;
    }
}

/** The contact form, landing as a Lead in EzBizi. */
class EzBizi_Elementor_Form extends EzBizi_Elementor_Widget {
    public function get_name() {
        return 'ezbizi-form';
    }

    public function get_title() {
        return esc_html__('EzBizi Contact Form', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-form-horizontal';
    }

    protected function render() {
        if (ezbizi_in_elementor_editor()) {
            echo ezbizi_elementor_placeholder(esc_html__('EzBizi Contact Form', 'ezbizi'));

            return;
        }

        // The shortcode escapes everything it prints; see enquiries.php.
        echo ezbizi_form_shortcode(array());
    }
}

/** The live chat, answered from EzBizi. */
class EzBizi_Elementor_Chat extends EzBizi_Elementor_Widget {
    public function get_name() {
        return 'ezbizi-chat';
    }

    public function get_title() {
        return esc_html__('EzBizi Live Chat', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-comments';
    }

    public function get_keywords() {
        return array_merge(parent::get_keywords(), array('chat', 'live chat', 'messenger'));
    }

    protected function render() {
        if (ezbizi_in_elementor_editor()) {
            echo ezbizi_elementor_placeholder(esc_html__('EzBizi Live Chat', 'ezbizi'));

            return;
        }

        echo ezbizi_chat_shortcode(array());
    }
}
