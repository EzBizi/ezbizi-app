<?php
/**
 * Just enough Elementor for the two widget classes to be real.
 *
 * In its own file because namespaced declarations need brace syntax, and the
 * harness is one flat script. Nothing here pretends to be Elementor's
 * behaviour: it exists so the classes can be declared, registered, and asked
 * what they are called and what they draw.
 */

namespace Elementor {

    class Widget_Base
    {
        /** What register_controls() built, so a test can read the notice back. */
        public $ezbizi_controls = [];

        public $ezbizi_sections = [];

        public function start_controls_section($id, $args = [])
        {
            $this->ezbizi_sections[$id] = $args;
        }

        public function add_control($id, $args = [])
        {
            $this->ezbizi_controls[$id] = $args;
        }

        public function end_controls_section() {}

        /*
         * `render()` and `register_controls()` are protected, as they are in
         * Elementor. A parent may call a child's protected method, so the two
         * doors below live here rather than as test-only public methods bolted
         * onto the production classes.
         */
        public function ezbizi_call_render()
        {
            $this->render();
        }

        public function ezbizi_call_register_controls()
        {
            $this->register_controls();
        }

        public function get_keywords()
        {
            return [];
        }
    }

    class Controls_Manager
    {
        const RAW_HTML = 'raw_html';
    }

    /** `Plugin::$instance->editor->is_edit_mode()` and nothing else. */
    class Plugin
    {
        public static $instance;

        public $editor;
    }

    class Editor
    {
        public $edit_mode = false;

        public function is_edit_mode()
        {
            return $this->edit_mode;
        }
    }
}

namespace {

    /** Put Elementor on the site, in or out of the editor. */
    function elementor_present($editing = false)
    {
        $plugin = new \Elementor\Plugin;
        $plugin->editor = new \Elementor\Editor;
        $plugin->editor->edit_mode = $editing;
        \Elementor\Plugin::$instance = $plugin;
    }

    /** Elementor's own registry, reduced to the one call the plugin makes. */
    class Elementor_Widgets_Manager
    {
        public $registered = [];

        public function register($widget)
        {
            $this->registered[$widget->get_name()] = $widget;
        }
    }
}
