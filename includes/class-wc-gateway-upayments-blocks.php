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
        $save_card_enabled       = $this->isGatewayOptionEnabled( 'enable_save_card' );
        $is_subscription_enabled = $this->isGatewayOptionEnabled( 'enable_subscriptions' );
        
        $icons_data     = $this->getPaymentIconsData();
        $user_card_data = $this->getUserCardData();
        
        $total = WC()->cart ? WC()->cart->get_total( '' ) : '0.00';

        return [
            'is_whitelabled'            => $icons_data['whitelabled'],
            'payment_icons'             => $icons_data['icons'],
            'saved_cards'               => $user_card_data['saved_cards'],
            'is_logged_in'              => $user_card_data['is_logged_in'],
            'save_card_enabled'         => $save_card_enabled,
            'save_card_toggle_on'       => $user_card_data['save_card_on'],
            'token'                     => $user_card_data['token'],
            'cart_total'                => $total,
            'currency_display'          => $this->getCurrencyDisplay(),
            'is_subscription_enabled'   => $is_subscription_enabled,
            'product_type'              => $this->getCartProductDetails(),
            'upay_subscription_plan'     => 'one_time',
            'upay_subscription_interval' => '0',
            'plugin_url'                => plugin_dir_url( dirname( __FILE__ ) ),
            'translation'               => [
                'save_card_label'       => __( 'For faster and more secure checkout. Save your card details.', 'upayments' ),
                'saved_cards_label'     => __( 'Saved Cards', 'upayments' ),
                'other_options_label'   => __( 'Other Options', 'upayments' ),
            ],
            'supports'                  => [ 'products' ],
        ];
    }

    /**
     * Check if a specific gateway option is enabled.
     *
     * @param string $option_key
     * @return bool
     */
    private function isGatewayOptionEnabled( $option_key ) {
        if ( ! $this->gateway ) {
            return false;
        }
        return $this->gateway->get_option( $option_key ) === 'yes';
    }

    /**
     * Get payment icons and whitelabel status.
     *
     * @return array
     */
    private function getPaymentIconsData() {
        if ( ! $this->gateway ) {
            return [
                'icons'       => [],
                'whitelabled' => false,
            ];
        }

        $payment_data = $this->gateway->getPaymentIcons();
        return [
            'icons'       => $payment_data['payment'] ?? [],
            'whitelabled' => $payment_data['whitelabled'] ?? false,
        ];
    }

    /**
     * Fetch user login state, saved cards, and toggle preferences.
     *
     * @return array
     */
    private function getUserCardData() {
        $user_id = get_current_user_id();
        $logged_in_user = $this->gateway ? $this->gateway->getLoggedinUserPhoneNumber() : [];
        $is_logged_in = !empty($logged_in_user['success']);
        $phone = $logged_in_user['phone'] ?? '';
        $token = '';

        $saved_cards = [];
        $save_card_enabled = $this->isGatewayOptionEnabled( 'enable_save_card' );
        if ($save_card_enabled && $is_logged_in && !empty($phone) && $this->gateway) {
            $icons_data = $this->getPaymentIconsData();
            $icons = $icons_data['icons'] ?? [];
            // 1. Fetch KNET saved cards using dynamic token
            $knetCardList = [];
            if (empty($icons) || isset($icons['knet'])) {
                if ($user_id > 0 && !empty($phone)) {
                    $token_info = Utils::generateDynamicToken($user_id, $phone);
                    $token = $token_info['token'] ?? '';
                }

                if (!empty($token) && $token !== '0') {
                    $savedKnetCards = $this->gateway->getSavedCards($token);
                    if ($savedKnetCards && isset($savedKnetCards['result']) && $savedKnetCards['result'] === 'success' && !empty($savedKnetCards['data'])) {
                        foreach ($savedKnetCards['data'] as $card) {
                            $brand  = strtoupper(trim($card['brand'] ?? ''));
                            $scheme = strtoupper(trim($card['scheme'] ?? ''));
                            $token_val = trim((string)($card['token'] ?? ''));
                            // Only accept genuine KNET cards (brand/scheme KNET or 8-digit KFast token)
                            if ($brand === 'KNET' || $scheme === 'KNET' || (strlen($token_val) === 8 && ctype_digit($token_val))) {
                                $card['payment_type'] = 'knet';
                                $knetCardList[] = $card;
                            }
                        }
                    }
                }
            }

            // 2. Fetch CC saved cards
            $ccCardList = [];
            if (empty($icons) || isset($icons['cc'])) {
                $savedCCards = $this->gateway->getSavedCards($phone . $user_id);
                if ($savedCCards && isset($savedCCards['result']) && $savedCCards['result'] === 'success' && !empty($savedCCards['data'])) {
                    foreach ($savedCCards['data'] as $card) {
                        $brand  = strtoupper(trim($card['brand'] ?? ''));
                        $scheme = strtoupper(trim($card['scheme'] ?? ''));
                        $token_val = trim((string)($card['token'] ?? ''));
                        // Ensure only credit cards are included in CC list
                        if ($brand !== 'KNET' && $scheme !== 'KNET' && strlen($token_val) !== 8) {
                            $card['payment_type'] = 'cc';
                            $ccCardList[] = $card;
                        }
                    }
                }
            }

            $saved_cards = array_merge($knetCardList, $ccCardList);
        }

        return [
            'is_logged_in' => $is_logged_in,
            'save_card_on' => $is_logged_in && !empty($phone),
            'saved_cards'  => $saved_cards,
            'token'        => $token,
        ];
    }

    /**
     * Extract product details from current cart.
     *
     * @return array|null
     */
    private function getCartProductDetails() {
        if ( ! WC()->cart ) {
            return null;
        }

        $product_details = [];
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $product           = $cart_item['data'];
            $product_details[] = [
                'type' => $product->get_type(),
            ];
        }

        return $product_details;
    }

    /**
     * Determine currency display string based on locale.
     *
     * @return string
     */
    private function getCurrencyDisplay() {
        $language = get_locale();
        if ( strpos( $language, 'en' ) === 0 ) {
            return get_woocommerce_currency();
        }

        return get_woocommerce_currency_symbol();
    }
}
