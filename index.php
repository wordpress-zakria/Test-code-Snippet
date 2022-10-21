<?php

/**
 * Plugin Name:       Reverse Proxy
 * Plugin URI:        https://precisesol.com
 * Description:       Reverse Proxy Solution for Multiple domains to WordPress
 * Version:           1.0.0
 * Author:            Zakria Sami
 * Author URI:        https://precisesol.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */
class ed_reverse_proxy
{

    //Plugin vars
    public $basename = "";
    public $plugin_name = "";
    public $plugin_display = "";
    public $plugin_folder = "";
    public $plugin_url = "";


    //Functional vars
    public static $is_reverse_proxy_request = false;
    private $origin_url = null;
    private $wp_rp_home_url = null;
    private $wp_rp_site_url = null;
    private $multisite_home_url_const = null;
    private $multisite_site_url_const = null;
    private $blog_id = null;

    //Config vars
    private $update_home_url = true;
    private $update_site_url = true;
    private $update_global_var = true;
    private $debug = false;

    public function __construct()
    {

        //Plugin vars
        $this->basenamed = plugin_basename(__FILE__);
        $this->plugin_name = 'ed-reverse-proxy'; // Plugin Folder
        $this->plugin_display = 'Reverse Proxy'; // Plugin Name
        $this->plugin_folder = plugin_dir_path(__FILE__);
        $this->plugin_url = plugin_dir_url(__FILE__);
        $this->origin_url = site_url();

        //import call
        add_action('wp_ajax_nopriv_rp_domain_addition_deletion', array($this, 'rp_domain_addition_deletion'));
        add_action('wp_ajax_rp_domain_addition_deletion', array($this, 'rp_domain_addition_deletion'));

        add_action('admin_menu', array($this, 'admin_panel_reverse_proxy_menu'));
        add_filter('plugin_action_links_' . $this->basenamed, array($this, 'ed_plugin_action_links'));

        add_action('admin_enqueue_scripts', array($this, 'admin_style'));
        add_action('init', array($this, 'reverse_proxy_siteurl'), -1);
        add_action('admin_init', array());

        //hooks for Web Crawlers
        add_action('admin_menu', array($this, 'admin_menu_page_for_web_crawlers'));
        add_action('admin_init', array($this, 'register_settings_for_web_crawlers'));

        add_action('wp_head', array($this, 'web_crawling_meta'), -1);
        add_action('wp_footer', array($this, 'url_debug'));
        add_filter('option_siteurl', array($this, 'multisite_site_url_option_override'));
        add_filter('option_home', array($this, 'multisite_home_url_option_override'));





        add_shortcode('rp_link',array($this,'generate_rp_url'));

        add_filter('wp_redirect',array($this,'fix_redirect_urls'));


    }






    /**
     * Entity Addition
     */
    public function reverse_proxy_siteurl()
    {
        if ((isset($_SERVER['HTTP_DISGUISED_HOST']) && $_SERVER['HTTP_DISGUISED_HOST'] != "") &&
            (isset($_SERVER['HTTP_X_ORIGINAL_URL']) && $_SERVER['HTTP_X_ORIGINAL_URL'] != "")
        ) {

            // die;
            $proxy_domains = get_option("proxy_domains");
            if (!empty($proxy_domains)) {
                foreach ($proxy_domains as $key => $proxy) {
                    $ips = explode(",", $proxy_domains[$key]['ip']);
                    $domain = $proxy_domains[$key]['domain'];
                    $path = $proxy_domains[$key]['path'];

                    if ((substr($path, -1) !== '/')) {
                        $path = $path . '/';
                    }

                    if (in_array($_SERVER['REMOTE_HOST'], $ips) || in_array($_SERVER['REMOTE_ADDR'], $ips) || true ) {



                        $_SERVER['REQUEST_URI'] = str_replace('//', '/', $_SERVER['REQUEST_URI']);

                        $request_uri = $_SERVER['REQUEST_URI'];

                        if (substr($request_uri, -1) !== '/') {
                            $request_uri = $request_uri . "/";
                        }

                        $url = parse_url($_SERVER['REQUEST_URI']);
                        if (isset($url['query'])) {
                            $request_uri = rtrim($_SERVER['REQUEST_URI'], '/');
                        }

                        $_SERVER['REQUEST_URI'] = str_replace($path, '/', $request_uri);

                        $this->update_wp_vars($domain);
                        $this->update_global_vars($domain);

                        break;
                    }
                }
            }
        }
    }

