<?php

namespace robuust\stripe\gateways;

use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\omnipay\base\OffsiteGateway;
use craft\helpers\App;
use DigiTickets\Stripe\CheckoutGateway as OmnipayGateway;
use Omnipay\Common\AbstractGateway;

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

    // Public Methods
    // =========================================================================

    /**
     * {@inheritdoc}
     */
    public static function displayName(): string
    {
        return \Craft::t('commerce', 'Stripe Checkout');
    }

    /**
     * {@inheritdoc}
     */
    public function getPaymentTypeOptions(): array
    {
        return [
            'purchase' => \Craft::t('commerce', 'Purchase (Authorize and Capture Immediately)'),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getSettingsHtml(): ?string
    {
        return \Craft::$app->getView()->renderTemplate('commerce-stripe-checkout/gatewaySettings', ['gateway' => $this]);
    }

    /**
     * {@inheritdoc}
     */
    public function populateRequest(array &$request, BasePaymentForm $paymentForm = null): void
    {
        parent::populateRequest($request, $paymentForm);
        $request['customerEmail'] = $request['order']->email;
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
}
