<?php
namespace MPCDP;

use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;

class StripeApi {

    public function __construct() {
        // Hook before MotoPress to override the PaymentIntent creation
        add_action( 'wp_ajax_mphb_create_stripe_payment_intent', [ $this, 'override_create_payment_intent' ], 1 );
        add_action( 'wp_ajax_nopriv_mphb_create_stripe_payment_intent', [ $this, 'override_create_payment_intent' ], 1 );
        
        // Hook into booking creation/payment to save customer/payment method
        add_action( 'mphb_booking_payment_completed', [ $this, 'save_payment_details_to_booking' ], 10, 2 );
    }

    public function override_create_payment_intent() {
        if ( ! isset( $_REQUEST['mphb_nonce'] ) || ! wp_verify_nonce( $_REQUEST['mphb_nonce'], 'mphb_create_stripe_payment_intent' ) ) {
            wp_send_json_error( [ 'errorMessage' => 'Nonce verification failed.' ], 400 );
        }

        $amount            = isset( $_REQUEST['amount'] ) ? floatval( wp_unslash( $_REQUEST['amount'] ) ) : 0;
        $description       = isset( $_REQUEST['description'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['description'] ) ) : '';
        $paymentMethodType = isset( $_REQUEST['paymentMethodType'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['paymentMethodType'] ) ) : '';
        $paymentMethodId   = isset( $_REQUEST['paymentMethodId'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['paymentMethodId'] ) ) : '';

        if ( $amount <= 0 || ! $paymentMethodType || ! $paymentMethodId ) {
            wp_send_json_error( [ 'errorMessage' => 'Missing required fields.' ], 400 );
        }

        $currency  = MPHB()->settings()->currency()->getCurrencyCode();
        $stripeApi = MPHB()->gatewayManager()->getStripeGateway()->getApi();

        if ( ! $stripeApi->checkMinimumAmount( $amount, $currency ) ) {
            wp_send_json_error( [ 'errorMessage' => 'Minimum amount not met.' ], 400 );
        }

        $stripeApi->setApp();

        try {
            // Create a Customer first so the payment method can be saved for off-session usage
            $customer = Customer::create([
                'description' => 'MotoPress Guest (Temp)',
            ]);

            $requestArgs = [
                'amount'               => $stripeApi->convertToSmallestUnit( $amount, $currency ),
                'currency'             => strtolower( $currency ),
                'payment_method_types' => [ $paymentMethodType ],
                'payment_method'       => $paymentMethodId,
                'customer'             => $customer->id,
                'setup_future_usage'   => 'off_session' // Key addition
            ];

            if ( ! empty( $description ) ) {
                $requestArgs['description'] = $description;
            }

            $paymentIntent = PaymentIntent::create( $requestArgs );

            wp_send_json_success( [
                'id'            => $paymentIntent->id,
                'client_secret' => $paymentIntent->client_secret,
            ], 200 );
            
        } catch ( \Exception $e ) {
            error_log( 'Stripe Override Error: ' . $e->getMessage() );
            wp_send_json_error( [ 'errorMessage' => $e->getMessage() ], 500 );
        }
    }
    
    public function save_payment_details_to_booking( $payment, $booking ) {
        // Payment is completed, let's try to extract the intent ID.
        // In MotoPress, the payment usually has a transaction ID which is the Payment Intent ID.
        $transaction_id = $payment->getTransactionId();
        
        if ( ! $transaction_id || strpos( $transaction_id, 'pi_' ) !== 0 ) {
            return;
        }
        
        $stripeApi = MPHB()->gatewayManager()->getStripeGateway()->getApi();
        $stripeApi->setApp();
        
        try {
            $intent = PaymentIntent::retrieve( $transaction_id );
            
            if ( $intent->customer && $intent->payment_method ) {
                // Update customer email with booking email
                Customer::update( $intent->customer, [
                    'email' => $booking->getCustomer()->getEmail(),
                    'name'  => $booking->getCustomer()->getFirstName() . ' ' . $booking->getCustomer()->getLastName(),
                    'description' => 'MotoPress Guest Booking #' . $booking->getId()
                ]);
                
                // Save customer and payment method to booking post meta
                update_post_meta( $booking->getId(), '_mpcdp_stripe_customer_id', $intent->customer );
                update_post_meta( $booking->getId(), '_mpcdp_stripe_payment_method_id', $intent->payment_method );
            }
        } catch ( \Exception $e ) {
            error_log( 'MPCDP Save Details Error: ' . $e->getMessage() );
        }
    }

    public function create_deposit_intent( $booking_id ) {
        $customer_id       = get_post_meta( $booking_id, '_mpcdp_stripe_customer_id', true );
        $payment_method_id = get_post_meta( $booking_id, '_mpcdp_stripe_payment_method_id', true );
        
        if ( ! $customer_id || ! $payment_method_id ) {
            return false;
        }
        
        $currency  = MPHB()->settings()->currency()->getCurrencyCode();
        $stripeApi = MPHB()->gatewayManager()->getStripeGateway()->getApi();
        $stripeApi->setApp();
        
        try {
            $intent = PaymentIntent::create([
                'amount'         => MPCDP_DEPOSIT_AMOUNT,
                'currency'       => strtolower( $currency ),
                'customer'       => $customer_id,
                'payment_method' => $payment_method_id,
                'off_session'    => true,
                'confirm'        => true,
                'capture_method' => 'manual',
                'description'    => 'Damage Deposit Hold (Booking #' . $booking_id . ')'
            ]);
            
            update_post_meta( $booking_id, '_mpcdp_deposit_intent_id', $intent->id );
            update_post_meta( $booking_id, '_mpcdp_deposit_status', 'frozen' );
            
            return $intent;
        } catch ( \Exception $e ) {
            error_log( 'MPCDP Deposit Creation Error: ' . $e->getMessage() );
            update_post_meta( $booking_id, '_mpcdp_deposit_error', $e->getMessage() );
            return false;
        }
    }

    public function cancel_deposit( $intent_id ) {
        $stripeApi = MPHB()->gatewayManager()->getStripeGateway()->getApi();
        $stripeApi->setApp();
        
        try {
            $intent = PaymentIntent::retrieve( $intent_id );
            
            if ( $intent->status === 'requires_capture' ) {
                $canceled_intent = $intent->cancel();
                return $canceled_intent;
            }
            return true;
        } catch ( \Exception $e ) {
            error_log( 'MPCDP Deposit Cancel Error: ' . $e->getMessage() );
            return false;
        }
    }

    public function capture_deposit( $intent_id, $amount = null ) {
        $stripeApi = MPHB()->gatewayManager()->getStripeGateway()->getApi();
        $stripeApi->setApp();
        
        try {
            $intent = PaymentIntent::retrieve( $intent_id );
            
            if ( $intent->status === 'requires_capture' ) {
                $args = [];
                if ( $amount !== null ) {
                    // amount should be passed in cents
                    $args['amount_to_capture'] = (int) $amount;
                }
                return $intent->capture( $args );
            }
            return false;
        } catch ( \Exception $e ) {
            error_log( 'MPCDP Deposit Capture Error: ' . $e->getMessage() );
            return false;
        }
    }
}
