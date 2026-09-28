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
class EzBizi_Elementor_Form extends EzBizi_Elementor_Preset_Widget {
    public function get_name() {
        return 'ezbizi-form';
    }

    public function get_title() {
        return esc_html__('EzBizi Contact Form', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-form-horizontal';
    }

    /**
     * A preset and "This widget only" style its look (Jose, 2026-09-25:
     * "company wide preset but allowing per widget override"); its questions
     * and words stay the form's own, set in EzBizi.
     */
    protected function ezbizi_draw($atts) {
        // The shortcode escapes everything it prints; see enquiries.php.
        return ezbizi_form_shortcode($atts);
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
    /** The shortcode this widget is a face for, given `preset` and any overrides. */
    abstract protected function ezbizi_draw($atts);

    /** The preset family: `ezbizi-social-links` draws `social_links`. */
    protected function ezbizi_widget() {
        return str_replace('-', '_', substr($this->get_name(), strlen('ezbizi-')));
    }

    /**
     * "This widget only": one optional control per preset option, from the
     * rules EzBizi sends. Empty means "use the preset", so the section can be
     * ignored entirely — which is the normal case.
     */
    protected function ezbizi_override_controls() {
        $payload = ezbizi_payload();
        $rules = isset($payload['widgets']['schema'][$this->ezbizi_widget()])
            ? (array) $payload['widgets']['schema'][$this->ezbizi_widget()]
            : array();

        if ($rules === array()) {
            return;
        }

        $this->start_controls_section('ezbizi_override_section', array(
            'label' => esc_html__('This widget only', 'ezbizi'),
        ));

        $this->add_control('ezbizi_override_note', array(
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw' => esc_html__('Leave these empty to use the preset. Anything set here changes this widget only.', 'ezbizi'),
            'content_classes' => 'elementor-descriptor',
        ));

        foreach ($rules as $key => $rule) {
            $label = ucfirst(str_replace('_', ' ', $key));
            $id = 'ezo_' . $key;
            $type = isset($rule['type']) ? $rule['type'] : '';

            if ($type === 'bool') {
                $this->add_control($id, array('label' => $label, 'type' => \Elementor\Controls_Manager::SELECT, 'default' => '',
                    'options' => array('' => esc_html__('From the preset', 'ezbizi'), 'yes' => esc_html__('On', 'ezbizi'), 'no' => esc_html__('Off', 'ezbizi'))));
            } elseif ($type === 'choice') {
                $this->add_control($id, array('label' => $label, 'type' => \Elementor\Controls_Manager::SELECT, 'default' => '',
                    'options' => array('' => esc_html__('From the preset', 'ezbizi')) + array_combine((array) $rule['choices'], array_map('ucfirst', (array) $rule['choices']))));
            } elseif ($type === 'range') {
                $this->add_control($id, array('label' => $label, 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => '',
                    'min' => (int) $rule['min'], 'max' => (int) $rule['max'], 'placeholder' => esc_html__('Preset', 'ezbizi')));
            } elseif ($type === 'text') {
                $this->add_control($id, array('label' => $label, 'type' => \Elementor\Controls_Manager::TEXT, 'default' => '',
                    'placeholder' => esc_html__('From the preset', 'ezbizi')));
            }
        }

        $this->end_controls_section();
    }

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

        $this->ezbizi_override_controls();
    }

    /** The preset name and every override somebody set, as shortcode attributes. */
    protected function ezbizi_atts($settings) {
        $atts = array(
            'preset' => isset($settings['ezbizi_preset']) && is_string($settings['ezbizi_preset']) ? $settings['ezbizi_preset'] : '',
        );

        foreach ((array) $settings as $key => $value) {
            if (strpos((string) $key, 'ezo_') === 0 && is_scalar($value) && trim((string) $value) !== '') {
                $atts[substr($key, 4)] = (string) $value;
            }
        }

        return $atts;
    }

    protected function render() {
        if (ezbizi_in_elementor_editor()) {
            echo ezbizi_elementor_placeholder($this->get_title());

            return;
        }

        // The shortcodes escape everything they print; see widgets.php.
        echo $this->ezbizi_draw($this->ezbizi_atts($this->get_settings_for_display()));
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

    protected function ezbizi_draw($atts) {
        return ezbizi_social_links_shortcode($atts);
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

    protected function ezbizi_draw($atts) {
        return ezbizi_google_map_shortcode($atts);
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

    protected function ezbizi_draw($atts) {
        return ezbizi_hours_table_shortcode($atts);
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

    protected function ezbizi_draw($atts) {
        return ezbizi_city_list_shortcode($atts);
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
class EzBizi_Elementor_Post_Title extends EzBizi_Elementor_Preset_Widget {
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

    /** The page's own title (or its town), with a preset for the tag and look. */
    protected function ezbizi_draw($atts) {
        return ezbizi_post_title_shortcode($atts);
    }
}

/** Phone, email and address as one block. */
class EzBizi_Elementor_Contact_Info extends EzBizi_Elementor_Preset_Widget {
    public function get_name() {
        return 'ezbizi-contact-info';
    }

    public function get_title() {
        return esc_html__('EzBizi Contact Details', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-bullet-list';
    }

    public function get_keywords() {
        return array_merge(parent::get_keywords(), array('contact', 'phone', 'email', 'address'));
    }

    protected function ezbizi_draw($atts) {
        return ezbizi_contact_info_shortcode($atts);
    }
}

/** A button that calls, books or opens the form. */
class EzBizi_Elementor_Cta extends EzBizi_Elementor_Preset_Widget {
    public function get_name() {
        return 'ezbizi-cta';
    }

    public function get_title() {
        return esc_html__('EzBizi Call to Action', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-button';
    }

    public function get_keywords() {
        return array_merge(parent::get_keywords(), array('cta', 'call', 'button', 'book'));
    }

    protected function ezbizi_draw($atts) {
        return ezbizi_cta_shortcode($atts);
    }
}

/** The phone number on its own. */
class EzBizi_Elementor_Phone_Box extends EzBizi_Elementor_Preset_Widget {
    public function get_name() {
        return 'ezbizi-phone-box';
    }

    public function get_title() {
        return esc_html__('EzBizi Phone', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-headphones';
    }

    public function get_keywords() {
        return array_merge(parent::get_keywords(), array('phone', 'call', 'number'));
    }

    protected function ezbizi_draw($atts) {
        return ezbizi_phone_box_shortcode($atts);
    }
}

/** A happy face to the review link, a sad one to a private form. */
class EzBizi_Elementor_Review_Faces extends EzBizi_Elementor_Preset_Widget {
    public function get_name() {
        return 'ezbizi-review-faces';
    }

    public function get_title() {
        return esc_html__('EzBizi Review Faces', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-rating';
    }

    public function get_keywords() {
        return array_merge(parent::get_keywords(), array('review', 'smiley', 'feedback', 'google', 'rating'));
    }

    protected function ezbizi_draw($atts) {
        return ezbizi_review_faces_shortcode($atts);
    }
}

/** The page's Street View. */
class EzBizi_Elementor_Street_View extends EzBizi_Elementor_Widget {
    public function get_name() {
        return 'ezbizi-street-view';
    }

    public function get_title() {
        return esc_html__('EzBizi Street View', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-google-maps';
    }

    public function get_keywords() {
        return array('ezbizi', 'street view', 'viewpoint', 'map', 'location');
    }

    protected function render() {
        echo ezbizi_street_view_shortcode(array());
    }
}

/** The company's people, from EzBizi. */
class EzBizi_Elementor_Team extends EzBizi_Elementor_Preset_Widget {
    public function get_name() {
        return 'ezbizi-team';
    }

    public function get_title() {
        return esc_html__('EzBizi Team', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-person';
    }

    public function get_keywords() {
        return array_merge(parent::get_keywords(), array('team', 'staff', 'people', 'about'));
    }

    protected function ezbizi_draw($atts) {
        return ezbizi_team_shortcode($atts);
    }
}

/** The branches this website answers for. */
class EzBizi_Elementor_Branches extends EzBizi_Elementor_Preset_Widget {
    public function get_name() {
        return 'ezbizi-branches';
    }

    public function get_title() {
        return esc_html__('EzBizi Branches', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-map-pin';
    }

    public function get_keywords() {
        return array_merge(parent::get_keywords(), array('franchise', 'branches', 'locations', 'offices'));
    }

    protected function ezbizi_draw($atts) {
        return ezbizi_branches_shortcode($atts);
    }
}

/** All of the company's locations, searchable. */
class EzBizi_Elementor_Store_Locator extends EzBizi_Elementor_Preset_Widget {
    public function get_name() {
        return 'ezbizi-store-locator';
    }

    public function get_title() {
        return esc_html__('EzBizi Store Locator', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-google-maps';
    }

    public function get_keywords() {
        return array_merge(parent::get_keywords(), array('store', 'locator', 'locations', 'map', 'search'));
    }

    protected function ezbizi_draw($atts) {
        return ezbizi_store_locator_shortcode($atts);
    }
}

/** The services the company lists in EzBizi. */
class EzBizi_Elementor_Services extends EzBizi_Elementor_Preset_Widget {
    public function get_name() {
        return 'ezbizi-services';
    }

    public function get_title() {
        return esc_html__('EzBizi Services', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-apps';
    }

    public function get_keywords() {
        return array_merge(parent::get_keywords(), array('services', 'offer', 'cards'));
    }

    protected function ezbizi_draw($atts) {
        return ezbizi_services_shortcode($atts);
    }
}

/** Featured or Google reviews. */
class EzBizi_Elementor_Reviews extends EzBizi_Elementor_Preset_Widget {
    public function get_name() {
        return 'ezbizi-reviews';
    }

    public function get_title() {
        return esc_html__('EzBizi Reviews', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-review';
    }

    public function get_keywords() {
        return array_merge(parent::get_keywords(), array('reviews', 'testimonials', 'rating', 'google'));
    }

    protected function ezbizi_draw($atts) {
        return ezbizi_reviews_shortcode($atts);
    }
}

/** Before-and-after pairs, compared. */
class EzBizi_Elementor_Before_After extends EzBizi_Elementor_Preset_Widget {
    public function get_name() {
        return 'ezbizi-before-after';
    }

    public function get_title() {
        return esc_html__('EzBizi Before & After', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-image-before-after';
    }

    public function get_keywords() {
        return array_merge(parent::get_keywords(), array('before', 'after', 'compare', 'projects'));
    }

    protected function ezbizi_draw($atts) {
        return ezbizi_before_after_shortcode($atts);
    }
}

/** Questions and answers, with FAQ markup. */
class EzBizi_Elementor_Faq extends EzBizi_Elementor_Preset_Widget {
    public function get_name() {
        return 'ezbizi-faq';
    }

    public function get_title() {
        return esc_html__('EzBizi FAQ', 'ezbizi');
    }

    public function get_icon() {
        return 'eicon-accordion';
    }

    public function get_keywords() {
        return array_merge(parent::get_keywords(), array('faq', 'questions', 'answers', 'accordion'));
    }

    protected function ezbizi_draw($atts) {
        return ezbizi_faq_shortcode($atts);
    }
}
