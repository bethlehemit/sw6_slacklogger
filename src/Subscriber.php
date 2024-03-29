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

    /** @var string */
    private $root;

    /**
     * Subscriber constructor.
     * @param LoggerInterface $logger
     * @param Client $client
     * @param SystemConfigService $config
     * @param string $rootDir
     */
    public function __construct(
        LoggerInterface $logger,
        Client $client,
        SystemConfigService $config,
        string $rootDir
    ) {
        $this->logger = $logger;
        $this->client = $client;
        $this->config = $config;
        $this->root = $rootDir;
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
        $userAgent = $event->getRequest()->server->get("HTTP_USER_AGENT");
        $referer = $event->getRequest()->server->get("HTTP_REFERER");

        if (!$exception instanceof HttpExceptionInterface
            || $exception->getStatusCode() >= 500) {
            if ($this->shouldLogErrors($exception, $referer)
                && !$this->isIgnoredBot($userAgent))
            {
                $e = FlattenException::createFromThrowable($exception);

                $message = sprintf("%s request to %s\n", $event->getRequest()->getMethod(), $event->getRequest()->getUri());
                if(is_string($userAgent) && !empty($userAgent)) {
                    $message .= "\twith user agent: $userAgent\n";
                }
                if(is_string($referer) && !empty($referer)) {
                    $message .= "\twith referer: $referer\n";
                }
                $message .= "\tthrew\n\n" . sprintf("%s\n\n\"%s\" at %s line %s", $e->getClass(), $e->getMessage(), $e->getFile(), $e->getLine());

                $this->sendException($message, $this->getTrace($exception));
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
                $regex = trim($regex);
                if (empty($regex)) {continue;}
                if (preg_match("/$regex/i", $userAgent)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function getTrace(\Throwable $throwable): string
    {
        $result = "";
        foreach ($throwable->getTrace() as $line)
        {
            $result .= "*" . str_replace($this->root . "/", "", $line["file"])
                . ":" . $line["line"] . "*"
                . "   " . $line["class"] . ":" . $line["function"] . PHP_EOL;
        }
        return $result;
    }

    /**
     * @param string $message
     */
    public function sendException(string $message, string $trace): void
    {
        $content = [
            "body" => json_encode([
                "text" => substr($this->getMentions() . $message, 0, 3000), #Hard limit by Slack
                "unfurl_links" => false,
                "unfurl_media" => false
            ]),
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'connect_timeout' => 15,
            'timeout' => 15
        ];

        if ($this->config->getBool("SlackLogger.config.sendtrace")) {
            $content["body"] = json_encode([
                "text" => substr($this->getMentions() . $message . PHP_EOL . PHP_EOL . $trace, 0, 3000), #Hard limit by Slack
                "unfurl_links" => false,
                "unfurl_media" => false
            ]);
        }

        try {
            $this->client->post(
                $this->getWebhookURL(),
                $content
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
        return $this->config->getBool("SlackLogger.config.enabled")
            && !empty($this->getWebhookURL()
            && (!$this->config->getBool("SlackLogger.config.ignoredev") || getenv("APP_ENV") === "prod"));
    }

    /**
     * @param \Throwable $exception
     * @param $referer
     * @return bool
     */
    private function shouldLogErrors(\Throwable $exception, $referer): bool {
        if($this->config->getBool("SlackLogger.config.errorsenabled")) {
            $classes = $this->config->getString("SlackLogger.config.ignorederrors");
            foreach(explode("\n", $classes) as $class) {
                $class = trim($class);
                if ($exception instanceof $class) {
                    return false;
                }
            }

            if (empty($referer)) {
                $classes = $this->config->getString("SlackLogger.config.ignoredreferererrors");
                foreach (explode("\n", $classes) as $class) {
                    $class = trim($class);
                    if ($exception instanceof $class) {
                        return false;
                    }
                }
            } else {
                $regexes = $this->config->getString("SlackLogger.config.ignoredreferers");
                foreach(explode("\n", $regexes) as $regex) {
                    $regex = trim($regex);
                    if (empty($regex)) {continue;}
                    if (preg_match("/$regex/i", $referer)) {
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