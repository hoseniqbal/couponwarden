=== CouponWarden: New Customer and One Per Person Coupons for WooCommerce ===
Contributors: iqbal511138
Tags: woocommerce, coupon, first order, coupon abuse, discount
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Welcome coupons that really go to new customers, once. Matches email, phone and address, guests included, and warns you when a code leaks.

== Description ==

You offer 15% off the first order. A customer uses it, then comes back as a guest with john.smith+2@gmail.com, or with a new account, or with their partner's email but the same address. WooCommerce sees a new person every time, and your welcome discount becomes a permanent discount.

CouponWarden recognises returning people and keeps your coupons fair.

= What it does =

* **New customers only.** Tick one box on a coupon. People who already have a paid order are told politely that the coupon is for first orders.
* **Once per person.** Each person can use the coupon one time, as a guest or with any number of accounts.
* **Smart matching.** A person is recognised by email (Gmail dots and +tags ignored), phone number and delivery address. You choose which to use.
* **Your past customers count.** On activation it reads your existing paid orders in the background, so returning customers are recognised from day one.
* **Leak watch.** If a code is suddenly used far more than usual, for example after it was posted on a coupon website, you get an email.
* **Works everywhere.** Classic checkout and the block checkout.
* **Dashboard** with attempts stopped and the discount you kept.

= Privacy =

CouponWarden never stores customer details in plain text. Emails, phone numbers and addresses are turned into scrambled fingerprints with your site's secret key, so they can be compared but not read. No external requests.

== Installation ==

1. Install and activate CouponWarden. WooCommerce must be active.
2. Edit a coupon, open Usage restriction and tick "New customers only" or "Once per person".

== Frequently Asked Questions ==

= Does it change my coupons? =

No. It only adds two optional tick boxes. Coupons you do not tick work exactly as before.

= Two people live at the same address. =

Turn off address matching in Settings, and only email and phone are used.

= What counts as an earlier order? =

Paid orders: processing, completed, on hold and refunded. Failed and cancelled orders do not count.

== Changelog ==

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.0 =
First release.
