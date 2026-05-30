<?php
namespace MPCDP;

class CronManager {

    public function __construct() {
        add_action( 'init', [ $this, 'schedule_events' ] );
        
        add_action( 'mpcdp_daily_hold_deposits', [ $this, 'process_hold_deposits' ] );
        add_action( 'mpcdp_daily_release_deposits', [ $this, 'process_release_deposits' ] );
        
        register_activation_hook( MPCDP_PLUGIN_DIR . 'motopress-custom-depozit-payment.php', [ $this, 'activate' ] );
        register_deactivation_hook( MPCDP_PLUGIN_DIR . 'motopress-custom-depozit-payment.php', [ $this, 'deactivate' ] );
    }
    
    public function activate() {
        $this->schedule_events();
    }
    
    public function deactivate() {
        wp_clear_scheduled_hook( 'mpcdp_daily_hold_deposits' );
        wp_clear_scheduled_hook( 'mpcdp_daily_release_deposits' );
    }

    public function schedule_events() {
        if ( ! wp_next_scheduled( 'mpcdp_daily_hold_deposits' ) ) {
            // Schedule it to run daily at 06:00
            wp_schedule_event( strtotime( '06:00:00' ), 'daily', 'mpcdp_daily_hold_deposits' );
        }
        if ( ! wp_next_scheduled( 'mpcdp_daily_release_deposits' ) ) {
            // Schedule it to run daily at 08:00
            wp_schedule_event( strtotime( '08:00:00' ), 'daily', 'mpcdp_daily_release_deposits' );
        }
    }
    
    public function process_hold_deposits() {
        $today = date( 'Y-m-d' );
        
        // Find bookings with check-in today
        $args = [
            'post_type'      => 'mphb_booking',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'     => 'mphb_check_in_date',
                    'value'   => $today,
                    'compare' => '='
                ],
                [
                    'key'     => '_mpcdp_deposit_status',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ];
        
        $bookings = get_posts( $args );
        $stripe_api = new StripeApi();
        
        foreach ( $bookings as $booking ) {
            $stripe_api->create_deposit_intent( $booking->ID );
        }
    }
    
    public function process_release_deposits() {
        // 2 days after check-out
        $two_days_ago = date( 'Y-m-d', strtotime( '-2 days' ) );
        
        // Find bookings with check-out 2 days ago
        $args = [
            'post_type'      => 'mphb_booking',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'     => 'mphb_check_out_date',
                    'value'   => $two_days_ago,
                    'compare' => '='
                ],
                [
                    'key'     => '_mpcdp_deposit_status',
                    'value'   => 'frozen',
                    'compare' => '='
                ]
            ]
        ];
        
        $bookings = get_posts( $args );
        $stripe_api = new StripeApi();
        
        foreach ( $bookings as $booking ) {
            $intent_id = get_post_meta( $booking->ID, '_mpcdp_deposit_intent_id', true );
            if ( $intent_id ) {
                $result = $stripe_api->cancel_deposit( $intent_id );
                if ( $result ) {
                    update_post_meta( $booking->ID, '_mpcdp_deposit_status', 'released' );
                }
            }
        }
    }
}
