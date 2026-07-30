<?php
/**
 * Plugin Name: UPayments
 * Plugin URI: https://developers.upayments.com/reference/woocommerce
 * Description: UPayments Plugin with Unified payment gateway supporting Old/New design, Save Card, and Multimerchant. Supports Block Checkout, Auto Deduction for Subscriptions, Bookable Products.
 * Version: 3.1.1
 * Author: <a href="https://developers.upayments.com/reference/woocommerce" target="_blank">UPayments Company</a>
 * Author URI: https://developers.upayments.com/reference/woocommerce
 * Requires at least: 5.6
 * Requires PHP: 7.2+
 * License: MIT
 * Text Domain: https://upayments.com/
 * Domain Path: /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define("UP_PLUGIN_URL", plugin_dir_url(__FILE__));
define("UP_PLUGIN_PATH", plugin_dir_path(__FILE__));
define('UPAYMENTS_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';

use UPayments\Subscription\Cron\Scheduler;
use UPayments\Subscription\Checkout\Fields;
use UPayments\Subscription\Helpers\Utils;
use UPayments\Subscription\Manager;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/upaymentskwt/woocommerce',
    __FILE__,
    'upayments-V2.2.1'
);

// Optional: use releases instead of tags
$updateChecker->getVcsApi()->enableReleaseAssets();

add_action( 'plugins_loaded', 'woocommerceUpaymentsInit' );
function woocommerceUpaymentsInit() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'upaymentsMissingWcNotice' );
        return;
    }
    class WC_Upayments extends WC_Payment_Gateway {
        public $domain;
        public $debug;
        public $apiKey;
        public $testMode;
        public $isOrderComplete;
        public $fromPluginEnabled;
        public $paymentData;

        public $multiMerchant;
        public $ibanNumber;
        public $knetCharge;
        public $knetChargeType;
        public $ccCharge;
        public $ccChargeType;
        public $saveCardEnabled;
        public $charge;
        public $autoDeduction;

        public function __construct() {
            // Define ID, title, description, and settings.
            $this->id                 = 'upayments';
            $this->icon = UP_PLUGIN_URL . "assets/images/logo.png";
            $this->method_title       = __("UPayments", $this->domain);
            $this->method_description = __("UPayments Plugin allows merchants to accept KNET, Cards, Samsung Pay, Apple Pay, Google Pay Payments. 
            Supports Block Checkout, Auto Deduction for Subscriptions.", $this->domain);
            $this->has_fields         = true; // Required for custom forms like Save Card/Design variations.

            // Define user set variables
            $this->title = '';
            $this->description = $this->get_option("description");
            $this->debug = $this->get_option("debug");
            $this->apiKey = $this->get_option("api_key");
            $this->isOrderComplete = $this->get_option('is_order_complete');
            $this->testMode = $this->get_option("test_mode");
            $this->charge = $this->get_option('charge');
            $this->fromPluginEnabled = false;
            $this->paymentData = array();

            //MultimerchantData
            $this->multiMerchant = $this->get_option("enable_multimerchant");
            $this->ibanNumber = $this->get_option("iban_number");
            $this->ccCharge = $this->get_option("cc_charge");
            $this->ccChargeType = $this->get_option("cc_charge_type");
            $this->knetCharge = $this->get_option("knet_charge");
            $this->knetChargeType = $this->get_option("knet_charge_type");
            $this->saveCardEnabled = $this->get_option("enable_save_card");
            $this->autoDeduction = $this->get_option("enable_subscriptions");

            // Load settings and hooks
            $this->init_form_fields();
            $this->init_settings();

            // Register action hook for saving settings (critical for all new toggles)
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            
            // Custom hooks for front-end rendering, scripts, etc.
            add_filter("woocommerce_get_order_item_totals", [$this, "add_order_item_totals"], 10, 3);
            add_action("woocommerce_api_" . strtolower("WC_UPayments") , [$this, "check_ipn_response", ]);
            add_filter("woocommerce_gateway_icon", [$this, "custom_payment_gateway_icons"], 10, 2);
            add_action("woocommerce_admin_order_data_after_order_details", [$this, "admin_order_details"], 10, 3);
            add_action("admin_footer", [$this, "UPayments_admin_footer"], 10, 3);
            add_action("admin_enqueue_scripts", [$this, "admin_enqueue_scripts"]);
            add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
            
            // Handlers to Display Thankyou Page after successful payment
            add_action("woocommerce_thankyou_" . $this->id, function ($order_id) {
                $this->thankyou_page($order_id);
            });
            
            // Handlers for Subscription Module
            $this->initializeSubscriptionModule();
            
            // My Account link for Login users to view their orders and saved cards
            add_action('woocommerce_before_checkout_form', function () {

                if (!function_exists('WC') || !WC()->session) {
                    return;
                }

                $account_url = wc_get_page_permalink('myaccount');

                echo '<div class="checkout-my-account-link">';
                echo '<a href="' . esc_url($account_url) . '" target="_blank">';
                echo __('Go to My Account', 'woocommerce');
                echo '</a>';
                echo '</div>';
                
                $gateways = WC()->payment_gateways()->get_available_payment_gateways();
                
                if (!isset($gateways['upayments'])) {
                    return;
                }
                
                $upay = $gateways['upayments'];
                
                if (WC()->session->get('chosen_payment_method') === 'upayments' && $upay->get_option('make_default_gateway') === 'no') {
                    WC()->session->set('chosen_payment_method', null);
                }
            }, 5);
            // Save Card & Subscriptions validation
            add_filter('woocommerce_settings_api_sanitized_fields_upayments', function ($settings) {
                $save_card      = isset($settings['enable_save_card']) && !empty($settings['enable_save_card']);
                $subscriptions  = isset($settings['enable_subscriptions']) && !empty($settings['enable_subscriptions']);
                if ($subscriptions && !$save_card) {
                    wc_add_notice(
                        __('Save Card must be enabled when Subscriptions are enabled.', 'woocommerce'),
                        'error'
                    );
                    $settings['enable_save_card'] = 'yes';
                }

                return $settings;
            });

            // Ensure phone number is required for Save Card functionality to work smoothly
            add_filter('woocommerce_checkout_fields', function ($fields) {

                // Make phone required
                $fields['billing']['billing_phone']['required'] = true;

                // Optional: update label to show *
                $fields['billing']['billing_phone']['label'] = __('Phone', 'woocommerce');

                return $fields;
            });
            // Additional validation for phone number format to ensure Save Card functionality works smoothly
            add_filter('woocommerce_billing_fields', function ($fields) {
                $fields['billing_phone']['required'] = true;
                return $fields;
            });

            // Validation for phone number on account details page to ensure Save Card functionality works smoothly
            add_action('woocommerce_save_account_details_errors', function ($errors, $user) {
                if (empty($_POST['billing_phone'])) {
                    $errors->add(
                        'billing_phone_error',
                        __('Billing phone number is required.', 'woocommerce')
                    );
                }
                if (!empty($_POST['billing_phone']) && !preg_match('/^\+?[0-9]{8,15}$/', $_POST['billing_phone'])) {
                    $errors->add(
                        'billing_phone_invalid',
                        __('Please enter a valid phone number.', 'woocommerce')
                    );
                }
            }, 10, 2);

            add_filter('woocommerce_default_gateway', function ($default) {
                wc_get_logger()->info(
                    'Default gateway filter hit. Current default: ' . $default,
                    ['source' => 'upayments-debug']
                );

                if ($this->get_option('make_default_gateway') === 'yes') {
                    wc_get_logger()->info('UPayments set as default', ['source' => 'upayments-debug']);
                    return 'upayments';
                }

                return $default;
            });

            add_filter('woocommerce_add_to_cart_validation', [$this, 'restrictMixedCartProducts'], 10, 3);
            add_action('woocommerce_before_shop_loop_item_title', [$this, 'renderSubscriptionBadgeInProductList'], 9);
        }

        public function init_form_fields() {
            $this->form_fields = array(
                "enabled" => array(
                    "title" => __("Active", $this->domain) , 
                    "type" => "checkbox", 
                    "label" => __(" ", $this->domain) , 
                    "default" => "yes"
                ), 
                'make_default_gateway' => [
                    'title'       => __('Default Gateway', $this->domain),
                    'type'        => 'checkbox',
                    'label'       => __('Make UPayments the default payment method at checkout', $this->domain),
                    'default'     => 'no',
                    'description' => __('If enabled, UPayments will be preselected at checkout. Merchants can still reorder gateways.', $this->domain),
                ],
                "title" => array(
                    "title" => __("Title", $this->domain) , 
                    "type" => "text", 
                    "description" => __("This controls the title which the user sees during checkout.", $this->domain) , 
                    "default" => $this->method_title, 
                    "desc_tip" => true
                ), 
                "description" => array(
                    "title" => __("Description", $this->domain) , 
                    "type" => "textarea", 
                    "description" => __("Instructions that the customer will see on your checkout.", $this->domain),
                    "default" => $this->method_description, 
                    "desc_tip" => true
                ),
                "api_key" => array(
                    "title" => __("Api Key", $this->domain) , 
                    "type" => "text", 
                    "description" => __("Copy/paste values from UPayments dashboard", $this->domain), 
                    "default" => "", 
                    "desc_tip" => true
                ),
                "debug" => array(
                    "title" => __("Debug", $this->domain),
                    "type" => "checkbox",
                    "label" => __(" ", $this->domain),
                    "default" => "no"
                ), 
                "test_mode" => array(
                    "title" => __("Test Mode", $this->domain),
                    "type" => "checkbox",
                    "label" => __(" ", $this->domain),
                    "default" => "no"
                ), 
                "is_order_complete" => array(   
                    "title" => __('Show paid orders as "Completed"?', $this->domain),   
                    "type" => "checkbox",   
                    "label" => __(" ", $this->domain),  
                    "default" => "yes"  
                ),
                'save_card_section_title' => array(
                    'title' => __( 'Card Tokenization & Design', $this->domain ),
                    'type'  => 'title',
                    'description' => '',
                ),
                'use_new_design' => array(
                    'title'   => __( 'Use New Design', $this->domain ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Use the modern design (if unchecked uses classic design)', $this->domain ),
                    'default' => 'yes', // Default to New Design
                ),
                'enable_save_card' => array(
                    'title'   => __( 'Enable Save Card', $this->domain ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Allow customers to save card details (Tokenization)', $this->domain ),
                    'default' => 'yes', // Default to Enabled (per V2.2.1)
                ),
                'multimerchant_section_title' => array(
                    'title' => __( 'Multimerchant Configuration', $this->domain ),
                    'type'  => 'title',
                ),
                'enable_multimerchant' => array(
                    'title'   => __( 'Enable Multimerchant', $this->domain ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Handle Merchant Account & Charges', $this->domain ),
                    'default' => 'no',
                ),
                'iban_number' => array(
                    'type' => 'text',
                    'css'  => 'display:none;',
                ),
                'cc_charge' => array(
                    'type' => 'text',
                    'css'  => 'display:none;',
                ),
                'cc_charge_type' => array(
                    'type' => 'text',
                    'css'  => 'display:none;',
                ),
                'knet_charge' => array(
                    'type' => 'text',
                    'css'  => 'display:none;',
                ),
                'knet_charge_type' => array(
                    'type' => 'text',
                    'css'  => 'display:none;',
                ),
                'multimerchant_accounts' => array(
                    'title'       => __( 'Multimerchant Accounts', $this->domain ),
                    'type'        => 'multimerchant_repeater',
                    'description' => __( 'Manage IBAN and charges for Main-Merchant.', $this->domain ),
                ),
                'autodeduction_section_title' => array(
                    'title' => __( 'Subscription Configuration', $this->domain ),
                    'type'  => 'title',
                ),
                'enable_subscriptions' => array(
                    'title'   => __( 'Enable Subscriptions', $this->domain ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable subscription payments', $this->domain ),
                    'default' => 'no',
                    "desc_tip" => true,
                    "description" => __( "Only Subscription Products are allowed at checkout If Subscription is enabled.", $this->domain ),
                ),
            );
        }

        public function UPayments_admin_footer()
        {
            include_once UP_PLUGIN_PATH . 'includes/admin-footer.php';
        }

        public function get_logged_in_user_phone_number() {
            
            // Check if the user is logged in
            if (is_user_logged_in()) {
                // Get the current user ID
                $user_id = get_current_user_id();
                $billing_phone = get_user_meta($user_id, 'billing_phone', true);
                
                if ($billing_phone) {
                    $phone = str_replace(' ', '', $billing_phone); // Replaces all spaces with hyphens.
                    $phone = preg_replace('/[^A-Za-z0-9\-]/','',$phone);
                    if (substr($phone, 0, 1) === '0') {
                        $phone = '1' . substr($phone, 1);
                    }
                    if($phone) {
                        return ['success' => true, 'phone' => $phone];
                    }
                }
                return ['success' => true, 'phone' => ''];
            }
            if (function_exists('WC') && WC()->customer) {
                $billing_phone = WC()->customer->get_billing_phone();
                
                if (!empty($billing_phone)) {
                    $phone = str_replace(' ', '', $billing_phone); // Replaces all spaces with hyphens.
                    $phone = preg_replace('/[^A-Za-z0-9\-]/','',$phone);
                    if (substr($phone, 0, 1) === '0') {
                        $phone = '1' . substr($phone, 1);
                    }
                    return ['success' => true, 'phone' => $phone];
                }
            }
            return ['success' => false, 'phone' => ''];
        }

        public function add_order_item_totals($total_rows, $order, $tax_display)
        {
            $payment_status = $order->get_meta('UPayments_Result');
            $upayment_id = $order->get_meta('UPayments_PaymentID');

            $new_total_rows = [];

            foreach ($total_rows as $key => $total)
            {
                $new_total_rows[$key] = $total;
                if ("payment_method" === $key)
                {
                    $new_total_rows["payment_status"] = ["label" => "Payment Status:", "value" => $payment_status, ];
                    if (!empty($upayment_id))
                    {
                        $new_total_rows["upayment_id"] = ["label" => "UPayment ID:", "value" => $upayment_id, ];
                    }
                }
            }

            return $new_total_rows;
        }

        /**
         * Output for the order received page.
         */
        public function thankyou_page($order_id) {
            if (!$order_id) {return;}

            $order = wc_get_order($order_id);

            if (!$order) {return;}

            $payment_status = $order->get_meta('UPayments_Result');
            $upayment_id    = $order->get_meta('UPayments_PaymentID');

            $style = "width: 100%;  margin-bottom: 1rem; background: #212B5F; padding: 20px; color: #fff; font-size: 22px;";
            $status = '';
            if (isset($_GET["status"])){
                $status = sanitize_text_field($_GET["status"]);
                if ($status == "canceled"){
                    $status = $order->get_status();
                    if ($status == "processing"){
                        $status = "completed";
                    }else{
                        $reference = sanitize_text_field($_GET["reference"]);
                        $status_message = __("Order cancelled by UPayments.", $this->domain) . ($reference ? " Reference: " . $reference : "");
                        $order->update_status("cancelled", $status_message);
                        $order->add_meta_data("UPayments_reference", $reference);
                        $order->save_meta_data();
                    }
                }
                if ($status == "completed"){
                    $status = "wait";
                }
            }
            if ($status != "wait"){
                $status = $order->get_status();
            }
            ?>
                <div class="upayments-thankyou-wrapper" data-order-id="<?php echo esc_attr($order_id); ?>">
            <?php 
                if ($status == "wait"){
            ?>
                <style>
                    .payment-panel-wait .img-container {
                        text-align: center;
                    }
                    .payment-panel-wait .img-container img{
                        display: inline-block !important;
                    }
                </style>
                <div class="payment-panel-wait">
                    <h3><?php esc_html_e("We are retrieving your payment status from UPayments, please wait...", $this->domain); ?></h3>
                    <div class="img-container"><img src="<?php echo UP_PLUGIN_PATH; ?>assets/images/loader.gif" alt="loader"/>
                    </div>
                </div>
            <?php
            } 
            ?>
                <div class="payment-panel-wait">
                    <h3><?php esc_html_e('We are retrieving your payment status...', $this->domain ); ?></h3>
                </div>
                <div class="payment-panel-pending" style="<?php echo $status == "pending" ? "display: block" : "display: none"; ?>">
                    <div style="<?php echo $style; ?>">
                        <?php esc_html_e("Your payment status is pending, we will update the status as soon as we receive notification from UPayments.", $this->domain); ?>
                    </div>
                </div>
                <div class="payment-panel-completed" style="<?php echo $status == "completed" ? "display: block" : "display: none"; ?>">
                    <div style="<?php echo $style; ?>">
                    <?php esc_html_e("Your payment is successful with UPayments.", $this->domain); ?>
                        <img style="width:100px" src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/check.png'); ?>"/>
                    </div>
                </div>
                <div class="payment-panel-failed" style="<?php echo $status == "failed" ? "display: block" : "display: none"; ?>">
                    <div style="<?php echo $style; ?>">
                    <?php esc_html_e("Your payment is failed with UPayments.", $this->domain); ?>
                    </div>
                </div>
                <div class="payment-panel-cancelled" style="<?php echo $status == "cancelled" ? "display: block" : "display: none"; ?>">
                    <div style="<?php echo $style; ?>">
                        <?php
                            if (isset($status_message) && !empty($status_message)){
                                echo $status_message;
                            }else{
                                esc_html_e("Your order is cancelled.", $this->domain);
                            }
                        ?>
                    </div>
                </div>
                <div class="payment-panel-error" style="display: none">
                    <div class="message-holder">
                        <?php esc_html_e("Something went wrong, please contact the merchant.", $this->domain); ?>
                    </div>
                </div>
                <div class="upayment-status-holder" style="display: none">
                    <li class="woocommerce-order-overview__payment-status status">
                        <?php esc_html_e("Payment Status:", "woocommerce"); ?>
                        <strong id="upayment-status-holder-strong"><?php echo wp_kses_post($payment_status); ?></strong>
                    </li>
                </div>
                <div class="upayment-id-holder" style="display: none">
                    <li class="woocommerce-order-overview__payment-id payment-id">
                        <?php esc_html_e("UPayment ID:", "woocommerce"); ?>
                        <strong id="upayment-id-holder-strong"><?php echo wp_kses_post($upayment_id); ?></strong>
                    </li>
                </div>
            </div>
        <?php
        }

        public function get_payment_staus()
        {
            $status = "wait";
            $message = "";

            try{
                $order_id = (int)sanitize_text_field($_GET["wc_order_id"]);
                if ($order_id == 0)
                {
                    throw new \Exception(__("Order not found.", $this->domain));
                }

                $payment_status = get_post_meta($order_id, "UPayments_WHS", true);
                if ($payment_status && !empty($payment_status))
                {
                    $status = $payment_status;
                }
            }catch(\Exception $e){
                $status = "error";
                $message = $e->getMessage();
            }
            $this->log($status);
            $data = ["status" => $status, "message" => $message, ];

            echo json_encode($data);
            die();
        }

        public function return_from_upayments()
        {
            if (!isset($_GET["wc_order_id"])){
                $status_message = __("No shop reference received from UPayments.", $this->domain);
                $this->log($status_message);
                wp_redirect(add_query_arg("suspected", "true", wc_get_checkout_url()));
                exit();
            }else{
                $this->log("Ret Order Id Received: " . $_GET["wc_order_id"]);
            }

            $order_id = sanitize_text_field($_GET["wc_order_id"]);
            $PaymentID = "";
            $pos = strpos($order_id, "?payment_id");
            if ($pos !== false){
                $PaymentID = substr($order_id, $pos + strlen("?payment_id") + 1);
                $order_id = (int)substr($order_id, 0, $pos);
            }

            $order = new WC_Order($order_id);

            if (isset($_GET["result"])){
                $this->log("Ret Order Result set.");
                $OrderID = sanitize_text_field($_GET["requested_order_id"]);
                $UPayments_order_id = get_post_meta($order_id, "UPayments_order_id", true)  ? get_post_meta($order_id, "UPayments_order_id", true) : $order->get_meta('UPayments_order_id');
                $this->log("Ret Upayments Order Id Received: " . $UPayments_order_id);
                if ($OrderID != $UPayments_order_id){
                    $status_message = __("Ret Order references does not match.", $this->domain);
                    $this->log($status_message);
                    $order->update_status("failed", $status_message, $this->domain);
                    wp_redirect(add_query_arg("suspected", "true", wc_get_checkout_url()));
                    exit();
                }else{
                    $this->log("Ret Order references matched.");
                    $status = sanitize_text_field($_GET["result"]);
                    
                    if (isset($_GET["payment_id"])){
                        $PaymentID = sanitize_text_field($_GET["payment_id"]);
                    }

                    $TrackID = sanitize_text_field($_GET["track_id"]);

                    $payment_type = "";

                    if (isset($_GET["payment_type"])){
                        $payment_type = sanitize_text_field($_GET["payment_type"]);
                    }

                    $PostDate = sanitize_text_field($_GET["post_date"]);
                    $TranID = sanitize_text_field($_GET["tran_id"]);
                    $Ref = sanitize_text_field($_GET["ref"]);
                    $Auth = sanitize_text_field($_GET["auth"]);

                    $order->delete_meta_data("UPayments_Result");

                    if (!empty($PaymentID)){
                        $order->delete_meta_data("UPayments_PaymentID");
                    }

                    $order->delete_meta_data("UPayments_TrackID");
                    $order->delete_meta_data("UPayments_payment_type");
                    $order->delete_meta_data("UPayments_PostDate");
                    $order->delete_meta_data("UPayments_TranID");
                    $order->delete_meta_data("UPayments_Ref");
                    $order->delete_meta_data("UPayments_Auth");
                    $order->delete_meta_data("_payment_method_title");

                    $order->add_meta_data("UPayments_Result", $status);

                    if (!empty($PaymentID)){
                        $order->add_meta_data("UPayments_PaymentID", $PaymentID);
                    }

                    $order->add_meta_data("UPayments_TrackID", $TrackID);
                    $order->add_meta_data("UPayments_payment_type", $payment_type);
                    $order->add_meta_data("UPayments_PostDate", $PostDate);
                    $order->add_meta_data("UPayments_TranID", $TranID);
                    $order->add_meta_data("UPayments_Ref", $Ref);
                    $order->add_meta_data("UPayments_Auth", $Auth);
                    $order->add_meta_data("_payment_method_title", 'UPayments');

                    $order->save_meta_data();

                    if ($status == "CANCELED" || $status == "CANCELLED"){
                        $status_message = __("Received canceled response from UPayments.", $this->domain) . ($PaymentID ? " PaymentID: " . $PaymentID : "");
                        $this->log("Ret Order Cancel Status: " . $status_message);
                        $order->update_status("cancelled", $status_message);
                        wp_redirect(add_query_arg("cancelled", "true", wc_get_checkout_url()));
                        exit();
                    }elseif ($status == "ERROR" || $status == "NOT CAPTURED" || $status == null || $status == "FAILURE"){
                        $status_message = __("Received error response from UPayments.", $this->domain) . ($PaymentID ? " PaymentID: " . $PaymentID : "");
                        $this->log("Ret Order Error Status: " . $status_message);
                        $order->update_status("failed", $status_message, $this->domain);
                        wp_redirect(add_query_arg("failed", "true", wc_get_checkout_url()));
                        exit();
                    }elseif ($status == "CAPTURED" || $status == "SUCCESS"){
                        $this->log("Ret Order CAPTURED Status");

                        $paid_order_status = 'processing';
                        if ($order->get_status() == 'completed' || $this->getIsOrderComplete()) {
                            $paid_order_status = 'completed';
                        }

                        global $woocommerce;

                        $order->update_status($paid_order_status, __('Payment successful with UPayments. PaymentID: '.$PaymentID, $this->domain));
                        $woocommerce->cart->empty_cart();
                        wp_redirect(add_query_arg("status", $status, $this->get_return_url($order)));
                        exit();
                    }
                }
            }else{
                $this->log("Ret Order Result not set.");
            }
        }

        public function web_hook_handler()
        {
            global $woocommerce;
            $this->log("Webhook Triggers");
            $this->log($_REQUEST);

            if (!isset($_REQUEST["wc_order_id"])){
                $status_message = __("No shop reference received from UPayments.", $this->domain);
                $this->log($status_message);
                exit();
            }else{
                $this->log("Order Id Received: " . $_REQUEST["wc_order_id"]);
            }

            $order_id = (int)sanitize_text_field($_REQUEST["wc_order_id"]);
            $pos = strpos($order_id, "?PaymentID");
            if ($pos !== false){
                $order_id = (int)substr($order_id, 0, $pos);
            }

            if ($order_id > 0){
                $UPayments_webhook_triggered = (int)get_post_meta($order_id, "UPayments_webhook_triggered", true);
                if ($UPayments_webhook_triggered == 1){
                    $this->log($order_id . " => UPayments_webhook_triggered set");
                    exit();
                }else{
                    $this->log($order_id . " => UPayments_webhook_triggered Not set");
                }
            }else{
                $this->log("Order Id > 0: " . $order_id);
            }

            $order = new WC_Order($order_id);

            try{
                if (isset($_REQUEST["result"])){
                    $this->log("Order Result set.");
                    $OrderID = sanitize_text_field($_REQUEST["requested_order_id"]);
                    $UPayments_order_id = get_post_meta($order_id, "UPayments_order_id", true)  ? get_post_meta($order_id, "UPayments_order_id", true) : $order->get_meta('UPayments_order_id');
                    if ($OrderID != $UPayments_order_id)
                    {
                        $status_message = __("Order references does not match.", $this->domain);
                        $this->log($status_message);
                        exit();
                    }
                    else
                    {
                        $this->log("Order references matched.");
                        $status = sanitize_text_field($_REQUEST["result"]);
                        $PaymentID = sanitize_text_field($_REQUEST["payment_id"]);
                        $TrackID = sanitize_text_field($_REQUEST["track_id"]);
                        $payment_type = sanitize_text_field($_REQUEST["payment_type"]);
                        $PostDate = sanitize_text_field($_REQUEST["post_date"]);
                        $TranID = sanitize_text_field($_REQUEST["tran_id"]);
                        $Ref = sanitize_text_field($_REQUEST["ref"]);
                        $Auth = sanitize_text_field($_REQUEST["auth"]);

                        $order->delete_meta_data("UPayments_Result");
                        $order->delete_meta_data("UPayments_PaymentID");
                        $order->delete_meta_data("UPayments_TrackID");
                        $order->delete_meta_data("UPayments_payment_type");
                        $order->delete_meta_data("UPayments_PostDate");
                        $order->delete_meta_data("UPayments_TranID");
                        $order->delete_meta_data("UPayments_Ref");
                        $order->delete_meta_data("UPayments_Auth");
                        $order->delete_meta_data("_payment_method_title");

                        $order->add_meta_data("UPayments_Result", $status);
                        $order->add_meta_data("UPayments_PaymentID", $PaymentID);
                        $order->add_meta_data("UPayments_TrackID", $TrackID);
                        $order->add_meta_data("UPayments_payment_type", $payment_type);
                        $order->add_meta_data("UPayments_PostDate", $PostDate);
                        $order->add_meta_data("UPayments_TranID", $TranID);
                        $order->add_meta_data("UPayments_Ref", $Ref);
                        $order->add_meta_data("UPayments_Auth", $Auth);
                        $order->add_meta_data("_payment_method_title", 'UPayments');

                        $order->save_meta_data();

                        if ($status == "CAPTURED" || $status == "SUCCESS"){
                            $order->add_meta_data("UPayments_webhook_triggered", 1);
                            $order->save_meta_data();
                            $this->log("Order status CAPTURED");

                            $paid_order_status = 'processing';  
                            if ($order->get_status() == 'completed' || $this->getIsOrderComplete()) {      
                                $paid_order_status = 'completed';
                            }
                            
                            $order->update_status($paid_order_status, __('Payment successful with UPayments. PaymentID: '.$PaymentID, $this->domain));
                            $woocommerce->cart->empty_cart();
                            exit();
                        }else{
                            $this->log("Order status not CAPTURED. " . $status);
                        }
                    }
                }
                else
                {
                    $this->log("Order Result not set.");
                }
            }catch(\Exception $e){
                $this->log("Webhook Catch");
                $this->log("Exception:" . $e->getMessage());

                $order->update_status("failed", "Error :" . $e->getMessage());
                $order->add_meta_data("UPayments_WHS", "failed");
                $woocommerce->cart->empty_cart();
            }
            exit();
        }

        public function check_ipn_response()
        {
            global $woocommerce;
            if (isset($_GET["get_order_status"])){
                $this->get_payment_staus();
            }elseif (isset($_GET["page"])){
                $this->return_from_upayments();
            }else{
                $this->web_hook_handler();
            }
            exit();
        }

        // Process payment (must use feature flags to route API calls)
        public function process_payment( $order_id ) {
            global $woocommerce;
            $whitelabled = false;

            $order = wc_get_order($order_id);
            $order_data = $order->get_data();
            $order_total = $order->get_total();

            $success_url = site_url() . "/?wc-api=wc_upayments&page=success&wc_order_id=" . $order_id;
            $error_url = site_url() . "/?wc-api=wc_upayments&page=error&wc_order_id=" . $order_id;
            $ipn_url = site_url() . "/?wc-api=wc_upayments&wc_order_id=" . $order_id;

            $unique_order_id = md5($order_id * time());
            $product_name = [];
            $product_price = [];
            $product_qty = [];
            $product_type = [];

            $productArrayNew = [];
            $cart_has_custom_product = false;

            $i=0;

            foreach ($order->get_items('line_item') as $item)
            {
                /** @var WC_Order_Item_Product $item */
                $product = $item->get_product();
                $sale_price = $product->get_regular_price();
                $sale_price = !empty($sale_price) ? $sale_price : 0;
                if($product->get_type() === 'custom_type'){
                    $cart_has_custom_product = true;
                }
                
                $item_data = $item->get_data();
                $product_name[] = $item->get_name();
                $product_price[] = $sale_price;
                $product_qty[] = $item_data["quantity"];
                $product_type[] = $product->get_type();
                
                $productArrayNew[$i]['name'] = $item->get_name();
                $productArrayNew[$i]['description']= $item->get_name();
                $productArrayNew[$i]['price'] = $sale_price;
                $productArrayNew[$i]['quantity'] =$item_data["quantity"];
                $productArrayNew[$i]['type'] = $product->get_type();
                $i++;
            }

            if($this->paymentData == null ) {
                $payment_data = $this->getPaymentIcons();
            } else {
                $payment_data = $this->paymentData;
            }
            if($payment_data){
                $whitelabled = $payment_data['whitelabled'];
            }

            // 1. Get Extension Data from the Blocks Checkout
            // WooCommerce Blocks sends this data in the request body, not $_POST
            $request_data = json_decode(file_get_contents('php://input'), true);
            $extension_data = isset($request_data['extensions']['upayments']) ? $request_data['extensions']['upayments'] : [];

            if(!empty($extension_data)){
                $src = '';
                if (isset($extension_data['upayment_payment_type'])) {
                    $src = sanitize_text_field($extension_data['upayment_payment_type']);
                }

                $cardToken = '';
                if(isset($extension_data['card_token'])){
                    $cardToken = sanitize_text_field($extension_data['card_token']);
                }

                $isSaveCardRequested = false;
                if(isset($extension_data['save_card']) && $extension_data['save_card'] == 1){
                    $isSaveCardRequested = true;
                }
                $isSaveCard = $isSaveCardRequested ? true : false;

                if ($whitelabled){
                    $whitelabled = true;
                    $upayment_payment_type = sanitize_text_field($extension_data['upayment_payment_type']);
                        if (!empty($upayment_payment_type)){
                            $src = $upayment_payment_type;
                            $order->delete_meta_data("UPayments_Checkout_Selected");
                            $order->add_meta_data("UPayments_Checkout_Selected", $upayment_payment_type);
                        }
                    $cardToken = sanitize_text_field($extension_data['card_token']);
                }

                // Checck if Upayments Payment type is empty then return error.
                if ($whitelabled && empty($src)){
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Please select a UPayments Payment Type.", $this->domain) , "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url() , ];
                }

                // Check if Auto Deduction Feature is on and save card toggle is disabled then return error.
                if($this->autoDeduction === 'yes' && (!$isSaveCardRequested) && $cart_has_custom_product) {
                    $this->log("Auto Deduction Enabled and Save Card Toggle Disabled");
                    wc_add_notice(__("Please Enable Save Card Toggle to Proceed with Subscription Purchase.", $this->domain) , "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }

                $subscription_plan = 'one_time';
                $subscription_interval = 0;
                if(isset($extension_data['upay_subscription_plan'])){
                    $subscription_plan = $extension_data['upay_subscription_plan'];
                }
                if(isset($extension_data['upay_subscription_interval'])){
                    $subscription_interval = $extension_data['upay_subscription_interval'];
                }

                if($this->autoDeduction === 'yes' && ($subscription_plan !== 'one_time' && $subscription_interval <= 0)) {
                    wc_add_notice(__("Please select a valid Billing Interval.", $this->domain), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }
            } else {
                $this->log("Whitelabled: " . ($whitelabled ? "true" : "false"));
                if ($whitelabled && !isset($_POST["upayment_payment_type"])){
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Please select a UPayments Payment Type.", $this->domain) , "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url() , ];
                }

                $src = "knet";
                $cardToken = '';
                $isSaveCardRequested = sanitize_text_field($_POST["save_card"]) == 1 ? true : false;
                $isSaveCard = $isSaveCardRequested ? true : false;

                if ($whitelabled){
                    $whitelabled = true;
                    $upayment_payment_type = sanitize_text_field($_POST["upayment_payment_type"]);
                        if (!empty($upayment_payment_type)){
                            $src = $upayment_payment_type;
                            $order->delete_meta_data("UPayments_Checkout_Selected");
                            $order->add_meta_data("UPayments_Checkout_Selected", $upayment_payment_type);
                        }
                    $cardToken = sanitize_text_field($_POST["card_token"]);
                }

                $subscription_plan = 'one_time';
                $subscription_interval = 0;
                if (isset($_POST['upay_subscription_plan'])) {
                    $subscription_plan = sanitize_text_field($_POST['upay_subscription_plan']);
                }
                if (isset($_POST['upay_subscription_interval'])) {
                    $subscription_interval = (int) sanitize_text_field($_POST['upay_subscription_interval']);
                }

                if($this->autoDeduction === 'yes' && ($subscription_plan !== 'one_time' && $subscription_interval <= 0)) {
                    wc_add_notice(__("Please select a valid Billing Interval.", $this->domain), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }

                if($this->autoDeduction === 'yes' && (!$isSaveCardRequested) && $cart_has_custom_product) {
                    $this->log("Auto Deduction Enabled and Save Card Toggle Disabled");
                    wc_add_notice(__("Please Enable Save Card Toggle to Proceed with Subscription Purchase.", $this->domain) , "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }
            }
            
            $credit_card_token = $cardToken;
            $phone = str_replace(' ', '', $order_data["billing"]["phone"]); // Replaces all spaces with hyphens.
            $phone = preg_replace('/[^A-Za-z0-9\-]/','',$phone);
            $customer_unq_token = $phone;
            $customerToken = '';
            
            $user_id = get_current_user_id();
            if($user_id && !empty($customer_unq_token)) {
                $customerToken = Utils::generateDynamicToken($user_id, $customer_unq_token)['token'];
                update_user_meta($user_id, 'customer_unique_token', $customerToken);
            }

            if($this->saveCardEnabled === 'yes' && $isSaveCard) {
                $customerUnqToken = $this->getCustomerUniqueToken($customerToken);
            } else {
                $customerUnqToken = null;
                $isSaveCard = false;
            }

            if(!empty($credit_card_token)){
                $order->delete_meta_data("_upay_credit_card_token");
                $order->add_meta_data("_upay_credit_card_token", $credit_card_token);
                $order->save_meta_data();
            }

            if($customerUnqToken){
                $order->delete_meta_data("_upay_customer_unique_token");
                $order->add_meta_data("_upay_customer_unique_token", $customerUnqToken);
                $order->save_meta_data();
            }

            $extraMerchantData = null;
            if ($this->multiMerchant == "yes") {
                $this->log("multiMerchant enabled");
                if(isset($this->ibanNumber) && isset($this->knetCharge) && $this->knetCharge > 0 && isset($this->ccCharge) &&((float) $this->knetCharge > 0) && (float) $this->ccCharge > 0) {

                    $extraMerchantData[0] = [
                        "amount" =>  $order_total,
                        "knetCharge" => (float) $this->knetCharge,
                        "knetChargeType" =>  $this->knetChargeType,
                        "ccCharge" => $this->ccCharge,
                        "ccChargeType" => $this->ccChargeType,
                        "ibanNumber" => $this->ibanNumber
                    ];
                }
                $this->log("extraMerchantData");
                $this->log($extraMerchantData);
            }

            $params = json_encode([
                "returnUrl" => $success_url, 
                "cancelUrl" => $error_url, 
                "notificationUrl" => $ipn_url, 
                "products" => $productArrayNew,
                "order" =>[
                    "amount" => $order_total, 
                    "currency" => $this->getCurrencyCode($order_data["currency"]) , 
                    "id" => $unique_order_id, 
                ], 
                "reference" => [
                    "id" => "".$order_id, 
                ], 
                "customer" => [
                    "uniqueId" => $customer_unq_token, 
                    "name" => $order_data["billing"]["first_name"] . " " . $order_data["billing"]["last_name"], 
                    "email" => $order_data["billing"]["email"], 
                    "mobile" => $phone, 
                ], 
                "plugin" => [
                    "src" => "woocommerce", 
                ], 
                "is_whitelabled" => $whitelabled, 
                "language" => "en", 
                "isSaveCard" => $isSaveCard, 
                "paymentGateway" => ["src" => $src,], 
                "tokens" => [
                    "creditCard" => $credit_card_token, 
                    "customerUniqueToken" => $customerUnqToken,
                ], 
                "device" => [
                    "browser" => "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/107.0.0.0 Safari/537.36 OPR/93.0.0.0", 
                    "browserDetails" => [
                        "screenWidth" => "1920", 
                        "screenHeight" => "1080", 
                        "colorDepth" => "24", 
                        "javaEnabled" => "false", 
                        "language" => "en", 
                        "timeZone" => "-180",
                        "3DSecureChallengeWindowSize" => "500_X_600"
                    ], 
                ], 
                "extraMerchantData" => $extraMerchantData,
            ]);

            $this->log(__("Create Payment Request:", $this->domain));
            $this->log($params);

            $this->log(__("API key:", $this->domain));
            $this->log($this->apiKey);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->getApiUrl('charge'));
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            // uncomment when required
            // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, $this->getUserAgent());
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $this->apiKey, "Accept: application/json", "Content-Type: application/json", ]);

            $response = curl_exec($ch);
            $this->log('Response: ' . $response);
            curl_close($ch);

            try
            {
                if (!$response){

                    $this->log(__("Create Payment Response: curl error", $this->domain) . " => " . curl_error($ch));
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Payment request failed. " . curl_error($ch) , $this->domain) , "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];

                }else{
                    $result = json_decode($response, true);
                    $this->log(__("Create Payment Response:", $this->domain));
                    $this->log($result);
                    if (!$result){
                        
                        WC()->session->set("refresh_totals", true);
                        wc_add_notice(__("Payment request failed. Empty Response Received.", $this->domain) , "error");
                        return ["result" => "failure", "redirect" => wc_get_checkout_url()];

                    }elseif (isset($result["status"]) && !$result["status"]){

                        WC()->session->set("refresh_totals", true);
                        wc_add_notice(__("Payment request failed. " . $result["message"], $this->domain) , "error");
                        return ["result" => "failure", "redirect" => wc_get_checkout_url()];

                    }elseif (isset($result["message"]) && !isset($result["status"])){

                        WC()->session->set("refresh_totals", true);
                        wc_add_notice(__("Payment request failed. " . $result["message"], $this->domain) , "error");
                        return ["result" => "failure", "redirect" => wc_get_checkout_url()];

                    }elseif (isset($result["status"]) && $result["status"]){
                        if($subscription_plan && $subscription_plan != 'one_time') {
                            $order->delete_meta_data('_upay_subscription_plan');
                            $order->add_meta_data('_upay_subscription_plan', $subscription_plan);
                            $order->delete_meta_data('_upay_subscription_interval');
                            $order->add_meta_data('_upay_subscription_interval', $subscription_interval);
                            $order->delete_meta_data('_upay_subscription_status');
                            $order->add_meta_data('_upay_subscription_status', 'active');
                            $order->delete_meta_data('UPayments_AutoDeduction');
                            $order->add_meta_data('UPayments_AutoDeduction', 'no');
                            $order->save_meta_data();
                        }
                        if ($result["data"]["link"]){
                            $order->delete_meta_data("UPayments_order_id");
                            $order->add_meta_data("UPayments_order_id", $unique_order_id);
                            $order->save_meta_data();

                            return ["result" => "success", "redirect" => $result["data"]["link"]];
                        }else{
                            $order->delete_meta_data("UPayments_order_id");
                            $order->add_meta_data("UPayments_order_id", $unique_order_id);
                            $order->save_meta_data();
                            $this->log(__($result["data"]["transactionData"]["redirect_url"], $this->domain));

                            return ["result" => "success", "redirect" => $result["data"]["transactionData"]["redirect_url"], ];
                        }
                    }else{
                        $status_message = __("UPayments: Something went wrong, please contact the merchant", $this->domain);
                        WC()->session->set("refresh_totals", true);
                        wc_add_notice($status_message, "error");
                        return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                    }
                }
            }catch(\Exception $e){
                $message = $e->getMessage();
                $this->log(__("Create Payment Response: catch exception", $this->domain) . " => " . $message);
                $status_message = __("UPayments: Something went wrong, please contact the merchant", $this->domain);

                WC()->session->set("refresh_totals", true);
                wc_add_notice($status_message, "error");
                return ["result" => "failure", "redirect" => wc_get_checkout_url()];
            }
        }

        // Frontend payment fields (must use feature flags for design)
        public function payment_fields() {
            $save_card_enabled  = ('yes' == $this->get_option('enable_save_card'));
            $template_args = array('gateway' => $this,'save_card_enabled' => ('yes' == $save_card_enabled));
            // Check setting for design toggle
            $use_new_design = ($this->get_option('use_new_design') == 'yes') ? true : false;
            
            wc_get_template( 
                $use_new_design ? 'new-design-form.php' : 'old-design-form.php', 
                $template_args, 
                $this->domain, 
                untrailingslashit( plugin_dir_path( __FILE__ ) ) . '/templates/' 
            );
        }
        
        /**
         * enqueue_scripts
         *
         * @return void
         */
        public function enqueue_scripts() {
            $plugin_url = plugin_dir_url( __FILE__ );
            wp_enqueue_style('customer-new-style', $plugin_url . 'assets/css/customer.css', array(), '3.0.0' );
            // Check if we are on the checkout page AND the gateway is active
            if ( ! is_checkout() || ! $this->is_available() ) {
                return;
            }
            
            // Always enqueue core scripts (e.g., utility functions, global validation)
            wp_enqueue_style('google-fonts', 'https://fonts.googleapis.com/css2?family=Almarai&display=swap');
            wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css');

            if (is_checkout() && !is_wc_endpoint_url()) {
                if ($this->get_option('use_new_design') == 'yes') {
                    // Load New Design specific resources (Modal handling, modern API SDK)
                    wp_enqueue_style('custom-checkout-new-style', $plugin_url . 'assets/css/new-design.css', array(), '3.0.0' );
                    wp_enqueue_script('custom-checkout-script', $plugin_url . 'assets/js/new-upay.js', array('jquery'), '3.0.0', true );
                } else {
                    // Load Old Design specific resources (Inline form handling, legacy API SDK)
                    wp_enqueue_style('custom-checkout-old-style', $plugin_url . 'assets/css/old-design.css', array(), '3.0.0' );
                    wp_enqueue_script('custom-checkout-old-script', $plugin_url . 'assets/js/old-upay.js', array('jquery'), '3.0.0', true );
                }
                wp_enqueue_script('upayments-subscription-checkout', $plugin_url. 'assets/js/subscription-checkout.js', array('jquery'),'3.0.0',true);
                wp_localize_script('upayments-subscription-checkout', 'wcUser', [
                    'isLoggedIn' => is_user_logged_in(),
                    'userId'     => get_current_user_id(),
                ]);
            }            
            
            // Localize data needed by the JavaScript (e.g., API keys, environment settings)
            wp_localize_script( 'your-gateway-core', 'YourGatewayParams', array(
                'isNewDesign' => $this->get_option('use_new_design') == 'yes',
            ));
        }

        /**
         * Enqueue admin scripts for the custom repeater.
         */
        public function admin_enqueue_scripts() {
            $plugin_url = plugin_dir_url( __FILE__ );
            
            // Check if we are on the correct gateway settings page
            if ( isset( $_GET['page'] ) && $_GET['page'] == 'wc-settings' && isset( $_GET['tab'] ) && $_GET['tab'] == 'checkout' && isset( $_GET['section'] ) && $_GET['section'] == $this->id ) {    
                
                wp_enqueue_style('upayments-multimerchant-style',$plugin_url.'assets/css/admin-style.css', [], '3.0.0' );
                wp_enqueue_script('upayments-multimerchant-repeater',$plugin_url.'assets/js/multimerchant-repeater.js',array('jquery'), '3.0.0',true);
            }

            // Check to ensure we are only loading this script on *our* settings page.
            $screen = get_current_screen();

            // The screen ID for WooCommerce payment settings pages often looks like 'woocommerce_page_wc-settings'
            if ( $screen && $screen->id === 'woocommerce_page_wc-settings' && isset( $_GET['tab'] ) && $_GET['tab'] === 'checkout' ) {
                // Enqueue the custom admin logic script
                wp_enqueue_script(
                    'upayments-admin-logic',$plugin_url.'assets/js/admin-settings.js',array( 'jquery' ),'3.0.0',true
                );
                
                // Also enqueue a small style block to make the disabled row visually distinct
                wp_add_inline_style(
                    'woocommerce_admin_styles', '.upayments-disabled-setting { opacity: 0.5; pointer-events: none; }'
                );
            }
        }

        public function admin_order_details($order)
        {
            if ($order->get_payment_method() == $this->id)
            {
                $payment_status = get_post_meta($order->get_id() , "UPayments_Result", true);
                $upayment_id = get_post_meta($order->get_id() , "UPayments_PaymentID", true);

                if (!empty($payment_status) || !empty($upayment_id))
                { ?>
                    <table class="wc-order-totals" style="border-top: 1px solid #999; margin-top:12px; padding-top:12px">
            <tbody>
                            <tr>
                                <td class="label"><h3 style="margin:0"><?php echo __("Payment Status", $this->domain); ?>:</h3></td>
                <td width="1%"></td>
                <td class="total">
                                    <span class="woocommerce-Price-amount amount"><strong><?php echo $payment_status; ?></strong></span>
                                </td>
                            </tr>
                            <tr>
                <td class="label"><h3 style="margin:0"><?php echo __("UPayment ID", $this->domain); ?>:</h3></td>
                <td width="1%"></td>
                <td class="total">
                                    <span class="woocommerce-Price-amount amount">
                                        <strong>
                                        <?php echo $upayment_id; ?>
                                        </strong>
                                    </span>
                                </td>
                            </tr>
                            
                        </tbody>
                    </table>
            <?php
                }
            }
        }

        public function custom_payment_gateway_icons($icon, $gateway_id)
        {
            foreach (WC()->payment_gateways->get_available_payment_gateways() as $gateway){
                if ($gateway->id == $gateway_id){
                    $title = $gateway->get_title();
                    break;
                }
            }
            if ($gateway_id == "upayments"){
                $icon = '<span>Pay securely with <img src="'.UP_PLUGIN_URL.'assets/images/upayment.png" alt="UPayemnts"  title="UPayments" style="height: 24px !important; padding-left:4px;"/></span>';
            }
            return $icon;
        }

        /**
         * Process Gateway Settings Form Fields.
         */
        public function process_admin_options()
        {
            $this->init_settings();
            $post_data = $this->get_post_data();

            if (empty($post_data["woocommerce_upayments_api_key"])){
                WC_Admin_Settings::add_error(__("Please enter UPayments API Key", $this->domain));
            }else{
                if(isset($post_data['woocommerce_upayments_enable_multimerchant']) && $post_data['woocommerce_upayments_enable_multimerchant'] == 1) {
                    if(empty($post_data['woocommerce_upayments_iban_number']) || empty($post_data['woocommerce_upayments_cc_charge']) || empty($post_data['woocommerce_upayments_cc_charge_type']) || empty($post_data['woocommerce_upayments_knet_charge']) || empty($post_data['woocommerce_upayments_knet_charge_type'])) {
                        WC_Admin_Settings::add_error(__("Please enter Multimerchant Configuration", $this->domain));
                    }
                } else {
                    $post_data['woocommerce_upayments_iban_number'] = null;
                    $post_data['woocommerce_upayments_cc_charge'] = null;
                    $post_data['woocommerce_upayments_cc_charge_type'] = null;
                    $post_data['woocommerce_upayments_knet_charge'] = null;
                    $post_data['woocommerce_upayments_knet_charge_type'] = null;
                }
                foreach ($this->get_form_fields() as $key => $field)
                {
                    $setting_value = $this->get_field_value($key, $field, $post_data);
                    $this->settings[$key] = $setting_value;
                }
                delete_option("upayments_maat");
                return update_option($this->get_option_key() , apply_filters("woocommerce_settings_api_sanitized_fields_" . $this->id, $this->settings));
            }
        }

        public function get_multimerchant_credentials( $order ) {
            // 1. Check if Multimerchant is enabled at all
            if ($this->get_option( 'enable_multimerchant' ) == 'no') {
                return $this->get_default_credentials();
            }
            
            // 2. Retrieve and parse the stored rules
            $rules_json = $this->get_option( 'multimerchant_accounts', '[]' );
            $rules = json_decode( $rules_json, true );

            if (!is_array($rules) || empty($rules)) {
                // Fallback if rules are enabled but not configured
                $this->log( 'Multimerchant enabled but no rules found. Using default credentials.', 'error' );
                return $this->get_default_credentials();
            }

            // --- Core Routing Logic ---
            
            foreach ( $rules as $rule ) {
                $condition_type  = $rule['condition_type'] ?? '';
                $condition_value = $rule['condition_value'] ?? '';

                // If a rule has no condition, skip it (it won't match anything specific)
                if ( empty( $condition_type ) || empty( $condition_value ) ) {
                    continue;
                }

                $match_found = false;

                switch ( $condition_type ) {
                    case 'fixed':
                        // Check if the order currency matches the rule value (e.g., USD, EUR)
                        if ($condition_value === 'fixed') {
                            $match_found = true;
                        }
                        break;
                    case 'percentage':
                        // Check if the billing country matches the rule value (e.g., US, DE)
                        if ($condition_value === 'percentage') {
                            $match_found = true;
                        }
                        break;
                    default:
                        // Unhandled condition type
                        break;
                }

                if ( $match_found ) {
                    $this->log( "Routing match found: {$condition_type} = {$condition_value}. Using IBAN: {$rule['iban_number']}.", 'info' );
                    return [
                        'merchant_id' => $rule['merchant_id'],
                        'api_key'     => $rule['api_key'],
                    ];
                }
            }
            // 3. Fallback: If no custom rule matched, use default credentials
            $this->log( 'No specific routing rule matched. Using default credentials.', 'info' );
            return $this->get_default_credentials();
        }

        public function get_default_credentials() {
            // Assuming your default credentials are stored as standard gateway options
            return [
                'merchant_id' => $this->get_option( 'default_merchant_id' ),
                'api_key'     => $this->get_option( 'default_api_key' ),
            ];
        }

        /**
         * Generate the HTML for the Multimerchant Repeater field.
         * This is where the table structure for the rules is defined.
         * * @param string $key The field key (multimerchant_accounts).
         * @param array $data Field data from init_form_fields().
         * @return string HTML output for the field.
         */
        public function generate_multimerchant_repeater_html( $key, $data ) {
            // Get stored rules (value is a JSON string, must be decoded)
            $settings = $this->get_option( $key, $data['default'] );
            $rules = json_decode( $settings, true );
            if ( ! is_array( $rules ) ) {
                $rules = [];
            }

            $conditions = [
                'fixed'      => __( 'Fixed', $this->domain ),
                'percentage'       => __( 'Percentage', $this->domain ),
            ];

            // Pass the repeater HTML to a dedicated function for cleanliness
            ob_start();
            ?>
            <tr valign="top" class="upayments-multimerchant-repeater">
                <th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
                <td class="forminp forminp-<?php echo esc_attr( sanitize_title( $data['type'] ) ); ?>">
                    <p class="description"><?php echo wp_kses_post( $data['description'] ); ?></p>
                    <div id="multimerchant_repeater_container">
                        <table class="widefat wc_input_multimerchant_repeater" cellspacing="0">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'IBAN Number', $this->domain ); ?></th>
                                    <th><?php esc_html_e( 'Knet Charge', $this->domain ); ?></th>
                                    <th><?php esc_html_e( 'Knet Charge Type', $this->domain ); ?></th>
                                    <th><?php esc_html_e( 'CC Charge', $this->domain ); ?></th>
                                    <th><?php esc_html_e( 'CC Charge Type', $this->domain ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="">
                                    <td>
                                        <input type="text" name="woocommerce_upayments_iban_number" data-field="iban_number" value="<?php echo $this->get_option('iban_number'); ?>" placeholder="<?php esc_html_e('KWK00445...', $this->domain); ?>" style="width: 400px;"/>
                                    </td>
                                    <td>
                                        <input type="number" name="woocommerce_upayments_knet_charge" data-field="knet_charge" value="<?php echo $this->get_option('knet_charge'); ?>" placeholder="<?php esc_html_e('0.000', $this->domain);?>" min="0.000" max="10.000" step="0.010"/>
                                    </td>
                                    <td>
                                        <select data-field="knet_charge_type" name="woocommerce_upayments_knet_charge_type">
                                            <option value=""><?php esc_html_e( 'Select', $this->domain ); ?></option>
                                            <?php foreach ( $conditions as $val => $label ) : ?>
                                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, $this->get_option('knet_charge_type') ); ?>>
                                                    <?php echo esc_html( $label ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" name="woocommerce_upayments_cc_charge" data-field="cc_charge" value="<?php echo $this->get_option('cc_charge'); ?>" placeholder="<?php esc_html_e('0.000', $this->domain); ?>" min="0.000" max="10.000" step="0.010"/>
                                    </td>
                                    <td>
                                        <select data-field="cc_charge_type" name="woocommerce_upayments_cc_charge_type">
                                            <option value=""><?php esc_html_e( 'Select', $this->domain ); ?></option>
                                            <?php foreach ( $conditions as $val => $label ) : ?>
                                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, $this->get_option('cc_charge_type') ); ?>>
                                                    <?php echo esc_html( $label ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            </tbody>                           
                        </table>
                    </div>
                    <input type="hidden" name="woocommerce_<?php echo esc_attr( $this->id ); ?>_<?php echo esc_attr( $key ); ?>"  id="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $settings ); ?>" />
                </td>
            </tr>
            <?php
            return ob_get_clean();
        }

        /**
         * Custom logic to sanitize and save the JSON data from the repeater field.
         * * @param string $value The raw POST value for the field.
         * @return string The sanitized and JSON-encoded string.
         */
        public function validate_multimerchant_repeater_field( $key, $value ) {
            // Decode the JSON string
            $rules = json_decode( stripslashes( $value ), true );
            
            if ( ! is_array( $rules ) ) {
                return '[]';
            }

            // Basic sanitation loop
            $sanitized_rules = [];
            foreach ( $rules as $rule ) {
                $sanitized_rules[] = array(
                    'iban_number'      => sanitize_text_field( $rule['iban_number'] ?? '' ),
                    'knet_charge'       => sanitize_text_field( $rule['knet_charge'] ?? '' ),
                    'knet_charge_type'           => wc_clean( $rule['knet_charge_type'] ?? '' ), 
                    'cc_charge'    => sanitize_text_field( $rule['cc_charge'] ?? '' ),
                    'cc_charge_type'    => wc_clean( $rule['cc_charge_type'] ?? '' ),
                );
            }

            // Re-encode the sanitized array back into a JSON string for storage
            return json_encode( $sanitized_rules );
        }

        public function getSiteName()
        {
            return __("Woocommerce", $this->domain);
        }

        public function getIsOrderComplete() {  
            $flag = true;   
            if ($this->isOrderComplete == 'no') { 
                $flag = false;  
            }   
            return $flag;   
        }

        public function getMode() {
            $mode = true;
            if ($this->testMode == 'no') {
                $mode = false;
            }
            return $mode;
        }
        
        public function getAPIUrl($apiRoute = "")
        {   
            $url = "https://apiv2api.upayments.com/api/v1/" . $apiRoute;
            if ($this->getMode()) {
                // $url = "https://sandboxapi.upayments.com/api/v1/" . $apiRoute;
                $url = "https://dev-apiv2api.upayments.com/api/v1/" . $apiRoute;
            }
            return $url;
        }

        public function getUserAgent(){
            $userAgent = 'UpaymentsWoocommercePlugin/2.2.1';
            if ($this->getMode()) {
                $userAgent = 'SandboxUpaymentsWoocommercePlugin/2.2.1';
            }
            return $userAgent;
        }
        
        public function getCurrencyCode($code)
        {
            return $code;
        }

        public function encrypt($param)
        {
            return base64_encode($param);
        }

        public function decrypt($param)
        {
            return base64_decode($param);
        }

        public function getApiKey()
        {
            return password_hash($this->apiKey, PASSWORD_BCRYPT);
        }

        public function getCustomerUniqueToken($phone)
        {
            $token = "";
            $phone = trim($phone);
            if (!empty($phone))
            {
                $token = $phone;
                $params = json_encode(["customerUniqueToken" => $token ]);
                $curl = curl_init();
                curl_setopt_array($curl, 
                [
                    CURLOPT_URL => $this->getAPIUrl('create-customer-unique-token') , 
                    CURLOPT_RETURNTRANSFER => true, 
                    CURLOPT_USERAGENT => $this->getUserAgent(), 
                    CURLOPT_ENCODING => "", 
                    CURLOPT_MAXREDIRS => 10, 
                    CURLOPT_TIMEOUT => 0, 
                    CURLOPT_FOLLOWLOCATION => true, 
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, 
                    CURLOPT_CUSTOMREQUEST => "POST", 
                    CURLOPT_POSTFIELDS => $params, 
                    CURLOPT_HTTPHEADER => ["Accept: application/json", "Content-Type: application/json", "Authorization: Bearer " . $this->apiKey ], 
                ]);

                $response = curl_exec($curl);

                if ($response){
                    $result = json_decode($response, true);
                    if ($result["errors"]){
                        $cards = ["error" => 1, "msg" => $result["message"]];
                    }elseif ($result["status"]){
                        $token = $token;
                    }else{
                        $cards = ["error" => 1, "msg" => $result["message"]];
                    }
                }
            }
            return $token;
        }

        public function getUpayPaymentMethods()
        {
            $api_key =  $this->apiKey;
            $payment_methods = null;
            if (!empty($api_key)){
                $curl = curl_init();

                curl_setopt_array($curl, array(
                CURLOPT_URL => $this->getAPIUrl('check-payment-button-status'),
                // uncomment when required
                // CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_USERAGENT => $this->getUserAgent(),
                CURLOPT_HTTPHEADER => array(
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                ),
                ));
                $response = curl_exec($curl);
                if ($response){
                    $result = json_decode($response, true);
                    if($result){
                        if ($result && array_key_exists("status",$result) && $result["status"]){
                            $payment_methods = $result['data'];
                            $payment_methods["result"] = 'success';
                        }else{
                            wc_clear_notices();
                            wc_add_notice(__("UPayments : " . $result["message"] , $this->domain) , $notice_type = "error");
                            return ["result" => "failure", "redirect" => wc_get_checkout_url() , ];
                        }
                    } else {
                        wc_clear_notices();
                        wc_add_notice(__("Error from UPayments : Please Contact support to whitelist your IP" , $this->domain) , $notice_type = "error");
                        return ["result" => "failure", "redirect" => wc_get_checkout_url() , ];
                    }
                }
            }
            return $payment_methods;
        }

        public function getSavedCards(string $phone)
        {
            $api_key =  $this->apiKey;
            $savedCards=null;
            if (!empty($api_key))
            {
                $params = json_encode(["customerUniqueToken" => $phone]);
                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL => $this->getAPIUrl('retrieve-customer-cards'),
                    CURLOPT_RETURNTRANSFER => true,
                    // uncomment when required
                    // curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                    CURLOPT_USERAGENT => $this->getUserAgent(),
                    CURLOPT_ENCODING => "",
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => "POST",
                    CURLOPT_POSTFIELDS => $params,
                    CURLOPT_HTTPHEADER => ["Accept: application/json", "Content-Type: application/json", "Authorization: Bearer " . $this->apiKey], 
                ]);

                $response = curl_exec($curl);
                
                if ($response){
                    $result = json_decode($response, true);
                    if($result && array_key_exists("status",$result) && $result["status"]){
                        $savedCards["data"] = $result['data']['customerCards'];
                        $savedCards["result"] = 'success';
                    }
                }
            }
            return $savedCards;
        }

        public function getPaymentIcons(): ?array
        {
            $data = $this->getUpayPaymentMethods();

            if (($data['result'] ?? '') === 'failure' || empty($data['payButtons'])) {
                return null;
            }

            $payment_methods = $data['payButtons'];

            // Subscription context: feature enabled AND custom type in cart AND NO normal products
            $isSubscriptionContext = ($this->autoDeduction === 'yes')
                && Utils::cartHasCustomType()
                && !Utils::cartHasNormalProduct();

            // Map button keys to their display names
            $availableMethods = [
                'knet'           => ['key' => 'knet',           'title' => __('KNET', $this->domain)],
                'apple_pay_knet' => ['key' => 'apple-pay-knet', 'title' => __('Apple Pay KNET', $this->domain)],
                'credit_card'    => ['key' => 'cc',             'title' => __('Credit Card', $this->domain)],
                'apple_pay'      => ['key' => 'apple-pay',      'title' => __('Apple Pay Credit Card', $this->domain)],
                'samsung_pay'    => ['key' => 'samsung-pay',    'title' => __('Samsung Pay', $this->domain)],
                'google_pay'     => ['key' => 'google-pay',     'title' => __('Google Pay', $this->domain)],
            ];

            $methods = ['payment' => []];

            foreach ($availableMethods as $apiKey => $info) {
                // If subscription context, restrict to credit card only
                if ($isSubscriptionContext && $apiKey !== 'credit_card') {
                    continue;
                }

                // Add method if enabled in API response
                if (!empty($payment_methods[$apiKey]) && $payment_methods[$apiKey] == 1) {
                    $methods['payment'][$info['key']] = $info['title'];
                }
            }

            $methods['whitelabled'] = $data['isWhiteLabel'] ?? false;

            return $methods;
        }

        public function log($content)
        {
            $debug = $this->debug;
            if ($debug)
            {
                $file = UP_PLUGIN_PATH . "debug.log";
                $fp = fopen($file, "a+");
                fwrite($fp, "\n");
                fwrite($fp, date("Y-m-d H:i:s") . ": ");
                fwrite($fp, print_r($content, true));
                fclose($fp);
            }
        }
        
        /**
         * initializeSubscriptionModule
         * Handle Subscription Module Initialization If Enabled from Admin Settings
         * @return void
         */
        public function initializeSubscriptionModule()
        {
            // Always load classes (they self-check enable flag)
            require_once __DIR__ . '/includes/Subscription/Checkout/Fields.php';
            require_once __DIR__ . '/includes/Subscription/Manager.php';
            require_once __DIR__ . '/includes/Subscription/Helpers/Utils.php';            
            Fields::init();
            Manager::init();
        }

        /**
         * Build API payload for invoice / subscription
         *
         * @param WC_Order $order
         * @return array
         */
        protected function build_api_payload($order)
        {
            $payload = [
                'order_id' => $order->get_id(),
                'amount'   => $order->get_total(),
                'currency' => $order->get_currency(),
                'customer' => [
                    'email' => $order->get_billing_email(),
                    'name'  => $order->get_formatted_billing_full_name(),
                ],
            ];

            $plan = $order->get_meta('_upay_subscription_plan');

            if ($plan && $plan !== 'one_time') {

                $interval = (int) $order->get_meta('_upay_subscription_interval');

                $payload['subscription'] = [
                    'enabled'            => true,
                    'type'               => 'recurring',
                    'plan'               => $plan,
                    'interval'           => $interval,
                    'period'             => $plan === 'yearly' ? 'year' : 'month',
                    'start_immediately'  => true,
                ];
            }

            return $payload;
        }

        /**
         * Render subscription summary in admin order view
         *
         * @param WC_Order $order
         * @return array
         */
        public function render_subscription_summary($order)
        {
            $plan     = $order->get_meta('_upay_subscription_plan');
            $interval = (int) $order->get_meta('_upay_subscription_interval');
            $autoDeduction = $order->get_meta('UPayments_AutoDeduction');
            $lastBilled = $order->get_meta('_upay_last_billed_at');
            $order_date = $order->get_date_created();
            $order_paid_date = $order->get_date_paid();
            $order_completed_date = $order->get_date_completed();
            
            $started_at = $order_paid_date ?: $order_completed_date ?: $order_date;
            if (!$started_at) {
                return;
            }
            if (is_string($started_at)) {
                $started_at = new DateTime($started_at);
            }

            // Dates
            $timezone = wp_timezone();
            
            $last_billed_dt = !empty($lastBilled) ? new DateTime($lastBilled, $timezone) : null;
            
            // Calculate next billing
            $next_billing_dt = Scheduler::getNextBillingDate($started_at, $plan, $interval);
            if (!$next_billing_dt) {
                return;
            }

            $SubscriptionStatus = $order->get_meta('_upay_subscription_status');
            if($SubscriptionStatus === 'active') {
                $SubscriptionStatus = '<span class="upay-status-active">'. ucfirst($SubscriptionStatus) .'</span>';
            } elseif($SubscriptionStatus === 'paused') {
                $SubscriptionStatus = '<span class="upay-status-paused">'. ucfirst($SubscriptionStatus) .'</span>';
                } elseif($SubscriptionStatus === 'cancelled') {
                $SubscriptionStatus = '<span class="upay-status-cancelled">'. ucfirst($SubscriptionStatus) .'</span>';
            } else {
                $SubscriptionStatus = ucfirst($SubscriptionStatus);
            }

            if (!$plan || $plan === 'one_time') {
                return;
            }

            $period = '';
            if ($plan === 'yearly') {
                $period = 'Year';
            } elseif($plan === 'monthly') {
                $period = 'Month';
            } elseif($plan === 'weekly') {
                $period = 'Week';
            } else {
                $period = 'Day';
            }

            echo '<div class="upay-subscription-summary">';
            echo '<h4>' . esc_html__('Subscription Details', 'upayments') . '</h4>';
            if($autoDeduction === 'no'){
                echo '<p><strong>Subscription Status:</strong> ' . wp_kses_post($SubscriptionStatus) . '</p>';
            }
            echo '<p><strong>Plan:</strong> ' . esc_html(ucfirst($plan)) . '</p>';
            echo '<p><strong>Interval:</strong> Every ' . esc_html($interval) . ' ' . esc_html($period) . '(s)</p>';
            if($autoDeduction === 'yes' && empty($last_billed_dt)) {
                echo '<p><strong>Auto Deduction Order:</strong> Yes</p>';
            } else {
                if($SubscriptionStatus !== 'cancelled') {
                    echo '<p><strong>Next Billing Date:</strong> ' . esc_html($next_billing_dt->format('Y-m-d H:i:s')) . '</p>';
                }
                if(!empty($last_billed_dt)){ 
                    echo '<p><strong>Last Billed at:</strong> ' . esc_html($last_billed_dt->format('Y-m-d H:i:s')) . '</p>';
                }
            }
            echo '</div>';
        }

        /**
         * restrictMixedCartProducts
         * Function to restrict adding subscription products together with normal products in the cart
         * @param  mixed $passed
         * @param  mixed $product_id
         * @param  mixed $quantity
         * @return void
         */
        public function restrictMixedCartProducts($passed, $product_id, $quantity)
        {
            if (!function_exists('WC') || !WC()->cart) {
                return $passed;
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                return $passed;
            }

            $is_subscription_product = ($product->get_type() === 'custom_type');

            // Current cart state
            $cart_has_subscription = Utils::cartHasCustomType();
            $cart_has_normal       = Utils::cartHasNormalProduct();

            // If cart already has subscription product, block adding normal products
            if ($cart_has_subscription && !$is_subscription_product) {
                wc_add_notice(
                    __('You can only add subscription products to the cart when a subscription item is present.', $this->domain),
                    'error'
                );
                return false;
            }

            // If cart already has normal products, block adding subscription products
            if ($cart_has_normal && $is_subscription_product) {
                wc_add_notice(
                    __('Subscription products cannot be added together with normal products. Please complete your current purchase first.', $this->domain),
                    'error'
                );
                return false;
            }

            return $passed;
        }
        
        /**
         * renderSubscriptionBadgeInProductList
         * Function to render subscription badge in product list if the product is subscription type
         * @return void
         */
        public function renderSubscriptionBadgeInProductList()
        {
            global $product;

            if (!$product instanceof WC_Product) {
                return;
            }

            if ($product->get_type() !== 'custom_type') {
                return;
            }

            echo '<span class="upay-subscription-badge"><strong>🔁 Subscription</strong></span>';
        }
    }

    add_action(
        'woocommerce_admin_order_data_after_billing_address',
        function($order) {
            foreach ($order->get_items('line_item') as $item)
            {
                $product = $item->get_product();
                if($product->get_type() === 'custom_type'){
                    $gateway = new WC_Upayments();
                    $gateway->render_subscription_summary($order);
                }
            }
        },
        10, 1
    );
}

