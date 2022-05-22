<?php

namespace PageWalker\MBotTelegramChannel\Console\Laravel;

use Exception;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Command;
use PageWalker\MBotTelegramChannel\Channel\MessageLongPolling;
use PageWalker\MBot\Exceptions\IncomingMessageNotDispatchedException;
use PageWalker\MBot\MBot;

class TelegramLongPollingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mbot:telegram-long-polling {channel=telegram}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start mbot long polling handler';

    /**
     * @var MBot
     */
    protected MBot $mBot;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(MBot $mBot)
    {
        parent::__construct();

        $this->mBot = $mBot;
    }

    /**
     * @param Response $response
     * @return Response
     */
    public function handleChannelLongPollingResponse(Response $response): Response
    {
        $size = $response->getBody()->getSize();
        $status = $response->getStatusCode();

        if ($status === 200) {
            $this->output("Received response with status code <comment>{$status}</comment> ({$size} bytes)");
        } else {
            $this->output("Received response with status code <error>{$status}</error>", true);
        }

        return $response;
    }

    /**
     * @param Exception $exception
     * @throws Exception
     */
    public function handleChannelExceptions(Exception $exception)
    {
        if ($exception instanceof IncomingMessageNotDispatchedException) {
            $class = get_class($exception->getIncomingMessage());
            $payload = $exception->getIncomingMessage()->getText();
            $this->output("Incoming message <error>ignored</error> - {$class}\n{$payload}", true);

            return;
        }

        throw $exception;
    }

    /**
     * @param string $line
     * @param bool $isImportant
     */
    protected function output(string $line, bool $isImportant = false)
    {
        $timestamp = date('Y-m-d H:i:s');

        if ($isImportant) {
            $this->line("<comment>{$timestamp}</comment>: {$line}");
        } else {
            $this->line("{$timestamp}: {$line}");
        }
    }

    /**
     * @return int
     * @throws Exception
     */
    public function handle(): int
    {
        $this->output('Started <info>long polling</info> listener...', true);

        $channel = $this->mBot->channel(
            $this->argument('channel')
        );

        $channel->startLongPolling(
            [$this, 'handleChannelLongPollingResponse'],
            [$this, 'handleChannelExceptions']
        );

        return 0;
    }
}
