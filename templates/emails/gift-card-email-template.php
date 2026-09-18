<?php
/**
 * Gift Card Email Template
 *
 * @package STS_Gift_Card
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * @var STS_Gift_Card_Email_Friend $email
 * @var object $gift_card
 * @var WC_Order $order
 */

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php
$recipient_name = $gift_card->recipient_name ? $gift_card->recipient_name : __( 'Friend', 'sts-gift-card' );
$sender_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

/**
 * Pulls a body-copy field from the email's admin-configured settings, with
 * placeholder substitution — falls back to the hardcoded default if $email
 * isn't a live WC_Email instance (defensive; shouldn't happen in practice).
 */
$sts_gc_line = function( $key, $default ) use ( $email, $recipient_name, $sender_name ) {
    if ( $email instanceof WC_Email ) {
        return $email->format_string( $email->get_option( $key, $default ) );
    }
    return strtr( $default, array(
        '{recipient_name}' => $recipient_name,
        '{sender_name}'    => $sender_name,
    ) );
};
?>
<p><?php echo esc_html( $sts_gc_line( 'greeting_line', 'היייי 😍 יש לך מתנה!' ) ); ?></p>

<p><?php echo esc_html( $sts_gc_line( 'intro_line', '{recipient_name}, מחכה לך הפתעה שווה במיוחד מ־{sender_name} 🎁✨' ) ); ?></p>

<p><?php echo esc_html( $sts_gc_line( 'redemption_line', 'קיבלת גיפט קארד לרכישה באתר שלנו על סך:' ) ); ?></p>
<p style="font-size: 20px; font-weight: bold; margin: 10px 0;">
    <?php echo wc_price( $gift_card->initial_amount, array( 'currency' => $gift_card->currency ) ); ?>
</p>

<?php if ( $gift_card->recipient_message ) : ?>
    <p><?php echo esc_html( $sts_gc_line( 'personal_message_intro', 'וגם השאירו לך ברכה אישית 💌' ) ); ?></p>
    <div style="margin: 20px 0; padding: 15px; border-left: 4px solid #ffc107; border-radius: 4px; background: #fff9e6;">
        <p style="margin: 0; font-style: italic; font-size: 16px;"><?php echo esc_html( $gift_card->recipient_message ); ?></p>
    </div>
<?php endif; ?>

<p><?php echo esc_html( $sts_gc_line( 'code_label', 'זה קוד השובר שלך לקנייה באתר:' ) ); ?></p>
<div style="margin: 20px 0; padding: 20px; background: #f5f5f5; border-radius: 8px; text-align: center;">
    <p style="font-size: 28px; font-weight: bold; letter-spacing: 3px; margin: 10px 0; color: #333;">
        <?php echo esc_html( $gift_card->code ); ?>
    </p>
</div>

<p style="margin-top: 30px;">
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" style="display: inline-block; padding: 15px 30px; background: #0073aa; color: #fff; text-decoration: none; border-radius: 5px; font-weight: bold;">
        <?php echo esc_html( $sts_gc_line( 'cta_button_text', 'למימוש השובר לחצי כאן 👈' ) ); ?>
    </a>
</p>

<p style="margin-top: 20px; font-size: 16px;">
    <?php echo esc_html( $sts_gc_line( 'closing_line', 'יאללה, כנסי לאתר, תתפרעי קצת ותמצאי את התכשיט שיגרום לך לחייך 😌💎' ) ); ?>
</p>

<?php
if ( $additional_content ) {
    echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

// Only show WooCommerce footer if not in preview mode
if ( ! defined( 'STS_GC_PREVIEW_MODE' ) || ! STS_GC_PREVIEW_MODE ) {
    do_action( 'woocommerce_email_footer', $email );
}

