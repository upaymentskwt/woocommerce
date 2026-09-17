<?php

namespace UPayments\Subscription\Checkout;

use UPayments\Subscription\Helpers\Utils;

defined('ABSPATH') || exit;

class Fields
{
    public static function init()
    {
        add_action('woocommerce_checkout_process', [__CLASS__, 'validate']);
        add_filter('woocommerce_checkout_fields', [__CLASS__, 'add']);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'save'], 20, 1);
    }

    /**
     * Validate checkout submission
     */
    public static function validate()
    {
        $gateway = self::getGateway();
        $enable_subscription = $gateway->get_option('enable_subscriptions') === 'yes' ? true : false;
        if ($enable_subscription && empty($_POST['upay_subscription_plan'])) {
            wc_add_notice(__('Please select a payment type.', 'upayments'), 'error');
            return;
        }

        // One-time payment requires no interval
        if ($enable_subscription && sanitize_text_field($_POST['upay_subscription_plan']) === 'one_time') {
            return;
        }

        if ($enable_subscription && empty($_POST['upay_subscription_interval'])) {
            wc_add_notice(__('Please select a billing interval.', 'upayments'), 'error');
            return;
        }

        if ($enable_subscription && !in_array($_POST['upay_subscription_interval'], ['', '1', '2', '3', '6'], true)) {
            wc_add_notice(__('Invalid billing interval selected.', 'upayments'), 'error');
        }

        //code for additional restrictions can be added here
        // if (!Utils::cartHasCustomType()) {
        //     wc_add_notice(__('Subscriptions are not allowed for this product.', 'upayments'), 'error');
        // }
    }

    /**
     * Add subscription fields to checkout
     */
    public static function add($fields)
    {
        $gateway = self::getGateway();

        // Subscriptions disabled at gateway level
        if (!$gateway || $gateway->get_option('enable_subscriptions') !== 'yes' || Utils::cartHasRestrictedProducts() || !Utils::cartHasCustomType()) {
            return $fields;
        }

        // Customer chooses payment type
        $fields['billing']['upay_subscription_plan'] = [
            'type'     => 'select',
            'label'    => __('Purchase Type', 'upayments'),
            'required' => true,
            'options'  => [
                'one_time' => __('One-time', 'upayments'),
                'daily'    => __('Daily Subscription', 'upayments'),
                'weekly'   => __('Weekly Subscription', 'upayments'),
                'monthly'  => __('Monthly Subscription', 'upayments'),
                'quarterly'   => __('Quarterly Subscription', 'upayments'),
                'yearly'   => __('Yearly Subscription', 'upayments'),
            ],
            'priority' => 120,
        ];

        // Customer chooses interval
        $fields['billing']['upay_subscription_interval'] = [
            'type'     => 'select',
            'label'    => __('Billing Interval', 'upayments'),
            'required' => true,
            'options'  => [
                ''  => __('Select interval', 'upayments'),
            ],
            'priority' => 121,
        ];

        return $fields;
    }

    /**
     * Save subscription data to order meta
     */
    public static function save($order)
    {
        if (!empty($_POST['upay_subscription_plan'])) {
            $order->update_meta_data(
                '_upay_subscription_plan',
                sanitize_text_field($_POST['upay_subscription_plan'])
            );
        }

        if (!empty($_POST['upay_subscription_interval'])) {
            $order->update_meta_data(
                '_upay_subscription_interval',
                absint($_POST['upay_subscription_interval'])
            );
        }
    }

    /**
     * Get UPayments gateway instance
     */
    protected static function getGateway()
    {
        if (!function_exists('WC')) {
            return null;
        }

        $gateways = WC()->payment_gateways()->get_available_payment_gateways();

        return $gateways['upayments'] ?? null;
    }
}
