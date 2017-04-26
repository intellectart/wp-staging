<?php
namespace WPStaging;

// No Direct Access
if (!defined("WPINC"))
{
    die;
}

// Ensure to include autoloader class
require_once __DIR__ . DIRECTORY_SEPARATOR . "Utils" . DIRECTORY_SEPARATOR . "Autoloader.php";

use WPStaging\Backend\Administrator;
use WPStaging\DTO\Settings;
use WPStaging\Frontend\Frontend;
use WPStaging\Utils\Autoloader;
use WPStaging\Utils\Cache;
use WPStaging\Utils\Loader;
use WPStaging\Utils\Logger;
use WPStaging\DI\InjectionAware;

/**
 * Class WPStaging
 * @package WPStaging
 */
final class WPStaging
{

    /**
     * Plugin version
     */
    const VERSION   = "2.0.0";

    /**
     * Plugin name
     */
    const NAME      = "WP Staging";

    /**
     * Plugin slug
     */
    const SLUG      = "wp-staging";

    /**
     * Compatible WP Version
     */
    const WP_COMPATIBLE = "4.7.3";
    

    /**
     * Services
     * @var array
     */
    private $services;

    /**
     * Singleton instance
     * @var WPStaging
     */
    private static $instance;

    /**
     * WPStaging constructor.
     */
    private function __construct()
    {
        $file = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . self::SLUG . DIRECTORY_SEPARATOR . self::SLUG . ".php";

        // Activation Hook
        register_activation_hook($file, array($this, "onActivation"));

        $this->registerNamespaces();
        $this->loadLanguages();
        $this->loadDependencies();
        $this->defineHooks();
        
        // URL to apps folder
        $this->url  = plugin_dir_url(  dirname(__FILE__) );
        
        // URL to backend public folder folder
        $this->backend_url  = plugin_dir_url(  dirname(__FILE__) ) . "Backend/public/";
        
        // URL to frontend public folder folder
        $this->frontend_url  = plugin_dir_url(  dirname(__FILE__) ) . "Frontend/public/";
    }
    
    /**
     * Define Hooks
     */
    public function defineHooks()
    {
        $loader = $this->get("loader");
        $loader->addAction("admin_enqueue_scripts", $this, "enqueueElements", 100);
        $loader->addAction("wp_enqueue_scripts", $this, "enqueueElements", 100);
    }
    
        /**
     * Scripts and Styles
     * @param string $hook
     */
    public function enqueueElements($hook)
    {
                
        // Load this css file on frontend and backend on all pages if current site is a staging site
        if( $this->isStagingSite() ) {
            wp_enqueue_style( "wpstg-admin-bar", $this->backend_url . "css/wpstg-admin-bar.css", $this->getVersion() );
        }
        
        $availablePages = array(
            "toplevel_page_wpstg_clone",
            "wp-staging_page_wpstg-settings",
            "wp-staging_page_wpstg-tools"
        );

        // Load these css and js files only on wp staging admin pages
        if (!in_array($hook, $availablePages) || !is_admin())
        {
            return;
        }

        // Load admin js files
        wp_enqueue_script(
            "wpstg-admin-script",
            $this->backend_url . "js/wpstg-admin.js",
            array("jquery"),
            $this->getVersion(),
            false
        );
        
        // Load admin css files
        wp_enqueue_style(
            "wpstg-admin",
            $this->backend_url . "css/wpstg-admin.css",
            $this->getVersion()
        );

        wp_localize_script("wpstg-admin-script", "wpstg", array(
            "nonce"                                 => wp_create_nonce("wpstg_ajax_nonce"),
            "mu_plugin_confirmation"                => __(
                "If confirmed we will install an additional WordPress 'Must Use' plugin. "
                . "This plugin will allow us to control which plugins are loaded during "
                . "WP Staging specific operations. Do you wish to continue?",
                "wpstg"
            ),
            "plugin_compatibility_settings_problem" => __(
                "A problem occurred when trying to change the plugin compatibility setting.",
                "wpstg"
            ),
            "saved"                                 => __("Saved", "The settings were saved successfully", "wpstg"),
            "status"                                => __("Status", "Current request status", "wpstg"),
            "response"                              => __("Response", "The message the server responded with", "wpstg"),
            "blacklist_problem"                     => __(
                "A problem occurred when trying to add plugins to backlist.",
                "wpstg"
            ),
            "cpuLoad"                               => $this->getCPULoadSetting(),
            "settings"                              => (object) array() // TODO add settings?
        ));
    }

