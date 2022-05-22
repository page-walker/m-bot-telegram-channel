<?php

namespace PageWalker\MBotTelegramChannel\Channel;

use Exception;
use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Utils;
use PageWalker\MBot\Channels\AbstractChannel;
use PageWalker\MBot\Messages\Chat;
use PageWalker\MBot\Messages\ChatInterface;
use PageWalker\MBot\Messages\Incoming\Common\CommonMessageInterface;
use PageWalker\MBot\Messages\Incoming\Common\ContactMessage;
use PageWalker\MBot\Messages\Incoming\Common\FileMessage;
use PageWalker\MBot\Messages\Incoming\Common\LocationMessage;
use PageWalker\MBot\Messages\Incoming\Common\PollMessage;
use PageWalker\MBot\Messages\Incoming\Common\StickerMessage;
use PageWalker\MBot\Messages\Incoming\Common\TextMessage;
use PageWalker\MBot\Messages\Incoming\Common\UnknownTypeMessage;
use PageWalker\MBot\Messages\Incoming\Common\VideoMessage;
use PageWalker\MBot\Messages\Incoming\IncomingMessageInterface;
use PageWalker\MBot\Messages\Incoming\Service\ChatMemberStatus;
use PageWalker\MBot\Messages\Incoming\Service\UnknownServiceMessage;
use PageWalker\MBot\Messages\Outgoing\Controls\InlineButtonCollectionInterface;
use PageWalker\MBot\Messages\Outgoing\LocationMessage as OutgoingLocationMessage;
use PageWalker\MBot\Messages\Outgoing\OutgoingMessageInterface;
use PageWalker\MBot\Messages\Outgoing\PhotoMessage as OutgoingPhotoMessage;
use PageWalker\MBot\Messages\Outgoing\Sent\AbstractSent;
use PageWalker\MBot\Messages\Outgoing\Sent\Failed;
use PageWalker\MBot\Messages\Outgoing\Sent\Success;
use PageWalker\MBot\Messages\Outgoing\SentMessage;
use PageWalker\MBot\Messages\Outgoing\SentMessageCancelled;
use PageWalker\MBot\Messages\Outgoing\SentMessageFailed;
use PageWalker\MBot\Messages\Outgoing\SentMessageSuccess;
use PageWalker\MBot\Messages\Outgoing\TextMessage as OutgoingTextMessage;
use PageWalker\MBot\Messages\Outgoing\TypingMessage as OutgoingTypingMessage;
use PageWalker\MBot\Messages\Receiver;
use PageWalker\MBot\Messages\Sender;
use PageWalker\MBot\Messages\SenderInterface;
use PageWalker\MBot\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Request;
use GuzzleHttp\Psr7\Response;
use RuntimeException;

class TelegramChannel extends AbstractChannel
{
   /**
     * @var int
     */
    protected int $latestUpdateId = 0;

    /**
     * @var int
     */
    protected int $fails = 0;

    public const MAX_FAILS = 5;

    /**
     * @param array $payload
     * @return bool
     * @throws GuzzleException
     */
    protected function handleUpdates(array $payload): bool
    {
        if (! $payload['ok']) {
            return false;
        }

        foreach ($payload['result'] as $resultData) {
            if ($resultData['update_id'] > $this->latestUpdateId) {
                $this->latestUpdateId = $resultData['update_id'];
            }

            $this->triggerAllListeners(
                $this->makeMessage($resultData)
            );
        }

        return true;
    }

    /**
     * @param Response $response
     */
    protected function handleFailedResponse(Response $response)
    {
        sleep(1);

        $this->fails++;

        if ($this->fails >= static::MAX_FAILS) {
            $this->fails = 0;

            throw new RuntimeException(
                'Terminated due to too many failures with status: ' . $response->getStatusCode()
            );
        }
    }

    /**
     * @param Response $response
     * @param callable $errorCallback
     * @throws GuzzleException
     */
    protected function handleResponse(Response $response, callable $errorCallback)
    {
        if ($response->getStatusCode() === 200) {
            $this->fails = 0;
            try {
                $this->handleUpdates(
                    json_decode($response->getBody(), true)
                );
            } catch (Exception $exception) {
                call_user_func($errorCallback, $exception);
            }
        } else {
            $this->handleFailedResponse($response);
        }
    }

