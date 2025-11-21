<?php
/*
Plugin Name: UPayments
Description: UPayments Plugin allows merchants to accept KNET, Cards, Samsung Pay, Apple Pay, Google Pay Payments.
Version: 2.1.5
Requires at least: 4.0
WC requires at least: 2.4
PHP Requires  at least: 5.5
Author: <a href="https://upayments.com/>UPayments Company</a>
Author URI: https://upayments.com/
License: MIT
*/

if (!defined("ABSPATH"))
{
    exit(); // Exit if accessed directly

}

define("UPayments_PLUGIN_URL", plugin_dir_url(__FILE__));
define("UPayments_PLUGIN_PATH", plugin_dir_path(__FILE__));

/**
 * Initiate UPayments once plugin is ready
 */
add_action("plugins_loaded", "woocommerce_upayments_init");

function woocommerce_upayments_init()
{
    class WC_UPayments extends WC_Payment_Gateway
    {
        public $domain;

        /**
         * Constructor for the gateway.
         */
        public function __construct()
        {
            $this->domain = "upayments";

            $this->id = "upayments";
            $this->icon = UPayments_PLUGIN_URL . "assets/images/logo.png";
            $this->has_fields = false;
            $this->method_title = __("UPayments", $this->domain);
            $this->method_description = __("UPayments Plugin allows merchants to accept KNET, Cards, Samsung Pay, Apple Pay, Google Pay Payments.", $this->domain);

            // Define user set variables
           // $this->title = $this->get_option("title");
            $this->title = '';
            $this->description = $this->get_option("description");
            $this->debug = $this->get_option("debug");
            $this->api_key = $this->get_option("api_key");
            $this->is_order_complete = $this->get_option('is_order_complete');
            $this->test_mode = $this->get_option("test_mode");
            $this->from_plugin_enabled = false;
            $this->payment_data = null;

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Actions
            add_action("woocommerce_update_options_payment_gateways_" . $this->id, [$this, "process_admin_options"]);
            add_filter("woocommerce_get_order_item_totals", [$this, "add_order_item_totals"], 10, 3);
            add_action("woocommerce_thankyou_" . $this->id, [$this, "thankyou_page", ]);
            add_action("woocommerce_api_" . strtolower("WC_UPayments") , [$this, "check_ipn_response", ]);
            add_filter("woocommerce_gateway_icon", [$this, "custom_payment_gateway_icons"], 10, 2);
            add_action("woocommerce_admin_order_data_after_order_details", [$this, "admin_order_details"], 10, 3);
            add_action("admin_footer", [$this, "UPayments_admin_footer"], 10, 3);
            add_action('wp_enqueue_scripts', array($this,'enqueue_my_plugin_styles'));
            add_action('wp_enqueue_scripts', array($this, 'enqueue_custom_checkout_script'));

        }
        function enqueue_my_plugin_styles() {
           // Enqueue Google Fonts
            wp_enqueue_style('google-fonts', 'https://fonts.googleapis.com/css2?family=Almarai&display=swap');
            wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css');
        }

        function enqueue_custom_checkout_script() {
            if (is_checkout() && !is_wc_endpoint_url()) {
                wp_enqueue_script('custom-checkout-script', plugin_dir_url(__FILE__) . 'assets/js/upay.js', array('jquery'), '1.0', true);
                //wp_localize_script('my-script', 'my_ajax_obj', array('site_url' => site_url()));
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
            foreach (WC()
                ->payment_gateways
                ->get_available_payment_gateways() as $gateway)
            {
                if ($gateway->id == $gateway_id)
                {
                    $title = $gateway->get_title();
                    break;
                }
            }
            if ($gateway_id == "upayments")
            {
                $icon = '<span>Pay securely with <img src="' . UPayments_PLUGIN_URL . 'assets/images/upayment.png" alt="UPayemnts"  title="UPayments" style="height: 24px !important; padding-left:4px;"/></span>';
            }
            return $icon;

        }

        /**
         * Initialize Gateway Settings Form Fields.
         */
        public function init_form_fields()
        {
            $countries_obj = new WC_Countries();
            $countries = $countries_obj->__get("countries");

            $field_arr = ["enabled" => ["title" => __("Active", $this->domain) , "type" => "checkbox", "label" => __(" ", $this->domain) , "default" => "yes", ],
            "title" => ["title" => __("Title", $this->domain) , "type" => "text", "description" => __("This controls the title which the user sees during checkout.", $this->domain) , "default" => $this->method_title, "desc_tip" => true, ],
            "description" => ["title" => __("Description", $this->domain) , "type" => "textarea", "description" => __("Instructions that the customer will see on your checkout.", $this->domain) , "default" => $this->method_description, "desc_tip" => true, ],
            "api_key" => ["title" => __("Api Key", $this->domain) , "type" => "text", "description" => __("Copy/paste values from UPayments dashboard", $this->domain) , "default" => "", "desc_tip" => true, ],
            "debug" => ["title" => __("Debug", $this->domain) , "type" => "checkbox", "label" => __(" ", $this->domain) , "default" => "no", ],
            "test_mode" => ["title" => __("Test Mode", $this->domain) , "type" => "checkbox", "label" => __(" ", $this->domain) , "default" => "no", ],
            'is_order_complete' => array(
                'title' => __('Show paid orders as "Completed"?', $this->domain),
                'type' => 'checkbox',
                'label' => __(' ', $this->domain),
                'default' => 'yes'
            ),];


            $this->form_fields = $field_arr;
        }

        /**
         * Process Gateway Settings Form Fields.
         */
        public function process_admin_options()
        {
            $this->init_settings();
            $post_data = $this->get_post_data();
            if (empty($post_data["woocommerce_upayments_api_key"]))
            {
                WC_Admin_Settings::add_error(__("Please enter UPayments API Key", $this->domain));
            }
            else
            {
                foreach ($this->get_form_fields() as $key => $field)
                {
                    $setting_value = $this->get_field_value($key, $field, $post_data);
                    $this->settings[$key] = $setting_value;
                }
                delete_option("upayments_maat");
                return update_option($this->get_option_key() , apply_filters("woocommerce_settings_api_sanitized_fields_" . $this->id, $this->settings));
            }
        }

        function payment_fields()
        {
        ?>
       <div class="form-row form-row-wide">
        <style>
            .upay-payment-method {
                border: 1px solid #D9D9D9;
                border-radius: 5px;
                padding: 8px;
                display: flex;
                align-items: center;
                width: 100%;
                background-color:#fff;
                margin: 8px 0px;
            }

            .payment-method-label {
                margin-left: 5px;
                color:#98999A !important;
                text-transform: none;
                font-weight: normal !important;
            }

            .payment-method-price {
                flex: 1 0 0;
                text-align:right;
                color:#1B1D21;
            }

            .payment-method-icon2 {
                color: #1B1D21;
                margin-left: 5px;
            }

            .upay-payment-method:hover {
                background-color:#fff;
                border: 1px solid #D9D9D9;
                box-shadow: 0 4px 3px rgba(0, 0, 0, 0.07), 0 2px 2px rgba(0, 0, 0, 0.06) !important;
            }
        </style>
         <!--p><?php echo $this->description; ?></p-->

                <?php if (isset($_REQUEST["cancelled"]))
            { ?>
                <script>
                    let message = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error alert-color"><?php echo __("Payment canceled by customer", $this->domain); ?></div></div>';
                    jQuery(document).ready(function(){
                        jQuery('.woocommerce-notices-wrapper:first').html(message);
                    });
                </script>
                <?php
            }
            elseif (isset($_REQUEST["failed"]))
            { ?>
                <script>
                    let message = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error alert-color"><?php echo __("Payment error from UPayments", $this->domain); ?></div></div>';
                    jQuery(document).ready(function(){
                        jQuery('.woocommerce-notices-wrapper:first').html(message);
                    });
                </script>
                <?php
            }
            elseif (isset($_REQUEST["suspected"]))
            { ?>
                <script>
                    let message = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error alert-color"><?php echo __("Payment failed for suspected fraud.", $this->domain); ?></div></div>';
                    jQuery(document).ready(function(){
                        jQuery('.woocommerce-notices-wrapper:first').html(message);
                    });
                </script>
                <?php
            } ?>


            <?php

            $total = "0";
            $total = WC()->cart->get_total('');
            $language=get_locale();
            $currency = get_woocommerce_currency_symbol();
            if (strpos($language, 'en') === 0) {
                $currency = get_woocommerce_currency();
            }
            $whitelabled = false;
            $payment_data = $this->getPaymentIcons();
            if($payment_data){
                $this->payment_data = $payment_data;
                $icons = $payment_data['payment'];
                $whitelabled = $payment_data['whitelabled'];
            }
            if($whitelabled == true){
            ?>
                <div class="payment-buttons">

                <?php
                foreach ($icons as $key => $value) {
                ?>
                    <button type="button" onclick="submitUpayButton('<?php echo esc_attr($key);?>')" class="upay-payment-method" id="upay-button-<?php echo esc_attr($key);?>">
                    <span class="payment-method-icon">

                    <?php
                                if($key == 'apple-pay-knet') {
                                    ?>
                                    <img src="<?php echo UPayments_PLUGIN_URL;?>assets/images/<?php echo esc_attr('apple-pay');?>.png" alt="<?php echo esc_attr($value);?>"  title="<?php echo esc_attr($value);?>"/>
                                     <img src="<?php echo UPayments_PLUGIN_URL;?>assets/images/<?php echo esc_attr('knet');?>.png" alt="<?php echo esc_attr($value);?>"  title="<?php echo esc_attr($value);?>"/>
                                    <?php
                                } elseif($key == 'apple-pay') {
                                    ?>
                                        <img src="<?php echo UPayments_PLUGIN_URL;?>assets/images/<?php echo esc_attr('apple-pay');?>.png" alt="<?php echo esc_attr($value);?>"  title="<?php echo esc_attr($value);?>"/>
                                        <img src="<?php echo UPayments_PLUGIN_URL;?>assets/images/<?php echo esc_attr('cc');?>.png" alt="<?php echo esc_attr($value);?>"  title="<?php echo esc_attr($value);?>"/>
                                    <?php
                                } else {
                                    ?>
                                        <img src="<?php echo UPayments_PLUGIN_URL;?>assets/images/<?php echo esc_attr($key);?>.png" alt="<?php echo esc_attr($value);?>"  title="<?php echo esc_attr($value);?>"/>
                                    <?php
                                }
                                ?>
                    </span>
                    <span class="payment-method-label"><?php echo esc_attr($value);?></span>
                    <span class="payment-method-price"><?php echo $total;?> <?php echo $currency;?></span>
                    <span class="payment-method-icon2"><i class="fa fa-chevron-right"></i></span>
                    </button>

                <?php
                }
                ?>

                </div>
            <?php
            } else {
                ?>
                <div class="payment-buttons">
                <button type="button" onclick="submitUpayButton('knet')" class="upay-payment-method">

                <?php
                foreach ($icons as $key => $value) {
                ?>
                    <span class="payment-method-icon" style="margin-right: 5px;" id="upay-button-<?php echo esc_attr($key);?>"><img src="<?php echo UPayments_PLUGIN_URL;?>assets/images/<?php echo esc_attr($key);?>.png" alt="<?php echo esc_attr($value);?>"  title="<?php echo esc_attr($value);?>"/></span>
                    <?php
                }
                ?>
                <span class="payment-method-price"><?php echo $total;?> <?php echo $currency;?></span>
                <span class="payment-method-icon2"><i class="fa fa-chevron-right"></i></span>
                </button>
                </div>
            <?php
            }
            ?>
            <input id="upayment_payment_type" type="hidden" name="upayment_payment_type" value="upayments"/>
            </div>
        <?php
        }

        public function add_order_item_totals($total_rows, $order, $tax_display)
        {
            $payment_status = get_post_meta($order->get_id() , "UPayments_Result", true);
            $upayment_id = get_post_meta($order->get_id() , "UPayments_PaymentID", true);

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
        public function thankyou_page($order_id)
        {
            $order = new WC_Order($order_id);

            $payment_status = get_post_meta($order_id, "UPayments_Result", true);
            $upayment_id = get_post_meta($order_id, "UPayments_PaymentID", true);

            $style = "width: 100%;  margin-bottom: 1rem; background: #212b5f; padding: 20px; color: #fff; font-size: 22px;";
            if (isset($_GET["status"]))
            {
                $status = sanitize_text_field($_GET["status"]);

                if ($status == "canceled")
                {
                    $status = $order->get_status();
                    if ($status == "processing")
                    {
                        $status = "completed";
                    }
                    else
                    {
                        $reference = sanitize_text_field($_GET["reference"]);

                        $status_message = __("Order cancelled by UPayments.", $this->domain) . ($reference ? " Reference: " . $reference : "");
                        $order->update_status("cancelled", $status_message);

                        $order->add_meta_data("UPayments_reference", $reference);
                        $order->save_meta_data();
                    }
                }

                if ($status == "completed")
                {
                    $status = "wait";
                }
            }

            if ($status != "wait")
            {
                $status = $order->get_status();
            }
            ?>
            <script>
                let is_status_received = false;
                let upayments_status_ajax_url = '<?php echo site_url() . "/?wc-api=wc_upayments&get_order_status=1"; ?>';
                jQuery(document).ready(function(){

                    jQuery(jQuery('.upayment-status-holder').html()).insertAfter('.woocommerce-order-overview__payment-method');
                    jQuery(jQuery('.upayment-id-holder').html()).insertAfter('.woocommerce-order-overview__payment-status');

                    jQuery('.entry-header .entry-title').html('<?php echo __("Order Status", $this->domain); ?>');
                    jQuery('.woocommerce-thankyou-order-received').hide();
                    jQuery('.woocommerce-thankyou-order-details').hide();
                    jQuery('.woocommerce-order-details').hide();
                    jQuery('.woocommerce-customer-details').hide();

                    show_upayments_status();
                });

                function show_upayments_status(type='') {
                    jQuery('.payment-panel-wait').hide();
                    <?php if ($status == "completed" || $status == "pending")
            { ?>
                    jQuery('.woocommerce-thankyou-order-received').show();
                    jQuery('.woocommerce-thankyou-order-details').show();
                    <?php
            } ?>
                    jQuery('.woocommerce-order-details').show();
                    jQuery('.woocommerce-customer-details').show();
                    if (type.length > 0) {
                        jQuery('.payment-panel-'+type).show();
                    }
                 }
            </script>
            <?php if ($status == "wait")
            { ?>
            <style>
                .payment-panel-wait .img-container {
                    text-align: center;
                }
                .payment-panel-wait .img-container img{
                    display: inline-block !important;
                }
            </style>
            <script>
                jQuery(document).ready(function(){
                    check_upayments_payment_status();

                    function check_upayments_payment_status() {

                         function upayments_status_loop() {
                             if (is_status_received) {
                                 return;
                             }

                             if (typeof(upayments_status_ajax_url) !== "undefined") {
                                 jQuery.getJSON(upayments_status_ajax_url, {'order_id' : <?php echo $order_id; ?>}, function (data) {
                                     if (data.status == 'wait') {-
                                        setTimeout(upayments_status_loop, 2000);
                                     } else if (data.status == 'error') {
                                        show_upayments_status('error');
                                        is_status_received = true;
                                     } else if (data.status == 'pending') {
                                        show_upayments_status('pending');
                                        is_status_received = true;
                                     } else if (data.status == 'failed') {
                                        show_upayments_status('failed');
                                        is_status_received = true;
                                     } else if (data.status == 'completed') {
                                        show_upayments_status('completed');
                                        is_status_received = true;
                                     }
                                });
                             }
                         }
                         upayments_status_loop();
                     }
                });
            </script>
            <div class="payment-panel-wait">
                <h3><?php echo __("We are retrieving your payment status from UPayments, please wait...", $this->domain); ?></h3>
                <div class="img-container"><img src="<?php echo UPayments_PLUGIN_URL; ?>assets/images/loader.gif" /></div>
            </div>
            <?php
            } ?>

            <div class="payment-panel-pending" style="<?php echo $status == "pending" ? "display: block" : "display: none"; ?>">
                <div style="<?php echo $style; ?>">
                <?php echo __("Your payment status is pending, we will update the status as soon as we receive notification from UPayments.", $this->domain); ?>
                </div>
            </div>

            <div class="payment-panel-completed" style="<?php echo $status == "completed" ? "display: block" : "display: none"; ?>">
                <div style="<?php echo $style; ?>">
                <?php echo __("Your payment is successful with UPayments.", $this->domain); ?>
                    <img style="width:100px" src="<?php echo UPayments_PLUGIN_URL; ?>assets/images/check.png"  />
                </div>
            </div>

             <div class="payment-panel-failed" style="<?php echo $status == "failed" ? "display: block" : "display: none"; ?>">
                <div style="<?php echo $style; ?>">
                <?php echo __("Your payment is failed with UPayments.", $this->domain); ?>
                </div>
            </div>

             <div class="payment-panel-cancelled" style="<?php echo $status == "cancelled" ? "display: block" : "display: none"; ?>">
                <div style="<?php echo $style; ?>">
                <?php if (isset($status_message) && !empty($status_message))
            {
                echo $status_message;
            }
            else
            {
                echo __("Your order is cancelled.", $this->domain);
            } ?>
                </div>
            </div>

            <div class="payment-panel-error" style="display: none">
                <div class="message-holder">
                    <?php echo __("Something went wrong, please contact the merchant.", $this->domain); ?>
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
            <?php
        }

        public function get_payment_staus()
        {
            $status = "wait";
            $message = "";

            try
            {
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
            }
            catch(\Exception $e)
            {
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
            $this->log("return_from_upayments");
            $this->log($_GET);

            if (!isset($_GET["wc_order_id"]))
            {
                $status_message = __("No shop reference received from UPayments.", $this->domain);
                $this->log($status_message);
                $order->update_status("failed", $status_message, $this->domain);
                wp_redirect(add_query_arg("suspected", "true", wc_get_checkout_url()));
                exit();
            }
            else
            {
                $this->log("Ret Order Id Received: " . $_GET["wc_order_id"]);
            }

            $order_id = sanitize_text_field($_GET["wc_order_id"]);
            $PaymentID = "";
            $pos = strpos($order_id, "?payment_id");
            if ($pos !== false)
            {
                $PaymentID = substr($order_id, $pos + strlen("?payment_id") + 1);
                $order_id = (int)substr($order_id, 0, $pos);
            }

            $order = new WC_Order($order_id);

            if (isset($_GET["result"]))
            {
                $this->log("Ret Order Result set.");
                $OrderID = sanitize_text_field($_GET["requested_order_id"]);
                $UPayments_order_id = get_post_meta($order_id, "UPayments_order_id", true) ? get_post_meta($order_id, "UPayments_order_id", true) : $order->get_meta('UPayments_order_id');
                $this->log("Ret Upayments Order Id Received: " . $UPayments_order_id);
                if ($OrderID != $UPayments_order_id)
                {
                    $status_message = __("Ret Order references does not match.", $this->domain);
                    $this->log($status_message);
                    $order->update_status("failed", $status_message, $this->domain);
                    wp_redirect(add_query_arg("suspected", "true", wc_get_checkout_url()));
                    exit();
                }
                else
                {
                    $this->log("Ret Order references matched.");
                    $status = sanitize_text_field($_GET["result"]);
                    if (isset($_GET["payment_id"]))
                    {
                        $PaymentID = sanitize_text_field($_GET["payment_id"]);
                    }
                    $TrackID = sanitize_text_field($_GET["track_id"]);

                    $payment_type = "";
                    if (isset($_GET["payment_type"]))
                    {
                        $payment_type = sanitize_text_field($_GET["payment_type"]);
                    }
                    $PostDate = sanitize_text_field($_GET["post_date"]);
                    $TranID = sanitize_text_field($_GET["tran_id"]);
                    $Ref = sanitize_text_field($_GET["ref"]);
                    $Auth = sanitize_text_field($_GET["auth"]);

                    $order->delete_meta_data("UPayments_Result");
                    if (!empty($PaymentID))
                    {
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
                    if (!empty($PaymentID))
                    {
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

                    if ($status == "CANCELED" || $status == "CANCELLED")
                    {
                        $status_message = __("Received canceled response from UPayments.", $this->domain) . ($PaymentID ? " PaymentID: " . $PaymentID : "");
                        $this->log("Ret Order Cancel Status: " . $status_message);
                        $order->update_status("cancelled", $status_message);
                        wp_redirect(add_query_arg("cancelled", "true", wc_get_checkout_url()));
                        exit();
                    }
                    elseif ($status == "ERROR" || $status == "NOT CAPTURED" || $status == null || $status == "FAILURE")
                    {
                        $status_message = __("Received error response from UPayments.", $this->domain) . ($PaymentID ? " PaymentID: " . $PaymentID : "");
                        $this->log("Ret Order Error Status: " . $status_message);
                        $order->update_status("failed", $status_message, $this->domain);
                        wp_redirect(add_query_arg("failed", "true", wc_get_checkout_url()));
                        exit();
                    }
                    elseif ($status == "CAPTURED" || $status == "SUCCESS")
                    {
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
            }
            else
            {
                $this->log("Ret Order Result not set.");
            }
        }

        public function web_hook_handler()
        {
            global $woocommerce;
            $this->log("Webhook Triggers");
            $this->log($_REQUEST);

            if (!isset($_REQUEST["wc_order_id"]))
            {
                $status_message = __("No shop reference received from UPayments.", $this->domain);
                $this->log($status_message);
                exit();
            }
            else
            {
                $this->log("Order Id Received: " . $_REQUEST["wc_order_id"]);
            }

            $order_id = (int)sanitize_text_field($_REQUEST["wc_order_id"]);
            $pos = strpos($order_id, "?PaymentID");
            if ($pos !== false)
            {
                $order_id = (int)substr($order_id, 0, $pos);
            }

            if ($order_id > 0)
            {
                $UPayments_webhook_triggered = (int)get_post_meta($order_id, "UPayments_webhook_triggered", true);
                if ($UPayments_webhook_triggered == 1)
                {
                    $this->log($order_id . " => UPayments_webhook_triggered set");
                    exit();
                }
                else
                {
                    $this->log($order_id . " => UPayments_webhook_triggered Not set");
                }
            }
            else
            {
                $this->log("Order Id > 0: " . $order_id);
            }

            $order = new WC_Order($order_id);

            try
            {
                if (isset($_REQUEST["result"]))
                {
                    $this->log("Order Result set.");
                    $OrderID = sanitize_text_field($_REQUEST["requested_order_id"]);
                    $UPayments_order_id = get_post_meta($order_id, "UPayments_order_id", true) ? get_post_meta($order_id, "UPayments_order_id", true) : $order->get_meta('UPayments_order_id');
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

                        if ($status == "CAPTURED" || $status == "SUCCESS")
                        {
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
                        }
                        else
                        {
                            $this->log("Order status not CAPTURED. " . $status);
                        }
                    }
                }
                else
                {
                    $this->log("Order Result not set.");
                }
            }
            catch(\Exception $e)
            {
                $this->log("Webhook Catch");
                $this->log("Exception:" . $e->getMessage());

                $order->update_status("failed", "Error :" . $e->getMessage());
                $order->add_meta_data("UPayments_WHS", "failed");
                $woocommerce
                    ->cart
                    ->empty_cart();
            }
            exit();
        }

        public function check_ipn_response()
        {
            global $woocommerce;
            if (isset($_GET["get_order_status"]))
            {
                $this->get_payment_staus();
            }
            elseif (isset($_GET["page"]))
            {
                $this->return_from_upayments();
            }
            else
            {
                $this->web_hook_handler();
            }
            exit();
        }

        /**
         * Process the payment and return the result.
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id)
        {
            global $woocommerce;
            $whitelabled = false;
            if($this->payment_data == null ) {
            $payment_data = $this->getPaymentIcons();
            } else {
             $payment_data = $this->payment_data;
            }
            if($payment_data){
            $whitelabled = $payment_data['whitelabled'];
            }
            if ($whitelabled == true)
            {
                if (!isset($_POST["upayment_payment_type"]))
                {
                    WC()
                        ->session
                        ->set("refresh_totals", true);
                    wc_add_notice(__("Please select a UPayments Payment Type.", $this->domain) , $notice_type = "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url() , ];
                }
            }

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

            $productArrayNew = [];

            $i=0;

            foreach ($order->get_items() as $item)
            {
                $product = $item->get_product();
                $active_price = $product->get_price();
                $regular_price = $product->get_sale_price();
                $sale_price = $product->get_regular_price();

                $item_data = $item->get_data();
                $product_name[] = $item->get_name();
                $product_price[] = $sale_price;
                $product_qty[] = $item_data["quantity"];

                $productArrayNew[$i]['name'] = $item->get_name();
                $productArrayNew[$i]['description']= $item->get_name();
                $productArrayNew[$i]['price'] = $sale_price;
                $productArrayNew[$i]['quantity'] =$item_data["quantity"];
                $i++;
            }

            $src = "knet";
            if ($whitelabled == true)
            {
                $whitelabled = true;
                $upayment_payment_type = sanitize_text_field($_POST["upayment_payment_type"]);
                    if (!empty($upayment_payment_type))
                    {
                        $src = $upayment_payment_type;
                        $order->delete_meta_data("UPayments_Checkout_Selected");
                        $order->add_meta_data("UPayments_Checkout_Selected", $upayment_payment_type);
                    }
            }
            $customer_unq_token = null;
            $credit_card_token = null;
            $isSaveCard = false;
            $phone = str_replace(' ', '', $order_data["billing"]["phone"]); // Replaces all spaces with hyphens.
            $phone = preg_replace('/[^A-Za-z0-9\-]/','',$phone);
            $customer_unq_token = $phone;
            if (substr($customer_unq_token, 0, 1) === '0') {
                $customer_unq_token = '1' . substr($customer_unq_token, 1);
            }
            $customer_unq_token = $this->getCustomerUniqueToken($customer_unq_token);

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
                            "customerUniqueToken" => $customer_unq_token,
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
                                            "3DSecureChallengeWindowSize" => "500_X_600", ],
                            ],
                ]);

            $this->log(__("Create Payment Request:", $this->domain));
            $this->log($params);

            $this->log(__("API key:", $this->domain));
            $this->log($this->api_key);

            //$querystring = json_encode($params);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->getApiUrl());
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_USERAGENT, $this->getUserAgent());
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $this->api_key, "Content-Type: application/json", ]);

            $response = curl_exec($ch);
            curl_close($ch);


            try
            {
                if (!$response)
                {
                    $this->log(__("Create Payment Response: curl error", $this->domain) . " => " . curl_error($ch));
                    WC()
                        ->session
                        ->set("refresh_totals", true);
                    wc_add_notice(__("Payment request failed. " . curl_error($ch) , $this->domain) , $notice_type = "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url() , ];
                }
                else
                {
                    $result = json_decode($response, true);
                    $this->log(__("Create Payment Response:", $this->domain));
                    $this->log($result);
                    if (!$result)
                    {
                        WC()
                            ->session
                            ->set("refresh_totals", true);
                        wc_add_notice(__("Payment request failed. Empty Response Received.", $this->domain) , $notice_type = "error");
                        return ["result" => "failure", "redirect" => wc_get_checkout_url() , ];
                    }
                    elseif (isset($result["status"]) && $result["status"] == false)
                    {
                        WC()
                            ->session
                            ->set("refresh_totals", true);
                        wc_add_notice(__("Payment request failed. " . $result["message"], $this->domain) , $notice_type = "error");
                        return ["result" => "failure", "redirect" => wc_get_checkout_url() , ];
                    }
                    elseif (isset($result["message"]) && (!isset($result["status"])))
                    {
                        WC()
                            ->session
                            ->set("refresh_totals", true);
                        wc_add_notice(__("Payment request failed. " . $result["message"], $this->domain) , $notice_type = "error");
                        return ["result" => "failure", "redirect" => wc_get_checkout_url() , ];
                    }
                    elseif (isset($result["status"]) && $result["status"] == true)
                    {
                        if ($result["data"]["link"])
                        {
                            $order->delete_meta_data("UPayments_order_id");
                            $order->add_meta_data("UPayments_order_id", $unique_order_id);
                            $order->save_meta_data();

                            return ["result" => "success", "redirect" => $result["data"]["link"], ];
                        }
                        else
                        {
                            $order->delete_meta_data("UPayments_order_id");
                            $order->add_meta_data("UPayments_order_id", $unique_order_id);
                            $order->save_meta_data();
                            $this->log(__($result["data"]["transactionData"]["redirect_url"], $this->domain));
                            return ["result" => "success", "redirect" => $result["data"]["transactionData"]["redirect_url"], ];
                        }
                    }
                    else
                    {
                        $status_message = __("UPayments: Something went wrong, please contact the merchant", $this->domain);
                        WC()
                            ->session
                            ->set("refresh_totals", true);
                        wc_add_notice($status_message, $notice_type = "error");
                        return ["result" => "failure", "redirect" => wc_get_checkout_url() , ];
                    }
                }
            }
            catch(\Exception $e)
            {
                $message = $e->getMessage();
                $this->log(__("Create Payment Response: catch exception", $this->domain) . " => " . $message);
                $status_message = __("UPayments: Something went wrong, please contact the merchant", $this->domain);
                WC()
                    ->session
                    ->set("refresh_totals", true);
                wc_add_notice($status_message, $notice_type = "error");
                return ["result" => "failure", "redirect" => wc_get_checkout_url() , ];
            }
        }

        public function UPayments_admin_footer()
        {

        }

        public function getSiteName()
        {
            return __("Woocommerce", $this->domain);
        }

        public function getIsOrderComplete() {
            $flag = true;
            if ($this->is_order_complete == 'no') {
                $flag = false;
            }
            return $flag;
        }

        public function getMode() {
            $mode = true;
            if ($this->test_mode == 'no') {
                $mode = false;
            }
            return $mode;
        }

        public function getAPIUrl()
        {
            $url = "https://apiv2api.upayments.com/api/v1/charge";
            if ($this->getMode()) {
                $url = "https://sandboxapi.upayments.com/api/v1/charge";
            }
            return $url;
        }

        public function getAPIUrlForCreateToken()
        {
            $url = "https://apiv2api.upayments.com/api/v1/create-customer-unique-token";
            if ($this->getMode()) {
               $url = "https://sandboxapi.upayments.com/api/v1/create-customer-unique-token";
            }
            return $url;
        }

        public function getAPIUrlForCheckPaymentButtonStatus() {
            $url = "https://apiv2api.upayments.com/api/v1/check-payment-button-status";
            if ($this->getMode()) {
                $url = "https://sandboxapi.upayments.com/api/v1/check-payment-button-status";
            }
            return $url;
        }

        public function getUserAgent(){
            $userAgent = 'UpaymentsWoocommercePlugin/2.1.4';
            if ($this->getMode()) {
                $userAgent = 'SandboxUpaymentsWoocommercePlugin/2.1.4';
            }
            return $userAgent;
        }

        public function getCurrencyCode($code)
        {
            $currency = $code;
            return $currency;
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
            $key = password_hash($this->api_key, PASSWORD_BCRYPT);
            return $key;
        }

        public function getCustomerUniqueToken($phone)
        {
            $token = "";
            $phone = trim($phone);
            if (!empty($phone))
            {
                $token = $phone;
                $params = json_encode(["customerUniqueToken" => $token, ]);
                $curl = curl_init();
                curl_setopt_array($curl, [CURLOPT_URL => $this->getAPIUrlForCreateToken() , CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => $this->getUserAgent(), CURLOPT_ENCODING => "", CURLOPT_MAXREDIRS => 10, CURLOPT_TIMEOUT => 0, CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, CURLOPT_CUSTOMREQUEST => "POST", CURLOPT_POSTFIELDS => $params, CURLOPT_HTTPHEADER => ["Accept: application/json", "Content-Type: application/json", "Authorization: Bearer " . $this->api_key, ], ]);

                $response = curl_exec($curl);
                if ($response)
                {
                    $result = json_decode($response, true);
                    if ($result["errors"])
                    {
                        $cards = ["error" => 1, "msg" => $result["message"]];
                    }
                    elseif ($result["status"] == true)
                    {
                        $token = $token;
                    }
                    else
                    {
                        $cards = ["error" => 1, "msg" => $result["message"]];
                    }
                }
            }
            return $token;
        }

        public function getUpayPaymentMethods()
        {
            $api_key =  $this->api_key;
            $payment_methods=null;
            if (!empty($api_key))
            {
                $curl = curl_init();

                curl_setopt_array($curl, array(
                CURLOPT_URL => $this->getAPIUrlForCheckPaymentButtonStatus(),
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
                    'Authorization: Bearer ' . $this->api_key
                ),
                ));
                $response = curl_exec($curl);
                $this->log(__("Check payment methods:", $this->domain));
                $this->log($response);
                if ($response)
                {
                    $result = json_decode($response, true);
                    if($result){
                        if ($result && array_key_exists("status",$result) && $result["status"] == true)
                        {
                            $payment_methods = $result['data'];
                            $payment_methods["result"] = 'success';
                        }
                        else
                        {
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

        public function getPaymentIcons()
        {
            $data=$this->getUpayPaymentMethods();
            if($data['result'] != 'failure') {
            $payment_methods=$data['payButtons'];
            $whitelabled=$data['isWhiteLabel'];
            $methods=[];
            if($payment_methods['knet'] == 1){ $methods['payment']['knet'] = __('KNET', $this->domain);}
            if($payment_methods['apple_pay_knet'] == 1){$methods['payment']['apple-pay-knet'] = __('Apple Pay Knet', $this->domain);}
            if($payment_methods['credit_card'] == 1){$methods['payment']['cc'] = __('Credit Card', $this->domain);}
             if($payment_methods['apple_pay'] == 1){$methods['payment']['apple-pay'] = __('Apple Pay Credit card', $this->domain);}
            if($payment_methods['samsung_pay'] == 1){$methods['payment']['samsung-pay'] = __('Samsung Pay', $this->domain); }
            if($payment_methods['google_pay'] == 1){$methods['payment']['google-pay'] = __('Google Pay', $this->domain);}
            $methods['whitelabled'] = $whitelabled;
            return $methods;
            }

        }

        public function log($content)
        {
            $debug = $this->debug;
            if ($debug == true)
            {
                $file = UPayments_PLUGIN_PATH . "debug.log";
                $fp = fopen($file, "a+");
                fwrite($fp, "\n");
                fwrite($fp, date("Y-m-d H:i:s") . ": ");
                fwrite($fp, print_r($content, true));
                fclose($fp);
            }
        }
    }
}

add_filter("woocommerce_payment_gateways", "add_upayments_gateway_class");
function add_upayments_gateway_class($methods)
{
    $methods[] = "WC_UPayments";
    return $methods;
}

add_filter("woocommerce_available_payment_gateways", "enable_upayments_gateway");
function enable_upayments_gateway($available_gateways)
{
    if (is_admin())
    {
        return $available_gateways;
    }

    if (isset($available_gateways["upayments"]))
    {
        $settings = get_option("woocommerce_upayments_settings");

        if (empty($settings["api_key"]))
        {
            unset($available_gateways["upayments"]);
        }
    }

    $supported_currencies = ["KWD", "SAR", "USD", "BHD", "EUR", "OMR", "QAR", "AED", ];
    if (!in_array(get_woocommerce_currency() , $supported_currencies))
    {
        unset($available_gateways["upayments"]);
    }

    return $available_gateways;
}
