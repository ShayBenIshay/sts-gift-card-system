<?php
/**
 * Gift Card Email
 *
 * @package STS_Gift_Card
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gift Card Email Class
 */
class STS_Gift_Card_Email_Friend extends WC_Email {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'sts_gift_card_email';
        $this->title = __( 'Gift Card Email', 'sts-gift-card' );
        $this->description = __( 'Gift card email sent to the recipient.', 'sts-gift-card' );
        $this->customer_email = true;
        $this->template_html = 'emails/gift-card-email-template.php';
        $this->template_plain = 'emails/plain/gift-card-friend.php';
        $this->placeholders = array(
            '{gift_card_code}' => '',
            '{gift_card_amount}' => '',
            '{gift_card_balance}' => '',
            '{recipient_name}' => '',
            '{sender_name}' => '',
        );
        
        parent::__construct();
    }
    
    /**
     * Get email subject
     */
    public function get_default_subject() {
        return __( 'You received a Gift Card! - {gift_card_code}', 'sts-gift-card' );
    }
    
    /**
     * Get email heading
     */
    public function get_default_heading() {
        return __( 'You received a Gift Card!', 'sts-gift-card' );
    }
    
    /**
     * Trigger email
     */
    public function trigger( $gift_card_id, $order_id, $is_friend = false ) {
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'sts_gift_cards';
        
        $gift_card = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $gift_card_id
        ) );
        
        if ( ! $gift_card || ! $gift_card->recipient_email ) {
            return;
        }
        
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        
        $this->object = $gift_card;
        $this->order = $order;
        $this->recipient = $gift_card->recipient_email;
        
        $this->placeholders['{gift_card_code}'] = $gift_card->code;
        $this->placeholders['{gift_card_amount}'] = wc_price( $gift_card->initial_amount, array( 'currency' => $gift_card->currency ) );
        $this->placeholders['{gift_card_balance}'] = wc_price( $gift_card->balance, array( 'currency' => $gift_card->currency ) );
        $this->placeholders['{recipient_name}'] = $gift_card->recipient_name ? $gift_card->recipient_name : __( 'Friend', 'sts-gift-card' );
        $this->placeholders['{sender_name}'] = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        
        if ( $this->is_enabled() && $this->get_recipient() ) {
            $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
        }
    }
    
    /**
     * Get content html
     */
    public function get_content_html() {
        return wc_get_template_html(
            $this->template_html,
            array(
                'gift_card' => $this->object,
                'order' => $this->order,
                'email_heading' => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin' => false,
                'plain_text' => false,
                'email' => $this,
            ),
            '',
            STS_GC_PLUGIN_DIR . 'templates/'
        );
    }
    
    /**
     * Get content plain
     */
    public function get_content_plain() {
        return wc_get_template_html(
            $this->template_plain,
            array(
                'gift_card' => $this->object,
                'order' => $this->order,
                'email_heading' => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin' => false,
                'plain_text' => true,
                'email' => $this,
            ),
            '',
            STS_GC_PLUGIN_DIR . 'templates/'
        );
    }
    
    /**
     * Initialize settings form fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __( 'Enable/Disable', 'sts-gift-card' ),
                'type' => 'checkbox',
                'label' => __( 'Enable this email notification', 'sts-gift-card' ),
                'default' => 'yes',
            ),
            'subject' => array(
                'title' => __( 'Subject', 'sts-gift-card' ),
                'type' => 'text',
                'desc_tip' => true,
                'description' => sprintf( __( 'Available placeholders: %s', 'sts-gift-card' ), '<code>{gift_card_code}, {gift_card_amount}, {gift_card_balance}, {recipient_name}, {sender_name}</code>' ),
                'placeholder' => $this->get_default_subject(),
                'default' => $this->get_default_subject(),
            ),
            'heading' => array(
                'title' => __( 'Email heading', 'sts-gift-card' ),
                'type' => 'text',
                'desc_tip' => true,
                'description' => sprintf( __( 'Available placeholders: %s', 'sts-gift-card' ), '<code>{gift_card_code}, {gift_card_amount}, {gift_card_balance}, {recipient_name}, {sender_name}</code>' ),
                'placeholder' => $this->get_default_heading(),
                'default' => $this->get_default_heading(),
            ),
            'additional_content' => array(
                'title' => __( 'Additional content', 'sts-gift-card' ),
                'description' => __( 'Text to appear below the main email content.', 'sts-gift-card' ),
                'css' => 'width:400px; height: 75px;',
                'placeholder' => __( 'N/A', 'sts-gift-card' ),
                'type' => 'textarea',
                'default' => '',
                'desc_tip' => true,
            ),
            'email_type' => array(
                'title' => __( 'Email type', 'sts-gift-card' ),
                'type' => 'select',
                'description' => __( 'Choose which format of email to send.', 'sts-gift-card' ),
                'default' => 'html',
                'class' => 'email_type wc-enhanced-select',
                'options' => $this->get_email_type_options(),
                'desc_tip' => true,
            ),
            'greeting_line' => array(
                'title' => __( 'Greeting line', 'sts-gift-card' ),
                'type' => 'text',
                'desc_tip' => true,
                'description' => __( 'First line of the email body.', 'sts-gift-card' ),
                'default' => 'היייי 😍 יש לך מתנה!',
            ),
            'intro_line' => array(
                'title' => __( 'Intro line', 'sts-gift-card' ),
                'type' => 'text',
                'desc_tip' => true,
                'description' => sprintf( __( 'Available placeholders: %s', 'sts-gift-card' ), '<code>{recipient_name}, {sender_name}</code>' ),
                'default' => '{recipient_name}, מחכה לך הפתעה שווה במיוחד מ־{sender_name} 🎁✨',
            ),
            'redemption_line' => array(
                'title' => __( 'Redemption line', 'sts-gift-card' ),
                'type' => 'text',
                'desc_tip' => true,
                'description' => __( 'Line shown above the gift card amount — describe where it can be redeemed (site, physical stands, etc).', 'sts-gift-card' ),
                'default' => 'קיבלת גיפט קארד לרכישה באתר שלנו על סך:',
            ),
            'personal_message_intro' => array(
                'title' => __( 'Personal message intro', 'sts-gift-card' ),
                'type' => 'text',
                'desc_tip' => true,
                'description' => __( 'Shown only when the buyer left a personal message for the recipient.', 'sts-gift-card' ),
                'default' => 'וגם השאירו לך ברכה אישית 💌',
            ),
            'code_label' => array(
                'title' => __( 'Code label', 'sts-gift-card' ),
                'type' => 'text',
                'desc_tip' => true,
                'description' => __( 'Line shown above the voucher code.', 'sts-gift-card' ),
                'default' => 'זה קוד השובר שלך לקנייה באתר:',
            ),
            'cta_button_text' => array(
                'title' => __( 'Button text', 'sts-gift-card' ),
                'type' => 'text',
                'desc_tip' => true,
                'description' => __( 'Text shown on the redeem button.', 'sts-gift-card' ),
                'default' => 'למימוש השובר לחצי כאן 👈',
            ),
            'closing_line' => array(
                'title' => __( 'Closing line', 'sts-gift-card' ),
                'type' => 'textarea',
                'css' => 'width:400px; height: 75px;',
                'desc_tip' => true,
                'description' => __( 'Closing line shown near the bottom of the email.', 'sts-gift-card' ),
                'default' => 'יאללה, כנסי לאתר, תתפרעי קצת ותמצאי את התכשיט שיגרום לך לחייך 😌💎',
            ),
        );
    }
}

