<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/lightningd-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Lightningd\Service;

use Daikon\Interop\Assertion;
use Daikon\Money\Exception\PaymentServiceFailed;
use Daikon\Money\Exception\PaymentServiceUnavailable;
use Daikon\Money\Service\MoneyServiceInterface;
use Daikon\Money\ValueObject\MoneyInterface;
use Daikon\ValueObject\Timestamp;
use NGUtech\Bitcoin\Service\SatoshiCurrencies;
use NGUtech\Bitcoin\ValueObject\Bitcoin;
use NGUtech\Bitcoin\ValueObject\Hash;
use NGUtech\Lightning\Entity\LightningInvoice;
use NGUtech\Lightning\Entity\LightningPayment;
use NGUtech\Lightning\Service\LightningServiceInterface;
use NGUtech\Lightning\ValueObject\InvoiceState;
use NGUtech\Lightning\ValueObject\PaymentState;
use NGUtech\Lightning\ValueObject\Request;
use NGUtech\Lightningd\Connector\LightningdRpcConnector;
use Psr\Log\LoggerInterface;
use Socket\Raw\Socket;

class LightningdService implements LightningServiceInterface
{
    public const INVOICE_STATUS_UNPAID = 'unpaid';
    public const INVOICE_STATUS_PAID = 'paid';
    public const INVOICE_STATUS_EXPIRED = 'expired';
    public const PAYMENT_STATUS_PENDING = 'pending';
    public const PAYMENT_STATUS_COMPLETE = 'complete';
    public const PAYMENT_STATUS_FAILED = 'failed';
    public const FAILURE_REASON_NO_ROUTE = 205;
    public const FAILURE_REASON_PAYMENT_TIMEOUT = 210;

    protected LoggerInterface $logger;

    protected LightningdRpcConnector $connector;

    protected MoneyServiceInterface $moneyService;

    protected array $settings;

    public function __construct(
        LoggerInterface $logger,
        LightningdRpcConnector $connector,
        MoneyServiceInterface $moneyService,
        array $settings = []
    ) {
        $this->logger = $logger;
        $this->connector = $connector;
        $this->moneyService = $moneyService;
        $this->settings = $settings;
    }

    public function request(LightningInvoice $invoice): LightningInvoice
    {
        Assertion::true($this->canRequest($invoice->getAmount()), 'Lightningd service cannot request given amount.');

        $expiry = $invoice->getExpiry()->toNative();
        Assertion::between($expiry, 60, 31536000, 'Invoice expiry is not acceptable.');

        $result = $this->call('invoice', [
            'msatoshi' => $this->convert((string)$invoice->getAmount())->getAmount(),
            'label' => (string)$invoice->getLabel(),
            'description' => (string)$invoice->getDescription(),
            'preimage' => (string)$invoice->getPreimage(),
            'expiry' => $expiry
        ]);

        return $invoice->withValues([
            'preimageHash' => $result['payment_hash'],
            'request' => $result['bolt11'],
            'expiry' => $expiry,
            'blockHeight' => $this->getInfo()['blockheight'],
            'createdAt' => Timestamp::now(),
        ]);
    }

    public function send(LightningPayment $payment): LightningPayment
    {
        Assertion::true($this->canSend($payment->getAmount()), 'Lightningd service cannot send given amount.');

        $result = $this->call('pay', [
            'bolt11' => (string)$payment->getRequest(),
            'label' => (string)$payment->getLabel(),
            'retry_for' => $this->settings['send']['timeout'] ?? 30,
            'maxfeepercent' => $payment->getFeeLimit()->format(6),
            'riskfactor' => $this->settings['send']['riskfactor'] ?? 10,
            'exemptfee' => $this->convert(
                ($this->settings['send']['exemptfee'] ?? '5000'.SatoshiCurrencies::MSAT)
            )->getAmount()
        ]);

        return $payment->withValues([
            'preimage' => $result['payment_preimage'],
            'preimageHash' => $result['payment_hash'],
            'feeSettled' => ($result['msatoshi_sent'] - $result['msatoshi']).SatoshiCurrencies::MSAT
        ]);
    }

    public function decode(Request $request): LightningInvoice
    {
        $result = $this->call('decodepay', ['bolt11' => (string)$request]);

        return LightningInvoice::fromNative([
            'preimageHash' => $result['payment_hash'],
            'request' => (string)$request,
            'destination' => $result['payee'],
            'amount' => ($result['msatoshi'] ?? 0).SatoshiCurrencies::MSAT,
            'description' => $result['description'],
            'expiry' => $result['expiry'],
            'cltvExpiry' => $result['min_final_cltv_expiry'],
            'createdAt' => $result['created_at']
        ]);
    }

    public function estimateFee(LightningPayment $payment): Bitcoin
    {
        $feeLimit = $payment->getAmount()->percentage($payment->getFeeLimit()->toNative(), Bitcoin::ROUND_UP);
        $exemptFee = $this->convert(($this->settings['send']['exemptfee'] ?? '5000'.SatoshiCurrencies::MSAT));
        $feeEstimate = $feeLimit->isGreaterThanOrEqual($exemptFee) ? $feeLimit : $exemptFee;

        $result = $this->call('getroute', [
            'id' => (string)$payment->getDestination(),
            'msatoshi' => $payment->getAmount()->getAmount(),
            'riskfactor' => $this->settings['send']['riskfactor'] ?? 10
        ]);

        $routeFee = Bitcoin::zero();
        foreach ($result['route'] as $route) {
            $hopFee = Bitcoin::fromNative($route['amount_msat'])->subtract($payment->getAmount());
            $routeFee = $routeFee->add($hopFee);
        }

        //@risky if a zero cost route is available then assume node will use that
        return !$routeFee->isZero() && $feeEstimate->isGreaterThanOrEqual($routeFee) ? $feeEstimate : $routeFee;
    }

