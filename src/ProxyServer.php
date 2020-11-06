<?php

namespace HttpProxy;

use Amp;
use Amp\Socket;
use function Amp\call;

final class ProxyServer
{

    public static ?string $externalProxy = null;
    public static ?string $proxyConnectRequest = null;

    public static function start(string $host, int $port, ?string $proxy = null): void
    {
        static::$externalProxy = $proxy;
        Amp\Loop::defer(
            function() use ($host, $port) {
                $server = Amp\Socket\Server::listen("tcp://{$host}:{$port}");
                print "Listening on http://" . $server->getAddress() . " ..." . PHP_EOL;

                /** @var Socket\ResourceSocket $socket */
                while ($socket = yield $server->accept()) {
                    call(static fn()=>yield from self::handleClient($socket));
                }
            }
        );
    }

    private static function handleClient(Socket\Socket $socket): \Generator
    {
        try {
            /** @var Socket\Socket $remoteTunnel */
            $remoteTunnel = yield from self::openRemoteTunnel($socket);

            $promises[] = call(static function() use($socket, $remoteTunnel) {
                while (null !== $data = yield $socket->read()) {
                    $remoteTunnel->write($data);
                }
            });

            $promises[] = call(static function() use($socket, $remoteTunnel) {
                while (null !== $data = yield $remoteTunnel->read()) {
                    $socket->write($data);
                }
            });

            yield $promises;
        } catch (\Throwable $e) {
            yield $socket->write("HTTP/1.1 400 Bad Request\r\n\r\n");
            yield $socket->end($e->getMessage());
        }

    }

    /**
     * @param Socket\Socket $socket
     *
     * @return \Generator<Socket\Socket>
     * @throws Amp\CancelledException
     * @throws Socket\ConnectException
     */
    private static function openRemoteTunnel(Socket\Socket $socket): \Generator
    {
        $request = yield $socket->read();

        if (preg_match('/^CONNECT ([^\s]+)/u', $request, $matches)) {
            static::$proxyConnectRequest = $request;
            if (MitmServer::isEnabled()) {
                $remoteSocket = yield Socket\connect(MitmServer::getUri());
                $socket->write("HTTP/1.1 200 OK\r\n\r\n");
            } else {
                if (static::$externalProxy) {
                    /** @var Socket\Socket $remoteSocket */
                    $remoteSocket = yield Socket\connect(static::$externalProxy);
                    $remoteSocket->write(static::$proxyConnectRequest);
                    yield $socket->write(yield $remoteSocket->read());
                } else {
                    $remoteSocket = yield Socket\connect($matches[1]);
                    $socket->write("HTTP/1.1 200 OK\r\n\r\n");
                }
            }
        } elseif (preg_match('~Host: ([^\s]+)~u', $request, $matches)) {
            $host = $matches[1];
            $port = 80;

            preg_match_all('/Proxy-.+\r\n/', $request, $matches);
            $proxyHeaders = implode('',$matches[0]);
            $request = preg_replace('/Proxy-.+\r\n/', '', $request);
            static::$proxyConnectRequest = "CONNECT $host:$port HTTP/1.1\r\n{$proxyHeaders}\r\n";
            if (static::$externalProxy) {
                /** @var Socket\Socket $remoteSocket */
                $remoteSocket = yield Socket\connect(static::$externalProxy);
                $remoteSocket->write(static::$proxyConnectRequest);
                yield $remoteSocket->read();
            } else {
                $remoteSocket = yield Socket\connect("$host:$port");
            }

            $remoteSocket->write($request);
        } else {
            throw new \UnexpectedValueException("Unknown request format: $request");
        }

        return $remoteSocket;
    }

}