/**
 * upaymentsMissingWcNotice
 * If Woocommerce Plugin is not active/installed show admin notice to install/activate Woocommerce
 * @return void
 */
function upaymentsMissingWcNotice() {
    ?>
    <div class="error notice">
        <p><?php _e( '<b>UPayments Gateway</b> requires WooCommerce to be installed and active!', 'upayments' ); ?></p>
    </div>
    <?php
}

add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods)
{
    $methods[] = "WC_UPayments";
    return $methods;
}

add_filter("woocommerce_available_payment_gateways", "enableUpaymentsGateway");
function enableUpaymentsGateway($available_gateways)
{
    if (is_admin()){
        return $available_gateways;
    }

    if (isset($available_gateways["upayments"])){
        // Move UPayments to the end unless merchant explicitly reordered
        $upay = $available_gateways['upayments'];
        unset($available_gateways['upayments']);
        $available_gateways['upayments'] = $upay;

        $settings = get_option("woocommerce_upayments_settings");

        if (empty($settings["api_key"])){
            unset($available_gateways["upayments"]);
        }

        if (is_checkout() && isset($available_gateways['cod']) && (isset($settings['enable_autodeduction']) && $settings['enable_autodeduction'] === 'yes')) {
            unset($available_gateways['cod']);
        }

        if (WC()->session->get('chosen_payment_method') === 'upayments' && (isset($settings['make_default_gateway']) && $settings['make_default_gateway'] !== 'yes')) {
            WC()->session->set('chosen_payment_method', null);
        }
    }

    $supported_currencies = ["KWD", "SAR", "USD", "BHD", "EUR", "OMR", "QAR", "AED", ];
    if (!in_array(get_woocommerce_currency() , $supported_currencies)){
        unset($available_gateways["upayments"]);
    }
    
    return $available_gateways;
}

