<?php

namespace SmartPayTripay\Integrations;
date_default_timezone_set(smartpay_tripay_wp_timezone_string());

use mysql_xdevapi\Exception;
use SmartPay\Models\Form;
use SmartPay\Models\Payment;
use SmartPay\Models\Product;
use SmartPayPro\Models\Subscription;
use SmartPay\Models\Customer;
use Carbon\Carbon;

/**
 * ToyyibPay PaymentGateway class
 * This class is responsible for toyyibPay payment processing
 */
class TripayPaymentGateway
{

    public static $baseurl = 'https://tripay.co.id';
    /**
     * The single instance of this class
     */
    private static $instance = null;
    // add the supported currency list for toyyibPay
    private static $supported_currency = [
        'IDR', 'USD'
    ];

    /**
     * Construct ToyyibPay class.
     * @since  2.6.7
     * @access private
     */
    public function __construct()
    {
        // make sure gateway is active
        if (!smartpay_is_gateway_active('tripay')) {
            return;
        }

        // check the api key first
        $this->_checkApiKeys();

        // make sure the currency is supported
        if (!in_array(strtoupper(smartpay_get_currency()), self::$supported_currency)) {
            add_action('admin_notices', [$this, 'unsupported_currency_notice']);
            return;
        }

        // Initialize actions.
        $this->init_actions();
    }

    /**
     * check the api credentials were set or not on settings
     * if not, then show error/warning message to the admin
     * @return void
     * @since 2.6.7
     * @access private
     */
    private function _checkApiKeys(): void
    {
        $tripay_mid = smartpay_is_test_mode() ? smartpay_get_option('tripay_sandbox_merchant_code') : smartpay_get_option('tripay_live_merchant_code');
        $tripay_api_key = smartpay_is_test_mode() ? smartpay_get_option('tripay_sandbox_api_key') : smartpay_get_option('tripay_live_api_key');
        $tripay_private_key = smartpay_is_test_mode() ? smartpay_get_option('tripay_sandbox_secret_key') : smartpay_get_option('tripay_live_secret_key');

        $tripay_channel_code = smartpay_get_option('tripay_channels');

        if (empty($tripay_mid) || empty($tripay_api_key) || empty ($tripay_private_key) || empty($tripay_channel_code)) {
            add_action('admin_notices', function () {
                echo __(sprintf(
                    '<div class="error">
                        <p><strong>Tripay user Merchant Code, API Key, Private Key and/or Channel codes was not set or found yet!</strong> To get the Tripay services on smartpay, you must put credentials 
                        <a href="%s"> Complete your credentials</a> or <a href="%s" target="_blank">Get your key here</a></p>
                    </div>',
                    admin_url('admin.php?page=smartpay-setting&tab=gateways&section=tripay'),
                    smartpay_is_test_mode() ? 'https://tripay.co.id/simulator/merchant' : 'https://tripay.co.id/member/merchant'
                ), 'smartpay-tripay');
            });
        }
    }

    /**
     * Initialize wp actions.
     *
     * @access private
     * @return void
     * @since  2.6.7
     */
    public function init_actions()
    {
        add_action('wp_footer', array($this, 'set_footer'));
//        add_action( 'wp_enqueue_scripts', array($this,'wp_enqueue_scripts'),50);
        // process the callback functions
        add_action('init', array($this, 'add_endpoint'), 0);
        add_filter('query_vars', array($this, 'add_query_vars'), 0);
        add_action('parse_request', array($this, 'handle_api_requests'), 0);
        add_filter("smartpay_tripay_subscription_active_period", array($this, "active_period"));
        add_filter("smartpay_tripay_subscription_cek_renewal_status", array($this, "subscription_cek_renewal_status"));
        add_action('set_status_smartpay_tripay', [$this, 'set_status_smartpay_tripay']);
        //        add_action('init', array($this, 'set_endpoint'), 100);

        // run the return callback function on init
        // because it will not automatically call when return url hits
//        add_action('init', [$this, 'process_return_response_callback']);

        // add the action and named it to exact with your gateway -- just change the gateway label
        // this action for processing the payment data if necessary
        add_action('smartpay_tripay_process_payment', [$this, 'process_payment']);

        // ddd the action and named it to exact with your gateway -- just change the gateway label
        add_action('smartpay_tripay_ajax_process_payment', [$this, 'ajax_process_payment']);
        add_action('smartpay_tripay_subscription_process_payment', [$this, 'subscriptionProcessPayment'], 10, 2);

//        add_action('smartpay-tripay_subscription_process_payment', [$this, 'subscriptionProcessPayment'], 10,3);
        // add this filter to register into gateway section on payment modal/form
        add_filter('smartpay_settings_sections_gateways', [$this, 'gateway_section'], 110);

        // add filter to get necessary configuration for taking payments
        add_filter('smartpay_settings_gateways', [$this, 'gateway_settings'], 110);

        // add filter to get necessary configuration for taking payments
//         add_filter( 'smartpay_payment_extra_data', [ $this, 'add_extra_data' ], 110 );
        add_filter('smartpay_prepare_payment_data', [$this, 'add_extra_data'], 20, 2);

        // add action to show channels on checkout
        add_action('smartpay_before_product_payment_form_button', [$this, 'mobile_input']);
        add_action('smartpay_before_product_payment_form_button', [$this, 'payment_channel_options']);
        add_action('smartpay_after_product_payment_form_button', [$this, 'js_oncheckout']);

//        add_action('smartpay_product_modal_popup_content', [$this, 'js_on_modal']);

        if (defined("SMARTPAY_PRO_VERSION")) {
            add_action('smartpay_customer_dashboard_tab_content', [$this, 'customerDashboardSubscriptionsTabContent'], 11, 2);
        }
        // Payment receipt shortcode
        add_shortcode('smartpay_payment_receipt', [$this, 'payment_receipt_shortcode']);
        // Customer dashboard shortcode
        add_shortcode('smartpay_dashboard', [$this, 'dashboard_shortcode']);
        // add shortcode for Tripay receipt
        add_shortcode('smartpay_tripay_payment_receipt', [$this, 'order_receipt']);
        // AJAX
        add_action('wp_ajax_recheck_payment_status', [$this, 'recheck_payment_status']);
        add_action('wp_ajax_nopriv_recheck_payment_status', [$this, 'recheck_payment_status']);
    }

    /**
     * WC API for payment gateway IPNs, etc.
     *
     * @since 2.0
     */
    public static function add_endpoint()
    {
        add_rewrite_rule('^smartpay-tripay-listener/([^/]*)?', 'index.php?smartpay-tripay-listener=$matches[1]', 'top');
    }

