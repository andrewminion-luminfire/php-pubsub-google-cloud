<?php

namespace Superbalist\PubSub\GoogleCloud;

use Google\Cloud\PubSub\Message;
use Google\Cloud\PubSub\PubSubClient;
use Google\Cloud\PubSub\Subscription;
use Superbalist\PubSub\PubSubAdapterInterface;
use Superbalist\PubSub\Utils;

class GoogleCloudPubSubAdapter implements PubSubAdapterInterface
{
    /**
     * @var PubSubClient
     */
    protected $client;

    /**
     * @var string
     */
    protected $clientIdentifier;

    /**
     * @var bool
     */
    protected $autoCreateTopics;

    /**
     * @var bool
     */
    protected $autoCreateSubscriptions;

    /**
     * @var bool
     */
    protected $backgroundBatching;

    /**
     * @var int
     */
    protected $maxMessages;

    /**
     * @var bool
     */
    protected $returnImmediately;

    /**
     * @var int
     */
    protected $returnImmediatelyPause;

    /**
     * @param PubSubClient $client
     * @param string $clientIdentifier
     * @param bool $autoCreateTopics
     * @param bool $autoCreateSubscriptions
     * @param bool $backgroundBatching
     * @param int $maxMessages
     */
    public function __construct(
        PubSubClient $client,
        $clientIdentifier = null,
        $autoCreateTopics = true,
        $autoCreateSubscriptions = true,
        $backgroundBatching = false,
        $maxMessages = 1000,
        $returnImmediately = false,
        $returnImmediatelyPause = 500000
    ) {
        $this->client = $client;
        $this->clientIdentifier = $clientIdentifier;
        $this->autoCreateTopics = $autoCreateTopics;
        $this->autoCreateSubscriptions = $autoCreateSubscriptions;
        $this->backgroundBatching = $backgroundBatching;
        $this->maxMessages = $maxMessages;
        $this->returnImmediately = $returnImmediately;
        $this->returnImmediatelyPause = (int) $returnImmediatelyPause;
    }

    /**
     * Return the Google PubSubClient.
     *
     * @return PubSubClient
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Set the unique client identifier.
     *
     * The client identifier is used when creating a subscription to a topic.
     *
     * A topic can have multiple subscribers connected.
     * If all subscribers use the same client identifier, the messages will load-balance across them.
     * If all subscribers have different client identifiers, the messages will be dispatched all of them.
     *
     * @param string $clientIdentifier
     */
    public function setClientIdentifier($clientIdentifier)
    {
        $this->clientIdentifier = $clientIdentifier;
    }

    /**
     * Return the unique client identifier.
     *
     * @return string
     */
    public function getClientIdentifier()
    {
        return $this->clientIdentifier;
    }

    /**
     * Set whether or not topics will be auto created.
     *
     * @param bool $autoCreateTopics
     */
    public function setAutoCreateTopics($autoCreateTopics)
    {
        $this->autoCreateTopics = $autoCreateTopics;
    }

    /**
     * Check whether or not topics will be auto created.
     *
     * @return bool
     */
    public function areTopicsAutoCreated()
    {
        return $this->autoCreateTopics;
    }

    /**
     * Set whether or not subscriptions will be auto created.
     *
     * @param bool $autoCreateSubscriptions
     */
    public function setAutoCreateSubscriptions($autoCreateSubscriptions)
    {
        $this->autoCreateSubscriptions = $autoCreateSubscriptions;
    }

    /**
     * Check whether or not subscriptions will be auto created.
     *
     * @return bool
     */
    public function areSubscriptionsAutoCreated()
    {
        return $this->autoCreateSubscriptions;
    }

    /**
     * Set if a pull should return immediately if there are no messages
     * @param bool $returnImmediately
     */
    public function setReturnImmediately($returnImmediately) {
        $this->returnImmediately = $returnImmediately;
    }

    /**
     * Return the return immediately configuration
     * @return bool
     */
    public function getReturnImmediately() {
        return $this->returnImmediately;
    }

    /**
     * Set the amount of time to pause between attempts to pull messages if return immediately is enabled.
     * Value is in microseconds
     *
     * @param int $returnImmediatelyPause
     */
    public function setReturnImmediatelyPause($returnImmediatelyPause) {
        $this->returnImmediatelyPause = (int) $returnImmediatelyPause;
    }

    /**
     * Return the return immediately pause configuration
     * @return int
     */
    public function getReturnImmediatelyPause() {
        return $this->returnImmediatelyPause;
    }