    /**
     * @param string $token
     * @param Request $request
     * @throws GuzzleException
     */
    public function startFromRequest(string $token, Request $request)
    {
        $this->triggerAllListeners(
            $this->makeMessage(
                json_decode($request->getContent(), true)
            )
        );
    }

    /**
     * @param callable $responseReceivedCallback
     * @param callable $errorCallback
     */
    public function startLongPolling(callable $responseReceivedCallback, callable $errorCallback)
    {
        $pollingUrl = $this->makeRequestUrl('getUpdates');

        $config = $this->getConfig();

        $query = [
            'timeout' => $config->get('timeout', 30),
            'limit' => $config->get('limit', 100),
            'allowed_updates' => $config->get('allowed_updates', []),
        ];

        while (true) {
            $query['offset'] = $this->latestUpdateId + 1;

            $response = $this->getHttpClient()->request('GET', $pollingUrl, [
                'http_errors' => false,
                'query' => $query,
            ]);

            $this->handleResponse(
                call_user_func($responseReceivedCallback, $response),
                $errorCallback
            );
        }
    }

    /**
     * @param string $url
     * @return bool
     * @throws GuzzleException
     * @throws Exception
     */
    public function registerWebhook(string $url): bool
    {
        $this->getHttpClient()
                ->request('GET', $this->makeRequestUrl('setWebhook'), [
                    'query' => [
                        'url' => $url,
                        // 'certificate' => null,
                        // 'ip_address' => null,
                        // 'max_connections' => null,
                        // 'allowed_updates' => null,
                    ],
                ]);

        return true;
    }

    /**
     * @return bool
     * @throws GuzzleException
     */
    public function deleteWebhook(): bool
    {
        $this->getHttpClient()
                ->request('GET', $this->makeRequestUrl('deleteWebhook'), [
                    'query' => [
                        'drop_pending_updates' => true,
                    ],
                ]);

        return true;
    }

    /**
     * @return string
     * @throws Exception
     */
    protected function getBotApiToken(): string
    {
        if (! $apiToken = $this->config->get('api_token')) {
            throw new Exception('No api_token configured for ' . $this->name . ' channel');
        }

        return $apiToken;
    }

    /**
     * @return GuzzleHttpClient
     */
    protected function getHttpClient(): GuzzleHttpClient
    {
        return new GuzzleHttpClient();
    }

    /**
     * @param string $method
     * @return string
     * @throws Exception
     */
    protected function makeRequestUrl(string $method): string
    {
        $token = $this->getBotApiToken();

        return "https://api.telegram.org/bot{$token}/{$method}";
    }

    /**
     * @param OutgoingMessageInterface $message
     * @return string|null
     */
    protected function buildMarkup(OutgoingMessageInterface $message): ?string
    {
        $markup = [];

        if ($buttons = $message->getButtons()) {
            if ($buttons instanceof InlineButtonCollectionInterface) {
                $markup['inline_keyboard'] = [];

                foreach ($buttons->rows() as $row) {
                    $rowItems = [];

                    foreach ($row->buttons() as $button) {
                        $rowItems[] = [
                            'text' => $button->getTitle(),
                            'callback_data' => $button->getKey(),
                        ];
                    }

                    $markup['inline_keyboard'][] = $rowItems;
                }
            } else {
                $markup['keyboard'] = [];
                $markup['one_time_keyboard'] = true;

                foreach ($buttons->rows() as $row) {
                    $rowItems = [];

                    foreach ($row->buttons() as $button) {
                        $rowItems[] = $button->getTitle();
                    }

                    $markup['keyboard'][] = $rowItems;
                }
            }
        }

        return $markup ? json_encode($markup) : null;
    }

    /**
     * @param OutgoingMessageInterface|IncomingMessageInterface $message
     * @return SessionInterface
     */
    protected function loadSession(OutgoingMessageInterface|IncomingMessageInterface $message): SessionInterface
    {
        if ($message instanceof OutgoingMessageInterface) {
            return $this->getSessionRepository()->load(
                $this,
                $message->getReceiver()
            );
        }

        return $this->getSessionRepository()->load(
            $this,
            $message->getSender()
        );
    }

