<?php

namespace BethlehemIT\Error;

use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use GuzzleHttp\Client;

class Subscriber implements EventSubscriberInterface
{
    /** @var LoggerInterface */
    private $logger;

    /** @var Client */
    private $client;

    /** @var SystemConfigService */
    private $config;

    /**
     * Subscriber constructor.
     * @param LoggerInterface $logger
     * @param Client $client
     * @param SystemConfigService $config
     */
    public function __construct(
        LoggerInterface $logger,
        Client $client,
        SystemConfigService $config
    ) {
        $this->logger = $logger;
        $this->client = $client;
        $this->config = $config;
    }

    /**
     * @return \array[][]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => [
                ['logKernelException', 0]
            ]
        ];
    }

    /**
     * Heavily based on the vendor/symfony/http-kernel/EventListener/ErrorListener.php
     *
     * @param ExceptionEvent $event
     */
    public function logKernelException(ExceptionEvent $event): void
    {
        if(!$this->isEnabled()) {
            return;
        }

        $exception = $event->getThrowable();
        if (!$exception instanceof HttpExceptionInterface || $exception->getStatusCode() >= 500) {
            $e = FlattenException::createFromThrowable($exception);
            $this->sendException(
                sprintf('Uncaught PHP Exception %s: "%s" at %s line %s', $e->getClass(), $e->getMessage(), $e->getFile(), $e->getLine()));
        }
    }

    /**
     * @param string $message
     */
    public function sendException(string $message): void
    {
        try {
            $this->client->post(
                $this->getWebhookURL(),
                [
                    'body' => json_encode([
                        "text" => $this->getMentions() . $message
                    ]),
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'connect_timeout' => 15,
                    'timeout' => 15
                ]
            );
        } catch (\Exception $e) {
            if(!is_null($this->logger)) {
                $this->logger->error($e->getMessage(), ['exception' => $e]);
            }
        } catch (GuzzleException $e) {
            if(!is_null($this->logger)) {
                $this->logger->error($e->getMessage(), ['exception' => $e]);
            }
        }
    }

    /**
     * @return bool
     */
    private function isEnabled(): bool {
        return $this->config->getBool("Error.config.enabled") && !empty($this->getWebhookURL());
    }

    /**
     * @return string
     */
    private function getMentions(): string
    {
        $mentions = $this->config->getString("Error.config.mentions");
        $result = "";
        foreach(explode(";", $mentions) as $key) {
            $key = trim($key);
            $result .= " <$key>";
        }

        if(strlen($result)) {
            $result .= "\n";
        }

        return $result;
    }

    /**
     * @return string
     */
    private function getWebhookURL(): string
    {
        return $this->config->getString("Error.config.webhookurl");
    }
}