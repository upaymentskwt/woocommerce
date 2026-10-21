<?php
/**
 * Payment form template for the Old Design (V2.0.8 Look).
 *
 * This file is included in WC_Gateway_Your_Gateway::payment_fields().
 *
 * @var WC_Gateway_Your_Gateway $gateway           The gateway instance.
 * @var bool                    $save_card_enabled Flag indicating if save card is enabled (unused in this design).
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="form-row form-row-wide">
    <?php
    echo $gateway->description;
    if (isset($_REQUEST["cancelled"]))
    {
    ?>
    <script>
        let message = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error alert-color"><?php echo __("Payment canceled by customer", 'upayments'); ?></div></div>';
        jQuery(document).ready(function(){
            jQuery('.woocommerce-notices-wrapper:first').html(message);
        });
    </script>
    <?php
    } elseif (isset($_REQUEST["failed"])) {
    ?>
    <script>
        let message = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error alert-color"><?php echo __("Payment error from UPayments", 'upayments'); ?></div></div>';
        jQuery(document).ready(function(){
            jQuery('.woocommerce-notices-wrapper:first').html(message);
        });
    </script>
    <?php
    } elseif (isset($_REQUEST["suspected"])){
    ?>
    <script>
        let message = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error alert-color"><?php echo __("Payment failed for suspected fraud.", 'upayments'); ?></div></div>';
        jQuery(document).ready(function(){
            jQuery('.woocommerce-notices-wrapper:first').html(message);
        });
    </script>
    <?php
    }
    $icons = null;
    $whitelabled = false;
    
    // Check $gateway property instead of $this
    if($gateway->paymentData == null ) {
        $payment_data = $gateway->getPaymentIcons(); // Use $gateway
    } else {
        $payment_data = $gateway->paymentData; // Use $gateway
    }
    
    if($payment_data){
        $icons = $payment_data['payment'];
        $whitelabled = $payment_data['whitelabled'];
    }
    
    if ($whitelabled)
    {
    ?>
        <ul style="list-style: none outside;">
            <p style="display: inline">Select Payment Type:</p>
            <?php 
            foreach ($icons as $key => $value){
                if ($key != "both"){
                    if($key == 'apple-pay') {
                        $icon = ' <img style="height: 13px;" src="' . UP_PLUGIN_URL . "assets/images/" . esc_attr('apple-pay') . '.png" alt="' . esc_attr($value) . '"  title="' . esc_attr($value) . '" />
                        <img style="height: 13px;" src="' . UP_PLUGIN_URL . "assets/images/" . esc_attr('cc') . '.png" alt="' . esc_attr($value) . '"  title="' . esc_attr($value) . '" />'; 
                    } elseif($key == 'apple-pay-knet') {
                        $icon = ' <img style="height: 13px;" src="' . UP_PLUGIN_URL . "assets/images/" . esc_attr('apple-pay') . '.png" alt="' . esc_attr($value) . '"  title="' . esc_attr($value) . '" />
                        <img style="height: 13px;" src="' . UP_PLUGIN_URL . "assets/images/" . esc_attr('knet') . '.png" alt="' . esc_attr($value) . '"  title="' . esc_attr($value) . '" />'; 
                    } else {
                        $icon = ' <img style="height: 13px;" src="' . UP_PLUGIN_URL . "assets/images/" . esc_attr($key) . '.png" alt="' . esc_attr($value) . '"  title="' . esc_attr($value) . '" />'; 
                    }
                        
            ?>
                <li>
                    <span class="<?php echo esc_attr($key);?>-upayments-button">
                    <input id="upayment_payment_type_<?php echo esc_attr($key); ?>" type="radio" class="input-radio"
                            name="upayment_payment_type" value="<?php echo esc_attr($key); ?>"/>
                    <label for="upayment_payment_type_<?php echo esc_attr($key); ?>"
                            style='display: inline-block; font-family: -apple-system,blinkmacsystemfont,"Helvetica Neue",helvetica,sans-serif;'>
                        <span class="upayment_payment_type_label_text"><?php echo esc_attr($value); ?></span>
                        <span class="upayment_payment_type_label_logo"><?php echo $icon; ?></span>
                    </label>
                    </span>
                </li>
            <?php
                }
            }
            ?>
        </ul>
    <?php
    }
    ?>
</div>