    protected function preprocessMessageBeforeSending(OutgoingMessageInterface $message): ?OutgoingMessageInterface
    {
        if ($buttons = $message->getButtons()) {
            $buttonsMap = [];

            foreach ($buttons->allButtons() as $button) {
                $buttonsMap[$button->getTitle()] = $button->getKey() ?: $button->getTitle();
            }

            $session = $this->loadSession($message);
            $session->set('_buttons_map', $buttonsMap);
            $this->getSessionRepository()->store($session);
        }

        return $message;
    }

    /**
     * @param AbstractSent $sent
     * @param OutgoingMessageInterface $message
     * @return AbstractSent
     * @throws GuzzleException
     */
    public function messageUpdate(AbstractSent $sent, OutgoingMessageInterface $message): AbstractSent
    {
        $this->preprocessMessageBeforeSending($message);

        $response = match (true) {
            $message instanceof OutgoingLocationMessage => $this->updateLocationMessage($sent, $message),
        };

        $responseData = json_decode((string) $response->getBody(), true);

        if ($messageId = $responseData['result']['message_id'] ?? null) {
            return new Success($this, $message, (string) $messageId);
        }

        return new Failed($this, $message, $responseData['description'] ?? '');
    }

    /**
     * @param OutgoingMessageInterface $message
     * @return AbstractSent
     * @throws GuzzleException
     */
    public function message(OutgoingMessageInterface $message): AbstractSent
    {
        $this->preprocessMessageBeforeSending($message);

        $response = match (true) {
            $message instanceof OutgoingPhotoMessage => $this->answerPhotoMessage($message),
            $message instanceof OutgoingTextMessage => $this->answerTextMessage($message),
            $message instanceof OutgoingTypingMessage => $this->answerTypingMessage($message),
            $message instanceof OutgoingLocationMessage => $this->answerLocationMessage($message),
        };

        $responseData = json_decode((string) $response->getBody(), true);

        if ($messageId = $responseData['result']['message_id'] ?? null) {
            return new Success($this, $message, (string) $messageId);
        }

        return new Failed($this, $message, $responseData['description'] ?? '');
    }

    /**
     * @param AbstractSent $sent
     * @param OutgoingLocationMessage $message
     * @return ResponseInterface
     * @throws GuzzleException
     * @throws Exception
     */
    protected function updateLocationMessage(AbstractSent $sent, OutgoingLocationMessage $message): ResponseInterface
    {
        return $this->getHttpClient()
            ->request('GET', $this->makeRequestUrl('editMessageLiveLocation'), [
                'query' => array_filter([
                    'chat_id' => $message->getReceiver()->getId(),
                    'message_id' => $sent->getId(),
                    'latitude' => $message->getLatitude(),
                    'longitude' => $message->getLongitude(),
                    'reply_markup' => $this->buildMarkup($message),
                ]),
            ]);
    }

    /**
     * @param OutgoingLocationMessage $message
     * @return ResponseInterface
     * @throws GuzzleException
     * @throws Exception
     */
    protected function answerLocationMessage(OutgoingLocationMessage $message): ResponseInterface
    {
        return $this->getHttpClient()
            ->request('GET', $this->makeRequestUrl('sendLocation'), [
                'query' => array_filter([
                    'chat_id' => $message->getReceiver()->getChatId(),
                    'live_period' => $message->getLivePeriod(),
                    'latitude' => $message->getLatitude(),
                    'longitude' => $message->getLongitude(),
                    'reply_markup' => $this->buildMarkup($message),
                ]),
            ]);
    }

    /**
     * @param OutgoingTextMessage $message
     * @return ResponseInterface
     * @throws GuzzleException
     * @throws Exception
     */
    protected function answerTextMessage(OutgoingTextMessage $message): ResponseInterface
    {
        return $this->getHttpClient()
            ->request('GET', $this->makeRequestUrl('sendMessage'), [
                'query' => array_filter([
                    'chat_id' => $message->getReceiver()->getChatId(),
                    'text' => $message->getText(),
                    'reply_markup' => $this->buildMarkup($message),
                ]),
            ]);
    }

