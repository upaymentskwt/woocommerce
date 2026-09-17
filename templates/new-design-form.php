<?php
/**
 * Payment form template for the New Design (V2.1.5/V2.2.1).
 *
 * This file is included in WC_Gateway_Your_Gateway::payment_fields().
 *
 * @var WC_Gateway_Your_Gateway $gateway           The gateway instance.
 * @var bool                    $save_card_enabled Flag indicating if save card is enabled.
 */

defined('ABSPATH') || exit;
?>
<style>
    .wc-toast {
        position: fixed;
        top: 30px;
        right: 30px;
        background: #F23232;
        color: #fff;
        padding: 12px 18px;
        border-radius: 6px;
        opacity: 0;
        pointer-events: none;
        transform: translateY(10px);
        transition: all 0.3s ease;
        z-index: 99999;
    }

    .wc-toast.show {
        opacity: 1;
        transform: translateY(0);
    }
</style>
<div id="wc-toast" class="wc-toast"></div>
<div class="form-row form-row-wide">
    <?php
    if (isset($_REQUEST["cancelled"])) { ?>
        <script>
            let message = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error alert-color"><?php echo esc_js(__("Payment canceled by customer", 'upayments')); ?></div></div>';
            jQuery(document).ready(function(){
                jQuery('.woocommerce-notices-wrapper:first').html(message);
            });
        </script>
    <?php
    } elseif (isset($_REQUEST["failed"])) { ?>
        <script>
            let message = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error alert-color"><?php echo esc_js(__("Payment error from UPayments", 'upayments')); ?></div></div>';
            jQuery(document).ready(function(){
                jQuery('.woocommerce-notices-wrapper:first').html(message);
            });
        </script>
    <?php
    } elseif (isset($_REQUEST["suspected"])) { ?>
        <script>
            let message = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error alert-color"><?php echo esc_js(__("Payment failed for suspected fraud.", 'upayments')); ?></div></div>';
            jQuery(document).ready(function(){
                jQuery('.woocommerce-notices-wrapper:first').html(message);
            });
        </script>
    <?php 
    } 

    $icons = [];
    $total = (WC()->cart) ? WC()->cart->get_total('') : "0";
    $language = get_locale();
    $currency = get_woocommerce_currency_symbol();
    if (strpos($language, 'en') === 0) {
        $currency = get_woocommerce_currency();
    }
    $whitelabled = false;

    // Safely retrieve payment methods
    $payment_data = $gateway->getPaymentIcons(); 
    if (is_array($payment_data)) {
        $gateway->paymentData = $payment_data;
        $icons = (isset($payment_data['payment']) && is_array($payment_data['payment'])) ? $payment_data['payment'] : [];
        $whitelabled = !empty($payment_data['whitelabled']);
    }

    $isSubscriptionEnabled = ($gateway->get_option('enable_subscriptions') === 'yes');

    // Only render if payment icons/methods exist
    if (!empty($icons)) {
        if ($whitelabled) {
    ?>
        <div class="payment-buttons">
            <?php
            // Retrieve Saved Cards
            $loggedInUser = $gateway->get_logged_in_user_phone_number();
            $user_id = get_current_user_id();
            
            if (!empty($loggedInUser['success']) && $save_card_enabled && $user_id) {
            ?>
                <input id="save_card" type="hidden" name="save_card" value="1"/>
                <?php
                $phone = $loggedInUser['phone'] ?? '';
                $savedCards = $gateway->getSavedCards($phone . $user_id);
                
                if (!empty($savedCards['result']) && $savedCards['result'] === 'success' && !empty($savedCards['data']) && is_array($savedCards['data'])) {
                    $cardList = $savedCards['data'];
                ?>
                    <span class="payment-method-label"><?php echo esc_html__('Saved Cards', 'upayments'); ?></span>
                    <?php foreach ($cardList as $cardValue) : ?>
                        <button type="button" value="<?php echo esc_attr($cardValue['token'] ?? ''); ?>" onclick="submitSavedCard(this)" class="upay-payment-method" id="upay-button-cc">
                            <span class="payment-method-icon"><img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/cc.png'); ?>" alt="<?php echo esc_attr($cardValue['number'] ?? ''); ?>" title="<?php echo esc_attr($cardValue['number'] ?? ''); ?>"/></span>
                            <span class="payment-method-label"><?php echo esc_html($cardValue['number'] ?? ''); ?></span>
                            <span class="payment-method-price"><?php echo esc_html($total); ?> <?php echo esc_html($currency); ?></span>
                            <span class="payment-method-icon2"><i class="fa fa-chevron-right"></i></span>
                        </button>
                    <?php endforeach; ?>
                    <span class="payment-method-label"><?php echo esc_html__('Other Options', 'upayments'); ?></span>
                <?php
                }
            } else {
            ?>
                <input id="save_card" type="hidden" name="save_card" value="0"/>
            <?php
            }

            foreach ($icons as $key => $value) :
            ?>
                <button type="button" onclick="submitUpayButton('<?php echo esc_attr($key); ?>')" class="upay-payment-method" id="upay-button-<?php echo esc_attr($key); ?>">
                    <span class="payment-method-icon">
                        <?php if ($key === 'apple-pay-knet') : ?>
                            <img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/apple-pay.png'); ?>" alt="<?php echo esc_attr($value); ?>" title="<?php echo esc_attr($value); ?>"/>
                            <img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/knet.png'); ?>" alt="<?php echo esc_attr($value); ?>" title="<?php echo esc_attr($value); ?>"/>
                        <?php elseif ($key === 'apple-pay') : ?>
                            <img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/apple-pay.png'); ?>" alt="<?php echo esc_attr($value); ?>" title="<?php echo esc_attr($value); ?>"/>
                            <img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/cc.png'); ?>" alt="<?php echo esc_attr($value); ?>" title="<?php echo esc_attr($value); ?>"/>
                        <?php else : ?>
                            <img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/' . esc_attr($key) . '.png'); ?>" alt="<?php echo esc_attr($value); ?>" title="<?php echo esc_attr($value); ?>"/>
                        <?php endif; ?>
                    </span>
                    <span class="payment-method-label"><?php echo esc_html($value); ?></span>
                    <span class="payment-method-price"><?php echo esc_html($total); ?> <?php echo esc_html($currency); ?></span>
                    <span class="payment-method-icon2"><i class="fa fa-chevron-right"></i></span>
                </button>
            
                <?php if ($key === 'cc' && $save_card_enabled) : 
                    $hasPhone = !empty($loggedInUser['success']) && !empty($loggedInUser['phone']);
                    $checked  = ($hasPhone || ($isSubscriptionEnabled && $hasPhone));
                ?>
                    <label class="switch-border"><?php echo esc_html__('For faster and more secure checkout. Save your card details.', 'upayments'); ?>
                        <label class="switch">
                            <input
                                type="checkbox"
                                id="chkSaveCard"
                                onclick="toggleSaveCard(<?php echo $checked ? 'true' : 'false'; ?>);"
                                <?php checked($checked, true); ?>
                            >
                            <span class="slider round"></span>
                        </label>
                    </label>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php
        } else {
    ?>
        <div class="payment-buttons">
            <button type="button" onclick="submitUpayButton('knet')" class="upay-payment-method">
                <?php foreach ($icons as $key => $value) :
                    if ($key !== 'apple-pay-knet') : ?>
                        <span class="payment-method-icon" style="margin-right: 5px;" id="upay-button-<?php echo esc_attr($key); ?>">
                            <img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/' . esc_attr($key) . '.png'); ?>" alt="<?php echo esc_attr($value); ?>" title="<?php echo esc_attr($value); ?>"/>
                        </span>
                    <?php endif;
                endforeach; ?>
                <span class="payment-method-price"><?php echo esc_html($total); ?> <?php echo esc_html($currency); ?></span>
                <span class="payment-method-icon2"><i class="fa fa-chevron-right"></i></span>
            </button>
        </div>
    <?php
        }
    } else {
        // Fallback message when payment methods are unreachable
        ?>
        <p><?php echo esc_html__('Online payment methods are currently unavailable. Please try again later.', 'upayments'); ?></p>
        <?php
    }
    ?>
    <input id="upayment_payment_type" type="hidden" name="upayment_payment_type" value="upayments"/>
    <input id="card_token" type="hidden" name="card_token" value=""/>
</div>