add_action('admin_head', function () {
    ?>
    <style>
        /* hide the entire row if input is hidden */
        .woocommerce table.form-table tr:has(input[style*="display:none"]) {
            display: none;
        }
        .upay-status-active { color: #2ecc71; font-weight: 600; }
        .upay-status-paused { color: #f39c12; font-weight: 600; }
        .upay-status-cancelled { color: #e74c3c; font-weight: 600; }
    </style>
    <?php
});

// Declare compatibility with WooCommerce's Cart & Checkout blocks (WooBlocks)
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
    }
});

// payment method registry
add_action( 'woocommerce_blocks_loaded', function() {
    add_action( 'woocommerce_blocks_payment_method_type_registration', function( $payment_method_registry ) {
        require_once __DIR__ . '/includes/class-wc-gateway-upayments-blocks.php';
        $payment_method_registry->register(
            new WCGatewayUPaymentsBlocks( __FILE__ )
        );
    });
});

register_activation_hook(__FILE__, 'myPaymentPluginSetupCheckout');
function myPaymentPluginSetupCheckout() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'upaymentsMissingWcNotice' );
        return;
    }
    $checkout_page_id = wc_get_page_id('checkout');
    if (!$checkout_page_id) {
        return;
    }

    $post = get_post($checkout_page_id);
    if (!$post) {
        return;
    }

    $has_shortcode = has_shortcode($post->post_content, 'woocommerce_checkout');
    $has_block     = has_block('woocommerce/checkout', $post->post_content);

    $use_blocks = false;
    $settings = get_option("woocommerce_upayments_settings");
    if (isset($settings['enable_block_checkout']) && $settings['enable_block_checkout'] === 'yes') {
        $use_blocks = true;
    }

    if (!$has_shortcode && !$has_block && !$use_blocks) {
        wp_update_post([
            'ID'           => $checkout_page_id,
            'post_content' => '[woocommerce_checkout]', // default: classic
        ]);
    }
}

