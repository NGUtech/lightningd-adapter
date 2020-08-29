<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/lightningd-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Lightningd\Migration\RabbitMq;

use Daikon\RabbitMq3\Migration\RabbitMq3Migration;
use PhpAmqpLib\Exchange\AMQPExchangeType;

final class InitializeMessageExchange20190311143000 extends RabbitMq3Migration
{
    public function getDescription(string $direction = self::MIGRATE_UP): string
    {
        return $direction === self::MIGRATE_UP
            ? 'Create a RabbitMQ message exchange for the Lightningd-Adapter context.'
            : 'Delete the RabbitMQ message message exchange for the Lightningd-Adapter context.';
    }

    public function isReversible(): bool
    {
        return true;
    }

    protected function up(): void
    {
        $this->createMigrationList('lightningd.adapter.migration_list');
        $this->declareExchange(
            'lightningd.adapter.exchange',
            'x-delayed-message',
            false,
            true,
            false,
            false,
            false,
            ['x-delayed-type' => AMQPExchangeType::TOPIC]
        );
    }

    protected function down(): void
    {
        $this->deleteExchange('lightningd.adapter.exchange');
        $this->deleteExchange('lightningd.adapter.migration_list');
    }
}
