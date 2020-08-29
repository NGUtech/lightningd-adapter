<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/lightningd-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Lightningd\Message;

use NGUtech\Lightning\Message\LightningPaymentMessageInterface;
use NGUtech\Lightning\Message\LightningPaymentMessageTrait;

final class LightningdPaymentSucceeded implements LightningPaymentMessageInterface
{
    use LightningPaymentMessageTrait;
}
