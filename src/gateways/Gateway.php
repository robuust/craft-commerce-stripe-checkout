<?php

namespace robuust\stripe\gateways;

use Craft;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\omnipay\base\OffsiteGateway;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\helpers\App;
use craft\web\Response;
use DigiTickets\Stripe\CheckoutGateway as OmnipayGateway;
use Omnipay\Common\AbstractGateway;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Stripe;
use Stripe\Webhook;

/**
 * Stripe Checkout gateway.
 */
class Gateway extends OffsiteGateway
{
    // Properties
    // =========================================================================

    /**
     * @var string
     */
    public $publishableKey;

    /**
     * @var string
     */
    public $secretKey;

    /**
     * @var string
     */
    public $webhookSigningSecret;

    // Public Methods
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public static function displayName(): string
    {
        return Craft::t('commerce', 'Stripe Checkout');
    }

    /**
     * {@inheritdoc}
     */
    public function getPaymentTypeOptions(): array
    {
        return [
            'purchase' => Craft::t('commerce', 'Purchase (Authorize and Capture Immediately)'),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('commerce-stripe-checkout/gatewaySettings', ['gateway' => $this]);
    }

    /**
     * {@inheritdoc}
     */
    public function populateRequest(array &$request, ?BasePaymentForm $paymentForm = null): void
    {
        parent::populateRequest($request, $paymentForm);
        $request['customerEmail'] = $request['order']->email;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsWebhooks(): bool
    {
        return true;
    }

    /**
     * @return Response
     *
     * @throws \Throwable
     */
    public function processWebHook(): Response
    {
        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;

        $request = Craft::$app->getRequest();
        $payload = $request->getRawBody();
        $sigHeader = $request->getHeaders()->get('Stripe-Signature');

        // Initialize Stripe with the secret key
        Stripe::setApiKey(App::parseEnv($this->secretKey));

        try {
            // Verify the webhook signature
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                App::parseEnv($this->webhookSigningSecret)
            );
        } catch (UnexpectedValueException $e) {
            Craft::warning('Invalid Stripe webhook payload: '.$e->getMessage(), 'commerce');
            $response->setStatusCode(400);

            return $response;
        } catch (SignatureVerificationException $e) {
            Craft::warning('Invalid Stripe webhook signature: '.$e->getMessage(), 'commerce');
            $response->setStatusCode(400);

            return $response;
        } catch (\Exception $e) {
            Craft::warning('Error processing Stripe webhook: '.$e->getMessage(), 'commerce');
            $response->setStatusCode(400);

            return $response;
        }

        // Get the Payment Intent object
        $paymentIntent = $event->data->object;

        // Extract the transaction hash from the metadata
        $transactionHash = $paymentIntent->metadata->commerceTransactionHash;

        if (!$transactionHash) {
            Craft::warning('No transaction hash found in Payment Intent metadata', 'commerce');
            $response->setStatusCode(400);

            return $response;
        }

        // Find the transaction by hash
        $transaction = Commerce::getInstance()->getTransactions()->getTransactionByHash($transactionHash);

        if (!$transaction) {
            Craft::warning('Transaction with the hash “'.$transactionHash.'“ not found.', 'commerce');
            $response->setStatusCode(400);

            return $response;
        }

        // Check to see if a successful purchase child transaction already exist and skip out early if they do
        $successfulPurchaseChildTransaction = TransactionRecord::find()->where([
            'parentId' => $transaction->id,
            'status' => TransactionRecord::STATUS_SUCCESS,
            'type' => TransactionRecord::TYPE_PURCHASE,
        ])->count();

        if ($successfulPurchaseChildTransaction && $event->type === 'payment_intent.succeeded') {
            Craft::warning('Successful child transaction for “'.$transactionHash.'“ already exists.', 'commerce');
            $response->setStatusCode(200);

            return $response;
        }

        $childTransaction = Commerce::getInstance()->getTransactions()->createTransaction(null, $transaction);
        $childTransaction->type = $transaction->type;

        // Set the transaction status based on the event type
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $childTransaction->status = TransactionRecord::STATUS_SUCCESS;
                $childTransaction->message = 'Payment succeeded';
                break;

            case 'payment_intent.payment_failed':
                $childTransaction->status = TransactionRecord::STATUS_FAILED;
                $childTransaction->message = $paymentIntent->last_payment_error->message ?? 'Payment failed';
                break;

            case 'payment_intent.canceled':
                $childTransaction->status = TransactionRecord::STATUS_FAILED;
                $childTransaction->message = 'Payment canceled';
                break;

            case 'payment_intent.processing':
                $childTransaction->status = TransactionRecord::STATUS_PENDING;
                $childTransaction->message = 'Payment processing';
                break;

            default:
                // For other event types, just log them but don't update transaction
                Craft::info('Received Stripe webhook event: '.$event->type, 'commerce');
                $response->setStatusCode(200);

                return $response;
        }

        $childTransaction->response = $paymentIntent;
        $childTransaction->code = $paymentIntent->id;
        $childTransaction->reference = $paymentIntent->id;

        // Save the transaction
        Commerce::getInstance()->getTransactions()->saveTransaction($childTransaction);

        $response->setStatusCode(200);

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = ['paymentType', 'compare', 'compareValue' => 'purchase'];

        return $rules;
    }

    // Protected Methods
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    protected function createGateway(): AbstractGateway
    {
        /** @var OmnipayGateway $gateway */
        $gateway = static::createOmnipayGateway($this->getGatewayClassName());

        $gateway->setPublic(App::parseEnv($this->publishableKey));
        $gateway->setApiKey(App::parseEnv($this->secretKey));

        return $gateway;
    }

    /**
     * {@inheritdoc}
     */
    protected function getGatewayClassName(): ?string
    {
        return '\\'.OmnipayGateway::class;
    }

    /**
     * {@inheritdoc}
     */
    protected function createRequest(Transaction $transaction, ?BasePaymentForm $form = null): mixed
    {
        $request = parent::createRequest($transaction, $form);
        $request['transactionReference'] = $transaction->reference;

        // Add transaction hash to metadata for Payment Intent webhooks
        if (!isset($request['metadata'])) {
            $request['metadata'] = [];
        }

        $request['metadata']['commerceTransactionHash'] = $transaction->hash;

        return $request;
    }
}
