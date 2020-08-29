<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/lightningd-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Lightningd\Connector;

use Daikon\Dbal\Connector\ConnectorInterface;
use Daikon\Dbal\Connector\ProvidesConnector;
use Socket\Raw\Factory;
use Socket\Raw\Socket;

final class LightningdRpcConnector implements ConnectorInterface
{
    use ProvidesConnector;

    protected function connect(): Socket
    {
        return (new Factory)->createClient(
            'unix://'.$this->settings['rpc_file'],
            $this->settings['socket_timeout'] ?? 30
        );
    }

    public function disconnect(): void
    {
        if ($this->isConnected()) {
            $this->connection->shutdown();
            $this->connection->close();
            $this->connection = null;
        }
    }
}