/* Subscription Product Data Handler from product Data Page - Start */
add_action( 'init', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }
    if ( class_exists( 'WC_Product_Simple' ) ) {
        class WCProductCustomType extends WC_Product_Simple {
            public function get_type() {
                return 'custom_type';
            }
        }
    }
});

add_filter( 'product_type_selector', 'addCustomProductType' );
function addCustomProductType( $types ){
    $types[ 'custom_type' ] = __( 'Subscription Product', 'upayments' );
    return $types;
}

add_filter( 'woocommerce_product_class', 'mapCustomProductClass', 10, 2 );
function mapCustomProductClass( $classname, $product_type ) {
    if ( $product_type === 'custom_type' ) { // Must match the key in your dropdown
        $classname = 'WCProductCustomType';
    }
    return $classname;
}

add_action( 'woocommerce_custom_type_add_to_cart', 'woocommerce_simple_add_to_cart', 30 );

add_action( 'admin_footer', 'customProductTypes' );
function customProductTypes() {
    if ( 'product' != get_post_type() ) { return ;}
    ?>
    <script type='text/javascript'>
        jQuery( document ).ready( function() {
            // Options like 'virtual' or 'downloadable' can be shown/hidden
            // or specific tabs can be toggled.
            jQuery( '.options_group.pricing' ).addClass( 'show_if_custom_type' );
            jQuery( '.inventory_options' ).addClass( 'show_if_custom_type' );
            
            // Force WooCommerce to trigger the show/hide logic
            jQuery( 'select#product-type' ).change();
            jQuery('.show_if_simple').addClass('show_if_custom_type');
        });
    </script>
    <?php
}

