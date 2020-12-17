<?php

namespace BethlehemIT\SlackLogger;

use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
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
        if (!$exception instanceof HttpExceptionInterface
            || $exception->getStatusCode() >= 500
            || $this->shouldLogErrors($exception, $event->getRequest())) {
            if (!$this->isIgnoredBot($event->getRequest()->server->get("HTTP_USER_AGENT"))) {
                $e = FlattenException::createFromThrowable($exception);
                $this->sendException(
                    sprintf("%s request to %s threw\n", $event->getRequest()->getMethod(), $event->getRequest()->getUri()) .
                    sprintf("%s\n \"%s\" at %s line %s", $e->getClass(), $e->getMessage(), $e->getFile(), $e->getLine()));
            }
        }
    }

    /**
     * @param $userAgent
     * @return bool
     */
    public function isIgnoredBot($userAgent): bool
    {
        if (!is_string($userAgent)) {
            return false;
        }

        if ($this->config->getBool("SlackLogger.config.ignorebots")) {
            $regexes = $this->config->getString("SlackLogger.config.ignoredbots");
            foreach(explode("\n", $regexes) as $regex) {
                if (preg_match("/$regex/i", $userAgent)) {
                    return true;
                }
            }
        }
        return false;
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
        return $this->config->getBool("SlackLogger.config.enabled") && !empty($this->getWebhookURL());
    }

    /**
     * @param \Throwable $exception
     * @param Request $request
     * @return bool
     */
    private function shouldLogErrors(\Throwable $exception, Request $request): bool {
        if($this->config->getBool("SlackLogger.config.errorsenabled")) {
            $classes = $this->config->getString("SlackLogger.config.ignorederrors");
            foreach(explode("\n", $classes) as $class) {
                $class = trim($class);
                if ($exception instanceof $class) {
                    return false;
                }
            }

            if (empty($request->server->get("HTTP_REFERER"))) {
                $classes = $this->config->getString("SlackLogger.config.ignoredreferererrors");
                foreach (explode("\n", $classes) as $class) {
                    $class = trim($class);
                    if ($exception instanceof $class) {
                        return false;
                    }
                }
            }

            return true;
        }
        return false;
    }

    /**
     * @return string
     */
    private function getMentions(): string
    {
        $mentions = $this->config->getString("SlackLogger.config.mentions");
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
        return $this->config->getString("SlackLogger.config.webhookurl");
    }
}