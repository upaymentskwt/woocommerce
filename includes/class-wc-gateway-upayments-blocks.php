<?php
// Use the necessary Blocks Interfaces
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use UPayments\Subscription\Helpers\Utils;

/**
 * UPayments Blocks Integration Class
 *
 * This class handles the integration of the UPayments gateway with the 
 * WooCommerce Block Checkout (including data and assets registration).
 */
class WCGatewayUPaymentsBlocks extends AbstractPaymentMethodType {

    protected $name = 'upayments';
    protected $gateway;
    protected $pluginFile;

    public function __construct( $pluginFile ) {
        $this->pluginFile = $pluginFile;
    }

    public function get_name() {
        return 'upayments';
    }

    public function initialize() {
        $this->settings = get_option( 'woocommerce_upayments_settings', [] );
        if ( class_exists( 'WC_Upayments' ) ) {
            $this->gateway = new WC_Upayments();
        } else {
            error_log( 'UPayments Error: WC_Upayments class not found during Blocks init.' );
        }
    }

    public function is_active() {
        return true;
    }

    public function get_supported_features() {
        return [ 'products' ];
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'upayments-block-checkout',
            plugins_url( 'assets/js/upayments-block.js', $this->pluginFile ),
            [
                // 'wc-blocks-checkout-blocks',
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-data',
                'wp-i18n',
                'wc-blocks-data-store',
            ],
            '3.0.0',
            true
        );
        return [ 'upayments-block-checkout' ];
    }

    public function get_payment_method_script_handles_for_admin() {
        return $this->get_payment_method_script_handles();
    }

    /**
     * Returns an array of key/value pairs that are passed to the JS frontend.
     *
     * @return array
     */
    public function get_payment_method_data() {
        // Basic settings and flags
        $whitelabled = false;
        $icons = [];
        $saved_cards = [];
        $is_logged_in = false;
        $user_id = null;
        $product_details = null;
        $save_card_on = false;
        
        // Safety check: ensure the gateway instance exists before calling methods
        $save_card_enabled = $this->gateway ? ($this->gateway->get_option('enable_save_card') === 'yes') : false;

        // Check if Subscription is Enabled or not
        $is_subscription_enabled = $this->gateway ? ($this->gateway->get_option('enable_subscriptions') === 'yes') : false;
        
        // 1. Get payment icons and whitelabeled status
        if ( $this->gateway ) {
            $payment_data = $this->gateway->getPaymentIcons();
            if ( $payment_data ) {
                $icons = $payment_data['payment'] ?? [];
                $whitelabled = $payment_data['whitelabled'] ?? false;
            }

            // 2. Get saved cards and login status
            $loggedInUser = $this->gateway->get_logged_in_user_phone_number();
            if ( isset($loggedInUser['success']) && $loggedInUser['success'] ) {
                $is_logged_in = true;
                $user_id = get_current_user_id();

                $token = Utils::generateDynamicToken($user_id, $loggedInUser['phone'])['token'];
                $savedCards = $this->gateway->getSavedCards($token);

                $hasPhone = $loggedInUser['success'] && !empty($loggedInUser['phone']);
                $save_card_on  = ($hasPhone || ($is_subscription_enabled && $hasPhone)) ? true : false;
                
                if ( $savedCards && isset($savedCards['result']) && $savedCards['result'] == 'success' ) {
                    $saved_cards = $savedCards['data'];
                }
            }

        }
        if ( WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
                $product = $cart_item['data'];
                $product_details[] = [
                    'type' => $product->get_type(), 
                ];
            }
        }
        
        // 3. Get total and currency display logic
        // We use standard WC() calls which are available during checkout block rendering
        $total = WC()->cart ? WC()->cart->get_total('') : '0.00';
        $language = get_locale();
        $currency_code = get_woocommerce_currency();
        
        // Logic for currency display preference
        if ( strpos($language, 'en') === 0 ) {
            $currency_display = $currency_code;
        } else {
            $currency_display = get_woocommerce_currency_symbol();
        }

        // 4. Return all data to the block
        return [
            'is_whitelabled'            => $whitelabled,
            'payment_icons'             => $icons,
            'saved_cards'               => $saved_cards,
            'is_logged_in'              => $is_logged_in,
            'save_card_enabled'         => $save_card_enabled,
            'save_card_toggle_on'       => $save_card_on,
            'cart_total'                => $total,
            'currency_display'          => $currency_display,
            'is_subscription_enabled'   => $is_subscription_enabled,
            'product_type'              => $product_details,
            'upay_subscription_plan'     => 'one_time', // Default value
            'upay_subscription_interval' => '0',        // Default value
            'plugin_url'                => plugin_dir_url( dirname( __FILE__ ) ),
            'translation'               => [
                'save_card_label'       => __('For faster and more secure checkout. Save your card details.', 'upayments'),
                'saved_cards_label'     => __('Saved Cards', 'upayments'),
                'other_options_label'   => __('Other Options', 'upayments'),
            ],
            'supports'    => [ 'products' ],
        ];
    }
}
