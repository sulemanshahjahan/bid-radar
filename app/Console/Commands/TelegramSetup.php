<?php

namespace App\Console\Commands;

use App\Services\Telegram\Telegram;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('bidradar:telegram {action : chat-id | webhook | test | remove-webhook}')]
#[Description('Telegram helpers: find your chat ID, register the webhook, send a test message')]
class TelegramSetup extends Command
{
    public function handle(Telegram $telegram): int
    {
        if (! config('bidradar.telegram.bot_token')) {
            return $this->failWith('Set TELEGRAM_BOT_TOKEN first.');
        }

        try {
            return match ($this->argument('action')) {
                'chat-id' => $this->chatId($telegram),
                'webhook' => $this->webhook($telegram),
                'remove-webhook' => $this->show($telegram->call('deleteWebhook')),
                'test' => $telegram->reply("\u{2705} BidRadar can reach you.") ? self::SUCCESS : $this->failWith('Send failed. Check TELEGRAM_CHAT_ID.'),
                default => $this->failWith('Unknown action.'),
            };
        } catch (Throwable $e) {
            return $this->failWith(Telegram::redact($e->getMessage()));
        }
    }

    /** Lists chats that recently messaged the bot (works only while no webhook is set). */
    private function chatId(Telegram $telegram): int
    {
        $updates = $telegram->call('getUpdates')['result'] ?? [];
        $chats = collect($updates)->map(fn ($u) => data_get($u, 'message.chat'))->filter()->unique('id');

        if ($chats->isEmpty()) {
            return $this->failWith('No messages found. Send your bot any message in Telegram, then run this again. (If a webhook is set, run remove-webhook first.)');
        }

        $this->table(['Chat ID', 'Name'], $chats->map(fn ($c) => [$c['id'], trim(($c['first_name'] ?? '').' '.($c['username'] ?? ''))])->values()->all());
        $this->line('Put your chat ID in TELEGRAM_CHAT_ID.');

        return self::SUCCESS;
    }

    private function webhook(Telegram $telegram): int
    {
        $secret = config('bidradar.telegram.webhook_secret');
        if (! $secret) {
            return $this->failWith('Set TELEGRAM_WEBHOOK_SECRET (a long random string, letters/numbers/_/- only) first.');
        }

        return $this->show($telegram->call('setWebhook', [
            'url' => route('telegram.webhook'),
            'secret_token' => $secret,
            'allowed_updates' => ['message'],
            'drop_pending_updates' => true,
        ]));
    }

    private function show(array $response): int
    {
        if ($response['ok'] ?? false) {
            $this->info($response['description'] ?? 'OK');

            return self::SUCCESS;
        }

        return $this->failWith($response['description'] ?? 'Telegram error');
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
