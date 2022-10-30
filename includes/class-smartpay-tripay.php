<?php
/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       http://example.com
 * @since      0.0.1
 *
 * @package    Smartpay_Tripay
 * @subpackage Smartpay_Tripay/includes
 */

use SmartPayTripay\Integrations\TripayPaymentGateway;

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      0.0.1
 * @package    Smartpay_Tripay
 * @subpackage Smartpay_Tripay/includes
 * @author     adhitya sukma <sukmaadhitya@gmail.com>
 */
class Smartpay_Tripay {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    0.0.1
	 * @access   protected
	 * @var      Smartpay_Tripay_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    0.0.1
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    0.0.1
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;
    /**
     * Instance of self
     *
     * @var Smartpay_Tripay
     */
    private static $instance = null;
	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    0.0.1
	 */
	public function __construct() {
        require_once SMARTPAY_TRIPAY_DIR_PATH . '/vendor/autoload.php';
		if ( defined( 'SMARTPAY_TRIPAY_VERSION' ) ) {
			$this->version = SMARTPAY_TRIPAY_VERSION;
		} else {
			$this->version = '0.0.1';
		}
		$this->plugin_name = 'smartpay-tripay';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_core_hooks();
		$this->define_admin_hooks();
		$this->define_public_hooks();

	}
    /**
     * Initializes the Toota_Vendor() class
     *
     * Checks for an existing Toota_Vendor() instance
     * and if it doesn't find one, creates it.
     */
    public static function initialize()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }
	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Smartpay_Tripay_Loader. Orchestrates the hooks of the plugin.
	 * - Smartpay_Tripay_i18n. Defines internationalization functionality.
	 * - Smartpay_Tripay_Admin. Defines all hooks for the admin area.
	 * - Smartpay_Tripay_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    0.0.1
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once SMARTPAY_TRIPAY_INC_DIR . '/class-smartpay-tripay-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once SMARTPAY_TRIPAY_INC_DIR . '/class-smartpay-tripay-i18n.php';

		require_once SMARTPAY_TRIPAY_INC_DIR . '/smartpay-tripay-core-functions.php';
//		require_once SMARTPAY_TRIPAY_INC_DIR . '/Integration/Tripay.php';
//		require_once SMARTPAY_TRIPAY_INC_DIR . '/Integration/TripayPaymentGateway.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once SMARTPAY_TRIPAY_ADMIN_DIR . '/class-smartpay-tripay-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once SMARTPAY_TRIPAY_PUBLIC_DIR . '/class-smartpay-tripay-public.php';

		$this->loader = new Smartpay_Tripay_Loader();
//        $foobar = new SmartPayTripay\Integrations\Tripay();  // correct
//        $foobar->boot();
//        TripayPaymentGateway::instance();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Smartpay_Tripay_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    0.0.1
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Smartpay_Tripay_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	private function define_core_hooks(){
	    add_action("init",array($this,"init_classes"));
    }
    public function init_classes(){

        $foobar = new SmartPayTripay\Integrations\Tripay();  // correct
        $foobar::instance();
        TripayPaymentGateway::instance();
    }
	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    0.0.1
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Smartpay_Tripay_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    0.0.1
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Smartpay_Tripay_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    0.0.1
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     0.0.1
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     0.0.1
	 * @return    Smartpay_Tripay_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     0.0.1
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
