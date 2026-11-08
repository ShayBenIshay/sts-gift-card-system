# STS Gift Card System

Gift cards for WooCommerce: customers choose an amount, receive a code by email, and redeem it at checkout, in one or several uses until the balance is gone.

## Features
- Customer-chosen amount (with a configurable minimum).
- Codes are created when the order is completed or paid, and sent by email (with a "send to a friend" option).
- Multi-use balance and expiry dates, with a daily reminder email before expiry.
- Redemption box at checkout, applied as a negative fee in the cart.
- Admin under **Gift Cards**: list and settings; the expiry-reminder email text is editable.
- Shortcode: `[sts_gift_card_form]`.

## Setting up the product
A product is treated as a gift card when its **name or slug** contains "gift card", "gift-card", "גיפט קארד" or "גיפט-קארד". Create a simple product with such a name; its price field is replaced by an amount field.

## Requirements
WordPress 5.0+, WooCommerce 5.0+, PHP 7.4+. Declares HPOS compatibility.

## Install
Copy the folder to `wp-content/plugins/` and activate. Tables are created automatically.

## Status

Version 1.0.0. Provided as-is, under GPL-2.0-or-later (see `LICENSE`). Published without full functional testing on a live store; try it on staging first.

Setup, customization and support are available from [Shay Tech Solutions](https://shaytechsolutions.com).
