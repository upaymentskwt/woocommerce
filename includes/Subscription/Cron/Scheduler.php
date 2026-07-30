<?php

namespace UPayments\Subscription\Cron;

use DateTime;
use WC_Order;
use WC_DateTime;
use DateTimeInterface;

defined('ABSPATH') || exit;

class Scheduler
{
    const CRON_HOOK = 'upay_process_subscriptions';
    const LOCK_KEY  = 'upayments_cron_lock';

    /**
     * Bootstraps scheduler
     */
    public static function init()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
        }
        add_action(self::CRON_HOOK, [__CLASS__, 'process']);
    }

    /**
     * Main cron logic
     */
    public static function process()
    {
        $logger  = wc_get_logger();
        $context = ['source' => 'upayments-cron'];
        $now = new DateTime('now', wp_timezone());

        // Prevent duplicate execution
        if (get_transient(self::LOCK_KEY)) {
            $logger->warning('Cron skipped due to active lock', $context);
            return;
        }
        set_transient(self::LOCK_KEY, true, 60);

        try {
            $gateway = self::getGateway();

            if (!$gateway || $gateway->get_option('enable_subscriptions') !== 'yes') {
                $logger->info('Subscriptions disabled or gateway unavailable', $context);
                return;
            }

            $page  = 1;
            $limit = 50;
            $matched_orders = [];

            do {
                $orders = wc_get_orders([
                    'status' => 'completed',
                    'limit'  => $limit,
                    'paged'  => $page,
                ]);
                
                foreach ($orders as $order) {
                    foreach ($order->get_items('line_item') as $item) {
                        $product = $item->get_product();
                        if ($product && $product->get_type() === 'custom_type') {
                            $matched_orders[] = $order;
                            break; // stop checking this order
                        }
                    }
                }
                $page++;
            } while (!empty($orders));
            
            if(!empty($matched_orders)){
                foreach ($matched_orders as $order) {

                    $isAutoDeductionOrder = $order->get_meta('UPayments_AutoDeduction') === 'yes' ? true : false;
                    $subscriptionStatus = $order->get_meta('_upay_subscription_status');
                    // Process each matched order as needed
                    $customerUnqToken = $order->get_meta('_upay_customer_unique_token');
                    
                    if($customerUnqToken == null || empty($customerUnqToken)) {
                        $logger->info('Customer Unique token not found for : ', $context + ['Order ID' => $order->get_id(), 'customer' => $order->get_customer_id()]);
                        break;
                    }
                    
                    $subscriptionPlan = $order->get_meta('_upay_subscription_plan');
                    $subscriptionInterval = (int) $order->get_meta('_upay_subscription_interval');
                    if($subscriptionPlan === 'daily'){
                        $subscriptionInterval = 1;
                    }
                    
                    // Prevent invalid configs
                    if ((!$subscriptionPlan || $subscriptionInterval < 1) || $subscriptionPlan === 'one_time') {
                        break;
                    }                    

                    $order_date = $order->get_date_created();
                    $order_paid_date = $order->get_date_paid();
                    $order_completed_date = $order->get_date_completed();
                    $order_last_billed_date = $order->get_meta('_upay_last_billed_at');

                    $start_date = $order_last_billed_date 
                        ?: $order_paid_date
                        ?: $order_completed_date 
                        ?: $order_date;

                    if (!$start_date) {
                        break;
                    }

                    // --- FIX FOR TYPE CHECKER & STRINGS ---
                    if (is_string($start_date)) {
                        $start_date = new DateTime($start_date);
                    } 
                    // Handles both WC_DateTime and standard DateTime safely inside namespaces
                    elseif ($start_date instanceof DateTimeInterface) {
                        $start_date = new DateTime($start_date->format('Y-m-d H:i:s'), $start_date->getTimezone());
                    }
                    
                    // Calculate next billing date
                    $next_billing_date = self::getNextBillingDate(
                        $start_date,
                        $subscriptionPlan,
                        $subscriptionInterval
                    );
                    if (!$next_billing_date) {
                        break;
                    }
                                        
                    if(!$isAutoDeductionOrder && $subscriptionStatus !== 'cancelled' && $now >= $next_billing_date) {
                        $credit_card_token = $order->get_meta('_upay_credit_card_token');
                        if(empty($credit_card_token)){
                            $savedCards = $gateway->getSavedCards($customerUnqToken);
                            if($savedCards && $savedCards['result'] == 'success'){
                                $cards = $savedCards['data'];
                                if(!empty($cards) && is_array($cards)){
                                    $credit_card_token = $cards[0]['token'] ?? null;
                                }
                            } else {
                                $logger->info('Credit card token not found for : ', $context + ['Order ID' => $order->get_id(), 'customer' => $order->get_customer_id()]);
                                break;
                            }
                        }
                        
                        // replace below line if required
                        // $unique_order_id = $order->get_meta('UPayments_order_id');
                        $unique_order_id = $order->get_id();
                        $ref_id = $order->get_meta('UPayments_Ref');
                        $order_total = $order->get_total();
                        $currency = $order->get_currency();
                        $phone = preg_replace('/\D+/', '', $order->get_billing_phone());
                        $firstName = $order->get_billing_first_name();
                        $lastName = $order->get_billing_last_name();
                        $fullName = $firstName." " .$lastName;
                        $email = $order->get_billing_email();
    
                        $params = json_encode([
                            "order" =>[
                                "id" => (string)$unique_order_id,
                                "amount" => $order_total, 
                                "currency" => $gateway->getCurrencyCode($currency) , 
                                "description" => "Woocommerce Auto Deduction Order: " . $unique_order_id,
                                "reference" => "Uniq Order ID: " . $unique_order_id,
                            ],
                            "reference" => [
                                "id" => (string)$ref_id, 
                            ],
                            "customer" => [
                                "name" => $fullName,
                                "email" => $email,
                                "mobile" => $phone,
                                "uniqueToken" => $customerUnqToken,
                            ],
                            "language" => "en",
                            "card" => [
                                "token" => $credit_card_token,
                            ],
                        ]);
    
                        $gateway->log(__("Create Payment Request:", $gateway->domain));
                        $gateway->log($params);
    
                        $gateway->log(__("API key:", $gateway->domain));
                        $gateway->log($gateway->apiKey);

                        $order->update_meta_data('_upay_last_attempt_at', current_time('mysql'));
                        $order->save();

                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $gateway->getApiUrl('auto-deduct'));
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                        // uncomment when required
                        // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_USERAGENT, $gateway->getUserAgent());
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $gateway->apiKey, "Accept: application/json", "Content-Type: application/json", ]);
    
                        $response = curl_exec($ch);
                        $logger->info('Response recieved: ', $context + ['response' => $response]);
                        curl_close($ch);           
                        
                        try
                        {
                            if (!$response){
                                $logger->info('Auto deduction CRON Error :: ', $context + ['Order ID' => $unique_order_id, 'message' => 'Empty response received']);
                                $retry_count = (int) $order->get_meta('_upay_retry_count');
                                $order->update_meta_data('_upay_retry_count', $retry_count + 1);
                                $order->update_meta_data('_upay_last_failed_reason', 'Gateway failure'); // optional
                                $order->save();
                                break;    
                            }else{
                                $result = json_decode($response, true);
                                $logger->info('Auto deduction Response:: ', $context + $result);
                                
                                //success result handling
                                if (isset($result["status"]) && $result["status"]){
                                    $responseData = $result["data"];
                                    $transaction = $responseData["transaction"];
                                    
                                    $existing_orders = wc_get_orders([
                                        'limit'      => 1,
                                        'meta_key'   => '_upay_payment_id',
                                        'meta_value' => $transaction['paymentId'],
                                    ]);

                                    if (!empty($existing_orders)) {
                                        return; // Already recorded
                                    }

                                    // Create renewal order
                                    $renewal_order = wc_create_order([
                                        'customer_id' => $order->get_user_id(),
                                    ]);

                                    // Copy products from parent order
                                    foreach ($order->get_items('line_item') as $item) {

                                        $product = $item->get_product();
                                        if (!$product) {
                                            continue;
                                        }

                                        $renewal_order->add_product(
                                            $product,
                                            $item->get_quantity(),
                                            [
                                                'subtotal' => $item->get_total(),
                                                'total'    => $item->get_total(),
                                            ]
                                        );
                                    }

                                    // Copy billing & shipping addresses
                                    $renewal_order->set_address($order->get_address('billing'), 'billing');
                                    $renewal_order->set_address($order->get_address('shipping'), 'shipping');

                                    // Set currency & totals
                                    $renewal_order->set_currency($transaction['paid_currency']);
                                    $renewal_order->set_total((float) $transaction['paid_amount']);

                                    // Set payment method
                                    $renewal_order->set_payment_method('upayments');
                                    $renewal_order->set_payment_method_title('UPayments Auto Deduction');

                                    // Save gateway transaction meta
                                    $renewal_order->update_meta_data('UPayments_order_id', $transaction['orderId'] + 1);
                                    $renewal_order->update_meta_data('UPayments_ParentOrderID', $order->get_id());
                                    $renewal_order->update_meta_data('UPayments_AutoDeduction', 'yes');
                                    $renewal_order->update_meta_data('UPayments_PaymentID', $transaction['paymentId']);
                                    $renewal_order->update_meta_data('UPayments_TrackID', $transaction['trackId']);
                                    $renewal_order->update_meta_data('UPayments_InvoiceID', $transaction['invoiceId']);
                                    $renewal_order->update_meta_data('UPayments_Ref', $transaction['reference']);
                                    $renewal_order->update_meta_data('UPayments_TransactionDate', $transaction['transactionDate']);
                                    $renewal_order->update_meta_data('UPayments_Result', 'CAPTURED');
                                    $renewal_order->update_meta_data('UPayments_PostDate', '');
                                    $renewal_order->update_meta_data('UPayments_payment_type', $transaction['paymentType']);
                                    $renewal_order->update_meta_data('UPayments_GatewayStatus', $result["status"]);
                                    $renewal_order->update_meta_data('_upay_credit_card_token', $credit_card_token);
                                    $renewal_order->update_meta_data('_upay_customer_unique_token', $customerUnqToken);
                                    $renewal_order->update_meta_data('_upay_subscription_plan', $subscriptionPlan);
                                    $renewal_order->update_meta_data('_upay_subscription_interval', $subscriptionInterval);

                                    // Finalize order status
                                    $renewal_order->payment_complete($transaction['paymentId']);
                                    $renewal_order->update_status('completed', __('Subscription renewal payment completed via UPayments Auto Deduction. PaymentID: '.$transaction['paymentId'], $gateway->domain));
                                    $renewal_order->save();

                                    // Update subscription meta on parent order
                                    $order->update_meta_data('_upay_last_billed_at', current_time('mysql'));
                                    $order->delete_meta_data('_upay_retry_count');
                                    $order->delete_meta_data('_upay_last_attempt_at');
                                    $order->delete_meta_data('_upay_last_failed_reason');
                                    $order->update_meta_data('_upay_subscription_status', 'active');
                                    $order->save();
                                } elseif (!$result){ // result or response is empty
                                    $logger->info('Payment request failed. Empty Response Received. ', $context);  
                                    $retry_count = (int) $order->get_meta('_upay_retry_count');
                                    $order->update_meta_data('_upay_retry_count', $retry_count + 1);
                                    $order->update_meta_data('_upay_last_failed_reason', 'Gateway failure'); // optional
                                    $order->save();  
                                }elseif (isset($result["status"]) && !$result["status"]){ // result status is false
                                    $logger->info('Payment request failed. Status is false. ', $context + ['result' => $result]);
                                    $retry_count = (int) $order->get_meta('_upay_retry_count');
                                    $order->update_meta_data('_upay_retry_count', $retry_count + 1);
                                    $order->update_meta_data('_upay_last_failed_reason', 'Gateway failure'); // optional
                                    $order->save();  
                                }elseif (isset($result["message"]) && !isset($result["status"])){ // result message with no status
                                    $logger->info('Payment request failed. No status in response. ', $context + ['result' => $result]);
                                    $retry_count = (int) $order->get_meta('_upay_retry_count');
                                    $order->update_meta_data('_upay_retry_count', $retry_count + 1);
                                    $order->update_meta_data('_upay_last_failed_reason', 'Gateway failure'); // optional
                                    $order->save();  
                                }else{
                                    $status_message = __("UPayments: Something went wrong, please contact the merchant", $gateway->domain);
                                    $logger->info('Payment request failed. Unexpected response format. ', $context + $status_message);
                                    $retry_count = (int) $order->get_meta('_upay_retry_count');
                                    $order->update_meta_data('_upay_retry_count', $retry_count + 1);
                                    $order->update_meta_data('_upay_last_failed_reason', 'Gateway failure'); // optional
                                    $order->save();  
                                }
                            }
                        }catch(\Exception $e){
                            $message = $e->getMessage();
                            $status_message = __("UPayments: Something went wrong, please contact the merchant", $gateway->domain);

                            $logger->info('Create Payment Response: catch exception', $context + ['message' => $message]);
                            $logger->info('Error Exception: ', $context + ['message' => $status_message]);
                            $retry_count = (int) $order->get_meta('_upay_retry_count');
                            $order->update_meta_data('_upay_retry_count', $retry_count + 1);
                            $order->update_meta_data('_upay_last_failed_reason', 'Gateway failure'); // optional
                            $order->save();  
                        }
                    }
                }
            } else {
                $logger->info('No matched orders found', $context);
            }

            $logger->info('Cron execution finished successfully', $context);

        } catch (\Throwable $e) {
            $logger->error(
                'Cron failed: ' . $e->getMessage(),
                $context
            );
        } finally {
            // temp
            delete_transient(self::LOCK_KEY);
        }
    }

    /**
     * Fetch UPayments gateway safely
     */
    protected static function getGateway()
    {
        if (!function_exists('WC')) {
            return null;
        }

        WC()->payment_gateways();
        $gateways = WC()->payment_gateways->payment_gateways();

        return $gateways['upayments'] ?? null;
    }

    public static function getNextBillingDate(?DateTime $start, string $plan, int $interval): ?DateTime
    {
        if (!$start) {
            return null;
        }
        // If a string or a WC_DateTime object slips through, convert it safely inside the method
        if (is_string($start)) {
            $date = new DateTime($start);
        } elseif ($start instanceof DateTimeInterface) {
            $date = clone $start;
        } else {
            // Fallback for unexpected types
            return null;
        }
        // $date = clone $start; // This line is redundant and can be removed

        switch ($plan) {
            case 'daily':
                $date->modify("+{$interval} day");
                break;
            case 'weekly':
                $date->modify("+{$interval} week");
                break;

            case 'monthly':
                $date->modify("+{$interval} month");
                break;

            case 'quarterly':
                $date->modify("+" . ($interval * 3) . " month");
                break;

            case 'yearly':
                $date->modify("+{$interval} year");
                break;
        }

        return $date;
    }

    public static function upayShouldAttemptRetry(WC_Order $order): bool
    {
        $status = $order->get_meta('_upay_subscription_status') ?: 'active';
        if (in_array($status, ['paused', 'cancelled'], true)) {
            return false;
        }

        $retry_count = (int) $order->get_meta('_upay_retry_count');
        $last_attempt = $order->get_meta('_upay_last_attempt_at');

        if ($retry_count >= 3) {
            return false; // max retries reached
        }

        if (!$last_attempt) {
            return true; // first retry attempt
        }

        $last_attempt_dt = new DateTime($last_attempt, wp_timezone());
        $now = new DateTime('now', wp_timezone());

        // Backoff schedule: 1h, 6h, 24h
        $delays = [1, 6, 24];
        $hours  = $delays[min($retry_count, count($delays) - 1)];

        $next_allowed = clone $last_attempt_dt;
        $next_allowed->modify("+{$hours} hour");

        return $now >= $next_allowed;
    }
}
