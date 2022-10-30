<?php
if (!function_exists("smartpay_tripay_write_debug_log")) {
    /**
     * Test debug and record into WP DEBUG Log File
     * @param $log
     */
    function smartpay_tripay_write_debug_log($log)
    {
        if (true === WP_DEBUG) {
            if (is_array($log) || is_object($log)) {
                error_log(print_r($log, true));
            } else {
                error_log($log);
            }
        }
    }
}
if (!function_exists("gp_tripay_wp_timezone_string")) {
    function gp_tripay_wp_timezone_string()
    {
        $timezone_string = smartpay_tripay_wp_timezone_string();
        smartpay_tripay_wp_string_to_timestamp($timezone_string);
    }
}
if (!function_exists("smartpay_tripay_wp_string_to_timestamp")) {
    /**
     * Convert mysql datetime to PHP timestamp, forcing UTC. Wrapper for strtotime.
     *
     * @param string $time_string Time string.
     * @param int|null $from_timestamp Timestamp to convert from.
     * @return int
     * @since  0.5.0
     */
    function smartpay_tripay_wp_string_to_timestamp($time_string, $from_timestamp = null)
    {
        $original_timezone = date_default_timezone_get();

        // @codingStandardsIgnoreStart
        date_default_timezone_set('UTC');

        if (null === $from_timestamp) {
            $next_timestamp = strtotime($time_string);
        } else {
            $next_timestamp = strtotime($time_string, $from_timestamp);
        }

        date_default_timezone_set($original_timezone);
        // @codingStandardsIgnoreEnd

        return $next_timestamp;
    }
}
if (!function_exists("smartpay_tripay_wp_timezone_string")) {
    /**
     * a WP core method exists (see https://core.trac.wordpress.org/ticket/24730).
     *
     * Adapted from https://secure.php.net/manual/en/function.timezone-name-from-abbr.php#89155.
     *
     * @return string PHP timezone string for the site
     * @since 2.1
     */
    function smartpay_tripay_wp_timezone_string()
    {
        // Added in WordPress 5.3 Ref https://developer.wordpress.org/reference/functions/wp_timezone_string/.
        if (function_exists('wp_timezone_string')) {
            return wp_timezone_string();
        }

        // If site timezone string exists, return it.
        $timezone = get_option('timezone_string');
        if ($timezone) {
            return $timezone;
        }

        // Get UTC offset, if it isn't set then return UTC.
        $utc_offset = floatval(get_option('gmt_offset', 0));
        if (!is_numeric($utc_offset) || 0.0 === $utc_offset) {
            return 'UTC';
        }

        // Adjust UTC offset from hours to seconds.
        $utc_offset = (int)($utc_offset * 3600);

        // Attempt to guess the timezone string from the UTC offset.
        $timezone = timezone_name_from_abbr('', $utc_offset);
        if ($timezone) {
            return $timezone;
        }

        // Last try, guess timezone string manually.
        foreach (timezone_abbreviations_list() as $abbr) {
            foreach ($abbr as $city) {
                // WordPress restrict the use of date(), since it's affected by timezone settings, but in this case is just what we need to guess the correct timezone.
                if ((bool)date('I') === (bool)$city['dst'] && $city['timezone_id'] && intval($city['offset']) === $utc_offset) { // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    return $city['timezone_id'];
                }
            }
        }

        // Fallback to UTC.
        return 'UTC';
    }
}
if (!function_exists("smartpay_tripay_get_subscription_by_parent_payment_id")) {
    /**
     * @param $parent_payment_id
     * @return array|object|stdClass[]
     */
    function smartpay_tripay_get_subscription_by_parent_payment_id($parent_payment_id)
    {
        global $wpdb;
        $payment_db = $wpdb->prefix . 'smartpay_subscriptions';
        $smartpay_payment = $wpdb->get_row("SELECT * FROM $payment_db WHERE parent_payment_id = '$parent_payment_id'");
        $result = array();
        if ($smartpay_payment) {
            $result = $smartpay_payment;
        }
        return $result;
    }
}
if(!function_exists("smartpay_tripay_format_pricing")) {
    /**
     * @param $price
     * @param $currency
     * @return string
     */
    function smartpay_tripay_format_pricing($price, $currency,$convert_to_symbol=true)
    {
        if (empty($currency)) {
            $currency = smartpay_get_option('currency', 'USD');
        }
        $symbol = $currency;
        if($convert_to_symbol) {
            $symbol = smartpay_get_currency_symbol($currency);
        }

        $position = smartpay_get_option('currency_position', 'before');

        /**
         * should check the amount is string or not
         * when updating the form amount with empty value, then it saves the empty string
         * handle the null value also
         */
        $amount = abs((float)$price) ?? 0;
        if ($position == 'before') {
            switch ($currency) {
                case 'GBP':
                case 'BRL':
                case 'EUR':
                case 'USD':
                    $formatted = $symbol . ' ' . number_format($amount, 2, '.', ',');
                    break;
                case 'IDR':
                    $formatted = $symbol . ' ' . number_format($amount, 0, ',', '.');
                    break;
                case 'AUD':
                case 'CAD':
                case 'HKD':
                case 'MXN':
                case 'NZD':
                case 'SGD':
                case 'JPY':
                case 'BDT':
                    $formatted = $symbol . $amount;
                    break;
                default:
                    $formatted = $currency . ' ' . $amount;
                    break;
            }
        } else {
            switch ($currency) {
                case 'GBP':
                case 'BRL':
                case 'EUR':
                case 'USD':
                    $formatted = number_format($amount, 2, '.', ',') . ' ' . $symbol;
                    break;
                case 'IDR':
                    $formatted = number_format($amount, 0, ',', '.') . ' ' . $symbol;
                    break;
                case 'AUD':
                case 'CAD':
                case 'HKD':
                case 'MXN':
                case 'SGD':
                case 'JPY':
                case 'BDT':
                    $formatted = $amount . $symbol;
                    break;
                default:
                    $formatted = $amount . ' ' . $currency;
                    break;
            }
        }

        return $formatted;
    }
}
if (!function_exists("smartpay_tripay_get_difference_day")) {
    /**
     * Get difference day
     * @param integer $timestamp unix timestamp
     * @param integer $current_time
     * @return  integer
     * @since   0.0.1
     */
    function smartpay_tripay_get_difference_day($timestamp, $given_time = NULL)
    {
        $given_time = empty($given_time) ? current_time('timestamp') : $given_time;
        return round(
            ($given_time - $timestamp) /
            DAY_IN_SECONDS
        );

    }
}

function smartpay_tripay_convertToIdr($value, $optionValue = null)
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
if (!function_exists("smartpay_tripay")) {
    function smartpay_tripay()
    {
        return \Smartpay_Tripay::initialize();
    }
}
