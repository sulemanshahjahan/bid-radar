<?php

namespace App\Http\Controllers;

use App\Services\Telegram\BotCommands;
use App\Services\Telegram\Telegram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telegram webhook. Verifies Telegram's secret header, then only acts on
 * messages from the owner's chat; everything else is ignored silently.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, Telegram $telegram, BotCommands $commands): JsonResponse
    {
        $secret = (string) config('bidradar.telegram.webhook_secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(403);
        }

        $message = $request->input('message');
        $chatId = data_get($message, 'chat.id');
        $text = data_get($message, 'text');

        if ($chatId !== null && is_string($text) && $telegram->isOwnerChat($chatId) && str_starts_with(trim($text), '/')) {
            $telegram->reply($commands->handle($text));
        }

        return response()->json(['ok' => true]);
    }
}
