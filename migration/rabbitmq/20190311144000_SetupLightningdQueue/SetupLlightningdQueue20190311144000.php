<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/lightningd-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Lightningd\Migration\RabbitMq;

use Daikon\RabbitMq3\Migration\RabbitMq3Migration;

final class SetupLightningdQueue20190311144000 extends RabbitMq3Migration
{
    public function getDescription(string $direction = self::MIGRATE_UP): string
    {
        return $direction === self::MIGRATE_UP
            ? 'Create RabbitMQ queue for Lightningd messages.'
            : 'Delete RabbitMQ queue for Lightningd messages.';
    }

    public function isReversible(): bool
    {
        return true;
    }

    protected function up(): void
    {
        $this->declareQueue('lightningd.adapter.messages', false, true, false, false);
        $this->bindQueue('lightningd.adapter.messages', 'lightningd.adapter.exchange', 'lightningd.message.#');
    }

    protected function down(): void
    {
        $this->deleteQueue('lightningd.adapter.messages');
    }
}