add_filter( 'woocommerce_product_data_tabs', 'addCustomDataTab' );
function addCustomDataTab( $tabs ) {
    $tabs['custom_settings'] = array(
        'label'    => __( 'Custom Settings', 'upayments' ),
        'target'   => 'custom_product_data_panel', // This matches the ID in the next step
        'class'    => array( 'show_if_custom_type' ), // Only show for your product type
        'priority' => 25,
    );
    return $tabs;
}

add_action( 'woocommerce_product_data_panels', 'addCustomDataPanel' );
function addCustomDataPanel() {
    ?>
    <div id="custom_product_data_panel" class="panel woocommerce_options_panel hidden">
        <div class="options_group">
            <?php
            // Create a custom text field
            woocommerce_wp_text_input( array(
                'id'          => '_custom_field_id',
                'label'       => __( 'Custom Field', 'upayments' ),
                'placeholder' => 'Enter value here',
                'desc_tip'    => 'true',
                'description' => __( 'This is a description of the field.', 'upayments' ),
            ) );
            ?>
        </div>
    </div>
    <?php
}

add_action( 'woocommerce_process_product_meta', 'saveCustomFieldData' );
function saveCustomFieldData( $post_id ) {
    $custom_field_value = isset( $_POST['_custom_field_id'] ) ? $_POST['_custom_field_id'] : '';
    
    if ( ! empty( $custom_field_value ) ) {
        update_post_meta( $post_id, '_custom_field_id', sanitize_text_field( $custom_field_value ) );
    }
}