    /**
     * @param OutgoingPhotoMessage $message
     * @return ResponseInterface
     * @throws GuzzleException
     * @throws Exception
     */
    protected function answerPhotoMessage(OutgoingPhotoMessage $message): ResponseInterface
    {
        $multipart = [
            ['name' => 'chat_id', 'contents' => $message->getReceiver()->getChatId()],
            ['name' => 'caption', 'contents' => $message->getText() ?: ''],
            ['name' => 'photo', 'contents' => Utils::tryFopen($message->getPath(), 'r')],
        ];

        return $this->getHttpClient()
            ->request('POST', $this->makeRequestUrl('sendPhoto'), [
                'multipart' => $multipart,
            ]);
    }

    /**
     * @param OutgoingTypingMessage $message
     * @return ResponseInterface
     * @throws GuzzleException
     * @throws Exception
     */
    protected function answerTypingMessage(OutgoingTypingMessage $message): ResponseInterface
    {
        return  $this->getHttpClient()
            ->request('GET', $this->makeRequestUrl('sendChatAction'), [
                'query' => [
                    'chat_id' => $message->getReceiver()->getChatId(),
                    'action' => match ($message->getType()) {
                        OutgoingTypingMessage::TYPE_TYPING_TEXT => 'typing',
                        OutgoingTypingMessage::TYPE_UPLOADING_PHOTO => 'upload_photo',
                        OutgoingTypingMessage::TYPE_RECORDING_VIDEO => 'record_video',
                        OutgoingTypingMessage::TYPE_UPLOADING_VIDEO => 'upload_video',
                        OutgoingTypingMessage::TYPE_RECORDING_VIDEO_NOTE => 'record_video_note',
                        OutgoingTypingMessage::TYPE_UPLOADING_VIDEO_NOTE => 'upload_video_note',
                        OutgoingTypingMessage::TYPE_RECORDING_VOICE => 'record_voice',
                        OutgoingTypingMessage::TYPE_UPLOADING_VOICE => 'upload_voice',
                        OutgoingTypingMessage::TYPE_UPLOADING_DOCUMENT => 'upload_document',
                        OutgoingTypingMessage::TYPE_FIND_LOCATION => 'find_location',
                        default => 'typing',
                    },
                ],
            ]);
    }

    /**
     * @param array $documentData
     * @return string
     * @throws GuzzleException
     * @throws Exception
     */
    protected function makeFileMessageUrl(array $documentData): string
    {
        $response = $this->getHttpClient()
            ->request('GET', $this->makeRequestUrl('getFile'), [
                'query' => [
                    'file_id' => $documentData['file_id'],
                ],
            ]);

        $fileData = json_decode((string) $response->getBody(), true);

        $filePath = $fileData['result']['file_path'];

        $token = $this->getBotApiToken();

        return "https://api.telegram.org/file/bot{$token}/{$filePath}";
    }

    /**
     * @param array $data
     * @return ChatInterface
     */
    protected function getChat(array $data): ChatInterface
    {
        $chat = new Chat(
            $data['chat']['id']
        );

        return $chat;
    }

    /**
     * @param array $data
     * @return SenderInterface
     */
    protected function getSender(array $data): SenderInterface
    {
        $sender = new Sender(
            $data['from']['id']
        );

        $sender->setIsBot($data['from']['is_bot']);

        $sender->setLanguageCode($data['from']['language_code']);

        return $sender;
    }

    /**
     * @param array $data
     * @return FileMessage|void
     * @throws GuzzleException
     */
    protected function makeFileMessage(array $data)
    {
        if (! $documentData = $data['message']['document'] ?? null) {
            return;
        }

        $url = $this->makeFileMessageUrl($documentData);

        return new FileMessage($url);
    }

    /**
     * @param array $data
     * @return TextMessage|void
     */
    protected function makeTextMessage(array $data)
    {
        if (! $text = $data['message']['text'] ?? null) {
            return;
        }

        return new TextMessage($text);
    }

    /**
     * @param array $data
     * @return TextMessage|void
     */
    protected function makeQueryCallbackTextMessage(array $data)
    {
        if (! $text = $data['callback_query']['data'] ?? null) {
            return;
        }

        return new TextMessage($text);
    }

