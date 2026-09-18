<?php
/**
 * Plugin Name: STS Gift Card System
 * Plugin URI: https://shaytechsolutions.com
 * Description: Custom gift card system for WooCommerce with multi-use cards, expiry dates, and email delivery
 * Version: 1.0.0
 * Author: Shay Tech Solutions
 * Author URI: https://shaytechsolutions.com
 * Text Domain: sts-gift-card
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

define( 'STS_GC_VERSION', '1.0.0' );
define( 'STS_GC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'STS_GC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main Gift Card System Class
 */
class STS_Gift_Card_System {
    
    private static $instance = null;
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
        
        // Initialize on plugins_loaded to ensure WooCommerce is loaded
        add_action( 'plugins_loaded', array( $this, 'init' ) );
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            return;
        }
        
        // Database setup
        add_action( 'init', array( $this, 'maybe_create_table' ) );
        add_action( 'init', array( $this, 'maybe_create_usage_table' ) );
        
        // Product page customization
        add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'add_gift_card_amount_field' ), 10 );
        add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_gift_card_amount' ), 10, 3 );
        add_action( 'woocommerce_add_cart_item_data', array( $this, 'add_gift_card_data_to_cart' ), 10, 3 );
        add_filter( 'woocommerce_cart_item_price', array( $this, 'display_gift_card_price' ), 10, 2 );
        add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'display_gift_card_subtotal' ), 10, 2 );
        
        // Hide product price for gift card products
        add_filter( 'woocommerce_get_price_html', array( $this, 'hide_gift_card_price' ), 10, 2 );
        
        // Set custom price on add to cart
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_gift_card_price' ), 10, 1 );
        
        // Admin page
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        
        // Expiry reminder cron
        add_action( 'sts_gift_card_expiry_reminder', array( $this, 'send_expiry_reminders' ) );
        if ( ! wp_next_scheduled( 'sts_gift_card_expiry_reminder' ) ) {
            wp_schedule_event( time(), 'daily', 'sts_gift_card_expiry_reminder' );
        }
        
        // Order completion - create gift card
        add_action( 'woocommerce_order_status_completed', array( $this, 'create_gift_card_on_order_completion' ), 10, 1 );
        add_action( 'woocommerce_payment_complete', array( $this, 'create_gift_card_on_payment' ), 10, 1 );
        
        // Checkout page - gift card application
        // Hook into after order review (better UX - shows after totals, before payment)
        add_action( 'woocommerce_checkout_after_order_review', array( $this, 'add_gift_card_checkout_section' ), 5 );
        // Keep before checkout form for compatibility with other themes
        add_action( 'woocommerce_before_checkout_form', array( $this, 'add_gift_card_checkout_section' ), 5 );
        add_action( 'wp_ajax_sts_apply_gift_card', array( $this, 'ajax_apply_gift_card' ) );
        add_action( 'wp_ajax_nopriv_sts_apply_gift_card', array( $this, 'ajax_apply_gift_card' ) );
        add_action( 'wp_ajax_sts_remove_gift_card', array( $this, 'ajax_remove_gift_card' ) );
        add_action( 'wp_ajax_nopriv_sts_remove_gift_card', array( $this, 'ajax_remove_gift_card' ) );
        
        // Apply gift card discount - use both hooks to ensure shipping is included
        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_gift_card_discount' ), 999, 1 );
        add_action( 'woocommerce_after_calculate_totals', array( $this, 'recalculate_gift_card_discount' ), 10 );
        
        // Customize gift card fee display
        add_filter( 'woocommerce_cart_totals_fee_html', array( $this, 'add_remove_button_to_gift_card_fee' ), 10, 2 );
        
        // Allow free orders when gift card covers everything
        add_filter( 'woocommerce_cart_needs_payment', array( $this, 'cart_needs_payment' ), 10, 2 );
        add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_payment_gateways' ), 10, 1 );
        add_filter( 'woocommerce_checkout_show_payment', array( $this, 'show_payment_section_for_free_orders' ), 10, 1 );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'process_free_order' ), 10, 3 );
        add_action( 'woocommerce_review_order_after_payment', array( $this, 'add_place_order_button_for_free_orders' ), 10 );
        
        // Save cart item data to order items
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_gift_card_data_to_order_item' ), 10, 4 );
        
        // Store gift card usage in order
        add_action( 'woocommerce_checkout_create_order', array( $this, 'store_gift_card_usage_in_order' ), 10, 2 );
        
        // Update gift card balance on order completion
        add_action( 'woocommerce_order_status_completed', array( $this, 'update_gift_card_balance' ), 20, 1 );
        add_action( 'woocommerce_payment_complete', array( $this, 'update_gift_card_balance' ), 20, 1 );
        
        // Email templates
        add_filter( 'woocommerce_email_classes', array( $this, 'register_email_classes' ) );
        add_filter( 'woocommerce_email_actions', array( $this, 'register_email_actions' ) );
        add_action( 'sts_gift_card_email', array( $this, 'trigger_gift_card_emails' ), 10, 3 );
        
        // Enqueue scripts
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        
        // Register shortcode
        add_shortcode( 'sts_gift_card_form', array( $this, 'render_gift_card_form_shortcode' ) );
        
        // AJAX for preview
        add_action( 'wp_ajax_sts_preview_gift_card_email', array( $this, 'ajax_preview_gift_card_email' ) );
        add_action( 'wp_ajax_nopriv_sts_preview_gift_card_email', array( $this, 'ajax_preview_gift_card_email' ) );
    }
    
    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php esc_html_e( 'STS Gift Card System requires WooCommerce to be installed and active.', 'sts-gift-card' ); ?></p>
        </div>
        <?php
    }
    
    /**
     * Create database table on activation
     */
    public function activate() {
        $this->create_gift_card_table();
        $this->create_gift_card_usage_table();
        $this->maybe_create_table();
        $this->maybe_create_usage_table();
        
        // Set default settings
        if ( ! get_option( 'sts_gift_card_minimum_amount' ) ) {
            update_option( 'sts_gift_card_minimum_amount', 50 );
        }
        if ( ! get_option( 'sts_gift_card_expiry_days' ) ) {
            update_option( 'sts_gift_card_expiry_days', 365 );
        }
    }
    
    /**
     * Deactivation hook
     */
    public function deactivate() {
        // Clear scheduled cron
        $timestamp = wp_next_scheduled( 'sts_gift_card_expiry_reminder' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'sts_gift_card_expiry_reminder' );
        }
    }
    
    /**
     * Create gift card database table
     */
    private function create_gift_card_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sts_gift_cards';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code varchar(50) NOT NULL,
            initial_amount decimal(10,2) NOT NULL,
            balance decimal(10,2) NOT NULL,
            currency varchar(10) NOT NULL DEFAULT 'ILS',
            status varchar(20) NOT NULL DEFAULT 'active',
            buyer_email varchar(255) NOT NULL,
            recipient_email varchar(255) DEFAULT NULL,
            recipient_name varchar(255) DEFAULT NULL,
            recipient_message text DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            order_id bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY code (code),
            KEY status (status),
            KEY expires_at (expires_at),
            KEY order_id (order_id)
        ) $charset_collate;";
        
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
        
        // Store version for future migrations
        update_option( 'sts_gift_card_db_version', '1.0.0' );
    }
    
    /**
     * Maybe create table (for existing installations)
     */
    public function maybe_create_table() {
        $db_version = get_option( 'sts_gift_card_db_version', '0' );
        if ( version_compare( $db_version, '1.0.0', '<' ) ) {
            $this->create_gift_card_table();
        }
    }
    
    /**
     * Create gift card usage table
     */
    private function create_gift_card_usage_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sts_gift_card_usage';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            gift_card_id bigint(20) UNSIGNED NOT NULL,
            order_id bigint(20) UNSIGNED NOT NULL,
            amount_used decimal(10,2) NOT NULL,
            balance_before decimal(10,2) NOT NULL,
            balance_after decimal(10,2) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY gift_card_id (gift_card_id),
            KEY order_id (order_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
        
        update_option( 'sts_gift_card_usage_db_version', '1.0.0' );
    }
    
    /**
     * Maybe create usage table (for existing installations)
     */
    public function maybe_create_usage_table() {
        $db_version = get_option( 'sts_gift_card_usage_db_version', '0' );
        if ( version_compare( $db_version, '1.0.0', '<' ) ) {
            $this->create_gift_card_usage_table();
        }
    }
    
    /**
     * Track gift card usage
     */
    private function track_gift_card_usage( $gift_card_id, $order_id, $amount_used, $balance_before, $balance_after ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sts_gift_card_usage';
        
        $wpdb->insert(
            $table_name,
            array(
                'gift_card_id' => $gift_card_id,
                'order_id' => $order_id,
                'amount_used' => $amount_used,
                'balance_before' => $balance_before,
                'balance_after' => $balance_after,
                'created_at' => current_time( 'mysql' )
            ),
            array( '%d', '%d', '%f', '%f', '%f', '%s' )
        );
    }
    
    /**
     * Check if product is a gift card product
     */
    private function is_gift_card_product( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return false;
        }
        
        // Check if product title or slug contains "gift card" or "גיפט-קארד"
        $title = strtolower( $product->get_name() );
        $slug = strtolower( $product->get_slug() );
        
        return (
            strpos( $title, 'gift card' ) !== false ||
            strpos( $title, 'gift-card' ) !== false ||
            strpos( $title, 'גיפט-קארד' ) !== false ||
            strpos( $title, 'גיפט קארד' ) !== false ||
            strpos( $slug, 'gift-card' ) !== false ||
            strpos( $slug, 'gift_card' ) !== false
        );
    }
    
    /**
     * Hide product price for gift card products
     */
    public function hide_gift_card_price( $price, $product ) {
        if ( $this->is_gift_card_product( $product->get_id() ) ) {
            return '';
        }
        return $price;
    }
    
    /**
     * Add gift card amount input field on product page
     */
    public function add_gift_card_amount_field() {
        global $product;
        
        if ( ! $this->is_gift_card_product( $product->get_id() ) ) {
            return;
        }
        
        $min_amount = $this->get_minimum_amount();
        $product_id = $product->get_id();
        ?>
        <div class="sts-gift-card-form-fields" style="display: flex; flex-direction: row; gap: 1rem;">
            <div>
                <div class="sts-gift-card-amount-field" style="margin-bottom: 20px;">
                    <label for="sts_gift_card_amount_widget" style="display: block; margin-bottom: 8px; font-weight: 600;">
                        <?php esc_html_e( 'שווי הגיפט קארד', 'sts-gift-card' ); ?>
                        <span style="color: red;">*</span>
                    </label>
                    <input 
                        type="number" 
                        id="sts_gift_card_amount_widget" 
                        name="sts_gift_card_amount" 
                        class="input-text" 
                        step="10" 
                        min="<?php echo esc_attr( $min_amount ); ?>" 
                        value="<?php echo esc_attr( $min_amount ); ?>" 
                        required 
                        style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;"
                    />
                    <small class="small-text-sts">
                        <?php printf( esc_html__( 'מינימום: %s ₪', 'sts-gift-card' ), number_format( $min_amount, 2 ) ); ?>
                    </small>
                </div>
                
                <div class="sts-gift-card-friend-details">
                    <label for="sts_recipient_name_widget" style="display: block; margin-bottom: 8px; font-weight: 600;">
                        <?php esc_html_e( 'שם מקבלת המתנה', 'sts-gift-card' ); ?>
                        <span style="color: red;">*</span>
                    </label>
                    <input 
                        type="text" 
                        id="sts_recipient_name_widget" 
                        name="sts_recipient_name" 
                        class="input-text" 
                        required
                        style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px;"
                    />
                    
                    <div style="margin-bottom: 12px;">
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                            <input type="checkbox" id="sts_send_to_self_widget" name="sts_send_to_self" value="1" style="width: auto; margin: 0;" />
                            <?php esc_html_e( 'שלחי לאימייל שלי', 'sts-gift-card' ); ?>
                        </label>
                    </div>

                    <div id="sts_recipient_email_field_widget">
                        <label for="sts_recipient_email_widget" style="display: block; margin-bottom: 8px; font-weight: 600;">
                            <?php esc_html_e( 'אימייל מקבלת המתנה', 'sts-gift-card' ); ?>
                            <span style="color: red;">*</span>
                        </label>
                        <input 
                            type="email" 
                            id="sts_recipient_email_widget" 
                            name="sts_recipient_email" 
                            class="input-text" 
                            required
                            style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px;"
                        />
                    </div>
                    
                </div>
            </div>
            <div class="sts-gift-card-message-field" style="display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 20px;">
                <label for="sts_recipient_message_widget" style="display: block; margin-bottom: 8px; font-weight: 600;">
                    <?php esc_html_e( 'הקדשה אישית', 'sts-gift-card' ); ?>
                </label>
                <textarea 
                    id="sts_recipient_message_widget" 
                    name="sts_recipient_message" 
                    rows="4" 
                    class="input-text" 
                    style="flex: 1; width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 0.25rem;"
                ></textarea>
            </div>
        
            <input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $product_id ); ?>" />
            <input type="hidden" name="product_id" value="<?php echo esc_attr( $product_id ); ?>" />
            
        </div>
        
        <div style="margin-top: 15px;">
            <button type="button" id="sts_preview_gift_card_btn" class="button" style="padding: 10px 20px;">
                <?php esc_html_e( 'תצוגה מקדימה', 'sts-gift-card' ); ?>
            </button>
        </div>
        
        <!-- Modal -->
        <div id="sts_gift_card_preview_modal" class="sts-gift-card-modal">
            <div class="sts-gift-card-modal-content">
                <div class="sts-gift-card-modal-header">
                    <h2 class="sts-gift-card-modal-title"><?php esc_html_e( 'תצוגה מקדימה - אימייל הגיפט קארד', 'sts-gift-card' ); ?></h2>
                    <span id="sts_close_preview_modal" class="sts-gift-card-modal-close">&times;</span>
                </div>
                <div id="sts_preview_email_content" class="sts-gift-card-modal-body">
                    <!-- Preview content will be loaded here via AJAX -->
                </div>
                <div class="sts-gift-card-modal-footer">
                    <button type="button" id="sts_close_preview_btn" class="button sts-gift-card-modal-close-btn">
                        <?php esc_html_e( 'סגור', 'sts-gift-card' ); ?>
                    </button>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var loadingPreview = false;
            
            // Toggle email field based on "send to self" checkbox
            $('[name="sts_send_to_self"]').on('change', function() {
                var $form = $(this).closest('form');
                if ($(this).is(':checked')) {
                    $form.find('#sts_recipient_email_field_widget').hide();
                    $form.find('[name="sts_recipient_email"]').prop('required', false);
                } else {
                    $form.find('#sts_recipient_email_field_widget').show();
                    $form.find('[name="sts_recipient_email"]').prop('required', true);
                }
                updatePreviewButton($form);
            });

            // Function to check if required fields are filled
            function checkRequiredFields($form) {
                $form = $form || $('[name="add-to-cart"]').closest('form').first();
                var amount     = $form.find('[name="sts_gift_card_amount"]').val();
                var name       = $form.find('[name="sts_recipient_name"]').val();
                var sendToSelf = $form.find('[name="sts_send_to_self"]').is(':checked');
                var email      = sendToSelf ? 'self' : $form.find('[name="sts_recipient_email"]').val();
                
                return amount && name && email;
            }
            
            // Enable/disable preview button based on required fields
            function updatePreviewButton($form) {
                if (checkRequiredFields($form)) {
                    $('#sts_preview_gift_card_btn').prop('disabled', false).css('opacity', '1').css('cursor', 'pointer');
                } else {
                    $('#sts_preview_gift_card_btn').prop('disabled', true).css('opacity', '0.5').css('cursor', 'not-allowed');
                }
            }
            
            // Check on page load
            updatePreviewButton();
            
            // Check on field changes
            $('[name="sts_gift_card_amount"], [name="sts_recipient_name"], [name="sts_recipient_email"]').on('input change', function() {
                updatePreviewButton($(this).closest('form'));
            });
            
            $('#sts_preview_gift_card_btn').on('click', function() {
                if (loadingPreview || !checkRequiredFields()) return;
                
                var $form       = $(this).closest('form');
                var amount      = $form.find('[name="sts_gift_card_amount"]').val();
                var name        = $form.find('[name="sts_recipient_name"]').val();
                var sendToSelf  = $form.find('[name="sts_send_to_self"]').is(':checked') ? '1' : '0';
                var email       = sendToSelf === '1' ? '' : $form.find('[name="sts_recipient_email"]').val();
                var message     = $form.find('[name="sts_recipient_message"]').val();
                
                loadingPreview = true;
                $('#sts_preview_email_content').html('<p style="text-align: center;"><?php esc_html_e( 'Loading preview...', 'sts-gift-card' ); ?></p>');
                $('#sts_gift_card_preview_modal').show();
                
                $.ajax({
                    url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
                    type: 'POST',
                    data: {
                        action: 'sts_preview_gift_card_email',
                        amount: amount,
                        recipient_name: name,
                        recipient_email: email,
                        send_to_self: sendToSelf,
                        recipient_message: message,
                        nonce: '<?php echo wp_create_nonce( 'sts_preview_nonce' ); ?>'
                    },
                    success: function(response) {
                        loadingPreview = false;
                        if (response.success) {
                            $('#sts_preview_email_content').html(response.data.html);
                        } else {
                            $('#sts_preview_email_content').html('<p style="color: red;">' + response.data.message + '</p>');
                        }
                    },
                    error: function() {
                        loadingPreview = false;
                        $('#sts_preview_email_content').html('<p style="color: red;"><?php esc_html_e( 'Error loading preview.', 'sts-gift-card' ); ?></p>');
                    }
                });
            });
            
            $('#sts_close_preview_modal, #sts_close_preview_btn').on('click', function() {
                $('#sts_gift_card_preview_modal').hide();
            });
            
            $(window).on('click', function(event) {
                if ($(event.target).is('#sts_gift_card_preview_modal')) {
                    $('#sts_gift_card_preview_modal').hide();
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Validate gift card amount on add to cart
     */
    public function validate_gift_card_amount( $passed, $product_id, $quantity ) {
        if ( ! $this->is_gift_card_product( $product_id ) ) {
            return $passed;
        }
        
        $amount = isset( $_POST['sts_gift_card_amount'] ) ? floatval( $_POST['sts_gift_card_amount'] ) : 0;
        $min_amount = $this->get_minimum_amount();
        
        if ( $amount < $min_amount ) {
            wc_add_notice( 
                sprintf( 
                    esc_html__( 'שווי הגיפט קארד חייב להיות לפחות %s ₪.', 'sts-gift-card' ), 
                    number_format( $min_amount, 2 ) 
                ), 
                'error' 
            );
            $passed = false;
        }
        
        // Validate recipient fields (always required)
        $send_to_self    = isset( $_POST['sts_send_to_self'] ) && '1' === $_POST['sts_send_to_self'];
        $recipient_name  = isset( $_POST['sts_recipient_name'] ) ? sanitize_text_field( $_POST['sts_recipient_name'] ) : '';
        $recipient_email = isset( $_POST['sts_recipient_email'] ) ? sanitize_email( $_POST['sts_recipient_email'] ) : '';
        
        if ( empty( $recipient_name ) ) {
            wc_add_notice( esc_html__( 'Recipient name is required.', 'sts-gift-card' ), 'error' );
            $passed = false;
        }
        
        if ( ! $send_to_self && ( empty( $recipient_email ) || ! is_email( $recipient_email ) ) ) {
            wc_add_notice( esc_html__( 'Valid recipient email is required.', 'sts-gift-card' ), 'error' );
            $passed = false;
        }
        
        return $passed;
    }
    
    /**
     * Add gift card data to cart item
     */
    public function add_gift_card_data_to_cart( $cart_item_data, $product_id, $variation_id ) {
        if ( ! $this->is_gift_card_product( $product_id ) ) {
            return $cart_item_data;
        }
        
        $amount = isset( $_POST['sts_gift_card_amount'] ) ? floatval( $_POST['sts_gift_card_amount'] ) : 0;
        
        $send_to_self = isset( $_POST['sts_send_to_self'] ) && '1' === $_POST['sts_send_to_self'];

        $cart_item_data['sts_gift_card_amount']    = $amount;
        $cart_item_data['sts_gift_card_delivery']  = 'friend';
        $cart_item_data['sts_send_to_self']        = $send_to_self ? '1' : '0';
        $cart_item_data['sts_recipient_name']      = isset( $_POST['sts_recipient_name'] ) ? sanitize_text_field( $_POST['sts_recipient_name'] ) : '';
        $cart_item_data['sts_recipient_email']     = $send_to_self ? '' : ( isset( $_POST['sts_recipient_email'] ) ? sanitize_email( $_POST['sts_recipient_email'] ) : '' );
        $cart_item_data['sts_recipient_message']   = isset( $_POST['sts_recipient_message'] ) ? sanitize_textarea_field( $_POST['sts_recipient_message'] ) : '';
        
        return $cart_item_data;
    }
    
    /**
     * Set custom price for gift card items
     */
    public function set_gift_card_price( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        
        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( isset( $cart_item['sts_gift_card_amount'] ) ) {
                $amount = floatval( $cart_item['sts_gift_card_amount'] );
                $cart_item['data']->set_price( $amount );
            }
        }
    }
    
    /**
     * Display gift card price in cart
     */
    public function display_gift_card_price( $price, $cart_item ) {
        if ( isset( $cart_item['sts_gift_card_amount'] ) ) {
            $amount = floatval( $cart_item['sts_gift_card_amount'] );
            return wc_price( $amount );
        }
        return $price;
    }
    
    /**
     * Display gift card subtotal in cart
     */
    public function display_gift_card_subtotal( $subtotal, $cart_item ) {
        if ( isset( $cart_item['sts_gift_card_amount'] ) ) {
            $amount = floatval( $cart_item['sts_gift_card_amount'] );
            return wc_price( $amount );
        }
        return $subtotal;
    }
    
    /**
     * Generate unique gift card code
     */
    private function generate_gift_card_code() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sts_gift_cards';
        
        do {
            $code = strtoupper( wp_generate_password( 12, false ) );
            $code = preg_replace( '/[^A-Z0-9]/', '', $code ); // Remove special chars
            $code = substr( $code, 0, 12 ); // Ensure 12 chars
            
            // Check if code exists
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE code = %s",
                $code
            ) );
        } while ( $exists > 0 );
        
        return $code;
    }
    
    /**
     * Create gift card on order completion
     */
    public function create_gift_card_on_order_completion( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        
        // Check if gift cards already created for this order
        $gift_cards_created = $order->get_meta( '_sts_gift_cards_created' );
        if ( $gift_cards_created ) {
            return; // Already created
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'sts_gift_cards';
        
        $buyer_email = $order->get_billing_email();
        $currency = $order->get_currency();
        
        foreach ( $order->get_items() as $item_id => $item ) {
            $product_id = $item->get_product_id();
            
            if ( ! $this->is_gift_card_product( $product_id ) ) {
                continue;
            }
            
            $gift_card_amount = $item->get_meta( 'sts_gift_card_amount' );
            if ( ! $gift_card_amount ) {
                // Try to get from cart item data
                $gift_card_amount = $item->get_meta( '_sts_gift_card_amount' );
            }
            
            if ( ! $gift_card_amount ) {
                // Fallback to item total
                $gift_card_amount = $item->get_total();
            }
            
            // Determine recipient email — use buyer email when "send to self" was selected
            $send_to_self = $item->get_meta( 'sts_send_to_self' );
            if ( ! $send_to_self ) {
                $send_to_self = $item->get_meta( '_sts_send_to_self' );
            }

            if ( '1' === $send_to_self ) {
                $recipient_email = $buyer_email;
            } else {
                $recipient_email = $item->get_meta( 'sts_recipient_email' );
                if ( ! $recipient_email ) {
                    $recipient_email = $item->get_meta( '_sts_recipient_email' );
                }
            }
            
            $recipient_name = $item->get_meta( 'sts_recipient_name' );
            if ( ! $recipient_name ) {
                $recipient_name = $item->get_meta( '_sts_recipient_name' );
            }
            
            $recipient_message = $item->get_meta( 'sts_recipient_message' );
            if ( ! $recipient_message ) {
                $recipient_message = $item->get_meta( '_sts_recipient_message' );
            }
            
            $code = $this->generate_gift_card_code();
            $expiry_days = $this->get_expiry_days();
            $expires_at = date( 'Y-m-d H:i:s', strtotime( '+' . $expiry_days . ' days' ) );
            $created_at = current_time( 'mysql' );
            
            $wpdb->insert(
                $table_name,
                array(
                    'code' => $code,
                    'initial_amount' => $gift_card_amount,
                    'balance' => $gift_card_amount,
                    'currency' => $currency,
                    'status' => 'active',
                    'buyer_email' => $buyer_email,
                    'recipient_email' => $recipient_email,
                    'recipient_name' => $recipient_name,
                    'recipient_message' => $recipient_message,
                    'expires_at' => $expires_at,
                    'order_id' => $order_id,
                    'created_at' => $created_at,
                ),
                array( '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
            );
            
            $gift_card_id = $wpdb->insert_id;
            
            // Store gift card ID in order item meta
            $item->update_meta_data( '_sts_gift_card_id', $gift_card_id );
            $item->update_meta_data( '_sts_gift_card_code', $code );
            $item->save_meta_data();
            
            // Send email
            $this->send_gift_card_email( $gift_card_id, $order_id );
        }
        
        // Mark gift cards as created
        $order->update_meta_data( '_sts_gift_cards_created', true );
        $order->save();
    }
    
    /**
     * Create gift card on payment (for orders that complete on payment)
     */
    public function create_gift_card_on_payment( $order_id ) {
        $this->create_gift_card_on_order_completion( $order_id );
    }
    
    /**
     * Save gift card data to order item
     */
    public function save_gift_card_data_to_order_item( $item, $cart_item_key, $values, $order ) {
        // Only process gift card products - check if gift card data exists
        if ( ! isset( $values['sts_gift_card_amount'] ) ) {
            return; // Not a gift card product, skip
        }
        
        $item->update_meta_data( '_sts_gift_card_amount', $values['sts_gift_card_amount'] );
        
        // Only set delivery to friend for gift card products
        $item->update_meta_data( '_sts_gift_card_delivery', 'friend' );
        
        if ( isset( $values['sts_send_to_self'] ) ) {
            $item->update_meta_data( '_sts_send_to_self', $values['sts_send_to_self'] );
        }

        if ( isset( $values['sts_recipient_name'] ) ) {
            $item->update_meta_data( '_sts_recipient_name', $values['sts_recipient_name'] );
        }
        
        if ( isset( $values['sts_recipient_email'] ) ) {
            $item->update_meta_data( '_sts_recipient_email', $values['sts_recipient_email'] );
        }
        
        if ( isset( $values['sts_recipient_message'] ) ) {
            $item->update_meta_data( '_sts_recipient_message', $values['sts_recipient_message'] );
        }
    }
    
    /**
     * Send gift card email
     */
    private function send_gift_card_email( $gift_card_id, $order_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sts_gift_cards';
        
        $gift_card = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $gift_card_id
        ) );
        
        if ( ! $gift_card ) {
            return;
        }
        
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        
        // Always send to friend (recipient)
        if ( ! empty( $gift_card->recipient_email ) ) {
            do_action( 'sts_gift_card_email', $gift_card_id, $order_id, true );
        }
    }
    
    /**
     * Add gift card checkout section
     */
    public function add_gift_card_checkout_section() {
        // Check if gift card is already applied from session
        $applied_gift_card = $this->get_applied_gift_card();
        $is_applied = ! empty( $applied_gift_card );
        $applied_code = '';
        $applied_message = '';
        $remaining_balance_text = '';
        
        if ( $is_applied ) {
            $applied_code = $applied_gift_card->code;
            
            // Calculate remaining balance after potential use - include shipping
            if ( WC()->cart ) {
                WC()->cart->calculate_totals(); // Ensure totals are calculated
                
                $cart_total = WC()->cart->get_subtotal();
                
                // Add taxes
                if ( WC()->cart->get_subtotal_tax() > 0 ) {
                    $cart_total += WC()->cart->get_subtotal_tax();
                }
                
                // Add shipping costs
                $shipping_total = WC()->cart->get_shipping_total();
                if ( $shipping_total > 0 ) {
                    $cart_total += $shipping_total;
                }
                
                // Add shipping taxes if any
                $shipping_tax = WC()->cart->get_shipping_tax();
                if ( $shipping_tax > 0 ) {
                    $cart_total += $shipping_tax;
                }
                
                $discount_amount = min( floatval( $applied_gift_card->balance ), $cart_total );
                $remaining_balance = floatval( $applied_gift_card->balance ) - $discount_amount;
                
                // Format prices as plain text (no HTML, decode entities)
                $balance_text = html_entity_decode( strip_tags( wc_price( $applied_gift_card->balance ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $remaining_text = html_entity_decode( strip_tags( wc_price( $remaining_balance ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                
                $applied_message = sprintf(
                    __( 'הגיפט קארד הוחל בהצלחה! שווי הגיפט קארד: %s', 'sts-gift-card' ),
                    $balance_text
                );
                $remaining_balance_text = __( 'יתרה לאחר רכישה:', 'sts-gift-card' ) . ' ' . $remaining_text;
            }
        }
        ?>
        <div class="sts-gift-card-checkout-section" style="margin-bottom: 20px; padding: 15px; border-radius: 4px;">
            <h3 style="margin-bottom: 10px;"><?php esc_html_e( 'יש לך גיפט-קארד?', 'sts-gift-card' ); ?></h3>
            <div class="sts-gift-card-apply-form" style="display: flex; gap: 10px; align-items: flex-end;">
                <div style="flex: 1;">
                    <input 
                        type="text" 
                        id="sts_gift_card_code_input" 
                        name="sts_gift_card_code" 
                        placeholder="<?php esc_attr_e( 'הזן קוד גיפט קארד', 'sts-gift-card' ); ?>"
                        value="<?php echo esc_attr( $applied_code ); ?>"
                        style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;"
                        <?php echo $is_applied ? 'disabled' : ''; ?>
                    />
                </div>
                <button 
                    type="button" 
                    id="sts_apply_gift_card_btn" 
                    class="button"
                    style="padding: 10px 20px;"
                >
                    <?php esc_html_e( 'החל', 'sts-gift-card' ); ?>
                </button>
            </div>
            <div id="sts_gift_card_message" style="margin-top: 10px;"></div>
            <div id="sts_gift_card_applied" style="margin-top: 10px; padding: 10px; border-radius: 4px; background: #f0f0f0; <?php echo $is_applied ? '' : 'display: none;'; ?>">
                <div style="margin-bottom: 10px;">
                    <span id="sts_gift_card_applied_text"><?php echo esc_html( $applied_message ); ?></span>
                </div>
                <?php if ( $remaining_balance_text ) : ?>
                <div id="sts_gift_card_balance_info" style="margin-bottom: 10px; font-weight: 600; color: #0073aa;"><?php echo esc_html( $remaining_balance_text ); ?></div>
                <?php else : ?>
                <div id="sts_gift_card_balance_info" style="margin-bottom: 10px; font-weight: 600; color: #0073aa;"></div>
                <?php endif; ?>
                <button type="button" id="sts_remove_gift_card_btn" class="button" style="margin-right: 10px;">
                    <?php esc_html_e( 'הסר', 'sts-gift-card' ); ?>
                </button>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var applying = false;
            
            $('#sts_apply_gift_card_btn').on('click', function() {
                if (applying) return;
                
                var code = $('#sts_gift_card_code_input').val().trim().toUpperCase();
                if (!code) {
                    $('#sts_gift_card_message').html('<span style="color: red;"><?php esc_html_e( 'Please enter a gift card code.', 'sts-gift-card' ); ?></span>');
                    return;
                }
                
                applying = true;
                $('#sts_apply_gift_card_btn').prop('disabled', true).text('<?php esc_html_e( 'מחיל...', 'sts-gift-card' ); ?>');
                $('#sts_gift_card_message').html('');
                
                $.ajax({
                    url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
                    type: 'POST',
                    data: {
                        action: 'sts_apply_gift_card',
                        code: code,
                        nonce: '<?php echo wp_create_nonce( 'sts_gift_card_nonce' ); ?>'
                    },
                    success: function(response) {
                        applying = false;
                        $('#sts_apply_gift_card_btn').prop('disabled', false).text('<?php esc_html_e( 'החל', 'sts-gift-card' ); ?>');
                        
                        if (response.success) {
                            // $('#sts_gift_card_message').html('<span style="color: green;">' + response.data.message + '</span>');
                            $('#sts_gift_card_applied_text').text(response.data.message);
                            if (response.data.remaining_balance !== undefined) {
                                var balanceText = '<?php esc_html_e( 'יתרה לאחר רכישה:', 'sts-gift-card' ); ?> ' + response.data.remaining_balance;
                                $('#sts_gift_card_balance_info').text(balanceText);
                            }
                            $('#sts_gift_card_applied').show();
                            $('#sts_gift_card_code_input').prop('disabled', true);
                            $('body').trigger('update_checkout');
                        } else {
                            $('#sts_gift_card_message').html('<span style="color: red;">' + response.data.message + '</span>');
                        }
                    },
                    error: function() {
                        applying = false;
                        $('#sts_apply_gift_card_btn').prop('disabled', false).text('<?php esc_html_e( 'החל', 'sts-gift-card' ); ?>');
                        $('#sts_gift_card_message').html('<span style="color: red;"><?php esc_html_e( 'An error occurred. Please try again.', 'sts-gift-card' ); ?></span>');
                    }
                });
            });
            
            $('#sts_remove_gift_card_btn').on('click', function() {
                $.ajax({
                    url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
                    type: 'POST',
                    data: {
                        action: 'sts_remove_gift_card',
                        nonce: '<?php echo wp_create_nonce( 'sts_gift_card_nonce' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#sts_gift_card_applied').hide();
                            $('#sts_gift_card_code_input').val('').prop('disabled', false);
                            $('#sts_gift_card_message').html('<span style="color: green;">' + response.data.message + '</span>');
                            $('body').trigger('update_checkout');
                        }
                    }
                });
            });
            
            // Handle remove button click from fee row (delegated event for dynamically added content)
            $(document).on('click', '.sts-remove-gift-card-fee', function(e) {
                e.preventDefault();
                
                var $button = $(this);
                $button.prop('disabled', true).text('<?php esc_html_e( 'מסיר...', 'sts-gift-card' ); ?>');
                
                $.ajax({
                    url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
                    type: 'POST',
                    data: {
                        action: 'sts_remove_gift_card',
                        nonce: '<?php echo wp_create_nonce( 'sts_gift_card_nonce' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#sts_gift_card_applied').hide();
                            $('#sts_gift_card_code_input').val('').prop('disabled', false);
                            $('#sts_gift_card_message').html('<span style="color: green;">' + response.data.message + '</span>');
                            $('body').trigger('update_checkout');
                        }
                        $button.prop('disabled', false).text('<?php esc_html_e( 'הסר', 'sts-gift-card' ); ?>');
                    },
                    error: function() {
                        $button.prop('disabled', false).text('<?php esc_html_e( 'הסר', 'sts-gift-card' ); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX handler to apply gift card
     */
    public function ajax_apply_gift_card() {
        check_ajax_referer( 'sts_gift_card_nonce', 'nonce' );
        
        $code = isset( $_POST['code'] ) ? sanitize_text_field( $_POST['code'] ) : '';
        $code = strtoupper( trim( $code ) );
        
        if ( empty( $code ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a gift card code.', 'sts-gift-card' ) ) );
        }
        
        // Validate gift card
        $validation = $this->validate_gift_card_code( $code );
        
        if ( ! $validation['valid'] ) {
            wp_send_json_error( array( 'message' => $validation['message'] ) );
        }
        
        // Store in session
        WC()->session->set( 'sts_applied_gift_card_code', $code );
        
        // Calculate remaining balance after potential use - include shipping
        WC()->cart->calculate_totals(); // Ensure totals are calculated
        
        $cart_total = WC()->cart->get_subtotal();
        
        // Add taxes
        if ( WC()->cart->get_subtotal_tax() > 0 ) {
            $cart_total += WC()->cart->get_subtotal_tax();
        }
        
        // Add shipping costs
        $shipping_total = WC()->cart->get_shipping_total();
        if ( $shipping_total > 0 ) {
            $cart_total += $shipping_total;
        }
        
        // Add shipping taxes if any
        $shipping_tax = WC()->cart->get_shipping_tax();
        if ( $shipping_tax > 0 ) {
            $cart_total += $shipping_tax;
        }
        
        $discount_amount = min( floatval( $validation['balance'] ), $cart_total );
        $remaining_balance = floatval( $validation['balance'] ) - $discount_amount;
        
        // Format prices as plain text (no HTML, decode entities)
        $balance_text = html_entity_decode( strip_tags( wc_price( $validation['balance'] ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $remaining_text = html_entity_decode( strip_tags( wc_price( $remaining_balance ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        
        wp_send_json_success( array( 
            'message' => sprintf( 
                __( 'הגיפט קארד הוחל בהצלחה! שווי הגיפט קארד: %s', 'sts-gift-card' ), 
                $balance_text
            ),
            'remaining_balance' => $remaining_text,
            'remaining_balance_html' => wc_price( $remaining_balance )
        ) );
    }
    
    /**
     * AJAX handler to remove gift card
     */
    public function ajax_remove_gift_card() {
        check_ajax_referer( 'sts_gift_card_nonce', 'nonce' );
        
        WC()->session->__unset( 'sts_applied_gift_card_code' );
        
        wp_send_json_success( array( 'message' => __( 'Gift card removed.', 'sts-gift-card' ) ) );
    }
    
    /**
     * Validate gift card code
     */
    private function validate_gift_card_code( $code ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sts_gift_cards';
        
        $gift_card = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table_name WHERE code = %s",
            $code
        ) );
        
        if ( ! $gift_card ) {
            return array(
                'valid' => false,
                'message' => __( 'Invalid gift card code.', 'sts-gift-card' )
            );
        }
        
        if ( $gift_card->status !== 'active' ) {
            return array(
                'valid' => false,
                'message' => __( 'This gift card is no longer active.', 'sts-gift-card' )
            );
        }
        
        if ( $gift_card->balance <= 0 ) {
            return array(
                'valid' => false,
                'message' => __( 'This gift card has no remaining balance.', 'sts-gift-card' )
            );
        }
        
        if ( $gift_card->expires_at ) {
            $expires = strtotime( $gift_card->expires_at );
            if ( $expires < current_time( 'timestamp' ) ) {
                return array(
                    'valid' => false,
                    'message' => __( 'This gift card has expired.', 'sts-gift-card' )
                );
            }
        }
        
        return array(
            'valid' => true,
            'gift_card' => $gift_card,
            'balance' => floatval( $gift_card->balance ),
            'message' => __( 'Gift card is valid.', 'sts-gift-card' )
        );
    }
    
    /**
     * Get applied gift card from session
     */
    private function get_applied_gift_card() {
        $code = WC()->session->get( 'sts_applied_gift_card_code' );
        if ( ! $code ) {
            return null;
        }
        
        $validation = $this->validate_gift_card_code( $code );
        if ( ! $validation['valid'] ) {
            WC()->session->__unset( 'sts_applied_gift_card_code' );
            return null;
        }
        
        return $validation['gift_card'];
    }
    
    /**
     * Check if gift card covers entire order (public method for templates)
     * 
     * @return bool True if gift card is applied and covers entire cart total
     */
    public function gift_card_covers_entire_order() {
        $gift_card = $this->get_applied_gift_card();
        if ( ! $gift_card ) {
            return false;
        }
        
        $cart = WC()->cart;
        if ( ! $cart || $cart->is_empty() ) {
            return false;
        }
        
        // Check if cart total is 0 or less (after gift card discount)
        $cart_total = $cart->get_total( 'edit' );
        return $cart_total <= 0.01;
    }
    
    /**
     * Apply gift card discount
     */
    public function apply_gift_card_discount( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        
        $gift_card = $this->get_applied_gift_card();
        if ( ! $gift_card ) {
            return;
        }
        
        // Calculate cart total including subtotal, taxes, and shipping
        $cart_total = $cart->get_subtotal();
        
        // Add taxes
        if ( $cart->get_subtotal_tax() > 0 ) {
            $cart_total += $cart->get_subtotal_tax();
        }
        
        // Add shipping costs
        $shipping_total = $cart->get_shipping_total();
        if ( $shipping_total > 0 ) {
            $cart_total += $shipping_total;
        }
        
        // Add shipping taxes if any
        $shipping_tax = $cart->get_shipping_tax();
        if ( $shipping_tax > 0 ) {
            $cart_total += $shipping_tax;
        }
        
        // Calculate discount amount
        $discount_amount = min( floatval( $gift_card->balance ), $cart_total );
        
        if ( $discount_amount > 0 ) {
            // Check if fee already exists to avoid duplicates
            $existing_fees = $cart->get_fees();
            $fee_exists = false;
            foreach ( $existing_fees as $fee ) {
                if ( $fee->name === __( 'Gift Card Discount', 'sts-gift-card' ) ) {
                    $fee_exists = true;
                    break;
                }
            }
            
            if ( ! $fee_exists ) {
                // Add as negative fee (discount)
                $cart->add_fee( 
                    __( 'Gift Card Discount', 'sts-gift-card' ), 
                    -$discount_amount,
                    false // Not taxable
                );
            }
        }
    }
    
    /**
     * Recalculate gift card discount after totals are calculated (ensures shipping is included)
     */
    public function recalculate_gift_card_discount() {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        
        $cart = WC()->cart;
        if ( ! $cart ) {
            return;
        }
        
        $gift_card = $this->get_applied_gift_card();
        if ( ! $gift_card ) {
            return;
        }
        
        // Calculate total including everything (subtotal, taxes, shipping, shipping taxes)
        $cart_total = $cart->get_subtotal();
        
        if ( $cart->get_subtotal_tax() > 0 ) {
            $cart_total += $cart->get_subtotal_tax();
        }
        
        $shipping_total = $cart->get_shipping_total();
        if ( $shipping_total > 0 ) {
            $cart_total += $shipping_total;
        }
        
        $shipping_tax = $cart->get_shipping_tax();
        if ( $shipping_tax > 0 ) {
            $cart_total += $shipping_tax;
        }
        
        // Get existing fees and update gift card discount
        $fees = $cart->get_fees();
        $discount_amount = min( floatval( $gift_card->balance ), $cart_total );
        $fee_updated = false;
        
        foreach ( $fees as $fee_key => $fee ) {
            if ( $fee->name === __( 'Gift Card Discount', 'sts-gift-card' ) ) {
                if ( $discount_amount > 0 ) {
                    $fee->amount = -$discount_amount;
                    $fee_updated = true;
                } else {
                    unset( $fees[ $fee_key ] );
                    $fee_updated = true;
                }
                break;
            }
        }
        
        // Only update fees if we found and modified the gift card fee
        if ( $fee_updated ) {
            $cart->fees_api()->set_fees( $fees );
        }
    }
    
    /**
     * Add remove button to gift card discount fee display
     */
    public function add_remove_button_to_gift_card_fee( $fee_html, $fee ) {
        // Check if this is our gift card discount fee
        if ( $fee->name === __( 'Gift Card Discount', 'sts-gift-card' ) ) {
            // Create remove button
            $remove_button = sprintf(
                '<button type="button" class="sts-remove-gift-card-fee" style="margin-right: 10px; padding: 5px 10px; font-size: 12px; cursor: pointer; background: #dc3232; color: #fff; border: none; border-radius: 3px;">%s</button>',
                esc_html__( 'הסר', 'sts-gift-card' )
            );
            
            // Insert button after fee name
            $fee_html = str_replace( 
                esc_html( $fee->name ), 
                esc_html( $fee->name ) . ' ' . $remove_button, 
                $fee_html 
            );
        }
        
        return $fee_html;
    }
    
    /**
     * Determine if cart needs payment when gift card covers everything
     */
    public function cart_needs_payment( $needs_payment, $cart ) {
        // If cart already doesn't need payment, return early
        if ( ! $needs_payment ) {
            return $needs_payment;
        }
        
        // Check if gift card is applied
        $gift_card = $this->get_applied_gift_card();
        if ( ! $gift_card ) {
            return $needs_payment;
        }
        
        // Get the final cart total (already includes all fees, taxes, shipping, and discounts)
        $cart_total = $cart->get_total( 'edit' );
        
        // If cart total is 0 or less (after gift card discount), no payment needed
        // Use small epsilon (0.01) for floating point comparison
        if ( $cart_total <= 0.01 ) {
            return false;
        }
        
        return $needs_payment;
    }
    
    /**
     * Filter payment gateways - ensure gateways are available when needed
     */
    public function filter_payment_gateways( $gateways ) {
        // Only filter on frontend
        if ( is_admin() ) {
            return $gateways;
        }
        
        $cart = WC()->cart;
        if ( ! $cart || $cart->is_empty() ) {
            return $gateways;
        }
        
        // If cart total is 0 or less and gift card is applied, allow order to proceed
        // WooCommerce will handle this via cart_needs_payment filter
        // But we ensure gateways aren't incorrectly filtered
        if ( $cart->get_total() <= 0 ) {
            $gift_card = $this->get_applied_gift_card();
            if ( $gift_card ) {
                // Don't filter out gateways - let WooCommerce handle free orders
                // The cart_needs_payment filter will prevent payment requirement
            }
        }
        
        return $gateways;
    }
    
    /**
     * Show payment section even for free orders (so Place Order button is visible)
     */
    public function show_payment_section_for_free_orders( $show ) {
        // If payment section is already shown, return early
        if ( $show ) {
            return $show;
        }
        
        // Check if gift card is applied and covers the order
        $cart = WC()->cart;
        if ( ! $cart || $cart->is_empty() ) {
            return $show;
        }
        
        $gift_card = $this->get_applied_gift_card();
        if ( ! $gift_card ) {
            return $show;
        }
        
        // If cart total is 0 or less, show payment section so Place Order button appears
        if ( $cart->get_total() <= 0.01 ) {
            return true;
        }
        
        return $show;
    }
    
    /**
     * Add Place Order button for free orders if payment section is hidden
     */
    public function add_place_order_button_for_free_orders() {
        $cart = WC()->cart;
        if ( ! $cart || $cart->is_empty() ) {
            return;
        }
        
        // Check if this is a free order with gift card
        if ( $cart->get_total() > 0.01 ) {
            return;
        }
        
        $gift_card = $this->get_applied_gift_card();
        if ( ! $gift_card ) {
            return;
        }
        
        // Get order button text - use Hebrew
        $order_button_text = apply_filters( 'woocommerce_order_button_text', __( 'שליחת הזמנה', 'sts-gift-card' ) );
        
        // Output Place Order button
        echo '<div class="form-row place-order" style="margin-top: 1.5rem;">';
        echo '<noscript>';
        printf( esc_html__( 'Since your browser does not support JavaScript, or it is disabled, please ensure you click the %1$sUpdate totals%2$s button before placing your order.', 'woocommerce' ), '<em>', '</em>' );
        echo '<br/><button type="submit" class="button alt" name="woocommerce_checkout_update_totals" value="' . esc_attr__( 'Update totals', 'woocommerce' ) . '">' . esc_html__( 'Update totals', 'woocommerce' ) . '</button>';
        echo '</noscript>';
        
        // Terms and conditions
        wc_get_template( 'checkout/terms.php' );
        
        // Place Order button
        echo apply_filters( 
            'woocommerce_order_button_html', 
            '<button type="submit" class="button alt" name="woocommerce_checkout_place_order" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '">' . esc_html( $order_button_text ) . '</button>'
        );
        
        wp_nonce_field( 'woocommerce-process_checkout', 'woocommerce-process-checkout-nonce' );
        echo '</div>';
    }
    
    /**
     * Process free orders - mark as paid automatically when gift card covers everything
     */
    public function process_free_order( $order_id, $posted_data, $order ) {
        // Check if order total is 0 or less and gift card was used
        if ( $order->get_total() <= 0.01 ) {
            $gift_card_code = $order->get_meta( '_sts_gift_card_code' );
            
            if ( $gift_card_code ) {
                // Mark order as paid
                $order->payment_complete();
                
                // Update order status to processing (or completed if you prefer)
                // Processing allows for fulfillment workflow
                $order->update_status( 'processing', __( 'Order paid with gift card. No payment required.', 'sts-gift-card' ) );
                
                // Add order note
                $order->add_order_note( __( 'Order fully paid with gift card. No payment gateway required.', 'sts-gift-card' ) );
                
                // Update gift card balance immediately
                // This will be called again via payment_complete hook, but we ensure it happens
                $this->update_gift_card_balance( $order_id );
            }
        }
    }
    
    /**
     * Store gift card usage in order
     */
    public function store_gift_card_usage_in_order( $order, $data ) {
        $gift_card = $this->get_applied_gift_card();
        if ( ! $gift_card ) {
            return;
        }
        
        // Calculate discount amount (same as in apply_gift_card_discount) - include shipping
        WC()->cart->calculate_totals();
        
        $cart_total = WC()->cart->get_subtotal();
        
        // Add taxes
        if ( WC()->cart->get_subtotal_tax() > 0 ) {
            $cart_total += WC()->cart->get_subtotal_tax();
        }
        
        // Add shipping costs
        $shipping_total = WC()->cart->get_shipping_total();
        if ( $shipping_total > 0 ) {
            $cart_total += $shipping_total;
        }
        
        // Add shipping taxes if any
        $shipping_tax = WC()->cart->get_shipping_tax();
        if ( $shipping_tax > 0 ) {
            $cart_total += $shipping_tax;
        }
        
        $discount_amount = min( floatval( $gift_card->balance ), $cart_total );
        
        $order->update_meta_data( '_sts_gift_card_code', $gift_card->code );
        $order->update_meta_data( '_sts_gift_card_discount', $discount_amount );
        $order->update_meta_data( '_sts_gift_card_id', $gift_card->id );
    }
    
    /**
     * Update gift card balance on order completion
     */
    public function update_gift_card_balance( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        
        $gift_card_code = $order->get_meta( '_sts_gift_card_code' );
        if ( ! $gift_card_code ) {
            return;
        }
        
        // Check if balance already updated to prevent duplicate processing
        $balance_updated = $order->get_meta( '_sts_gift_card_balance_updated' );
        if ( $balance_updated ) {
            return; // Already updated
        }
        
        $discount_amount = floatval( $order->get_meta( '_sts_gift_card_discount' ) );
        if ( $discount_amount <= 0 ) {
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'sts_gift_cards';
        
        // Use row-level locking to prevent race conditions
        $wpdb->query( 'START TRANSACTION' );
        
        // Lock the row for update
        $gift_card = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table_name WHERE code = %s FOR UPDATE",
            $gift_card_code
        ) );
        
        if ( ! $gift_card ) {
            $wpdb->query( 'ROLLBACK' );
            return;
        }
        
        // Double-check balance is sufficient
        $current_balance = floatval( $gift_card->balance );
        if ( $current_balance < $discount_amount ) {
            $wpdb->query( 'ROLLBACK' );
            // Add order note about insufficient balance
            $order->add_order_note( sprintf( 
                __( 'Gift card balance update failed. Required: %s, Available: %s', 'sts-gift-card' ),
                wc_price( $discount_amount ),
                wc_price( $current_balance )
            ) );
            return;
        }
        
        $new_balance = $current_balance - $discount_amount;
        $status = $new_balance <= 0 ? 'inactive' : 'active';
        
        $updated = $wpdb->update(
            $table_name,
            array(
                'balance' => $new_balance,
                'status' => $status,
                'updated_at' => current_time( 'mysql' )
            ),
            array( 'code' => $gift_card_code ),
            array( '%f', '%s', '%s' ),
            array( '%s' )
        );
        
        if ( $updated !== false ) {
            $wpdb->query( 'COMMIT' );
            
            // Mark as updated to prevent duplicate processing
            $order->update_meta_data( '_sts_gift_card_balance_updated', true );
            $order->save();
            
            // Track usage
            $this->track_gift_card_usage( $gift_card->id, $order_id, $discount_amount, $current_balance, $new_balance );
            
            // Add order note
            $order->add_order_note( sprintf(
                __( 'Gift card %s used. Amount: %s, Remaining balance: %s', 'sts-gift-card' ),
                $gift_card_code,
                wc_price( $discount_amount ),
                wc_price( $new_balance )
            ) );
        } else {
            $wpdb->query( 'ROLLBACK' );
            $order->add_order_note( __( 'Failed to update gift card balance.', 'sts-gift-card' ) );
        }
        
        // Clear session
        WC()->session->__unset( 'sts_applied_gift_card_code' );
    }
    
    /**
     * Register email classes
     */
    public function register_email_classes( $emails ) {
        require_once STS_GC_PLUGIN_DIR . 'includes/class-sts-gift-card-email-friend.php';
        
        $emails['STS_Gift_Card_Email'] = new STS_Gift_Card_Email_Friend();
        
        return $emails;
    }
    
    /**
     * Register email actions
     */
    public function register_email_actions( $actions ) {
        $actions[] = 'sts_gift_card_email';
        return $actions;
    }
    
    /**
     * Trigger gift card emails
     */
    public function trigger_gift_card_emails( $gift_card_id, $order_id, $is_friend = false ) {
        $mailer = WC()->mailer();
        $emails = $mailer->get_emails();
        
        // Always send to recipient
        if ( isset( $emails['STS_Gift_Card_Email'] ) ) {
            $emails['STS_Gift_Card_Email']->trigger( $gift_card_id, $order_id, true );
        }
    }
    
    /**
     * Enqueue scripts
     */
    public function enqueue_scripts() {
        if ( is_checkout() ) {
            wp_enqueue_script( 'jquery' );
        }
    }
    
    /**
     * Find gift card product ID
     */
    private function find_gift_card_product_id() {
        // Try to find by slug first (most reliable)
        $page = get_page_by_path( 'gift-card', OBJECT, 'product' );
        if ( $page ) {
            $product = wc_get_product( $page->ID );
            if ( $product && $this->is_gift_card_product( $product->get_id() ) ) {
                return $product->get_id();
            }
        }
        
        // Search all products for gift card
        $args = array(
            'limit' => -1,
            'status' => 'publish',
        );
        
        $products = wc_get_products( $args );
        
        foreach ( $products as $product ) {
            if ( $this->is_gift_card_product( $product->get_id() ) ) {
                return $product->get_id();
            }
        }
        
        return null;
    }
    
    /**
     * Render gift card form shortcode
     */
    public function render_gift_card_form_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'product_id' => '',
        ), $atts, 'sts_gift_card_form' );
        
        // Get product ID
        $product_id = ! empty( $atts['product_id'] ) ? intval( $atts['product_id'] ) : $this->find_gift_card_product_id();
        
        if ( ! $product_id ) {
            return '<p>' . esc_html__( 'Gift card product not found. Please specify a product_id attribute.', 'sts-gift-card' ) . '</p>';
        }
        
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return '<p>' . sprintf( esc_html__( 'Product with ID %d does not exist.', 'sts-gift-card' ), $product_id ) . '</p>';
        }
        
        // Check if product is published
        if ( $product->get_status() !== 'publish' ) {
            return '<p>' . sprintf( esc_html__( 'Product with ID %d is not published. Status: %s', 'sts-gift-card' ), $product_id, $product->get_status() ) . '</p>';
        }
        
        // Check if product is detected as gift card (only warn, don't block if product_id is explicitly provided)
        if ( ! $this->is_gift_card_product( $product_id ) ) {
            // If product_id was explicitly provided, allow it anyway (user knows what they're doing)
            // But if auto-detected, require it to be a gift card product
            if ( empty( $atts['product_id'] ) ) {
                return '<p>' . esc_html__( 'The product is not detected as a gift card product. Please ensure the product title or slug contains "gift card" or "גיפט קארד".', 'sts-gift-card' ) . '</p>';
            }
        }
        
        // Check if product is purchasable
        if ( ! $product->is_purchasable() ) {
            $reasons = array();
            if ( ! $product->is_in_stock() ) {
                $reasons[] = esc_html__( 'out of stock', 'sts-gift-card' );
            }
            if ( $product->get_catalog_visibility() === 'hidden' ) {
                $reasons[] = esc_html__( 'hidden from catalog', 'sts-gift-card' );
            }
            $reason_text = ! empty( $reasons ) ? ' (' . implode( ', ', $reasons ) . ')' : '';
            return '<p>' . sprintf( esc_html__( 'Gift card product is not available for purchase.%s', 'sts-gift-card' ), $reason_text ) . '</p>';
        }
        
        $min_amount = $this->get_minimum_amount();
        $cart_url = wc_get_cart_url();
        $add_to_cart_url = add_query_arg( 'add-to-cart', $product_id, $cart_url );
        
        ob_start();
        ?>
        <div class="sts-gift-card-widget-container">
            <!-- Initial Button -->
            <div class="sts-gift-card-widget-button-wrapper" style="text-align: center;">
                <button type="button" class="sts-gift-card-show-form-btn btn btn-primary button h3-sts" tabindex="0">
                    לקניית גיפט-קארד
                </button>
            </div>
            
            <!-- Form (hidden initially) -->
            <div class="sts-gift-card-widget-form" style="display: none;">
                <!-- Close button -->
                <div style="text-align: left; margin-bottom: 15px;">
                    <button type="button" class="sts-gift-card-close-form-btn" style="background: var(--color-background); border-radius: 50%; font-size: 24px; cursor: pointer; color: #333; padding: 5px 10px; line-height: 1;" aria-label="<?php esc_attr_e( 'Close form', 'sts-gift-card' ); ?>">
                        &times;
                    </button>
                </div>
                
                <form method="post" action="<?php echo esc_url( $add_to_cart_url ); ?>" class="sts-gift-card-form">
                
                    <div class="sts-gift-card-form-fields">
                        <!-- Fields Column (Left) -->
                        <div class="sts-gift-card-fields-column">
                            <div class="sts-gift-card-amount-field" style="margin-bottom: 20px;">
                                <label for="sts_gift_card_amount_widget" style="display: block; margin-bottom: 8px; font-weight: 600;">
                                    <?php esc_html_e( 'שווי המתנה', 'sts-gift-card' ); ?>
                                    <span style="color: red;">*</span>
                                </label>
                                <input 
                                    type="number" 
                                    id="sts_gift_card_amount_widget" 
                                    name="sts_gift_card_amount" 
                                    class="input-text" 
                                    step="10" 
                                    min="<?php echo esc_attr( $min_amount ); ?>" 
                                    value="<?php echo esc_attr( $min_amount ); ?>" 
                                    required 
                                    style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;"
                                />
                                <small class="small-text-sts" style="color: #fff !important;">
                                    <?php printf( esc_html__( 'מינימום: %s ₪', 'sts-gift-card' ), number_format( $min_amount, 2 ) ); ?>
                                </small>
                            </div>
                            
                            <div class="sts-gift-card-friend-details">
                                <label for="sts_recipient_name_widget" style="display: block; margin-bottom: 8px; font-weight: 600;">
                                    <?php esc_html_e( 'שם מקבלת המתנה', 'sts-gift-card' ); ?>
                                    <span style="color: red;">*</span>
                                </label>
                                <input 
                                    type="text" 
                                    id="sts_recipient_name_widget" 
                                    name="sts_recipient_name" 
                                    class="input-text" 
                                    required
                                    style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px;"
                                />
                                
                                <div style="margin-bottom: 12px;">
                                    <label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
                                        <input type="checkbox" id="sts_send_to_self_widget" name="sts_send_to_self" value="1" style="width: auto; margin: 0;" />
                                        <?php esc_html_e( 'שלחי לאימייל שלי', 'sts-gift-card' ); ?>
                                    </label>
                                </div>

                                <div id="sts_recipient_email_field_widget">
                                    <label for="sts_recipient_email_widget" style="display: block; margin-bottom: 8px; font-weight: 600;">
                                        <?php esc_html_e( 'אימייל מקבלת המתנה', 'sts-gift-card' ); ?>
                                        <span style="color: red;">*</span>
                                    </label>
                                    <input 
                                        type="email" 
                                        id="sts_recipient_email_widget" 
                                        name="sts_recipient_email" 
                                        class="input-text" 
                                        required
                                        style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 15px;"
                                    />
                                </div>
                                
                            </div>
                            
                            <div class="sts-gift-card-message-field" style="display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 20px;">
                                <label for="sts_recipient_message_widget" style="display: block; margin-bottom: 8px; font-weight: 600;">
                                    <?php esc_html_e( 'הקדשה אישית', 'sts-gift-card' ); ?>
                                </label>
                                <textarea 
                                    id="sts_recipient_message_widget" 
                                    name="sts_recipient_message" 
                                    rows="4" 
                                    class="input-text" 
                                    style="flex: 1; width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 0.25rem;"
                                ></textarea>
                            </div>
            
                            <input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $product_id ); ?>" />
                            <input type="hidden" name="product_id" value="<?php echo esc_attr( $product_id ); ?>" />
                        </div>
                        
                        <!-- Buttons Column (Right) -->
                        <div class="sts-gift-card-buttons-column">
                            <button type="submit" class="btn btn-primary h3-sts">
                                <?php esc_html_e( 'הוסיפי גיפט קארד לעגלה', 'sts-gift-card' ); ?>
                            </button>
                            <button type="button" id="sts_preview_gift_card_widget_btn" class="button btn-secondary h3-sts">
                                <?php esc_html_e( 'תצוגה מקדימה', 'sts-gift-card' ); ?>
                            </button>

                        </div>
                    </div>
                    
                    <div id="sts_gift_card_preview_widget" style="display: none; margin-top: 20px; padding: 20px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">
                        <h4><?php esc_html_e( 'תצוגה מקדימה', 'sts-gift-card' ); ?></h4>
                        <p><strong><?php esc_html_e( 'שווי:', 'sts-gift-card' ); ?></strong> <span id="preview_amount_widget"></span></p>
                        <p><strong><?php esc_html_e( 'שם מקבלת המתנה:', 'sts-gift-card' ); ?></strong> <span id="preview_name_widget"></span></p>
                        <p><strong><?php esc_html_e( 'אימייל:', 'sts-gift-card' ); ?></strong> <span id="preview_email_widget"></span></p>
                        <p><strong><?php esc_html_e( 'הקדשה:', 'sts-gift-card' ); ?></strong> <span id="preview_message_widget"></span></p>
                    </div>
                
                </form>
            </div>
        </div>
        
        <!-- Modal (same as product page) -->
        <div id="sts_gift_card_preview_modal" class="sts-gift-card-modal">
            <div class="sts-gift-card-modal-content">
                <div class="sts-gift-card-modal-header">
                    <h2 class="sts-gift-card-modal-title"><?php esc_html_e( 'תצוגה מקדימה', 'sts-gift-card' ); ?></h2>
                    <span id="sts_close_preview_modal_widget" class="sts-gift-card-modal-close">&times;</span>
                </div>
                <div id="sts_preview_email_content" class="sts-gift-card-modal-body">
                    <!-- Preview content will be loaded here via AJAX -->
                </div>
                <div class="sts-gift-card-modal-footer">
                    <button type="button" id="sts_close_preview_btn_widget" class="button sts-gift-card-modal-close-btn">
                        <?php esc_html_e( 'סגור', 'sts-gift-card' ); ?>
                    </button>
                </div>
            </div>
        </div>
        
        <style>
        /* Prevent scroll jump on mobile when button is clicked */
        .sts-gift-card-show-form-btn {
            -webkit-tap-highlight-color: transparent;
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            user-select: none;
            margin: 0;
            padding: 0.125rem 0.25rem;
            opacity: 0.6;
        }
        .sts-gift-card-show-form-btn:focus {
            outline: none !important;
        }
        
        /* Close button mobile styles */
        @media (max-width: 767px) {
            .sts-gift-card-close-form-btn {
                font-size: 14px !important;
            }
        }
        </style>
        <script>
        jQuery(document).ready(function($) {
            var loadingPreview = false;
            
            // Show form when button is clicked
            $('.sts-gift-card-show-form-btn').on('click', function(e) {
                e.preventDefault(); // Prevent default behavior
                e.stopPropagation(); // Stop event bubbling
                e.stopImmediatePropagation(); // Stop other handlers
                
                // Remove focus from button to prevent mobile scroll-to-focus behavior
                $(this).blur();
                
                // Store current scroll position before any DOM changes
                var scrollPosition = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop;
                
                $('.sts-gift-card-widget-button-wrapper').slideUp();
                $('.sts-gift-card-widget-form').slideDown();
                
                // Force scroll position to remain unchanged immediately
                window.scrollTo(0, scrollPosition);
                $(window).scrollTop(scrollPosition);
                document.documentElement.scrollTop = scrollPosition;
                document.body.scrollTop = scrollPosition;
                
                // Check fields after form is shown
                setTimeout(function() {
                    // Ensure scroll position is maintained after animation
                    window.scrollTo(0, scrollPosition);
                    $(window).scrollTop(scrollPosition);
                    document.documentElement.scrollTop = scrollPosition;
                    document.body.scrollTop = scrollPosition;
                    updatePreviewButtonWidget();
                }, 100);
                
                // Also restore scroll position after slideDown completes (usually 400ms)
                setTimeout(function() {
                    window.scrollTo(0, scrollPosition);
                    $(window).scrollTop(scrollPosition);
                    document.documentElement.scrollTop = scrollPosition;
                    document.body.scrollTop = scrollPosition;
                }, 450);
                
                return false; // Additional prevention
            });
            
            // Close form when X button is clicked
            $('.sts-gift-card-close-form-btn').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Store current scroll position
                var scrollPosition = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop;
                
                // Hide form and show button
                $('.sts-gift-card-widget-form').slideUp();
                $('.sts-gift-card-widget-button-wrapper').slideDown();
                
                // Maintain scroll position
                window.scrollTo(0, scrollPosition);
                $(window).scrollTop(scrollPosition);
                document.documentElement.scrollTop = scrollPosition;
                document.body.scrollTop = scrollPosition;
                
                // Ensure scroll position after animation
                setTimeout(function() {
                    window.scrollTo(0, scrollPosition);
                    $(window).scrollTop(scrollPosition);
                    document.documentElement.scrollTop = scrollPosition;
                    document.body.scrollTop = scrollPosition;
                }, 450);
                
                return false;
            });
            
            $('#sts_close_preview_modal_widget, #sts_close_preview_btn_widget').on('click', function() {
                $('#sts_gift_card_preview_modal').hide();
            });
            
            $(window).on('click', function(event) {
                if ($(event.target).is('#sts_gift_card_preview_modal')) {
                    $('#sts_gift_card_preview_modal').hide();
                }
            });
            
            // Toggle email field based on "send to self" checkbox
            $('[name="sts_send_to_self"]').on('change', function() {
                var $form = $(this).closest('form');
                if ($(this).is(':checked')) {
                    $form.find('#sts_recipient_email_field_widget').hide();
                    $form.find('[name="sts_recipient_email"]').prop('required', false);
                } else {
                    $form.find('#sts_recipient_email_field_widget').show();
                    $form.find('[name="sts_recipient_email"]').prop('required', true);
                }
                updatePreviewButtonWidget($form);
            });

            // Function to check if required fields are filled
            function checkRequiredFieldsWidget($form) {
                $form = $form || $('.sts-gift-card-form').first();
                var amount     = $form.find('[name="sts_gift_card_amount"]').val();
                var name       = $form.find('[name="sts_recipient_name"]').val();
                var sendToSelf = $form.find('[name="sts_send_to_self"]').is(':checked');
                var email      = sendToSelf ? 'self' : $form.find('[name="sts_recipient_email"]').val();
                
                return amount && name && email;
            }
            
            // Enable/disable preview button based on required fields
            function updatePreviewButtonWidget($form) {
                if (checkRequiredFieldsWidget($form)) {
                    $('#sts_preview_gift_card_widget_btn').prop('disabled', false).css('opacity', '1').css('cursor', 'pointer');
                } else {
                    $('#sts_preview_gift_card_widget_btn').prop('disabled', true).css('opacity', '0.5').css('cursor', 'not-allowed');
                }
            }
            
            // Check on page load
            updatePreviewButtonWidget();
            
            // Check on field changes
            $('[name="sts_gift_card_amount"], [name="sts_recipient_name"], [name="sts_recipient_email"]').on('input change', function() {
                updatePreviewButtonWidget($(this).closest('form'));
            });
            
            // Preview button handler - use same modal as product page
            $('#sts_preview_gift_card_widget_btn').on('click', function() {
                if (loadingPreview || !checkRequiredFieldsWidget()) return;
                
                var $form       = $(this).closest('form');
                var amount      = $form.find('[name="sts_gift_card_amount"]').val();
                var name        = $form.find('[name="sts_recipient_name"]').val();
                var sendToSelf  = $form.find('[name="sts_send_to_self"]').is(':checked') ? '1' : '0';
                var email       = sendToSelf === '1' ? '' : $form.find('[name="sts_recipient_email"]').val();
                var message     = $form.find('[name="sts_recipient_message"]').val();
                
                loadingPreview = true;
                $('#sts_preview_email_content').html('<p style="text-align: center;"><?php esc_html_e( 'Loading preview...', 'sts-gift-card' ); ?></p>');
                $('#sts_gift_card_preview_modal').show();
                
                $.ajax({
                    url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
                    type: 'POST',
                    data: {
                        action: 'sts_preview_gift_card_email',
                        amount: amount,
                        recipient_name: name,
                        recipient_email: email,
                        send_to_self: sendToSelf,
                        recipient_message: message,
                        nonce: '<?php echo wp_create_nonce( 'sts_preview_nonce' ); ?>'
                    },
                    success: function(response) {
                        loadingPreview = false;
                        if (response.success) {
                            $('#sts_preview_email_content').html(response.data.html);
                        } else {
                            $('#sts_preview_email_content').html('<p style="color: red;">' + response.data.message + '</p>');
                        }
                    },
                    error: function() {
                        loadingPreview = false;
                        $('#sts_preview_email_content').html('<p style="color: red;"><?php esc_html_e( 'Error loading preview.', 'sts-gift-card' ); ?></p>');
                    }
                });
            });
        });
        </script>
        <?php
        
        // Enqueue jQuery if not already enqueued
        wp_enqueue_script( 'jquery' );
        
        return ob_get_clean();
    }
    
    /**
     * Get minimum amount setting
     */
    private function get_minimum_amount() {
        return floatval( get_option( 'sts_gift_card_minimum_amount', 50 ) );
    }
    
    /**
     * Get expiry days setting
     */
    private function get_expiry_days() {
        return intval( get_option( 'sts_gift_card_expiry_days', 365 ) );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting( 'sts_gift_card_settings', 'sts_gift_card_minimum_amount', array(
            'type' => 'number',
            'sanitize_callback' => 'floatval',
            'default' => 50
        ) );
        
        register_setting( 'sts_gift_card_settings', 'sts_gift_card_expiry_days', array(
            'type' => 'number',
            'sanitize_callback' => 'intval',
            'default' => 365
        ) );

        register_setting( 'sts_gift_card_settings', 'sts_gift_card_expiry_subject', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'Your Gift Card Expires in {days} Days'
        ) );

        register_setting( 'sts_gift_card_settings', 'sts_gift_card_expiry_greeting', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'Hi {recipient_name},'
        ) );

        register_setting( 'sts_gift_card_settings', 'sts_gift_card_expiry_body', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => 'This is a reminder that your gift card (Code: {gift_card_code}) will expire on {expiry_date}.'
        ) );

        register_setting( 'sts_gift_card_settings', 'sts_gift_card_expiry_balance_line', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'Current Balance: {gift_card_balance}'
        ) );

        register_setting( 'sts_gift_card_settings', 'sts_gift_card_expiry_closing', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => "Don't forget to use it before it expires!"
        ) );
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __( 'Gift Cards', 'sts-gift-card' ),
            __( 'Gift Cards', 'sts-gift-card' ),
            'manage_woocommerce',
            'sts-gift-cards',
            array( $this, 'render_admin_page' ),
            'dashicons-tickets-alt',
            56
        );
        
        add_submenu_page(
            'sts-gift-cards',
            __( 'Settings', 'sts-gift-card' ),
            __( 'Settings', 'sts-gift-card' ),
            'manage_woocommerce',
            'sts-gift-cards-settings',
            array( $this, 'render_settings_page' )
        );
    }
    
    /**
     * Send expiry reminders
     */
    public function send_expiry_reminders() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sts_gift_cards';
        
        $today = current_time( 'mysql' );
        
        // Get gift cards expiring in exactly 30 days (1 month reminder)
        $one_month_start = date( 'Y-m-d 00:00:00', strtotime( '+30 days' ) );
        $one_month_end = date( 'Y-m-d 23:59:59', strtotime( '+30 days' ) );
        $one_month_cards = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table_name 
            WHERE status = 'active' 
            AND expires_at IS NOT NULL 
            AND expires_at >= %s 
            AND expires_at <= %s
            AND balance > 0",
            $one_month_start,
            $one_month_end
        ) );
        
        // Get gift cards expiring in exactly 7 days (1 week reminder)
        $one_week_start = date( 'Y-m-d 00:00:00', strtotime( '+7 days' ) );
        $one_week_end = date( 'Y-m-d 23:59:59', strtotime( '+7 days' ) );
        $one_week_cards = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table_name 
            WHERE status = 'active' 
            AND expires_at IS NOT NULL 
            AND expires_at >= %s 
            AND expires_at <= %s
            AND balance > 0",
            $one_week_start,
            $one_week_end
        ) );
        
        // Send 1 month reminders
        foreach ( $one_month_cards as $card ) {
            $this->send_expiry_reminder_email( $card, '1_month' );
        }
        
        // Send 1 week reminders
        foreach ( $one_week_cards as $card ) {
            $this->send_expiry_reminder_email( $card, '1_week' );
        }
    }
    
    /**
     * Substitute {token} placeholders in an admin-configured template string.
     */
    private function render_email_template( $template, array $tokens ) {
        return strtr( $template, $tokens );
    }

    /**
     * Send expiry reminder email
     */
    private function send_expiry_reminder_email( $gift_card, $reminder_type ) {
        $recipient_email = ! empty( $gift_card->recipient_email ) ? $gift_card->recipient_email : $gift_card->buyer_email;

        if ( ! $recipient_email ) {
            return;
        }

        $days = $reminder_type === '1_month' ? 30 : 7;

        $tokens = array(
            '{days}'              => $days,
            '{recipient_name}'    => $gift_card->recipient_name ? $gift_card->recipient_name : __( 'there', 'sts-gift-card' ),
            '{gift_card_code}'    => $gift_card->code,
            '{expiry_date}'       => date_i18n( get_option( 'date_format' ), strtotime( $gift_card->expires_at ) ),
            '{gift_card_balance}' => wp_strip_all_tags( wc_price( $gift_card->balance, array( 'currency' => $gift_card->currency ) ) ),
        );

        $subject = $this->render_email_template( get_option( 'sts_gift_card_expiry_subject', 'Your Gift Card Expires in {days} Days' ), $tokens );

        $message  = $this->render_email_template( get_option( 'sts_gift_card_expiry_greeting', 'Hi {recipient_name},' ), $tokens ) . "\n\n";
        $message .= $this->render_email_template( get_option( 'sts_gift_card_expiry_body', 'This is a reminder that your gift card (Code: {gift_card_code}) will expire on {expiry_date}.' ), $tokens ) . "\n\n";
        $message .= $this->render_email_template( get_option( 'sts_gift_card_expiry_balance_line', 'Current Balance: {gift_card_balance}' ), $tokens ) . "\n\n";
        $message .= $this->render_email_template( get_option( 'sts_gift_card_expiry_closing', "Don't forget to use it before it expires!" ), $tokens ) . "\n";

        wp_mail( $recipient_email, $subject, $message );
    }
    
    /**
     * AJAX handler for preview email
     */
    public function ajax_preview_gift_card_email() {
        check_ajax_referer( 'sts_preview_nonce', 'nonce' );
        
        $amount            = isset( $_POST['amount'] ) ? floatval( $_POST['amount'] ) : 0;
        $recipient_name    = isset( $_POST['recipient_name'] ) ? sanitize_text_field( $_POST['recipient_name'] ) : '';
        $recipient_email   = isset( $_POST['recipient_email'] ) ? sanitize_email( $_POST['recipient_email'] ) : '';
        $recipient_message = isset( $_POST['recipient_message'] ) ? sanitize_textarea_field( $_POST['recipient_message'] ) : '';
        $send_to_self      = isset( $_POST['send_to_self'] ) && '1' === $_POST['send_to_self'];

        if ( ! $amount || ! $recipient_name || ( ! $send_to_self && ! $recipient_email ) ) {
            wp_send_json_error( array( 'message' => __( 'יש למלא את כל השדות הנדרשים.', 'sts-gift-card' ) ) );
        }

        // For preview, show a placeholder when sending to self
        if ( $send_to_self ) {
            $recipient_email = '<האימייל שלך>';
        }
        
        // Use variable names for code, but actual values for names and message
        $preview_code = '<קוד שובר>';
        $expiry_days = $this->get_expiry_days();
        
        // Get buyer/sender name from current user or WooCommerce customer
        $sender_name = '<שם המזמין>';
        if ( is_user_logged_in() ) {
            $current_user = wp_get_current_user();
            // Try display name first
            if ( ! empty( $current_user->display_name ) ) {
                $sender_name = $current_user->display_name;
            } 
            // Then try first + last name
            elseif ( ! empty( $current_user->first_name ) || ! empty( $current_user->last_name ) ) {
                $sender_name = trim( $current_user->first_name . ' ' . $current_user->last_name );
            }
            // Fallback to user login if nothing else available
            elseif ( ! empty( $current_user->user_login ) ) {
                $sender_name = $current_user->user_login;
            }
            
            // If still empty, try WooCommerce customer data
            if ( $sender_name === '<שם המזמין>' && class_exists( 'WC_Customer' ) ) {
                $customer = new WC_Customer( $current_user->ID );
                $first_name = $customer->get_first_name();
                $last_name = $customer->get_last_name();
                if ( $first_name || $last_name ) {
                    $sender_name = trim( $first_name . ' ' . $last_name );
                }
            }
        }
        
        // Create mock gift card object - use actual recipient name and message
        $gift_card = (object) array(
            'code' => $preview_code,
            'initial_amount' => $amount, // Keep actual amount for display
            'balance' => $amount,
            'currency' => 'ILS',
            'recipient_name' => $recipient_name, // Actual recipient name
            'recipient_email' => $recipient_email, // Not shown in template
            'recipient_message' => $recipient_message, // Actual message text
            'expires_at' => date( 'Y-m-d H:i:s', strtotime( '+' . $expiry_days . ' days' ) )
        );
        
        // Create mock order class with actual sender name
        $order = new class($sender_name) {
            private $sender_name;
            public function __construct($name) {
                $this->sender_name = $name;
            }
            public function get_billing_first_name() {
                return $this->sender_name;
            }
            public function get_billing_last_name() {
                return '';
            }
        };
        
        // Set template variables — use the real registered email instance so the
        // preview reflects the admin-configured templates (subject, heading, body
        // lines) instead of hardcoded fallbacks.
        $mailer = WC()->mailer();
        $registered_emails = $mailer->get_emails();
        $email = isset( $registered_emails['STS_Gift_Card_Email'] ) ? $registered_emails['STS_Gift_Card_Email'] : null;

        if ( $email ) {
            $email->placeholders['{gift_card_code}']    = $preview_code;
            $email->placeholders['{gift_card_amount}']  = wc_price( $amount, array( 'currency' => 'ILS' ) );
            $email->placeholders['{gift_card_balance}'] = wc_price( $amount, array( 'currency' => 'ILS' ) );
            $email->placeholders['{recipient_name}']    = $recipient_name;
            $email->placeholders['{sender_name}']       = $sender_name;
        }

        $email_heading = $email ? $email->get_heading() : __( 'You received a Gift Card!', 'sts-gift-card' );
        $additional_content = $email ? $email->get_additional_content() : '';
        $sent_to_admin = false;
        $plain_text = false;
        
        // Flag to indicate we're in preview mode
        if ( ! defined( 'STS_GC_PREVIEW_MODE' ) ) {
            define( 'STS_GC_PREVIEW_MODE', true );
        }
        
        // Get WooCommerce email header
        $email_header = wc_get_template_html( 'emails/email-header.php', array( 'email_heading' => $email_heading ) );
        
        // Load actual template file - footer will be handled by template
        ob_start();
        $template_path = STS_GC_PLUGIN_DIR . 'templates/emails/gift-card-email-template.php';
        if ( file_exists( $template_path ) ) {
            include $template_path;
        } else {
            echo '<p>' . esc_html__( 'Template file not found.', 'sts-gift-card' ) . '</p>';
        }
        $email_content = ob_get_clean();
        
        // Create custom footer for preview (without placeholders) - only if we have content
        $site_title = get_bloginfo( 'name' );
        $store_address = get_option( 'woocommerce_store_address' );
        $store_address_2 = get_option( 'woocommerce_store_address_2' );
        $store_city = get_option( 'woocommerce_store_city' );
        $store_postcode = get_option( 'woocommerce_store_postcode' );
        
        $address_parts = array_filter( array( $store_address, $store_address_2, $store_city, $store_postcode ) );
        $full_address = ! empty( $address_parts ) ? implode( ', ', $address_parts ) : '';
        
        // Simple footer without placeholders - only show if we have content
        $email_footer = '';
        if ( $site_title || $full_address ) {
            $email_footer = '<div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center; color: #666; font-size: 12px;">';
            if ( $site_title ) {
                $email_footer .= '<p style="margin: 5px 0;">' . esc_html( $site_title ) . '</p>';
            }
            if ( $full_address ) {
                $email_footer .= '<p style="margin: 5px 0;">' . esc_html( $full_address ) . '</p>';
            }
            $email_footer .= '</div>';
        }
        
        $full_email = $email_header . $email_content . $email_footer;
        
        wp_send_json_success( array( 'html' => $full_email ) );
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if ( isset( $_POST['submit'] ) && check_admin_referer( 'sts_gift_card_settings' ) ) {
            update_option( 'sts_gift_card_minimum_amount', floatval( $_POST['sts_gift_card_minimum_amount'] ) );
            update_option( 'sts_gift_card_expiry_days', intval( $_POST['sts_gift_card_expiry_days'] ) );
            update_option( 'sts_gift_card_expiry_subject', sanitize_text_field( wp_unslash( $_POST['sts_gift_card_expiry_subject'] ) ) );
            update_option( 'sts_gift_card_expiry_greeting', sanitize_text_field( wp_unslash( $_POST['sts_gift_card_expiry_greeting'] ) ) );
            update_option( 'sts_gift_card_expiry_body', sanitize_textarea_field( wp_unslash( $_POST['sts_gift_card_expiry_body'] ) ) );
            update_option( 'sts_gift_card_expiry_balance_line', sanitize_text_field( wp_unslash( $_POST['sts_gift_card_expiry_balance_line'] ) ) );
            update_option( 'sts_gift_card_expiry_closing', sanitize_textarea_field( wp_unslash( $_POST['sts_gift_card_expiry_closing'] ) ) );
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'sts-gift-card' ) . '</p></div>';
        }

        $min_amount = $this->get_minimum_amount();
        $expiry_days = $this->get_expiry_days();
        $expiry_subject = get_option( 'sts_gift_card_expiry_subject', 'Your Gift Card Expires in {days} Days' );
        $expiry_greeting = get_option( 'sts_gift_card_expiry_greeting', 'Hi {recipient_name},' );
        $expiry_body = get_option( 'sts_gift_card_expiry_body', 'This is a reminder that your gift card (Code: {gift_card_code}) will expire on {expiry_date}.' );
        $expiry_balance_line = get_option( 'sts_gift_card_expiry_balance_line', 'Current Balance: {gift_card_balance}' );
        $expiry_closing = get_option( 'sts_gift_card_expiry_closing', "Don't forget to use it before it expires!" );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Gift Card Settings', 'sts-gift-card' ); ?></h1>
            
            <form method="post" action="">
                <?php wp_nonce_field( 'sts_gift_card_settings' ); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="sts_gift_card_minimum_amount"><?php esc_html_e( 'Minimum Amount (ILS)', 'sts-gift-card' ); ?></label>
                        </th>
                        <td>
                            <input 
                                type="number" 
                                id="sts_gift_card_minimum_amount" 
                                name="sts_gift_card_minimum_amount" 
                                value="<?php echo esc_attr( $min_amount ); ?>" 
                                step="10" 
                                min="0" 
                                class="regular-text"
                            />
                            <p class="description"><?php esc_html_e( 'Minimum amount required for gift card purchase.', 'sts-gift-card' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sts_gift_card_expiry_days"><?php esc_html_e( 'Expiry Days', 'sts-gift-card' ); ?></label>
                        </th>
                        <td>
                            <input 
                                type="number" 
                                id="sts_gift_card_expiry_days" 
                                name="sts_gift_card_expiry_days" 
                                value="<?php echo esc_attr( $expiry_days ); ?>" 
                                min="1" 
                                class="regular-text"
                            />
                            <p class="description"><?php esc_html_e( 'Number of days until gift card expires (default: 365).', 'sts-gift-card' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Expiry Reminder Email', 'sts-gift-card' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Sent automatically 30 and 7 days before a gift card expires.', 'sts-gift-card' ); ?></p>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="sts_gift_card_expiry_subject"><?php esc_html_e( 'Subject', 'sts-gift-card' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="sts_gift_card_expiry_subject"
                                name="sts_gift_card_expiry_subject"
                                value="<?php echo esc_attr( $expiry_subject ); ?>"
                                class="regular-text"
                            />
                            <p class="description"><?php echo wp_kses_post( sprintf( __( 'Available placeholders: %s', 'sts-gift-card' ), '<code>{days}</code>' ) ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sts_gift_card_expiry_greeting"><?php esc_html_e( 'Greeting line', 'sts-gift-card' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="sts_gift_card_expiry_greeting"
                                name="sts_gift_card_expiry_greeting"
                                value="<?php echo esc_attr( $expiry_greeting ); ?>"
                                class="regular-text"
                            />
                            <p class="description"><?php echo wp_kses_post( sprintf( __( 'Available placeholders: %s', 'sts-gift-card' ), '<code>{recipient_name}</code>' ) ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sts_gift_card_expiry_body"><?php esc_html_e( 'Reminder line', 'sts-gift-card' ); ?></label>
                        </th>
                        <td>
                            <textarea
                                id="sts_gift_card_expiry_body"
                                name="sts_gift_card_expiry_body"
                                class="large-text"
                                rows="2"
                            ><?php echo esc_textarea( $expiry_body ); ?></textarea>
                            <p class="description"><?php echo wp_kses_post( sprintf( __( 'Available placeholders: %s', 'sts-gift-card' ), '<code>{gift_card_code}, {expiry_date}</code>' ) ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sts_gift_card_expiry_balance_line"><?php esc_html_e( 'Balance line', 'sts-gift-card' ); ?></label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="sts_gift_card_expiry_balance_line"
                                name="sts_gift_card_expiry_balance_line"
                                value="<?php echo esc_attr( $expiry_balance_line ); ?>"
                                class="regular-text"
                            />
                            <p class="description"><?php echo wp_kses_post( sprintf( __( 'Available placeholders: %s', 'sts-gift-card' ), '<code>{gift_card_balance}</code>' ) ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sts_gift_card_expiry_closing"><?php esc_html_e( 'Closing line', 'sts-gift-card' ); ?></label>
                        </th>
                        <td>
                            <textarea
                                id="sts_gift_card_expiry_closing"
                                name="sts_gift_card_expiry_closing"
                                class="large-text"
                                rows="2"
                            ><?php echo esc_textarea( $expiry_closing ); ?></textarea>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Update admin page to show usage history
     */
    public function render_admin_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sts_gift_cards';
        $usage_table = $wpdb->prefix . 'sts_gift_card_usage';
        
        // Handle actions
        if ( isset( $_GET['action'] ) && isset( $_GET['gift_card_id'] ) && check_admin_referer( 'sts_gift_card_action' ) ) {
            $gift_card_id = intval( $_GET['gift_card_id'] );
            
            if ( $_GET['action'] === 'deactivate' ) {
                $wpdb->update(
                    $table_name,
                    array( 'status' => 'inactive' ),
                    array( 'id' => $gift_card_id ),
                    array( '%s' ),
                    array( '%d' )
                );
                echo '<div class="notice notice-success"><p>' . esc_html__( 'Gift card deactivated.', 'sts-gift-card' ) . '</p></div>';
            } elseif ( $_GET['action'] === 'activate' ) {
                $wpdb->update(
                    $table_name,
                    array( 'status' => 'active' ),
                    array( 'id' => $gift_card_id ),
                    array( '%s' ),
                    array( '%d' )
                );
                echo '<div class="notice notice-success"><p>' . esc_html__( 'Gift card activated.', 'sts-gift-card' ) . '</p></div>';
            }
        }
        
        // Get gift card ID for usage view
        $view_usage_id = isset( $_GET['view_usage'] ) ? intval( $_GET['view_usage'] ) : 0;
        
        // Search
        $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $where = '';
        if ( $search ) {
            $where = $wpdb->prepare( " WHERE code LIKE %s OR buyer_email LIKE %s OR recipient_email LIKE %s", 
                '%' . $wpdb->esc_like( $search ) . '%',
                '%' . $wpdb->esc_like( $search ) . '%',
                '%' . $wpdb->esc_like( $search ) . '%'
            );
        }
        
        // Pagination
        $per_page = 20;
        $current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $offset = ( $current_page - 1 ) * $per_page;
        
        $total_items = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name $where" );
        $total_pages = ceil( $total_items / $per_page );
        
        $gift_cards = $wpdb->get_results( 
            "SELECT * FROM $table_name $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset" 
        );
        
        // Get usage history if viewing specific card
        $usage_history = array();
        if ( $view_usage_id ) {
            $usage_history = $wpdb->get_results( $wpdb->prepare(
                "SELECT u.*, gc.code as gift_card_code 
                FROM $usage_table u 
                LEFT JOIN $table_name gc ON u.gift_card_id = gc.id 
                WHERE u.gift_card_id = %d 
                ORDER BY u.created_at DESC",
                $view_usage_id
            ) );
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Gift Cards', 'sts-gift-card' ); ?></h1>
            
            <?php if ( $view_usage_id && ! empty( $usage_history ) ) : ?>
                <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=sts-gift-cards' ) ); ?>" class="button"><?php esc_html_e( '← Back to Gift Cards', 'sts-gift-card' ); ?></a></p>
                <h2><?php esc_html_e( 'Usage History', 'sts-gift-card' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Date', 'sts-gift-card' ); ?></th>
                            <th><?php esc_html_e( 'Order', 'sts-gift-card' ); ?></th>
                            <th><?php esc_html_e( 'Amount Used', 'sts-gift-card' ); ?></th>
                            <th><?php esc_html_e( 'Balance Before', 'sts-gift-card' ); ?></th>
                            <th><?php esc_html_e( 'Balance After', 'sts-gift-card' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $usage_history as $usage ) : ?>
                            <tr>
                                <td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $usage->created_at ) ) ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $usage->order_id . '&action=edit' ) ); ?>">
                                        #<?php echo esc_html( $usage->order_id ); ?>
                                    </a>
                                </td>
                                <td><?php echo wc_price( $usage->amount_used ); ?></td>
                                <td><?php echo wc_price( $usage->balance_before ); ?></td>
                                <td><?php echo wc_price( $usage->balance_after ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <?php if ( $view_usage_id ) : ?>
                    <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=sts-gift-cards' ) ); ?>" class="button"><?php esc_html_e( '← Back to Gift Cards', 'sts-gift-card' ); ?></a></p>
                    <p><?php esc_html_e( 'No usage history found for this gift card.', 'sts-gift-card' ); ?></p>
                <?php else : ?>
                    <form method="get" style="margin: 20px 0;">
                        <input type="hidden" name="page" value="sts-gift-cards">
                        <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by code, email...', 'sts-gift-card' ); ?>" style="width: 300px;">
                        <button type="submit" class="button"><?php esc_html_e( 'Search', 'sts-gift-card' ); ?></button>
                        <?php if ( $search ) : ?>
                            <a href="?page=sts-gift-cards" class="button"><?php esc_html_e( 'Clear', 'sts-gift-card' ); ?></a>
                        <?php endif; ?>
                    </form>
                    
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Code', 'sts-gift-card' ); ?></th>
                                <th><?php esc_html_e( 'Amount', 'sts-gift-card' ); ?></th>
                                <th><?php esc_html_e( 'Balance', 'sts-gift-card' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'sts-gift-card' ); ?></th>
                                <th><?php esc_html_e( 'Recipient', 'sts-gift-card' ); ?></th>
                                <th><?php esc_html_e( 'Expires', 'sts-gift-card' ); ?></th>
                                <th><?php esc_html_e( 'Order', 'sts-gift-card' ); ?></th>
                                <th><?php esc_html_e( 'Actions', 'sts-gift-card' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( $gift_cards ) : ?>
                                <?php foreach ( $gift_cards as $card ) : ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $card->code ); ?></strong></td>
                                        <td><?php echo wc_price( $card->initial_amount, array( 'currency' => $card->currency ) ); ?></td>
                                        <td><?php echo wc_price( $card->balance, array( 'currency' => $card->currency ) ); ?></td>
                                        <td>
                                            <span class="status-<?php echo esc_attr( $card->status ); ?>">
                                                <?php echo esc_html( ucfirst( $card->status ) ); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ( $card->recipient_email ) : ?>
                                                <?php echo esc_html( $card->recipient_name ); ?><br>
                                                <small><?php echo esc_html( $card->recipient_email ); ?></small>
                                            <?php else : ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ( $card->expires_at ) : ?>
                                                <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $card->expires_at ) ) ); ?>
                                            <?php else : ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ( $card->order_id ) : ?>
                                                <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $card->order_id . '&action=edit' ) ); ?>">
                                                    #<?php echo esc_html( $card->order_id ); ?>
                                                </a>
                                            <?php else : ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'view_usage' => $card->id ) ), 'sts_gift_card_action' ) ); ?>" class="button button-small">
                                                <?php esc_html_e( 'View Usage', 'sts-gift-card' ); ?>
                                            </a>
                                            <?php if ( $card->status === 'active' ) : ?>
                                                <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'deactivate', 'gift_card_id' => $card->id ) ), 'sts_gift_card_action' ) ); ?>" class="button button-small">
                                                    <?php esc_html_e( 'Deactivate', 'sts-gift-card' ); ?>
                                                </a>
                                            <?php else : ?>
                                                <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'activate', 'gift_card_id' => $card->id ) ), 'sts_gift_card_action' ) ); ?>" class="button button-small">
                                                    <?php esc_html_e( 'Activate', 'sts-gift-card' ); ?>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr>
                                    <td colspan="8"><?php esc_html_e( 'No gift cards found.', 'sts-gift-card' ); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    
                    <?php if ( $total_pages > 1 ) : ?>
                        <div class="tablenav">
                            <div class="tablenav-pages">
                                <?php
                                echo paginate_links( array(
                                    'base' => add_query_arg( 'paged', '%#%' ),
                                    'format' => '',
                                    'prev_text' => '&laquo;',
                                    'next_text' => '&raquo;',
                                    'total' => $total_pages,
                                    'current' => $current_page
                                ) );
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <style>
        .status-active { color: #46b450; font-weight: 600; }
        .status-inactive { color: #dc3232; font-weight: 600; }
        </style>
        <?php
    }
}

// Initialize plugin
STS_Gift_Card_System::get_instance();