add_action( 'woocommerce_single_product_summary', 'displayCustomFieldOnFrontend', 10 );
function displayCustomFieldOnFrontend() {
    global $product;

    // 1. Check if the product exists and is your custom type
    if ( ! is_object( $product ) || ! $product->is_type( 'custom_type' ) ) {
        return;
    }

    if ( $product->is_type( 'custom_type' ) ) {
        // 2. Fetch the data using the field ID we used during the save process
        $custom_data = get_post_meta( $product->get_id(), '_custom_field_id', true );
    
        // 3. Output the data safely
        if ( ! empty( $custom_data ) ) {
            echo '<div class="custom-product-info">';
            echo '<strong style="background: #ffcc00; padding: 5px 10px; border-radius: 3px;">' . esc_html( $custom_data ) . '</strong>';
            echo '</div>';
        }
    }

}

add_filter( 'woocommerce_get_item_data', 'displayCustomDataInCart', 10, 2 );
function displayCustomDataInCart( $item_data, $cart_item ) {
    // 1. Get the product ID from the cart item
    $product_id = $cart_item['product_id'];
    
    // 2. Fetch the custom meta
    $custom_value = get_post_meta( $product_id, '_custom_field_id', true );

    // 3. Add it to the display array if it exists
    if ( ! empty( $custom_value ) ) {
        $item_data[] = array(
            'key'     => __( 'Special Feature', 'upayments' ),
            'value'   => $custom_value,
            'display' => '', // Optional: format for display
        );
    }

    return $item_data;
}