    public function update_global_vars($domain)
    {
        if ($this->update_global_var) {
            $_SERVER['HTTP_HOST'] = $this->remove_http($this->remove_forward_slash($domain));
            $_SERVER['SERVER_NAME'] = $this->remove_http($this->remove_forward_slash($domain));
        }
    }

    public function update_wp_vars($domain)
    {
        global $blog_id;
        self::$is_reverse_proxy_request = true;
        $this->blog_id = $blog_id;

        if (!is_multisite()) {

            if ($this->update_home_url) {
                $this->wp_rp_home_url = $domain;
                define('WP_HOME', $this->wp_rp_home_url);
            }

            if ($this->update_site_url) {
                $this->wp_rp_site_url = $domain;
                define('WP_SITEURL', $this->wp_rp_site_url);
            }

        } else {

            $key = ($blog_id != 1) ? $blog_id . '_' : '';

            if ($this->update_home_url) {
                $this->wp_rp_home_url = $domain;
                $this->multisite_home_url_const = 'WP_' . $key . 'HOME';
                define($this->multisite_home_url_const, $this->wp_rp_home_url);
            }

            if ($this->update_site_url) {
                $this->wp_rp_site_url = $domain;
                $this->multisite_site_url_const = 'WP_' . $key . 'SITEURL';
                define($this->multisite_site_url_const, $this->wp_rp_site_url);
            }
        }
    }

    function multisite_site_url_option_override($url = '')
    {
        if (is_multisite() && $this->update_site_url == true && $this->multisite_site_url_const != null
            && defined($this->multisite_site_url_const)
        ) {
            if (constant($this->multisite_site_url_const) == $this->wp_rp_site_url) {
                return untrailingslashit($this->wp_rp_site_url);
            }
        }

        return $url;
    }

    function multisite_home_url_option_override($url = '')
    {

        if (is_multisite() && $this->update_home_url == true && $this->multisite_home_url_const != null
            && defined($this->multisite_home_url_const)
        ) {
            if (constant($this->multisite_home_url_const) == $this->wp_rp_home_url) {
                return untrailingslashit($this->wp_rp_home_url);
            }
        }

        return $url;
    }



    function generate_rp_url($atts){

        $atts = shortcode_atts( array(
            'link' => '',
        ), $atts );

        if(self::$is_reverse_proxy_request){
            return home_url($atts['link']);
        }

        return $atts['link'];
    }

    function  fix_redirect_urls ($url){

        return $url;
    }


    /**
     * Entity Addition
     */
    public function rp_domain_addition_deletion()
    {
        global $wpdb;
        // add fields data to database
        if (isset($_REQUEST['domain_name'])) {
            $domain_ip = trim($_REQUEST['domain_ip']);
            $domain_name = esc_url_raw($_REQUEST['domain_name']);
            $proxy_domains = get_option("proxy_domains");

            if (!is_array($proxy_domains)) {
                $proxy_domains = [];
            }


            $path = parse_url($domain_name, PHP_URL_PATH);
            $just_domain = $this->remove_http($domain_name);
            $proxy_domains[$just_domain]['domain'] = $domain_name;
            $proxy_domains[$just_domain]['path'] = $path;
            $proxy_domains[$just_domain]['ip'] = $domain_ip;

            update_option("proxy_domains", $proxy_domains);

            wp_send_json_success();
            wp_die();
        } else if (isset($_REQUEST['domain_delete'])) {
            $domain_delete = $_REQUEST['domain_delete'];
            $proxy_domains = get_option("proxy_domains");
            unset($proxy_domains[$domain_delete]);
            update_option("proxy_domains", $proxy_domains);

            wp_send_json_success();
            wp_die();
        }
        echo wp_send_json_error("Error");
        wp_die();
    }


    public function remove_http($url)
    {
        $disallowed = array('http://', 'https://');
        foreach ($disallowed as $d) {
            if (strpos($url, $d) === 0) {
                return str_replace($d, '', $url);
            }
        }

    }

    public function remove_forward_slash($url)
    {
        if ((substr($url, -1) === '/')) {
            return substr($url, 0, -1);
        }
        return $url;
    }


    // Update CSS within in Admin
    public function admin_style()
    {
        wp_enqueue_style('sdl-styles', $this->plugin_url . 'assets/css/rp-admin-style.css');
        wp_enqueue_script('sdl-scripts', $this->plugin_url . 'assets/js/rp-admin-script.js', null, null, true);
    }

    /**
     * Add settings link directing user to privacy page on plug-in page
     *
     * @param array $links Array of links for plugin actions
     *
     * @return array
     */
    public function ed_plugin_action_links($links)
    {
        $settings_link = '<a href="options-general.php?page=' . $this->plugin_name . '">' . __('Settings', $this->plugin_name) . '</a>';
        array_push($links, $settings_link);
        return $links;
    }


