<?php

namespace Drupal\commerce_alipay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;
use Omnipay\Omnipay;

/**
 * Provides Alipay gateway for customer to scan QR-Code to pay.
 * @link https://doc.open.alipay.com/docs/doc.htm?treeId=194&articleId=105072&docType=1
 *
 * @CommercePaymentGateway(
 *   id = "alipay_customer_scan_qrcode_pay",
 *   label = "Alipay - Customer Scan QR-Code to Pay",
 *   display_label = "Alipay - Customer Scan QR-Code to Pay",
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_alipay\PluginForm\QRCodePaymentForm",
 *   }
 * )
 */
class CustomerScanQRCodePay extends OffsitePaymentGatewayBase implements SupportsRefundsInterface{

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'app_id' => '',
        'public_key' => '',
        'private_key' => ''
      ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['app_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('支付宝分配给开发者的应用ID'),
      '#default_value' => $this->configuration['app_id'],
      '#required' => TRUE,
    ];

    $form['private_key'] = [
      '#type' => 'textarea',
      '#title' => $this->t('开发者应用私钥'),
      '#description' => $this->t('应用私钥在创建订单时会使用到，需要它计算出签名供支付宝验证（应用公钥需要在支付宝开放平台中填写）'),
      '#default_value' => $this->configuration['private_key'],
      '#required' => TRUE,
    ];

    $form['public_key'] = [
      '#type' => 'textarea',
      '#title' => $this->t('支付宝公钥'),
      '#description' => $this->t('支付宝公钥在同步异步通知中会使用到，它能验证请求的签名是否是支付宝的私钥所签名。（支付宝公钥需要在支付宝开放平台中获取）'),
      '#default_value' => $this->configuration['public_key'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['app_id'] = $values['app_id'];
      $this->configuration['public_key'] = $values['public_key'];
      $this->configuration['private_key'] = $values['private_key'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    if (!in_array($payment->getState()->value, ['capture_completed', 'capture_partially_refunded'])) {
      throw new \InvalidArgumentException(t('Only payments in the "capture_completed" and "capture_partially_refunded" states can be refunded.'));
    }
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    // Validate the requested amount.
    $balance = $payment->getBalance();
    if ($amount->greaterThan($balance)) {
      throw new InvalidRequestException(sprintf("Can't refund more than %s.", $balance->__toString()));
    }

    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $app_id = $this->getConfiguration()['app_id'];
    $private_key = $this->getConfiguration()['private_key'];
    $public_key = $this->getConfiguration()['public_key'];

    /** @var \Omnipay\Alipay\AopF2FGateway $gateway */
    $gateway = Omnipay::create('Alipay_AopF2F');
    if ($payment_gateway_plugin->getMode() == 'test') {
      $gateway->sandbox(); // set to use sandbox endpoint
    }
    $gateway->setAppId($app_id);
    $gateway->setSignType('RSA2');
    $gateway->setPrivateKey($private_key);
    $gateway->setAlipayPublicKey($public_key);

    /** @var \Omnipay\Alipay\Requests\AopTradeRefundRequest $request */
    $request = $gateway->refund();

    $request->setBizContent([
      'out_trade_no' => strval($payment->getOrderId()),
      'trade_no' => $payment->getRemoteId(),
      'refund_amount' => (float) $amount->getNumber(),
      'out_request_no' => $payment->getOrderId() . date("zHis")
    ]);

    try {
      /** @var \Omnipay\Alipay\Responses\AopTradeRefundResponse $response */
      $response = $request->send();
      if($response->getAlipayResponse('code') == '10000'){
        // Refund is successful
        // Perform the refund request here, throw an exception if it fails.
        // See \Drupal\commerce_payment\Exception for the available exceptions.
        $remote_id = $payment->getRemoteId();
        $number = $amount->getNumber();

        $old_refunded_amount = $payment->getRefundedAmount();
        $new_refunded_amount = $old_refunded_amount->add($amount);
        if ($new_refunded_amount->lessThan($payment->getAmount())) {
          $payment->state = 'capture_partially_refunded';
        }
        else {
          $payment->state = 'capture_refunded';
        }

        $payment->setRefundedAmount($new_refunded_amount);
        $payment->save();

      } else {
        // Refund is not successful
        throw new InvalidRequestException(t('The refund request has failed: ') . $response->getAlipayResponse('sub_msg'));
      }
    } catch (\Exception $e) {
      // Refund is not successful
      \Drupal::logger('commerce_alipay')->error($e->getMessage());
      throw new InvalidRequestException(t('Alipay Service cannot approve this request: ') . $response->getAlipayResponse('sub_msg'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {

    $app_id = $this->getConfiguration()['app_id'];
    $private_key = $this->getConfiguration()['private_key'];
    $public_key = $this->getConfiguration()['public_key'];

    /** @var \Omnipay\Alipay\AopF2FGateway $gateway */
    $gateway = Omnipay::create('Alipay_AopF2F');
    $gateway->setAppId($app_id);
    $gateway->setPrivateKey($private_key);
    $gateway->setAlipayPublicKey($public_key);

    /** @var \Omnipay\Alipay\Requests\AopCompletePurchaseRequest $virtual_request */
    $virtual_request = $gateway->completePurchase();
    $virtual_request->setParams($_POST); //Optional

    try {
      /** @var \Omnipay\Alipay\Responses\AopCompletePurchaseResponse $response */
      $response = $virtual_request->send();
      $data = $response->getData();

      if (array_key_exists('refund_fee', $data)) {
        die('success'); // Ingore refund notifcation
      } elseif ($response->isPaid()) { // Payment is successful

        if ($this->getMode()) {
          \Drupal::logger('commerce_alipay')->notice(print_r($data, TRUE));
        }

        $this->createPayment($data);

        die('success'); //The response should be 'success' only
      } else {
        // Payment is not successful
        \Drupal::logger('commerce_alipay')->error(print_r($data, TRUE));
        die('fail');
      }
    } catch (\Exception $e) {
      // Payment is not successful
      \Drupal::logger('commerce_alipay')->error($e->getMessage());
      die('fail');
    }
  }

  /**
   * Create a Commerce Payment from a WeChat request successful result
   * @param  array $result
   * @param  string $state
   * @param  OrderInterface $order
   * @param  string $remote_state
   */
  public function createPayment(array $result, $state = 'capture_completed', OrderInterface $order = NULL, $remote_state = NULL) {
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');

    if (!$order) {
      $price = new Price(strval($result['total_amount']), 'CNY');
    } else {
      $price = $order->getTotalPrice();
    }
    $payment = $payment_storage->create([
      'state' => $state,
      'amount' => $price,
      'payment_gateway' => $this->entityId,
      'order_id' => $result['out_trade_no']? $result['out_trade_no'] : $order->id(),
      'test' => $this->getMode() == 'test',
      'remote_id' => $result['trade_no'],
      'remote_state' => $remote_state,
      'authorized' => REQUEST_TIME
    ]);
    $payment->save();
  }

}
