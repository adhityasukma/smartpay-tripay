<?php
namespace SmartPayTripay\Integrations;
class Tripay
{
    /**
     * The single instance of this class
     */
    private static $instance = null;
	public static function config(): array
	{
		return [
			'name'       => 'tripay',
			'excerpt'    => 'Quickest & easiest Indonesian online payment solution.',
			'cover'      =>  SMARTPAY_TRIPAY_PLUGIN_ASSEST.'/img/tripay-logo-dark.webp',
			'manager'    => __CLASS__,
			'type'       => 'pro',
			'categories' => ['Payment Gateway'],
			'setting_link' => 'tab=gateways&section=tripay', //provide tab & section as shown here
		];
	}

	public function __construct()
	{
		add_filter('smartpay_gateways', [$this, 'registerGateway'], 110);
		add_filter('smartpay_get_available_payment_gateways', [$this, 'register_to_available_gateway_on_setting'], 111);
	}
    /**
     * Main class Instance.
     *
     * Ensures that only one instance of class exists in memory at any one
     * time. Also prevents needing to define globals all over the place.
     *
     * @return object
     * @access public
     * @since  0.0.1
     */
    public static function instance() {
        if ( ! isset( self::$instance ) && ! ( self::$instance instanceof TripayPaymentGateway ) ) {
            self::$instance = new self();
        }

        return self::$instance;
    }
	public function registerGateway($gateways): array
	{
		return array_merge($gateways, [
			'tripay' => [
				'admin_label'       => 'Tripay',
				'checkout_label'    => 'Tripay',
				'gateway_icon'      => SMARTPAY_TRIPAY_PLUGIN_ASSEST.'/img/tripay-logo-dark.webp',
			],
		]);
	}

	/**
	 * show's on selected gateway list
	 * @param $availableGateways
	 * @return array
	 */
	public function register_to_available_gateway_on_setting($availableGateways): array
	{
		return array_merge($availableGateways, [
			'tripay' => [
				'label' => 'Tripay'
			]
		]);
	}
}