    /**
     * Main class Instance.
     *
     * Ensures that only one instance of class exists in memory at any one
     * time. Also prevents needing to define globals all over the place.
     *
     * @return object
     * @access public
     * @since  2.6.7
     */
    public static function instance()
    {
        if (!isset(self::$instance) && !(self::$instance instanceof TripayPaymentGateway)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function convertToIdr($value, $optionValue = null)
    {
        $currency = smartpay_get_currency();
        $currentCurrency = strtolower($currency);

        if ($currentCurrency == 'idr') {
            return ceil($value);
        }

        $optionValue = $optionValue ? $optionValue : smartpay_get_option('tripay_exchange_rate_value');

        if (empty($optionValue)) {
            echo "TriPay exchange rate has not been set";
            return 0;
        }
        return ceil($value * $optionValue);
    }

    public function wp_enqueue_scripts()
    {
        wp_dequeue_style('generate-child');
    }

    public function set_footer()
    {
        global $wpdb;
        $product_db = $wpdb->prefix . 'smartpay_products';
        $title = get_the_title();
        $get_product_data = $wpdb->get_results("SELECT * FROM $product_db WHERE title = '$title'");
        if ($get_product_data):
            $base_price = $get_product_data[0]->base_price;
            $sale_price = $get_product_data[0]->sale_price;

            $settings = get_option('smartpay_settings');
            $currency = $settings['currency'];
            $exchangeValue = smartpay_get_option('tripay_exchange_rate_value');
            $product_price_smartpay_tripay = smartpay_tripay_convertToIdr($sale_price, $exchangeValue);
            ?>
            <script>
                var currency_smartpay = '<?php echo $currency;?>';
                var pricing_base_smartpay = '<?php echo smartpay_tripay_format_pricing($base_price, $currency, false);?>';
                var pricing_sale_smartpay = '<?php echo smartpay_tripay_format_pricing($sale_price, $currency, false);?>';
                var pricing_sale_smartpay_tripay = '<?php echo smartpay_tripay_format_pricing($product_price_smartpay_tripay, 'IDR');?>';
                jQuery(document).ready(function ($) {
                    $('.sale-price').html('<?php echo $currency . ' ' . number_format($sale_price, 0, ',', '.'); ?>');
                    $('.base-price').html('<?php echo $currency . ' ' . number_format($base_price, 0, ',', '.'); ?>');
                    //$('.open-payment-form').on('click', function () {
                    //    setTimeout(() => {
                    //        // $('.payment-modal').modal({dismissible: false, keyboard: false});
                    //        // $('.payment-modal--title.amount').remove();
                    //        //$('.modal-title').append('<h2 class="payment-modal--title amount m-0"><?php ////echo $currency . ' ' . number_format($sale_price, 0, ',', '.'); ?>////</h2>');
                    //    }, 250);
                    //});

                    $('.payment-modal .modal-close').on('click', function () {
                        $('.smartpay-payment').find(".modal-backdrop").remove();
                    });
                    $('.back-to-first-step').on('click', function () {
                        $(".payment-modal .modal-close").show();
                    });

                    function getUrlParameter(sParam) {
                        var sPageURL = window.location.search.substring(1),
                            sURLVariables = sPageURL.split('&'),
                            sParameterName,
                            i;

                        for (i = 0; i < sURLVariables.length; i++) {
                            sParameterName = sURLVariables[i].split('=');

                            if (sParameterName[0] === sParam) {
                                return typeof sParameterName[1] === undefined ? true : decodeURIComponent(sParameterName[1]);
                            }
                        }
                        return false;
                    }

                    var renewal_order = getUrlParameter('renewal');

                    if (renewal_order) {
                        $('body').addClass("using-mouse");
                        $('.payment-modal').modal({
                            backdrop: 'static',
                            dismissible: false,
                            keyboard: false,
                            show: true
                        });
                        $('.payment-modal').addClass("show");
                        $(".smartpay-payment").append('<div class="modal-backdrop fade show"></div>');
                    }
                });
            </script>
        <?php endif; ?>
        <script>
            function copyElementText(id) {
                var text = document.getElementById(id).innerText;
                var elem = document.createElement("textarea");
                document.body.appendChild(elem);
                elem.value = text;
                elem.select();
                document.execCommand("copy");
                document.body.removeChild(elem);
                alert('Kode Virtual Account ' + text + ' sudah disalin!');
            }

            (function ($) {
                $.fn.inputFilter = function (callback, errMsg) {
                    return this.on("input keydown keyup mousedown mouseup select contextmenu drop focusout", function (e) {
                        if (callback(this.value)) {
                            // Accepted value
                            if (["keydown", "mousedown", "focusout"].indexOf(e.type) >= 0) {
                                $(this).removeClass("input-error");
                                this.setCustomValidity("");
                            }
                            this.oldValue = this.value;
                            this.oldSelectionStart = this.selectionStart;
                            this.oldSelectionEnd = this.selectionEnd;
                        } else if (this.hasOwnProperty("oldValue")) {
                            // Rejected value - restore the previous one
                            $(this).addClass("input-error");
                            this.setCustomValidity(errMsg);
                            this.reportValidity();
                            this.value = this.oldValue;
                            this.setSelectionRange(this.oldSelectionStart, this.oldSelectionEnd);
                        } else {
                            // Rejected value - nothing to restore
                            this.value = "";
                        }
                    });
                };

            }(jQuery));
        </script>
        <?php
    }

    /**
     * Payment receipt shortcode.
     * @param $atts
     *
     * @return false|string|void
     */
    public function payment_receipt_shortcode($atts)
    {
        $payment_uuid = isset($_GET['smartpay-payment']) ? ($_GET['smartpay-payment']) : null;

        if (!$payment_uuid) {
            return;
        }

        // Sometimes payment gateway need more time to complete a payment
        sleep(3);

//        $payment = smartpay_get_payment($payment_id);
        // fetch the payment regrading to payment uuid
        $payment = Payment::where('uuid', $payment_uuid)->first();

        if (!$payment) {
            return;
        }

        try {
            ob_start();
            include_once SMARTPAY_TRIPAY_PUBLIC_DIR . '/partials/payment_receipt.php';
//            echo smartpay_view('shortcodes.payment_receipt', ['payment' => $payment]);

            return ob_get_clean();
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    public function dashboard_shortcode($atts)
    {
        // If not logged in or id not found, then return
        if (!is_user_logged_in() || get_current_user_id() <= 0) {
            echo '<p>You must log in to access the dashboard!</p>';
            return;
        }

        $customer = Customer::with('payments')->where('user_id', get_current_user_id())->orWhere('email', wp_get_current_user()->user_email)->first();

        if (!$customer) {
            echo '<p>We don\'t find any account, please register or contact to admin!</p>';
            return;
        }

        ob_start();
        include_once SMARTPAY_TRIPAY_PUBLIC_DIR . '/partials/customer_dashboard.php';
//        echo smartpay_view('shortcodes.customer_dashboard', ['customer' => $customer]);

        return ob_get_clean();
    }

    public function customerDashboardSubscriptionsTabContent($customer, $payments)
    {
        ?>
        <script>
            jQuery(document).ready(function ($) {
                $("#subscriptions").remove();
            });
        </script>
        <div class="tab-pane fade" id="subscriptions" role="tabpanel">
            <?php if (!count($payments)) : ?>
                <div class="card">
                    <div class="card-body py-5">
                        <p class="text-info  m-0 text-center"><?php _e('You don\'t have any subscriptions yet.', 'smartpay-tripay'); ?></p>
                    </div>
                </div>
            <?php else : ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                        <tr>
                            <th scope="col"><?php _e('ID', 'smartpay-tripay'); ?></th>
                            <th scope="col"><?php _e('Product', 'smartpay-tripay'); ?></th>
                            <th scope="col"><?php _e('Date', 'smartpay-tripay'); ?></th>
                            <th scope="col"><?php _e('Expired', 'smartpay-tripay'); ?></th>
                            <th scope="col"><?php _e('Amount', 'smartpay-tripay'); ?></th>
                            <th scope="col"><?php _e('Status', 'smartpay-tripay'); ?></th>
                            <th scope="col"><?php _e('Action', 'smartpay-tripay'); ?></th>
                        </tr>
                        </thead>
                        <tbody>

                        <?php
                        foreach ($payments as $index => $payment) :
                            $subscription = Subscription::where('parent_payment_id', $payment->id)->first();
                            if ($subscription) :
                                ?>
                                <tr>
                                    <th scope="row"><?php echo '#' . $subscription->id; ?></th>
                                    <td><?php echo esc_html(smartpay_get_payment_product_or_form_name($payment->id)['name']); ?></td>
                                    <td><?php echo mysql2date('F j, Y', $subscription->created_at); ?></td>
                                    <td><?php echo ($subscription->expiration) ? mysql2date('F j, Y', $subscription->expiration) . '<p>( ' . apply_filters("smartpay_tripay_subscription_active_period", $payment->id) . ' )</p>' : '-'; ?></td>
                                    <td class="text-muted">
                                        <strong class="<?php echo 'completed' == $subscription->status ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo smartpay_tripay_format_pricing($subscription->recurring_amount, $payment->currency); ?>
                                        </strong>
                                    </td>
                                    <!--                                    --><?php
                                    //                                    $status_color = 'text-danger';
                                    //                                    if ('Completed' == $subscription->status || 'Active' == $subscription->status) {
                                    //                                        $status_color = 'text-success';
                                    //                                    }
                                    //
                                    ?>
                                    <!--                                    <td class="-->
                                    <?php //echo esc_attr($status_color);
                                    ?><!--">-->
                                    <!--                                        --><?php //echo $subscription->status;
                                    ?>
                                    <!--                                        --><?php
                                    //                                        echo apply_filters("set_status_smartpay_tripay",$subscription);
                                    //
                                    ?>
                                    <!--                                    </td>-->
                                    <?php
                                    echo apply_filters("set_status_smartpay_tripay", $payment->id);
                                    ?>
                                    <td>
                                        <?php
                                            $action_link = add_query_arg(
                                                [
                                                    'subscription_id' => $subscription->id,
                                                    'change_subscription_to' => 'cancelled'
                                                ],
                                                get_permalink()
                                            );

                                            $action_link = wp_nonce_url($action_link, 'subscription-change');
                                            ?>
                                        <?php if ('active' == strtolower($subscription->status) || 'expired' == strtolower($subscription->status)) : ?>
                                            <?php
                                            $subscription_renewal = apply_filters("smartpay_tripay_subscription_cek_renewal_status", $payment->id);
                                            if ($subscription_renewal) {
                                                if (isset($subscription_renewal['renewal']) && $subscription_renewal['renewal']) {
                                                    ?>
                                                    <a href="<?php echo esc_url($action_link); ?>"
                                                       class="btn btn-primary"><?php _e('Cancel', 'smartpay-tripay'); ?></a>
                                                    <a href="<?php echo esc_url($subscription_renewal['link']); ?>"
                                                       target="_blank"
                                                       class="btn btn-primary"><?php _e('Renewal', 'smartpay-tripay'); ?></a>
                                                    <?php
                                                } else {
                                                    ?>
                                                    <a href="<?php echo esc_url($subscription_renewal['link']); ?>"
                                                       target="_blank"
                                                       class="btn btn-primary"><?php _e('New Order', 'smartpay-tripay'); ?></a>
                                                    <?php
                                                }
                                            }
                                            ?>
                                        <?php elseif ('pending' == strtolower($subscription->status)): ?>
                                            <?php
                                            $subscription_renewal = apply_filters("smartpay_tripay_subscription_cek_renewal_status", $payment->id);

                                            if ($subscription_renewal) {
                                                if (isset($subscription_renewal['renewal']) && $subscription_renewal['renewal']) {
                                                    ?>
                                                    <a href="<?php echo esc_url($action_link); ?>"
                                                       class="btn btn-primary"><?php _e('Cancel', 'smartpay-tripay'); ?></a>
                                                    <a href="<?php echo esc_url($subscription_renewal['link']); ?>"
                                                       target="_blank"
                                                       class="btn btn-primary"><?php _e('Renewal', 'smartpay-tripay'); ?></a>
                                                    <?php
                                                } else {
                                                    ?>
                                                    <a href="<?php echo esc_url($action_link); ?>"
                                                       class="btn btn-primary"><?php _e('Cancel', 'smartpay-tripay'); ?></a>
                                                    <a href="<?php echo esc_url($subscription_renewal['link']); ?>"
                                                       target="_blank"
                                                       class="btn btn-primary"><?php _e('New Order', 'smartpay-tripay'); ?></a>
                                                    <?php
                                                }
                                            } else {
                                                ?>
                                                <a href="<?php echo esc_url($action_link); ?>"
                                                   class="btn btn-primary"><?php _e('Cancel', 'smartpay-tripay'); ?></a>
                                                <?php
                                            }
                                            ?>
                                        <?php else: ?>
                                            <?php
                                            $subscription_renewal = apply_filters("smartpay_tripay_subscription_cek_renewal_status", $payment->id);
                                            ?>
                                            <a href="<?php echo esc_url($action_link); ?>"
                                               class="btn btn-primary"><?php _e('Cancel', 'smartpay-tripay'); ?></a>
                                            <?php
                                            if ($subscription_renewal) {
                                                ?>
                                                <a href="<?php echo esc_url($subscription_renewal['link']); ?>"
                                                   target="_blank"
                                                   class="btn btn-primary"><?php _e('New Order', 'smartpay-tripay'); ?></a>
                                                <?php
                                            }
                                            ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php
                            endif;
                        endforeach;
                        ?>
                        </tbody>
                    </table>
                </div>
            <?php endif ?>
        </div>
        <?php
    }

    /**
     * Process webhook requests.
     *
     * @param array $payment_data
     * @return void
     * @access public
     * @since  0.0.1
     */
    public function process_payment($payment_data)
    {
        return;
    }

    public function set_status_smartpay_tripay($payment_id)
    {
        $subscription = smartpay_tripay_get_subscription_by_parent_payment_id($payment_id);
        $status = ucfirst($subscription->status);
        $expired = false;
        if ('Completed' == $status || 'Active' == $status) {
            $status_color = 'text-success';
        } else {
            $status_color = 'text-danger';
        }
        if ($subscription->expiration) {
            if (strtotime($subscription->expiration) > time()) :
                $expired = false;
            else :
                $expired = true;
            endif;
        }
        if ($expired) {
            $subscription = Subscription::where('parent_payment_id', $payment_id)->first();
            if ($subscription->status != "Cancelled") {
                $subscription->updateStatus(Subscription::STATUS_EXPIRED);
            }
            $status_color = 'text-danger';
            ?>
            <td class="<?php echo esc_attr($status_color); ?>">
                <?php echo $subscription->status; ?>
            </td>
            <?php
        } else {
            ?>
            <td class="<?php echo esc_attr($status_color); ?>">
                <?php echo $status; ?>
            </td>
            <?php
        }
    }

    public function subscription_cek_renewal_status($payment_id)
    {
        $subscription = smartpay_tripay_get_subscription_by_parent_payment_id($payment_id);

        $result = array();
        $result['link'] = smartpay_get_payment_product_or_form_name($payment_id)['preview'];
        if ($subscription->expiration) {
            $result['link'] = add_query_arg(array('renewal' => true), smartpay_get_payment_product_or_form_name($payment_id)['preview']);
            if (strtotime($subscription->expiration) > current_time('timestamp')) :
                $result['expired'] = false;
                $result['renewal'] = true;
            else :
                $result['expired'] = true;
                $result['renewal'] = true;
                $result['status'] = 'expired';
                $max_renewal_day = absint(smartpay_get_option('tripay_subscription_renewal_period_expired_value'));
                if (
                    0 < $max_renewal_day &&
                    $max_renewal_day < smartpay_tripay_get_difference_day(strtotime($subscription->expiration))
                ) :
                    // New Order
                    $result['renewal'] = false;
                    $result['link'] = smartpay_get_payment_product_or_form_name($payment_id)['preview'];
                endif;
            endif;
        }
        return $result;
    }

    public function active_period($payment_id)
    {
        $subscription = smartpay_tripay_get_subscription_by_parent_payment_id($payment_id);
        $period = "-";
        if ($subscription->expiration) {
            if (strtotime($subscription->expiration) > time()) :
                $day_left = Carbon::createFromDate($subscription->expiration)->diffInDays(Carbon::now());
                $expired = false;
                $period = ($day_left > 1) ? $day_left . " days" : $day_left . " day";
            else :
                $period = '-';
                $expired = true;
            endif;
        }
        return $period;
    }

    /**
     * API request - Trigger any API requests.
     *
     * @since   2.0
     * @version 2.4
     */
    public function handle_api_requests()
    {
        global $wp;
        if (!empty($_GET['smartpay-tripay-listener'])) { // WPCS: input var okay, CSRF ok.
            $wp->query_vars['smartpay-tripay-listener'] = sanitize_key(wp_unslash($_GET['smartpay-tripay-listener'])); // WPCS: input var okay, CSRF ok.
        }

        if (!empty($wp->query_vars['smartpay-tripay-listener'])) {
            if ($wp->query_vars['smartpay-tripay-listener'] == "tripay") {
                $payment_tripay_private = '';
                if (smartpay_is_test_mode()) {
                    $payment_tripay_private = smartpay_get_option('tripay_sandbox_secret_key');
                } else {
                    $payment_tripay_private = smartpay_get_option('tripay_live_secret_key');
                }
//            $callbackSignature = isset($_SERVER['HTTP_X_CALLBACK_SIGNATURE']) ? $_SERVER['HTTP_X_CALLBACK_SIGNATURE'] : '';
                $callbackSignature = sanitize_text_field($_SERVER['HTTP_X_CALLBACK_SIGNATURE']);
                $json = file_get_contents("php://input");
                $data = json_decode($json);

                $signature = hash_hmac('sha256', $json, $payment_tripay_private);
                if ($callbackSignature == $signature) {
                    $event = $_SERVER['HTTP_X_CALLBACK_EVENT'];
                    if ($event == 'payment_status') {
                        $trx_id = $data->reference;
                        global $wpdb;
                        $payment_db = $wpdb->prefix . 'smartpay_payments';
                        $smartpay_payment = $wpdb->get_results("SELECT * FROM $payment_db WHERE transaction_id = '$trx_id'")[0];
                        $payment_id = $smartpay_payment->id;
                        $payment_data = $smartpay_payment->data;


                        $payment_create_at = $smartpay_payment->created_at;
                        $payment_extra = array(
                            'channel' => $data->payment_method_code,
                            'tripay' => array(
                                'response' => serialize($data),
                            )
                        );
                        if ($payment_data) {
                            $payment_data = json_decode($payment_data);
                            $product_id = $payment_data->product_id;
                            $data->product_id = $product_id;
                        }
                        if (false !== $smartpay_payment) :
                            $completed_time = '';
                            $completed_time_at = '';
                            $status = 'abandoned';
                            if ($data->status == 'PAID'):
                                $status = 'completed';
                                $completed_time = date('Y-m-d H:i:s', $data->paid_at);
                                $completed_time_at = date('H:i:s', $data->paid_at);
                            elseif ($data->status == 'UNPAID'):
                                $status = 'pending';
                            elseif ($data->status == 'FAILED'):
                                $status = 'failed';
                            endif;

                            if (!empty($data->paid_at)) {
                                $paid_at = date('Y-m-d H:i:s', $data->paid_at);
                                $payment_create_at = $paid_at;
                            }
                            $subscription = Subscription::where('parent_payment_id', $payment_id)->first();
                            $subscription_period = $subscription->period;
                            if ($subscription_period === Subscription::BILLING_PERIOD_DAILY) {
                                $interval = '1 day';
                            } elseif ($subscription_period === Subscription::BILLING_PERIOD_WEEKLY) {
                                $interval = '1 week';
                            } elseif ($subscription_period === Subscription::BILLING_PERIOD_MONTHLY) {
                                $interval = '1 month';
                            } elseif ($subscription_period === Subscription::BILLING_PERIOD_QUARTERLY) {
                                $interval = '3 months';
                            } elseif ($subscription_period === Subscription::BILLING_PERIOD_SEMIANNUAL) {
                                $interval = '6 months';
                            } else {
                                $interval = '12 months';
                            }
                            $expiration_date = date('Y-m-d', strtotime($completed_time . " +" . $interval));
                            $expiration_date = $expiration_date . " " . $completed_time_at;
                            $payment_subscription_db = $wpdb->prefix . 'smartpay_subscriptions';
                            $data_subscription = ['extra' => serialize($data), 'expiration' => $expiration_date, 'updated_at' => date('Y-m-d H:i:s', time())];
                            $where_subscription = ['parent_payment_id' => $payment_id];
                            $update_subscription = $wpdb->update($payment_subscription_db, $data_subscription, $where_subscription);
                            if ($data->status == 'PAID'):
                                $subscription->updateStatus(Subscription::STATUS_ACTIVE);
                            elseif ($data->status == 'FAILED'):
                                $subscription->updateStatus(Subscription::STATUS_FAILING);
                            endif;
                            $data_status = ['status' => $status, 'extra' => serialize($payment_extra), 'completed_at' => $completed_time, 'updated_at' => date('Y-m-d H:i:s', time())];
                            $where_status = ['transaction_id' => $trx_id];
                            $update_status = $wpdb->update($payment_db, $data_status, $where_status);

                            if (!is_wp_error($update_status)) {
                                echo json_encode(['success' => true]); // berikan respon yang sesuai
                                exit;
                            }

                        endif;

                    }
                }
            }
        }
    }

    /*
     * show currency unsupported message
     * @since  0.0.1
     * */

    /**
     * Add new query vars.
     *
     * @param array $vars Query vars.
     * @return string[]
     * @since 2.0
     */
    public function add_query_vars($vars)
    {
        $vars[] = 'smartpay-tripay-listener';
        return $vars;
    }

    public function unsupported_currency_notice()
    {
        echo __('<div class="error"><p>Unsupported currency! Your currency <code>' . strtoupper(smartpay_get_currency()) . '</code> does not supported by Tripay. Please change your currency from <a href="' . get_admin_url() . 'admin.php?page=smartpay-setting&tab=general">currency setting</a>.</p></div>', 'smartpay-tripay');
    }

    /**
     * recheck_payment_status into Tripay
     * @return false
     */
    public function recheck_payment_status()
    {
        $order_id = $_REQUEST['order_id'];
        $reference = $_REQUEST['reference'];

        $tripay_api_key = smartpay_is_test_mode() ? smartpay_get_option('tripay_sandbox_api_key') : smartpay_get_option('tripay_live_api_key');


        require_once('TripayPaymentGateway.php');
        $tripay_pg = new TripayPaymentGateway;  // correct
//    $transaction = $tripay_pg->tripay_get_trx($tripay_api_key, $reference);
        if (smartpay_is_test_mode()) {
            $create_bill_url = 'https://tripay.co.id/api-sandbox/transaction/detail';
        } else {
            $create_bill_url = 'https://tripay.co.id/api/transaction/detail';
        }
        $url = $create_bill_url . "/?reference=" . $reference;
        $headers = array(
            'Authorization' => 'Bearer ' . $tripay_api_key,
            'X-Plugin-Meta' => 'smartpay|' . SMARTPAY_TRIPAY_VERSION,
        );
        $response = wp_remote_post($url, array(
            'method' => 'GET',
            'timeout' => 90,
            'headers' => $headers,
        ));
        if (is_wp_error($response)) {
            return false;
        }
//        $transaction = $this->tripay_get_trx($tripay_api_key, $reference);
        $response_body = wp_remote_retrieve_body($response);
        $response_code = wp_remote_retrieve_response_code($response);

        if (empty($response_body)) {
            return false;
        }

        $transaction = json_decode($response_body, true);
        $status['status'] = "UNPAID";
        if ($transaction['success'] == true) {
            if (isset($transaction['data'])) {
                if (isset($transaction['data']['status'])) {
                    $status['status'] = $transaction['data']['status'];
                }
            }
        }
        wp_send_json($status);
        wp_die();
//        echo wp_json_encode(array("status"=>$status));
//        exit();
    }

    /**
     * Tripay create transaction
     * @return void
     * @since 2.6.7
     */

    public function tripay_create_trx($apiKey, $data, $url)
    {

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
            CURLOPT_FAILONERROR => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);

        curl_close($curl);

        return empty($error) ? $response : $error;
    }

    /**
     * Tripay check transaction
     * @return void
     * @since 2.6.7
     */

    public function tripay_get_trx($apiKey, $reference)
    {

        if (smartpay_is_test_mode()) {
            $tripay_url = 'https://tripay.co.id/api-sandbox/transaction/detail';
        } else {
            $tripay_url = 'https://tripay.co.id/api/transaction/detail';
        }

        $payload = ['reference' => $reference];

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_URL => $tripay_url . '?' . http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
            CURLOPT_FAILONERROR => false,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);

        curl_close($curl);

        return empty($error) ? $response : $error;
    }

    /**
     * add payment extra data option
     * @return void
     * @since 2.6.7
     */
    public function add_extra_data($paymentDataArray, $_data)
    {
        $exp_billing_type = '';
        $productId = absint($_data['smartpay_product_id'] ?? 0);
        $product = Product::where('id', $productId)->first();

        if ($paymentDataArray['gateway'] == "tripay") {
            if (strpos($_data['smartpay_product_billing_type'], "||") !== false) {
                $exp_billing_type = explode('||', $_data['smartpay_product_billing_type']);
            }
            if (is_array($exp_billing_type)) {
                $exp_billing_type = array_unique($exp_billing_type);
                $exp_billing_type = array_values($exp_billing_type);

                $paymentDataArray['payment_data']['product_title'] = $exp_billing_type[0];
                $paymentDataArray['date'] = date('Y-m-d H:i:s', time());
                $extra['channel'] = end($exp_billing_type);
                $paymentDataArray['extra'] = $extra;
                if ('form_payment' === $_data['smartpay_payment_type'] && Payment::BILLING_TYPE_SUBSCRIPTION === $_data['smartpay_form_billing_type']) {
                    $paymentDataArray['billing_type'] = $exp_billing_type[1];
                    $paymentDataArray['billing_period'] = $_data['smartpay_form_billing_period'];
                }
                if ('product_purchase' === $_data['smartpay_payment_type'] && Payment::BILLING_TYPE_SUBSCRIPTION === $exp_billing_type[1]) {
                    $paymentDataArray['billing_type'] = $exp_billing_type[1];
                    if (!isset($product)) return;
                    if (count($product->variations)) {
                        $defaultVariation = $product->variations->first();
                    } else {
                        $defaultVariation = $product;
                    }

                    $paymentDataArray['billing_period'] = $defaultVariation->extra['billing_period'];
                }

            } else {
                echo '<div class="alert alert-danger">Channel Pembayaran belum dipilih</div>';
                die();
            }
        }
        return $paymentDataArray;

    }

    /**
     * Set callback recipient only for tripay
     * Hooked via action init, priority 100
     * @return  void
     * @since   1.0.0
     */
    public function set_endpoint()
    {

        add_rewrite_rule('^tripay/([^/]*)/?', 'index.php?tripay-method=1&action=$matches[1]', 'top');

        flush_rewrite_rules();
    }

    /**
     * @param array $sections Gateway subsections
     * @return array
     * @access public
     * @since 2.6.7
     */
    public function gateway_section(array $sections = array()): array
    {
        $sections['tripay'] = __('Tripay', 'smartpay-tripay');

        return $sections;
    }

    /**
     * process subscription
     *
     * @param [type] $payment
     * @param [type] $paymentData
     * @param [type] $res_tripay
     * @return void
     */
    public function subscriptionProcessPayment($payment, $paymentData)
    {
        // store the subscription data into subscription table
        $subscription = new Subscription();
        $subscription->period = $paymentData['billing_period'];
        $subscription->recurring_amount = $paymentData['amount'];
        $subscription->parent_payment_id = $payment->id;
        $subscription->status = Subscription::STATUS_PENDING;
        $subscription->save();
    }

    /**
     * process tha payment
     * core function of payment processor
     * @param $payment_data
     * @return void
     * @since 2.6.7
     */
    public function ajax_process_payment($payment_data): void
    {

        global $smartpay_options;

//        self::convertToIdr($payment_data['amount'],$exchangeValue);
        // check the customer has all required data
// 		if (empty($payment_data['mobile'])) {
// 			echo '<p class="text-danger">Mobile field can not be empty. </p>';
// 		}

        // Process the subscription
// 		if ( Payment::BILLING_TYPE_SUBSCRIPTION === $payment_data['payment_data']['billing_type'] ) {
// 			// show an error msg if the payment type is subscription
// 			// because ToyyibPay does not support recurring payment
// //			do_action('smartpay_mollie_subscription_process_payment', $payment, $payment_data);
// 			echo '<p class="text-danger">Subscription/Recurring payments are not available at this moment</p>';
// 			die();
// 		}

        // make sure category code is not empty
        // if empty show an error/warning message


        try {
            echo "<span class='smartpay_tripay'> Your payment is processing. </br> Please keep patience. </br>
				You will be redirected to Tripay soon .....</span>";
            // do payment process
            $tripay_mid = smartpay_is_test_mode() ? smartpay_get_option('tripay_sandbox_merchant_code') : smartpay_get_option('tripay_live_merchant_code');
            $tripay_api_key = smartpay_is_test_mode() ? smartpay_get_option('tripay_sandbox_api_key') : smartpay_get_option('tripay_live_api_key');
            $tripay_private_key = smartpay_is_test_mode() ? smartpay_get_option('tripay_sandbox_secret_key') : smartpay_get_option('tripay_live_secret_key');

            // make sure user secret key is not empty
            // if empty show an error/warning message
            if (empty($tripay_mid) || empty($tripay_api_key) || empty($tripay_private_key)) {
                echo '<p class="text-danger">Merchant Code, API Key and/or Private Key can not be empty</p>';
                die();
            }
            $tripay_channel_code = $payment_data['extra']['channel'];
            $smartpay_gateway = $payment_data['gateway'];

            $productAmount = $payment_data['amount'];
            $this->_checkApiKeys();
            $gateways = smartpay_get_enabled_payment_gateways(true);

            if (array_key_exists("tripay", $gateways) && $smartpay_gateway == "tripay") {
                $exchangeValue = smartpay_get_option('tripay_exchange_rate_value');
                $payment_data['payment_data']['product_price'] = smartpay_tripay_convertToIdr($payment_data['payment_data']['product_price'], $exchangeValue);
                $productAmount = $payment_data['payment_data']['product_price'];
                $payment_data['amount'] = $payment_data['payment_data']['product_price'];
            }

            $gateway = $payment_data['gateway'];
            if ($gateway == 'tripay') {
                $payment_data['currency'] = 'IDR';
            }
            if (empty($tripay_channel_code)) {
                echo '<p class="text-danger">Channel belum dipilih</p>';
                die();
            }
            $payment = smartpay_insert_payment($payment_data);

            if (!$payment->id) {
                echo '<p class="text-danger">Can\'t insert payment.</p>';
                die();
            }

            if (Payment::FORM_PAYMENT === $payment_data['payment_type']) {
                $form = Form::where('id', $payment->data['form_id'])->first();
                $productTitle = strtoupper($form->title);
            }

            if (Payment::PRODUCT_PURCHASE === $payment_data['payment_type']) {
                $product = Product::where('id', $payment->data['product_id'])->first();
                $productTitle = strtoupper($product->title);
            }

            if (Payment::BILLING_TYPE_SUBSCRIPTION === $payment_data['payment_data']['billing_type']) {
                $priceName = strtoupper($payment_data['payment_data']['billing_type'] . ' ' . $payment_data['billing_period']);
            } else {
                $priceName = strtoupper($payment_data['payment_data']['billing_type']);
            }


            $return_url = add_query_arg('smartpay-payment', $payment->uuid, get_permalink($smartpay_options['tripay_confirmation_page']));

            $merchant_ref = 'INV' . $payment->id;
            $productTitle = $payment_data['payment_data']['product_title'];
            $payment_uuid = $payment->uuid;

            $payload_data = [
                'method' => $tripay_channel_code,
                'merchant_ref' => $merchant_ref,
                'amount' => $productAmount,
                'customer_name' => $payment->customer->full_name,
                'customer_email' => $payment->customer->email,
                'customer_phone' => $payment_data['mobile'],
                'order_items' => [
                    [
                        'name' => $productTitle,
                        'price' => $payment_data['payment_data']['product_price'],
                        'quantity' => 1,
                    ],
                ],
                'return_url' => $return_url,
                'expired_time' => (time() + (24 * 60 * 60)), // 24 jam
                'signature' => hash_hmac('sha256', $tripay_mid . $merchant_ref . $productAmount, $tripay_private_key)
            ];

            if (smartpay_is_test_mode()) {
                $create_bill_url = 'https://tripay.co.id/api-sandbox/transaction/create';
            } else {
                $create_bill_url = 'https://tripay.co.id/api/transaction/create';
            }

            $headers = array(
                'Authorization' => 'Bearer ' . $tripay_api_key,
                'X-Plugin-Meta' => 'smartpay|' . SMARTPAY_TRIPAY_VERSION,
            );
            $response = wp_remote_post($create_bill_url, array(
                'method' => 'POST',
                'body' => $payload_data,
                'timeout' => 90,
                'headers' => $headers,
            ));
            if (is_wp_error($response)) {
                throw new \Exception(__('We are currently experiencing problems trying to connect to this payment gateway. Sorry for the inconvenience.', 'tripay'));
            }

            $response_body = wp_remote_retrieve_body($response);

            $response_code = wp_remote_retrieve_response_code($response);
            if (empty($response_body)) {
                echo '<p class="text-danger">' . __('TriPay Response was empty.', 'tripay');
                '</p>';
                die();
            }
            $resp = json_decode($response_body, true);
            if (!$resp['success']) {
                echo '<p class="text-danger">Failed proses create order TriPay. Karena ' . $resp['message'] . '</p>';
                die();
            }
            $getPayment = Payment::find($payment->id);
            $update_extra = $getPayment->updatePaymentExtra('tripay', 'response', serialize($resp));
            $update_trx_id = $getPayment->setTransactionId($resp['data']['reference']);

            // Process the subscription
            if (isset($payment_data['billing_type']) && Payment::BILLING_TYPE_SUBSCRIPTION === $payment_data['billing_type'] && defined("SMARTPAY_PRO_VERSION")) {
                do_action('smartpay_tripay_subscription_process_payment', $payment, $payment_data);
            }

            echo do_shortcode("[smartpay_tripay_payment_receipt payment_uuid=$payment->uuid]");
            die();
        } catch (\Exception $e) {
            echo $e->getMessage();
            die();
        }
    }

    /**
     * Register the gateway settings for tripay
     *
     * @param array $settings
     *
     * @return array
     * @access public
     * @since 2.6.7
     */
    public function gateway_settings(array $settings): array
    {
        $ip = self::getIP();
        $gateway_settings = array(
            // Sandbox

            array(
                'id' => 'sandbox_heading',
                'name' => '<h4 class="text-uppercase text-info my-1">' . __('Sandbox', 'smartpay-tripay') . '</h4>',
                'desc' => __('Configure your Tripay Gateway Settings', 'smartpay-tripay'),
                'type' => 'header'
            ),

            array(
                'id' => 'tripay_sandbox_merchant_code',
                'name' => __('Merchant Code', 'smartpay-tripay'),
                'desc' => __('Enter your sandbox merchant key, <a href="https://tripay.co.id/simulator/merchant">check here</a>', 'smartpay-tripay'),
                'type' => 'text',
            ),

            array(
                'id' => 'tripay_sandbox_api_key',
                'name' => __('API Key', 'smartpay-tripay'),
                'desc' => __('Enter your sandbox API key, <a href="https://tripay.co.id/simulator/merchant">check here</a>', 'smartpay-tripay'),
                'type' => 'text',
            ),

            array(
                'id' => 'tripay_sandbox_secret_key',
                'name' => __('Private Key', 'smartpay-tripay'),
                'desc' => __('Enter your sandbox private key, <a href="https://tripay.co.id/simulator/merchant">check here</a>', 'smartpay-tripay'),
                'type' => 'text',
            ),

            // Production

            array(
                'id' => 'live_heading',
                'name' => '<h4 class="text-uppercase text-info my-1">' . __('Production', 'smartpay-tripay') . '</h4>',
                'desc' => __('Configure your Tripay Gateway Settings', 'smartpay-tripay'),
                'type' => 'header'
            ),

            array(
                'id' => 'tripay_live_merchant_code',
                'name' => __('Merchant Code', 'smartpay-tripay'),
                'desc' => __('Enter your live merchant key, <a href="https://tripay.co.id/member/merchant">check here</a>', 'smartpay-tripay'),
                'type' => 'text',
            ),

            array(
                'id' => 'tripay_live_api_key',
                'name' => __('API Key', 'smartpay-tripay'),
                'desc' => __('Enter your live API key, <a href="https://tripay.co.id/member/merchant">check here</a>', 'smartpay-tripay'),
                'type' => 'text',
            ),

            array(
                'id' => 'tripay_live_secret_key',
                'name' => __('Private Key', 'smartpay-tripay'),
                'desc' => __('Enter your live private key, <a href="https://tripay.co.id/member/merchant">check here</a>', 'smartpay-tripay'),
                'type' => 'text',
            ),

            // Production

            array(
                'id' => 'payment_heading',
                'name' => '<h4 class="text-uppercase text-info my-1">' . __('Payment Channel', 'smartpay-tripay') . '</h4>',
                'desc' => __('Configure your Tripay Gateway Settings', 'smartpay-tripay'),
                'type' => 'header'
            ),

            array(
                'id' => 'tripay_channels',
                'name' => __('Payment Channels', 'smartpay-tripay'),
                'desc' => __('Choose one or more channel.', 'smartpay-tripay'),
                'type' => 'checkbox',
                'multiple' => true,
                'options' => array(
                    'BNIVA' => 'BNI Virtual Account',
                    'MANDIRIVA' => 'Mandiri Virtual Account',
                    'BSIVA' => 'BSI Virtual Account',
                    'MYBVA' => 'Maybank Virtual Account',
                    'PERMATAVA' => 'Permata Virtual Account',
                    'SMSVA' => 'Sinarmas Virtual Account',
                    'MUAMALATVA' => 'Muamalat Virtual Account',
                    'CIMBVA' => 'CIMB Virtual Account',
                    'SAMPOERNAVA' => 'Sahabat Sampoerna Virtual Account',
                    'ALFAMART' => 'Alfamart',
                    'INDOMARET' => 'Indomaret',
                    'ALFAMIDI' => 'Alfamidi',
                    'OVO' => 'OVO',
                    'QRIS' => 'QRIS (ShopeePay)',
                    'QRISD' => 'QRIS (DANA)',
                    'SHOPEEPAY' => 'ShopeePay',
                )
            ),
            // Kurs Konversi ke IDR
            array(
                'id' => 'tripay_kurs_konversi_heading',
                'name' => '<h4 class="text-uppercase text-info my-1">' . __('Kurs Konversi ke IDR', 'smartpay-tripay') . '</h4>',
                'desc' => __('Configure your Tripay Kurs Konversi ke IDR Settings', 'smartpay-tripay'),
                'type' => 'header'
            ),
            array(
                'id' => 'tripay_exchange_rate_value',
                'name' => __('Exchange Rate value', 'smartpay-tripay'),
                'desc' => __('Configure your Tripay kurs konversi value Settings', 'smartpay-tripay'),
                'default' => 0,
                'type' => 'text',
            ),
            // Kurs Konversi ke IDR
            array(
                'id' => 'tripay_subscription_renewal_period_expired_heading',
                'name' => '<h4 class="text-uppercase text-info my-1">' . __('Subscription Settings', 'smartpay-tripay') . '</h4>',
                'desc' => __('Jika diisi dengan nilai lebih dari 0, maka sistem secara otomatis akan meniadakan pembaharuan langganan jika melewati lama hari yang telah dilakukan.', 'smartpay-tripay'),
                'type' => 'header'
            ),
            array(
                'id' => 'tripay_subscription_renewal_period_expired_value',
                'name' => __('The renewal period expires in days', 'smartpay-tripay'),
                'desc' => __('Jika diisi dengan nilai lebih dari 0, maka sistem secara otomatis akan meniadakan pembaharuan langganan jika melewati lama hari yang telah dilakukan.', 'smartpay-tripay'),
                'default' => 0,
                'type' => 'text',
            ),
            // Callback
            array(
                'id' => 'tripay_callback_heading',
                'name' => '<h4 class="text-uppercase text-info my-1">' . __('Callback URL', 'smartpay-tripay') . '</h4>',
                'desc' => __('Configure your Tripay Callback URL Settings', 'smartpay-tripay'),
                'type' => 'header'
            ),
            $smartpay_tripay_callback_description_text = __(
                sprintf(
                    '<p>Masukan link diatas ke kolom URL Callback</p><p>Untuk Production %s lalu klik tombol <b>edit</b> sesuai merchant anda.</p><p>Untuk Sandbox %s</p>',
                    '<a href="https://tripay.co.id/member/merchant" target="_blank">di sini</a>',
                    '<a href="https://tripay.co.id/member/merchant" target="_blank">di sini</a>'
                ),
                'smartpay-tripay'
            ),
            $_SERVER['REMOTE_ADDR'] == '127.0.0.1' ? $smartpay_tripay_callback_description_text .= __('<p><b>Warning!</b> It seems you are on the localhost.</p>', 'smartpay-tripay') : '',
            array(
                'id' => 'tripay_callback_desc',
                'name' => __('Callback URL', 'smartpay-tripay'),
                'desc' => $smartpay_tripay_callback_description_text,
                'std' => home_url("index.php?smartpay-tripay-listener=tripay"),
                'faux' => true,
                'readonly' => true,
                'type' => 'text'
            ),
            // Server IP

            array(
                'id' => 'tripay_server_ip_heading',
                'name' => '<h4 class="text-uppercase text-info my-1">' . __('Server IP', 'smartpay-tripay') . '</h4>',
                'desc' => __('Tripay Server IP Settings', 'smartpay-tripay'),
                'type' => 'header'
            ),
            $smartpay_tripay_server_ip_description_text = __(
                sprintf(
                    '<p>Untuk keamanan tambahan (tidak wajib), tambahkan IP diatas ke kolom Whitelist IP</p><p>Untuk Production %s lalu klik tombol <b>edit</b> sesuai merchant anda.',
                    '<a href="https://tripay.co.id/member/merchant" target="_blank">di sini</a>'
                ),
                'smartpay-tripay'
            ),
            array(
                'id' => 'tripay_server_ip_desc',
                'name' => __('Server IP', 'smartpay-tripay'),
                'desc' => $smartpay_tripay_server_ip_description_text,
                'std' => $ip,
                'faux' => true,
                'readonly' => true,
                'type' => 'text'
            ),
            // Additional Settings
            array(
                'id' => 'tripay_page',
                'name' => '<h4 class="text-uppercase text-info my-1">' . __('Page', 'smartpay-tripay') . '</h4>',
                'desc' => __('Configure your Tripay Gateway Page Settings', 'smartpay-tripay'),
                'type' => 'header'
            ),

            array(
                'id' => 'page_desc',
                'name' => __('Shortcode', 'smartpay-tripay'),
                'desc' => sprintf(__('Add shortcode <code>[smartpay_tripay_payment_receipt]</code> to the selected page below', 'smartpay-tripay')),
                'type' => 'descriptive_text'
            ),

            array(
                'id' => 'tripay_confirmation_page',
                'name' => __('Tripay Payment Confirmation Page', 'smartpay-tripay'),
                'desc' => __('Select a page for returning payment data by receipt from Tripay.', 'smartpay-tripay'),
                'type' => 'page_select',
            ),

        );

        return array_merge($settings, ['tripay' => $gateway_settings]);
    }

    public static function getIP()
    {
        $url = self::ApiUrl('/ip');

        $headers = [
            'X-Plugin-Meta' => 'woocommerce|' . SMARTPAY_TRIPAY_VERSION,
        ];

        $response = wp_remote_post($url, array(
            'method' => 'GET',
            'timeout' => 90,
            'headers' => $headers
        ));

        if (is_wp_error($response)) {
            return null;
        }

        // Retrieve the body's resopnse if no errors found
        $response_body = wp_remote_retrieve_body($response);
        $response_code = wp_remote_retrieve_response_code($response);

        if ($response_code == 200) {
            // Parse the response into something we can read
            $resp = json_decode($response_body);

            if ($resp->success == true) {
                return $resp->data->ip;
            }
        }

        return null;
    }

    public static function ApiUrl($path = '')
    {
//        $endpoint = function_exists("smartpay_is_test_mode()") ? (!smartpay_is_test_mode() ? self::$baseurl.'/api' : self::$baseurl.'/api-sandbox') : rtrim(get_option('tripay_endpoint'), '/');
        $endpoint = !smartpay_is_test_mode() ? self::$baseurl . '/api' : self::$baseurl . '/api-sandbox';

        if (empty($endpoint)) {
            $endpoint = self::$baseurl . '/api-sandbox';
        }

        return rtrim($endpoint, '/') . (!empty($path) ? '/' . ltrim($path, '/') : '');
    }

    /**
     * display mobile field option
     * @return void
     * @since 2.6.7
     */
    public function mobile_input()
    {

        echo '
		<div class="form-group smartpay_tripay_field">
			<input type="text" placeholder="08xxx" class="form-control" name="smartpay_payment_mobile" id="smartpay_payment_mobile" value="" autocomplete="phone" required>
		</div>';
    }

    /**
     * display payment channels option
     * @return void
     * @since 2.6.7
     */
    public function payment_channel_options()
    {

        $settings = get_option('smartpay_settings');
        $channels = $settings['tripay_channels'];
        ?>
        <style>
            .payment_channels .radio-group input:not(:checked) ~ label {
                filter: grayscale(1);
                transition: all .5s ease;
            }

            .payment_channels .radio-group {
                flex: 0 0 30%;
            }

            @media (max-width: 480px) {
                .payment_channels .radio-group {
                    flex: 0 0 46%;
                }
            }
        </style>
        <?php
        echo '<div class="d-flex flex-wrap justify-content-between my-3 payment_channels smartpay_tripay_field">';
        foreach ($channels as $channel) {
            if ($channel == 'BNIVA') {
                $img_url = SMARTPAY_TRIPAY_PLUGIN_ASSEST . '/img/bni.webp';
            } elseif ($channel == 'MANDIRIVA') {
                $img_url = SMARTPAY_TRIPAY_PLUGIN_ASSEST . '/img/mandiri.webp';
            } elseif ($channel == 'BSIVA') {
                $img_url = SMARTPAY_TRIPAY_PLUGIN_ASSEST . '/img/bank_bsi.webp';
            } elseif ($channel == 'MYBVA') {
                $img_url = SMARTPAY_TRIPAY_PLUGIN_ASSEST . '/img/maybank.webp';
            } elseif ($channel == 'PERMATAVA') {
                $img_url = SMARTPAY_TRIPAY_PLUGIN_ASSEST . '/img/permata.webp';
            } elseif ($channel == 'SMSVA') {
                $img_url = SMARTPAY_TRIPAY_PLUGIN_ASSEST . '/img/sms.webp';
            } elseif ($channel == 'MUAMALATVA') {
                $img_url = SMARTPAY_TRIPAY_PLUGIN_ASSEST . '/img/muamalat.webp';
            } elseif ($channel == 'CIMBVA') {
                $img_url = SMARTPAY_TRIPAY_PLUGIN_ASSEST . '/img/cimb.webp';
            } elseif ($channel == 'SAMPOERNAVA') {
                $img_url = SMARTPAY_TRIPAY_PLUGIN_ASSEST . '/img/hana_bank.webp';
            } elseif ($channel == 'ALFAMART') {
                $img_url = SMARTPAY_TRIPAY_PLUGIN_ASSEST . '/img/alfamart.webp';
            } elseif ($channel == 'INDOMARET') {
                $img_url = SMARTPAY_TRIPAY_PLUGIN_ASSEST . '/img/indomaret.webp';
            } elseif ($channel == 'ALFAMIDI') {
                $img_url = SMARTPAY_TRIPAY_PLUGIN_ASSEST . '/img/alfamidi.webp';
            } elseif ($channel == 'OVO') {
                $img_url = SMARTPAY_TRIPAY_PLUGIN_ASSEST . '/img/ovo.webp';
            } elseif ($channel == 'QRIS') {
                $img_url = SMARTPAY_TRIPAY_PLUGIN_ASSEST . '/img/qris.webp';
            } elseif ($channel == 'QRISD') {
                $img_url = SMARTPAY_TRIPAY_PLUGIN_ASSEST . '/img/qris_dana.webp';
            } elseif ($channel == 'SHOPEEPAY') {
                $img_url = SMARTPAY_TRIPAY_PLUGIN_ASSEST . '/img/shopeepay.webp';
            }

            echo '<div class="form-group radio-group p-4 m-1 rounded shadow d-flex align-items-center smartpay_tripay_field">';
            echo '<input type="radio" class="d-none channel_radio" class="form-control" name="smartpay_payment_channel" value="' . $channel . '" id="tripay_channel_' . $channel . '" required/>';
            echo '<label for="tripay_channel_' . $channel . '" class="m-0">';
            echo '<img src="' . $img_url . '"/>';
            echo '</label>';
            echo '</div>';
        }
        echo '</div>';
        ?>
        <script>
            jQuery(document).ready(function ($) {
                $(document).on('click', '.payment_channels input', function () {
                    if ($(this).is(':checked')) {
                        var type = $('input[name=smartpay_product_billing_type]');
                        title = '<?php echo get_the_title(); ?>';
                        type_value = title + '||' + $('input[name=smartpay_product_billing_type]').val();

                        type.val(type_value + '||' + $(this).val());
                    }
                });
            });
        </script>
        <?php
    }

    /**
     * add a few js on checkout
     * @return void
     * @since 2.6.7
     */

    public function js_oncheckout()
    {
        ?>
        <script>
            jQuery(document).ready(function ($) {
                $("#smartpay_payment_mobile").inputFilter(function (value) {
                    return /^\d*$/.test(value);    // Allow digits only, using a RegExp
                }, "Only digits allowed");

                $('.coupon-info').addClass('text-center');
            });
        </script>
        <?php
    }

    /**
     * add a few js on modal
     * @return void
     * @since 2.6.7
     */

    public function js_on_modal()
    {
        global $wpdb;
        $product_db = $wpdb->prefix . 'smartpay_products';
        $title = get_the_title();
        $get_product_data = $wpdb->get_results("SELECT * FROM $product_db WHERE title = '$title'");
        $base_price = $get_product_data[0]->base_price;
        $sale_price = $get_product_data[0]->sale_price;

        $settings = get_option('smartpay_settings');
        $currency = $settings['currency'];
        ?>
        <script>
            jQuery(document).ready(function ($) {
                $('.payment-modal').modal({backdrop: 'static', keyboard: false})
                $('.sale-price').html('<?php echo $currency . ' ' . number_format($sale_price, 0, ',', '.'); ?>');
                $('.base-price').html('<?php echo $currency . ' ' . number_format($base_price, 0, ',', '.'); ?>');
                $('.open-payment-form').on('click', function () {
                    setTimeout(() => {
                        $('.payment-modal--title.amount').remove();
                        $('.modal-title').append('<h2 class="payment-modal--title amount m-0"><?php echo $currency . ' ' . number_format($sale_price, 0, ',', '.'); ?></h2>');
                    }, 250);
                });
                $('.back-to-first-step').on('click', function () {
                    $(".payment-modal .modal-close").show();
                });

            });
        </script>
        <?php
    }

    /**
     * add a shortcode
     * @return void
     * @since 2.6.7
     */

    public function order_receipt($atts)
    {
        $data_list = shortcode_atts(array(
            'payment_uuid' => ''
        ), $atts);
        if (isset($data_list['payment_uuid'])) {
            $payment_uuid = $data_list['payment_uuid'];
        } else {
            $payment_uuid = isset($_GET['smartpay-payment']) ? ($_GET['smartpay-payment']) : null;
        }

        if (!$payment_uuid) {
            return;
        }

        sleep(3);

        $payment = Payment::where('uuid', $payment_uuid)->first();
        $reference = $payment->transaction_id;

        if (!$payment) {
            return;
        }

        $tripay_api_key = smartpay_is_test_mode() ? smartpay_get_option('tripay_sandbox_api_key') : smartpay_get_option('tripay_live_api_key');
        $payment_id = $payment->id;

        if (smartpay_is_test_mode()) {
            $create_bill_url = 'https://tripay.co.id/api-sandbox/transaction/detail';
        } else {
            $create_bill_url = 'https://tripay.co.id/api/transaction/detail';
        }
        $url = $create_bill_url . "/?reference=" . $reference;
        $headers = array(
            'Authorization' => 'Bearer ' . $tripay_api_key,
            'X-Plugin-Meta' => 'smartpay|' . SMARTPAY_TRIPAY_VERSION,
        );
        $response = wp_remote_post($url, array(
            'method' => 'GET',
            'timeout' => 90,
            'headers' => $headers,
        ));
        if (is_wp_error($response)) {
            return false;
        }
//        $transaction = $this->tripay_get_trx($tripay_api_key, $reference);
        $response_body = wp_remote_retrieve_body($response);
        $response_code = wp_remote_retrieve_response_code($response);

        if (empty($response_body)) {
            return;
        }

        $callback_data = json_decode($response_body, true);
        // dd($callback_data);
        if ($callback_data['success'] !== true) {
            return;
        }
        ?>
        <script>
            jQuery(document).ready(function ($) {
                $(".smartpay_tripay").remove();
                $("button.modal-close").hide();
            });
        </script>
        <?php
        $payment_method = $callback_data['data']['payment_method'];
        $payment_status = $callback_data['data']['status'];
        $name = $callback_data['data']['customer_name'];
        $wa = $callback_data['data']['customer_phone'];
        $checkout_url = $callback_data['data']['checkout_url'];
        $donate = $callback_data['data']['amount_received'];
        if ($payment_status == 'PAID') {
            $status_icon = '<i class="fa-solid fa-badge-check fs-1 text-success"></i>';
            $status_text = __('Your payment is completed!', 'smartpay-tripay');
            $my_status = 'Telah Selesai';
            // $message = 'Hai *'.$name.'*, donasi Anda ('.$callback_data['data']['reference'].') via '.$callback_data['data']['payment_name'].' berstatus *'.$my_status.'*.';
            // $get_url = $_SERVER['SCRIPT_URI'].'?sendwa=no&'.$_SERVER['QUERY_STRING'];
            // if(!isset($_GET['sendwa'])){
            //     send_whatsapp($message, 'Cek Pembayaran', $get_url, $wa);
            // }
        } else {
            $status_icon = '<i class="fa-solid fa-circle-exclamation fs-1 text-danger"></i>';
            if ($payment_method == 'OVO' || $payment_method == 'SHOPEEPAY') {
                if ($payment_method == 'OVO') {
                    $app = 'OVO';
                } elseif ($payment_method == 'SHOPEEPAY') {
                    $app = 'ShopeePay';
                }
                $status_text = sprintf(__('<b>Your payment is not completed!</b><br><small>Click Pay Now button and you will be directed to %s app.</small>', 'smartpay-tripay'), $app);
            } elseif ($payment_method == 'QRIS' || $payment_method == 'QRISD') {
                $status_text = __('<b>Your payment is not completed!</b><br><small>Scan QRCode below with your bank or e-money mobile app.</small>', 'smartpay-tripay');
            } else {
                $status_text = __('<b>Your payment is not completed!</b><br><small>Click <i class="fa-thin fa-copy"></i> icon to copy your virtual account number.</small>', 'smartpay-tripay');
            }
            // $message = 'Hai *'.$name.'*, terimakasih atas niatan Anda untuk berdonasi senilai *Rp.'.number_format($donate, 0, ',', '.').'*.';
            // $get_url = $_SERVER['SCRIPT_URI'].'?sendwa=no&tripay_reference='.$callback_data['data']['reference'];
            // if(!isset($_GET['sendwa'])){
            //     send_whatsapp($message, 'Transfer Sekarang', $get_url, $wa);
            // }
        }

        ?>
        <div class="container p-0 text-center" style="max-width: 480px">
            <div class="status-container d-flex flex-column justify-content-center align-items-center position-relative">
                <h3 class="status-icon"><?php echo $status_icon; ?></h3>
                <p><?php echo $status_text; ?></p>
                <?php
                if ($payment_status != 'PAID') {
                    if ($payment_method == 'OVO' || $payment_method == 'SHOPEEPAY') {
                        ?>
                        <input type="hidden" id="payment_ref"
                               value="<?php echo $callback_data['data']['reference']; ?>"/>
                        <label class="smartpay_tripay_status_label"><?php echo esc_attr($payment_status); ?></label>
                        <a href="<?php echo $checkout_url; ?>">
                            <button type="button"
                                    class="btn btn-primary redirect-payment"><?php echo __('Pay Now', 'smartpay-tripay'); ?>
                                <i class="fa-solid fa-arrow-right"></i></button>
                        </a>
                    <?php } elseif ($payment_method == 'QRIS' || $payment_method == 'QRISD') { ?>
                        <img src="<?php echo $callback_data['data']['qr_url']; ?>" width="150px" class="mb-3"/>
                        <input type="hidden" id="payment_ref"
                               value="<?php echo $callback_data['data']['reference']; ?>"/>
                        <label class="smartpay_tripay_status_label"><?php echo esc_attr($payment_status); ?></label>
                        <button type="button"
                                class="btn btn-primary recheck-payment"><?php echo __('Check Payment Status', 'smartpay-tripay'); ?>
                            <i class="fa-solid fa-arrows-rotate"></i></button>
                        <div class="ball position-absolute top-50 start-50" style="display: none;"></div>
                    <?php } else { ?>
                        <h3><span id="pay_code"><?php echo $callback_data['data']['pay_code']; ?></span> <i
                                    class="fa-solid fa-copy" onclick="copyElementText('pay_code')"></i></h3>
                        <input type="hidden" id="payment_ref"
                               value="<?php echo $callback_data['data']['reference']; ?>"/>
                        <label class="smartpay_tripay_status_label text-muted fw-bold fs-4"><?php echo esc_attr($payment_status); ?></label>
                        <button type="button"
                                class="btn btn-primary recheck-payment"><?php echo __('Check Payment Status', 'smartpay-tripay'); ?>
                            <i class="fa-solid fa-arrows-rotate"></i></button>
                        <div class="ball position-absolute top-50 start-50" style="display: none;"></div>
                    <?php }
                }
                ?>
            </div>
            <table class="table payment-details mt-3 mb-3">
                <tbody class="text-start" style="border-width: 1px;">
                <tr>
                    <th>Reference</th>
                    <td><?php echo $callback_data['data']['reference']; ?></td>
                </tr>
                <tr>
                    <th>Payment</th>
                    <td><?php echo $callback_data['data']['payment_name']; ?></td>
                </tr>
                <tr>
                    <th>Price</th>
                    <td><?php echo 'Rp ' . number_format($callback_data['data']['amount_received'], 0, ',', '.'); ?></td>
                </tr>
                <tr>
                    <th>Fee</th>
                    <td><?php echo 'Rp ' . number_format($callback_data['data']['total_fee'], 0, ',', '.'); ?></td>
                </tr>
                <tr>
                    <th>Total</th>
                    <td>
                        <b><?php echo 'Rp ' . number_format($callback_data['data']['amount_received'] + $callback_data['data']['total_fee'], 0, ',', '.'); ?></b>
                        <i class="fa-solid fa-money-bill text-success"></i></td>
                </tr>
                <?php if ($payment_status != 'PAID') { ?>
                    <tr>
                        <th>Expired</th>
                        <td><?php echo date('d-M-Y H:i:s', $callback_data['data']['expired_time']); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
            <?php if ($payment_status == 'PAID') { ?>
                <a href="<?php echo get_permalink(smartpay_get_option('customer_dashboard_page')); ?>">
                    <button type="button" class="btn btn-primary member-area-url">Member Area</button>
                </a>
            <?php } ?>
            <script>
                jQuery(document).ready(function ($) {
                    $(".modal-content").find("div.justify-content-center:eq(1)").remove();
                    $(document).on('click', '.payment-modal-close', function (e) {
                        $(".payment-modal").modal("hide");
                    });
                    $('.amount').html('<?php echo 'Rp ' . number_format($callback_data['data']['amount_received'] + $callback_data['data']['total_fee'], 0, ',', '.'); ?>');
                    $(document).on('click', '.recheck-payment', function (e) {
                        e.preventDefault();

                        var thisbutton = $(this);
                        order_id = <?php echo $payment_id; ?>;
                        ref = $('#payment_ref').val();
                        loading = $('.ball');

                        thisbutton.hide();
                        loading.show();
                        $('.status-container').css('background-color', '#cfcfcf');

                        $.ajax({
                            type: 'post',
                            url: '<?php echo admin_url('admin-ajax.php'); ?>',
                            data: {
                                action: 'recheck_payment_status',
                                'order_id': order_id,
                                'reference': ref,
                            },
                            success: function (response) {
                                // res = JSON.parse(response);
                                thisbutton.show();
                                loading.hide();
                                $('.status-container').removeAttr('style');

                                if (response.status === 'PAID') {
                                    $('.status-container h3.status-icon').html('<i class="fa-solid fa-badge-check fs-1 text-success"></i>');
                                    $('.status-container p').html('<?php echo __('<b>Your payment is completed!</b>', 'smartpay-tripay'); ?>');
                                    $(".smartpay_tripay_status_label").removeClass("text-danger text-muted").addClass("text-success");
                                    $(".smartpay_tripay_status_label").html(response.status);

                                    $('#steps, h3:not(.status-icon)').remove();
                                    $(".modal-content .step-2").find(".align-self-center").find("div:first").hide();
                                    thisbutton.remove();
                                    $('table.payment-details tr:last-child').remove();
                                    $('table.payment-details .member-area-url').remove();
                                    $('table.payment-details').after(
                                        '<a href="javascript:void(0);" class="payment-modal-close">' +
                                        '<button type="button" class="btn btn-primary member-area-url"><?php echo __('<b>Tutup</b>', 'smartpay-tripay'); ?></button>' +
                                        '</a>' +
                                        '<a href="<?php echo get_permalink(smartpay_get_option('customer_dashboard_page')); ?>">' +
                                        '<button type="button" class="btn btn-primary member-area-url"><?php echo __('<b>Member Area</b>', 'smartpay-tripay'); ?></button>' +
                                        '</a>'
                                    );
                                } else if (response.status === 'UNPAID') {
                                    $('.status-container p').html('<?php echo __('<b>Your payment is still unpaid</b>', 'smartpay-tripay'); ?>');
                                    $(".modal-content .step-2").find(".align-self-center").find("div:first").show();
                                    $(".smartpay_tripay_status_label").removeClass("text-success text-danger").addClass("text-muted");
                                    $(".smartpay_tripay_status_label").html(response.status);
                                } else if (response.status === 'FAILED') {
                                    $('.status-container p').html('<?php echo __('<b>Your payment has been failed</b>', 'smartpay-tripay'); ?>');
                                    $(".modal-content .step-2").find(".align-self-center").find("div:first").show();
                                    $(".smartpay_tripay_status_label").removeClass("text-success text-muted").addClass("text-danger");
                                    $(".smartpay_tripay_status_label").html(response.status);
                                }
                            }
                        });

                        return false;
                    });
                });
            </script>
            <?php
            $instructions = $callback_data['data']['instructions'];
            if ($instructions && $payment_status != 'PAID') {
                echo '<div class="accordion mb-3" id="steps">';
                foreach ($instructions as $key => $command) {
                    if ($key == 0) {
                        $key_selector = 'a';
                    } elseif ($key == 1) {
                        $key_selector = 'b';
                    } elseif ($key == 2) {
                        $key_selector = 'c';
                    } elseif ($key == 3) {
                        $key_selector = 'd';
                    } elseif ($key == 4) {
                        $key_selector = 'e';
                    }
                    echo '<div class="accordion-item">';
                    echo '<h6 class="accordion-header" id="heading_' . $key_selector . '">
                              <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#' . $key_selector . '_steps" aria-expanded="true" aria-controls="' . $key_selector . '_steps">
                                ' . $command['title'] . '
                              </button>
                            </h6>';
                    echo '<div id="' . $key_selector . '_steps" class="accordion-collapse collapse" aria-labelledby="heading_' . $key_selector . '" data-bs-parent="#steps">
                            <div class="accordion-body">';
                    echo '<ol style="padding-left: 0;">';
                    foreach ($command['steps'] as $steps) {
                        echo '<li class="text-start">' . $steps . '</li>';
                    }
                    echo '</ol>';
                    echo '</div></div></div>';
                }
                echo '</div>';
            }
            ?> </div> <?php

    }

    /**
     * Get setup values
     * @return array
     */
    protected function get_setup_values()
    {

        $merchant_code = smartpay_is_test_mode() ? smartpay_get_option('tripay_sandbox_merchant_code') : smartpay_get_option('tripay_live_merchant_code');
        $api_key = smartpay_is_test_mode() ? smartpay_get_option('tripay_sandbox_api_key') : smartpay_get_option('tripay_live_api_key');
        $private_api_key = smartpay_is_test_mode() ? smartpay_get_option('tripay_sandbox_secret_key') : smartpay_get_option('tripay_live_secret_key');
        if (smartpay_is_test_mode()) {
            $request_url = 'https://tripay.co.id/api-sandbox/transaction/create';
        } else {
            $request_url = 'https://tripay.co.id/api/transaction/create';
        }

        return array(
            'merchant_code' => $merchant_code,
            'api_key' => $api_key,
            'private_api_key' => $private_api_key,
            'request_url' => $request_url
        );
    }
}
