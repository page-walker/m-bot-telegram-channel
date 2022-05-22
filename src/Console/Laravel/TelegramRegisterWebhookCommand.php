<?php

namespace PageWalker\MBotTelegramChannel\Console\Laravel;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;
use PageWalker\MBotTelegramChannel\Channel\MessageFromRequest;
use PageWalker\MBot\MBot;

class TelegramRegisterWebhookCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mbot:telegram-webhook {channelName=telegram} {--delete}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register/Delete telegram webhook';

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
     * @return int
     * @throws Exception
     * @throws GuzzleException
     */
    public function handle(): int
    {
        $channel = $this->mBot->channel($this->argument('channelName'));

        if ($this->option('delete')) {
            $this->line('Deleting....');

            if ($channel->deleteWebhook()) {
                $this->line('Webhook <comment>deleted</comment> successfully.');
            }

            return 0;
        }

        $this->line('Registering....');

        $url = route('m-bot.telegram.webhook', [
            'channel' => $this->argument('channelName'),
            'token' => $channel->getConfig()->get('api_token'),
        ]);

        if ($channel->registerWebhook($url)) {
            $this->line("{$url}\nWebhook <comment>registered</comment> successfully.");
        }

        return 0;
    }
}