    /**
     * @param array $data
     * @return StickerMessage|void
     * @throws GuzzleException
     */
    protected function makeStickerMessage(array $data)
    {
        if (! $stickerData = $data['message']['sticker'] ?? null) {
            return;
        }

        $url = $this->makeFileMessageUrl($stickerData);

        return new StickerMessage($url);
    }

    /**
     * @param array $data
     * @return VideoMessage|void
     * @throws GuzzleException
     */
    protected function makeVideoMessage(array $data)
    {
        if (! $videoData = $data['message']['video'] ?? null) {
            return;
        }

        $url = $this->makeFileMessageUrl($videoData);

        return new VideoMessage($url);
    }

    /**
     * @param array $data
     * @return PollMessage|void
     */
    protected function makePollMessage(array $data)
    {
        if (! $pollData = $data['message']['poll'] ?? null) {
            return;
        }

        $poll = new PollMessage(
            $pollData['question']
        );

        foreach ($poll['options'] as $option) {
            $poll->addOption($option['text']);
        }

        return $poll;
    }

    /**
     * @param array $data
     * @return LocationMessage|void
     */
    protected function makeLocationMessage(array $data)
    {
        if (! $locationData = $data['message']['location'] ?? null) {
            return;
        }

        return new LocationMessage(
            $locationData['latitude'],
            $locationData['longitude'],
        );
    }

    /**
     * @param array $data
     * @return ContactMessage|void
     */
    protected function makeContactMessage(array $data)
    {
        if (! $contactData = $data['message']['contact'] ?? null) {
            return;
        }

        return new ContactMessage(
            $contactData['phone_number'] ?? null,
            $contactData['first_name'] ?? null,
            $contactData['last_name'] ?? null,
            $contactData['user_id'] ?? null,
        );
    }

    /**
     * @param array $data
     * @return UnknownTypeMessage
     */
    protected function makeCommonMessage(array $data): UnknownTypeMessage
    {
        return new UnknownTypeMessage();
    }

    /**
     * @param array $data
     * @return ChatMemberStatus|void
     */
    protected function makeServiceMessageChatMemberStatus(array $data)
    {
        if (! $chatMemberStatusData = $data['my_chat_member'] ?? null) {
            return;
        }

        $receiver = new Receiver($chatMemberStatusData['new_chat_member']['user']['id']);

        $status = $chatMemberStatusData['new_chat_member']['status'];

        $message = new ChatMemberStatus($receiver, $status);

        $message->setSender(
            $this->getSender($chatMemberStatusData)->setChat($this->getChat($chatMemberStatusData))
        );

        return $message;
    }

    /**
     * @param array $data
     * @return UnknownServiceMessage
     */
    protected function makeUnknownServiceMessage(array $data): UnknownServiceMessage
    {
        return new UnknownServiceMessage(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * @param array $data
     * @return CommonMessageInterface
     * @throws GuzzleException
     */
    protected function makeMessage(array $data): IncomingMessageInterface
    {
        if (! empty($data['callback_query'])) {
            $message = $this->makeQueryCallbackTextMessage($data);

            $message
                ->setId($data['callback_query']['message']['message_id'])
                ->setSender(
                    $this->getSender($data['callback_query'])
                        ->setChat($this->getChat($data['callback_query']['message']))
                )
                ->setChannel($this);

            return $message;
        }

        if (! empty($data['message'])) {
            $message = $this->makeTextMessage($data)
                ?: $this->makeFileMessage($data)
                ?: $this->makeStickerMessage($data)
                ?: $this->makeVideoMessage($data)
                ?: $this->makePollMessage($data)
                ?: $this->makeLocationMessage($data)
                ?: $this->makeContactMessage($data)
                ?: $this->makeCommonMessage($data);

            $message
                ->setId($data['message']['message_id'])
                ->setSender(
                    $this->getSender($data['message'])
                        ->setChat($this->getChat($data['message']))
                )
                ->setChannel($this);

            if ($message instanceof TextMessage) {
                $session = $this->loadSession($message);

                if ($mappedAnswer = $session->get('_buttons_map')[$message->getText()] ?? null) {
                    $message->setText($mappedAnswer);
                }
            }

            return $message;
        }

        $message = $this->makeServiceMessageChatMemberStatus($data)
            ?: $this->makeUnknownServiceMessage($data);

        return $message
            ->setChannel($this);
    }
}
