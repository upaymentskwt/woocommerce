<?php
/**
 * Payment form template for the New Design (V2.1.5/V2.2.1).
 *
 * This file is included in WC_Gateway_Your_Gateway::payment_fields().
 *
 * @var WC_Gateway_Your_Gateway $gateway           The gateway instance (now renamed to $this in the original context).
 * @var bool                    $save_card_enabled Flag indicating if save card is enabled.
 */

defined( 'ABSPATH' ) || exit;
use UPayments\Subscription\Helpers\Utils;
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
    if (isset($_REQUEST["cancelled"])){ ?>
        <script>
            let message = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error alert-color"><?php echo __("Payment canceled by customer", $gateway->domain); ?></div></div>';
            jQuery(document).ready(function(){
                jQuery('.woocommerce-notices-wrapper:first').html(message);
            });
        </script>
    <?php
    } elseif (isset($_REQUEST["failed"])){ ?>
        <script>
            let message = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error alert-color"><?php echo __("Payment error from UPayments", $gateway->domain); ?></div></div>';
            jQuery(document).ready(function(){
                jQuery('.woocommerce-notices-wrapper:first').html(message);
            });
        </script>
    <?php
    } elseif (isset($_REQUEST["suspected"])) { ?>
        <script>
            let message = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error alert-color"><?php echo __("Payment failed for suspected fraud.", $gateway->domain); ?></div></div>';
            jQuery(document).ready(function(){
                jQuery('.woocommerce-notices-wrapper:first').html(message);
            });
        </script>
    <?php
    }
        $icons = null;
        $total = "0";
        $total = WC()->cart->get_total('');
        $language=get_locale();
        $currency = get_woocommerce_currency_symbol();
        if (strpos($language, 'en') === 0) {
            $currency = get_woocommerce_currency();
        }
        $whitelabled = false;
        // NOTE: The function call was $this->getPaymentIcons() in the original method.
        // Since we are in a template, we use the $gateway variable.
        $payment_data = $gateway->getPaymentIcons();
        if($payment_data){
            $gateway->paymentData = $payment_data;
            $icons = $payment_data['payment'];
            $whitelabled = $payment_data['whitelabled'];
        }
        $isSubscriptionEnabled = $gateway->get_option('enable_subscriptions') === 'yes' ? true : false;
        if($whitelabled){
    ?>
        <div class="payment-buttons">
            <?php
            // Retrieve Saved Cards
            $loggedInUser = $gateway->getLoggedinUserPhoneNumber(); // Use $gateway
            $user_id = get_current_user_id();
            if($loggedInUser['success']  && $save_card_enabled && $user_id) {
            ?>
                <input id="save_card" type="hidden" name="save_card" value="1"/>
                <?php
                // Fetch KNET saved cards using dynamic token
                $knetCardList = [];
                if (empty($icons) || isset($icons['knet'])) {
                    $knetTokenData = Utils::generateDynamicToken($user_id, $loggedInUser['phone']);
                    $knetToken = $knetTokenData['token'] ?? '';
                    if (!empty($knetToken) && $knetToken !== '0') {
                        $savedKnetCards = $gateway->getSavedCards($knetToken);
                        if ($savedKnetCards && isset($savedKnetCards['result']) && $savedKnetCards['result'] === 'success' && !empty($savedKnetCards['data'])) {
                            foreach ($savedKnetCards['data'] as $card) {
                                $brand  = strtoupper(trim($card['brand'] ?? ''));
                                $scheme = strtoupper(trim($card['scheme'] ?? ''));
                                $token  = trim((string)($card['token'] ?? ''));
                                // Only accept genuine KNET cards (brand/scheme KNET or 8-digit KFast token)
                                if ($brand === 'KNET' || $scheme === 'KNET' || (strlen($token) === 8 && ctype_digit($token))) {
                                    $knetCardList[] = $card;
                                }
                            }
                        }
                    }
                }

                // Fetch CC saved cards
                $ccCardList = [];
                if (empty($icons) || isset($icons['cc'])) {
                    $savedCCCards = $gateway->getSavedCards($loggedInUser['phone'].$user_id);
                    if ($savedCCCards && isset($savedCCCards['result']) && $savedCCCards['result'] === 'success' && !empty($savedCCCards['data'])) {
                        foreach ($savedCCCards['data'] as $card) {
                            $brand  = strtoupper(trim($card['brand'] ?? ''));
                            $scheme = strtoupper(trim($card['scheme'] ?? ''));
                            $token  = trim((string)($card['token'] ?? ''));
                            // Ensure only credit cards are included in CC list
                            if ($brand !== 'KNET' && $scheme !== 'KNET' && strlen($token) !== 8) {
                                $ccCardList[] = $card;
                            }
                        }
                    }
                }

                if (!empty($knetCardList) || !empty($ccCardList))
                {
                ?>
                    <span class="payment-method-label">Saved Cards</span>
                    <?php
                    foreach ($knetCardList as $cardkey => $cardValue) {
                    ?>
                        <button type="button" value="<?php echo esc_attr($cardValue['token']);?>" onclick="submitSavedCard(this, 'knet')" class="upay-payment-method" id="upay-button-knet" data-payment-type="knet">
                        <span class="payment-method-icon"><img src="<?php echo UP_PLUGIN_URL;?>assets/images/knet.png" alt="<?php echo esc_attr($cardValue['number']);?>"  title="<?php echo esc_attr($cardValue['number']);?>"/></span>
                        <span class="payment-method-label"><?php echo esc_html($cardValue['number']);?></span>
                        <span class="payment-method-price"><?php echo $total;?> <?php echo $currency;?></span>
                        <span class="payment-method-icon2"><i class="fa fa-chevron-right"></i></span>
                        </button>
                    <?php
                    }

                    foreach ($ccCardList as $cardkey => $cardValue) {
                    ?>
                        <button type="button" value="<?php echo esc_attr($cardValue['token']);?>" onclick="submitSavedCard(this, 'cc')" class="upay-payment-method" id="upay-button-cc" data-payment-type="cc">
                        <span class="payment-method-icon"><img src="<?php echo UP_PLUGIN_URL;?>assets/images/cc.png" alt="<?php echo esc_attr($cardValue['number']);?>"  title="<?php echo esc_attr($cardValue['number']);?>"/></span>
                        <span class="payment-method-label"><?php echo esc_html($cardValue['number']);?></span>
                        <span class="payment-method-price"><?php echo $total;?> <?php echo $currency;?></span>
                        <span class="payment-method-icon2"><i class="fa fa-chevron-right"></i></span>
                        </button>
                    <?php
                    }
                    ?>
                    <span class="payment-method-label">Other Options</span>
                <?php
                }
            } else {
            ?>
                <input id="save_card" type="hidden" name="save_card" value="0"/>
            <?php
            }
                foreach ($icons as $key => $value) {
            ?>
                <button type="button" onclick="submitUpayButton('<?php echo esc_attr($key);?>')" class="upay-payment-method" id="upay-button-<?php echo esc_attr($key);?>">
                    <span class="payment-method-icon">
                        <?php
                            if($key == 'apple-pay-knet') {
                                ?>
                                <img src="<?php echo UP_PLUGIN_URL;?>assets/images/<?php echo esc_attr('apple-pay');?>.png" alt="<?php echo esc_attr($value);?>"  title="<?php echo esc_attr($value);?>"/>
                                    <img src="<?php echo UP_PLUGIN_URL;?>assets/images/<?php echo esc_attr('knet');?>.png" alt="<?php echo esc_attr($value);?>"  title="<?php echo esc_attr($value);?>"/>
                                <?php
                            } elseif($key == 'apple-pay') {
                                ?>
                                    <img src="<?php echo UP_PLUGIN_URL;?>assets/images/<?php echo esc_attr('apple-pay');?>.png" alt="<?php echo esc_attr($value);?>"  title="<?php echo esc_attr($value);?>"/>
                                    <img src="<?php echo UP_PLUGIN_URL;?>assets/images/<?php echo esc_attr('cc');?>.png" alt="<?php echo esc_attr($value);?>"  title="<?php echo esc_attr($value);?>"/>
                                <?php
                            } else {
                                ?>
                                    <img src="<?php echo UP_PLUGIN_URL;?>assets/images/<?php echo esc_attr($key);?>.png" alt="<?php echo esc_attr($value);?>"  title="<?php echo esc_attr($value);?>"/>
                                <?php
                            }
                        ?>
                    </span>
                    <span class="payment-method-label"><?php echo esc_attr($value);?></span>
                    <span class="payment-method-price"><?php echo $total;?> <?php echo $currency;?></span>
                    <span class="payment-method-icon2"><i class="fa fa-chevron-right"></i></span>
                </button>
            
            <?php if($key == 'cc' && $save_card_enabled) { ?>
                <label class="switch-border">For faster and more secure checkout. Save your card details.
                    <label class="switch">
                        <?php
                            $hasPhone = $loggedInUser['success'] && !empty($loggedInUser['phone']);
                            $checked  = ($hasPhone || ($isSubscriptionEnabled && $hasPhone)) ? true : false;
                        ?>
                        <input
                            type="checkbox"
                            id="chkSaveCard"
                            onclick="toggleSaveCard(<?php echo $checked ? 'true' : 'false'; ?>);"
                            onkeydown="if(event.key === 'Enter' || event.keyCode === 13) { this.click(); }"
                            <?php echo $checked ? 'checked' : ''; ?>
                        >
                        <span class="slider round"></span>
                    </label>
                </label>
            <?php
                    }
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
                if($key != 'apple-pay-knet') {
    ?>
                <span class="payment-method-icon" style="margin-right: 5px;" id="upay-button-<?php echo esc_attr($key);?>"><img src="<?php echo UP_PLUGIN_URL;?>assets/images/<?php echo esc_attr($key);?>.png" alt="<?php echo esc_attr($value);?>"  title="<?php echo esc_attr($value);?>"/></span>
    <?php
                }
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
        <input id="card_token" type="hidden" name="card_token" value=""/>
</div>