add_action( 'woocommerce_checkout_create_order_line_item', 'saveCustomDataToOrderItems', 10, 4 );
function saveCustomDataToOrderItems( $item, $cart_item_key, $values, $order ) {
    // 1. Get the product ID
    $product_id = $values['product_id'];
    
    // 2. Fetch the custom meta from the product
    $custom_value = get_post_meta( $product_id, '_custom_field_id', true );

    // 3. Add the meta to the order item
    if ( ! empty( $custom_value ) ) {
        $item->add_meta_data( __( 'Special Feature', 'upayments' ), $custom_value );
    }
}

add_action('woocommerce_order_details_after_order_table', function ($order) {

    if (!$order instanceof WC_Order || !is_user_logged_in()) {
        return;
    }

    if ((int) $order->get_user_id() !== get_current_user_id()) {
        return;
    }

    if ($order->get_meta('_upay_subscription_status') === 'cancelled') {
        return;
    }

    // Subscription meta
    $plan       = $order->get_meta('_upay_subscription_plan');
    $interval   = (int) $order->get_meta('_upay_subscription_interval');
    if (!$plan) {return;}
    
    $order_date = $order->get_date_created();
    $order_paid_date = $order->get_date_paid();
    $order_completed_date = $order->get_date_completed();
    $last_billed = $order->get_meta('_upay_last_billed_at');
    $isAutoDeduction = $order->get_meta('UPayments_AutoDeduction') === 'yes' ? true : false;
    $started_at = $order_paid_date ?: $order_completed_date ?: $order_date;
    if (!$started_at) {
        return;
    }
    if (is_string($started_at)) {
        $started_at = new DateTime($started_at);
    }
    
    // Dates
    $timezone = wp_timezone();
    
    $last_billed_dt = !empty($last_billed) ? new DateTime($last_billed, $timezone) : null;
        
    // Calculate next billing
    $next_billing_dt = Scheduler::getNextBillingDate($started_at, $plan, $interval);
    if (!$next_billing_dt) {
        return;
    }

    // Not a subscription order → don’t show anything
    if (!$plan || !$interval) {
        return;
    }

    // Format labels
    $plan_labels = [
        'daily'     => 'Daily',
        'weekly'    => 'Weekly',
        'monthly'   => 'Monthly',
        'quarterly' => 'Quarterly',
        'yearly'    => 'Yearly',
    ];

    $interval_labels = [
        'daily' => [
            1 => 'Every Day',
        ],
        'weekly' => [
            1 => 'Every Week',
            2 => 'Every 2 Weeks',
            3 => 'Every 3 Weeks',
        ],
        'monthly' => [
            1 => 'Every Month',
            2 => 'Every 2 Months',
        ],
        'quarterly' => [
            1 => 'Every Quarter',
            2 => 'Every 2 Quarters',
            3 => 'Every 3 Quarters',
        ],
        'yearly' => [
            1 => 'Every Year',
        ],
    ];
    ?>

    <section class="woocommerce-subscription-details">
        <h2><?php esc_html_e('Subscription Details', 'woocommerce'); ?></h2>

        <table class="shop_table shop_table_responsive" style="border: 1px solid;">
            <tbody>
                <tr>
                    <th style="border: 1px solid;"><?php esc_html_e('Plan', 'woocommerce'); ?></th>
                    <td style="border: 1px solid;"><?php echo esc_html($plan_labels[$plan] ?? ucfirst($plan)); ?></td>
                </tr>
                <tr>
                    <th style="border: 1px solid;"><?php esc_html_e('Interval', 'woocommerce'); ?></th>
                    <td style="border: 1px solid;"><?php echo esc_html($interval_labels[$plan][$interval] ?? $interval); ?></td>
                </tr>
                <tr>
                    <th style="border: 1px solid;"><?php esc_html_e('Started On', 'woocommerce'); ?></th>
                    <td style="border: 1px solid;"><?php echo esc_html($started_at ? $started_at->format('Y-m-d H:i:s') : '-'); ?></td>
                </tr>
                <?php if(!$isAutoDeduction) { ?>
                    <tr>
                        <th style="border: 1px solid;"><?php esc_html_e('Last Billed On', 'woocommerce'); ?></th>
                        <td style="border: 1px solid;"><?php echo esc_html($last_billed_dt ? $last_billed_dt->format('Y-m-d H:i:s') : '-'); ?></td>
                    </tr>
                    <tr>
                        <th style="border: 1px solid;"><?php esc_html_e('Next Billing Date', 'woocommerce'); ?></th>
                        <td style="border: 1px solid;"><?php echo esc_html($next_billing_dt ? $next_billing_dt->format('Y-m-d H:i:s') : '-'); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </section>

    <?php
        $isAutoDeductionOrder = $order->get_meta('UPayments_AutoDeduction') === 'yes' ? true : false;
        if (!$isAutoDeductionOrder) {
            $unsubscribe_url = wp_nonce_url(
                add_query_arg([
                    'upay_action' => 'unsubscribe',
                    'order_id'    => $order->get_id(),
                ], wc_get_account_endpoint_url('view-order')),
                'upay_unsubscribe_' . $order->get_id()
            );
    ?>
    <p class="upay-subscription-actions">
        <a href="<?php echo esc_url($unsubscribe_url); ?>"
            class="button upay-unsubscribe-button"
            onclick="return confirm('<?php esc_attr_e('Are you sure you want to unsubscribe?', 'woocommerce'); ?>');">
            <?php esc_html_e('Unsubscribe', 'woocommerce'); ?>
        </a>
    </p>

    <?php
            $status = $order->get_meta('_upay_subscription_status') ?: 'active';
            $action = $status === 'paused' ? 'resume' : 'pause';
            $label  = $status === 'paused' ? 'Resume Subscription' : 'Pause Subscription';

            $url = wp_nonce_url(
                add_query_arg([
                    'upay_action' => $action,
                    'order_id'    => $order->get_id(),
                ], wc_get_account_endpoint_url('view-order')),
                'upay_' . $action . '_' . $order->get_id()
            );
    ?>
    <p class="upay-subscription-actions">
        <a href="<?php echo esc_url($url); ?>" class="button upay-pause-resume-button">
            <?php echo esc_html($label); ?>
        </a>
    </p>
    <?php
    }
});

add_action('woocommerce_before_account_orders', function () {
    $current = sanitize_text_field($_GET['subscription_filter'] ?? '');
    ?>
    <form method="get" class="upay-orders-filter" action="<?php echo esc_url( add_query_arg( null, null ) ); ?>">
        <input type="hidden" name="page_id" value="<?php echo esc_attr($_GET['page_id'] ?? 12); ?>">
        <input type="hidden" name="orders" value="">

        <label for="subscription_filter">Select Order Type:</label>
        <select id="subscription_filter" name="subscription_filter" onchange="this.form.submit()">
            <option value="">All orders</option>
            <option value="active" <?php selected($current, 'active'); ?>>Active subscriptions</option>
            <option value="paused" <?php selected($current, 'paused'); ?>>Paused subscriptions</option>
            <option value="cancelled" <?php selected($current, 'cancelled'); ?>>Cancelled subscriptions</option>
        </select>
    </form>
    <?php
});

add_filter('woocommerce_my_account_my_orders_query', function ($args) {
    if (empty($_GET['subscription_filter'])) {
        return $args;
    }
    $filter = sanitize_text_field($_GET['subscription_filter']);
    $args['meta_query'][] = [
        'key'   => '_upay_subscription_status',
        'value' => $filter,
    ];
    return $args;
});

add_filter('woocommerce_my_account_my_orders_columns', function ($columns) {
    $new_columns = [];
    foreach ($columns as $key => $label) {
        $new_columns[$key] = $label;

        if ($key === 'order-status') {
            $new_columns['order_type'] = __('Type', 'woocommerce');
            $new_columns['order_status'] = __('Status', 'woocommerce');
        }
    }
    return $new_columns;
});

add_action('woocommerce_my_account_my_orders_column_order_type', function ($order) {
    $isAutoDeduction = $order->get_meta('UPayments_AutoDeduction') === 'yes' ? true : false;
    echo $isAutoDeduction ? __('Auto Deduction', 'woocommerce') : __('Regular', 'woocommerce');
});

add_action('woocommerce_my_account_my_orders_column_order_status', function ($order) {
    $status = $order->get_meta('_upay_subscription_status');
    if (!$status) {
        echo '—';
        return;
    }
    echo '<span class="upay-status upay-status-' . esc_attr($status) . '">' . esc_html(ucfirst($status)) . '</span>';
});
/* Subscription Product Data Handler from product Data Page - End */

add_action('woocommerce_init', function () {
    require_once __DIR__ . '/includes/Subscription/Cron/Scheduler.php';
    Scheduler::init();
});

add_action('init', function () {
    update_option('woocommerce_checkout_phone_field', 'required');
    if (!wp_next_scheduled('upay_hourly_cron_job')) {
        wp_schedule_event(time(), 'hourly', 'upay_hourly_cron_job');
    }

    $action = isset($_GET['upay_action']) ? $_GET['upay_action'] : '';
    $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : '';

    if(!empty($action) && !empty($order_id)) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Security checks
        if (!is_user_logged_in() || get_current_user_id() !== $order->get_user_id()) {
            wc_add_notice(__('Unauthorized request.', 'woocommerce'), 'error');
            wp_safe_redirect(wc_get_account_endpoint_url('orders'));
            exit;
        }

        if($action === 'unsubscribe'){
            if (!wp_verify_nonce($_GET['_wpnonce'], 'upay_unsubscribe_' . $order_id)) {
                wc_add_notice(__('Invalid request.', 'woocommerce'), 'error');
                wp_safe_redirect(wc_get_account_endpoint_url('orders'));
                exit;
            }
    
            // Mark subscription as cancelled
            $order->update_meta_data('_upay_subscription_status', 'cancelled');
            wc_add_notice(__('Your subscription has been cancelled.', 'woocommerce'), 'success');
        }
        
        if ($action === 'pause') {
            $order->update_meta_data('_upay_subscription_status', 'paused');
            wc_add_notice(__('Subscription paused.', 'woocommerce'), 'success');
        }

        if ($action === 'resume') {
            $order->update_meta_data('_upay_subscription_status', 'active');
            wc_add_notice(__('Subscription resumed.', 'woocommerce'), 'success');
        }

        $order->save();
        wp_safe_redirect(wc_get_account_endpoint_url('view-order') . $order_id);
        exit;
    }
});

add_action('upay_hourly_cron_job', 'runCustomCron');
function runCustomCron() {    
    error_log('cron Execution started at ' . current_time('Y-m-d H:i:s'));
    do_action('upay_process_subscriptions');
}
