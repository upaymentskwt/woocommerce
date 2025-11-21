=== UPayments Payment Gateway for WooCommerce ===
Contributors: UPayments
Tags: UPayments payments, woocommerce, payment gateway, UPayments, pay with UPayments, credit card, knet, samsung pay, Apple Pay, Google Pay
Requires at least: 4.0
Tested up to: 6.3.1
Stable tag: 2.1.2
PHP requires  at least: 5.5
PHP tested up to: 8.2.9
WC requires at least: 2.4
WC tested up to: 8.0.3
License: MIT

UPayments Plugin allows merchants to accept KNET, Cards, Samsung Pay, Apple Pay, Google Pay Payments.

== Description ==

UPayments Plugin allows merchants to accept KNET, Cards, Samsung Pay, Apple Pay, Google Pay Payments.

This plugin would communicate with 3rd party UPayments payment gateway(https://apiv2api.upayments.com/) in order to process the payments.

Merchant must create an account with UPayments payment gateway(https://upayments.com/).

Merchant once created an account with UPayments(https://upay.upayments.com/), they can go to thier UPayments dashboard and choose the payment options they would to avail for their site.

And merchant need to copy the merchant id and api key from the UPayments Merchant account.

== Installation ==

= Using The WordPress Dashboard =

1. Navigate to the 'Add New' in the plugins dashboard
2. Search for 'UPayments'
3. Click 'Install Now'
4. Activate the plugin on the Plugin dashboard

= Uploading in WordPress Dashboard =

1. Navigate to the 'Add New' in the plugins dashboard
2. Navigate to the 'Upload' area
3. Select plugins zip file from your computer
4. Click 'Install Now'
5. Activate the plugin in the Plugin dashboard

= Using FTP =

1. Download Plugin zip file
2. Extract the Zip file directory to your computer
3. Upload directory to the `/wp-content/plugins/` directory
4. Activate the plugin in the Plugin dashboard

= Updating =

Automatic updates should work like a charm; as always though, ensure you backup your site just in case.

== Configuration ==

1. Go to WooCommerce settings
2. Select the "Payments" tab
3. Activate the payment method (if inactive)
4. Set the name you wish to show your users on Checkout (for example: "UPayments KNET or Creditcard")
5. Fill the payment method's description (for example: "Pay with UPayments")
6. Copy the API keys and Salt values from the UPayments Web Dashboard under Settings > Payment Gateway > API Keys
7. Click "Save Changes"
8. All done!

== Frequently Asked Questions ==

= Where can I get support? =

The easiest and fastest way is via our live chat on our [website](https://upayments.com/) or via our [contact form](https://upayments.com/en/contact-us/).

= Why UPayments not displaying in the checkout?

Please make sure you have entered api key.

= Still UPayments not displaying in the checkout after entered api key?

Supported currency codes are: KWD, SAR, USD, BHD, EUR, OMR, QAR, AED

== Screenshots ==

1. Plugin listed in the plugins page after installed/uploaded.
2. The WooCommerce > Settings > Payments to manage UPayments Payment Gateway settings panel.
3. The settings panel used to configure the gateway.
4. Normal checkout with UPayments Payment Gateway for non whitelabled user.
5. Normal checkout with UPayments Payment Gateway for whitelabled user.
6. UPayments Payment selection interface for non-whitelabeled.
7. UPayments Payment interface with form.
8. UPayments Payment interface for confirming the payment.

== Changelog ==

= 2.0 =
* Initial release.

== Upgrade Notice ==
= 2.0.1 =
-Added test mode(sandbox option)

= 2.0.2 =
-Customer unique token validation

= 2.0.3 =
-Improved performance

= 2.0.4 =
-Ajax Issue & critical error resolved

= 2.0.5 =
-IP whitelisting issue is resolved

= 2.1.0 =
- Design updates

= 2.1.1 =
- Payment failed for suspected fraud issue is resolved for latest woocommerce version
- Customer unique token validation accept with 0.

= 2.1.2 =
- Payment Confirmation Mail - Product name issue fixed.

= 2.1.4 =
- Order Status Issue Fixed.

= 2.1.5 =
-Apple Debit Card Option Added