    public function getInvoice(Hash $preimageHash): ?LightningInvoice
    {
        $result = $this->call('listinvoices', ['payment_hash' => (string)$preimageHash]);
        if (empty($result['invoices'][0])) {
            return null;
        }

        return LightningInvoice::fromNative([
            'preimage' => $result['invoices'][0]['preimage'],
            'preimageHash' => $result['invoices'][0]['payment_hash'],
            'request' => $result['invoices'][0]['bolt11'],
            'destination' => $result['invoices'][0]['destination'],
            'amount' => $result['invoices'][0]['amount_msat'],
            'amountPaid' => $result['invoices'][0]['amount_received_msat'],
            'label' => $result['invoices'][0]['label'],
            'description' => $result['invoices'][0]['description'],
            'state' => (string)$this->mapInvoiceState($result['invoices'][0]['status']),
            'settledAt' => $result['invoices'][0]['paid_at']
        ]);
    }

    public function getPayment(Hash $preimageHash): ?LightningPayment
    {
        $result = $this->call('listpays', ['payment_hash' => (string)$preimageHash]);
        if (empty($result['pays'][0])) {
            return null;
        }

        return LightningPayment::fromNative([
            'preimage' => $result['pays'][0]['preimage'],
            'preimageHash' => $result['pays'][0]['payment_hash'],
            'request' => $result['pays'][0]['bolt11'],
            'destination' => $result['pays'][0]['destination'],
            'amount' => $result['pays'][0]['amount_msat'],
            'amountPaid' => $result['pays'][0]['amount_sent_msat'],
            'feeSettled' => (
                intval($result['pays'][0]['amount_sent_msat']) - intval($result['pays'][0]['amount_msat'])
            ).SatoshiCurrencies::MSAT,
            'label' => $result['pays'][0]['label'],
            'state' => (string)$this->mapPaymentState($result['pays'][0]['status']),
            'createdAt' => $result['pays'][0]['created_at']
        ]);
    }

    public function getInfo(): array
    {
        return $this->call('getinfo');
    }

    public function canRequest(MoneyInterface $amount): bool
    {
        return ($this->settings['request']['enabled'] ?? true)
            && $amount->isGreaterThanOrEqual(
                $this->convert(($this->settings['request']['minimum'] ?? '1'.SatoshiCurrencies::MSAT))
            );
    }

    public function canSend(MoneyInterface $amount): bool
    {
        return ($this->settings['send']['enabled'] ?? true)
            && $amount->isGreaterThanOrEqual(
                $this->convert(($this->settings['send']['minimum'] ?? '1'.SatoshiCurrencies::MSAT))
            );
    }

    protected function call(string $method, array $params = []): array
    {
        /** @var Socket $socket */
        $socket = $this->connector->getConnection();

        $socket->write(json_encode([
            'id' => 0,
            'method' => $method,
            'params' => $params
        ]));

        $response = '';
        do {
            $response .= $socket->read(1024);
        } while (substr($response, -1) !== PHP_EOL);

        $content = json_decode($response, true);
        if (!$content || isset($content['error'])) {
            if (isset($content['error']) && in_array($content['error']['code'], [
                self::FAILURE_REASON_NO_ROUTE,
                self::FAILURE_REASON_PAYMENT_TIMEOUT
            ])) {
                throw new PaymentServiceUnavailable($content['error']['message'], $content['error']['code']);
            }
            $this->logger->error($content['error']['message'] ?? 'Unknown response.');
            throw new PaymentServiceFailed("Lightningd '$method' request failed.", $content['error']['code']);
        }

        return $content['result'];
    }

    protected function convert(string $amount, string $currency = SatoshiCurrencies::MSAT): Bitcoin
    {
        return $this->moneyService->convert($this->moneyService->parse($amount), $currency);
    }

    protected function mapInvoiceState(string $state): InvoiceState
    {
        $invoiceState = null;
        switch ($state) {
            case self::INVOICE_STATUS_UNPAID:
                $invoiceState = InvoiceState::PENDING;
                break;
            case self::INVOICE_STATUS_PAID:
                $invoiceState = InvoiceState::SETTLED;
                break;
            case self::INVOICE_STATUS_EXPIRED:
                $invoiceState = InvoiceState::CANCELLED;
                break;
            default:
                throw new PaymentServiceFailed("Unknown invoice state '$state'.");
        }
        return InvoiceState::fromNative($invoiceState);
    }

    protected function mapPaymentState(string $state): PaymentState
    {
        $paymentState = null;
        switch ($state) {
            case self::PAYMENT_STATUS_PENDING:
                $paymentState = PaymentState::PENDING;
                break;
            case self::PAYMENT_STATUS_COMPLETE:
                $paymentState = PaymentState::COMPLETED;
                break;
            case self::PAYMENT_STATUS_FAILED:
                $paymentState = PaymentState::FAILED;
                break;
            default:
                throw new PaymentServiceFailed("Unknown payment state '$state'.");
        }
        return PaymentState::fromNative($paymentState);
    }
}