    /**
     * Set whether or not background batching is enabled.
     *
     * This is available from Google Cloud 0.33+ - https://github.com/GoogleCloudPlatform/google-cloud-php/releases/tag/v0.33.0
     *
     * If the http://php.net/manual/en/book.sem.php and http://php.net/manual/en/book.pcntl.php extensions are enabled
     * AND the IS_BATCH_DAEMON_RUNNING ENV var is set to true, the library will queue messages to be published by the
     * Batch Daemon (https://github.com/GoogleCloudPlatform/google-cloud-php/blob/master/src/Core/Batch/BatchDaemon.php)
     *
     * For all other cases, messages will be queued in memory and will be published before the script terminates using
     * a vendor registered shutdown handler.
     *
     * @param bool $backgroundBatching
     */
    public function setBackgroundBatching($backgroundBatching)
    {
        $this->backgroundBatching = $backgroundBatching;
    }

    /**
     * Check whether or not background batching is enabled.
     *
     * @return bool
     */
    public function isBackgroundBatchingEnabled()
    {
        return $this->backgroundBatching;
    }

    /**
     * Max messages to pull at a time.
     * https://googlecloudplatform.github.io/google-cloud-php/#/docs/google-cloud/v0.35.0/pubsub/subscription?method=pull
     *
     * @param int $maxMessages
     */
    public function setMaxMessages($maxMessages)
    {
        $this->maxMessages = $maxMessages;
    }

    /**
     * Subscribe a handler to a channel.
     *
     * @param string $channel
     * @param callable $handler
     */
    public function subscribe($channel, callable $handler)
    {
        $subscription = $this->getSubscriptionForChannel($channel);

        $isSubscriptionLoopActive = true;
        $isPauseEnabled = $this->returnImmediately && ($this->returnImmediatelyPause > 0);

        while ($isSubscriptionLoopActive) {
            $messages = $subscription->pull([
                'grpcOptions' => [
                    'timeoutMillis' => null,
                ],
                'maxMessages' => $this->maxMessages,
                'returnImmediately' => $this->returnImmediately,
            ]);
            if ($isPauseEnabled && empty($messages)) {
                usleep($this->returnImmediatelyPause);
                continue;
            }
            foreach ($messages as $message) {
                /** @var Message $message */
                $payload = Utils::unserializeMessagePayload($message->data());

                if ($payload === 'unsubscribe') {
                    $isSubscriptionLoopActive = false;
                } else {
                    call_user_func($handler, $payload);
                }

                $subscription->acknowledge($message);
            }
        }
    }

    /**
     * Publish a message to a channel.
     *
     * @param string $channel
     * @param mixed $message
     *
     * @return array Array of message IDs.
     */
    public function publish($channel, $message)
    {
        $topic = $this->getTopicForChannel($channel);
        $payload = Utils::serializeMessage($message);

        if ($this->backgroundBatching) {
            $topic->batchPublisher()->publish(['data' => $payload]);
        } else {
            return $topic->publish(['data' => $payload]);
        }
    }

    /**
     * Publish multiple messages to a channel.
     *
     * @param string $channel
     * @param array $messages
     */
    public function publishBatch($channel, array $messages)
    {
        $topic = $this->getTopicForChannel($channel);
        $messages = array_map(function ($message) {
            return ['data' => Utils::serializeMessage($message)];
        }, $messages);

        if ($this->backgroundBatching) {
            $batchPublisher = $topic->batchPublisher();
            foreach ($messages as $message) {
                $batchPublisher->publish($message);
            }
        } else {
            $topic->publishBatch($messages);
        }
    }

    /**
     * Return a `Topic` instance from a channel name.
     *
     * If the topic doesn't exist, the topic is first created.
     *
     * @param string $channel
     *
     * @return \Google\Cloud\PubSub\Topic
     */
    protected function getTopicForChannel($channel)
    {
        $topic = $this->client->topic($channel);
        if ($this->autoCreateTopics && !$topic->exists()) {
            $topic->create();
        }
        return $topic;
    }

    /**
     * Return a `Subscription` instance from a channel name.
     *
     * If the subscription doesn't exist, the subscription is first created.
     *
     * @param string $channel
     *
     * @return \Google\Cloud\PubSub\Subscription
     */
    protected function getSubscriptionForChannel($channel)
    {
        $topic = $this->getTopicForChannel($channel);
        $clientIdentifier = $this->clientIdentifier ? $this->clientIdentifier : 'default';
        $clientIdentifier .= '.' . $channel;
        $subscription = $topic->subscription($clientIdentifier);
        if ($this->autoCreateSubscriptions && !$subscription->exists()) {
            $subscription->create();
        }
        return $subscription;
    }
}
