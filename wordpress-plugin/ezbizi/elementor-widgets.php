<?php
/**
 * The Elementor widget classes.
 *
 * Loaded from `elementor.php` only once Elementor has announced itself, because
 * they extend `\Elementor\Widget_Base` and a site without Elementor would
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

/**
 * The widgets that render published values, and the one control they share.
 *
 * ── Why a preset NAME is the only control ──
 *
 * The presets are the styling, and they live in EzBizi so that a company with
 * six subdomains sets its icon size once. A size control here would be a
 * second place to set the same thing, disagreeing with the first, on the one
 * page somebody edited — which is the arrangement `elementor.php` was written
 * to avoid. A name is not a value: it points at the bundle, and the bundle is
 * still edited in one place and still reaches every site at once.
 *
 * Leaving it empty is the normal case and resolves to the default, so the
 * widget draws the moment it is dropped on the canvas.
 */
abstract class EzBizi_Elementor_Preset_Widget extends EzBizi_Elementor_Widget {
    /** The shortcode this widget is a face for. */
    abstract protected function ezbizi_draw($preset);

    protected function register_controls() {
        $this->start_controls_section('ezbizi_preset_section', array(
            'label' => esc_html__('EzBizi', 'ezbizi'),
        ));

        $this->add_control('ezbizi_preset', array(
            'label' => esc_html__('Preset', 'ezbizi'),
            'type' => \Elementor\Controls_Manager::TEXT,
            'placeholder' => 'default',
            'description' => esc_html__(
                'The name of a preset set up in EzBizi, under Web Management → Marketing → Widgets. Leave it empty for the default. A name that does not exist falls back to the default rather than showing nothing.',
                'ezbizi'
            ),
        ));

        $this->end_controls_section();
    }

    protected function render() {
        if (ezbizi_in_elementor_editor()) {
            echo ezbizi_elementor_placeholder($this->get_title());

            return;
        }

        $settings = $this->get_settings_for_display();
        $preset = isset($settings['ezbizi_preset']) && is_string($settings['ezbizi_preset'])
            ? $settings['ezbizi_preset']
            : '';

        // The shortcodes escape everything they print; see widgets.php.
        echo $this->ezbizi_draw($preset);
    }
}

/** The row of social links. */
class EzBizi_Elementor_Social_Links extends EzBizi_Elementor_Preset_Widget {
    public function get_name() {
        return 'ezbizi-social-links';
    }

    public function get_title() {
        return esc_html__('EzBizi Social Links', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-social-icons';
    }

    public function get_keywords() {
        return array_merge(parent::get_keywords(), array('social', 'icons', 'facebook', 'instagram'));
    }

    protected function ezbizi_draw($preset) {
        return ezbizi_social_links_shortcode(array('preset' => $preset));
    }
}

/** The map of where the business is. */
class EzBizi_Elementor_Google_Map extends EzBizi_Elementor_Preset_Widget {
    public function get_name() {
        return 'ezbizi-google-map';
    }

    public function get_title() {
        return esc_html__('EzBizi Map', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-google-maps';
    }

    public function get_keywords() {
        return array_merge(parent::get_keywords(), array('map', 'google maps', 'location', 'address'));
    }

    protected function ezbizi_draw($preset) {
        return ezbizi_google_map_shortcode(array('preset' => $preset));
    }
}

/** The week, as a table. */
class EzBizi_Elementor_Hours_Table extends EzBizi_Elementor_Preset_Widget {
    public function get_name() {
        return 'ezbizi-hours-table';
    }

    public function get_title() {
        return esc_html__('EzBizi Hours Table', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-table';
    }

    public function get_keywords() {
        return array_merge(parent::get_keywords(), array('hours', 'opening hours', 'schedule', 'table'));
    }

    protected function ezbizi_draw($preset) {
        return ezbizi_hours_table_shortcode(array('preset' => $preset));
    }
}

/** The towns this site has pages for. */
class EzBizi_Elementor_City_List extends EzBizi_Elementor_Preset_Widget {
    public function get_name() {
        return 'ezbizi-city-list';
    }

    public function get_title() {
        return esc_html__('EzBizi City List', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-bullet-list';
    }

    public function get_keywords() {
        return array_merge(parent::get_keywords(), array('cities', 'towns', 'service area', 'locations'));
    }

    protected function ezbizi_draw($preset) {
        return ezbizi_city_list_shortcode(array('preset' => $preset));
    }
}

/**
 * This page's own title.
 *
 * The one widget with no preset, because it has no presentation to bundle: it
 * renders a heading's worth of text and the page styles it. A preset control
 * that resolved to nothing would be a box somebody fills in and then wonders
 * about, so it gets the notice instead.
 */
class EzBizi_Elementor_Post_Title extends EzBizi_Elementor_Widget {
    public function get_name() {
        return 'ezbizi-post-title';
    }

    public function get_title() {
        return esc_html__('EzBizi Page Title', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-post-title';
    }

    public function get_keywords() {
        return array_merge(parent::get_keywords(), array('title', 'page title', 'heading'));
    }

    protected function register_controls() {
        $this->start_controls_section('ezbizi_where', array(
            'label' => esc_html__('EzBizi', 'ezbizi'),
        ));

        $this->add_control('ezbizi_notice', array(
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw' => esc_html__(
                'Shows the title of the page it is placed on, so one template can head every town page with its own name. There is nothing to configure.',
                'ezbizi'
            ),
            'content_classes' => 'elementor-descriptor',
        ));

        $this->end_controls_section();
    }

    protected function render() {
        if (ezbizi_in_elementor_editor()) {
            echo ezbizi_elementor_placeholder(esc_html__('EzBizi Page Title', 'ezbizi'));

            return;
        }

        echo ezbizi_post_title_shortcode(array());
    }
}
