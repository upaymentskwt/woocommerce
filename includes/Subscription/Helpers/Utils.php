<?php

namespace UPayments\Subscription\Helpers;

defined('ABSPATH') || exit;

class Utils
{
    private static $modulo = 90000000;

    public static function isSubscriptionOrder($order)
    {
        return in_array(
            $order->get_meta('_upay_subscription_plan'),
            ['daily', 'weekly', 'monthly', 'yearly'],
            true
        );
    }

    public static function cartHasRestrictedProducts()
    {
        if (!WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $item) {
            $product_id = $item['product_id'];

            // Product-level restriction
            if (get_post_meta($product_id, '_upay_disable_subscription', true) === 'yes') {
                return true;
            }

            // Hard-coded restriction example
            if (in_array($product_id, [123, 456], true)) {
                return true;
            }
        }

        return false;
    }

    public static function cartHasCustomType()
    {
        if (!WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'];
            if ($product && $product->get_type() === 'custom_type') {
                return true;
            }
        }

        return false;
    }

    public static function cartHasNormalProduct()
    {
        if (!WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'];
            if ($product && $product->get_type() !== 'custom_type') {
                return true;
            }
        }

        return false;
    }

    public static function generateDynamicToken(int $userId, string $mobileNumber): array {
        if ($userId <= 0 || $userId >= self::$modulo) {
            return ['token' => (string)(0)];
        }
        // 1. Clean the mobile number (remove spaces, dashes, country codes if inconsistent)
        $cleanMobile = preg_replace('/[^0-9]/', '', $mobileNumber);
        
        // 2. Combine them into a unique string string
        $combinedString = $userId . '|' . $cleanMobile;

        // 3. Convert the string into a chaotic, reproducible 32-bit integer
        $numericHash = sprintf("%u", crc32($combinedString));

        // Map to [0 to 89,999,999] then shift up by 10,000,000
        $scrambled = $numericHash % self::$modulo;
        return ['token' => (string)($scrambled + 10000000)];
    }
}
