<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/lightningd-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Lightningd\Message;

use Daikon\AsyncJob\Worker\WorkerInterface;
use Daikon\Boot\Service\Provisioner\MessageBusProvisioner;
use Daikon\Interop\Assertion;
use Daikon\Interop\RuntimeException;
use Daikon\MessageBus\MessageBusInterface;
use Daikon\RabbitMq3\Connector\RabbitMq3Connector;
use NGUtech\Bitcoin\Service\SatoshiCurrencies;
use NGUtech\Lightning\Message\LightningMessageInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

final class LightningdMessageWorker implements WorkerInterface
{
    private const MESSAGE_INVOICE_PAYMENT = 'lightningd.message.invoice_payment';
    private const MESSAGE_SENDPAY_SUCCESS = 'lightningd.message.sendpay_success';

    private RabbitMq3Connector $connector;

    private MessageBusInterface $messageBus;

    private LoggerInterface $logger;

    private array $settings;

    public function __construct(
        RabbitMq3Connector $connector,
        MessageBusInterface $messageBus,
        LoggerInterface $logger,
        array $settings = []
    ) {
        $this->connector = $connector;
        $this->messageBus = $messageBus;
        $this->logger = $logger;
        $this->settings = $settings;
    }

    public function run(array $parameters = []): void
    {
        $queue = $parameters['queue'];
        Assertion::notBlank($queue);

        $messageHandler = function (AMQPMessage $amqpMessage): void {
            $this->execute($amqpMessage);
        };

        /** @var AMQPChannel $channel */
        $channel = $this->connector->getConnection()->channel();
        $channel->basic_qos(0, 1, false);
        $channel->basic_consume($queue, '', true, false, false, false, $messageHandler);

        while (count($channel->callbacks)) {
            $channel->wait();
        }
    }

    private function execute(AMQPMessage $amqpMessage): void
    {
        try {
            $message = $this->createMessage($amqpMessage);
            if ($message instanceof LightningMessageInterface) {
                $this->messageBus->publish($message, MessageBusProvisioner::EVENTS_CHANNEL);
            }
            $amqpMessage->ack();
        } catch (RuntimeException $error) {
            $this->logger->error(
                "Error handling lightningd message '{$amqpMessage->getRoutingKey()}'.",
                ['exception' => $error->getTrace()]
            );
            $amqpMessage->nack();
        }
    }

    private function createMessage(AMQPMessage $amqpMessage): ?LightningMessageInterface
    {
        switch ($amqpMessage->getRoutingKey()) {
            case self::MESSAGE_INVOICE_PAYMENT:
                $message = $this->createInvoicePaymentMessage($amqpMessage);
                break;
            case self::MESSAGE_SENDPAY_SUCCESS:
                $message = $this->createSendpaySuccessMessage($amqpMessage);
                break;
            default:
                // ignore unknown routing keys
        }

        return $message ?? null;
    }

    private function createInvoicePaymentMessage(AMQPMessage $amqpMessage): LightningdInvoiceSettled
    {
        $invoice = json_decode($amqpMessage->body, true)['invoice_payment'];

        return LightningdInvoiceSettled::fromNative([
            'preimageHash' => hash('sha256', hex2bin($invoice['preimage'])),
            'preimage' => $invoice['preimage'] ?? null,
            'amountPaid' => strtoupper($invoice['msat']),
            'label' => $invoice['label'],
            'timestamp' => (string)$amqpMessage->get('timestamp')
        ]);
    }

    private function createSendpaySuccessMessage(AMQPMessage $amqpMessage): LightningdPaymentSucceeded
    {
        $payment = json_decode($amqpMessage->body, true)['sendpay_success'];

        return LightningdPaymentSucceeded::fromNative([
            'preimage' => $payment['payment_preimage'],
            'preimageHash' => $payment['payment_hash'],
            'amount' => $payment['msatoshi'].SatoshiCurrencies::MSAT,
            'amountPaid' => $payment['msatoshi_sent'].SatoshiCurrencies::MSAT,
            'timestamp' => (string)$amqpMessage->get('timestamp')
        ]);
    }
}