    /**
     * Register the plugin settings panel
     */
    public function admin_panel_reverse_proxy_menu()
    {
        add_submenu_page('options-general.php', __('Reverse Proxy', $this->plugin_name), __('Reverse Proxy', $this->plugin_name), 'manage_options', $this->plugin_name, array(&$this, 'ed_admin_panel'));

    }

    /**
     * Output the Administration Panel
     * Save data from panel to WordPress option
     */
    public function ed_admin_panel()
    {
        // only admin user can access this page
        if (!current_user_can('administrator')) {
            echo '<p>' . __('Sorry, you are not allowed to access this page.', $this->plugin_name) . '</p>';
            return;
        }

        // Load Settings Form
        include_once(WP_PLUGIN_DIR . '/' . $this->plugin_name . '/views/reverse_proxy.php');
    }

    public function admin_menu_page_for_web_crawlers()
    {
        add_options_page(
            'Web Crawlers Settings Page',
            'Web Crawlers',
            'manage_options',
            'web-crawlers',
            array($this, 'web_crawlers_admin_menu_page_call_back')
        );
    }

    public function web_crawlers_admin_menu_page_call_back()
    {

        $blog_public = get_option('blog_public');
        ?>
        <div class="wrap">
            <h1>Web Crawlers Settings</h1>
            <?php if (!$blog_public): ?>
                <div class="error notice">
                    <p>
                        <?php _e('Please enable your Search Engine Visibility from reading settings for these options to work.',
                            $this->plugin_name); ?>
                    </p>
                    <a href="<?php echo admin_url('/options-reading.php#blog_public'); ?>">
                        <strong>
                            <?php
                            _e(
                                'Update Settings',
                                $this->plugin_name
                            ); ?>
                        </strong>
                    </a>
                    <p></p>
                </div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php
                settings_fields('web_crawlers_fields');
                do_settings_sections('web-crawlers');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function register_settings_for_web_crawlers()
    {

        add_settings_section(
            'web_crawlers_settings_section_id',
            '',
            '',
            'web-crawlers'
        );


        //Disable Crawling for default domains
        register_setting(
            'web_crawlers_fields',
            'disable_web_crawling_for_default_domains',
            ''
        );

        add_settings_field(
            'disable_web_crawling_for_default_domains',
            'Disable Crawling For Default Domains',
            array($this, 'disable_web_crawling_for_default_domains_html'),
            'web-crawlers',
            'web_crawlers_settings_section_id',
            array(
                'label_for' => 'disable_web_crawling_for_default_domains',
            )
        );

        //disable Crawling for default domains
        register_setting(
            'web_crawlers_fields',
            'disable_web_crawling_for_rp_domains',
            ''
        );

        add_settings_field(
            'disable_web_crawling_for_rp_domains',
            'Disable Crawling For All Reverse Proxy Domain',
            array($this, 'disable_web_crawling_for_rp_domains_html'),
            'web-crawlers',
            'web_crawlers_settings_section_id',
            array(
                'label_for' => 'disable_web_crawling_for_rp_domains',
            )
        );


    }


    function disable_web_crawling_for_default_domains_html()
    {

        $disable_web_crawling_for_default_domains_html = get_option('disable_web_crawling_for_default_domains');
        ?>
        <input
                class="checkbox" id="disable_web_crawling_for_default_domains"
                name="disable_web_crawling_for_default_domains"
                type="checkbox"
                value="1"
            <?php @checked(1, $disable_web_crawling_for_default_domains_html); ?>/>
        <?php
    }

    function disable_web_crawling_for_rp_domains_html()
    {

        $disable_web_crawling_for_rp_domains = get_option('disable_web_crawling_for_rp_domains');
        ?>
        <input
                class="checkbox" id="disable_web_crawling_for_rp_domains"
                name="disable_web_crawling_for_rp_domains"
                type="checkbox"
                value="1"
            <?php @checked(1, $disable_web_crawling_for_rp_domains); ?>/>
        <?php
    }

    function web_crawling_meta()
    {
        if (get_option('blog_public')) {

            if (get_option('disable_web_crawling_for_default_domains') == 1 && !self::$is_reverse_proxy_request) {
                $this->wp_no_robots();
            }
            if (get_option('disable_web_crawling_for_rp_domains') == 1 && self::$is_reverse_proxy_request) {
                $this->wp_no_robots();
            }
        }
    }


    function wp_no_robots()
    {
        echo "<meta name='robots' content='noindex,follow' />\n";
        return;
    }


}

new ed_reverse_proxy();
?>