    /**
     * Method to be executed upon activation of the plugin
     */
    public function onActivation()
    {

    }
    /**
     * Caching and logging folder
     * 
     * @return string
     */
    public static function getContentDir(){
	$wp_upload_dir = wp_upload_dir();
        $path = $wp_upload_dir['basedir'] . '/wp-staging';
	wp_mkdir_p( $path );
	return apply_filters( 'wpstg_get_upload_dir', $path . DIRECTORY_SEPARATOR );        
    }
    

    /**
     * Register used namespaces
     */
    private function registerNamespaces()
    {
        $autoloader = new Autoloader();
        $this->set("autoloader", $autoloader);

        // Base directory
        $dir = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . self::SLUG . DIRECTORY_SEPARATOR . "apps" . DIRECTORY_SEPARATOR;

        // Autoloader
        $autoloader->registerNamespaces(array(
            "WPStaging" => array(
                $dir,
                $dir . "Core" . DIRECTORY_SEPARATOR
            )
        ));

        // Register namespaces
        $autoloader->register();
    }

    /**
     * Get Instance
     * @return WPStaging
     */
    public static function getInstance()
    {
        if (null === static::$instance)
        {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * Prevent cloning
     * @return void
     */
    private function __clone()
    {}

    /**
     * Prevent unserialization
     * @return void
     */
    private function __wakeup()
    {}

    /**
     * Load Dependencies
     */
    private function loadDependencies()
    {
        // Set loader
        $this->set("loader", new Loader());

        // Set cache
        $this->set("cache", new Cache());

        // Set logger
        $this->set("logger", new Logger());

        // Set settings
        $this->set("settings", new Settings());

        // Set Administrator
        if (is_admin())
        {
            new Administrator($this);
        }
        else
        {
            new Frontend($this);
        }
    }

    /**
     * Execute Plugin
     */
    public function run()
    {
        $this->get("loader")->run();
    }

    /**
     * Set a variable to DI with given name
     * @param string $name
     * @param mixed $variable
     * @return $this
     */
    public function set($name, $variable)
    {
        // It is a function
        if (is_callable($variable)) $variable = $variable();

        // Add it to services
        $this->services[$name] = $variable;

        return $this;
    }

    /**
     * Get given name index from DI
     * @param string $name
     * @return mixed|null
     */
    public function get($name)
    {
        return (isset($this->services[$name])) ? $this->services[$name] : null;
    }

    /**
     * @return string
     */
    public function getVersion()
    {
        return self::VERSION;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * @return string
     */
    public function getSlug()
    {
        return self::SLUG;
    }
    
    /**
     * Get path to main plugin file
     * @return string
     */
    public function getPath(){
        return dirname(dirname(__FILE__));
    }
    /**
     * Get main plugin url
     * @return type
     */
    public function getUrl(){
        return plugin_dir_url(dirname(__FILE__));
    }

    /**
     * @return array|mixed|object
     */
    public function getCPULoadSetting()
    {
        $options = $this->get("settings");
        $setting = $options->getCpuLoad();

        switch ($setting)
        {
            case "high":
                $cpuLoad = 0;
                break;

            case "medium":
                $cpuLoad = 1000;
                break;

            case "low":
                $cpuLoad = 3000;
                break;

            case "default":
            default:
                $cpuLoad = 1000;
        }

        return $cpuLoad;
    }

    /**
     * Load language file
     */
    public function loadLanguages()
    {
        $languagesDirectory = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . self::SLUG . DIRECTORY_SEPARATOR;
        $languagesDirectory.= "vars" . DIRECTORY_SEPARATOR . "languages" . DIRECTORY_SEPARATOR;

        // Set filter for plugins languages directory
        $languagesDirectory = apply_filters("wpstg_languages_directory", $languagesDirectory);

        // Traditional WP plugin locale filter
        $locale             = apply_filters("plugin_locale", get_locale(), "wpstg");
        $moFile             = sprintf('%1$s-%2$s.mo', "wpstg", $locale);

        // Setup paths to current locale file
        $moFileLocal        = $languagesDirectory . $moFile;
        $moFileGlobal       = WP_LANG_DIR . DIRECTORY_SEPARATOR . "wpstg" . DIRECTORY_SEPARATOR . $moFile;

        // Global file (/wp-content/languages/WPSTG)
        if (file_exists($moFileGlobal))
        {
            load_textdomain("wpstg", $moFileGlobal);
        }
        // Local file (/wp-content/plugins/wp-staging/languages/)
        elseif (file_exists($moFileLocal))
        {
            load_textdomain("wpstg", $moFileGlobal);
        }
        // Default file
        else
        {
            load_plugin_textdomain("wpstg", false, $languagesDirectory);
        }
    }
    
    /**
     * Check if it is a staging site
     * @return bool
     */
    private function isStagingSite()
    {
        return ("true" === get_option("wpstg_is_staging_site"));
    }
   
}