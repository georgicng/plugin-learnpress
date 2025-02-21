<?php
/**
 * Plugin load class.
 *
 * @author   Ikpugbu George
 * @package  LearnPress/Paystack
 * @version  1.0.0
 */

defined('ABSPATH') or exit;

if (! function_exists('LP_Gateway_Paystack')) {
    /**
     * Class LP_Gateway_Paystack.
     */
    class LP_Gateway_Paystack extends LP_Gateway_Abstract
    {

        /**
         * @var LP_Settings
         */
        public $settings;


        /**
         * @var string
         */
        public $id = 'paystack';

        /**
         * Constructor for the gateway.
         */
        public function __construct()
        {
            parent::__construct();

            $this->url ='https://api.paystack.co/transaction/';
            $this->icon = $this->settings->get(
                'icon',
                LP_Addon_Paystack::instance()->plugin_url('assets/paystack_icon.png')
            );
            $this->method_title = __('Paystack', 'learnpress');
            $this->method_description = __('Make payment via paystack', 'learnpress');

            // Get settings
            $this->title = $this->settings->get('title', $this->method_title);
            $this->description = $this->settings->get('description', $this->method_description);


            add_filter(
                "learn-press/payment-gateway/{$this->id}/available",
                array(
                    $this,
                    'is_available'
                )
            );
            $this->init();
        }

        /**
        * Register hooks.
        */
        public function init()
        {
            if (!$this->is_available()) {
                return;
            }

            if (did_action('init')) {
                $this->register_web_hook();
            } else {
                add_action('init', array($this, 'register_web_hook'));
            }

            add_action(
                'learn_press_web_hook_learn_press_paystack',
                array($this, 'web_hook_process_paystack')
            );

            add_action(
                'learn-press/order/received/paystack',
                array($this, 'confirm_payment')
            );
        }

        /**
         * Register paystack webhook with learnpress.
        */
        public function register_web_hook()
        {
            learn_press_register_web_hook('paystack', 'learn_press_paystack');
        }

        /**
         * Process paystack webhook.
         * Registered webhook address: home_url()/?learn_press_paystack=1
        */
        public function web_hook_process_paystack($request)
        {
            if (isset($request['reference'])) {
                //check transaction status from paystack
                $order_id = (int) sanitize_text_field($request['reference']);
                $order = new LP_Order($order_id);
                if ($order->get_order_status() == 'completed') {
                    return;
                }
                $transaction = $this->verify_payment($reference);
                if ($transaction) {
                    if ($transaction->status && 'success' == $transaction->data->status) {
                        $order_total = $order->get_total();
                        // convert to naira from kobo
                        $amount_paid = floatval($transaction->data->amount)/100;
                        // check if the amount paid is equal to the order amount.
                        if ($amount_paid < $order_total) {
                            //Update the order status
                            $order->update_status(
                                'cancelled',
                                __('Amount paid does not match order amount, this requires investigation', 'learnpress')
                            );
                            update_post_meta(
                                $order->get_id(),
                                '_payment_message',
                                __('Amount paid does not match order amount, this requires investigation', 'learnpress')
                            );
                            echo 'Total amount mis-match';
                        } else {
                            //Update the order status
                            $order->update_status(
                                'completed',
                                __($transaction->message, 'learnpress')
                            );
                            update_post_meta(
                                $order->get_id(),
                                '_payment_message',
                                __($transaction->message, 'learnpress')
                            );
                            $order->payment_complete($reference);
                            echo 'OK';
                        }
                    } else {
                        //Update the order status
                        $order->update_status('failed', $transaction->message);
                        update_post_meta($order->get_id(), '_payment_message', $transaction->message);
                        die('API returned error: ' . $transaction->message);
                    }
                } else {
                    die("Couldn't verify payment");
                }
            }
        }

        /**
         * Action hook to verify payment after checkout.
        */
        public function confirm_payment($order_id)
        {
            $order = new LP_Order($order_id);
            if ($order->get_order_status() == 'pending') {
                $transaction = $this->verify_payment($order_id);
                if ($transaction && $transaction->status && 'success' == $transaction->data->status) {
                    $order_total = $order->get_total();
                    // convert to naira from kobo
                    $amount_paid = floatval($transaction->data->amount)/100;
                    // check if the amount paid is equal to the order amount.
                    if ($amount_paid == $order_total) {
                        //Update the order status
                        $order->update_status(
                            'completed',
                            __($transaction->message, 'learnpress')
                        );
                        update_post_meta(
                            $order_id,
                            '_payment_message',
                            __($transaction->message, 'learnpress')
                        );
                        $order->payment_complete($reference);
                        echo '<div><div class="status">'
                            .'<span>Payment Status</span>'
                            .'<span>Confirmed</span>'
                            .'</div><div class="cta">'
                            .'<a class="button" href="/profile/">'
                            .'Go to Courses</a></div></div>';
                    }
                }
            }
        }

        /**
         * Verify payment from paystack.
         *
         * @param $refernce (order id)
         *
         * @return Object || Null
        */
        private function verify_payment($reference)
        {
            $verify_url = $this->url .'verify/'.rawurlencode($reference);
            $response = wp_remote_get(
                $verify_url,
                [
                    'timeout' => 60,
                    'headers' => [
                        "authorization" => "Bearer {$this->get_identifier()}",
                        "content-type" => "application/json",
                        "cache-control"=>"no-cache",
                    ]
                ]
            );

            if (!is_wp_error($response)) {
                return json_decode(wp_remote_retrieve_body($response));
            }

            return null;
        }

        /**
         * Check if gateway is enabled.
         *
         * @return bool
         */
        public function is_available()
        {
            if (LP()->settings->get("{$this->id}.enable") != 'yes') {
                return false;
            }

            if (empty($this->get_identifier())) {
                return false;
            }

            return true;
        }

        /**
         * Output for the order received page.
         *
         * @param $order
         */
        protected function _get($name)
        {
            return LP()->settings->get($this->id . '.' . $name);
        }

        /**
         * Admin payment settings.
         *
         * @return array
         */
        public function get_settings()
        {
            return apply_filters(
                'learn-press/gateway-payment/paystack/settings',
                array(
                    array(
                        'title'   => __('Enable', 'learnpress'),
                        'id'      => '[enable]',
                        'default' => 'no',
                        'type'    => 'yes-no'
                    ),
                    array(
                        'title'   => __('Test Mode', 'learnpress'),
                        'id'      => '[demo]',
                        'default' => 'no',
                        'type'    => 'yes-no'
                    ),
                    array(
                        'title'      => __('Test Secret Key', 'learnpress'),
                        'id'         => '[test_secret_key]',
                        'type'       => 'text',
                    ),
                    array(
                        'title'      => __('Test Public Key', 'learnpress'),
                        'id'         => '[test_public_key]',
                        'type'       => 'text',
                    ),
                    array(
                        'title'      => __('Live Secret Key', 'learnpress'),
                        'id'         => '[live_secret_key]',
                        'type'       => 'text',
                    ),
                    array(
                        'title'      => __('Live Public Key', 'learnpress'),
                        'id'         => '[live_public_key]',
                        'type'       => 'text',
                    ),
                    array(
                        'title'      => __('Description', 'learnpress'),
                        'id'         => '[description]',
                        'default'    => $this->description,
                        'type'       => 'textarea',
                        'editor'     => array( 'textarea_rows' => 5 ),
                        'visibility' => array(
                            'state'       => 'show',
                            'conditional' => array(
                                array(
                                    'field'   => '[enable]',
                                    'compare' => '=',
                                    'value'   => 'yes'
                                )
                            )
                        )
                    )
                )
            );
        }

        /**
         * Payment form. used for description on payment section
         */
        public function get_payment_form()
        {
            return LP()->settings->get($this->id . '.description');
        }

        /**
         * Process the payment and return the result
         *
         * @param $order_id
         *
         * @return array
         */
        public function process_payment($order_id)
        {
            $redirect = $this->get_request_url($order_id);

            $json = array(
                'result'   => $redirect ? 'success' : 'fail',
                'redirect' => $redirect
            );

            return $json;
        }

        /**
         * Process the payment and return the result
         *
         * @param $order_id
         *
         * @return string || null
        */
        public function get_request_url($order_id)
        {
            $checkout = LP()->checkout();
            $email = $checkout->get_checkout_email();
            $order = new LP_Order($order_id);
            $amount = floatval($order->get_total()) * 100;
            $callback_url = esc_url($this->get_return_url($order));

            $response = $this->get_payment_object($email, $amount, $callback_url, $order_id);

            if (!is_wp_error($response) && 200 == wp_remote_retrieve_response_code($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                return ($body['status']) ?
                 $body['data']['authorization_url']:
                 false;
            }

            return null;
        }

        /**
         * Initialiase transaction on paystack
         *
         * @param $email, amount, $callback_url, $reference
         *
         * @return array || WP_Error
        */
        private function get_payment_object($email, $amount, $callback_url, $reference)
        {
            $init_url = $this->url.'initialize';
            $paystack_args = [
                'method' => 'POST',
                'timeout' => 60,
                'redirection' => 5,
                'httpversion' => '1.0',
                'blocking' => true,
                'headers' => [
                    "authorization" => "Bearer {$this->get_identifier()}",
                    "content-type" => "application/json",
                    "cache-control"=>"no-cache"
                ],
                'body' => json_encode(
                    [
                        'email' => $email,
                        'amount' => $amount,
                        'callback_url' => $callback_url,
                        'reference' => $reference
                    ]
                ),
                'cookies' => []
            ];
            return wp_remote_post($init_url, $paystack_args);
        }

        /**
         * Return secret key
         *
         * @param none
         *
         * @return string
        */
        public function get_identifier()
        {
            return ($this->settings->get("demo") === 'yes') ?
                $this->settings->get('test_secret_key') :
                $this->settings->get('live_secret_key');
        }
    }
}
