<?php
namespace MPCDP;

class AdminUI {

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
        
        // AJAX Endpoints
        add_action( 'wp_ajax_mpcdp_release_deposit', [ $this, 'ajax_release_deposit' ] );
        add_action( 'wp_ajax_mpcdp_capture_deposit', [ $this, 'ajax_capture_deposit' ] );
    }
    
    public function register_meta_box() {
        add_meta_box(
            'mpcdp_deposit_meta_box',
            __( 'Damage Deposit (Stripe)', 'motopress-custom-depozit-payment' ),
            [ $this, 'render_meta_box' ],
            'mphb_booking',
            'side',
            'high'
        );
    }
    
    public function render_meta_box( $post ) {
        $status    = get_post_meta( $post->ID, '_mpcdp_deposit_status', true );
        $intent_id = get_post_meta( $post->ID, '_mpcdp_deposit_intent_id', true );
        $captured  = get_post_meta( $post->ID, '_mpcdp_deposit_captured_amount', true );
        $error     = get_post_meta( $post->ID, '_mpcdp_deposit_error', true );
        
        $nonce = wp_create_nonce( 'mpcdp_deposit_action' );
        
        echo '<div id="mpcdp-deposit-wrapper">';
        echo '<input type="hidden" id="mpcdp_booking_id" value="' . esc_attr( $post->ID ) . '">';
        echo '<input type="hidden" id="mpcdp_nonce" value="' . esc_attr( $nonce ) . '">';
        
        if ( ! $status ) {
            echo '<p><strong>Status:</strong> Nije zamrznuto</p>';
            if ( $error ) {
                echo '<p style="color:red; font-weight:bold;">Greška pri zamrzavanju: ' . esc_html( $error ) . '</p>';
                echo '<p class="description">Pokušaj naplate automatskog pologa nije uspio. Provjerite u Stripe nadzornoj ploči za više detalja.</p>';
            } else {
                echo '<p class="description">Polog će biti automatski zamrznut na dan dolaska gosta.</p>';
            }
        } elseif ( $status === 'frozen' ) {
            echo '<p><strong>Status:</strong> <span style="color:orange; font-weight:bold;">ZAMRZNUT (' . ( MPCDP_DEPOSIT_AMOUNT / 100 ) . '€)</span></p>';
            echo '<p><small>Intent ID: ' . esc_html( $intent_id ) . '</small></p>';
            echo '<hr>';
            
            // Release Button
            echo '<p><button type="button" class="button button-primary" style="background:green;border-color:green;width:100%;margin-bottom:10px;" id="mpcdp_release_btn">Sve je u redu - Otpusti polog</button></p>';
            
            // Capture Button & Input
            echo '<p><strong>Naplati štetu:</strong></p>';
            echo '<p><input type="number" id="mpcdp_capture_amount" placeholder="Iznos u EUR" max="' . ( MPCDP_DEPOSIT_AMOUNT / 100 ) . '" min="1" style="width:100%;"></p>';
            echo '<p><button type="button" class="button button-primary" style="background:red;border-color:red;width:100%;" id="mpcdp_capture_btn">Naplati štetu (Capture)</button></p>';
            
            echo '<div id="mpcdp_message" style="margin-top:10px;"></div>';
        } elseif ( $status === 'released' ) {
            echo '<p><strong>Status:</strong> <span style="color:green; font-weight:bold;">Vraćeno (Otpušteno)</span></p>';
        } elseif ( $status === 'captured' ) {
            echo '<p><strong>Status:</strong> <span style="color:red; font-weight:bold;">Naplaćena šteta: ' . ( $captured / 100 ) . '€</span></p>';
        }
        echo '</div>';
        
        // JS Logic inline for simplicity
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', () => {
            const releaseBtn = document.getElementById('mpcdp_release_btn');
            const captureBtn = document.getElementById('mpcdp_capture_btn');
            const bookingId = document.getElementById('mpcdp_booking_id').value;
            const nonce = document.getElementById('mpcdp_nonce').value;
            const msgBox = document.getElementById('mpcdp_message');
            
            // Helper function to send AJAX request
            const sendAjaxRequest = async (action, data = {}) => {
                const formData = new FormData();
                formData.append('action', action);
                formData.append('booking_id', bookingId);
                formData.append('nonce', nonce);
                
                for (const key in data) {
                    formData.append(key, data[key]);
                }

                try {
                    // ajaxurl is globally defined by WordPress in the admin dashboard
                    const response = await fetch(ajaxurl, {
                        method: 'POST',
                        body: formData
                    });
                    return await response.json();
                } catch (error) {
                    return { success: false, data: { message: 'Network error occurred.' } };
                }
            };

            if (releaseBtn) {
                releaseBtn.addEventListener('click', async (e) => {
                    e.preventDefault();
                    if (!confirm('Jeste li sigurni da želite otpustiti polog?')) return;
                    
                    releaseBtn.disabled = true;
                    releaseBtn.textContent = 'Otpuštanje...';
                    
                    const res = await sendAjaxRequest('mpcdp_release_deposit');
                    
                    if (res.success) {
                        location.reload();
                    } else {
                        msgBox.innerHTML = '<span style="color:red;">Greška: ' + (res.data?.message || 'Nepoznata greška') + '</span>';
                        releaseBtn.disabled = false;
                        releaseBtn.textContent = 'Sve je u redu - Otpusti polog';
                    }
                });
            }
            
            if (captureBtn) {
                captureBtn.addEventListener('click', async (e) => {
                    e.preventDefault();
                    const amountInput = document.getElementById('mpcdp_capture_amount');
                    const amount = amountInput ? amountInput.value : 0;
                    
                    if (!amount || amount <= 0) {
                        alert('Unesite ispravan iznos!');
                        return;
                    }
                    
                    if (!confirm('Jeste li sigurni da želite naplatiti ' + amount + '€ štete?')) return;
                    
                    captureBtn.disabled = true;
                    captureBtn.textContent = 'Naplaćivanje...';
                    
                    const res = await sendAjaxRequest('mpcdp_capture_deposit', { amount: amount });
                    
                    if (res.success) {
                        location.reload();
                    } else {
                        msgBox.innerHTML = '<span style="color:red;">Greška: ' + (res.data?.message || 'Nepoznata greška') + '</span>';
                        captureBtn.disabled = false;
                        captureBtn.textContent = 'Naplati štetu (Capture)';
                    }
                });
            }
        });
        </script>
        <?php
    }
    
    public function ajax_release_deposit() {
        check_ajax_referer( 'mpcdp_deposit_action', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Nemate sigurnosne ovlasti za ovu akciju.' ] );
        }
        
        $booking_id = intval( $_POST['booking_id'] );
        $intent_id  = get_post_meta( $booking_id, '_mpcdp_deposit_intent_id', true );
        $status     = get_post_meta( $booking_id, '_mpcdp_deposit_status', true );
        
        if ( $status !== 'frozen' || ! $intent_id ) {
            wp_send_json_error( [ 'message' => 'Polog nije u statusu za otpuštanje.' ] );
        }
        
        $stripe_api = new StripeApi();
        $result = $stripe_api->cancel_deposit( $intent_id );
        
        if ( $result ) {
            update_post_meta( $booking_id, '_mpcdp_deposit_status', 'released' );
            wp_send_json_success();
        } else {
            wp_send_json_error( [ 'message' => 'Stripe API greška prilikom otpuštanja.' ] );
        }
    }
    
    public function ajax_capture_deposit() {
        check_ajax_referer( 'mpcdp_deposit_action', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Nemate sigurnosne ovlasti za ovu akciju.' ] );
        }
        
        $booking_id = intval( $_POST['booking_id'] );
        $amount_eur = floatval( $_POST['amount'] );
        $intent_id  = get_post_meta( $booking_id, '_mpcdp_deposit_intent_id', true );
        $status     = get_post_meta( $booking_id, '_mpcdp_deposit_status', true );
        
        if ( $status !== 'frozen' || ! $intent_id ) {
            wp_send_json_error( [ 'message' => 'Polog nije u statusu za naplatu.' ] );
        }
        
        $amount_cents = (int) round( $amount_eur * 100 );
        
        $stripe_api = new StripeApi();
        $result = $stripe_api->capture_deposit( $intent_id, $amount_cents );
        
        if ( $result ) {
            update_post_meta( $booking_id, '_mpcdp_deposit_status', 'captured' );
            update_post_meta( $booking_id, '_mpcdp_deposit_captured_amount', $amount_cents );
            wp_send_json_success();
        } else {
            wp_send_json_error( [ 'message' => 'Stripe API greška prilikom naplate.' ] );
        }
    }
}
